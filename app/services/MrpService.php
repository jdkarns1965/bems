<?php
/**
 * BEMS MRP Service
 * Best ERP Manufacturing System
 * 
 * Business logic layer for Material Requirements Planning (MRP) including
 * requirements explosion, netting logic, planned order generation, and scheduling.
 */

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__) . '/services/BomService.php';

class MrpService {
    
    private $db;
    private $bomService;
    
    public function __construct() {
        $this->db = DatabaseConfig::getConnection();
        $this->bomService = new BomService();
    }
    
    /**
     * Execute full MRP run
     * 
     * @param array $parameters MRP run parameters
     * @param string $userId User executing the run
     * @return array Result with run statistics
     */
    public function executeMrpRun($parameters, $userId) {
        $runId = 'MRP_' . date('Ymd_His') . '_' . substr(uniqid(), -6);
        
        try {
            $this->db->beginTransaction();
            
            // Create planning run record
            $planningRun = $this->createPlanningRun($runId, $parameters, $userId);
            
            // Clear previous planning data if full regenerative run
            if ($parameters['run_type'] === 'FULL_REGENERATIVE') {
                $this->clearPreviousPlanningData($parameters['plan_version']);
            }
            
            // Step 1: Calculate low-level codes
            $this->calculateLowLevelCodes();
            
            // Step 2: Load Master Production Schedule demands
            $mpsItems = $this->loadMasterProductionSchedule($runId, $parameters);
            
            // Step 3: Process items by low-level code (bottom-up)
            $itemsProcessed = $this->processItemsByLowLevelCode($runId, $parameters);
            
            // Step 4: Generate planned orders from net requirements
            $plannedOrders = $this->generatePlannedOrders($runId, $parameters);
            
            // Step 5: Generate action messages
            $actionMessages = $this->generateActionMessages($runId, $parameters);
            
            // Update run statistics
            $this->updatePlanningRunStatistics($runId, [
                'items_planned' => $itemsProcessed,
                'planned_orders_created' => $plannedOrders['created'],
                'planned_orders_cancelled' => $plannedOrders['cancelled'],
                'action_messages_generated' => $actionMessages,
                'run_status' => 'COMPLETED'
            ]);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'planning_run_id' => $runId,
                'statistics' => [
                    'mps_items_loaded' => count($mpsItems),
                    'items_processed' => $itemsProcessed,
                    'planned_orders_created' => $plannedOrders['created'],
                    'planned_orders_cancelled' => $plannedOrders['cancelled'],
                    'action_messages' => $actionMessages
                ]
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            
            // Mark run as failed
            $this->updatePlanningRunStatistics($runId, [
                'run_status' => 'FAILED',
                'error_message' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'planning_run_id' => $runId,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create planning run record
     */
    private function createPlanningRun($runId, $parameters, $userId) {
        $sql = "INSERT INTO mrp_planning_runs (
            planning_run_id, plan_version, planning_horizon_days,
            planning_start_date, planning_end_date, run_type,
            planning_parameters, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $runId,
            $parameters['plan_version'] ?? 'ACTIVE',
            $parameters['planning_horizon_days'] ?? 365,
            $parameters['planning_start_date'] ?? date('Y-m-d'),
            $parameters['planning_end_date'] ?? date('Y-m-d', strtotime('+365 days')),
            $parameters['run_type'] ?? 'FULL_REGENERATIVE',
            json_encode($parameters),
            $userId
        ]);
        
        return $runId;
    }
    
    /**
     * Clear previous planning data
     */
    private function clearPreviousPlanningData($planVersion) {
        // Clear old requirements
        $sql = "DELETE FROM mrp_requirements WHERE plan_version = ?";
        $this->db->prepare($sql)->execute([$planVersion]);
        
        // Clear old planned orders
        $sql = "DELETE FROM planned_orders WHERE plan_version = ? AND firm_flag = FALSE";
        $this->db->prepare($sql)->execute([$planVersion]);
    }
    
    /**
     * Calculate low-level codes for all items
     */
    private function calculateLowLevelCodes() {
        // Reset all low-level codes to 0
        $sql = "UPDATE mrp_planning_parameters SET low_level_code = 0";
        $this->db->exec($sql);
        
        // Calculate low-level codes based on BOM structure
        $maxLevels = 20; // Prevent infinite loops
        
        for ($level = 0; $level < $maxLevels; $level++) {
            $sql = "UPDATE mrp_planning_parameters pp
                SET low_level_code = ?
                WHERE pp.item_number IN (
                    SELECT DISTINCT bl.component_material_number
                    FROM bom_headers bh
                    JOIN bom_lines bl ON bh.id = bl.bom_id
                    JOIN mrp_planning_parameters pp2 ON bh.parent_material_number = pp2.item_number
                    WHERE pp2.low_level_code = ?
                      AND bh.status = 'active'
                      AND bl.effective_date <= CURDATE()
                      AND (bl.expiry_date IS NULL OR bl.expiry_date > CURDATE())
                )
                AND pp.low_level_code < ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$level + 1, $level, $level + 1]);
            
            if ($stmt->rowCount() === 0) {
                break; // No more changes
            }
        }
    }
    
