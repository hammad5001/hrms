-- =====================================================
-- DATABASE: balitech_attendance
-- SHIFT TIMING: 7:00 PM to 5:00 AM (Night Shift)
-- =====================================================

-- Create database
CREATE DATABASE IF NOT EXISTS balitech_attendance;
USE balitech_attendance;

-- 1. EMPLOYEES TABLE (sync with ZKTeco)
CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(20) UNIQUE NOT NULL,
    zkteco_id VARCHAR(20) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    department VARCHAR(50) DEFAULT 'General',
    designation VARCHAR(50) DEFAULT 'Employee',
    shift_start TIME DEFAULT '19:00:00', -- 7:00 PM
    shift_end TIME DEFAULT '05:00:00',   -- 5:00 AM
    grace_minutes INT DEFAULT 15,
    weekly_off VARCHAR(20) DEFAULT 'Sunday',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_zkteco (zkteco_id),
    INDEX idx_employee_code (employee_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. RAW ATTENDANCE LOGS (from ZKTeco machine)
CREATE TABLE IF NOT EXISTS attendance_raw (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    zkteco_id VARCHAR(20) NOT NULL,
    punch_time DATETIME NOT NULL,
    machine_ip VARCHAR(15) DEFAULT '103.189.232.7',
    sync_status ENUM('pending','processed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (zkteco_id) REFERENCES employees(zkteco_id) ON DELETE CASCADE,
    INDEX idx_zkteco_time (zkteco_id, punch_time),
    INDEX idx_date (DATE(punch_time))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. DAILY ATTENDANCE SUMMARY (calculated daily)
CREATE TABLE IF NOT EXISTS attendance_daily (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    attendance_date DATE NOT NULL,  -- This is the date when shift started
    first_in DATETIME,
    last_out DATETIME,
    total_seconds INT DEFAULT 0,
    late_seconds INT DEFAULT 0,
    overtime_seconds INT DEFAULT 0,
    working_hours DECIMAL(5,2) DEFAULT 0,
    status ENUM('present','absent','late','half-day','holiday') DEFAULT 'absent',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE KEY unique_attendance (employee_id, attendance_date),
    INDEX idx_date (attendance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. HOLIDAYS TABLE
CREATE TABLE IF NOT EXISTS holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE UNIQUE NOT NULL,
    holiday_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- INSERT SAMPLE DATA
-- =====================================================

-- Insert holidays for 2026
INSERT INTO holidays (holiday_date, holiday_name) VALUES
('2026-03-23', 'Pakistan Day'),
('2026-05-01', 'Labour Day'),
('2026-08-14', 'Independence Day'),
('2026-12-25', 'Quaid-e-Azam Day');

-- =====================================================
-- STORED PROCEDURE: Calculate daily attendance
-- =====================================================

DELIMITER $$

CREATE PROCEDURE calculate_daily_attendance(IN target_date DATE)
BEGIN
    -- Delete existing records for this date
    DELETE FROM attendance_daily WHERE attendance_date = target_date;
    
    -- Insert calculated attendance
    INSERT INTO attendance_daily (
        employee_id,
        attendance_date,
        first_in,
        last_out,
        total_seconds,
        late_seconds,
        overtime_seconds,
        working_hours,
        status
    )
    SELECT 
        e.id,
        target_date,
        MIN(a.punch_time) as first_in,
        MAX(a.punch_time) as last_out,
        TIMESTAMPDIFF(SECOND, MIN(a.punch_time), MAX(a.punch_time)) as total_seconds,
        -- Late calculation (if first punch after 7:15 PM)
        GREATEST(0, TIMESTAMPDIFF(SECOND, 
            CONCAT(target_date, ' 19:15:00'),
            MIN(a.punch_time)
        )) as late_seconds,
        -- Overtime calculation (if last punch after 5:00 AM next day)
        GREATEST(0, TIMESTAMPDIFF(SECOND,
            CONCAT(DATE_ADD(target_date, INTERVAL 1 DAY), ' 05:00:00'),
            MAX(a.punch_time)
        )) as overtime_seconds,
        -- Working hours
        ROUND(TIMESTAMPDIFF(SECOND, MIN(a.punch_time), MAX(a.punch_time)) / 3600, 2) as working_hours,
        -- Status
        CASE 
            WHEN MIN(a.punch_time) IS NULL THEN 'absent'
            WHEN TIMESTAMPDIFF(SECOND, CONCAT(target_date, ' 19:15:00'), MIN(a.punch_time)) > 0 THEN 'late'
            ELSE 'present'
        END as status
    FROM employees e
    LEFT JOIN attendance_raw a ON e.zkteco_id = a.zkteco_id 
        AND DATE(a.punch_time) = target_date
    WHERE e.is_active = 1
    GROUP BY e.id;
    
    -- Mark raw records as processed
    UPDATE attendance_raw 
    SET sync_status = 'processed' 
    WHERE DATE(punch_time) = target_date;
END$$

DELIMITER ;