-- =====================================================================
-- Executive Grooming Studio - database migration of 16 October 2026
-- Adds: optional client accounts (Client.password_hash), admin access granted from the website
-- (Administrator.client_id) and one-time email links (AccountToken).
--
-- HOW TO RUN: phpMyAdmin > click YOUR database > Import > choose this file.
-- SAFE TO RUN MORE THAN ONCE. It deletes nothing. Take a backup first (tools/backup_db.php).
-- Run migration_2026_10_15.sql first if you have not already.
-- (A brand-new install does not need it: schema.sql already includes all of this.)
-- =====================================================================

-- 1. Optional client accounts.
ALTER TABLE Client ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NULL;

-- 2. Admin access granted from the website: an administrator may be a client account.
ALTER TABLE Administrator ADD COLUMN IF NOT EXISTS client_id INT NULL;
ALTER TABLE Administrator MODIFY password_hash VARCHAR(255) NULL;
ALTER TABLE Administrator ADD UNIQUE INDEX IF NOT EXISTS uq_admin_client (client_id);
ALTER TABLE Administrator ADD FOREIGN KEY IF NOT EXISTS fk_admin_client (client_id) REFERENCES Client(client_id);
ALTER TABLE Administrator ADD CONSTRAINT IF NOT EXISTS chk_admin_login CHECK (password_hash IS NOT NULL OR client_id IS NOT NULL);

-- 3. One-time email links (choose a password).
CREATE TABLE IF NOT EXISTS AccountToken (
    token_id INT AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    INDEX idx_token_email (email, created_at)
);

-- =====================================================================
-- STAGE 2: stylists - the services each one performs, working hours as blocks, and a "taking bookings" switch.
-- Existing stylists perform ALL services until you untick some. Their old hours text becomes blocks.
-- =====================================================================
ALTER TABLE Stylist ADD COLUMN IF NOT EXISTS accepting_bookings TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE Stylist ADD COLUMN IF NOT EXISTS working_hours VARCHAR(255) NULL;   -- (already there on an old database)
ALTER TABLE Stylist MODIFY working_hours VARCHAR(255) NULL;                       -- the old text is no longer required

CREATE TABLE IF NOT EXISTS StylistService (
    stylist_id INT NOT NULL,
    service_id INT NOT NULL,
    PRIMARY KEY (stylist_id, service_id),
    CONSTRAINT fk_ss_stylist FOREIGN KEY (stylist_id) REFERENCES Stylist(stylist_id) ON DELETE CASCADE,
    CONSTRAINT fk_ss_service FOREIGN KEY (service_id) REFERENCES Service(service_id)
);

CREATE TABLE IF NOT EXISTS WorkingBlock (
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

-- Every stylist who has no services yet performs all of them.
INSERT IGNORE INTO StylistService (stylist_id, service_id)
SELECT st.stylist_id, sv.service_id FROM Stylist st CROSS JOIN Service sv
WHERE NOT EXISTS (SELECT 1 FROM StylistService x WHERE x.stylist_id = st.stylist_id);

-- Old hours text -> blocks. "Off", or text that cannot be read, becomes "not taking bookings".
UPDATE Stylist SET accepting_bookings = 0
WHERE working_hours IS NOT NULL
  AND working_hours NOT REGEXP '^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)-(Mon|Tue|Wed|Thu|Fri|Sat|Sun) [0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2}$';

INSERT INTO WorkingBlock (stylist_id, weekday, start_time, end_time)
SELECT p.stylist_id, n.d, p.t_open, p.t_close
FROM (
    SELECT stylist_id,
           FIELD(LEFT(working_hours, 3), 'Mon','Tue','Wed','Thu','Fri','Sat','Sun')      AS f,
           FIELD(SUBSTRING(working_hours, 5, 3), 'Mon','Tue','Wed','Thu','Fri','Sat','Sun') AS l,
           CASE WHEN working_hours REGEXP '^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)-(Mon|Tue|Wed|Thu|Fri|Sat|Sun) [0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2}$' THEN TIME(CONCAT(SUBSTRING(working_hours, 9, 5), ':00')) END  AS t_open,
           CASE WHEN working_hours REGEXP '^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)-(Mon|Tue|Wed|Thu|Fri|Sat|Sun) [0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2}$' THEN TIME(CONCAT(SUBSTRING(working_hours, 15, 5), ':00')) END AS t_close
    FROM Stylist
    WHERE working_hours REGEXP '^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)-(Mon|Tue|Wed|Thu|Fri|Sat|Sun) [0-9]{2}:[0-9]{2}-[0-9]{2}:[0-9]{2}$'
      AND NOT EXISTS (SELECT 1 FROM WorkingBlock w WHERE w.stylist_id = Stylist.stylist_id)
) p
JOIN (SELECT 1 AS d UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7) n
  ON ((p.f <= p.l AND n.d BETWEEN p.f AND p.l) OR (p.f > p.l AND (n.d >= p.f OR n.d <= p.l)))
WHERE p.t_close > p.t_open;

-- The old text is no longer used. Clearing it makes this migration safe to run again.
UPDATE Stylist SET working_hours = NULL WHERE working_hours IS NOT NULL;
