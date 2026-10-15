-- =====================================================================
-- Executive Grooming Studio - database setup (SAFE VERSION, 15 October 2026)
-- Implements the schema from Phase 4, Section 4.5, plus sample data.
--
-- THIS FILE ONLY CREATES. IT NEVER DELETES ANYTHING.
--   * A NEW, EMPTY database  ->  import this file.
--   * A database that ALREADY HAS tables  ->  the import stops with an error and changes
--     nothing. To bring an existing database up to date, import
--     migration_2026_10_15.sql instead (it keeps all your data).
-- =====================================================================

CREATE DATABASE IF NOT EXISTS grooming_studio
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE grooming_studio;

-- email is optional: a client who phones the salon may not have one.
CREATE TABLE Client (
    client_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NULL,
    phone VARCHAR(20),
    -- Set only when the client has made an account (optional). Guests never have one.
    password_hash VARCHAR(255) NULL
);

-- NOTE: email and password_hash are included here so stylists can log in
-- (the Phase 4 design has stylists log in, via the User base class).
CREATE TABLE Stylist (
    stylist_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    -- 0 = not offered for new bookings (bookings already made stay as they are)
    accepting_bookings TINYINT(1) NOT NULL DEFAULT 1,
    -- Legacy: the old hours text. No longer used by the website (hours are WorkingBlock rows). Kept so that
    -- migration_2026_10_16.sql can run on any database without an error.
    working_hours VARCHAR(255) NULL
);

CREATE TABLE Service (
    service_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    duration_minutes INT NOT NULL CHECK (duration_minutes > 0),
    price DECIMAL(8,2) NOT NULL CHECK (price > 0),
    image VARCHAR(255) NULL   -- file name of the service's picture (kept in public/uploads/services)
);

-- Which services each stylist performs. A stylist is offered for a service only if it is ticked here.
CREATE TABLE StylistService (
    stylist_id INT NOT NULL,
    service_id INT NOT NULL,
    PRIMARY KEY (stylist_id, service_id),
    CONSTRAINT fk_ss_stylist FOREIGN KEY (stylist_id) REFERENCES Stylist(stylist_id) ON DELETE CASCADE,
    CONSTRAINT fk_ss_service FOREIGN KEY (service_id) REFERENCES Service(service_id)
);

-- A stylist's working hours as BLOCKS: any number per day, so a lunch break is just two blocks.
-- weekday is 1 = Monday ... 7 = Sunday. An appointment must fit entirely inside ONE block.
CREATE TABLE WorkingBlock (
    block_id INT AUTO_INCREMENT PRIMARY KEY,
    stylist_id INT NOT NULL,
    weekday TINYINT NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    CONSTRAINT chk_block_day CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT chk_block_times CHECK (end_time > start_time),
    INDEX idx_block_stylist (stylist_id, weekday),
    CONSTRAINT fk_block_stylist FOREIGN KEY (stylist_id) REFERENCES Stylist(stylist_id) ON DELETE CASCADE
);


CREATE TABLE Administrator (
    admin_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    -- An administrator either has a password of their own (made with tools/set_password.php), or is a
    -- CLIENT account that was given admin access from the website (client_id), and signs in with that password.
    password_hash VARCHAR(255) NULL,
    client_id INT NULL UNIQUE,
    CONSTRAINT chk_admin_login CHECK (password_hash IS NOT NULL OR client_id IS NOT NULL),
    CONSTRAINT fk_admin_client FOREIGN KEY (client_id) REFERENCES Client(client_id)
);

-- One-time links by email: they let a person choose a password (new account, forgotten password,
-- or a new administrator). Only the SHA-256 of the link's secret is stored.
CREATE TABLE AccountToken (
    token_id INT AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    INDEX idx_token_email (email, created_at)
);

