CREATE DATABASE IF NOT EXISTS mobile_mechanic;
USE mobile_mechanic;

-- ======================
-- USERS TABLE
-- ======================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20),
    password VARCHAR(255) NOT NULL,
    role ENUM('user','admin') DEFAULT 'user',

    -- Profile
    profile_pic   VARCHAR(255) NULL,
    date_of_birth DATE NOT NULL,
    gender        ENUM('male','female') NOT NULL,

    is_superadmin BOOLEAN NOT NULL DEFAULT FALSE,
    is_disabled   BOOLEAN NOT NULL DEFAULT FALSE,

    last_login  TIMESTAMP NULL,
    last_logout TIMESTAMP NULL,

    cancel_count_today   TINYINT  NOT NULL DEFAULT 0,
    cancel_date          DATE     NULL,
    cancel_blocked_until DATETIME NULL,

    base_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ======================
-- TRIGGER: ENFORCE SINGLE SUPER ADMIN (INSERT)
-- ======================
DROP TRIGGER IF EXISTS trg_one_superadmin_insert;
DELIMITER $$
CREATE TRIGGER trg_one_superadmin_insert
BEFORE INSERT ON users
FOR EACH ROW
BEGIN
    IF NEW.is_superadmin = TRUE THEN
        IF (SELECT COUNT(*) FROM users WHERE is_superadmin = TRUE) > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Only one super admin is allowed per deployment.';
        END IF;
    END IF;
END$$
DELIMITER ;

-- ======================
-- TRIGGER: ENFORCE SINGLE SUPER ADMIN (UPDATE)
-- ======================
DROP TRIGGER IF EXISTS trg_one_superadmin_update;
DELIMITER $$
CREATE TRIGGER trg_one_superadmin_update
BEFORE UPDATE ON users
FOR EACH ROW
BEGIN
    IF NEW.is_superadmin = TRUE AND OLD.is_superadmin = FALSE THEN
        IF (SELECT COUNT(*) FROM users WHERE is_superadmin = TRUE) > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Only one super admin is allowed per deployment.';
        END IF;
    END IF;
END$$
DELIMITER ;

-- ======================
-- WALK-IN CUSTOMERS
-- ======================
CREATE TABLE IF NOT EXISTS walkin_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(100) NOT NULL,
    phone         VARCHAR(20),
    email         VARCHAR(100),
    address       TEXT,
    date_of_birth DATE NOT NULL,
    gender        ENUM('male','female') NOT NULL,

    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ======================
-- MECHANICS TABLE
-- ======================
CREATE TABLE IF NOT EXISTS mechanics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name  VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(100) UNIQUE NULL,
    password VARCHAR(255) NULL,

    profile_pic   VARCHAR(255) NULL,
    address       TEXT NULL,
    date_of_birth DATE NOT NULL,
    gender        ENUM('male','female') NOT NULL,

    current_lat         DECIMAL(10,8) NULL,
    current_lng         DECIMAL(11,8) NULL,
    location_updated_at TIMESTAMP NULL,

    status ENUM('available','busy') DEFAULT 'available',

    is_disabled BOOLEAN DEFAULT FALSE,
    is_deleted  BOOLEAN DEFAULT FALSE,

    last_login  TIMESTAMP NULL,
    last_logout TIMESTAMP NULL,

    base_salary     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    commission_rate DECIMAL(5,2)  NOT NULL DEFAULT 0.00,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_mechanics_status     (status),
    INDEX idx_mechanics_is_deleted (is_deleted)
);

-- ======================
-- REQUESTS TABLE
-- ======================
CREATE TABLE IF NOT EXISTS requests (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id   INT NULL,
    walkin_id INT NULL,

    vehicle_make       VARCHAR(100) NULL,
    vehicle_make_other VARCHAR(100) NULL,
    vehicle_model      VARCHAR(100) NULL,
    vehicle_year       SMALLINT     NULL,

    problem_type VARCHAR(100),
    description  TEXT,
    image        VARCHAR(255),

    latitude  DECIMAL(10,8),
    longitude DECIMAL(11,8),

    diagnosis TEXT,

    status ENUM('pending','assigned','in_progress','completed','rejected','cancelled') DEFAULT 'pending',

    rejection_reason TEXT NULL,

    mechanic_id  INT NULL,

    assigned_by  INT NULL,
    completed_by INT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    hidden_by_user  BOOLEAN DEFAULT FALSE,
    hidden_by_admin BOOLEAN DEFAULT FALSE,

    FOREIGN KEY (user_id)      REFERENCES users(id)            ON DELETE CASCADE,
    FOREIGN KEY (walkin_id)    REFERENCES walkin_customers(id) ON DELETE CASCADE,
    FOREIGN KEY (mechanic_id)  REFERENCES mechanics(id)        ON DELETE SET NULL,
    FOREIGN KEY (assigned_by)  REFERENCES users(id)            ON DELETE SET NULL,
    FOREIGN KEY (completed_by) REFERENCES users(id)            ON DELETE SET NULL
);

