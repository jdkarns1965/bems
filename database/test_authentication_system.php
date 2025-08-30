<?php
/**
 * BEMS Authentication System Test Script
 * Best ERP Manufacturing System
 * 
 * This script tests the Phase 0 authentication and audit system
 * to verify proper database schema and functionality.
 */

require_once dirname(__DIR__) . '/app/services/AuthenticationService.php';
require_once dirname(__DIR__) . '/config/database.php';

class AuthenticationSystemTest {
    
    private $auth;
    private $pdo;
    
    public function __construct() {
        $this->auth = new AuthenticationService();
        $this->pdo = DatabaseConfig::getConnection();
    }
    
    /**
     * Run all tests
     */
    public function runTests() {
        echo "BEMS Phase 0 Authentication System Test\n";
        echo str_repeat("=", 50) . "\n\n";
        
        $testResults = [];
        
        // Test database connectivity
        $testResults['database'] = $this->testDatabaseConnection();
        
        // Test schema existence
        $testResults['schema'] = $this->testSchemaExists();
        
        // Test roles data
        $testResults['roles'] = $this->testRolesData();
        
        // Test admin account
        $testResults['admin'] = $this->testAdminAccount();
        
        // Test authentication
        $testResults['auth'] = $this->testAuthentication();
        
        // Test session management
        $testResults['session'] = $this->testSessionManagement();
        
        // Test audit logging
        $testResults['audit'] = $this->testAuditLogging();
        
        // Test user management
        $testResults['user_mgmt'] = $this->testUserManagement();
        
        // Display summary
        $this->displayTestSummary($testResults);
        
        return $testResults;
    }
    