    /**
     * Load Master Production Schedule demands
     */
    private function loadMasterProductionSchedule($runId, $parameters) {
        $planVersion = $parameters['plan_version'] ?? 'ACTIVE';
        $planningStartDate = $parameters['planning_start_date'] ?? date('Y-m-d');
        $planningEndDate = $parameters['planning_end_date'] ?? date('Y-m-d', strtotime('+365 days'));
        
        $sql = "SELECT * FROM master_production_schedule 
                WHERE plan_version = ?
                  AND demand_date BETWEEN ? AND ?
                  AND planned_quantity > 0
                ORDER BY demand_date";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$planVersion, $planningStartDate, $planningEndDate]);
        $mpsItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create gross requirements from MPS
        foreach ($mpsItems as $mps) {
            $this->createMrpRequirement($runId, [
                'item_number' => $mps['item_number'],
                'plan_version' => $planVersion,
                'requirement_date' => $mps['demand_date'],
                'requirement_type' => 'GROSS',
                'source_type' => 'MPS',
                'source_reference' => 'MPS_ID_' . $mps['id'],
                'required_quantity' => $mps['planned_quantity'],
                'bom_level' => 0
            ]);
        }
        
        return $mpsItems;
    }
    
    /**
     * Process items by low-level code (bottom-up processing)
     */
    private function processItemsByLowLevelCode($runId, $parameters) {
        $planVersion = $parameters['plan_version'] ?? 'ACTIVE';
        $itemsProcessed = 0;
        
        // Get maximum low-level code
        $sql = "SELECT MAX(low_level_code) as max_level FROM mrp_planning_parameters WHERE active_flag = TRUE";
        $maxLevel = $this->db->query($sql)->fetchColumn() ?: 0;
        
        // Process items from highest level down to 0 (bottom-up)
        for ($level = $maxLevel; $level >= 0; $level--) {
            $sql = "SELECT pp.*, m.material_name
                    FROM mrp_planning_parameters pp
                    JOIN materials m ON pp.item_number = m.material_number
                    WHERE pp.low_level_code = ?
                      AND pp.active_flag = TRUE
                      AND pp.planning_method IN ('MRP', 'REORDER_POINT')
                    ORDER BY pp.item_number";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$level]);
            $levelItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($levelItems as $item) {
                $this->processItemMrp($runId, $item, $parameters);
                $itemsProcessed++;
            }
        }
        
        return $itemsProcessed;
    }
    