CREATE TABLE Booking (
    booking_id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    stylist_id INT NOT NULL,
    service_id INT NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    status ENUM('confirmed','completed','no-show','cancelled')
        NOT NULL DEFAULT 'confirmed',
    notes TEXT,
    allergies VARCHAR(500) NULL,
    price_charged DECIMAL(8,2) NOT NULL DEFAULT 0,
    inspiration_image VARCHAR(255),
    reminder_sent BOOLEAN NOT NULL DEFAULT FALSE,
    manage_token CHAR(64) NOT NULL UNIQUE,
    CONSTRAINT fk_client FOREIGN KEY (client_id) REFERENCES Client(client_id),
    CONSTRAINT fk_stylist FOREIGN KEY (stylist_id) REFERENCES Stylist(stylist_id),
    CONSTRAINT fk_service FOREIGN KEY (service_id) REFERENCES Service(service_id),
    CONSTRAINT chk_times CHECK (end_time > start_time),
    CONSTRAINT chk_price CHECK (price_charged >= 0)
);

-- Audit trail: one row for every important thing that happens to a booking (who did it, and when).
CREATE TABLE BookingLog (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    action ENUM('created','rescheduled','cancelled','completed','no-show','reminder-sent') NOT NULL,
    actor VARCHAR(120) NOT NULL,
    detail VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_log_booking (booking_id, created_at),
    CONSTRAINT fk_log_booking FOREIGN KEY (booking_id) REFERENCES Booking(booking_id)
);

-- Loyalty points: every earning, redemption and correction is a row, so a balance can always be explained.
-- booking_id is set only for points earned from a booking and is UNIQUE, so one booking can earn points only once.
CREATE TABLE LoyaltyLedger (
    ledger_id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    booking_id INT NULL UNIQUE,
    points INT NOT NULL CHECK (points <> 0),
    reason ENUM('earned','redeemed','adjustment') NOT NULL,
    note VARCHAR(255) NULL,
    actor VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_ledger_client (client_id),
    CONSTRAINT fk_ledger_client FOREIGN KEY (client_id) REFERENCES Client(client_id),
    CONSTRAINT fk_ledger_booking FOREIGN KEY (booking_id) REFERENCES Booking(booking_id)
);

-- ---------------------------------------------------------------------
-- Sample data (fictional). Stylist passwords are placeholders; real
-- passwords are set later with PHP's password_hash().
-- ---------------------------------------------------------------------
INSERT INTO Service (name, description, duration_minutes, price) VALUES
('Men''s Haircut', 'Classic cut with wash and style.', 30, 150.00),
('Beard Trim & Shape', 'Trim, line-up and shaping of the beard.', 20, 90.00),
('Hair Colour', 'Full colour application.', 90, 650.00),
('Deep Conditioning Treatment', 'Restorative treatment for dry or damaged hair.', 45, 350.00),
('Executive Grooming Package', 'Haircut, beard trim and conditioning treatment.', 90, 550.00);

INSERT INTO Stylist (name, email, password_hash) VALUES
('Thandi Mokoena', 'thandi@executivegrooming.test', 'SET_LATER'),
('Rohan Naidoo', 'rohan@executivegrooming.test', 'SET_LATER'),
('Sipho Dlamini', 'sipho@executivegrooming.test', 'SET_LATER');

-- In the sample data every stylist performs every service.
INSERT INTO StylistService (stylist_id, service_id) SELECT st.stylist_id, sv.service_id FROM Stylist st CROSS JOIN Service sv;

-- Thandi and Rohan: Monday to Friday 09:00-17:00. Sipho: Tuesday to Saturday 10:00-18:00.
INSERT INTO WorkingBlock (stylist_id, weekday, start_time, end_time)
SELECT st.stylist_id, d.n, '09:00:00', '17:00:00'
FROM Stylist st JOIN (SELECT 1 AS n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) d
WHERE st.email IN ('thandi@executivegrooming.test', 'rohan@executivegrooming.test');
INSERT INTO WorkingBlock (stylist_id, weekday, start_time, end_time)
SELECT st.stylist_id, d.n, '10:00:00', '18:00:00'
FROM Stylist st JOIN (SELECT 2 AS n UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) d
WHERE st.email = 'sipho@executivegrooming.test';
