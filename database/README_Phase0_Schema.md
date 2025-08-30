# BEMS Phase 0: User Authentication & Audit System Database Schema

## Overview

This document describes the Phase 0 database schema for the BEMS (Best ERP Manufacturing System) User Authentication and Audit system. The schema implements a comprehensive authentication system with role-based access control and full audit trail capabilities.

## Database Tables

### 1. `roles` - User Role Definitions

Defines the 11 user roles with their permissions and access levels.

**Key Features:**
- Permission levels from 1 (lowest) to 100 (highest)
- Granular permission flags for specific system functions
- Support for role descriptions and capabilities

**Role Hierarchy:**
1. **Admin** (Level 100) - Full system access
2. **Manager** (Level 90) - Operational management
3. **Planner** (Level 80) - Production planning
4. **Quality Manager** (Level 85) - Quality oversight
5. **HR Employee** (Level 70) - Human resources
6. **Quality Technician** (Level 65) - Quality inspections
7. **Mold Setter** (Level 60) - Mold setup & processing
8. **Processor** (Level 50) - Processing & setup assistance
9. **Material Handler** (Level 40) - Material movements
10. **Operator** (Level 30) - Basic production operations
11. **Viewer** (Level 20) - Read-only access

### 2. `users` - User Accounts

Core user authentication table with clock number-based login system.

**Key Features:**
- Clock number as unique username (no email required)
- Real name for display in interfaces and reports
- Initials for quick identification in operations
- Argon2ID password hashing
- Account lockout after 5 failed login attempts (30-minute lock)
- Force password change capability
- Admin-controlled password management

**Security Features:**
- Password hashes using Argon2ID algorithm
- Login attempt tracking and account lockout
- Last login tracking
- Created by tracking for audit purposes

### 3. `user_sessions` - Session Management

Manages user authentication sessions with proper expiration and security.

**Key Features:**
- 8-hour session timeout (configurable)
- IP address tracking
- User agent logging
- Automatic session cleanup
- Session activity tracking

### 4. `audit_logs` - Comprehensive Audit Trail

Complete audit logging system with 365-day retention policy.

**Key Features:**
- Tracks all user actions with full context
- JSON storage for old/new values in updates
- Severity levels (LOW, MEDIUM, HIGH, CRITICAL)
- IP address and session correlation
- Automated cleanup after 365 days
- Real names stored for easy reading

## Database Views

### 1. `v_users_summary`
Provides user information with role details and account status.

### 2. `v_recent_audit_activity`
Shows audit activity from the last 30 days.

### 3. `v_active_sessions`
Lists currently active user sessions with inactivity indicators.

## Stored Procedures

### 1. `sp_create_audit_log`
Creates standardized audit log entries with proper user name resolution.

### 2. `sp_cleanup_expired_sessions`
Removes expired and old session records (runs hourly).

### 3. `sp_cleanup_old_audit_logs`
Enforces 365-day audit retention policy (runs daily at 2 AM).

### 4. `sp_authenticate_user`
Comprehensive user authentication with all security checks.

## Database Events

### 1. `ev_cleanup_sessions`
Automated session cleanup every hour.

### 2. `ev_cleanup_audit_logs`
Automated audit log cleanup daily at 2 AM.

## Triggers

### 1. `tr_users_insert_audit`
Automatically logs user account creation.

### 2. `tr_users_update_audit`
Automatically logs user account modifications.

## Key Security Features

### Password Security
- **Argon2ID Hashing**: Industry-standard password hashing
- **Admin-Controlled**: Users cannot change their own passwords
- **Force Change**: Capability to require password changes
- **Account Lockout**: 5 failed attempts = 30-minute lock

### Session Security
- **8-Hour Timeout**: Configurable session expiration
- **IP Tracking**: Session tied to originating IP address
- **Automatic Cleanup**: Expired sessions automatically removed
- **Activity Tracking**: Last activity timestamp maintained

### Audit Security
- **Complete Trail**: All actions logged with full context
- **Immutable Records**: Audit logs cannot be modified
- **365-Day Retention**: Automated cleanup after one year
- **Severity Levels**: Critical actions flagged appropriately

### Access Control
- **Role-Based**: 11 distinct roles with specific permissions
- **Permission Flags**: Granular control over system functions
- **Active Status**: Accounts can be disabled without deletion
- **Initials System**: Quick user identification for operations

## Database Configuration

### Character Set and Collation
- **Charset**: UTF8MB4 for full Unicode support
- **Collation**: utf8mb4_unicode_ci for international characters

### Performance Optimization
- **Indexes**: Strategic indexing on frequently queried columns
- **Foreign Keys**: Proper referential integrity constraints
- **Engine**: InnoDB for transaction support and row-level locking

## Installation and Setup

### Prerequisites
- MySQL/MariaDB 5.7 or higher
- PHP 7.4 or higher with PDO MySQL extension
- Argon2 password hashing support

### Installation Steps

1. **Run Database Migration**
   ```bash
   mysql -u root -p BEMS < /var/www/html/bems/database/migrations/001_create_authentication_audit_schema.sql
   ```

