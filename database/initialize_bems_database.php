<?php
/**
 * BEMS Database Initialization Script
 * Best ERP Manufacturing System
 * 
 * This script initializes the BEMS database with Phase 0 authentication
 * and audit system schema. It should be run once during system setup.
 */

require_once dirname(__DIR__) . '/config/database.php';

class BemsDbInitializer {
    
    private $pdo;
    private $migrationPath;
    
    public function __construct() {
        $this->migrationPath = __DIR__ . '/migrations/';
        echo "BEMS Database Initialization Starting...\n\n";
    }
    
    /**
     * Initialize database connection
     */
    private function initializeConnection() {
        try {
            $this->pdo = DatabaseConfig::getConnection();
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            echo "✅ Database connection established successfully\n";
            return true;
        } catch (Exception $e) {
            echo "❌ Database connection failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Check if database exists and create if needed
     */
    private function ensureDatabaseExists() {
        try {
            // Connect without specifying database
            $dsn = sprintf(
                'mysql:host=%s;charset=%s',
                DatabaseConfig::DB_HOST,
                DatabaseConfig::DB_CHARSET
            );
            
            $pdo = new PDO($dsn, DatabaseConfig::DB_USER, DatabaseConfig::DB_PASS, DatabaseConfig::DB_OPTIONS);
            
            // Create database if it doesn't exist
            $sql = "CREATE DATABASE IF NOT EXISTS `" . DatabaseConfig::DB_NAME . "` 
                    DEFAULT CHARACTER SET " . DatabaseConfig::DB_CHARSET . " 
                    COLLATE " . DatabaseConfig::DB_COLLATION;
            
            $pdo->exec($sql);
            echo "✅ Database '" . DatabaseConfig::DB_NAME . "' ready\n";
            return true;
        } catch (Exception $e) {
            echo "❌ Database creation failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Check if migration has already been applied
     */
    private function isMigrationApplied($migrationFile) {
        try {
            // Check if audit_logs table exists (indicator of Phase 0 completion)
            $sql = "SHOW TABLES LIKE 'audit_logs'";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Execute SQL migration file
     */
    private function executeMigration($migrationFile) {
        $filePath = $this->migrationPath . $migrationFile;
        
        if (!file_exists($filePath)) {
            echo "❌ Migration file not found: $filePath\n";
            return false;
        }
        
        try {
            // Read the migration file
            $sql = file_get_contents($filePath);
            
            if (empty($sql)) {
                echo "❌ Migration file is empty: $filePath\n";
                return false;
            }
            
            echo "📄 Executing migration: $migrationFile\n";
            
            // Split SQL statements and execute them
            $statements = $this->splitSqlStatements($sql);
            
            foreach ($statements as $index => $statement) {
                $statement = trim($statement);
                if (empty($statement) || $this->isComment($statement)) {
                    continue;
                }
                
                try {
                    $this->pdo->exec($statement);
                } catch (Exception $e) {
                    // Some statements might fail on re-run (like CREATE PROCEDURE IF NOT EXISTS)
                    // Log but don't fail the entire migration
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        echo "⚠️  Warning in statement " . ($index + 1) . ": " . $e->getMessage() . "\n";
                    }
                }
            }
            
            echo "✅ Migration completed successfully: $migrationFile\n";
            return true;
            
        } catch (Exception $e) {
            echo "❌ Migration failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Split SQL file into individual statements
     */
    private function splitSqlStatements($sql) {
        // Remove comments and normalize
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        
        // Split by semicolon, but respect DELIMITER changes
        $statements = [];
        $delimiter = ';';
        $temp = '';
        
        $lines = explode("\n", $sql);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Check for delimiter changes
            if (preg_match('/^DELIMITER\s+(.+)$/i', $line, $matches)) {
                $delimiter = $matches[1];
                continue;
            }
            
            $temp .= $line . "\n";
            
            // Check if line ends with current delimiter
            if (substr($line, -strlen($delimiter)) === $delimiter) {
                $statements[] = substr($temp, 0, -strlen($delimiter) - 1);
                $temp = '';
            }
        }
        
        // Add any remaining statement
        if (!empty(trim($temp))) {
            $statements[] = trim($temp);
        }
        
        return array_filter($statements, function($stmt) {
            return !empty(trim($stmt));
        });
    }
    
    /**
     * Check if statement is a comment
     */
    private function isComment($statement) {
        $statement = trim($statement);
        return empty($statement) || 
               substr($statement, 0, 2) === '--' || 
               substr($statement, 0, 2) === '/*' ||
               substr($statement, 0, 1) === '#';
    }
    
    /**
     * Create default admin password hash using Argon2ID
     */
    private function createAdminPasswordHash($password = 'AdminPassword123!') {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID);
        } else {
            // Fallback to Argon2I if Argon2ID not available
            return password_hash($password, PASSWORD_ARGON2I);
        }
    }
    
    /**
     * Update admin account with proper password hash
     */
    private function updateAdminAccount() {
        try {
            $passwordHash = $this->createAdminPasswordHash();
            
            $sql = "UPDATE users SET password_hash = :password_hash WHERE clock_number = 'ADMIN001'";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':password_hash', $passwordHash);
            $stmt->execute();
            
            echo "✅ Admin account password hash updated with Argon2ID\n";
            echo "📝 Default Admin Credentials:\n";
            echo "   Username: ADMIN001\n";
            echo "   Password: AdminPassword123!\n";
            echo "   ⚠️  CHANGE PASSWORD IMMEDIATELY AFTER FIRST LOGIN!\n\n";
            
            return true;
        } catch (Exception $e) {
            echo "❌ Failed to update admin password: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Verify database schema
     */
    private function verifySchema() {
        try {
            $requiredTables = ['roles', 'users', 'user_sessions', 'audit_logs'];
            $existingTables = [];
            
            foreach ($requiredTables as $table) {
                $sql = "SHOW TABLES LIKE '$table'";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    $existingTables[] = $table;
                }
            }
            
            if (count($existingTables) === count($requiredTables)) {
                echo "✅ All required tables created successfully\n";
                
                // Check roles count
                $sql = "SELECT COUNT(*) FROM roles";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
                $roleCount = $stmt->fetchColumn();
                
                echo "✅ $roleCount roles created\n";
                
                // Check admin user
                $sql = "SELECT COUNT(*) FROM users WHERE clock_number = 'ADMIN001'";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
                $adminExists = $stmt->fetchColumn() > 0;
                
                if ($adminExists) {
                    echo "✅ Admin account created\n";
                } else {
                    echo "⚠️  Admin account not found\n";
                }
                
                return true;
            } else {
                echo "❌ Missing tables: " . implode(', ', array_diff($requiredTables, $existingTables)) . "\n";
                return false;
            }
            
        } catch (Exception $e) {
            echo "❌ Schema verification failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Display system information
     */
    private function displaySystemInfo() {
        try {
            echo "\n" . str_repeat("=", 60) . "\n";
            echo "BEMS PHASE 0 INITIALIZATION COMPLETE\n";
            echo str_repeat("=", 60) . "\n\n";
            
            echo "Database Configuration:\n";
            echo "  Host: " . DatabaseConfig::DB_HOST . "\n";
            echo "  Database: " . DatabaseConfig::DB_NAME . "\n";
            echo "  Charset: " . DatabaseConfig::DB_CHARSET . "\n";
            echo "  Collation: " . DatabaseConfig::DB_COLLATION . "\n\n";
            
            // Display role summary
            $sql = "SELECT role_name, permission_level, 
                           CASE WHEN can_manage_users = 1 THEN 'Yes' ELSE 'No' END as can_manage_users,
                           CASE WHEN can_change_passwords = 1 THEN 'Yes' ELSE 'No' END as can_change_passwords
                    FROM roles ORDER BY permission_level DESC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "Roles Created (Permission Level):\n";
            foreach ($roles as $role) {
                echo sprintf("  %-20s (Level %3d) - Manage Users: %-3s - Change Passwords: %s\n",
                    $role['role_name'], 
                    $role['permission_level'],
                    $role['can_manage_users'],
                    $role['can_change_passwords']
                );
            }
            
            echo "\nSecurity Features:\n";
            echo "  ✅ Argon2ID password hashing\n";
            echo "  ✅ Account lockout after 5 failed attempts\n";
            echo "  ✅ Session management with expiration\n";
            echo "  ✅ Comprehensive audit logging\n";
            echo "  ✅ Role-based access control\n";
            echo "  ✅ Automated data retention (365 days)\n\n";
            
            echo "Automated Maintenance:\n";
            echo "  ✅ Session cleanup (hourly)\n";
            echo "  ✅ Audit log cleanup (daily at 2 AM)\n";
            echo "  ✅ Automatic audit triggers\n\n";
            
            echo "Next Steps:\n";
            echo "  1. Login as ADMIN001 with password: AdminPassword123!\n";
            echo "  2. Change the admin password immediately\n";
            echo "  3. Create additional user accounts\n";
            echo "  4. Proceed with Phase 1 (Inventory Module) development\n\n";
            
            echo "BEMS Database is ready for Phase 1 development!\n";
            echo str_repeat("=", 60) . "\n";
            
        } catch (Exception $e) {
            echo "Error displaying system info: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Main initialization method
     */
    public function initialize() {
        echo "Starting BEMS Database Initialization...\n";
        echo "Phase 0: User Authentication & Audit System\n";
        echo str_repeat("-", 50) . "\n\n";
        
        // Step 1: Ensure database exists
        if (!$this->ensureDatabaseExists()) {
            echo "❌ Database initialization failed - cannot create database\n";
            return false;
        }
        
        // Step 2: Initialize connection
        if (!$this->initializeConnection()) {
            echo "❌ Database initialization failed - connection error\n";
            return false;
        }
        
        // Step 3: Check if already initialized
        if ($this->isMigrationApplied('001_create_authentication_audit_schema.sql')) {
            echo "⚠️  Phase 0 migration already applied\n";
            echo "   Database appears to be already initialized.\n";
            echo "   To re-initialize, manually drop tables first.\n\n";
            $this->displaySystemInfo();
            return true;
        }
        
        // Step 4: Execute migration
        if (!$this->executeMigration('001_create_authentication_audit_schema.sql')) {
            echo "❌ Database initialization failed - migration error\n";
            return false;
        }
        
        // Step 5: Update admin account with proper password hash
        if (!$this->updateAdminAccount()) {
            echo "❌ Database initialization failed - admin account setup error\n";
            return false;
        }
        
        // Step 6: Verify schema
        if (!$this->verifySchema()) {
            echo "❌ Database initialization failed - schema verification error\n";
            return false;
        }
        
        // Step 7: Display completion information
        $this->displaySystemInfo();
        
        return true;
    }
}

// Execute initialization if run directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $initializer = new BemsDbInitializer();
    $success = $initializer->initialize();
    exit($success ? 0 : 1);
}
?>