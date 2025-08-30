<?php
/**
 * BEMS Inventory Audit Service
 * Best ERP Manufacturing System
 * 
 * Specialized audit logging service for inventory operations with enhanced
 * tracking, cost impact analysis, and compliance reporting.
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';

class InventoryAuditService {
    
    private $db;
    
    public function __construct() {
        $this->db = DatabaseConfig::getConnection();
    }
    
    /**
     * Log inventory receiving action
     * 
     * @param string $invTag INV tag generated
     * @param array $details Receiving details
     * @param string $clockNumber User clock number
     * @return bool Success status
     */
    public function logInventoryReceive($invTag, $details, $clockNumber) {
        try {
            $auditData = [
                'inv_tag' => $invTag,
                'material_number' => $details['material_number'],
                'lot_number' => $details['lot_number'],
                'quantity_received' => floatval($details['quantity']),
                'unit_cost' => floatval($details['unit_cost']),
                'total_value' => floatval($details['quantity']) * floatval($details['unit_cost']),
                'location_code' => $details['location_code'],
                'supplier_name' => $details['supplier_name'] ?? null,
                'purchase_order' => $details['purchase_order'] ?? null,
                'receiving_notes' => $details['notes'] ?? null
            ];
            
            return $this->createAuditEntry(
                $clockNumber,
                'INVENTORY_RECEIVE',
                'inventory',
                $invTag,
                $auditData,
                'Material received into inventory'
            );
            
        } catch (Exception $e) {
            error_log('Inventory receive audit error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log inventory movement/transfer action
     * 
     * @param string $invTag INV tag moved
     * @param array $details Movement details
     * @param string $clockNumber User clock number
     * @return bool Success status
     */
    public function logInventoryMovement($invTag, $details, $clockNumber) {
        try {
            $auditData = [
                'inv_tag' => $invTag,
                'movement_type' => 'TRANSFER',
                'quantity_moved' => floatval($details['quantity']),
                'from_location_code' => $details['from_location_code'] ?? null,
                'from_location_manual' => $details['from_location_manual'] ?? null,
                'to_location_code' => $details['to_location_code'] ?? null,
                'to_location_manual' => $details['to_location_manual'] ?? null,
                'reference_number' => $details['reference_number'] ?? null,
                'movement_reason' => $details['reason'] ?? null,
                'remaining_quantity' => isset($details['remaining_quantity']) ? floatval($details['remaining_quantity']) : null
            ];
            
            $description = sprintf(
                'Moved %.2f units from %s to %s',
                $auditData['quantity_moved'],
                $auditData['from_location_code'] ?? $auditData['from_location_manual'] ?? 'Unknown',
                $auditData['to_location_code'] ?? $auditData['to_location_manual'] ?? 'Unknown'
            );
            
            return $this->createAuditEntry(
                $clockNumber,
                'INVENTORY_TRANSFER',
                'inventory',
                $invTag,
                $auditData,
                $description
            );
            
        } catch (Exception $e) {
            error_log('Inventory movement audit error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log inventory consumption action
     * 
     * @param string $invTag INV tag consumed
     * @param array $details Consumption details
     * @param string $clockNumber User clock number
     * @return bool Success status
     */
    public function logInventoryConsumption($invTag, $details, $clockNumber) {
        try {
            $auditData = [
                'inv_tag' => $invTag,
                'movement_type' => 'CONSUME',
                'quantity_consumed' => floatval($details['quantity']),
                'work_order' => $details['work_order'] ?? null,
                'job_number' => $details['job_number'] ?? null,
                'machine_press' => $details['machine_press'] ?? null,
                'operator_initials' => $details['operator_initials'] ?? null,
                'remaining_quantity' => isset($details['remaining_quantity']) ? floatval($details['remaining_quantity']) : null,
                'lot_depleted' => $details['lot_depleted'] ?? false,
                'consumption_cost' => isset($details['unit_cost']) ? 
                    floatval($details['quantity']) * floatval($details['unit_cost']) : null
            ];
            
            $description = sprintf(
                'Consumed %.2f units for %s%s%s',
                $auditData['quantity_consumed'],
                $auditData['work_order'] ? "WO: {$auditData['work_order']}" : 'production',
                $auditData['job_number'] ? " / Job: {$auditData['job_number']}" : '',
                $auditData['machine_press'] ? " on {$auditData['machine_press']}" : ''
            );
            
            return $this->createAuditEntry(
                $clockNumber,
                'INVENTORY_CONSUME',
                'inventory',
                $invTag,
                $auditData,
                $description
            );
            
        } catch (Exception $e) {
            error_log('Inventory consumption audit error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log material master data changes
     * 
     * @param string $materialNumber Material number
     * @param string $action Action performed (CREATE, UPDATE)
     * @param array $details Change details
     * @param string $clockNumber User clock number
     * @return bool Success status
     */
    public function logMaterialChange($materialNumber, $action, $details, $clockNumber) {
        try {
            $auditData = [
                'material_number' => $materialNumber,
                'action_type' => $action,
                'changed_fields' => $details['changed_fields'] ?? [],
                'old_values' => $details['old_values'] ?? [],
                'new_values' => $details['new_values'] ?? [],
                'material_name' => $details['material_name'] ?? null,
                'category_code' => $details['category_code'] ?? null
            ];
            
            $description = $action === 'CREATE' ? 
                "Created material: {$materialNumber}" : 
                "Updated material: {$materialNumber}";
            
            if (!empty($details['changed_fields'])) {
                $description .= " (Fields: " . implode(', ', $details['changed_fields']) . ")";
            }
            
            return $this->createAuditEntry(
                $clockNumber,
                'MATERIAL_' . $action,
                'materials',
                $materialNumber,
                $auditData,
                $description
            );
            
        } catch (Exception $e) {
            error_log('Material change audit error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log location master data changes
     * 
     * @param string $locationCode Location code
     * @param string $action Action performed (CREATE, UPDATE)
     * @param array $details Change details
     * @param string $clockNumber User clock number
     * @return bool Success status
     */
    public function logLocationChange($locationCode, $action, $details, $clockNumber) {
        try {
            $auditData = [
                'location_code' => $locationCode,
                'action_type' => $action,
                'changed_fields' => $details['changed_fields'] ?? [],
                'old_values' => $details['old_values'] ?? [],
                'new_values' => $details['new_values'] ?? [],
                'location_name' => $details['location_name'] ?? null,
                'location_type' => $details['location_type'] ?? null
            ];
            
            $description = $action === 'CREATE' ? 
                "Created location: {$locationCode}" : 
                "Updated location: {$locationCode}";
            
            if (!empty($details['changed_fields'])) {
                $description .= " (Fields: " . implode(', ', $details['changed_fields']) . ")";
            }
            
            return $this->createAuditEntry(
                $clockNumber,
                'LOCATION_' . $action,
                'locations',
                $locationCode,
                $auditData,
                $description
            );
            
        } catch (Exception $e) {
            error_log('Location change audit error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log inventory adjustment/cycle count
     * 
     * @param string $invTag INV tag adjusted
     * @param array $details Adjustment details
     * @param string $clockNumber User clock number
     * @return bool Success status
     */
    public function logInventoryAdjustment($invTag, $details, $clockNumber) {
        try {
            $auditData = [
                'inv_tag' => $invTag,
                'adjustment_type' => $details['adjustment_type'] ?? 'ADJUSTMENT',
                'quantity_before' => floatval($details['quantity_before']),
                'quantity_after' => floatval($details['quantity_after']),
                'quantity_difference' => floatval($details['quantity_after']) - floatval($details['quantity_before']),
                'reason_code' => $details['reason_code'] ?? null,
                'adjustment_reason' => $details['reason'] ?? null,
                'approved_by' => $details['approved_by'] ?? null,
                'cost_impact' => isset($details['unit_cost']) ? 
                    (floatval($details['quantity_after']) - floatval($details['quantity_before'])) * floatval($details['unit_cost']) : null
            ];
            
            $description = sprintf(
                'Adjusted quantity from %.2f to %.2f (Δ%.2f) - %s',
                $auditData['quantity_before'],
                $auditData['quantity_after'],
                $auditData['quantity_difference'],
                $auditData['adjustment_reason'] ?? 'No reason provided'
            );
            
            return $this->createAuditEntry(
                $clockNumber,
                'INVENTORY_ADJUST',
                'inventory',
                $invTag,
                $auditData,
                $description
            );
            
        } catch (Exception $e) {
            error_log('Inventory adjustment audit error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get audit trail for specific inventory item
     * 
     * @param string $invTag INV tag to lookup
     * @param int $limit Maximum number of entries
     * @return array Audit trail entries
     */
    public function getInventoryAuditTrail($invTag, $limit = 50) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    al.*,
                    u.real_name as user_real_name,
                    u.initials as user_initials
                FROM audit_logs al
                LEFT JOIN users u ON al.clock_number = u.clock_number
                WHERE al.record_id = ? 
                  AND al.table_affected = 'inventory'
                  AND al.action LIKE 'INVENTORY_%'
                ORDER BY al.created_at DESC
                LIMIT ?
            ");
            
            $stmt->execute([$invTag, $limit]);
            $auditTrail = $stmt->fetchAll();
            
            // Decode JSON details
            foreach ($auditTrail as &$entry) {
                $entry['details'] = json_decode($entry['details'], true);
            }
            unset($entry);
            
            return $auditTrail;
            
        } catch (Exception $e) {
            error_log('Get audit trail error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Generate inventory activity report
     * 
     * @param array $filters Report filters
     * @return array Activity report data
     */
    public function generateActivityReport($filters = []) {
        try {
            $whereConditions = ["al.table_affected = 'inventory'"];
            $params = [];
            
            // Date range filter
            if (!empty($filters['start_date'])) {
                $whereConditions[] = "al.created_at >= ?";
                $params[] = $filters['start_date'];
            }
            
            if (!empty($filters['end_date'])) {
                $whereConditions[] = "al.created_at <= ?";
                $params[] = $filters['end_date'] . ' 23:59:59';
            }
            
            // Action type filter
            if (!empty($filters['action_type'])) {
                $whereConditions[] = "al.action = ?";
                $params[] = $filters['action_type'];
            }
            
            // User filter
            if (!empty($filters['clock_number'])) {
                $whereConditions[] = "al.clock_number = ?";
                $params[] = $filters['clock_number'];
            }
            
            $sql = "
                SELECT 
                    DATE(al.created_at) as activity_date,
                    al.action,
                    COUNT(*) as action_count,
                    COUNT(DISTINCT al.clock_number) as unique_users,
                    COUNT(DISTINCT al.record_id) as unique_items
                FROM audit_logs al
                WHERE " . implode(' AND ', $whereConditions) . "
                GROUP BY DATE(al.created_at), al.action
                ORDER BY activity_date DESC, al.action
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log('Generate activity report error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Generate cost impact report for inventory adjustments
     * 
     * @param array $filters Report filters
     * @return array Cost impact report data
     */
    public function generateCostImpactReport($filters = []) {
        try {
            $whereConditions = [
                "al.table_affected = 'inventory'",
                "al.action IN ('INVENTORY_ADJUST', 'INVENTORY_CONSUME', 'INVENTORY_RECEIVE')"
            ];
            $params = [];
            
            // Date range filter
            if (!empty($filters['start_date'])) {
                $whereConditions[] = "al.created_at >= ?";
                $params[] = $filters['start_date'];
            }
            
            if (!empty($filters['end_date'])) {
                $whereConditions[] = "al.created_at <= ?";
                $params[] = $filters['end_date'] . ' 23:59:59';
            }
            
            $sql = "
                SELECT 
                    al.action,
                    al.record_id as inv_tag,
                    al.created_at,
                    al.clock_number,
                    u.real_name as user_name,
                    JSON_EXTRACT(al.details, '$.total_value') as transaction_value,
                    JSON_EXTRACT(al.details, '$.cost_impact') as cost_impact,
                    JSON_EXTRACT(al.details, '$.material_number') as material_number,
                    JSON_EXTRACT(al.details, '$.quantity_consumed') as quantity_consumed,
                    JSON_EXTRACT(al.details, '$.quantity_received') as quantity_received
                FROM audit_logs al
                LEFT JOIN users u ON al.clock_number = u.clock_number
                WHERE " . implode(' AND ', $whereConditions) . "
                  AND (JSON_EXTRACT(al.details, '$.total_value') IS NOT NULL 
                       OR JSON_EXTRACT(al.details, '$.cost_impact') IS NOT NULL)
                ORDER BY al.created_at DESC
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log('Generate cost impact report error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Create audit entry in database
     * 
     * @param string $clockNumber User clock number
     * @param string $action Action performed
     * @param string $table Table affected
     * @param string $recordId Record identifier
     * @param array $details Action details
     * @param string $description Human-readable description
     * @return bool Success status
     */
    private function createAuditEntry($clockNumber, $action, $table, $recordId, $details, $description) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO audit_logs (
                    clock_number, action, table_affected, record_id, 
                    details, description, ip_address, user_agent, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            return $stmt->execute([
                $clockNumber,
                $action,
                $table,
                $recordId,
                json_encode($details, JSON_UNESCAPED_SLASHES),
                $description,
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                $_SERVER['HTTP_USER_AGENT'] ?? 'BEMS-API'
            ]);
            
        } catch (Exception $e) {
            error_log('Create audit entry error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Cleanup old audit logs based on retention policy
     * 
     * @param int $retentionDays Number of days to retain
     * @return int Number of records deleted
     */
    public function cleanupOldAuditLogs($retentionDays = null) {
        try {
            $retentionDays = $retentionDays ?? AppConfig::AUDIT_LOG_RETENTION_DAYS;
            
            $stmt = $this->db->prepare("
                DELETE FROM audit_logs 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND table_affected = 'inventory'
            ");
            
            $stmt->execute([$retentionDays]);
            
            return $stmt->rowCount();
            
        } catch (Exception $e) {
            error_log('Cleanup audit logs error: ' . $e->getMessage());
            return 0;
        }
    }
}
?>