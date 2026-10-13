-- =====================================================================
-- Executive Grooming Studio - database setup
-- Implements the schema from Phase 4, Section 4.5, plus sample data.
-- Import this file in phpMyAdmin (Import tab). It is safe to re-import:
-- it drops and recreates the tables each time (this DELETES any data).
-- =====================================================================

CREATE DATABASE IF NOT EXISTS grooming_studio
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE grooming_studio;

SET FOREIGN_KEY_CHECKS = 0;
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
    inspiration_image VARCHAR(255),
    reminder_sent BOOLEAN NOT NULL DEFAULT FALSE,
    manage_token CHAR(64) NOT NULL UNIQUE,
    CONSTRAINT fk_client FOREIGN KEY (client_id) REFERENCES Client(client_id),
    CONSTRAINT fk_stylist FOREIGN KEY (stylist_id) REFERENCES Stylist(stylist_id),
    CONSTRAINT fk_service FOREIGN KEY (service_id) REFERENCES Service(service_id),
    CONSTRAINT chk_times CHECK (end_time > start_time)
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
