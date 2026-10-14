-- =====================================================================
-- Executive Grooming Studio - database setup
-- Implements the schema from Phase 4, Section 4.5, plus sample data.
-- HOSTING VERSION: import this file in phpMyAdmin (Import tab) AFTER selecting your database. It is safe to re-import:
-- it drops and recreates the tables each time (this DELETES any data).
-- =====================================================================

-- (For hosting: the database already exists. In phpMyAdmin, click YOUR database first,
-- then use the Import tab. This file does not create or switch databases.)

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS LoyaltyLedger;
DROP TABLE IF EXISTS BookingLog;
DROP TABLE IF EXISTS Booking;
DROP TABLE IF EXISTS Client;
DROP TABLE IF EXISTS Stylist;
DROP TABLE IF EXISTS Service;
DROP TABLE IF EXISTS Administrator;
SET FOREIGN_KEY_CHECKS = 1;

-- email is optional: a client who phones the salon may not have one.
CREATE TABLE Client (
    client_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NULL,
    phone VARCHAR(20)
);

-- NOTE: email and password_hash are included here so stylists can log in
-- (the Phase 4 design has stylists log in, via the User base class).
CREATE TABLE Stylist (
    stylist_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    specialty VARCHAR(100),
    working_hours VARCHAR(255) NOT NULL
);

CREATE TABLE Service (
    service_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    duration_minutes INT NOT NULL CHECK (duration_minutes > 0),
    price DECIMAL(8,2) NOT NULL CHECK (price > 0),
    image VARCHAR(255) NULL   -- file name of the service's picture (kept in public/uploads/services)
);

CREATE TABLE Administrator (
    admin_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL
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
-- working_hours format used by the app: 'Mon-Fri 09:00-17:00'
-- ---------------------------------------------------------------------
INSERT INTO Service (name, description, duration_minutes, price) VALUES
('Men''s Haircut', 'Classic cut with wash and style.', 30, 150.00),
('Beard Trim & Shape', 'Trim, line-up and shaping of the beard.', 20, 90.00),
('Hair Colour', 'Full colour application.', 90, 650.00),
('Deep Conditioning Treatment', 'Restorative treatment for dry or damaged hair.', 45, 350.00),
('Executive Grooming Package', 'Haircut, beard trim and conditioning treatment.', 90, 550.00);

INSERT INTO Stylist (name, email, password_hash, specialty, working_hours) VALUES
('Thandi Mokoena', 'thandi@executivegrooming.test', 'SET_LATER', 'Colour', 'Mon-Fri 09:00-17:00'),
('Rohan Naidoo', 'rohan@executivegrooming.test', 'SET_LATER', 'Cuts and beards', 'Mon-Fri 09:00-17:00'),
('Sipho Dlamini', 'sipho@executivegrooming.test', 'SET_LATER', 'Treatments', 'Tue-Sat 10:00-18:00');
