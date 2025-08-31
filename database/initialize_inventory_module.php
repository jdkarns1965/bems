<?php
/**
 * BEMS Phase 1 Inventory Module Initialization Script
 * 
 * This script helps initialize the inventory module after migration
 * by setting up proper sequences, validating data, and creating
 * additional sample records if needed.
 */

// Use centralized database configuration
require_once dirname(__DIR__) . '/config/database.php';

try {
    // Create database connection using centralized config
    $pdo = DatabaseConfig::getConnection();

    echo "=== BEMS Phase 1 Inventory Module Initialization ===\n\n";

    // Step 1: Verify Phase 0 is installed
    echo "Step 1: Verifying Phase 0 authentication system...\n";
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->query("SELECT COUNT(*) as user_count FROM users WHERE active_status = 1");
        $userCount = $stmt->fetch()['user_count'];
        echo "  ✓ Phase 0 installed with $userCount active users\n";
    } else {
        echo "  ✗ Phase 0 not found - please install authentication system first\n";
        exit(1);
    }

    // Step 2: Verify Phase 1 tables
    echo "\nStep 2: Verifying Phase 1 inventory tables...\n";
    $inventoryTables = [
        'inv_tag_sequences',
        'material_categories',
        'materials', 
        'locations',
        'inventory',
        'inventory_movements',
        'inventory_reservations',
        'inventory_adjustments'
    ];

    $missingTables = [];
    foreach ($inventoryTables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "  ✓ Table '$table' found\n";
        } else {
            echo "  ✗ Table '$table' missing\n";
            $missingTables[] = $table;
        }
    }

    if (!empty($missingTables)) {
        echo "\nError: Missing tables detected. Please run the Phase 1 migration first:\n";
        echo "mysql -u root -p BEMS < /var/www/html/bems/database/migrations/002_create_inventory_module_schema.sql\n";
        exit(1);
    }

    // Step 3: Initialize INV tag sequences
    echo "\nStep 3: Initializing INV tag sequences...\n";
    
    // Check if sequences need initialization
    $stmt = $pdo->query("SELECT COUNT(*) as seq_count FROM inv_tag_sequences WHERE active_status = 1");
    $seqCount = $stmt->fetch()['seq_count'];
    
    if ($seqCount >= 3) {
        echo "  ✓ INV tag sequences already initialized ($seqCount sequences)\n";
    } else {
        echo "  ! Reinitializing INV tag sequences...\n";
        
        // Clear and reinitialize sequences
        $pdo->exec("DELETE FROM inv_tag_sequences");
        
        $sequences = [
            ['INV_MAIN', 'INV', 10000, 'Main inventory tag sequence for all received materials'],
            ['INV_CONSUMABLE', 'CONS', 50000, 'Consumable materials tag sequence'],
            ['INV_SCRAP', 'SCRAP', 90000, 'Scrap and waste materials tag sequence']
        ];
        
        $stmt = $pdo->prepare("
            INSERT INTO inv_tag_sequences (sequence_name, prefix, current_number, min_number, max_number, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($sequences as $seq) {
            $stmt->execute([
                $seq[0], $seq[1], $seq[2], $seq[2] + 1, 
                $seq[2] + 49999, $seq[3]
            ]);
            echo "    - Initialized {$seq[0]} starting at {$seq[1]}" . str_pad($seq[2] + 1, 5, '0', STR_PAD_LEFT) . "\n";
        }
    }

    // Step 4: Validate material categories
    echo "\nStep 4: Validating material categories...\n";
    $stmt = $pdo->query("SELECT COUNT(*) as cat_count FROM material_categories WHERE active_status = 1");
    $catCount = $stmt->fetch()['cat_count'];
    
    if ($catCount >= 6) {
        echo "  ✓ Material categories initialized ($catCount categories)\n";
        
        // Show categories
        $stmt = $pdo->query("
            SELECT category_code, category_name 
            FROM material_categories 
            WHERE active_status = 1 
            ORDER BY sort_order
        ");
        $categories = $stmt->fetchAll();
        foreach ($categories as $cat) {
            echo "    - {$cat['category_code']}: {$cat['category_name']}\n";
        }
    } else {
        echo "  ✗ Insufficient material categories ($catCount found, expected 6+)\n";
    }

    // Step 5: Validate locations
    echo "\nStep 5: Validating location setup...\n";
    $stmt = $pdo->query("SELECT COUNT(*) as loc_count FROM locations WHERE active_status = 1");
    $locCount = $stmt->fetch()['loc_count'];
    
    if ($locCount >= 10) {
        echo "  ✓ Locations initialized ($locCount locations)\n";
        
        // Show location summary by type
        $stmt = $pdo->query("
            SELECT location_type, COUNT(*) as count
            FROM locations 
            WHERE active_status = 1 
            GROUP BY location_type
            ORDER BY location_type
        ");
        $locationTypes = $stmt->fetchAll();
        foreach ($locationTypes as $type) {
            echo "    - {$type['location_type']}: {$type['count']} locations\n";
        }
    } else {
        echo "  ✗ Insufficient locations ($locCount found, expected 10+)\n";
    }

    // Step 6: Test INV tag generation
    echo "\nStep 6: Testing INV tag generation...\n";
    try {
        // Test main sequence
        $stmt = $pdo->query("SELECT fn_get_next_inv_tag('INV_MAIN') as test_tag");
        $testTag = $stmt->fetch()['test_tag'];
        echo "  ✓ INV tag generation working - next tag: $testTag\n";
        
        // Test consumable sequence
        $stmt = $pdo->query("SELECT fn_get_next_inv_tag('INV_CONSUMABLE') as test_tag");
        $testTag2 = $stmt->fetch()['test_tag'];
        echo "  ✓ Consumable tag generation working - next tag: $testTag2\n";
        
    } catch (Exception $e) {
        echo "  ✗ INV tag generation failed: " . $e->getMessage() . "\n";
    }

    // Step 7: Test FIFO priority calculation
    echo "\nStep 7: Testing FIFO priority system...\n";
    try {
        $stmt = $pdo->query("
            SELECT fn_calculate_fifo_priority(CURDATE(), DATE_ADD(CURDATE(), INTERVAL 365 DAY)) as priority1,
                   fn_calculate_fifo_priority(DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_ADD(CURDATE(), INTERVAL 30 DAY)) as priority2
        ");
        $priorities = $stmt->fetch();
        
        if ($priorities['priority1'] > $priorities['priority2']) {
            echo "  ✓ FIFO priority system working correctly\n";
            echo "    - Today's material priority: {$priorities['priority1']}\n";
            echo "    - 30-day-old material priority: {$priorities['priority2']} (higher priority)\n";
        } else {
            echo "  ✗ FIFO priority system not working correctly\n";
        }
        
    } catch (Exception $e) {
        echo "  ✗ FIFO priority test failed: " . $e->getMessage() . "\n";
    }

    // Step 8: Validate sample materials
    echo "\nStep 8: Validating sample materials...\n";
    $stmt = $pdo->query("SELECT COUNT(*) as mat_count FROM materials WHERE active_status = 1");
    $matCount = $stmt->fetch()['mat_count'];
    
    if ($matCount >= 5) {
        echo "  ✓ Sample materials loaded ($matCount materials)\n";
        
        // Show material summary
        $stmt = $pdo->query("
            SELECT m.material_number, m.material_name, mc.category_code
            FROM materials m
            JOIN material_categories mc ON m.category_id = mc.id
            WHERE m.active_status = 1
            ORDER BY mc.category_code, m.material_number
        ");
        $materials = $stmt->fetchAll();
        foreach ($materials as $mat) {
            echo "    - {$mat['category_code']}: {$mat['material_number']} - {$mat['material_name']}\n";
        }
    } else {
        echo "  ! Limited sample materials ($matCount found)\n";
    }

    // Step 9: Check sample inventory
    echo "\nStep 9: Checking sample inventory data...\n";
    $stmt = $pdo->query("SELECT COUNT(*) as inv_count FROM inventory WHERE quantity > 0");
    $invCount = $stmt->fetch()['inv_count'];
    
    if ($invCount > 0) {
        echo "  ✓ Sample inventory loaded ($invCount lots)\n";
        
        // Show inventory summary
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as lot_count,
                SUM(quantity) as total_qty,
                ROUND(SUM(total_cost), 2) as total_value,
                COUNT(DISTINCT material_id) as unique_materials
            FROM inventory 
            WHERE quantity > 0
        ");
        $summary = $stmt->fetch();
        echo "    - Total lots: {$summary['lot_count']}\n";
        echo "    - Total quantity: {$summary['total_qty']} units\n";
        echo "    - Total value: $" . number_format($summary['total_value'], 2) . "\n";
        echo "    - Unique materials: {$summary['unique_materials']}\n";
    } else {
        echo "  ! No sample inventory found - consider running test data creation\n";
    }

    // Step 10: Test stored procedures
    echo "\nStep 10: Testing core stored procedures...\n";
    
    // Test receive procedure with a small test lot
    try {
        $stmt = $pdo->prepare("CALL sp_receive_inventory(?, ?, ?, ?, ?, ?, ?, ?, ?, @result, @inv_tag)");
        $stmt->execute([
            'PP-1001-NAT',          // existing material
            'INIT-TEST-' . date('Ymd'), // unique lot number
            100.00,                 // small quantity
            0.85,                   // unit cost
            'RCV-01',              // receiving location
            'Test Supplier',        // supplier
            'INIT-TEST-PO',        // purchase order
            'ADMIN001',            // received by admin
            'Initialization test lot'  // notes
        ]);
        
        $stmt = $pdo->query("SELECT @result as result, @inv_tag as inv_tag");
        $result = $stmt->fetch();
        
        if ($result['result'] === 'SUCCESS') {
            echo "  ✓ Material receiving procedure working - created: {$result['inv_tag']}\n";
        } else {
            echo "  ✗ Material receiving failed: {$result['result']}\n";
        }
        
    } catch (Exception $e) {
        echo "  ✗ Stored procedure test failed: " . $e->getMessage() . "\n";
    }

    // Step 11: Validate database views
    echo "\nStep 11: Validating database views...\n";
    $views = [
        'v_inventory_summary',
        'v_fifo_available_inventory', 
        'v_inventory_totals_by_location',
        'v_recent_inventory_movements',
        'v_expiring_inventory'
    ];
    
    foreach ($views as $view) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM $view LIMIT 1");
            $count = $stmt->fetch()['count'];
            echo "  ✓ View '$view' functional (records: $count)\n";
        } catch (Exception $e) {
            echo "  ✗ View '$view' failed: " . $e->getMessage() . "\n";
        }
    }

    // Step 12: Check event scheduler
    echo "\nStep 12: Checking automated maintenance...\n";
    try {
        $stmt = $pdo->query("SELECT @@event_scheduler as scheduler_status");
        $schedulerStatus = $stmt->fetch()['scheduler_status'];
        
        if ($schedulerStatus === 'ON') {
            echo "  ✓ Event scheduler enabled\n";
            
            // Check inventory events
            $stmt = $pdo->query("
                SELECT EVENT_NAME, STATUS 
                FROM INFORMATION_SCHEMA.EVENTS 
                WHERE EVENT_SCHEMA = 'BEMS' 
                AND EVENT_NAME LIKE '%inventory%' OR EVENT_NAME LIKE '%expir%'
            ");
            $events = $stmt->fetchAll();
            
            if (count($events) > 0) {
                echo "  ✓ Inventory maintenance events configured:\n";
                foreach ($events as $event) {
                    echo "    - {$event['EVENT_NAME']}: {$event['STATUS']}\n";
                }
            } else {
                echo "  ! No inventory-specific events found\n";
            }
        } else {
            echo "  ! Event scheduler disabled - automated maintenance won't run\n";
            echo "    Run: SET GLOBAL event_scheduler = ON; to enable\n";
        }
        
    } catch (Exception $e) {
        echo "  ✗ Event scheduler check failed: " . $e->getMessage() . "\n";
    }

    // Step 13: Final system health check
    echo "\nStep 13: Final system health check...\n";
    
    // Check for any obvious data issues
    $healthChecks = [
        "SELECT COUNT(*) as count FROM inventory WHERE quantity < 0" => "Negative quantities",
        "SELECT COUNT(*) as count FROM inventory WHERE inv_tag IS NULL OR inv_tag = ''" => "Missing INV tags",
        "SELECT COUNT(*) as count FROM materials WHERE material_number IS NULL OR material_number = ''" => "Missing material numbers",
        "SELECT COUNT(*) as count FROM locations WHERE location_code IS NULL OR location_code = ''" => "Missing location codes"
    ];
    
    $healthIssues = 0;
    foreach ($healthChecks as $query => $description) {
        $stmt = $pdo->query($query);
        $count = $stmt->fetch()['count'];
        
        if ($count > 0) {
            echo "  ✗ $description: $count issues found\n";
            $healthIssues++;
        } else {
            echo "  ✓ $description: OK\n";
        }
    }
    
    if ($healthIssues === 0) {
        echo "  ✓ All health checks passed\n";
    } else {
        echo "  ! $healthIssues health issues detected - review data integrity\n";
    }

    // Completion summary
    echo "\n=== INITIALIZATION COMPLETE ===\n";
    echo "✅ BEMS Phase 1 Inventory Module is ready for use!\n\n";
    
    echo "🎯 Key Features Available:\n";
    echo "   • Unique INV tag generation (starting at INV10001)\n";
    echo "   • FIFO material consumption logic\n"; 
    echo "   • Complete movement audit trail\n";
    echo "   • Flexible location management\n";
    echo "   • Material expiration tracking\n";
    echo "   • Reservation and adjustment workflows\n\n";
    
    echo "📋 Next Steps:\n";
    echo "   1. Configure user permissions for inventory roles\n";
    echo "   2. Set up web interface for daily operations\n";
    echo "   3. Train users on material receiving and movement procedures\n";
    echo "   4. Implement barcode scanning for location tracking\n";
    echo "   5. Create custom reports for inventory management\n\n";
    
    echo "📖 Documentation:\n";
    echo "   • Phase 1 Schema Guide: /var/www/html/bems/database/README_Phase1_Inventory.md\n";
    echo "   • Test Script: /var/www/html/bems/database/test_inventory_system.php\n";
    echo "   • Migration File: /var/www/html/bems/database/migrations/002_create_inventory_module_schema.sql\n\n";
    
    echo "🔧 Support:\n";
    echo "   • Run test script to validate all functionality\n";
    echo "   • Check audit_logs table for system activity\n";
    echo "   • Monitor v_expiring_inventory for material alerts\n";
    
} catch (Exception $e) {
    echo "Initialization failed: " . $e->getMessage() . "\n";
    echo "\nTroubleshooting:\n";
    echo "1. Verify database connection settings\n";
    echo "2. Ensure BEMS database exists\n";
    echo "3. Run Phase 0 migration first if not completed\n";
    echo "4. Run Phase 1 migration: 002_create_inventory_module_schema.sql\n";
    echo "5. Check MySQL user permissions\n\n";
    
    exit(1);
}
?>