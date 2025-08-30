<?php
/**
 * BEMS Locations Controller
 * Best ERP Manufacturing System
 * 
 * Handles location master data API endpoints for managing warehouse locations,
 * production areas, and storage facilities with hierarchical organization.
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__) . '/middleware/AuthMiddleware.php';

class LocationsController {
    
    private $db;
    private $authMiddleware;
    
    public function __construct() {
        $this->db = DatabaseConfig::getConnection();
        $this->authMiddleware = new AuthMiddleware();
    }
    
    /**
     * Get all locations with optional filtering
     * GET /api/v1/locations
     * 
     * @return array JSON response
     */
    public function getLocations() {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            // Get query parameters
            $location_type = $_GET['location_type'] ?? null;
            $active_only = isset($_GET['active_only']) ? filter_var($_GET['active_only'], FILTER_VALIDATE_BOOLEAN) : true;
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
            $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
            $search = $_GET['search'] ?? null;
            $with_inventory = isset($_GET['with_inventory']) ? filter_var($_GET['with_inventory'], FILTER_VALIDATE_BOOLEAN) : false;
            
            // Build base query
            $sql = "
                SELECT 
                    l.*,
                    CASE 
                        WHEN l.parent_location_id IS NOT NULL THEN pl.location_name 
                        ELSE NULL 
                    END as parent_location_name
                FROM locations l
                LEFT JOIN locations pl ON l.parent_location_id = pl.id
                WHERE 1=1
            ";
            $params = [];
            
            if ($active_only) {
                $sql .= " AND l.active_status = 1";
            }
            
            if ($location_type) {
                $sql .= " AND l.location_type = ?";
                $params[] = $location_type;
            }
            
            if ($search) {
                $sql .= " AND (l.location_code LIKE ? OR l.location_name LIKE ? OR l.description LIKE ?)";
                $searchParam = "%$search%";
                $params[] = $searchParam;
                $params[] = $searchParam;
                $params[] = $searchParam;
            }
            
            $sql .= " ORDER BY l.location_type, l.location_code LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $locations = $stmt->fetchAll();
            
            // Add inventory information if requested
            if ($with_inventory && !empty($locations)) {
                foreach ($locations as &$location) {
                    $inventoryStmt = $this->db->prepare("
                        SELECT 
                            COUNT(*) as total_lots,
                            SUM(CASE WHEN status = 'AVAILABLE' THEN quantity ELSE 0 END) as available_quantity,
                            SUM(quantity) as total_quantity,
                            SUM(total_value) as total_value
                        FROM v_inventory_summary
                        WHERE location_code = ?
                    ");
                    $inventoryStmt->execute([$location['location_code']]);
                    $location['inventory_summary'] = $inventoryStmt->fetch();
                }
                unset($location); // Break reference
            }
            
            // Get total count for pagination
            $countSql = "SELECT COUNT(*) as total FROM locations l WHERE 1=1";
            $countParams = [];
            
            if ($active_only) {
                $countSql .= " AND l.active_status = 1";
            }
            
            if ($location_type) {
                $countSql .= " AND l.location_type = ?";
                $countParams[] = $location_type;
            }
            
            if ($search) {
                $countSql .= " AND (l.location_code LIKE ? OR l.location_name LIKE ? OR l.description LIKE ?)";
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
                'data' => $locations,
                'pagination' => [
                    'total' => intval($total),
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $total
                ]
            ]);
            
        } catch (Exception $e) {
            error_log('Get locations error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve locations'
            ], 500);
        }
    }
    
    /**
     * Get specific location details by location code
     * GET /api/v1/locations/{location_code}
     * 
     * @param string $location_code Location code to lookup
     * @return array JSON response
     */
    public function getLocation($location_code) {
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
                    l.*,
                    CASE 
                        WHEN l.parent_location_id IS NOT NULL THEN pl.location_name 
                        ELSE NULL 
                    END as parent_location_name,
                    CASE 
                        WHEN l.parent_location_id IS NOT NULL THEN pl.location_code 
                        ELSE NULL 
                    END as parent_location_code
                FROM locations l
                LEFT JOIN locations pl ON l.parent_location_id = pl.id
                WHERE l.location_code = ?
            ");
            $stmt->execute([$location_code]);
            $location = $stmt->fetch();
            
            if (!$location) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Location not found'
                ], 404);
            }
            
            // Get child locations
            $childStmt = $this->db->prepare("
                SELECT id, location_code, location_name, location_type, active_status
                FROM locations
                WHERE parent_location_id = ? AND active_status = 1
                ORDER BY location_code
            ");
            $childStmt->execute([$location['id']]);
            $child_locations = $childStmt->fetchAll();
            
            // Get current inventory summary for this location
            $inventoryStmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_lots,
                    SUM(CASE WHEN status = 'AVAILABLE' THEN quantity ELSE 0 END) as available_quantity,
                    SUM(quantity) as total_quantity,
                    SUM(total_value) as total_value,
                    COUNT(CASE WHEN alert_status != 'NORMAL' THEN 1 END) as alert_count,
                    COUNT(DISTINCT material_number) as unique_materials
                FROM v_inventory_summary
                WHERE location_code = ?
            ");
            $inventoryStmt->execute([$location_code]);
            $inventory_summary = $inventoryStmt->fetch();
            
            // Get recent movement activity
            $movementStmt = $this->db->prepare("
                SELECT 
                    inv_tag, material_number, movement_type, quantity_moved,
                    movement_date, created_by_name, reference_number
                FROM v_recent_inventory_movements
                WHERE to_location_code = ? OR from_location_code = ?
                ORDER BY movement_time DESC
                LIMIT 10
            ");
            $movementStmt->execute([$location_code, $location_code]);
            $recent_movements = $movementStmt->fetchAll();
            
            return $this->jsonResponse([
                'success' => true,
                'data' => [
                    'location' => $location,
                    'child_locations' => $child_locations,
                    'inventory_summary' => $inventory_summary,
                    'recent_movements' => $recent_movements
                ]
            ]);
            
        } catch (Exception $e) {
            error_log('Get location error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve location'
            ], 500);
        }
    }
    
    /**
     * Create new location (Admin only)
     * POST /api/v1/locations
     * 
     * @param array $input Request input data
     * @return array JSON response
     */
    public function createLocation($input) {
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
            $required = ['location_code', 'location_name', 'location_type'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ], 400);
                }
            }
            
            // Validate location type
            $validTypes = ['RECEIVING', 'STORAGE', 'PRODUCTION', 'SHIPPING', 'QC', 'SCRAP'];
            if (!in_array($input['location_type'], $validTypes)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Invalid location type. Must be one of: ' . implode(', ', $validTypes)
                ], 400);
            }
            
            // Check if location code already exists
            $checkStmt = $this->db->prepare("SELECT id FROM locations WHERE location_code = ?");
            $checkStmt->execute([$input['location_code']]);
            if ($checkStmt->fetch()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Location code already exists'
                ], 400);
            }
            
            // Validate parent location if provided
            $parent_location_id = null;
            if (!empty($input['parent_location_code'])) {
                $parentStmt = $this->db->prepare("SELECT id FROM locations WHERE location_code = ?");
                $parentStmt->execute([$input['parent_location_code']]);
                $parent = $parentStmt->fetch();
                if (!$parent) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => 'Invalid parent location code'
                    ], 400);
                }
                $parent_location_id = $parent['id'];
            }
            
            // Validate numeric fields
            $capacity_quantity = isset($input['capacity_quantity']) ? floatval($input['capacity_quantity']) : null;
            $capacity_weight = isset($input['capacity_weight']) ? floatval($input['capacity_weight']) : null;
            $capacity_volume = isset($input['capacity_volume']) ? floatval($input['capacity_volume']) : null;
            $temperature_min = isset($input['temperature_min']) ? floatval($input['temperature_min']) : null;
            $temperature_max = isset($input['temperature_max']) ? floatval($input['temperature_max']) : null;
            $humidity_max = isset($input['humidity_max']) ? floatval($input['humidity_max']) : null;
            
            // Insert new location
            $insertStmt = $this->db->prepare("
                INSERT INTO locations (
                    location_code, location_name, description, location_type, parent_location_id,
                    building, zone_area, aisle, rack, shelf, bin, barcode_data, qr_code_data,
                    capacity_quantity, capacity_weight, capacity_volume, temperature_controlled,
                    temperature_min, temperature_max, humidity_controlled, humidity_max,
                    hazmat_approved, security_level, notes, active_status, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW()
                )
            ");
            
            $insertStmt->execute([
                $input['location_code'],
                $input['location_name'],
                $input['description'] ?? null,
                $input['location_type'],
                $parent_location_id,
                $input['building'] ?? null,
                $input['zone_area'] ?? null,
                $input['aisle'] ?? null,
                $input['rack'] ?? null,
                $input['shelf'] ?? null,
                $input['bin'] ?? null,
                $input['barcode_data'] ?? null,
                $input['qr_code_data'] ?? null,
                $capacity_quantity,
                $capacity_weight,
                $capacity_volume,
                isset($input['temperature_controlled']) ? ($input['temperature_controlled'] ? 1 : 0) : 0,
                $temperature_min,
                $temperature_max,
                isset($input['humidity_controlled']) ? ($input['humidity_controlled'] ? 1 : 0) : 0,
                $humidity_max,
                isset($input['hazmat_approved']) ? ($input['hazmat_approved'] ? 1 : 0) : 0,
                $input['security_level'] ?? 'STANDARD',
                $input['notes'] ?? null,
                $user['clock_number']
            ]);
            
            $locationId = $this->db->lastInsertId();
            
            // Log audit trail
            $this->logAuditAction('LOCATION_CREATE', [
                'location_id' => $locationId,
                'location_code' => $input['location_code'],
                'location_name' => $input['location_name'],
                'location_type' => $input['location_type']
            ], $user['clock_number']);
            
            return $this->jsonResponse([
                'success' => true,
                'message' => 'Location created successfully',
                'data' => [
                    'id' => intval($locationId),
                    'location_code' => $input['location_code'],
                    'location_name' => $input['location_name']
                ]
            ], 201);
            
        } catch (Exception $e) {
            error_log('Create location error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to create location'
            ], 500);
        }
    }
    
    /**
     * Update existing location (Admin only)
     * PUT /api/v1/locations/{location_code}
     * 
     * @param string $location_code Location code to update
     * @param array $input Request input data
     * @return array JSON response
     */
    public function updateLocation($location_code, $input) {
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
            
            // Check if location exists
            $locationStmt = $this->db->prepare("SELECT id FROM locations WHERE location_code = ?");
            $locationStmt->execute([$location_code]);
            $location = $locationStmt->fetch();
            if (!$location) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Location not found'
                ], 404);
            }
            
            // Build update query dynamically based on provided fields
            $updateFields = [];
            $params = [];
            
            $allowedFields = [
                'location_name', 'description', 'location_type', 'building', 'zone_area',
                'aisle', 'rack', 'shelf', 'bin', 'barcode_data', 'qr_code_data',
                'capacity_quantity', 'capacity_weight', 'capacity_volume',
                'temperature_controlled', 'temperature_min', 'temperature_max',
                'humidity_controlled', 'humidity_max', 'hazmat_approved',
                'security_level', 'notes', 'active_status'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $input[$field];
                }
            }
            
            // Handle parent location update
            if (isset($input['parent_location_code'])) {
                if (empty($input['parent_location_code'])) {
                    $updateFields[] = "parent_location_id = NULL";
                } else {
                    $parentStmt = $this->db->prepare("SELECT id FROM locations WHERE location_code = ?");
                    $parentStmt->execute([$input['parent_location_code']]);
                    $parent = $parentStmt->fetch();
                    if (!$parent) {
                        return $this->jsonResponse([
                            'success' => false,
                            'message' => 'Invalid parent location code'
                        ], 400);
                    }
                    $updateFields[] = "parent_location_id = ?";
                    $params[] = $parent['id'];
                }
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
            $params[] = $location_code;
            
            $sql = "UPDATE locations SET " . implode(', ', $updateFields) . " WHERE location_code = ?";
            $updateStmt = $this->db->prepare($sql);
            $updateStmt->execute($params);
            
            // Log audit trail
            $this->logAuditAction('LOCATION_UPDATE', [
                'location_id' => $location['id'],
                'location_code' => $location_code,
                'updated_fields' => array_keys(array_intersect_key($input, array_flip($allowedFields)))
            ], $user['clock_number']);
            
            return $this->jsonResponse([
                'success' => true,
                'message' => 'Location updated successfully',
                'data' => [
                    'location_code' => $location_code,
                    'updated_fields' => count($updateFields) - 2 // Subtract updated_by and updated_at
                ]
            ]);
            
        } catch (Exception $e) {
            error_log('Update location error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to update location'
            ], 500);
        }
    }
    
    /**
     * Get locations for dropdown (simplified data)
     * GET /api/v1/locations/dropdown
     * 
     * @return array JSON response
     */
    public function getDropdownLocations() {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            $location_type = $_GET['location_type'] ?? null;
            
            $sql = "
                SELECT 
                    location_code,
                    location_name,
                    location_type,
                    building,
                    zone_area
                FROM locations
                WHERE active_status = 1
            ";
            $params = [];
            
            if ($location_type) {
                $sql .= " AND location_type = ?";
                $params[] = $location_type;
            }
            
            $sql .= " ORDER BY location_type, location_code";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $locations = $stmt->fetchAll();
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $locations
            ]);
            
        } catch (Exception $e) {
            error_log('Get dropdown locations error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve locations'
            ], 500);
        }
    }
    
    /**
     * Get location types for filtering
     * GET /api/v1/locations/types
     * 
     * @return array JSON response
     */
    public function getLocationTypes() {
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
                    location_type,
                    COUNT(*) as location_count,
                    COUNT(CASE WHEN active_status = 1 THEN 1 END) as active_count
                FROM locations
                GROUP BY location_type
                ORDER BY location_type
            ");
            $stmt->execute();
            $types = $stmt->fetchAll();
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $types
            ]);
            
        } catch (Exception $e) {
            error_log('Get location types error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve location types'
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
                VALUES (?, ?, 'locations', ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $clockNumber,
                $action,
                $details['location_id'] ?? null,
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