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

    -- Super admin flag
    -- TRUE  = shop owner / root admin; created via SQL on setup.
    --         Can access super_admin/ portal and manage staff admins.
    -- FALSE = regular customer OR staff admin (determined by `role`).
    --         Staff admins cannot create other admins.
    -- Only one super admin is expected per deployment.
    -- Physical promotion restricted to DB admin via direct SQL UPDATE.
    -- Uniqueness is enforced by the trg_one_superadmin_insert /
    -- trg_one_superadmin_update triggers below.
    is_superadmin BOOLEAN NOT NULL DEFAULT FALSE,

    -- Account control
    -- is_disabled : set by super admin to temporarily block a customer or staff admin's login.
    --               Super admin account cannot be disabled through the UI.
    is_disabled   BOOLEAN NOT NULL DEFAULT FALSE,

    -- Session tracking (mirrors mechanic table pattern)
    last_login  TIMESTAMP NULL,
    last_logout TIMESTAMP NULL,

    -- Cancel spam prevention (registered customers only; walk-ins excluded)
    -- cancel_count_today    : number of cancellations made on cancel_date.
    --                         Reset to 0 automatically when cancel_date < CURDATE().
    -- cancel_date           : the calendar date the current count applies to.
    -- cancel_blocked_until  : if NOT NULL and > NOW(), the customer cannot submit
    --                         a new request. Set to NOW() + 5 minutes when
    --                         cancel_count_today reaches 2. NULL otherwise.
    cancel_count_today   TINYINT  NOT NULL DEFAULT 0,
    cancel_date          DATE     NULL,
    cancel_blocked_until DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ======================
