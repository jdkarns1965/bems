<?php
/**
 * BEMS Phase 1 Inventory Management API Test Suite
 * Best ERP Manufacturing System
 * 
 * Comprehensive testing of all inventory management API endpoints
 * including authentication, CRUD operations, and business logic.
 */

require_once dirname(__DIR__) . '/bems/config/app.php';
require_once dirname(__DIR__) . '/bems/config/database.php';

class InventoryApiTestSuite {
    
    private $baseUrl;
    private $sessionCookie;
    private $testResults = [];
    private $db;
    
    public function __construct() {
        // Set up test environment
        $this->baseUrl = 'http://localhost/bems';
        $this->db = DatabaseConfig::getConnection();
        
        echo "===========================================\n";
        echo "BEMS Phase 1 Inventory API Test Suite\n";
        echo "===========================================\n\n";
    }
    
    /**
     * Run all test suites
     */
    public function runAllTests() {
        $this->testAuthentication();
        $this->testMaterialsEndpoints();
        $this->testLocationsEndpoints();
        $this->testInventoryEndpoints();
        $this->testBusinessLogic();
        $this->generateTestReport();
    }
    
    /**
     * Test authentication and session management
     */
    private function testAuthentication() {
        echo "Testing Authentication System...\n";
        echo str_repeat("-", 40) . "\n";
        
        // Test login with default admin user (should exist from Phase 0)
        $loginData = [
            'clock_number' => 'ADMIN001',
            'password' => 'TempPass123!'
        ];
        
        $response = $this->makeApiRequest('POST', '/api/v1/auth/login', $loginData);
        
        if ($response && isset($response['success']) && $response['success']) {
            $this->recordTest('Authentication', 'Admin Login', true, 'Successfully logged in');
            
            // Extract session cookie for subsequent requests
            $this->extractSessionCookie();
            
            // Test authentication status
            $statusResponse = $this->makeApiRequest('GET', '/api/v1/auth/status');
            $this->recordTest('Authentication', 'Status Check', 
                $statusResponse && $statusResponse['authenticated'], 
                'Authentication status verified');
                
        } else {
            $this->recordTest('Authentication', 'Admin Login', false, 'Failed to authenticate');
            echo "ERROR: Cannot proceed without authentication. Please ensure Phase 0 is properly set up.\n";
            return false;
        }
        
        echo "\n";
        return true;
    }
    
    /**
     * Test materials management endpoints
     */
    private function testMaterialsEndpoints() {
        echo "Testing Materials Management Endpoints...\n";
        echo str_repeat("-", 40) . "\n";
        
        // Test get all materials
        $response = $this->makeApiRequest('GET', '/api/v1/materials');
        $this->recordTest('Materials', 'Get All Materials', 
            $response && isset($response['success']) && $response['success'],
            'Retrieved materials list');
        
        // Test get materials dropdown
        $response = $this->makeApiRequest('GET', '/api/v1/materials/dropdown');
        $this->recordTest('Materials', 'Get Dropdown Materials', 
            $response && isset($response['success']) && $response['success'],
            'Retrieved dropdown materials');
        
        // Test get material categories
        $response = $this->makeApiRequest('GET', '/api/v1/materials/categories');
        $this->recordTest('Materials', 'Get Categories', 
            $response && isset($response['success']) && $response['success'],
            'Retrieved material categories');
        
        // Test get specific material (should have sample materials from Phase 1)
        $response = $this->makeApiRequest('GET', '/api/v1/materials/PP-1001-NAT');
        $this->recordTest('Materials', 'Get Specific Material', 
            $response && isset($response['success']) && $response['success'],
            'Retrieved specific material details');
        
        // Test create new material (Admin only)
        $testMaterial = [
            'material_number' => 'TEST-001',
            'material_name' => 'Test Material API',
            'description' => 'Created via API test',
            'category_code' => 'RAW',
            'primary_uom' => 'LBS',
            'material_grade' => 'Test Grade',
            'standard_cost' => 1.50,
            'abc_classification' => 'C'
        ];
        
        $response = $this->makeApiRequest('POST', '/api/v1/materials', $testMaterial);
        $testMaterialCreated = $response && isset($response['success']) && $response['success'];
        $this->recordTest('Materials', 'Create Material', $testMaterialCreated, 
            $testMaterialCreated ? 'Test material created' : 'Failed to create material');
        
        // Test update material (Admin only)
        if ($testMaterialCreated) {
            $updateData = [
                'description' => 'Updated via API test',
                'standard_cost' => 1.75
            ];
            
            $response = $this->makeApiRequest('PUT', '/api/v1/materials/TEST-001', $updateData);
            $this->recordTest('Materials', 'Update Material', 
                $response && isset($response['success']) && $response['success'],
                'Test material updated');
        }
        
        echo "\n";
    }
    
