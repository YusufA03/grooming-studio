-- =====================================================================
-- Executive Grooming Studio - database migration of 15 October 2026
-- Adds: allergy notes, a price snapshot on each booking, the audit trail (BookingLog)
-- and loyalty points (LoyaltyLedger).
--
-- HOW TO RUN: phpMyAdmin > click YOUR database > Import > choose this file.
-- It is SAFE TO RUN MORE THAN ONCE and it does NOT delete any data.
-- (A brand-new install does not need it: schema.sql and schema_hosting.sql already include all of this.)
-- =====================================================================

-- 1. A dedicated field for allergies and sensitivities (a safety feature: the stylist sees it highlighted).
ALTER TABLE Booking ADD COLUMN IF NOT EXISTS allergies VARCHAR(500) NULL AFTER notes;

-- 2. The price the client was charged, kept on the booking so reports and loyalty points stay correct
--    even if a service's price changes later. Existing bookings get the service's current price.
ALTER TABLE Booking ADD COLUMN IF NOT EXISTS price_charged DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER allergies;
UPDATE Booking b JOIN Service s ON s.service_id = b.service_id SET b.price_charged = s.price WHERE b.price_charged = 0;

-- 3. The audit trail.
CREATE TABLE IF NOT EXISTS BookingLog (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    action ENUM('created','rescheduled','cancelled','completed','no-show','reminder-sent') NOT NULL,
    actor VARCHAR(120) NOT NULL,
    detail VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_log_booking (booking_id, created_at),
    CONSTRAINT fk_log_booking FOREIGN KEY (booking_id) REFERENCES Booking(booking_id)
);

-- 4. Loyalty points.
CREATE TABLE IF NOT EXISTS LoyaltyLedger (
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

-- 5. Appointments that were already completed earn their points now (1 point for every R10).
INSERT IGNORE INTO LoyaltyLedger (client_id, booking_id, points, reason, note, actor, created_at)
SELECT b.client_id, b.booking_id, FLOOR(b.price_charged / 10), 'earned',
       'Completed before loyalty points were introduced', 'System', NOW()
FROM Booking b
WHERE b.status = 'completed' AND FLOOR(b.price_charged / 10) > 0;
