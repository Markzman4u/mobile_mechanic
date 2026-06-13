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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ======================
-- WALK-IN CUSTOMERS
-- ======================
CREATE TABLE IF NOT EXISTS walkin_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ======================
-- MECHANICS TABLE
-- ======================
CREATE TABLE IF NOT EXISTS mechanics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    status ENUM('available','busy') DEFAULT 'available'
);

-- ======================
-- REQUESTS TABLE
-- ======================
CREATE TABLE IF NOT EXISTS requests (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Either user OR walk-in
    user_id INT NULL,
    walkin_id INT NULL,

    problem_type VARCHAR(100),
    description TEXT,
    image VARCHAR(255),

    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),

    diagnosis TEXT,

    status ENUM('pending','assigned','in_progress','completed','rejected') DEFAULT 'pending',

    rejection_reason TEXT NULL,

    mechanic_id INT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Soft delete flags
    -- Physical deletion restricted to DB admin/manager only
    hidden_by_user BOOLEAN DEFAULT FALSE,
    hidden_by_admin BOOLEAN DEFAULT FALSE,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (walkin_id) REFERENCES walkin_customers(id) ON DELETE CASCADE,
    FOREIGN KEY (mechanic_id) REFERENCES mechanics(id) ON DELETE SET NULL
);

-- ======================
-- SERVICES (FINAL BILL)
-- ======================
CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    service_name VARCHAR(100),
    total_amount DECIMAL(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE
);

-- ======================
-- SERVICE ITEMS (DETAILS)
-- ======================
CREATE TABLE IF NOT EXISTS service_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    item_name VARCHAR(100),
    price DECIMAL(10,2),

    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
);

-- ======================
-- ACTIVITIES (LOGS)
-- ======================
CREATE TABLE IF NOT EXISTS activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ======================
-- NOTIFICATIONS TABLE
-- ======================
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT,
    request_id INT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,

    INDEX idx_user_id (user_id),
    INDEX idx_is_read (is_read)
);

-- ======================
-- HISTORY RECORDS
-- Archive of completed and rejected jobs.
-- No FK constraints intentional: this is an archive table.
-- Records must survive deletion of requests, users, and mechanics.
-- ======================
CREATE TABLE IF NOT EXISTS history_records (
    id INT AUTO_INCREMENT PRIMARY KEY,

    request_id INT NOT NULL,

    -- Customer snapshot (preserved even if user/walkin is deleted)
    user_id INT NULL,
    walkin_id INT NULL,
    customer_name VARCHAR(100),
    customer_phone VARCHAR(20),

    -- Mechanic snapshot (preserved even if mechanic is deleted)
    mechanic_id INT NULL,
    mechanic_name VARCHAR(100),

    problem_type VARCHAR(100),
    description TEXT,
    image VARCHAR(255),

    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),

    diagnosis TEXT,

    status ENUM('completed','rejected') NOT NULL,
    rejection_reason TEXT,
    total_amount DECIMAL(10,2) DEFAULT 0.00,

    -- request_created_at : when customer originally submitted the job
    -- completed_at       : when the job was actually finished/rejected
    -- history_created_at : when this record was archived (auto-set)
    request_created_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    history_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Soft delete flags
    -- Physical deletion restricted to DB admin/manager only
    hidden_by_user BOOLEAN DEFAULT FALSE,
    hidden_by_admin BOOLEAN DEFAULT FALSE,

    INDEX idx_history_mechanic_id (mechanic_id),
    INDEX idx_history_status (status),
    INDEX idx_history_problem_type (problem_type),
    INDEX idx_history_request_created (request_created_at),
    INDEX idx_history_completed_at (completed_at)
);

-- ======================
-- HISTORY SERVICE ITEMS
-- Snapshot of service items linked to a history record
-- ======================
CREATE TABLE IF NOT EXISTS history_service_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    history_id INT NOT NULL,
    item_name VARCHAR(100),
    price DECIMAL(10,2),

    FOREIGN KEY (history_id) REFERENCES history_records(id) ON DELETE CASCADE
);

-- ======================
-- INDEXES (PERFORMANCE)
-- ======================
CREATE INDEX IF NOT EXISTS idx_requests_user_id    ON requests(user_id);
CREATE INDEX IF NOT EXISTS idx_requests_walkin_id  ON requests(walkin_id);
CREATE INDEX IF NOT EXISTS idx_requests_mechanic_id ON requests(mechanic_id);
CREATE INDEX IF NOT EXISTS idx_services_request_id ON services(request_id);