    /**
     * Test locations management endpoints
     */
    private function testLocationsEndpoints() {
        echo "Testing Locations Management Endpoints...\n";
        echo str_repeat("-", 40) . "\n";
        
        // Test get all locations
        $response = $this->makeApiRequest('GET', '/api/v1/locations');
        $this->recordTest('Locations', 'Get All Locations', 
            $response && isset($response['success']) && $response['success'],
            'Retrieved locations list');
        
        // Test get locations dropdown
        $response = $this->makeApiRequest('GET', '/api/v1/locations/dropdown');
        $this->recordTest('Locations', 'Get Dropdown Locations', 
            $response && isset($response['success']) && $response['success'],
            'Retrieved dropdown locations');
        
        // Test get location types
        $response = $this->makeApiRequest('GET', '/api/v1/locations/types');
        $this->recordTest('Locations', 'Get Location Types', 
            $response && isset($response['success']) && $response['success'],
            'Retrieved location types');
        
        // Test get specific location
        $response = $this->makeApiRequest('GET', '/api/v1/locations/RAW-A01');
        $this->recordTest('Locations', 'Get Specific Location', 
            $response && isset($response['success']) && $response['success'],
            'Retrieved specific location details');
        
        // Test create new location (Admin only)
        $testLocation = [
            'location_code' => 'TEST-001',
            'location_name' => 'Test Location API',
            'description' => 'Created via API test',
            'location_type' => 'STORAGE',
            'building' => 'Test Building',
            'zone_area' => 'Test Zone',
            'active_status' => 1
        ];
        
        $response = $this->makeApiRequest('POST', '/api/v1/locations', $testLocation);
        $testLocationCreated = $response && isset($response['success']) && $response['success'];
        $this->recordTest('Locations', 'Create Location', $testLocationCreated, 
            $testLocationCreated ? 'Test location created' : 'Failed to create location');
        
        // Test update location (Admin only)
        if ($testLocationCreated) {
            $updateData = [
                'description' => 'Updated via API test',
                'capacity_quantity' => 5000.00
            ];
            
            $response = $this->makeApiRequest('PUT', '/api/v1/locations/TEST-001', $updateData);
            $this->recordTest('Locations', 'Update Location', 
                $response && isset($response['success']) && $response['success'],
                'Test location updated');
        }
        
        echo "\n";
    }
    
    /**
     * Test inventory management endpoints
     */
    private function testInventoryEndpoints() {
        echo "Testing Inventory Management Endpoints...\n";
        echo str_repeat("-", 40) . "\n";
        
        // Test get all inventory
        $response = $this->makeApiRequest('GET', '/api/v1/inventory');
        $this->recordTest('Inventory', 'Get All Inventory', 
            $response && isset($response['success']) && $response['success'],
            'Retrieved inventory list');
        
        // Test inventory receiving
        $receiveData = [
            'material_number' => 'PP-1001-NAT',
            'lot_number' => 'TEST-LOT-' . date('Ymd-His'),
            'quantity' => 1000.00,
            'unit_cost' => 0.95,
            'location_code' => 'RAW-A01',
            'supplier_name' => 'Test Supplier API',
            'purchase_order' => 'PO-TEST-001',
            'notes' => 'Received via API test'
        ];
        
        $response = $this->makeApiRequest('POST', '/api/v1/inventory/receive', $receiveData);
        $receiveSuccess = $response && isset($response['success']) && $response['success'];
        $testInvTag = null;
        
        if ($receiveSuccess && isset($response['data']['inv_tag'])) {
            $testInvTag = $response['data']['inv_tag'];
        }
        
        $this->recordTest('Inventory', 'Receive Material', $receiveSuccess, 
            $receiveSuccess ? "Material received with tag: $testInvTag" : 'Failed to receive material');
        
        // Test get specific inventory by INV tag
        if ($testInvTag) {
            $response = $this->makeApiRequest('GET', "/api/v1/inventory/$testInvTag");
            $this->recordTest('Inventory', 'Get Inventory by Tag', 
                $response && isset($response['success']) && $response['success'],
                'Retrieved inventory by INV tag');
        }
        
        // Test FIFO inventory lookup
        $response = $this->makeApiRequest('GET', '/api/v1/inventory/fifo/PP-1001-NAT');
        $this->recordTest('Inventory', 'Get FIFO Inventory', 
            $response && isset($response['success']) && $response['success'],
            'Retrieved FIFO inventory');
        
        // Test inventory movement
        if ($testInvTag) {
            $moveData = [
                'to_location_code' => 'PROD-01',
                'quantity' => 500.00,
                'reference_number' => 'WO-TEST-001',
                'reason' => 'Moved for production via API test'
            ];
            
            $response = $this->makeApiRequest('POST', "/api/v1/inventory/$testInvTag/move", $moveData);
            $this->recordTest('Inventory', 'Move Inventory', 
                $response && isset($response['success']) && $response['success'],
                'Inventory moved successfully');
        }
        
        // Test inventory consumption
        if ($testInvTag) {
            $consumeData = [
                'quantity' => 250.00,
                'work_order' => 'WO-TEST-001',
                'job_number' => 'JOB-TEST-001',
                'machine_press' => 'Press 16',
                'operator_initials' => 'TEST'
            ];
            
            $response = $this->makeApiRequest('POST', "/api/v1/inventory/$testInvTag/consume", $consumeData);
            $this->recordTest('Inventory', 'Consume Inventory', 
                $response && isset($response['success']) && $response['success'],
                'Inventory consumed successfully');
        }
        
        // Test location summary
        $response = $this->makeApiRequest('GET', '/api/v1/inventory/summary/location');
        $this->recordTest('Inventory', 'Get Location Summary', 
            $response && isset($response['success']) && $response['success'],
            'Retrieved location summary');
        
        // Test expiring alerts
        $response = $this->makeApiRequest('GET', '/api/v1/inventory/alerts/expiring');
        $this->recordTest('Inventory', 'Get Expiring Alerts', 
            $response && isset($response['success']) && $response['success'],
            'Retrieved expiring inventory alerts');
        
        echo "\n";
    }
    