-- ======================
-- SERVICES (FINAL BILL)
-- ======================
CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id   INT NOT NULL,
    service_name VARCHAR(100),
    total_amount DECIMAL(10,2),
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE
);

-- ======================
-- PARTS CATALOG
-- Pre-catalogued parts with known buy/sell prices and stock tracking.
-- Used by service_items.catalog_id when picking from catalog.
-- Free-text (uncatalogued) parts leave catalog_id NULL.
-- cost_price  = shop's purchase cost (default for catalog parts).
-- sell_price  = default charge to customer (admin may override per invoice line).
-- stock_quantity      = current units on hand; decremented by app layer when part is used on a job.
-- low_stock_threshold = alert when stock_quantity falls at or below this value. 0 = no alert.
-- is_active   = FALSE hides the part from new invoices but preserves history references.
--               Super admin can toggle; parts are NEVER hard-deleted once used on a job.
-- ======================
CREATE TABLE IF NOT EXISTS parts_catalog (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    sku         VARCHAR(50)  NULL,
    description TEXT         NULL,
    cost_price  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    sell_price  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock_quantity      INT NOT NULL DEFAULT 0,
    low_stock_threshold INT NOT NULL DEFAULT 0,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_by  INT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_parts_catalog_is_active (is_active),
    INDEX idx_parts_catalog_name      (name)
);

-- ======================
-- BALANCE LEDGER
-- Moved BEFORE parts_catalog_log because parts_catalog_log holds a FK to balance_ledger(id).
-- MySQL requires the referenced table to exist at CREATE TABLE time.
--
-- Append-only audit trail of every money movement.
-- Current balance = SUM(amount WHERE direction='in') - SUM(amount WHERE direction='out')
--
-- Written by the application layer on:
--   type='payment_in'         direction='in'  — customer payment recorded
--   type='top_up'             direction='in'  — super admin manual deposit
--   type='expense'            direction='out' — expense status flipped to 'paid' (NOT on insert)
--   type='payroll'            direction='out' — payroll_record flipped to 'paid'
--   type='inventory_purchase' direction='out' — parts added to inventory (new part OR restock)
--                                               by super admin (any qty) or staff admin (max 10/tx)
--                                               amount = cost_price × quantity_added
--
-- reference_id links to:
--   payments.id          for payment_in
--   expenses.id          for expense
--   payroll_records.id   for payroll
--   parts_catalog_log.id for inventory_purchase
--   NULL                 for top_up
--
-- NEVER delete or update rows — reverse with a correction entry.
-- ======================
CREATE TABLE IF NOT EXISTS balance_ledger (
    id           INT AUTO_INCREMENT PRIMARY KEY,

    type         ENUM('payment_in','top_up','expense','payroll','inventory_purchase') NOT NULL,
    direction    ENUM('in','out') NOT NULL,
    amount       DECIMAL(10,2) NOT NULL,

    reference_id INT NULL,
    notes        TEXT NULL,
    created_by   INT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_ledger_type       (type),
    INDEX idx_ledger_direction  (direction),
    INDEX idx_ledger_created_at (created_at)
);

