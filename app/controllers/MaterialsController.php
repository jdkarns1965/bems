<?php
/**
 * BEMS Materials Controller
 * Best ERP Manufacturing System
 * 
 * Handles materials master data API endpoints for creating, reading,
 * updating materials with proper validation and audit trail.
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__) . '/middleware/AuthMiddleware.php';

class MaterialsController {
    
    private $db;
    private $authMiddleware;
    
    public function __construct() {
        $this->db = DatabaseConfig::getConnection();
        $this->authMiddleware = new AuthMiddleware();
    }
    
    /**
     * Get all materials with optional filtering
     * GET /api/v1/materials
     * 
     * @return array JSON response
     */
    public function getMaterials() {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            // Get query parameters
            $category_code = $_GET['category_code'] ?? null;
            $active_only = isset($_GET['active_only']) ? filter_var($_GET['active_only'], FILTER_VALIDATE_BOOLEAN) : true;
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
            $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
            $search = $_GET['search'] ?? null;
            
            // Build query
            $sql = "
                SELECT 
                    m.*,
                    mc.category_name,
                    mc.category_description
                FROM materials m
                LEFT JOIN material_categories mc ON m.category_id = mc.id
                WHERE 1=1
            ";
            $params = [];
            
            if ($active_only) {
                $sql .= " AND m.active_status = 1";
            }
            
            if ($category_code) {
                $sql .= " AND mc.category_code = ?";
                $params[] = $category_code;
            }
            
            if ($search) {
                $sql .= " AND (m.material_number LIKE ? OR m.material_name LIKE ? OR m.description LIKE ?)";
                $searchParam = "%$search%";
                $params[] = $searchParam;
                $params[] = $searchParam;
                $params[] = $searchParam;
            }
            
            $sql .= " ORDER BY m.material_number LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $materials = $stmt->fetchAll();
            
            // Get total count for pagination
            $countSql = "
                SELECT COUNT(*) as total 
                FROM materials m
                LEFT JOIN material_categories mc ON m.category_id = mc.id
                WHERE 1=1
            ";
            $countParams = [];
            
            if ($active_only) {
                $countSql .= " AND m.active_status = 1";
            }
            
            if ($category_code) {
                $countSql .= " AND mc.category_code = ?";
                $countParams[] = $category_code;
            }
            
            if ($search) {
                $countSql .= " AND (m.material_number LIKE ? OR m.material_name LIKE ? OR m.description LIKE ?)";
                $searchParam = "%$search%";
                $countParams[] = $searchParam;
                $countParams[] = $searchParam;
                $countParams[] = $searchParam;
            }
            
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($countParams);
            $total = $countStmt->fetch()['total'];
            
            return $this->jsonResponse([
                'success' => true,
                'materials' => $materials,
                'pagination' => [
                    'total' => intval($total),
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $total
                ]
            ]);
            
        } catch (Exception $e) {
            error_log('Get materials error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve materials'
            ], 500);
        }
    }
    
    /**
     * Get specific material details by material number
     * GET /api/v1/materials/{material_number}
     * 
     * @param string $material_number Material number to lookup
     * @return array JSON response
     */
    public function getMaterial($material_number) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            $stmt = $this->db->prepare("
                SELECT 
                    m.*,
                    mc.category_name,
                    mc.category_description,
                    mc.category_code
                FROM materials m
                LEFT JOIN material_categories mc ON m.category_id = mc.id
                WHERE m.material_number = ?
            ");
            $stmt->execute([$material_number]);
            $material = $stmt->fetch();
            
            if (!$material) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Material not found'
                ], 404);
            }
            
            // Get current inventory summary for this material
            $inventoryStmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_lots,
                    SUM(CASE WHEN status = 'AVAILABLE' THEN quantity ELSE 0 END) as available_quantity,
                    SUM(quantity) as total_quantity,
                    SUM(total_value) as total_value,
                    MIN(received_date) as oldest_lot_date,
                    COUNT(CASE WHEN alert_status != 'NORMAL' THEN 1 END) as alert_count
                FROM v_inventory_summary
                WHERE material_number = ?
            ");
            $inventoryStmt->execute([$material_number]);
            $inventory_summary = $inventoryStmt->fetch();
            
            return $this->jsonResponse([
                'success' => true,
                'data' => [
                    'material' => $material,
                    'inventory_summary' => $inventory_summary
                ]
            ]);
            
        } catch (Exception $e) {
            error_log('Get material error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve material'
            ], 500);
        }
    }
    
    /**
     * Create new material (Admin only)
     * POST /api/v1/materials
     * 
     * @param array $input Request input data
     * @return array JSON response
     */
    public function createMaterial($input) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            // Check admin permission
            if ($user['permission_level'] < 100) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Administrator privileges required'
                ], 403);
            }
            
            // Validate required fields
            $required = ['material_number', 'material_name', 'category_code', 'primary_uom', 'material_grade'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ], 400);
                }
            }
            
            // Check if material number already exists
            $checkStmt = $this->db->prepare("SELECT id FROM materials WHERE material_number = ?");
            $checkStmt->execute([$input['material_number']]);
            if ($checkStmt->fetch()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Material number already exists'
                ], 400);
            }
            
            // Get category ID
            $categoryStmt = $this->db->prepare("SELECT id FROM material_categories WHERE category_code = ?");
            $categoryStmt->execute([$input['category_code']]);
            $category = $categoryStmt->fetch();
            if (!$category) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Invalid category code'
                ], 400);
            }
            
            // Validate numeric fields
            $standard_cost = isset($input['standard_cost']) ? floatval($input['standard_cost']) : 0.00;
            $shelf_life_days = isset($input['shelf_life_days']) ? intval($input['shelf_life_days']) : null;
            $reorder_point = isset($input['reorder_point']) ? floatval($input['reorder_point']) : 0.00;
            $reorder_quantity = isset($input['reorder_quantity']) ? floatval($input['reorder_quantity']) : 0.00;
            $lead_time_days = isset($input['lead_time_days']) ? intval($input['lead_time_days']) : 0;
            
            // Insert new material
            $insertStmt = $this->db->prepare("
                INSERT INTO materials (
                    material_number, material_name, description, category_id, primary_uom,
                    alternative_uom, material_grade, specifications, standard_cost,
                    shelf_life_days, storage_requirements, supplier_name, manufacturer_name,
                    supplier_part_number, manufacturer_part_number, abc_classification,
                    reorder_point, reorder_quantity, lead_time_days, safety_stock,
                    hazmat_code, notes, active_status, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW()
                )
            ");
            
            $insertStmt->execute([
                $input['material_number'],
                $input['material_name'],
                $input['description'] ?? null,
                $category['id'],
                $input['primary_uom'],
                $input['alternative_uom'] ?? null,
                $input['material_grade'],
                $input['specifications'] ?? null,
                $standard_cost,
                $shelf_life_days,
                $input['storage_requirements'] ?? null,
                $input['supplier_name'] ?? null,
                $input['manufacturer_name'] ?? null,
                $input['supplier_part_number'] ?? null,
                $input['manufacturer_part_number'] ?? null,
                $input['abc_classification'] ?? 'C',
                $reorder_point,
                $reorder_quantity,
                $lead_time_days,
                $input['safety_stock'] ?? null,
                $input['hazmat_code'] ?? null,
                $input['notes'] ?? null,
                $user['clock_number']
            ]);
            
            $materialId = $this->db->lastInsertId();
            
            // Log audit trail
            $this->logAuditAction('MATERIAL_CREATE', [
                'material_id' => $materialId,
                'material_number' => $input['material_number'],
                'material_name' => $input['material_name'],
                'category_code' => $input['category_code']
            ], $user['clock_number']);
            
            return $this->jsonResponse([
                'success' => true,
                'message' => 'Material created successfully',
                'data' => [
                    'id' => intval($materialId),
                    'material_number' => $input['material_number'],
                    'material_name' => $input['material_name']
                ]
            ], 201);
            
        } catch (Exception $e) {
            error_log('Create material error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to create material'
            ], 500);
        }
    }
    
    /**
     * Update existing material (Admin only)
     * PUT /api/v1/materials/{material_number}
     * 
     * @param string $material_number Material number to update
     * @param array $input Request input data
     * @return array JSON response
     */
    public function updateMaterial($material_number, $input) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            // Check admin permission
            if ($user['permission_level'] < 100) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Administrator privileges required'
                ], 403);
            }
            
            // Check if material exists
            $materialStmt = $this->db->prepare("SELECT id FROM materials WHERE material_number = ?");
            $materialStmt->execute([$material_number]);
            $material = $materialStmt->fetch();
            if (!$material) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Material not found'
                ], 404);
            }
            
            // Build update query dynamically based on provided fields
            $updateFields = [];
            $params = [];
            
            $allowedFields = [
                'material_name', 'description', 'primary_uom', 'alternative_uom',
                'material_grade', 'specifications', 'standard_cost', 'shelf_life_days',
                'storage_requirements', 'supplier_name', 'manufacturer_name',
                'supplier_part_number', 'manufacturer_part_number', 'abc_classification',
                'reorder_point', 'reorder_quantity', 'lead_time_days', 'safety_stock',
                'hazmat_code', 'notes', 'active_status'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $input[$field];
                }
            }
            
            if (isset($input['category_code'])) {
                // Get category ID
                $categoryStmt = $this->db->prepare("SELECT id FROM material_categories WHERE category_code = ?");
                $categoryStmt->execute([$input['category_code']]);
                $category = $categoryStmt->fetch();
                if (!$category) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => 'Invalid category code'
                    ], 400);
                }
                $updateFields[] = "category_id = ?";
                $params[] = $category['id'];
            }
            
            if (empty($updateFields)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'No fields to update'
                ], 400);
            }
            
            // Add updated_by and updated_at
            $updateFields[] = "updated_by = ?";
            $updateFields[] = "updated_at = NOW()";
            $params[] = $user['clock_number'];
            $params[] = $material_number;
            
            $sql = "UPDATE materials SET " . implode(', ', $updateFields) . " WHERE material_number = ?";
            $updateStmt = $this->db->prepare($sql);
            $updateStmt->execute($params);
            
            // Log audit trail
            $this->logAuditAction('MATERIAL_UPDATE', [
                'material_id' => $material['id'],
                'material_number' => $material_number,
                'updated_fields' => array_keys(array_intersect_key($input, array_flip($allowedFields)))
            ], $user['clock_number']);
            
            return $this->jsonResponse([
                'success' => true,
                'message' => 'Material updated successfully',
                'data' => [
                    'material_number' => $material_number,
                    'updated_fields' => count($updateFields) - 2 // Subtract updated_by and updated_at
                ]
            ]);
            
        } catch (Exception $e) {
            error_log('Update material error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to update material'
            ], 500);
        }
    }
    
    /**
     * Get material categories for dropdowns
     * GET /api/v1/materials/categories
     * 
     * @return array JSON response
     */
    public function getCategories() {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            $stmt = $this->db->prepare("
                SELECT 
                    id, category_code, category_name, category_description,
                    parent_category_id, sort_order, active_status
                FROM material_categories
                WHERE active_status = 1
                ORDER BY sort_order, category_name
            ");
            $stmt->execute();
            $categories = $stmt->fetchAll();
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $categories
            ]);
            
        } catch (Exception $e) {
            error_log('Get categories error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve categories'
            ], 500);
        }
    }
    
    /**
     * Get materials for dropdown (simplified data)
     * GET /api/v1/materials/dropdown
     * 
     * @return array JSON response
     */
    public function getDropdownMaterials() {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            $category_code = $_GET['category_code'] ?? null;
            
            $sql = "
                SELECT 
                    m.material_number,
                    m.material_name,
                    m.primary_uom,
                    mc.category_code,
                    mc.category_name
                FROM materials m
                LEFT JOIN material_categories mc ON m.category_id = mc.id
                WHERE m.active_status = 1
            ";
            $params = [];
            
            if ($category_code) {
                $sql .= " AND mc.category_code = ?";
                $params[] = $category_code;
            }
            
            $sql .= " ORDER BY m.material_number";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $materials = $stmt->fetchAll();
            
            return $this->jsonResponse([
                'success' => true,
                'materials' => $materials
            ]);
            
        } catch (Exception $e) {
            error_log('Get dropdown materials error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve materials'
            ], 500);
        }
    }
    
    /**
     * Log audit action
     * 
     * @param string $action Action performed
     * @param array $details Action details
     * @param string $clockNumber User clock number
     */
    private function logAuditAction($action, $details, $clockNumber) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO audit_logs (clock_number, action, table_affected, record_id, details, ip_address, user_agent, created_at)
                VALUES (?, ?, 'materials', ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $clockNumber,
                $action,
                $details['material_id'] ?? null,
                json_encode($details),
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                $_SERVER['HTTP_USER_AGENT'] ?? 'API'
            ]);
            
        } catch (Exception $e) {
            error_log('Audit logging error: ' . $e->getMessage());
            // Don't throw exception for audit logging failures
        }
    }
    
    /**
     * Create standardized JSON response
     * 
     * @param array $data Response data
     * @param int $statusCode HTTP status code
     * @return array Response array
     */
    private function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        
        $response = array_merge([
            'timestamp' => date('c'),
            'status_code' => $statusCode
        ], $data);
        
        return $response;
    }
}
?>