    /**
     * Test business logic and validation
     */
    private function testBusinessLogic() {
        echo "Testing Business Logic and Validation...\n";
        echo str_repeat("-", 40) . "\n";
        
        // Test invalid material receiving
        $invalidReceive = [
            'material_number' => 'NON-EXISTENT',
            'lot_number' => 'TEST-INVALID',
            'quantity' => -100, // Invalid negative quantity
            'unit_cost' => 1.00,
            'location_code' => 'RAW-A01'
        ];
        
        $response = $this->makeApiRequest('POST', '/api/v1/inventory/receive', $invalidReceive);
        $this->recordTest('Business Logic', 'Invalid Material Receive', 
            $response && isset($response['success']) && !$response['success'],
            'Correctly rejected invalid receive data');
        
        // Test moving more inventory than available
        if (!empty($testInvTag)) {
            $excessiveMove = [
                'to_location_code' => 'PROD-01',
                'quantity' => 99999999.00, // Excessive quantity
                'reason' => 'Testing excessive quantity'
            ];
            
            $response = $this->makeApiRequest('POST', "/api/v1/inventory/$testInvTag/move", $excessiveMove);
            $this->recordTest('Business Logic', 'Excessive Quantity Move', 
                $response && isset($response['success']) && !$response['success'],
                'Correctly rejected excessive quantity move');
        }
        
        // Test access control - non-admin trying to create material
        // First logout as admin
        $this->makeApiRequest('POST', '/api/v1/auth/logout');
        
        // Try to login as regular user (if exists) or test without authentication
        $testMaterial = [
            'material_number' => 'UNAUTH-001',
            'material_name' => 'Unauthorized Material',
            'category_code' => 'RAW',
            'primary_uom' => 'LBS',
            'material_grade' => 'Test'
        ];
        
        $response = $this->makeApiRequest('POST', '/api/v1/materials', $testMaterial);
        $this->recordTest('Business Logic', 'Unauthorized Material Create', 
            $response && isset($response['success']) && !$response['success'],
            'Correctly blocked unauthorized material creation');
        
        // Re-authenticate as admin for cleanup
        $this->testAuthentication();
        
        echo "\n";
    }
    
    /**
     * Make API request with proper headers and session handling
     */
    private function makeApiRequest($method, $endpoint, $data = null) {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        // Set method-specific options
        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Cookie: ' . $this->sessionCookie
                    ]);
                } else {
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Cookie: ' . $this->sessionCookie
                    ]);
                }
                break;
                
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Cookie: ' . $this->sessionCookie
                    ]);
                } else {
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Cookie: ' . $this->sessionCookie
                    ]);
                }
                break;
                
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Cookie: ' . $this->sessionCookie
                ]);
                break;
                
            default: // GET
                if ($this->sessionCookie) {
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Cookie: ' . $this->sessionCookie
                    ]);
                }
        }
        
        $response = curl_exec($ch);
        
        if (curl_error($ch)) {
            echo "CURL Error: " . curl_error($ch) . "\n";
            curl_close($ch);
            return null;
        }
        
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        curl_close($ch);
        
        // Store session cookie from login response
        if (strpos($endpoint, 'auth/login') !== false && strpos($header, 'Set-Cookie:') !== false) {
            $this->extractSessionCookieFromHeader($header);
        }
        
        return json_decode($body, true);
    }
    
    /**
     * Extract session cookie from response header
     */
    private function extractSessionCookieFromHeader($header) {
        if (preg_match('/Set-Cookie:\s*([^;]+)/', $header, $matches)) {
            $this->sessionCookie = $matches[1];
        }
    }
    
    /**
     * Extract session cookie from current session
     */
    private function extractSessionCookie() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $this->sessionCookie = session_name() . '=' . session_id();
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
    private function cleanupTestData() {
        try {
            // Delete test material
            $this->db->exec("DELETE FROM materials WHERE material_number = 'TEST-001'");
            
            // Delete test location
            $this->db->exec("DELETE FROM locations WHERE location_code = 'TEST-001'");
            
            // Delete test inventory (INV tags starting with INV will be cleaned up by database constraints)
            
            echo "Test data cleanup completed.\n";
            
        } catch (Exception $e) {
            echo "Cleanup error: " . $e->getMessage() . "\n";
        }
    }
}

// Run the test suite
$testSuite = new InventoryApiTestSuite();
$testSuite->runAllTests();

echo "Inventory API Test Suite completed.\n";
echo "Check the results above for any failed tests.\n\n";

?>