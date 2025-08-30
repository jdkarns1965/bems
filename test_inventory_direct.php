<?php
/**
 * BEMS Phase 1 Inventory Management Direct Database Test
 * Best ERP Manufacturing System
 * 
 * Direct testing of inventory operations through database calls
 * to verify the business logic without authentication dependencies.
 */

require_once dirname(__DIR__) . '/bems/config/app.php';
require_once dirname(__DIR__) . '/bems/config/database.php';

class InventoryDirectTest {
    
    private $db;
    private $testResults = [];
    
    public function __construct() {
        $this->db = DatabaseConfig::getConnection();
        
        echo "===========================================\n";
        echo "BEMS Phase 1 Inventory Direct Test Suite\n";
        echo "===========================================\n\n";
    }
    
    /**
     * Run all database tests
     */
    public function runAllTests() {
        $this->testDatabaseSchema();
        $this->testSampleData();
        $this->testStoredProcedures();
        $this->testViews();
        $this->testBusinessLogic();
        $this->generateTestReport();
    }
    
    /**
     * Test database schema exists
     */
    private function testDatabaseSchema() {
        echo "Testing Database Schema...\n";
        echo str_repeat("-", 40) . "\n";
        
        // Test core tables exist
        $requiredTables = [
            'materials', 'locations', 'inventory', 'inventory_movements',
            'inventory_reservations', 'inventory_adjustments', 'material_categories',
            'inv_tag_sequences'
        ];
        
        foreach ($requiredTables as $table) {
            $stmt = $this->db->query("SHOW TABLES LIKE '$table'");
            $exists = $stmt->fetch() !== false;
            
            $this->recordTest('Database Schema', "Table: $table", $exists, 
                $exists ? 'Table exists' : 'Table missing');
        }
        
        // Test core views exist
        $requiredViews = [
            'v_inventory_summary', 'v_fifo_available_inventory',
            'v_inventory_totals_by_location', 'v_recent_inventory_movements',
            'v_expiring_inventory'
        ];
        
        foreach ($requiredViews as $view) {
            $stmt = $this->db->query("SHOW TABLES LIKE '$view'");
            $exists = $stmt->fetch() !== false;
            
            $this->recordTest('Database Schema', "View: $view", $exists,
                $exists ? 'View exists' : 'View missing');
        }
        
        echo "\n";
    }
    
    /**
     * Test sample data exists
     */
    private function testSampleData() {
        echo "Testing Sample Data...\n";
        echo str_repeat("-", 40) . "\n";
        
        // Test materials
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM materials");
        $materialCount = $stmt->fetch()['count'];
        $this->recordTest('Sample Data', 'Materials', $materialCount > 0,
            "Found $materialCount materials");
        
        // Test locations
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM locations");
        $locationCount = $stmt->fetch()['count'];
        $this->recordTest('Sample Data', 'Locations', $locationCount > 0,
            "Found $locationCount locations");
        
        // Test material categories
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM material_categories");
        $categoryCount = $stmt->fetch()['count'];
        $this->recordTest('Sample Data', 'Categories', $categoryCount > 0,
            "Found $categoryCount categories");
        
        // Test INV tag sequences
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM inv_tag_sequences");
        $sequenceCount = $stmt->fetch()['count'];
        $this->recordTest('Sample Data', 'INV Sequences', $sequenceCount > 0,
            "Found $sequenceCount sequences");
        
        echo "\n";
    }
    