    /**
     * Process MRP logic for a single item
     */
    private function processItemMrp($runId, $item, $parameters) {
        $itemNumber = $item['item_number'];
        $planVersion = $parameters['plan_version'] ?? 'ACTIVE';
        
        // Get all gross requirements for this item
        $grossRequirements = $this->getGrossRequirements($runId, $itemNumber, $planVersion);
        
        // Get current inventory and scheduled receipts
        $availableQuantity = $this->getAvailableQuantity($itemNumber);
        
        // Process requirements by date
        $runningBalance = $availableQuantity + $item['safety_stock_quantity'];
        
        foreach ($grossRequirements as $req) {
            $netRequirement = max(0, $req['required_quantity'] - $runningBalance);
            
            if ($netRequirement > 0) {
                // Apply lot sizing
                $orderQuantity = $this->calculateOrderQuantity($netRequirement, $item);
                
                // Create net requirement record
                $this->createMrpRequirement($runId, [
                    'item_number' => $itemNumber,
                    'plan_version' => $planVersion,
                    'requirement_date' => $req['requirement_date'],
                    'requirement_type' => 'NET',
                    'source_type' => $req['source_type'],
                    'source_reference' => $req['source_reference'],
                    'required_quantity' => $req['required_quantity'],
                    'available_quantity' => $runningBalance,
                    'net_requirement' => $netRequirement,
                    'lot_size_quantity' => $orderQuantity,
                    'planning_lead_time_days' => $item['planning_lead_time_days'],
                    'order_release_date' => date('Y-m-d', strtotime($req['requirement_date'] . ' -' . $item['planning_lead_time_days'] . ' days')),
                    'bom_level' => $item['low_level_code'],
                    'action_required' => 'CREATE_ORDER'
                ]);
                
                // Explode dependent demand if this is a manufactured item
                if ($item['make_buy_code'] === 'MAKE') {
                    $this->explodeDependentDemand($runId, $itemNumber, $orderQuantity, $req['requirement_date'], $parameters);
                }
                
                $runningBalance += $orderQuantity;
            } else {
                // Create record showing no action needed
                $this->createMrpRequirement($runId, [
                    'item_number' => $itemNumber,
                    'plan_version' => $planVersion,
                    'requirement_date' => $req['requirement_date'],
                    'requirement_type' => 'GROSS',
                    'source_type' => $req['source_type'],
                    'source_reference' => $req['source_reference'],
                    'required_quantity' => $req['required_quantity'],
                    'available_quantity' => $runningBalance,
                    'net_requirement' => 0,
                    'planning_lead_time_days' => $item['planning_lead_time_days'],
                    'bom_level' => $item['low_level_code'],
                    'action_required' => 'NONE'
                ]);
            }
            
            $runningBalance -= $req['required_quantity'];
        }
    }
    
    /**
     * Get gross requirements for an item
     */
    private function getGrossRequirements($runId, $itemNumber, $planVersion) {
        $sql = "SELECT * FROM mrp_requirements 
                WHERE planning_run_id = ? 
                  AND item_number = ?
                  AND plan_version = ?
                  AND requirement_type = 'GROSS'
                ORDER BY requirement_date";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$runId, $itemNumber, $planVersion]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get available quantity (on-hand + scheduled receipts)
     */
    private function getAvailableQuantity($itemNumber) {
        // Get current inventory from Phase 1 system
        $sql = "SELECT COALESCE(SUM(quantity), 0) as on_hand
                FROM inventory 
                WHERE material_number = ? AND quantity > 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$itemNumber]);
        $onHand = $stmt->fetchColumn() ?: 0;
        
        // Add scheduled receipts (open purchase orders, production orders, etc.)
        // For now, we'll just return on-hand quantity
        // In a full system, this would include open orders
        