-- TRIGGER: ENFORCE SINGLE SUPER ADMIN (INSERT)
-- Fires before any INSERT on users.
-- Blocks the insert if is_superadmin = TRUE and a super admin already exists.
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
-- Fires before any UPDATE on users.
-- Blocks the update if it would promote a second row to is_superadmin = TRUE.
-- Allows the existing super admin to update their own row freely.
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

    -- Vehicle info (mirrors the vehicle fields on the requests table)
    -- vehicle_make: selected from a predefined list; 'Other' allows free-text entry via vehicle_make_other.
    -- vehicle_make_other: used only when vehicle_make = 'Other'.
    -- vehicle_model: always free text.
    -- vehicle_year: 4-digit year, e.g. 2019. NULL if not provided.
    vehicle_make       VARCHAR(100) NULL,
    vehicle_make_other VARCHAR(100) NULL,
    vehicle_model      VARCHAR(100) NULL,
    vehicle_year       SMALLINT     NULL,

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

    -- Vehicle info
    -- vehicle_make: selected from a predefined list; 'Other' allows free-text entry via vehicle_make_other.
    -- vehicle_make_other: used only when vehicle_make = 'Other'.
    -- vehicle_model: always free text (too many models to enumerate).
    -- vehicle_year: 4-digit year, e.g. 2019. NULL if not provided.
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

    -- 'cancelled' : set by the customer while status is still 'pending'.
    --               Only pending requests can be cancelled — assigned/in_progress cannot.
    --               Record is retained for auditing; filtered out of active views.
    status ENUM('pending','assigned','in_progress','completed','rejected','cancelled') DEFAULT 'pending',

    rejection_reason TEXT NULL,

    mechanic_id INT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    hidden_by_user  BOOLEAN DEFAULT FALSE,
    hidden_by_admin BOOLEAN DEFAULT FALSE,

    FOREIGN KEY (user_id)     REFERENCES users(id)            ON DELETE CASCADE,
    FOREIGN KEY (walkin_id)   REFERENCES walkin_customers(id) ON DELETE CASCADE,
    FOREIGN KEY (mechanic_id) REFERENCES mechanics(id)        ON DELETE SET NULL
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
-- SERVICE ITEMS (DETAILS)
-- ======================
CREATE TABLE IF NOT EXISTS service_items (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    item_name  VARCHAR(100),
    price      DECIMAL(10,2),

    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
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
-- ======================
CREATE TABLE IF NOT EXISTS history_records (
    id INT AUTO_INCREMENT PRIMARY KEY,

    request_id INT NOT NULL,

    user_id         INT NULL,
    walkin_id       INT NULL,
    is_walkin       BOOLEAN NOT NULL DEFAULT FALSE,
    customer_name   VARCHAR(100),
    customer_phone  VARCHAR(20),
    customer_gender ENUM('male','female') NULL,
    customer_dob    DATE NULL,

    mechanic_id     INT NULL,
    mechanic_name   VARCHAR(100),
    mechanic_gender ENUM('male','female') NULL,

    -- Vehicle info snapshot (copied from requests at completion/rejection/cancellation time)
    -- Stored as resolved display value: if make was 'Other', vehicle_make holds vehicle_make_other.
    vehicle_make  VARCHAR(100) NULL,
    vehicle_model VARCHAR(100) NULL,
    vehicle_year  SMALLINT     NULL,

    problem_type VARCHAR(100),
    description  TEXT,
    image        VARCHAR(255),

    latitude  DECIMAL(10,8),
    longitude DECIMAL(11,8),

    diagnosis TEXT,

    -- 'cancelled' : customer cancelled while request was still pending.
    --               mechanic_id/mechanic_name will be NULL; total_amount will be 0.00.
    status           ENUM('completed','rejected','cancelled') NOT NULL,
    rejection_reason TEXT,
    total_amount     DECIMAL(10,2) DEFAULT 0.00,

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
    INDEX idx_history_vehicle_make    (vehicle_make)
);

-- ======================
-- HISTORY SERVICE ITEMS
-- ======================
CREATE TABLE IF NOT EXISTS history_service_items (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    history_id INT NOT NULL,
    item_name  VARCHAR(100),
    price      DECIMAL(10,2),

    FOREIGN KEY (history_id) REFERENCES history_records(id) ON DELETE CASCADE
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
CREATE INDEX IF NOT EXISTS idx_requests_user_id     ON requests(user_id);
CREATE INDEX IF NOT EXISTS idx_requests_walkin_id   ON requests(walkin_id);
CREATE INDEX IF NOT EXISTS idx_requests_mechanic_id ON requests(mechanic_id);
CREATE INDEX IF NOT EXISTS idx_services_request_id  ON services(request_id);

-- ======================
-- SUPER ADMIN SETUP
-- Run once after importing to promote the shop owner account.
-- This is the ONLY way to create a super admin — no UI exists for this.
-- The triggers above will prevent a second super admin from ever being set.
--
--   UPDATE users
--   SET role = 'admin', is_superadmin = TRUE
--   WHERE email = 'owner@example.com';
--
-- To verify:
--   SELECT id, full_name, email, role, is_superadmin, is_disabled FROM users;
-- ======================

-- ======================
-- EXISTING DB MIGRATION
-- If upgrading an existing install, run these instead of reimporting:
--
-- 1. Users table — super admin & account control flags:
--   ALTER TABLE users ADD COLUMN is_superadmin BOOLEAN NOT NULL DEFAULT FALSE;
--   ALTER TABLE users ADD COLUMN is_disabled   BOOLEAN NOT NULL DEFAULT FALSE;
--
-- 2. Users table — session tracking:
--   ALTER TABLE users ADD COLUMN last_login  TIMESTAMP NULL;
--   ALTER TABLE users ADD COLUMN last_logout TIMESTAMP NULL;
--
-- 3. Users table — cancel spam prevention:
--   ALTER TABLE users ADD COLUMN cancel_count_today   TINYINT  NOT NULL DEFAULT 0;
--   ALTER TABLE users ADD COLUMN cancel_date          DATE     NULL;
--   ALTER TABLE users ADD COLUMN cancel_blocked_until DATETIME NULL;
--
-- 4. Vehicle fields on requests:
--   ALTER TABLE requests
--     ADD COLUMN vehicle_make       VARCHAR(100) NULL AFTER walkin_id,
--     ADD COLUMN vehicle_make_other VARCHAR(100) NULL AFTER vehicle_make,
--     ADD COLUMN vehicle_model      VARCHAR(100) NULL AFTER vehicle_make_other,
--     ADD COLUMN vehicle_year       SMALLINT     NULL AFTER vehicle_model;
--
-- 5. Vehicle snapshot fields on history_records:
--   ALTER TABLE history_records
--     ADD COLUMN vehicle_make  VARCHAR(100) NULL AFTER mechanic_gender,
--     ADD COLUMN vehicle_model VARCHAR(100) NULL AFTER vehicle_make,
--     ADD COLUMN vehicle_year  SMALLINT     NULL AFTER vehicle_model;
--   CREATE INDEX idx_history_vehicle_make ON history_records(vehicle_make);
--
-- 6. Cancel feature — status enum expansion:
--   ALTER TABLE requests
--     MODIFY COLUMN status ENUM('pending','assigned','in_progress','completed','rejected','cancelled') DEFAULT 'pending';
--   ALTER TABLE history_records
--     MODIFY COLUMN status ENUM('completed','rejected','cancelled') NOT NULL;
--
-- 7. Walk-in customer vehicle fields:
--   ALTER TABLE walkin_customers
--     ADD COLUMN vehicle_make       VARCHAR(100) NULL AFTER gender,
--     ADD COLUMN vehicle_make_other VARCHAR(100) NULL AFTER vehicle_make,
--     ADD COLUMN vehicle_model      VARCHAR(100) NULL AFTER vehicle_make_other,
--     ADD COLUMN vehicle_year       SMALLINT     NULL AFTER vehicle_model;
--
-- 8. Super admin uniqueness triggers (run after step 1):
--
--   DROP TRIGGER IF EXISTS trg_one_superadmin_insert;
--   DELIMITER $$
--   CREATE TRIGGER trg_one_superadmin_insert
--   BEFORE INSERT ON users
--   FOR EACH ROW
--   BEGIN
--       IF NEW.is_superadmin = TRUE THEN
--           IF (SELECT COUNT(*) FROM users WHERE is_superadmin = TRUE) > 0 THEN
--               SIGNAL SQLSTATE '45000'
--                   SET MESSAGE_TEXT = 'Only one super admin is allowed per deployment.';
--           END IF;
--       END IF;
--   END$$
--   DELIMITER ;
--
--   DROP TRIGGER IF EXISTS trg_one_superadmin_update;
--   DELIMITER $$
--   CREATE TRIGGER trg_one_superadmin_update
--   BEFORE UPDATE ON users
--   FOR EACH ROW
--   BEGIN
--       IF NEW.is_superadmin = TRUE AND OLD.is_superadmin = FALSE THEN
--           IF (SELECT COUNT(*) FROM users WHERE is_superadmin = TRUE) > 0 THEN
--               SIGNAL SQLSTATE '45000'
--                   SET MESSAGE_TEXT = 'Only one super admin is allowed per deployment.';
--           END IF;
--       END IF;
--   END$$
--   DELIMITER ;
-- ======================