2. **Initialize Database**
   ```bash
   php /var/www/html/bems/database/initialize_bems_database.php
   ```

3. **Test System**
   ```bash
   php /var/www/html/bems/database/test_authentication_system.php
   ```

### Default Admin Account
- **Username**: ADMIN001
- **Password**: AdminPassword123!
- **⚠️ Change immediately after first login!**

## Usage Examples

### Authentication Service

```php
$auth = new AuthenticationService();

// Authenticate user
$result = $auth->authenticateUser('USER001', 'password123', $ipAddress, $userAgent);

if ($result['success']) {
    // Login successful
    $userData = $result['user'];
    $sessionId = $userData['session_id'];
} else {
    // Handle authentication failure
    $error = $result['message'];
}

// Validate session
$sessionResult = $auth->validateSession($sessionId);

if ($sessionResult['valid']) {
    $currentUser = $sessionResult['user'];
}

// Change password (Admin/Manager only)
$passwordResult = $auth->changeUserPassword(
    'ADMIN001',      // Admin clock number
    'USER001',       // Target user
    'newPassword123', // New password
    true             // Force change on next login
);
```

### Permission Checking

```php
$permissions = $auth->getUserPermissions('USER001');

if ($permissions['can_manage_inventory']) {
    // User can manage inventory
}

if ($permissions['can_approve_quality']) {
    // User can approve quality inspections
}
```

## Maintenance

### Automated Maintenance
- **Session Cleanup**: Runs every hour automatically
- **Audit Cleanup**: Runs daily at 2 AM automatically
- **Database Events**: Enabled automatically during installation

### Manual Maintenance

```sql
-- Clean expired sessions manually
CALL sp_cleanup_expired_sessions();

-- Clean old audit logs manually
CALL sp_cleanup_old_audit_logs();

-- Check database health
SELECT 
    table_name,
    table_rows,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Size (MB)'
FROM information_schema.TABLES 
WHERE table_schema = 'BEMS'
ORDER BY (data_length + index_length) DESC;
```

## Monitoring and Reporting

### User Activity Monitoring

```sql
-- Recent login activity
SELECT * FROM v_recent_audit_activity 
WHERE action_type IN ('LOGIN', 'LOGIN_FAILED') 
ORDER BY timestamp DESC LIMIT 10;

-- Active sessions
SELECT * FROM v_active_sessions;

-- Failed login attempts
SELECT 
    clock_number,
    real_name,
    COUNT(*) as failed_attempts,
    MAX(timestamp) as last_attempt
FROM audit_logs 
WHERE action_type = 'LOGIN_FAILED'
AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY clock_number, real_name
ORDER BY failed_attempts DESC;
```

### System Health Checks

```sql
-- Users with expired passwords (if implemented)
SELECT clock_number, real_name, password_changed_at
FROM users 
WHERE password_changed_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
AND active_status = 1;

-- Locked accounts
SELECT clock_number, real_name, locked_until, login_attempts
FROM users 
WHERE locked_until > NOW();

-- Audit log volume by day
SELECT 
    DATE(timestamp) as log_date,
    COUNT(*) as log_entries,
    COUNT(DISTINCT clock_number) as unique_users
FROM audit_logs 
WHERE timestamp > DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(timestamp)
ORDER BY log_date DESC;
```

## Security Recommendations

1. **Regular Password Changes**: Implement policy for regular admin password changes
2. **Session Monitoring**: Monitor active sessions for suspicious activity
3. **Audit Review**: Regularly review audit logs for security incidents
4. **Account Cleanup**: Disable unused accounts promptly
5. **Database Backups**: Regular backups of authentication data
6. **Network Security**: Implement proper network-level security controls
7. **SSL/TLS**: Use encrypted connections for all authentication traffic

## Troubleshooting

### Common Issues

1. **Connection Errors**: Check database configuration in `/config/database.php`
2. **Permission Errors**: Verify database user has proper privileges
3. **Session Issues**: Check session table cleanup and expiration settings
4. **Audit Log Growth**: Monitor audit log size and cleanup frequency
5. **Password Hashing**: Verify Argon2 support in PHP installation

### Debug Queries

```sql
-- Check database configuration
SELECT @@character_set_database, @@collation_database;

-- Verify event scheduler
SELECT @@event_scheduler;

-- Check triggers
SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE 
FROM INFORMATION_SCHEMA.TRIGGERS 
WHERE TRIGGER_SCHEMA = 'BEMS';

-- Verify stored procedures
SELECT ROUTINE_NAME, ROUTINE_TYPE 
FROM INFORMATION_SCHEMA.ROUTINES 
WHERE ROUTINE_SCHEMA = 'BEMS';
```

## Next Phase Integration

This Phase 0 authentication system is designed to integrate seamlessly with future BEMS phases:

- **Phase 1**: Inventory module will use user initials for material movement tracking
- **Phase 2**: BOM management will leverage role-based permissions
- **Phase 3**: MRP planning will use audit trails for change tracking
- **Phase 4**: Shipping will integrate with user authentication for document creation
- **Phase 5**: MES extensions will use detailed audit logging for production tracking

The comprehensive audit system ensures full traceability across all future modules while maintaining security and compliance requirements.