-- ======================
-- PARTS CATALOG LOG
-- Append-only audit trail of every stock movement on parts_catalog.
-- Written by the application layer whenever stock changes:
--
--   action='add_new'  — super admin creates a new part with initial stock.
--                       quantity_change = initial stock_quantity.
--                       cost_per_unit   = parts_catalog.cost_price at creation time.
--                       balance_ledger row written: type='inventory_purchase', direction='out',
--                       amount = cost_per_unit * quantity_change.
--
--   action='restock'  — super admin OR staff admin adds more units to an existing part.
--                       quantity_change = units added (positive).
--                       cost_per_unit   = parts_catalog.cost_price at restock time.
--                       balance_ledger row written: same as above.
--                       Staff admin restock is capped at 10 units per transaction (app layer).
--
--   action='used'     — app layer decrements stock when a part is added to a service invoice.
--                       quantity_change = units consumed (stored as negative value).
--                       cost_per_unit   = NULL (no cash movement; cost was already paid at purchase).
--                       No balance_ledger row.
--
--   action='adjusted' — super admin manual correction (e.g. stock count after audit).
--                       quantity_change = delta (positive or negative).
--                       cost_per_unit   = NULL.
--                       No balance_ledger row (adjustments are not cash events).
--
-- balance_ledger_id links to the ledger row written for add_new / restock actions.
-- NULL for 'used' and 'adjusted' actions which produce no ledger entry.
--
-- role_at_time captures whether the action was taken by 'super_admin' or 'staff_admin'
-- so audit reports can distinguish emergency restocks from normal purchasing.
-- ======================
CREATE TABLE IF NOT EXISTS parts_catalog_log (
    id           INT AUTO_INCREMENT PRIMARY KEY,

    part_id      INT NOT NULL,
    action       ENUM('add_new','restock','used','adjusted') NOT NULL,

    quantity_change  INT           NOT NULL,   -- positive = stock added; negative = stock consumed
    cost_per_unit    DECIMAL(10,2) NULL,        -- NULL for 'used' and 'adjusted'
    total_cost       DECIMAL(10,2) NULL,        -- cost_per_unit * ABS(quantity_change); NULL for non-cash actions

    balance_ledger_id INT NULL,                 -- FK to balance_ledger row; NULL for 'used'/'adjusted'

    performed_by  INT  NULL,                    -- users.id of the staff or super admin
    role_at_time  ENUM('super_admin','staff_admin') NOT NULL,
    notes         TEXT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (part_id)           REFERENCES parts_catalog(id)  ON DELETE CASCADE,
    FOREIGN KEY (performed_by)      REFERENCES users(id)          ON DELETE SET NULL,
    FOREIGN KEY (balance_ledger_id) REFERENCES balance_ledger(id) ON DELETE SET NULL,

    INDEX idx_parts_log_part_id      (part_id),
    INDEX idx_parts_log_action       (action),
    INDEX idx_parts_log_performed_by (performed_by),
    INDEX idx_parts_log_created_at   (created_at)
);

-- ======================
-- SERVICE ITEMS (DETAILS)
-- type:
--   'labor' — mechanic work/time; pure revenue, no cost tracked.
--   'part'  — physical parts.
--              catalog_id SET  → cost_price auto-copied from parts_catalog at insert time.
--                                stock_quantity decremented by quantity in app layer.
--                                parts_catalog_log row written (action='used').
--              catalog_id NULL → free-text part; admin enters cost_price and price manually.
--                                cost_price NULL means revenue-only (admin left it blank).
--   'fee'   — call-out fee, diagnostic fee, etc. Pure revenue like labor.
--   'other' — anything that does not fit the above.
-- price      = what the customer is charged per unit.
-- cost_price = shop's cost per unit.
--              Catalog parts: copied from parts_catalog.cost_price at insert time.
--              Free-text parts: entered manually by admin; NULL if unknown.
--              Labor / fee / other: always NULL.
-- quantity   = units used; defaults to 1. Line total = price * quantity.
-- ======================
CREATE TABLE IF NOT EXISTS service_items (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    catalog_id INT NULL,
    item_name  VARCHAR(150) NOT NULL,
    quantity   INT NOT NULL DEFAULT 1,
    price      DECIMAL(10,2) NOT NULL,
    cost_price DECIMAL(10,2) NULL,
    type       ENUM('labor','part','fee','other') NOT NULL DEFAULT 'other',

    FOREIGN KEY (service_id) REFERENCES services(id)      ON DELETE CASCADE,
    FOREIGN KEY (catalog_id) REFERENCES parts_catalog(id) ON DELETE SET NULL,

    INDEX idx_service_items_catalog_id (catalog_id)
);

