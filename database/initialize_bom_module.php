<?php
/**
 * BEMS BOM Module Database Initialization Script
 * Best ERP Manufacturing System
 * 
 * This script initializes the Phase 2 BOM (Bill of Materials) module
 * database schema and sample data.
 */

require_once dirname(__DIR__) . '/config/database.php';

class BomModuleInitializer {
    
    private $pdo;
    private $migrationPath;
    
    public function __construct() {
        $this->migrationPath = __DIR__ . '/migrations/';
        echo "BEMS Phase 2: BOM Module Initialization Starting...\n\n";
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
     * Check if BOM module is already initialized
     */
    private function isBomModuleInitialized() {
        try {
            // Check if bom_headers table exists
            $sql = "SHOW TABLES LIKE 'bom_headers'";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Execute BOM module migration
     */
    private function executeBomMigration() {
        $migrationFile = '002_create_bom_module.sql';
        $filePath = $this->migrationPath . $migrationFile;
        
        if (!file_exists($filePath)) {
            echo "❌ BOM migration file not found: $filePath\n";
            return false;
        }
        
        try {
            echo "📄 Executing BOM module migration...\n";
            
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
            echo "✅ BOM module database schema created successfully\n";
            return true;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            echo "❌ BOM migration failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Verify BOM tables were created correctly
     */
    private function verifyBomTables() {
        try {
            $requiredTables = ['bom_headers', 'bom_lines', 'bom_explosion_cache', 'bom_cost_rollup'];
            
            foreach ($requiredTables as $table) {
                $sql = "SHOW TABLES LIKE '$table'";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
                
                if ($stmt->rowCount() === 0) {
                    echo "❌ Required table '$table' was not created\n";
                    return false;
                }
            }
            
            echo "✅ All BOM module tables verified successfully\n";
            return true;
            
        } catch (Exception $e) {
            echo "❌ Table verification failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Verify sample BOM data was inserted
     */
    private function verifySampleData() {
        try {
            // Check for sample BOMs
            $sql = "SELECT COUNT(*) as count FROM bom_headers";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $bomCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            // Check for sample BOM lines
            $sql = "SELECT COUNT(*) as count FROM bom_lines";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $lineCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($bomCount > 0 && $lineCount > 0) {
                echo "✅ Sample BOM data verified: $bomCount BOMs, $lineCount lines\n";
                return true;
            } else {
                echo "⚠️  No sample BOM data found\n";
                return false;
            }
            
        } catch (Exception $e) {
            echo "❌ Sample data verification failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Create BOM module database views for reporting
     */
    private function createBomViews() {
        try {
            echo "📊 Creating BOM reporting views...\n";
            
            // BOM Structure View - flattened view of all BOMs with components
            $bomStructureView = "
                CREATE OR REPLACE VIEW vw_bom_structure AS
                SELECT 
                    h.id as bom_id,
                    h.bom_number,
                    h.parent_material_number,
                    h.bom_version,
                    h.description as bom_description,
                    h.base_quantity,
                    h.status as bom_status,
                    l.line_number,
                    l.component_material_number,
                    l.required_quantity,
                    l.unit_of_measure,
                    l.scrap_percentage,
                    l.is_critical,
                    l.is_phantom,
                    m.description as component_description,
                    m.unit_cost as component_unit_cost,
                    ROUND(l.required_quantity * (1 + l.scrap_percentage/100), 6) as net_required_qty,
                    ROUND(l.required_quantity * (1 + l.scrap_percentage/100) * m.unit_cost, 4) as extended_cost
                FROM bom_headers h
                JOIN bom_lines l ON h.id = l.bom_id
                LEFT JOIN materials m ON l.component_material_number = m.material_number
                WHERE h.status = 'active' 
                  AND l.effective_date <= CURDATE() 
                  AND (l.expiry_date IS NULL OR l.expiry_date > CURDATE())
                ORDER BY h.bom_number, l.line_number
            ";
            
            $this->pdo->exec($bomStructureView);
            
            // BOM Cost Summary View
            $bomCostView = "
                CREATE OR REPLACE VIEW vw_bom_cost_summary AS
                SELECT 
                    h.id as bom_id,
                    h.bom_number,
                    h.parent_material_number,
                    h.description,
                    h.base_quantity,
                    COUNT(l.id) as component_count,
                    SUM(l.required_quantity * (1 + l.scrap_percentage/100) * COALESCE(m.unit_cost, 0)) as total_material_cost,
                    SUM(l.required_quantity * (1 + l.scrap_percentage/100) * COALESCE(m.unit_cost, 0)) / h.base_quantity as cost_per_unit,
                    MAX(l.updated_at) as last_component_update
                FROM bom_headers h
                LEFT JOIN bom_lines l ON h.id = l.bom_id 
                    AND l.effective_date <= CURDATE() 
                    AND (l.expiry_date IS NULL OR l.expiry_date > CURDATE())
                LEFT JOIN materials m ON l.component_material_number = m.material_number
                WHERE h.status = 'active'
                GROUP BY h.id, h.bom_number, h.parent_material_number, h.description, h.base_quantity
                ORDER BY h.bom_number
            ";
            
            $this->pdo->exec($bomCostView);
            
            echo "✅ BOM reporting views created successfully\n";
            return true;
            
        } catch (Exception $e) {
            echo "❌ View creation failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Run the complete BOM module initialization
     */
    public function initialize() {
        echo "Starting BEMS Phase 2: BOM Module Initialization\n";
        echo str_repeat("=", 60) . "\n\n";
        
        // Step 1: Initialize database connection
        if (!$this->initializeConnection()) {
            echo "\n❌ BOM module initialization failed: Database connection error\n";
            return false;
        }
        
        // Step 2: Check if already initialized
        if ($this->isBomModuleInitialized()) {
            echo "⚠️  BOM module appears to already be initialized\n";
            echo "   Proceeding with verification checks...\n\n";
        }
        
        // Step 3: Execute BOM migration
        if (!$this->executeBomMigration()) {
            echo "\n❌ BOM module initialization failed: Migration error\n";
            return false;
        }
        
        // Step 4: Verify tables were created
        if (!$this->verifyBomTables()) {
            echo "\n❌ BOM module initialization failed: Table verification error\n";
            return false;
        }
        
        // Step 5: Verify sample data
        $this->verifySampleData();
        
        // Step 6: Create reporting views
        if (!$this->createBomViews()) {
            echo "\n⚠️  BOM module initialized but views creation failed\n";
        }
        
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "✅ BEMS Phase 2: BOM Module initialization completed successfully!\n\n";
        
        echo "Next steps:\n";
        echo "1. Test BOM API endpoints with test_bom_api.php\n";
        echo "2. Access BOM interface at /bems/bom.html\n";
        echo "3. Review BOM data using reporting views\n";
        echo "4. Begin Phase 3 (MRP) planning\n\n";
        
        return true;
    }
}

// Run the initialization if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    $initializer = new BomModuleInitializer();
    $success = $initializer->initialize();
    exit($success ? 0 : 1);
}
?>