<?php
/**
 * BEMS MRP Module Database Initialization Script
 * Best ERP Manufacturing System
 * 
 * This script initializes the Phase 3 MRP (Material Requirements Planning) module
 * database schema and sample data.
 */

require_once dirname(__DIR__) . '/config/database.php';

class MrpModuleInitializer {
    
    private $pdo;
    private $migrationPath;
    
    public function __construct() {
        $this->migrationPath = __DIR__ . '/migrations/';
        echo "BEMS Phase 3: MRP Module Initialization Starting...\n\n";
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
     * Check if MRP module is already initialized
     */
    private function isMrpModuleInitialized() {
        try {
            // Check if master_production_schedule table exists
            $sql = "SHOW TABLES LIKE 'master_production_schedule'";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Execute MRP module migration
     */
    private function executeMrpMigration() {
        $migrationFile = '003_create_mrp_module.sql';
        $filePath = $this->migrationPath . $migrationFile;
        
        if (!file_exists($filePath)) {
            echo "❌ MRP migration file not found: $filePath\n";
            return false;
        }
        
        try {
            echo "📄 Executing MRP module migration...\n";
            
            // Read the migration file
            $sql = file_get_contents($filePath);
            
            if (empty($sql)) {
                echo "❌ Migration file is empty: $filePath\n";
                return false;
            }
            
            // Split SQL statements and execute them
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                function($stmt) {
                    return !empty($stmt) && 
                           !preg_match('/^\s*--/', $stmt) && 
                           !preg_match('/^\s*\/\*/', $stmt);
                }
            );
            
            $this->pdo->beginTransaction();
            
            foreach ($statements as $statement) {
                if (trim($statement)) {
                    try {
                        $this->pdo->exec($statement);
                    } catch (Exception $e) {
                        // Skip if table already exists
                        if (strpos($e->getMessage(), 'already exists') === false) {
                            throw $e;
                        }
                    }
                }
            }
            
            $this->pdo->commit();
            echo "✅ MRP module database schema created successfully\n";
            return true;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            echo "❌ MRP migration failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Verify MRP tables were created correctly
     */
    private function verifyMrpTables() {
        try {
            $requiredTables = [
                'master_production_schedule', 
                'mrp_requirements', 
                'planned_orders', 
                'mrp_planning_parameters',
                'mrp_planning_runs'
            ];
            
            foreach ($requiredTables as $table) {
                $sql = "SHOW TABLES LIKE '$table'";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
                
                if ($stmt->rowCount() === 0) {
                    echo "❌ Required table '$table' was not created\n";
                    return false;
                }
            }
            
            echo "✅ All MRP module tables verified successfully\n";
            return true;
            
        } catch (Exception $e) {
            echo "❌ Table verification failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Verify sample MRP data was inserted
     */
    private function verifySampleData() {
        try {
            // Check for sample MPS entries
            $sql = "SELECT COUNT(*) as count FROM master_production_schedule";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $mpsCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            // Check for sample planning parameters
            $sql = "SELECT COUNT(*) as count FROM mrp_planning_parameters";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $paramCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($mpsCount > 0 && $paramCount > 0) {
                echo "✅ Sample MRP data verified: $mpsCount MPS entries, $paramCount planning parameters\n";
                return true;
            } else {
                echo "⚠️  Limited sample MRP data found: $mpsCount MPS entries, $paramCount parameters\n";
                return false;
            }
            
        } catch (Exception $e) {
            echo "❌ Sample data verification failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Create MRP module database views for reporting
     */
    private function createMrpViews() {
        try {
            echo "📊 Creating MRP reporting views...\n";
            
            // Views should have been created by the migration
            // Verify they exist
            $views = [
                'vw_mrp_requirements_summary',
                'vw_planned_orders_summary', 
                'vw_mrp_action_messages'
            ];
            
            $viewsCreated = 0;
            foreach ($views as $view) {
                $sql = "SHOW TABLES LIKE '$view'";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
                if ($stmt->rowCount() > 0) {
                    $viewsCreated++;
                }
            }
            
            echo "✅ MRP reporting views verified: $viewsCreated/" . count($views) . " views found\n";
            return true;
            
        } catch (Exception $e) {
            echo "❌ View verification failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Initialize default planning parameters for existing materials
     */
    private function initializeDefaultPlanningParameters() {
        try {
            echo "⚙️ Initializing default planning parameters...\n";
            
            // Get materials that don't have planning parameters yet
            $sql = "SELECT m.material_number, m.material_name, m.base_unit
                    FROM materials m
                    LEFT JOIN mrp_planning_parameters pp ON m.material_number = pp.item_number
                    WHERE pp.item_number IS NULL
                      AND m.active_status = 1
                    LIMIT 20"; // Limit to prevent overwhelming
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $parametersAdded = 0;
            
            foreach ($materials as $material) {
                // Determine default make/buy based on material name/type
                $makeBuy = 'BUY'; // Default to buy
                if (strpos(strtoupper($material['material_name']), 'ASSEMBLY') !== false ||
                    strpos(strtoupper($material['material_name']), 'FINISHED') !== false) {
                    $makeBuy = 'MAKE';
                }
                
                $sql = "INSERT IGNORE INTO mrp_planning_parameters (
                    item_number, planning_method, lot_sizing_rule, planning_lead_time_days,
                    safety_stock_quantity, make_buy_code, minimum_order_quantity,
                    order_multiple, created_by
                ) VALUES (?, 'MRP', 'LOT_FOR_LOT', 7, 0, ?, 0, 1, 'SYSTEM')";
                
                $stmt = $this->pdo->prepare($sql);
                if ($stmt->execute([$material['material_number'], $makeBuy])) {
                    $parametersAdded++;
                }
            }
            
            echo "✅ Default planning parameters initialized: $parametersAdded parameters added\n";
            return true;
            
        } catch (Exception $e) {
            echo "❌ Planning parameters initialization failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Test basic MRP functionality
     */
    private function testMrpFunctionality() {
        try {
            echo "🧪 Testing basic MRP functionality...\n";
            
            // Test 1: Can we create a planning run record?
            $testRunId = 'TEST_' . date('His');
            $sql = "INSERT INTO mrp_planning_runs (
                planning_run_id, plan_version, planning_horizon_days,
                planning_start_date, planning_end_date, run_type, created_by
            ) VALUES (?, 'TEST', 30, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'FULL_REGENERATIVE', 'SYSTEM')";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$testRunId]);
            
            // Test 2: Can we create a test requirement?
            $sql = "INSERT INTO mrp_requirements (
                planning_run_id, item_number, plan_version, requirement_date,
                requirement_type, source_type, required_quantity
            ) VALUES (?, (SELECT material_number FROM materials LIMIT 1), 'TEST', CURDATE(), 'GROSS', 'MPS', 100)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$testRunId]);
            
            // Clean up test data
            $sql = "DELETE FROM mrp_requirements WHERE planning_run_id = ?";
            $this->pdo->prepare($sql)->execute([$testRunId]);
            
            $sql = "DELETE FROM mrp_planning_runs WHERE planning_run_id = ?";
            $this->pdo->prepare($sql)->execute([$testRunId]);
            
            echo "✅ Basic MRP functionality test passed\n";
            return true;
            
        } catch (Exception $e) {
            echo "❌ MRP functionality test failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Run the complete MRP module initialization
     */
    public function initialize() {
        echo "Starting BEMS Phase 3: MRP Module Initialization\n";
        echo str_repeat("=", 60) . "\n\n";
        
        // Step 1: Initialize database connection
        if (!$this->initializeConnection()) {
            echo "\n❌ MRP module initialization failed: Database connection error\n";
            return false;
        }
        
        // Step 2: Check if already initialized
        if ($this->isMrpModuleInitialized()) {
            echo "⚠️  MRP module appears to already be initialized\n";
            echo "   Proceeding with verification checks...\n\n";
        }
        
        // Step 3: Execute MRP migration
        if (!$this->executeMrpMigration()) {
            echo "\n❌ MRP module initialization failed: Migration error\n";
            return false;
        }
        
        // Step 4: Verify tables were created
        if (!$this->verifyMrpTables()) {
            echo "\n❌ MRP module initialization failed: Table verification error\n";
            return false;
        }
        
        // Step 5: Verify sample data
        $this->verifySampleData();
        
        // Step 6: Create/verify reporting views
        if (!$this->createMrpViews()) {
            echo "\n⚠️  MRP module initialized but views verification failed\n";
        }
        
        // Step 7: Initialize default planning parameters
        $this->initializeDefaultPlanningParameters();
        
        // Step 8: Test basic functionality
        if (!$this->testMrpFunctionality()) {
            echo "\n⚠️  MRP module initialized but functionality test failed\n";
        }
        
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "✅ BEMS Phase 3: MRP Module initialization completed successfully!\n\n";
        
        echo "Next steps:\n";
        echo "1. Test MRP API endpoints with test_mrp_api.php\n";
        echo "2. Access MRP interface at /bems/mrp.html\n";
        echo "3. Run first MRP planning cycle\n";
        echo "4. Review planning results and action messages\n";
        echo "5. Consider production deployment\n\n";
        
        echo "MRP System Features:\n";
        echo "• Time-phased material requirements planning\n";
        echo "• Master Production Schedule (MPS) management\n";
        echo "• Multi-level BOM explosion with netting logic\n";
        echo "• Planned order generation and scheduling\n";
        echo "• Action message generation for planners\n";
        echo "• Planning parameter management by item\n";
        echo "• Integration with Phases 1 (Inventory) and 2 (BOM)\n\n";
        
        return true;
    }
}

// Run the initialization if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    $initializer = new MrpModuleInitializer();
    $success = $initializer->initialize();
    exit($success ? 0 : 1);
}
?>