-- ======================
-- ACTIVITIES (LOGS)
-- ======================
CREATE TABLE IF NOT EXISTS activities (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT,
    action     TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ======================
-- NOTIFICATIONS TABLE
-- ======================
CREATE TABLE IF NOT EXISTS notifications (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    type       VARCHAR(50)  NOT NULL,
    title      VARCHAR(255) NOT NULL,
    message    TEXT,
    request_id INT NULL,
    is_read    BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,

    INDEX idx_user_id (user_id),
    INDEX idx_is_read (is_read)
);

-- ======================
-- HISTORY RECORDS
-- Denormalized snapshot of every closed request (completed / rejected / cancelled).
-- No FK constraints on most snapshot fields intentionally — archive records must
-- survive deletion of the source user, mechanic, or walk-in customer.
--
-- Snapshot fields added in this version:
--   customer_email    — preserved so contact info survives account/walk-in deletion.
--   customer_address  — walk-in customers carry an address; snapshotted here for the same reason.
--   payment_method    — copied from payments table when payment is recorded; lets the
--                       receipt page render without an extra JOIN.
--   payment_reference — same: reference number (e.g. mobile-money transaction ID) frozen at
--                       payment time so the receipt is fully self-contained.
-- ======================
CREATE TABLE IF NOT EXISTS history_records (
    id INT AUTO_INCREMENT PRIMARY KEY,

    request_id INT NOT NULL,

    user_id          INT NULL,
    walkin_id        INT NULL,
    is_walkin        BOOLEAN NOT NULL DEFAULT FALSE,
    customer_name    VARCHAR(100),
    customer_email   VARCHAR(100) NULL,   -- snapshot; survives user/walk-in deletion
    customer_phone   VARCHAR(20),
    customer_address TEXT NULL,           -- walk-in address snapshot
    customer_gender  ENUM('male','female') NULL,
    customer_dob     DATE NULL,

    mechanic_id     INT NULL,
    mechanic_name   VARCHAR(100),
    mechanic_gender ENUM('male','female') NULL,

    vehicle_make  VARCHAR(100) NULL,
    vehicle_model VARCHAR(100) NULL,
    vehicle_year  SMALLINT     NULL,

    problem_type VARCHAR(100),
    description  TEXT,
    image        VARCHAR(255),

    latitude  DECIMAL(10,8),
    longitude DECIMAL(11,8),

    diagnosis TEXT,

    status           ENUM('completed','rejected','cancelled') NOT NULL,
    rejection_reason TEXT,
    total_amount     DECIMAL(10,2) DEFAULT 0.00,

    payment_status    ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
    payment_method    ENUM('cash','mobile_money','bank_transfer','card','other') NULL, -- snapshot from payments
    payment_reference VARCHAR(100) NULL,                                               -- snapshot from payments

    assigned_by_name  VARCHAR(100) NULL,
    completed_by_name VARCHAR(100) NULL,

    request_created_at TIMESTAMP NULL,
    completed_at       TIMESTAMP NULL,
    history_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    hidden_by_user  BOOLEAN DEFAULT FALSE,
    hidden_by_admin BOOLEAN DEFAULT FALSE,

    feedback_dismiss_count TINYINT NOT NULL DEFAULT 0,

    INDEX idx_history_mechanic_id     (mechanic_id),
    INDEX idx_history_status          (status),
    INDEX idx_history_problem_type    (problem_type),
    INDEX idx_history_request_created (request_created_at),
    INDEX idx_history_completed_at    (completed_at),
    INDEX idx_history_is_walkin       (is_walkin),
    INDEX idx_history_vehicle_make    (vehicle_make),
    INDEX idx_history_payment_status  (payment_status)
);

-- ======================
-- HISTORY SERVICE ITEMS
-- Snapshot of service_items at job close time.
-- catalog_id preserved for traceability; price, cost_price, quantity frozen at snapshot time
-- so historical P&L never changes if catalog prices or stock are updated later.
-- ======================
CREATE TABLE IF NOT EXISTS history_service_items (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    history_id INT NOT NULL,
    catalog_id INT NULL,
    item_name  VARCHAR(150) NOT NULL,
    quantity   INT NOT NULL DEFAULT 1,
    price      DECIMAL(10,2) NOT NULL,
    cost_price DECIMAL(10,2) NULL,
    type       ENUM('labor','part','fee','other') NOT NULL DEFAULT 'other',

    FOREIGN KEY (history_id) REFERENCES history_records(id) ON DELETE CASCADE,
    FOREIGN KEY (catalog_id) REFERENCES parts_catalog(id)   ON DELETE SET NULL,

    INDEX idx_history_service_items_catalog_id (catalog_id)
);

-- ======================
-- PAYMENTS
-- One row per paid history_record.
-- On insert:
--   1. Flip history_records.payment_status  → 'paid'
--   2. Copy payment_method + payment_reference → history_records (receipt snapshot)
--   3. Write a balance_ledger row (type='payment_in', direction='in')
-- All three steps must run inside a single transaction in the application layer.
-- ======================
CREATE TABLE IF NOT EXISTS payments (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    history_id INT NOT NULL,

    amount_paid       DECIMAL(10,2) NOT NULL,
    payment_method    ENUM('cash','mobile_money','bank_transfer','card','other') NOT NULL DEFAULT 'cash',
    payment_reference VARCHAR(100) NULL,

    recorded_by INT NULL,
    paid_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes       TEXT NULL,

    FOREIGN KEY (history_id)  REFERENCES history_records(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id)           ON DELETE SET NULL,

    INDEX idx_payments_history_id  (history_id),
    INDEX idx_payments_recorded_by (recorded_by),
    INDEX idx_payments_paid_at     (paid_at)
);

-- ======================
-- PAYROLL RECORDS
-- Net pay = base_salary + commission_earnings - deductions.
-- When status flips to 'paid', write a balance_ledger row
-- (type='payroll', direction='out') in the application layer.
-- ======================
CREATE TABLE IF NOT EXISTS payroll_records (
    id INT AUTO_INCREMENT PRIMARY KEY,

    target_type    ENUM('mechanic','staff_admin') NOT NULL,
    mechanic_id    INT NULL,
    staff_admin_id INT NULL,

    employee_name  VARCHAR(100) NOT NULL,

    period_start DATE NOT NULL,
    period_end   DATE NOT NULL,

    total_jobs          INT           NOT NULL DEFAULT 0,
    gross_job_revenue   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    base_salary         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    commission_rate     DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    commission_earnings DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    deductions          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    net_pay             DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    status ENUM('draft','approved','paid') NOT NULL DEFAULT 'draft',

    payment_method    ENUM('cash','mobile_money','bank_transfer','other') NULL,
    payment_reference VARCHAR(100) NULL,
    deduction_notes   TEXT NULL,
    notes             TEXT NULL,

    created_by INT NULL,
    paid_at    TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (mechanic_id)    REFERENCES mechanics(id) ON DELETE SET NULL,
    FOREIGN KEY (staff_admin_id) REFERENCES users(id)     ON DELETE SET NULL,
    FOREIGN KEY (created_by)     REFERENCES users(id)     ON DELETE SET NULL,

    INDEX idx_payroll_target_type    (target_type),
    INDEX idx_payroll_mechanic_id    (mechanic_id),
    INDEX idx_payroll_staff_admin_id (staff_admin_id),
    INDEX idx_payroll_period_start   (period_start),
    INDEX idx_payroll_status         (status)
);

-- ======================
-- EXPENSES
-- Operational costs logged by super admin only.
-- Two-step lifecycle:
--   status='active' — expense recorded (liability known) but not yet paid.
--                     Fully editable and deletable. No ledger entry yet.
--   status='paid'   — expense marked paid. Immutable (no edits, no deletes).
--                     Application layer must run inside a single transaction:
--                       1. UPDATE expenses SET status='paid', paid_at=NOW(),
--                                             payment_method=?, payment_reference=?
--                       2. INSERT into balance_ledger
--                            (type='expense', direction='out', amount=expenses.amount,
--                             reference_id=expenses.id)
-- vendor_name captures the supplier or company paid (e.g. "SomaliPower", "City Water").
-- Nullable because petty cash or internal purchases may have no formal vendor.
-- ======================
CREATE TABLE IF NOT EXISTS expenses (
    id           INT AUTO_INCREMENT PRIMARY KEY,

    category     ENUM('electricity','water','cleaning','rent','supplies','other') NOT NULL,
    vendor_name  VARCHAR(150) NULL,        -- company/supplier receiving the payment
    amount       DECIMAL(10,2) NOT NULL,
    expense_date DATE NOT NULL,
    notes        TEXT NULL,

    status            ENUM('active','paid') NOT NULL DEFAULT 'active',
    payment_method    ENUM('cash','mobile_money','bank_transfer','other') NULL,
    payment_reference VARCHAR(100) NULL,   -- e.g. mobile-money transaction ID
    paid_at           TIMESTAMP NULL,      -- set when status flips to 'paid'

    recorded_by  INT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_expenses_category     (category),
    INDEX idx_expenses_expense_date (expense_date),
    INDEX idx_expenses_recorded_by  (recorded_by),
    INDEX idx_expenses_status       (status)
);

-- ======================
-- FEEDBACK TAGS
-- ======================
CREATE TABLE IF NOT EXISTS feedback_tags (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    label       VARCHAR(100) NOT NULL,
    category    ENUM('mechanic','service') NOT NULL,
    is_positive BOOLEAN DEFAULT TRUE,
    is_active   BOOLEAN DEFAULT TRUE,
    sort_order  INT DEFAULT 0,

    INDEX idx_feedback_tags_category (category),
    INDEX idx_feedback_tags_active   (is_active)
);

-- ======================
-- FEEDBACK
-- ======================
CREATE TABLE IF NOT EXISTS feedback (
    id         INT AUTO_INCREMENT PRIMARY KEY,

    request_id INT NULL,
    history_id INT NULL,
    user_id    INT NULL,

    customer_name VARCHAR(100) NULL,
    mechanic_id   INT NULL,
    mechanic_name VARCHAR(100) NULL,

    mechanic_rating TINYINT NULL CHECK (mechanic_rating BETWEEN 1 AND 5),
    service_rating  TINYINT NULL CHECK (service_rating  BETWEEN 1 AND 5),

    comments        TEXT NULL,
    dismissed_count TINYINT DEFAULT 0,
    submitted_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_feedback_request (request_id, user_id),

    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE SET NULL,

    INDEX idx_feedback_history_id  (history_id),
    INDEX idx_feedback_mechanic_id (mechanic_id),
    INDEX idx_feedback_user_id     (user_id)
);

-- ======================
-- FEEDBACK TAG SELECTIONS
-- ======================
CREATE TABLE IF NOT EXISTS feedback_tag_selections (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    feedback_id INT NOT NULL,
    tag_id      INT NOT NULL,

    UNIQUE KEY uq_selection (feedback_id, tag_id),

    FOREIGN KEY (feedback_id) REFERENCES feedback(id)      ON DELETE CASCADE,
    FOREIGN KEY (tag_id)      REFERENCES feedback_tags(id) ON DELETE CASCADE
);

-- ======================
-- SEED: DEFAULT FEEDBACK TAGS
-- ======================
INSERT INTO feedback_tags (label, category, is_positive, sort_order) VALUES
('Friendly',             'mechanic', 1, 1),
('Professional',         'mechanic', 1, 2),
('Solved issue quickly', 'mechanic', 1, 3),
('Good workmanship',     'mechanic', 1, 4),
('Arrived late',         'mechanic', 0, 5),
('Poor communication',   'mechanic', 0, 6),
('Unprofessional',       'mechanic', 0, 7),
('Did not fix issue',    'mechanic', 0, 8),
('Easy booking',         'service',  1, 1),
('Fast response',        'service',  1, 2),
('Good customer support','service',  1, 3),
('Issue resolved',       'service',  1, 4),
('Long waiting time',    'service',  0, 5),
('Expensive',            'service',  0, 6),
('Hard to book',         'service',  0, 7),
('Poor follow-up',       'service',  0, 8);

-- ======================
-- INDEXES (PERFORMANCE)
-- ======================
CREATE INDEX IF NOT EXISTS idx_requests_user_id      ON requests(user_id);
CREATE INDEX IF NOT EXISTS idx_requests_walkin_id    ON requests(walkin_id);
CREATE INDEX IF NOT EXISTS idx_requests_mechanic_id  ON requests(mechanic_id);
CREATE INDEX IF NOT EXISTS idx_requests_assigned_by  ON requests(assigned_by);
CREATE INDEX IF NOT EXISTS idx_requests_completed_by ON requests(completed_by);
CREATE INDEX IF NOT EXISTS idx_services_request_id   ON services(request_id);

-- ======================
-- SUPER ADMIN SETUP
-- Run once after importing to promote the shop owner account.
--
--   UPDATE users
--   SET role = 'admin', is_superadmin = TRUE
--   WHERE email = 'owner@example.com';
--
-- To verify:
--   SELECT id, full_name, email, role, is_superadmin, is_disabled FROM users;
-- ======================