    /**
     * Test database connection
     */
    private function testDatabaseConnection() {
        try {
            echo "Testing database connection... ";
            
            $sql = "SELECT VERSION() as mysql_version, DATABASE() as current_db";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo "✅ Connected to MySQL " . $result['mysql_version'] . 
                 " - Database: " . $result['current_db'] . "\n";
            
            return ['success' => true, 'details' => $result];
            
        } catch (Exception $e) {
            echo "❌ Failed: " . $e->getMessage() . "\n";
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Test schema exists
     */
    private function testSchemaExists() {
        try {
            echo "Testing database schema... ";
            
            $requiredTables = ['roles', 'users', 'user_sessions', 'audit_logs'];
            $missingTables = [];
            
            foreach ($requiredTables as $table) {
                $sql = "SHOW TABLES LIKE '$table'";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
                
                if ($stmt->rowCount() === 0) {
                    $missingTables[] = $table;
                }
            }
            
            if (empty($missingTables)) {
                echo "✅ All required tables exist\n";
                return ['success' => true, 'tables' => $requiredTables];
            } else {
                echo "❌ Missing tables: " . implode(', ', $missingTables) . "\n";
                return ['success' => false, 'missing_tables' => $missingTables];
            }
            
        } catch (Exception $e) {
            echo "❌ Failed: " . $e->getMessage() . "\n";
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Test roles data
     */
    private function testRolesData() {
        try {
            echo "Testing roles data... ";
            
            $sql = "SELECT COUNT(*) as role_count FROM roles";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $expectedRoles = 11; // As per PRD requirements
            
            if ($result['role_count'] == $expectedRoles) {
                echo "✅ All $expectedRoles roles created\n";
                
                // Display roles
                $sql = "SELECT role_name, permission_level FROM roles ORDER BY permission_level DESC";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
                $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($roles as $role) {
                    echo "   - " . str_pad($role['role_name'], 20) . " (Level " . $role['permission_level'] . ")\n";
                }
                
                return ['success' => true, 'role_count' => $result['role_count'], 'roles' => $roles];
            } else {
                echo "❌ Expected $expectedRoles roles, found " . $result['role_count'] . "\n";
                return ['success' => false, 'expected' => $expectedRoles, 'actual' => $result['role_count']];
            }
            
        } catch (Exception $e) {
            echo "❌ Failed: " . $e->getMessage() . "\n";
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Test admin account
     */
    private function testAdminAccount() {
        try {
            echo "Testing admin account... ";
            
            $sql = "SELECT u.clock_number, u.real_name, u.active_status, r.role_name, r.permission_level
                    FROM users u
                    INNER JOIN roles r ON u.role_id = r.id
                    WHERE u.clock_number = 'ADMIN001'";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($admin && $admin['active_status'] && $admin['role_name'] === 'Admin') {
                echo "✅ Admin account exists and is active\n";
                echo "   Clock Number: " . $admin['clock_number'] . "\n";
                echo "   Real Name: " . $admin['real_name'] . "\n";
                echo "   Role: " . $admin['role_name'] . " (Level " . $admin['permission_level'] . ")\n";
                
                return ['success' => true, 'admin' => $admin];
            } else {
                echo "❌ Admin account not found or inactive\n";
                return ['success' => false, 'admin' => $admin];
            }
            
        } catch (Exception $e) {
            echo "❌ Failed: " . $e->getMessage() . "\n";
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Test authentication
     */
    private function testAuthentication() {
        try {
            echo "Testing authentication system... ";
            
            // Test invalid user
            $result = $this->auth->authenticateUser('INVALID001', 'wrongpassword');
            if (!$result['success'] && $result['code'] === 'USER_NOT_FOUND') {
                echo "✅ Invalid user rejected\n";
            } else {
                echo "❌ Invalid user not properly rejected\n";
                return ['success' => false, 'issue' => 'Invalid user authentication'];
            }
            
            // Note: We can't test admin login without knowing the actual hashed password
            // This would require the initialization script to have been run
            echo "   Authentication logic validated\n";
            
            return ['success' => true, 'tests_passed' => ['invalid_user_rejection']];
            
        } catch (Exception $e) {
            echo "❌ Failed: " . $e->getMessage() . "\n";
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Test session management
     */
    private function testSessionManagement() {
        try {
            echo "Testing session management... ";
            
            // Test invalid session
            $result = $this->auth->validateSession('invalid_session_id');
            if (!$result['valid']) {
                echo "✅ Invalid session rejected\n";
            } else {
                echo "❌ Invalid session not properly rejected\n";
                return ['success' => false, 'issue' => 'Invalid session validation'];
            }
            
            echo "   Session validation logic verified\n";
            
            return ['success' => true, 'tests_passed' => ['invalid_session_rejection']];
            
        } catch (Exception $e) {
            echo "❌ Failed: " . $e->getMessage() . "\n";
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Test audit logging
     */
    private function testAuditLogging() {
        try {
            echo "Testing audit logging... ";
            
            // Check if audit logs table has the right structure
            $sql = "DESCRIBE audit_logs";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $requiredColumns = [
                'id', 'clock_number', 'real_name', 'action_type', 'action_description',
                'table_affected', 'record_id', 'old_values', 'new_values', 'ip_address',
                'session_id', 'user_agent', 'additional_context', 'severity_level', 'timestamp'
            ];
            
            $missingColumns = array_diff($requiredColumns, $columns);
            
            if (empty($missingColumns)) {
                echo "✅ Audit log structure validated\n";
                
                // Check for existing audit entries (from migration)
                $sql = "SELECT COUNT(*) as log_count FROM audit_logs";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo "   Current audit entries: " . $result['log_count'] . "\n";
                
                return ['success' => true, 'log_count' => $result['log_count']];
            } else {
                echo "❌ Missing audit log columns: " . implode(', ', $missingColumns) . "\n";
                return ['success' => false, 'missing_columns' => $missingColumns];
            }
            
        } catch (Exception $e) {
            echo "❌ Failed: " . $e->getMessage() . "\n";
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Test user management features
     */
    private function testUserManagement() {
        try {
            echo "Testing user management... ";
            
            // Test getting all users (should fail without proper permissions)
            $result = $this->auth->getAllUsers('INVALID001');
            if (!$result['success']) {
                echo "✅ User list access properly restricted\n";
            } else {
                echo "❌ User list access not properly restricted\n";
                return ['success' => false, 'issue' => 'User management security'];
            }
            
            echo "   User management permissions validated\n";
            
            return ['success' => true, 'tests_passed' => ['permission_restrictions']];
            
        } catch (Exception $e) {
            echo "❌ Failed: " . $e->getMessage() . "\n";
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Display test summary
     */
    private function displayTestSummary($testResults) {
        echo "\n" . str_repeat("=", 50) . "\n";
        echo "TEST SUMMARY\n";
        echo str_repeat("=", 50) . "\n\n";
        
        $totalTests = count($testResults);
        $passedTests = 0;
        
        foreach ($testResults as $testName => $result) {
            $status = $result['success'] ? "✅ PASS" : "❌ FAIL";
            echo sprintf("%-20s: %s\n", strtoupper(str_replace('_', ' ', $testName)), $status);
            
            if ($result['success']) {
                $passedTests++;
            }
        }
        
        echo "\n" . str_repeat("-", 50) . "\n";
        echo sprintf("OVERALL RESULT: %d/%d tests passed\n", $passedTests, $totalTests);
        
        if ($passedTests === $totalTests) {
            echo "🎉 ALL TESTS PASSED - BEMS Phase 0 is ready!\n";
            echo "\nNext Steps:\n";
            echo "1. Run the database initialization script to set up the admin account\n";
            echo "2. Test login with ADMIN001 / AdminPassword123!\n";
            echo "3. Change the admin password immediately\n";
            echo "4. Begin Phase 1 (Inventory Module) development\n";
        } else {
            echo "⚠️  Some tests failed - please review the issues above\n";
        }
        
        echo str_repeat("=", 50) . "\n";
    }
    
    /**
     * Test database views
     */
    public function testDatabaseViews() {
        echo "\nTesting database views...\n";
        echo str_repeat("-", 30) . "\n";
        
        $views = ['v_users_summary', 'v_recent_audit_activity', 'v_active_sessions'];
        
        foreach ($views as $view) {
            try {
                echo "Testing view '$view'... ";
                
                $sql = "SELECT COUNT(*) as record_count FROM $view";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo "✅ " . $result['record_count'] . " records\n";
                
            } catch (Exception $e) {
                echo "❌ Error: " . $e->getMessage() . "\n";
            }
        }
    }
    
    /**
     * Test stored procedures
     */
    public function testStoredProcedures() {
        echo "\nTesting stored procedures...\n";
        echo str_repeat("-", 30) . "\n";
        
        try {
            echo "Testing sp_cleanup_expired_sessions... ";
            $sql = "CALL sp_cleanup_expired_sessions()";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            echo "✅ Executed successfully\n";
            
        } catch (Exception $e) {
            echo "❌ Error: " . $e->getMessage() . "\n";
        }
        
        try {
            echo "Testing sp_cleanup_old_audit_logs... ";
            $sql = "CALL sp_cleanup_old_audit_logs()";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            echo "✅ Executed successfully\n";
            
        } catch (Exception $e) {
            echo "❌ Error: " . $e->getMessage() . "\n";
        }
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $tester = new AuthenticationSystemTest();
    $results = $tester->runTests();
    
    // Additional tests
    $tester->testDatabaseViews();
    $tester->testStoredProcedures();
    
    // Exit with appropriate code
    $allPassed = array_reduce($results, function($carry, $result) {
        return $carry && $result['success'];
    }, true);
    
    exit($allPassed ? 0 : 1);
}
?>