        return $onHand;
    }
    
    /**
     * Calculate order quantity based on lot sizing rules
     */
    private function calculateOrderQuantity($netRequirement, $item) {
        $lotSizingRule = $item['lot_sizing_rule'];
        $quantity = $netRequirement;
        
        switch ($lotSizingRule) {
            case 'FIXED_LOT':
                $quantity = max($netRequirement, $item['fixed_lot_size'] ?: $netRequirement);
                break;
                
            case 'ECONOMIC_ORDER_QTY':
                // Simplified EOQ calculation - in practice would use demand rate, holding cost, etc.
                $eoq = $item['fixed_lot_size'] ?: sqrt(2 * $netRequirement * 100 / 10); // Simplified
                $quantity = max($netRequirement, $eoq);
                break;
                
            case 'PERIOD_ORDER_QTY':
                // Would look ahead to cover multiple periods - simplified for now
                $quantity = $netRequirement * 2; // Cover 2 periods
                break;
                
            case 'LOT_FOR_LOT':
            default:
                $quantity = $netRequirement;
                break;
        }
        
        // Apply minimum order quantity
        if ($item['minimum_order_quantity'] > 0) {
            $quantity = max($quantity, $item['minimum_order_quantity']);
        }
        
        // Apply maximum order quantity
        if ($item['maximum_order_quantity'] > 0) {
            $quantity = min($quantity, $item['maximum_order_quantity']);
        }
        
        // Apply order multiple
        if ($item['order_multiple'] > 1) {
            $quantity = ceil($quantity / $item['order_multiple']) * $item['order_multiple'];
        }
        
        return $quantity;
    }
    
    /**
     * Explode dependent demand from parent to components
     */
    private function explodeDependentDemand($runId, $parentItem, $parentQuantity, $dueDate, $parameters) {
        $planVersion = $parameters['plan_version'] ?? 'ACTIVE';
        
        // Get BOM explosion for this parent item
        $explosion = $this->bomService->explodeBom($parentItem, $parentQuantity, 1); // 1 level only
        
        foreach ($explosion as $component) {
            if ($component['level'] === 1) { // Direct children only
                $this->createMrpRequirement($runId, [
                    'item_number' => $component['component_material_number'],
                    'plan_version' => $planVersion,
                    'requirement_date' => $dueDate,
                    'requirement_type' => 'GROSS',
                    'source_type' => 'DEPENDENT',
                    'source_reference' => 'PARENT_' . $parentItem,
                    'required_quantity' => $component['net_quantity'],
                    'parent_item' => $parentItem,
                    'bom_level' => $component['level']
                ]);
            }
        }
    }
    
    /**
     * Generate planned orders from net requirements
     */
    private function generatePlannedOrders($runId, $parameters) {
        $planVersion = $parameters['plan_version'] ?? 'ACTIVE';
        $created = 0;
        $cancelled = 0;
        
        // Get net requirements that need planned orders
        $sql = "SELECT mr.*, pp.make_buy_code, pp.lot_sizing_rule
                FROM mrp_requirements mr
                JOIN mrp_planning_parameters pp ON mr.item_number = pp.item_number
                WHERE mr.planning_run_id = ?
                  AND mr.plan_version = ?
                  AND mr.requirement_type = 'NET'
                  AND mr.net_requirement > 0
                  AND mr.action_required = 'CREATE_ORDER'
                ORDER BY mr.requirement_date, mr.item_number";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$runId, $planVersion]);
        $netRequirements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($netRequirements as $req) {
            $plannedOrderNumber = 'PO_' . $runId . '_' . substr(uniqid(), -6);
            
            $orderType = ($req['make_buy_code'] === 'MAKE') ? 'PRODUCTION' : 'PURCHASE';
            
            $sql = "INSERT INTO planned_orders (
                planning_run_id, planned_order_number, item_number, plan_version,
                order_type, planned_quantity, unit_of_measure, due_date, start_date,
                planning_lead_time_days, lot_sizing_rule, source_requirement_id,
                priority_code, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $runId,
                $plannedOrderNumber,
                $req['item_number'],
                $planVersion,
                $orderType,
                $req['lot_size_quantity'],
                'EA', // Default unit - should come from materials table
                $req['requirement_date'],
                $req['order_release_date'],
                $req['planning_lead_time_days'],
                $req['lot_sizing_rule'],
                $req['id'],
                'NORMAL',
                'MRP_SYSTEM'
            ]);
            
            $created++;
        }
        
        return ['created' => $created, 'cancelled' => $cancelled];
    }
    
    /**
     * Generate action messages for planners
     */
    private function generateActionMessages($runId, $parameters) {
        $messagesGenerated = 0;
        
        // Update requirements with action messages
        $sql = "UPDATE mrp_requirements 
                SET action_message = CASE
                    WHEN action_required = 'CREATE_ORDER' THEN 
                        CONCAT('Create ', 
                            CASE WHEN (SELECT make_buy_code FROM mrp_planning_parameters WHERE item_number = mrp_requirements.item_number) = 'MAKE' 
                                 THEN 'production order' 
                                 ELSE 'purchase order' 
                            END,
                            ' for ', FORMAT(lot_size_quantity, 2), ' units by ', DATE_FORMAT(order_release_date, '%Y-%m-%d'))
                    WHEN action_required = 'EXPEDITE' THEN 
                        CONCAT('Expedite order - need ', FORMAT(net_requirement, 2), ' units by ', DATE_FORMAT(requirement_date, '%Y-%m-%d'))
                    WHEN action_required = 'RESCHEDULE' THEN
                        'Reschedule existing order to align with new requirement date'
                    WHEN action_required = 'CANCEL' THEN
                        'Cancel unnecessary order - no longer needed'
                    ELSE NULL
                END
                WHERE planning_run_id = ?
                  AND action_required != 'NONE'";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$runId]);
        $messagesGenerated = $stmt->rowCount();
        
        return $messagesGenerated;
    }
    
    /**
     * Create MRP requirement record
     */
    private function createMrpRequirement($runId, $data) {
        $sql = "INSERT INTO mrp_requirements (
            planning_run_id, item_number, plan_version, requirement_date,
            requirement_type, source_type, source_reference, required_quantity,
            available_quantity, net_requirement, lot_size_quantity,
            planning_lead_time_days, order_release_date, parent_item,
            bom_level, action_required, action_message
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $runId,
            $data['item_number'],
            $data['plan_version'] ?? 'ACTIVE',
            $data['requirement_date'],
            $data['requirement_type'],
            $data['source_type'],
            $data['source_reference'] ?? null,
            $data['required_quantity'],
            $data['available_quantity'] ?? 0,
            $data['net_requirement'] ?? 0,
            $data['lot_size_quantity'] ?? null,
            $data['planning_lead_time_days'] ?? 0,
            $data['order_release_date'] ?? null,
            $data['parent_item'] ?? null,
            $data['bom_level'] ?? 0,
            $data['action_required'] ?? 'NONE',
            $data['action_message'] ?? null
        ]);
    }
    
    /**
     * Update planning run statistics
     */
    private function updatePlanningRunStatistics($runId, $statistics) {
        $updateFields = [];
        $params = [];
        
        foreach ($statistics as $field => $value) {
            $updateFields[] = "$field = ?";
            $params[] = $value;
        }
        
        if ($statistics['run_status'] === 'COMPLETED' || $statistics['run_status'] === 'FAILED') {
            $updateFields[] = "run_end_time = CURRENT_TIMESTAMP";
            $updateFields[] = "run_duration_seconds = TIMESTAMPDIFF(SECOND, run_start_time, CURRENT_TIMESTAMP)";
        }
        
        $params[] = $runId;
        
        $sql = "UPDATE mrp_planning_runs SET " . implode(', ', $updateFields) . " WHERE planning_run_id = ?";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Get MRP requirements summary
     */
    public function getMrpRequirementsSummary($filters = []) {
        $sql = "SELECT * FROM vw_mrp_requirements_summary WHERE 1=1";
        $params = [];
        
        if (!empty($filters['planning_run_id'])) {
            $sql .= " AND planning_run_id = ?";
            $params[] = $filters['planning_run_id'];
        }
        
        if (!empty($filters['item_number'])) {
            $sql .= " AND item_number LIKE ?";
            $params[] = '%' . $filters['item_number'] . '%';
        }
        
        if (!empty($filters['action_required'])) {
            $sql .= " AND action_required = ?";
            $params[] = $filters['action_required'];
        }
        
        $sql .= " ORDER BY requirement_date, bom_level, item_number";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get planned orders summary
     */
    public function getPlannedOrdersSummary($filters = []) {
        $sql = "SELECT * FROM vw_planned_orders_summary WHERE 1=1";
        $params = [];
        
        if (!empty($filters['item_number'])) {
            $sql .= " AND item_number LIKE ?";
            $params[] = '%' . $filters['item_number'] . '%';
        }
        
        if (!empty($filters['order_type'])) {
            $sql .= " AND order_type = ?";
            $params[] = $filters['order_type'];
        }
        
        if (!empty($filters['days_ahead'])) {
            $sql .= " AND days_until_due <= ?";
            $params[] = (int)$filters['days_ahead'];
        }
        
        $sql .= " ORDER BY due_date, priority_code DESC, item_number";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get MRP action messages
     */
    public function getMrpActionMessages($filters = []) {
        $sql = "SELECT * FROM vw_mrp_action_messages WHERE 1=1";
        $params = [];
        
        if (!empty($filters['planning_run_id'])) {
            $sql .= " AND planning_run_id = ?";
            $params[] = $filters['planning_run_id'];
        }
        
        if (!empty($filters['action_required'])) {
            $sql .= " AND action_required = ?";
            $params[] = $filters['action_required'];
        }
        
        $sql .= " ORDER BY action_required, requirement_date, item_number";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get latest planning run
     */
    public function getLatestPlanningRun($planVersion = 'ACTIVE') {
        $sql = "SELECT * FROM mrp_planning_runs 
                WHERE plan_version = ? 
                  AND run_status = 'COMPLETED'
                ORDER BY run_start_time DESC 
                LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$planVersion]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>