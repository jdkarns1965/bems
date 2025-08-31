<?php
/**
 * BEMS BOM Service
 * Best ERP Manufacturing System
 * 
 * Business logic layer for Bill of Materials (BOM) operations including
 * multi-level explosion, implosion, cost rollup, and circular reference detection.
 */

require_once dirname(__DIR__, 2) . '/config/database.php';

class BomService {
    
    private $db;
    
    public function __construct() {
        $this->db = DatabaseConfig::getConnection();
    }
    
    /**
     * Create a new BOM
     * 
     * @param array $data BOM data
     * @param string $userId User creating the BOM
     * @return array Result with success status and BOM ID
     */
    public function createBom($data, $userId) {
        try {
            $this->db->beginTransaction();
            
            // Set user IP for audit trail
            $userIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $this->db->exec("SET @user_ip = '$userIp'");
            
            $sql = "INSERT INTO bom_headers (
                bom_number, parent_material_number, bom_version, description,
                base_quantity, unit_of_measure, status, effective_date,
                expiry_date, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([
                $data['bom_number'],
                $data['parent_material_number'],
                $data['bom_version'] ?? '1.0',
                $data['description'],
                $data['base_quantity'],
                $data['unit_of_measure'] ?? 'EA',
                $data['status'] ?? 'active',
                $data['effective_date'] ?? date('Y-m-d'),
                $data['expiry_date'] ?? null,
                $userId
            ]);
            
            if (!$result) {
                throw new Exception('Failed to insert BOM header');
            }
            
            $bomId = $this->db->lastInsertId();
            
            $this->db->commit();
            
            return [
                'success' => true,
                'bom_id' => $bomId,
                'message' => 'BOM created successfully'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get BOM structure with all components
     * 
     * @param int $bomId BOM ID
     * @return array|null BOM structure data
     */
    public function getBomStructure($bomId) {
        try {
            // Get BOM header
            $sql = "SELECT 
                h.*,
                m.material_description as parent_description,
                m.standard_cost as parent_unit_cost
            FROM bom_headers h
            LEFT JOIN materials m ON h.parent_material_number = m.material_number
            WHERE h.id = ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$bomId]);
            $header = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$header) {
                return null;
            }
            
            // Get BOM lines (components)
            $sql = "SELECT 
                l.*,
                m.material_description as component_description,
                m.standard_cost as component_unit_cost,
                m.base_unit as component_uom,
                ROUND(l.required_quantity * (1 + l.scrap_percentage/100), 6) as net_required_qty,
                ROUND(l.required_quantity * (1 + l.scrap_percentage/100) * COALESCE(m.standard_cost, 0), 4) as extended_cost
            FROM bom_lines l
            LEFT JOIN materials m ON l.component_material_number = m.material_number
            WHERE l.bom_id = ?
              AND l.effective_date <= CURDATE()
              AND (l.expiry_date IS NULL OR l.expiry_date > CURDATE())
            ORDER BY l.line_number";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$bomId]);
            $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate totals
            $totalCost = array_sum(array_column($lines, 'extended_cost'));
            $componentCount = count($lines);
            
            return [
                'header' => $header,
                'lines' => $lines,
                'summary' => [
                    'component_count' => $componentCount,
                    'total_material_cost' => $totalCost,
                    'cost_per_unit' => $header['base_quantity'] > 0 ? $totalCost / $header['base_quantity'] : 0
                ]
            ];
            
        } catch (Exception $e) {
            error_log("BomService::getBomStructure error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Add component to BOM
     * 
     * @param array $data Component data
     * @param string $userId User adding the component
     * @return array Result with success status
     */
    public function addBomComponent($data, $userId) {
        try {
            $this->db->beginTransaction();
            
            // Check if line number already exists
            $sql = "SELECT COUNT(*) FROM bom_lines WHERE bom_id = ? AND line_number = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$data['bom_id'], $data['line_number']]);
            
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('Line number already exists');
            }
            
            // Set user IP for audit trail
            $userIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $this->db->exec("SET @user_ip = '$userIp'");
            
            $sql = "INSERT INTO bom_lines (
                bom_id, line_number, component_material_number, required_quantity,
                unit_of_measure, scrap_percentage, lead_time_offset_days,
                operation_sequence, substitute_group, is_critical, is_phantom,
                notes, effective_date, expiry_date, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([
                $data['bom_id'],
                $data['line_number'],
                $data['component_material_number'],
                $data['required_quantity'],
                $data['unit_of_measure'] ?? 'EA',
                $data['scrap_percentage'] ?? 0.00,
                $data['lead_time_offset_days'] ?? 0,
                $data['operation_sequence'] ?? null,
                $data['substitute_group'] ?? null,
                isset($data['is_critical']) ? (bool)$data['is_critical'] : false,
                isset($data['is_phantom']) ? (bool)$data['is_phantom'] : false,
                $data['notes'] ?? null,
                $data['effective_date'] ?? date('Y-m-d'),
                $data['expiry_date'] ?? null,
                $userId
            ]);
            
            if (!$result) {
                throw new Exception('Failed to insert BOM component');
            }
            
            $lineId = $this->db->lastInsertId();
            
            $this->db->commit();
            
            return [
                'success' => true,
                'line_id' => $lineId,
                'message' => 'Component added successfully'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Update BOM component
     * 
     * @param array $data Component data including line_id
     * @param string $userId User updating the component
     * @return array Result with success status
     */
    public function updateBomComponent($data, $userId) {
        try {
            $this->db->beginTransaction();
            
            // Build dynamic update query
            $updateFields = [];
            $params = [];
            
            $allowedFields = [
                'required_quantity', 'unit_of_measure', 'scrap_percentage',
                'lead_time_offset_days', 'operation_sequence', 'substitute_group',
                'is_critical', 'is_phantom', 'notes', 'effective_date', 'expiry_date'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }
            
            if (empty($updateFields)) {
                throw new Exception('No fields to update');
            }
            
            $updateFields[] = "updated_by = ?";
            $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
            $params[] = $userId;
            $params[] = $data['line_id'];
            
            $sql = "UPDATE bom_lines SET " . implode(', ', $updateFields) . " WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute($params);
            
            if (!$result) {
                throw new Exception('Failed to update component');
            }
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Component not found');
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Component updated successfully'
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Remove component from BOM
     * 
     * @param int $lineId BOM line ID
     * @param string $userId User removing the component
     * @return array Result with success status
     */
    public function removeBomComponent($lineId, $userId) {
        try {
            $sql = "DELETE FROM bom_lines WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([$lineId]);
            
            if ($stmt->rowCount() === 0) {
                return [
                    'success' => false,
                    'message' => 'Component not found'
                ];
            }
            
            return [
                'success' => true,
                'message' => 'Component removed successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Explode BOM to get all components at all levels
     * 
     * @param string $parentMaterial Parent material number
     * @param float $quantity Quantity to explode
     * @param int $maxLevels Maximum levels to explode
     * @return array Multi-level component requirements
     */
    public function explodeBom($parentMaterial, $quantity = 1, $maxLevels = 10) {
        $explosion = [];
        $this->explodeBomRecursive($parentMaterial, $quantity, 0, $maxLevels, $explosion, []);
        
        // Consolidate components by material number
        $consolidated = [];
        foreach ($explosion as $component) {
            $key = $component['component_material_number'];
            if (isset($consolidated[$key])) {
                $consolidated[$key]['total_quantity'] += $component['net_quantity'];
                $consolidated[$key]['sources'][] = [
                    'parent' => $component['parent_material'],
                    'level' => $component['level'],
                    'quantity' => $component['net_quantity']
                ];
            } else {
                $consolidated[$key] = $component;
                $consolidated[$key]['total_quantity'] = $component['net_quantity'];
                $consolidated[$key]['sources'] = [[
                    'parent' => $component['parent_material'],
                    'level' => $component['level'],
                    'quantity' => $component['net_quantity']
                ]];
            }
        }
        
        return array_values($consolidated);
    }
    
    /**
     * Recursive BOM explosion helper
     */
    private function explodeBomRecursive($materialNumber, $quantity, $level, $maxLevels, &$explosion, $visited) {
        if ($level >= $maxLevels || in_array($materialNumber, $visited)) {
            return; // Prevent infinite recursion
        }
        
        $visited[] = $materialNumber;
        
        // Get active BOM for this material
        $sql = "SELECT h.*, l.*,
                m.material_description as component_description,
                m.standard_cost as component_unit_cost
            FROM bom_headers h
            JOIN bom_lines l ON h.id = l.bom_id
            LEFT JOIN materials m ON l.component_material_number = m.material_number
            WHERE h.parent_material_number = ?
              AND h.status = 'active'
              AND h.effective_date <= CURDATE()
              AND (h.expiry_date IS NULL OR h.expiry_date > CURDATE())
              AND l.effective_date <= CURDATE()
              AND (l.expiry_date IS NULL OR l.expiry_date > CURDATE())
            ORDER BY l.line_number";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$materialNumber]);
        $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($components as $component) {
            $netQty = ($component['required_quantity'] * (1 + $component['scrap_percentage']/100)) * $quantity;
            
            $explosion[] = [
                'parent_material' => $materialNumber,
                'component_material_number' => $component['component_material_number'],
                'component_description' => $component['component_description'],
                'level' => $level + 1,
                'required_quantity' => $component['required_quantity'],
                'scrap_percentage' => $component['scrap_percentage'],
                'net_quantity' => $netQty,
                'unit_of_measure' => $component['unit_of_measure'],
                'unit_cost' => $component['component_unit_cost'],
                'extended_cost' => $netQty * ($component['component_unit_cost'] ?? 0),
                'is_critical' => $component['is_critical'],
                'is_phantom' => $component['is_phantom']
            ];
            
            // Recurse into this component if it's not phantom
            if (!$component['is_phantom']) {
                $this->explodeBomRecursive(
                    $component['component_material_number'], 
                    $netQty, 
                    $level + 1, 
                    $maxLevels, 
                    $explosion, 
                    $visited
                );
            }
        }
        
        array_pop($visited); // Remove from visited on backtrack
    }
    
    /**
     * Calculate cost rollup for BOM
     * 
     * @param int $bomId BOM ID
     * @return array Cost breakdown
     */
    public function calculateCostRollup($bomId) {
        try {
            // Get BOM structure first
            $structure = $this->getBomStructure($bomId);
            if (!$structure) {
                throw new Exception('BOM not found');
            }
            
            $materialCost = 0;
            $componentDetails = [];
            
            foreach ($structure['lines'] as $line) {
                $extendedCost = $line['extended_cost'] ?? 0;
                $materialCost += $extendedCost;
                
                $componentDetails[] = [
                    'component' => $line['component_material_number'],
                    'description' => $line['component_description'],
                    'quantity' => $line['net_required_qty'],
                    'unit_cost' => $line['component_unit_cost'] ?? 0,
                    'extended_cost' => $extendedCost
                ];
            }
            
            // For now, labor and overhead are 0 (Phase 3 will add routing)
            $laborCost = 0;
            $overheadCost = 0;
            $totalCost = $materialCost + $laborCost + $overheadCost;
            
            $costPerUnit = $structure['header']['base_quantity'] > 0 
                ? $totalCost / $structure['header']['base_quantity'] 
                : 0;
            
            // Save cost rollup to database
            $this->saveCostRollup($bomId, [
                'material_cost' => $materialCost,
                'labor_cost' => $laborCost,
                'overhead_cost' => $overheadCost,
                'total_cost' => $totalCost,
                'cost_per_unit' => $costPerUnit
            ], 'system');
            
            return [
                'bom_id' => $bomId,
                'bom_number' => $structure['header']['bom_number'],
                'parent_material' => $structure['header']['parent_material_number'],
                'base_quantity' => $structure['header']['base_quantity'],
                'cost_breakdown' => [
                    'material_cost' => round($materialCost, 4),
                    'labor_cost' => round($laborCost, 4),
                    'overhead_cost' => round($overheadCost, 4),
                    'total_cost' => round($totalCost, 4),
                    'cost_per_unit' => round($costPerUnit, 4)
                ],
                'component_details' => $componentDetails,
                'calculation_date' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            error_log("BomService::calculateCostRollup error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Save cost rollup to database
     */
    private function saveCostRollup($bomId, $costs, $userId) {
        try {
            $sql = "INSERT INTO bom_cost_rollup (
                bom_id, cost_type, material_cost, labor_cost, 
                overhead_cost, total_cost, cost_per_unit, calculated_by
            ) VALUES (?, 'standard', ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                material_cost = VALUES(material_cost),
                labor_cost = VALUES(labor_cost),
                overhead_cost = VALUES(overhead_cost),
                total_cost = VALUES(total_cost),
                cost_per_unit = VALUES(cost_per_unit),
                calculation_date = CURRENT_TIMESTAMP,
                calculated_by = VALUES(calculated_by)";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $bomId,
                $costs['material_cost'],
                $costs['labor_cost'], 
                $costs['overhead_cost'],
                $costs['total_cost'],
                $costs['cost_per_unit'],
                $userId
            ]);
            
        } catch (Exception $e) {
            error_log("BomService::saveCostRollup error: " . $e->getMessage());
        }
    }
    
    /**
     * Check for circular reference in BOM structure
     * 
     * @param string $parentMaterial Parent material
     * @param string $componentMaterial Component being checked
     * @return bool True if circular reference would occur
     */
    public function hasCircularReference($parentMaterial, $componentMaterial) {
        return $this->checkCircularRecursive($parentMaterial, $componentMaterial, []);
    }
    
    /**
     * Recursive circular reference checker
     */
    private function checkCircularRecursive($parentMaterial, $targetComponent, $visited) {
        if ($parentMaterial === $targetComponent) {
            return true; // Found circular reference
        }
        
        if (in_array($parentMaterial, $visited)) {
            return false; // Already checked this path
        }
        
        $visited[] = $parentMaterial;
        
        // Get all components of the target component
        $sql = "SELECT DISTINCT l.component_material_number
            FROM bom_headers h
            JOIN bom_lines l ON h.id = l.bom_id
            WHERE h.parent_material_number = ?
              AND h.status = 'active'";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$targetComponent]);
        $components = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($components as $component) {
            if ($this->checkCircularRecursive($parentMaterial, $component, $visited)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * List BOMs with optional filtering
     * 
     * @param array $filters Filter criteria
     * @return array List of BOMs
     */
    public function listBoms($filters = []) {
        try {
            $sql = "SELECT 
                h.id, h.bom_number, h.parent_material_number, h.bom_version,
                h.description, h.base_quantity, h.unit_of_measure, h.status,
                h.effective_date, h.expiry_date, h.created_by, h.created_at,
                m.material_description as parent_description,
                COUNT(l.id) as component_count,
                COALESCE(cr.total_cost, 0) as total_cost,
                COALESCE(cr.cost_per_unit, 0) as cost_per_unit
            FROM bom_headers h
            LEFT JOIN materials m ON h.parent_material_number = m.material_number
            LEFT JOIN bom_lines l ON h.id = l.bom_id 
                AND l.effective_date <= CURDATE() 
                AND (l.expiry_date IS NULL OR l.expiry_date > CURDATE())
            LEFT JOIN bom_cost_rollup cr ON h.id = cr.bom_id AND cr.cost_type = 'standard'
            WHERE 1=1";
            
            $params = [];
            
            if (!empty($filters['status'])) {
                $sql .= " AND h.status = ?";
                $params[] = $filters['status'];
            }
            
            if (!empty($filters['parent_material'])) {
                $sql .= " AND h.parent_material_number LIKE ?";
                $params[] = '%' . $filters['parent_material'] . '%';
            }
            
            $sql .= " GROUP BY h.id ORDER BY h.bom_number";
            
            if (!empty($filters['limit'])) {
                $sql .= " LIMIT ?";
                $params[] = $filters['limit'];
                
                if (!empty($filters['offset'])) {
                    $sql .= " OFFSET ?";
                    $params[] = $filters['offset'];
                }
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("BomService::listBoms error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Clear explosion cache for a BOM
     * 
     * @param int $bomId BOM ID
     */
    public function clearExplosionCache($bomId) {
        try {
            // Get parent material for this BOM
            $sql = "SELECT parent_material_number FROM bom_headers WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$bomId]);
            $parentMaterial = $stmt->fetchColumn();
            
            if ($parentMaterial) {
                $sql = "DELETE FROM bom_explosion_cache WHERE parent_material_number = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$parentMaterial]);
            }
        } catch (Exception $e) {
            error_log("BomService::clearExplosionCache error: " . $e->getMessage());
        }
    }
}
?>