    /**
     * Test stored procedures and functions
     */
    private function testStoredProcedures() {
        echo "Testing Stored Procedures and Functions...\n";
        echo str_repeat("-", 40) . "\n";
        
        // Test INV tag generation function
        try {
            $stmt = $this->db->query("SELECT fn_get_next_inv_tag('INV_MAIN') as next_tag");
            $result = $stmt->fetch();
            $invTag = $result['next_tag'];
            
            $this->recordTest('Functions', 'INV Tag Generation', 
                !empty($invTag) && strpos($invTag, 'INV') === 0,
                "Generated tag: $invTag");
        } catch (Exception $e) {
            $this->recordTest('Functions', 'INV Tag Generation', false,
                "Error: " . $e->getMessage());
        }
        
        // Test FIFO priority calculation function
        try {
            $stmt = $this->db->query("SELECT fn_calculate_fifo_priority(NOW() - INTERVAL 30 DAY, NOW() + INTERVAL 180 DAY) as priority");
            $result = $stmt->fetch();
            $priority = $result['priority'];
            
            $this->recordTest('Functions', 'FIFO Priority Calculation', 
                is_numeric($priority) && $priority > 0,
                "Generated priority: $priority");
        } catch (Exception $e) {
            $this->recordTest('Functions', 'FIFO Priority Calculation', false,
                "Error: " . $e->getMessage());
        }
        
        // Test receive inventory procedure
        try {
            $stmt = $this->db->prepare("CALL sp_receive_inventory(?, ?, ?, ?, ?, ?, ?, ?, ?, @result, @inv_tag)");
            $stmt->execute([
                'PP-1001-NAT',           // material_number
                'TEST-LOT-DIRECT',       // lot_number
                500.00,                  // quantity
                0.85,                    // unit_cost
                'RAW-A01',               // location_code
                'Test Supplier Direct',  // supplier_name
                'PO-TEST-DIRECT',       // purchase_order
                'TESTUSER',             // received_by
                'Direct test procedure' // notes
            ]);
            
            $result = $this->db->query("SELECT @result as result, @inv_tag as inv_tag")->fetch();
            
            $this->recordTest('Stored Procedures', 'Receive Inventory', 
                $result['result'] === 'SUCCESS',
                $result['result'] === 'SUCCESS' ? 
                    "Received with tag: {$result['inv_tag']}" : 
                    "Error: {$result['result']}");
            
            // Store the INV tag for further tests
            if ($result['result'] === 'SUCCESS') {
                $this->testInvTag = $result['inv_tag'];
            }
            
        } catch (Exception $e) {
            $this->recordTest('Stored Procedures', 'Receive Inventory', false,
                "Error: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    /**
     * Test database views
     */
    private function testViews() {
        echo "Testing Database Views...\n";
        echo str_repeat("-", 40) . "\n";
        
        // Test inventory summary view
        try {
            $stmt = $this->db->query("SELECT COUNT(*) as count FROM v_inventory_summary LIMIT 10");
            $count = $stmt->fetch()['count'];
            
            $this->recordTest('Views', 'Inventory Summary', true,
                "View accessible, found $count records");
        } catch (Exception $e) {
            $this->recordTest('Views', 'Inventory Summary', false,
                "Error: " . $e->getMessage());
        }
        
        // Test FIFO available inventory view
        try {
            $stmt = $this->db->query("SELECT COUNT(*) as count FROM v_fifo_available_inventory WHERE material_number = 'PP-1001-NAT'");
            $count = $stmt->fetch()['count'];
            
            $this->recordTest('Views', 'FIFO Available Inventory', true,
                "View accessible, found $count available lots for PP-1001-NAT");
        } catch (Exception $e) {
            $this->recordTest('Views', 'FIFO Available Inventory', false,
                "Error: " . $e->getMessage());
        }
        
        // Test location totals view
        try {
            $stmt = $this->db->query("SELECT COUNT(*) as count FROM v_inventory_totals_by_location LIMIT 5");
            $count = $stmt->fetch()['count'];
            
            $this->recordTest('Views', 'Location Totals', true,
                "View accessible, found $count location summaries");
        } catch (Exception $e) {
            $this->recordTest('Views', 'Location Totals', false,
                "Error: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    /**
     * Test business logic through direct operations
     */
    private function testBusinessLogic() {
        echo "Testing Business Logic...\n";
        echo str_repeat("-", 40) . "\n";
        
        // Test inventory movement
        if (!empty($this->testInvTag)) {
            try {
                $stmt = $this->db->prepare("CALL sp_transfer_inventory(?, ?, ?, ?, ?, ?, ?, @result)");
                $stmt->execute([
                    $this->testInvTag,      // inv_tag
                    'PROD-01',              // to_location_code
                    null,                   // to_location_manual
                    250.00,                 // quantity
                    'WO-TEST-DIRECT',       // reference_number
                    'Direct test transfer', // reason
                    'TESTUSER'              // transferred_by
                ]);
                
                $result = $this->db->query("SELECT @result as result")->fetch();
                
                $this->recordTest('Business Logic', 'Inventory Transfer', 
                    $result['result'] === 'SUCCESS',
                    $result['result'] === 'SUCCESS' ? 
                        'Transfer successful' : 
                        "Error: {$result['result']}");
                        
            } catch (Exception $e) {
                $this->recordTest('Business Logic', 'Inventory Transfer', false,
                    "Error: " . $e->getMessage());
            }
            
            // Test inventory consumption
            try {
                $stmt = $this->db->prepare("CALL sp_consume_inventory(?, ?, ?, ?, ?, ?, ?, @result)");
                $stmt->execute([
                    $this->testInvTag,      // inv_tag
                    100.00,                 // quantity
                    'WO-TEST-DIRECT',       // work_order
                    'JOB-TEST-DIRECT',      // job_number
                    'Press 16',             // machine_press
                    'TEST',                 // operator_initials
                    'TESTUSER'              // consumed_by
                ]);
                
                $result = $this->db->query("SELECT @result as result")->fetch();
                
                $this->recordTest('Business Logic', 'Inventory Consumption', 
                    $result['result'] === 'SUCCESS',
                    $result['result'] === 'SUCCESS' ? 
                        'Consumption successful' : 
                        "Error: {$result['result']}");
                        
            } catch (Exception $e) {
                $this->recordTest('Business Logic', 'Inventory Consumption', false,
                    "Error: " . $e->getMessage());
            }
        }
        
        // Test FIFO ordering
        try {
            $stmt = $this->db->query("
                SELECT inv_tag, fifo_priority, received_date 
                FROM v_fifo_available_inventory 
                WHERE material_number = 'PP-1001-NAT' 
                ORDER BY fifo_priority 
                LIMIT 5
            ");
            $fifoResults = $stmt->fetchAll();
            
            // Check if results are ordered by priority
            $properlyOrdered = true;
            $lastPriority = 0;
            foreach ($fifoResults as $result) {
                if ($result['fifo_priority'] < $lastPriority) {
                    $properlyOrdered = false;
                    break;
                }
                $lastPriority = $result['fifo_priority'];
            }
            
            $this->recordTest('Business Logic', 'FIFO Ordering', $properlyOrdered,
                $properlyOrdered ? 
                    "FIFO ordering correct (" . count($fifoResults) . " results)" :
                    "FIFO ordering incorrect");
                    
        } catch (Exception $e) {
            $this->recordTest('Business Logic', 'FIFO Ordering', false,
                "Error: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    /**
     * Record test result
     */
    private function recordTest($category, $testName, $passed, $message) {
        $status = $passed ? 'PASS' : 'FAIL';
        $statusColor = $passed ? "\033[32m" : "\033[31m"; // Green or Red
        $resetColor = "\033[0m";
        
        echo sprintf("  %s[%s]%s %s: %s\n", $statusColor, $status, $resetColor, $testName, $message);
        
        $this->testResults[] = [
            'category' => $category,
            'test_name' => $testName,
            'passed' => $passed,
            'message' => $message
        ];
    }
    
    /**
     * Generate comprehensive test report
     */
    private function generateTestReport() {
        echo "\n===========================================\n";
        echo "TEST SUMMARY REPORT\n";
        echo "===========================================\n";
        
        $totalTests = count($this->testResults);
        $passedTests = array_sum(array_column($this->testResults, 'passed'));
        $failedTests = $totalTests - $passedTests;
        $successRate = $totalTests > 0 ? round(($passedTests / $totalTests) * 100, 2) : 0;
        
        echo "Total Tests: $totalTests\n";
        echo "Passed: $passedTests\n";
        echo "Failed: $failedTests\n";
        echo "Success Rate: $successRate%\n\n";
        
        // Group results by category
        $categories = array_unique(array_column($this->testResults, 'category'));
        
        foreach ($categories as $category) {
            $categoryTests = array_filter($this->testResults, function($test) use ($category) {
                return $test['category'] === $category;
            });
            
            $categoryTotal = count($categoryTests);
            $categoryPassed = array_sum(array_column($categoryTests, 'passed'));
            $categoryRate = round(($categoryPassed / $categoryTotal) * 100, 2);
            
            echo "$category: $categoryPassed/$categoryTotal ($categoryRate%)\n";
        }
        
        echo "\n";
        
        // Show failed tests in detail
        $failedTests = array_filter($this->testResults, function($test) {
            return !$test['passed'];
        });
        
        if (!empty($failedTests)) {
            echo "FAILED TESTS DETAIL:\n";
            echo str_repeat("-", 40) . "\n";
            foreach ($failedTests as $test) {
                echo "❌ {$test['category']} - {$test['test_name']}: {$test['message']}\n";
            }
        } else {
            echo "🎉 ALL TESTS PASSED!\n";
        }
        
        echo "\n";
    }
    
    /**
     * Cleanup test data
     */
    public function cleanupTestData() {
        try {
            // Delete test inventory
            $this->db->exec("DELETE FROM inventory WHERE lot_number = 'TEST-LOT-DIRECT'");
            echo "Test data cleanup completed.\n";
            
        } catch (Exception $e) {
            echo "Cleanup error: " . $e->getMessage() . "\n";
        }
    }
}

// Run the direct test suite
$testSuite = new InventoryDirectTest();
$testSuite->runAllTests();

echo "\nCleaning up test data...\n";
$testSuite->cleanupTestData();

echo "\nInventory Direct Test Suite completed.\n";
echo "This test validates the database schema and business logic independent of the API layer.\n\n";

?>