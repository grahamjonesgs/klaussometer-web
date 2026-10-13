-- Schema for the `readings` database, exported from the live server (MariaDB 11.8).
-- Only needed when building an empty database; a restore from a nightly backup
-- (see Setup/README.md) already contains the tables and events.
--
--   sudo mariadb < Setup/database/schema.sql
--
-- Replace the CHANGE_ME passwords first. The server's clock and database run in UTC.

CREATE DATABASE IF NOT EXISTS readings;
USE readings;

-- Raw sensor readings, written by Node-RED from MQTT
CREATE TABLE IF NOT EXISTS rec_data (
    room_id VARCHAR(50) NOT NULL,
    value FLOAT NOT NULL,
    type VARCHAR(50) NOT NULL,
    dt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (room_id, type, dt),
    KEY idx_dt (dt)
) ENGINE=InnoDB ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8;

CREATE TABLE IF NOT EXISTS hourly_avg (
    room_id VARCHAR(50) NOT NULL,
    type VARCHAR(50) NOT NULL,
    dt_hour DATETIME NOT NULL,
    avg_value FLOAT NOT NULL,
    PRIMARY KEY (room_id, type, dt_hour),
    KEY idx_dt_hour (dt_hour)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daily_avg (
    room_id VARCHAR(50) NOT NULL,
    type VARCHAR(50) NOT NULL,
    dt_day DATE NOT NULL,
    avg_value FLOAT NOT NULL,
    PRIMARY KEY (room_id, type, dt_day),
    KEY idx_dt_day (dt_day)
) ENGINE=InnoDB;

-- Written by the events below; shown on system_status.php
CREATE TABLE IF NOT EXISTS event_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    event_name VARCHAR(100),
    execution_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    rows_affected INT,
    status VARCHAR(20),
    error_message TEXT
) ENGINE=InnoDB;

-- Scheduled aggregation (needs event_scheduler = ON, see README)
DELIMITER //

CREATE EVENT IF NOT EXISTS hourly_aggregation_event
ON SCHEDULE EVERY 1 HOUR
DO
BEGIN
    DECLARE rows_count INT DEFAULT 0;

    INSERT INTO hourly_avg (room_id, type, dt_hour, avg_value)
    SELECT room_id, type, DATE_FORMAT(dt, '%Y-%m-%d %H:30:00') AS dt_hour, AVG(value) AS avg_value
    FROM rec_data
    WHERE dt >= NOW() - INTERVAL 2 HOUR AND dt < NOW()
    GROUP BY room_id, type, dt_hour
    ON DUPLICATE KEY UPDATE avg_value = VALUES(avg_value);

    SET rows_count = ROW_COUNT();
    INSERT INTO event_log (event_name, rows_affected, status)
    VALUES ('hourly_aggregation_event', rows_count, 'SUCCESS');
END //

CREATE EVENT IF NOT EXISTS daily_aggregation_event
ON SCHEDULE EVERY 1 DAY
STARTS (CURRENT_DATE + INTERVAL 1 DAY)
DO
BEGIN
    DECLARE rows_count INT DEFAULT 0;

    INSERT INTO daily_avg (room_id, type, dt_day, avg_value)
    SELECT room_id, type, DATE(dt) AS dt_day, AVG(value) AS avg_value
    FROM rec_data
    WHERE dt >= CURRENT_DATE - INTERVAL 1 DAY AND dt < CURRENT_DATE
    GROUP BY room_id, type, dt_day
    ON DUPLICATE KEY UPDATE avg_value = VALUES(avg_value);

    SET rows_count = ROW_COUNT();
    INSERT INTO event_log (event_name, rows_affected, status)
    VALUES ('daily_aggregation_event', rows_count, 'SUCCESS');
END //

DELIMITER ;

CREATE EVENT IF NOT EXISTS cleanup_event_log
ON SCHEDULE EVERY 1 WEEK
DO DELETE FROM event_log WHERE execution_time < NOW() - INTERVAL 30 DAY;

-- Solar forecast accuracy, written hourly by scripts/solar/model.py (as www-data)
CREATE TABLE IF NOT EXISTS solar_forecast (
    target_date DATE NOT NULL COMMENT 'Cape Town day being forecast',
    issued_at DATETIME NOT NULL COMMENT 'UTC, when the forecast was made',
    days_ahead TINYINT NOT NULL COMMENT '0 = forecast for the same day, 1 = for the next day',
    expected_kwh FLOAT NOT NULL COMMENT 'after throttling once the battery is full',
    potential_kwh FLOAT NOT NULL COMMENT 'what the roof could make',
    battery_full_by TIME NULL COMMENT 'Cape Town time; NULL = not expected to fill',
    PRIMARY KEY (target_date, issued_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS solar_actual (
    day DATE NOT NULL PRIMARY KEY COMMENT 'Cape Town day',
    generated_kwh FLOAT NOT NULL,
    battery_full_at TIME NULL COMMENT 'Cape Town time battery first reached 97% in daylight; NULL = never',
    max_soc FLOAT NULL,
    updated_at DATETIME NOT NULL COMMENT 'UTC'
) ENGINE=InnoDB;

-- One row per day: actual against the forecast from the evening before (16:10 UTC = 18:10
-- Cape Town) and from that morning (04:10 UTC = 06:10 Cape Town). Use this for graphs.
CREATE OR REPLACE VIEW solar_accuracy AS
SELECT a.day,
       a.generated_kwh,
       e.expected_kwh AS evening_before_kwh,
       m.expected_kwh AS morning_kwh,
       a.battery_full_at,
       e.battery_full_by AS evening_before_full_by,
       m.battery_full_by AS morning_full_by
FROM solar_actual a
LEFT JOIN solar_forecast e ON e.target_date = a.day AND e.issued_at = (
    SELECT MIN(issued_at) FROM solar_forecast
    WHERE target_date = a.day AND days_ahead = 1 AND issued_at >= a.day - INTERVAL 1 DAY + INTERVAL 16 HOUR)
LEFT JOIN solar_forecast m ON m.target_date = a.day AND m.issued_at = (
    SELECT MIN(issued_at) FROM solar_forecast
    WHERE target_date = a.day AND days_ahead = 0 AND issued_at >= a.day + INTERVAL 4 HOUR);

-- Users
-- Admin user (Node-RED writes readings with it; also for remote admin tools)
CREATE USER IF NOT EXISTS 'grahamjonesgs'@'%' IDENTIFIED BY 'CHANGE_ME';
GRANT ALL PRIVILEGES ON *.* TO 'grahamjonesgs'@'%';

-- Read-only user for the PHP pages ($username/$password in vars.php)
CREATE USER IF NOT EXISTS 'reader'@'%' IDENTIFIED BY 'CHANGE_ME';
GRANT SELECT ON readings.* TO 'reader'@'%';

-- Passwordless (unix socket) user for the nightly backup, Setup/bin/backup_readings.sh
CREATE USER IF NOT EXISTS 'ubuntu'@'localhost' IDENTIFIED VIA unix_socket;
GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER ON readings.* TO 'ubuntu'@'localhost';

-- Passwordless (unix socket) user for the solar forecast cron jobs, limited to its two tables
CREATE USER IF NOT EXISTS 'www-data'@'localhost' IDENTIFIED VIA unix_socket;
GRANT SELECT, INSERT, UPDATE ON readings.solar_forecast TO 'www-data'@'localhost';
GRANT SELECT, INSERT, UPDATE ON readings.solar_actual TO 'www-data'@'localhost';
