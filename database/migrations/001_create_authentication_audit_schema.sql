-- ========================================================================
-- BEMS Phase 0: User Authentication & Audit System Database Schema
-- Best ERP Manufacturing System
-- 
-- This migration creates the foundational authentication and audit system
-- for the BEMS ERP system with proper UTF8MB4 support, indexing, and
-- foreign key constraints.
-- ========================================================================

-- Set connection charset and collation
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Drop existing tables if they exist (for clean reinstall)
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `user_sessions`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `roles`;

-- ========================================================================
-- ROLES TABLE
-- Defines the 11 user roles with descriptions and permission levels
-- ========================================================================

CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL COMMENT 'Role identifier (Admin, Manager, etc.)',
  `role_description` text NOT NULL COMMENT 'Detailed description of role responsibilities',
  `permission_level` int(3) NOT NULL DEFAULT 1 COMMENT 'Numeric permission level (1=lowest, 100=highest)',
  `can_manage_users` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Can create/edit user accounts',
  `can_change_passwords` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Can reset user passwords',
  `can_view_audit` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Can access audit logs',
  `can_manage_inventory` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Can modify inventory records',
  `can_manage_production` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Can manage production orders',
  `can_approve_quality` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Can approve/reject quality inspections',
  `can_setup_molds` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Can perform mold setup operations',
  `can_process_parts` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Can operate processing equipment',
  `can_handle_materials` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Can move and track materials',
  `can_ship_products` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Can manage shipping operations',
  `can_post_announcements` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Can post company-wide announcements',
  `active_status` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Role is active and can be assigned',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` varchar(20) DEFAULT NULL COMMENT 'Clock number of user who created this role',
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_roles_name` (`role_name`),
  KEY `idx_roles_permission_level` (`permission_level`),
  KEY `idx_roles_active` (`active_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='User roles with permission definitions for BEMS authentication system';

-- ========================================================================
-- USERS TABLE
-- Core user authentication with clock number based login system
-- ========================================================================

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `clock_number` varchar(20) NOT NULL COMMENT 'Unique username for login (employee clock number)',
  `real_name` varchar(100) NOT NULL COMMENT 'Full name for display in interfaces and reports',
  `initials` varchar(10) NOT NULL COMMENT 'User initials for quick identification in operations',
  `password_hash` varchar(255) NOT NULL COMMENT 'Argon2ID password hash',
  `role_id` int(11) NOT NULL COMMENT 'Foreign key to roles table',
  `active_status` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'User account is active',
  `last_login` timestamp NULL DEFAULT NULL COMMENT 'Last successful login timestamp',
  `login_attempts` int(3) NOT NULL DEFAULT 0 COMMENT 'Failed login attempt counter',
  `locked_until` timestamp NULL DEFAULT NULL COMMENT 'Account locked until this time',
  `password_changed_at` timestamp NULL DEFAULT NULL COMMENT 'Last password change timestamp',
  `force_password_change` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'User must change password on next login',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` varchar(20) DEFAULT NULL COMMENT 'Clock number of admin who created this account',
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_clock_number` (`clock_number`),
  UNIQUE KEY `uk_users_initials` (`initials`),
  KEY `idx_users_role` (`role_id`),
  KEY `idx_users_active` (`active_status`),
  KEY `idx_users_real_name` (`real_name`),
  KEY `idx_users_last_login` (`last_login`),
  
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_users_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`clock_number`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='User accounts with clock number authentication for BEMS system';

-- ========================================================================
-- USER_SESSIONS TABLE
-- Session management for authentication persistence
-- ========================================================================

CREATE TABLE `user_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(128) NOT NULL COMMENT 'PHP session identifier',
  `clock_number` varchar(20) NOT NULL COMMENT 'User clock number',
  `ip_address` varchar(45) NOT NULL COMMENT 'Client IP address (supports IPv6)',
  `user_agent` text DEFAULT NULL COMMENT 'Client browser/device information',
  `login_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Session start time',
  `last_activity` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last session activity',
  `expires_at` timestamp NOT NULL COMMENT 'Session expiration time',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Session is currently active',
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sessions_id` (`session_id`),
  KEY `idx_sessions_clock_number` (`clock_number`),
  KEY `idx_sessions_active` (`is_active`),
  KEY `idx_sessions_expires` (`expires_at`),
  KEY `idx_sessions_last_activity` (`last_activity`),
  
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`clock_number`) REFERENCES `users` (`clock_number`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='User session tracking for authentication persistence';

-- ========================================================================
-- AUDIT_LOGS TABLE
-- Comprehensive audit trail with 365-day retention policy
-- ========================================================================

CREATE TABLE `audit_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `clock_number` varchar(20) NOT NULL COMMENT 'User who performed the action',
  `real_name` varchar(100) NOT NULL COMMENT 'User real name for easy reading',
  `action_type` varchar(50) NOT NULL COMMENT 'Type of action performed (LOGIN, LOGOUT, CREATE, UPDATE, DELETE, etc.)',
  `action_description` text NOT NULL COMMENT 'Detailed description of the action performed',
  `table_affected` varchar(100) DEFAULT NULL COMMENT 'Database table that was affected',
  `record_id` varchar(50) DEFAULT NULL COMMENT 'ID of the specific record affected',
  `old_values` longtext DEFAULT NULL COMMENT 'JSON of previous values (for updates/deletes)',
  `new_values` longtext DEFAULT NULL COMMENT 'JSON of new values (for creates/updates)',
  `ip_address` varchar(45) NOT NULL COMMENT 'Client IP address where action originated',
  `session_id` varchar(128) DEFAULT NULL COMMENT 'Session ID for correlation with user sessions',
  `user_agent` text DEFAULT NULL COMMENT 'Client browser/device information',
  `additional_context` longtext DEFAULT NULL COMMENT 'Additional context data as JSON',
  `severity_level` enum('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'LOW' COMMENT 'Importance level of the action',
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the action occurred',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this log entry was created',
  
  PRIMARY KEY (`id`),
  KEY `idx_audit_clock_number` (`clock_number`),
  KEY `idx_audit_timestamp` (`timestamp`),
  KEY `idx_audit_action_type` (`action_type`),
  KEY `idx_audit_table_affected` (`table_affected`),
  KEY `idx_audit_severity` (`severity_level`),
  KEY `idx_audit_session` (`session_id`),
  KEY `idx_audit_created_at` (`created_at`),
  
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`clock_number`) REFERENCES `users` (`clock_number`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Comprehensive audit trail with 365-day retention for BEMS system';

-- ========================================================================
-- INSERT INITIAL ROLE DATA
-- Creates the 11 required roles with proper permissions
-- ========================================================================

INSERT INTO `roles` (`role_name`, `role_description`, `permission_level`, `can_manage_users`, `can_change_passwords`, `can_view_audit`, `can_manage_inventory`, `can_manage_production`, `can_approve_quality`, `can_setup_molds`, `can_process_parts`, `can_handle_materials`, `can_ship_products`, `can_post_announcements`, `created_by`) VALUES

-- Admin: Highest level access to all system functions
('Admin', 'System administrator with full access to all modules, user management, and system configuration. Can create users, reset passwords, view all audit logs, and manage all business operations.', 100, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, NULL),

-- Manager: High level operational management access
('Manager', 'Operational manager with access to most system functions including user password resets, audit logs, inventory management, production oversight, and quality approvals. Cannot create new users but can assist with password changes.', 90, 0, 1, 1, 1, 1, 1, 0, 0, 1, 1, 1, NULL),

-- HR Employee: Human resources with announcement capabilities
('HR Employee', 'Human resources personnel responsible for employee information management and company-wide communications. Can post holiday schedules, company announcements, and manage employee-related data.', 70, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, NULL),

-- Planner: Production planning and inventory management
('Planner', 'Production planner responsible for inventory management, production scheduling, BOM management, and MRP operations. Has read-only access to production data for planning purposes.', 80, 0, 0, 0, 1, 1, 0, 0, 0, 0, 0, 0, NULL),

-- Operator: Basic production operations
('Operator', 'Shop floor operator with minimal permissions for recording production data and palletizing operations. Can only access functions directly related to their production assignments.', 30, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, NULL),

-- Material Handler: Material movement and inventory updates
('Material Handler', 'Responsible for moving materials between locations, updating inventory positions, and maintaining accurate location tracking. Can scan location tags and manually update material positions.', 40, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, NULL),

-- Mold Setter: Mold setup and processing operations
('Mold Setter', 'Responsible for mold installation, removal, water line connections, and auxiliary equipment setup. Can also perform processing operations when needed. Dual role covering both setup and production functions.', 60, 0, 0, 0, 0, 0, 0, 1, 1, 0, 0, 0, NULL),

-- Processor: Processing operations and setup assistance
('Processor', 'Responsible for molding press startup and part processing operations. Can determine part quality for production decisions and assist with mold setup when needed. Dual role covering both production and setup assistance.', 50, 0, 0, 0, 0, 0, 0, 1, 1, 0, 0, 0, NULL),

-- Quality Manager: Quality oversight and approvals
('Quality Manager', 'Quality department manager responsible for part approval/rejection decisions, first article inspections, and quality system oversight. Can approve production go based on first article parts and post quality alerts.', 85, 0, 0, 1, 0, 0, 1, 0, 0, 0, 0, 1, NULL),

-- Quality Technician: Quality inspections and approvals
('Quality Technician', 'Quality department technician responsible for part inspections, first article evaluations, and quality approvals/rejections. Works under Quality Manager supervision for production go decisions.', 65, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, NULL),

-- Viewer: Read-only access for reporting and monitoring
('Viewer', 'Read-only access role for viewing reports, monitoring production status, and accessing system data without modification capabilities. Used for stakeholders who need visibility without operational permissions.', 20, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, NULL);

-- ========================================================================
-- CREATE DATABASE VIEWS FOR COMMON QUERIES
-- ========================================================================

-- User summary view with role information
CREATE OR REPLACE VIEW `v_users_summary` AS
SELECT 
    u.`id`,
    u.`clock_number`,
    u.`real_name`,
    u.`initials`,
    r.`role_name`,
    r.`role_description`,
    r.`permission_level`,
    u.`active_status`,
    u.`last_login`,
    u.`created_at`,
    CASE 
        WHEN u.`locked_until` > NOW() THEN 1 
        ELSE 0 
    END as `is_locked`,
    u.`login_attempts`
FROM `users` u
INNER JOIN `roles` r ON u.`role_id` = r.`id`
ORDER BY u.`real_name`;

-- Recent audit activity view (last 30 days)
CREATE OR REPLACE VIEW `v_recent_audit_activity` AS
SELECT 
    al.`id`,
    al.`clock_number`,
    al.`real_name`,
    al.`action_type`,
    al.`action_description`,
    al.`table_affected`,
    al.`severity_level`,
    al.`timestamp`,
    al.`ip_address`
FROM `audit_logs` al
WHERE al.`timestamp` >= DATE_SUB(NOW(), INTERVAL 30 DAY)
ORDER BY al.`timestamp` DESC;

-- Active sessions view
CREATE OR REPLACE VIEW `v_active_sessions` AS
SELECT 
    s.`session_id`,
    s.`clock_number`,
    u.`real_name`,
    r.`role_name`,
    s.`ip_address`,
    s.`login_time`,
    s.`last_activity`,
    s.`expires_at`,
    TIMESTAMPDIFF(MINUTE, s.`last_activity`, NOW()) as `minutes_inactive`
FROM `user_sessions` s
INNER JOIN `users` u ON s.`clock_number` = u.`clock_number`
INNER JOIN `roles` r ON u.`role_id` = r.`id`
WHERE s.`is_active` = 1 
AND s.`expires_at` > NOW()
ORDER BY s.`last_activity` DESC;

-- ========================================================================
-- CREATE STORED PROCEDURES FOR COMMON OPERATIONS
-- ========================================================================

DELIMITER $$

-- Procedure to create audit log entries
CREATE PROCEDURE `sp_create_audit_log`(
    IN p_clock_number VARCHAR(20),
    IN p_action_type VARCHAR(50),
    IN p_action_description TEXT,
    IN p_table_affected VARCHAR(100),
    IN p_record_id VARCHAR(50),
    IN p_old_values LONGTEXT,
    IN p_new_values LONGTEXT,
    IN p_ip_address VARCHAR(45),
    IN p_session_id VARCHAR(128),
    IN p_user_agent TEXT,
    IN p_additional_context LONGTEXT,
    IN p_severity_level ENUM('LOW','MEDIUM','HIGH','CRITICAL')
)
BEGIN
    DECLARE v_real_name VARCHAR(100);
    
    -- Get real name from users table
    SELECT `real_name` INTO v_real_name 
    FROM `users` 
    WHERE `clock_number` = p_clock_number;
    
    -- Insert audit log entry
    INSERT INTO `audit_logs` (
        `clock_number`,
        `real_name`,
        `action_type`,
        `action_description`,
        `table_affected`,
        `record_id`,
        `old_values`,
        `new_values`,
        `ip_address`,
        `session_id`,
        `user_agent`,
        `additional_context`,
        `severity_level`
    ) VALUES (
        p_clock_number,
        COALESCE(v_real_name, 'Unknown User'),
        p_action_type,
        p_action_description,
        p_table_affected,
        p_record_id,
        p_old_values,
        p_new_values,
        p_ip_address,
        p_session_id,
        p_user_agent,
        p_additional_context,
        COALESCE(p_severity_level, 'LOW')
    );
END$$

-- Procedure to clean up expired sessions
CREATE PROCEDURE `sp_cleanup_expired_sessions`()
BEGIN
    -- Mark expired sessions as inactive
    UPDATE `user_sessions` 
    SET `is_active` = 0 
    WHERE `expires_at` < NOW() 
    AND `is_active` = 1;
    
    -- Delete sessions older than 7 days
    DELETE FROM `user_sessions` 
    WHERE `expires_at` < DATE_SUB(NOW(), INTERVAL 7 DAY);
END$$

-- Procedure to clean up old audit logs (365-day retention)
CREATE PROCEDURE `sp_cleanup_old_audit_logs`()
BEGIN
    DELETE FROM `audit_logs` 
    WHERE `timestamp` < DATE_SUB(NOW(), INTERVAL 365 DAY);
END$$

-- Procedure to authenticate user
CREATE PROCEDURE `sp_authenticate_user`(
    IN p_clock_number VARCHAR(20),
    IN p_password VARCHAR(255),
    IN p_ip_address VARCHAR(45),
    IN p_user_agent TEXT,
    OUT p_result VARCHAR(50),
    OUT p_user_data JSON
)
BEGIN
    DECLARE v_user_id INT;
    DECLARE v_real_name VARCHAR(100);
    DECLARE v_password_hash VARCHAR(255);
    DECLARE v_active_status TINYINT;
    DECLARE v_login_attempts INT;
    DECLARE v_locked_until TIMESTAMP;
    DECLARE v_role_id INT;
    DECLARE v_role_name VARCHAR(50);
    DECLARE v_permission_level INT;
    DECLARE v_force_password_change TINYINT;
    
    -- Initialize result
    SET p_result = 'INVALID_CREDENTIALS';
    SET p_user_data = NULL;
    
    -- Get user data
    SELECT 
        u.`id`, u.`real_name`, u.`password_hash`, u.`active_status`, 
        u.`login_attempts`, u.`locked_until`, u.`role_id`, u.`force_password_change`,
        r.`role_name`, r.`permission_level`
    INTO 
        v_user_id, v_real_name, v_password_hash, v_active_status,
        v_login_attempts, v_locked_until, v_role_id, v_force_password_change,
        v_role_name, v_permission_level
    FROM `users` u
    INNER JOIN `roles` r ON u.`role_id` = r.`id`
    WHERE u.`clock_number` = p_clock_number;
    
    -- Check if user exists
    IF v_user_id IS NULL THEN
        SET p_result = 'USER_NOT_FOUND';
    
    -- Check if user is active
    ELSEIF v_active_status = 0 THEN
        SET p_result = 'ACCOUNT_DISABLED';
    
    -- Check if account is locked
    ELSEIF v_locked_until IS NOT NULL AND v_locked_until > NOW() THEN
        SET p_result = 'ACCOUNT_LOCKED';
    
    -- Verify password (Note: Actual password verification would be done in PHP)
    ELSEIF v_password_hash = p_password THEN
        -- Reset login attempts on successful login
        UPDATE `users` 
        SET `login_attempts` = 0, 
            `locked_until` = NULL,
            `last_login` = NOW()
        WHERE `clock_number` = p_clock_number;
        
        -- Check if password change is forced
        IF v_force_password_change = 1 THEN
            SET p_result = 'FORCE_PASSWORD_CHANGE';
        ELSE
            SET p_result = 'SUCCESS';
        END IF;
        
        -- Prepare user data JSON
        SET p_user_data = JSON_OBJECT(
            'user_id', v_user_id,
            'clock_number', p_clock_number,
            'real_name', v_real_name,
            'role_id', v_role_id,
            'role_name', v_role_name,
            'permission_level', v_permission_level,
            'force_password_change', v_force_password_change
        );
        
        -- Log successful login
        CALL sp_create_audit_log(
            p_clock_number,
            'LOGIN',
            CONCAT('User successfully logged in from IP: ', p_ip_address),
            'users',
            v_user_id,
            NULL,
            NULL,
            p_ip_address,
            NULL,
            p_user_agent,
            JSON_OBJECT('login_time', NOW()),
            'LOW'
        );
    
    ELSE
        -- Increment login attempts
        UPDATE `users` 
        SET `login_attempts` = `login_attempts` + 1,
            `locked_until` = CASE 
                WHEN `login_attempts` >= 4 THEN DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                ELSE NULL
            END
        WHERE `clock_number` = p_clock_number;
        
        -- Log failed login attempt
        CALL sp_create_audit_log(
            p_clock_number,
            'LOGIN_FAILED',
            CONCAT('Failed login attempt from IP: ', p_ip_address),
            'users',
            v_user_id,
            NULL,
            NULL,
            p_ip_address,
            NULL,
            p_user_agent,
            JSON_OBJECT('attempt_number', v_login_attempts + 1),
            'MEDIUM'
        );
        
        SET p_result = 'INVALID_CREDENTIALS';
    END IF;
    
END$$

DELIMITER ;

-- ========================================================================
-- CREATE EVENTS FOR AUTOMATED MAINTENANCE
-- ========================================================================

-- Enable event scheduler
SET GLOBAL event_scheduler = ON;

-- Event to clean up expired sessions (runs every hour)
CREATE EVENT IF NOT EXISTS `ev_cleanup_sessions`
ON SCHEDULE EVERY 1 HOUR
DO
  CALL sp_cleanup_expired_sessions();

-- Event to clean up old audit logs (runs daily at 2 AM)
CREATE EVENT IF NOT EXISTS `ev_cleanup_audit_logs`
ON SCHEDULE EVERY 1 DAY
STARTS TIMESTAMP(CURRENT_DATE) + INTERVAL 2 HOUR
DO
  CALL sp_cleanup_old_audit_logs();

-- ========================================================================
-- CREATE TRIGGERS FOR AUTOMATIC AUDIT LOGGING
-- ========================================================================

DELIMITER $$

-- Trigger for users table INSERT
CREATE TRIGGER `tr_users_insert_audit` 
AFTER INSERT ON `users`
FOR EACH ROW
BEGIN
    CALL sp_create_audit_log(
        COALESCE(NEW.created_by, 'SYSTEM'),
        'CREATE',
        CONCAT('Created new user account: ', NEW.clock_number, ' (', NEW.real_name, ')'),
        'users',
        NEW.id,
        NULL,
        JSON_OBJECT(
            'clock_number', NEW.clock_number,
            'real_name', NEW.real_name,
            'initials', NEW.initials,
            'role_id', NEW.role_id,
            'active_status', NEW.active_status
        ),
        '127.0.0.1',
        NULL,
        NULL,
        NULL,
        'MEDIUM'
    );
END$$

-- Trigger for users table UPDATE
CREATE TRIGGER `tr_users_update_audit` 
AFTER UPDATE ON `users`
FOR EACH ROW
BEGIN
    DECLARE v_action_desc TEXT;
    
    -- Determine what was updated
    SET v_action_desc = 'Updated user account: ';
    
    IF OLD.password_hash != NEW.password_hash THEN
        SET v_action_desc = CONCAT(v_action_desc, 'Password changed. ');
    END IF;
    
    IF OLD.role_id != NEW.role_id THEN
        SET v_action_desc = CONCAT(v_action_desc, 'Role changed. ');
    END IF;
    
    IF OLD.active_status != NEW.active_status THEN
        SET v_action_desc = CONCAT(v_action_desc, 'Status changed. ');
    END IF;
    
    CALL sp_create_audit_log(
        NEW.clock_number,
        'UPDATE',
        v_action_desc,
        'users',
        NEW.id,
        JSON_OBJECT(
            'clock_number', OLD.clock_number,
            'real_name', OLD.real_name,
            'initials', OLD.initials,
            'role_id', OLD.role_id,
            'active_status', OLD.active_status,
            'last_login', OLD.last_login
        ),
        JSON_OBJECT(
            'clock_number', NEW.clock_number,
            'real_name', NEW.real_name,
            'initials', NEW.initials,
            'role_id', NEW.role_id,
            'active_status', NEW.active_status,
            'last_login', NEW.last_login
        ),
        '127.0.0.1',
        NULL,
        NULL,
        NULL,
        'HIGH'
    );
END$$

DELIMITER ;

-- ========================================================================
-- INITIAL SYSTEM ADMIN ACCOUNT
-- Default admin account for system initialization
-- Password: AdminPassword123! (should be changed immediately after first login)
-- ========================================================================

-- Note: In production, the password hash would be generated using Argon2ID
-- This is a placeholder - actual implementation should hash the password properly
INSERT INTO `users` (
    `clock_number`,
    `real_name`,
    `initials`,
    `password_hash`,
    `role_id`,
    `active_status`,
    `force_password_change`,
    `created_by`
) VALUES (
    'ADMIN001',
    'System Administrator',
    'ADMIN',
    '$argon2id$v=19$m=65536,t=3,p=4$c2FsdHNhbHRzYWx0$hash_placeholder_change_immediately',
    (SELECT id FROM roles WHERE role_name = 'Admin'),
    1,
    1,
    NULL
);

-- ========================================================================
-- DATABASE PERFORMANCE OPTIMIZATIONS
-- ========================================================================

-- Analyze tables for query optimization
ANALYZE TABLE `roles`, `users`, `user_sessions`, `audit_logs`;

-- ========================================================================
-- SECURITY CONFIGURATIONS
-- ========================================================================

-- Create dedicated BEMS database user with limited privileges
-- (Run these commands as MySQL root user)

/*
-- Create BEMS application user
CREATE USER 'bems_app'@'localhost' IDENTIFIED BY 'GenerateSecurePasswordHere!';

-- Grant necessary privileges
GRANT SELECT, INSERT, UPDATE, DELETE ON BEMS.* TO 'bems_app'@'localhost';
GRANT EXECUTE ON BEMS.* TO 'bems_app'@'localhost';

-- Grant specific privileges for session management
GRANT CREATE TEMPORARY TABLES ON BEMS.* TO 'bems_app'@'localhost';

-- Flush privileges
FLUSH PRIVILEGES;
*/

-- ========================================================================
-- MIGRATION COMPLETION LOG
-- ========================================================================

-- Log the completion of this migration
INSERT INTO `audit_logs` (
    `clock_number`,
    `real_name`,
    `action_type`,
    `action_description`,
    `table_affected`,
    `severity_level`,
    `ip_address`,
    `additional_context`
) VALUES (
    'SYSTEM',
    'Database Migration System',
    'MIGRATION',
    'Phase 0 Authentication & Audit System database schema migration completed successfully',
    'ALL',
    'HIGH',
    '127.0.0.1',
    JSON_OBJECT(
        'migration_file', '001_create_authentication_audit_schema.sql',
        'phase', 'Phase 0',
        'tables_created', JSON_ARRAY('roles', 'users', 'user_sessions', 'audit_logs'),
        'views_created', JSON_ARRAY('v_users_summary', 'v_recent_audit_activity', 'v_active_sessions'),
        'procedures_created', JSON_ARRAY('sp_create_audit_log', 'sp_cleanup_expired_sessions', 'sp_cleanup_old_audit_logs', 'sp_authenticate_user'),
        'events_created', JSON_ARRAY('ev_cleanup_sessions', 'ev_cleanup_audit_logs'),
        'triggers_created', JSON_ARRAY('tr_users_insert_audit', 'tr_users_update_audit')
    )
);

-- ========================================================================
-- END OF MIGRATION
-- Phase 0: User Authentication & Audit System Complete
-- ========================================================================

-- Display completion message
SELECT 'BEMS Phase 0 Authentication & Audit System migration completed successfully!' as 'Migration Status',
       'Tables: roles, users, user_sessions, audit_logs' as 'Tables Created',
       '11 roles inserted with proper permissions' as 'Initial Data',
       'Admin account created: ADMIN001 (change password immediately!)' as 'Default Account',
       '365-day audit retention with automated cleanup' as 'Audit Policy',
       'UTF8MB4 charset with proper indexing and constraints' as 'Database Features';