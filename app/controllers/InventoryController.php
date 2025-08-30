<?php
/**
 * BEMS Inventory Controller
 * Best ERP Manufacturing System
 * 
 * Handles inventory management API endpoints for receiving, moving, consuming,
 * and tracking materials with complete audit trail and FIFO logic.
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__) . '/middleware/AuthMiddleware.php';

class InventoryController {
    
    private $db;
    private $authMiddleware;
    
    public function __construct() {
        $this->db = DatabaseConfig::getConnection();
        $this->authMiddleware = new AuthMiddleware();
    }
    
    /**
     * Receive new materials into inventory
     * POST /api/v1/inventory/receive
     * 
     * @param array $input Request input data
     * @return array JSON response
     */
    public function receive($input) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            // Validate required fields
            $required = ['material_number', 'lot_number', 'quantity', 'unit_cost', 'location_code'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ], 400);
                }
            }
            
            // Validate numeric fields
            if (!is_numeric($input['quantity']) || $input['quantity'] <= 0) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Quantity must be a positive number'
                ], 400);
            }
            
            if (!is_numeric($input['unit_cost']) || $input['unit_cost'] < 0) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Unit cost must be a non-negative number'
                ], 400);
            }
            
            // Use stored procedure to receive inventory
            $stmt = $this->db->prepare("CALL sp_receive_inventory(?, ?, ?, ?, ?, ?, ?, ?, ?, @result, @inv_tag)");
            $stmt->execute([
                $input['material_number'],
                $input['lot_number'],
                floatval($input['quantity']),
                floatval($input['unit_cost']),
                $input['location_code'],
                $input['supplier_name'] ?? null,
                $input['purchase_order'] ?? null,
                $user['clock_number'],
                $input['notes'] ?? 'Material received via API'
            ]);
            
            // Get results
            $result = $this->db->query("SELECT @result as result, @inv_tag as inv_tag")->fetch();
            
            if ($result['result'] === 'SUCCESS') {
                // Log audit trail
                $this->logAuditAction('INVENTORY_RECEIVE', [
                    'inv_tag' => $result['inv_tag'],
                    'material_number' => $input['material_number'],
                    'quantity' => $input['quantity'],
                    'location_code' => $input['location_code']
                ], $user['clock_number']);
                
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Material received successfully',
                    'data' => [
                        'inv_tag' => $result['inv_tag'],
                        'material_number' => $input['material_number'],
                        'lot_number' => $input['lot_number'],
                        'quantity' => floatval($input['quantity']),
                        'location_code' => $input['location_code']
                    ]
                ], 201);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $result['result']
                ], 400);
            }
            
        } catch (Exception $e) {
            error_log('Inventory receive error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to receive material'
            ], 500);
        }
    }
    
    /**
     * List all inventory with filters
     * GET /api/v1/inventory
     * 
     * @return array JSON response
     */
    public function getInventory() {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            // Get query parameters
            $material_number = $_GET['material_number'] ?? null;
            $location_code = $_GET['location_code'] ?? null;
            $status = $_GET['status'] ?? null;
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
            $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
            
            // Build query
            $sql = "SELECT * FROM v_inventory_summary WHERE 1=1";
            $params = [];
            
            if ($material_number) {
                $sql .= " AND material_number = ?";
                $params[] = $material_number;
            }
            
            if ($location_code) {
                $sql .= " AND location_code = ?";
                $params[] = $location_code;
            }
            
            if ($status) {
                $sql .= " AND status = ?";
                $params[] = $status;
            }
            
            $sql .= " ORDER BY received_date DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $inventory = $stmt->fetchAll();
            
            // Get total count for pagination
            $countSql = "SELECT COUNT(*) as total FROM v_inventory_summary WHERE 1=1";
            $countParams = [];
            
            if ($material_number) {
                $countSql .= " AND material_number = ?";
                $countParams[] = $material_number;
            }
            
            if ($location_code) {
                $countSql .= " AND location_code = ?";
                $countParams[] = $location_code;
            }
            
            if ($status) {
                $countSql .= " AND status = ?";
                $countParams[] = $status;
            }
            
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($countParams);
            $total = $countStmt->fetch()['total'];
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $inventory,
                'pagination' => [
                    'total' => intval($total),
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $total
                ]
            ]);
            
        } catch (Exception $e) {
            error_log('Get inventory error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve inventory'
            ], 500);
        }
    }
    
    /**
     * Get specific inventory details by INV tag
     * GET /api/v1/inventory/{inv_tag}
     * 
     * @param string $inv_tag INV tag to lookup
     * @return array JSON response
     */
    public function getInventoryByTag($inv_tag) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            $stmt = $this->db->prepare("SELECT * FROM v_inventory_summary WHERE inv_tag = ?");
            $stmt->execute([$inv_tag]);
            $inventory = $stmt->fetch();
            
            if (!$inventory) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Inventory not found'
                ], 404);
            }
            
            // Get movement history
            $movementStmt = $this->db->prepare("
                SELECT * FROM v_recent_inventory_movements 
                WHERE inv_tag = ? 
                ORDER BY movement_time DESC
            ");
            $movementStmt->execute([$inv_tag]);
            $movements = $movementStmt->fetchAll();
            
            return $this->jsonResponse([
                'success' => true,
                'data' => [
                    'inventory' => $inventory,
                    'movements' => $movements
                ]
            ]);
            
        } catch (Exception $e) {
            error_log('Get inventory by tag error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve inventory'
            ], 500);
        }
    }
    
    /**
     * Move inventory to new location
     * POST /api/v1/inventory/{inv_tag}/move
     * 
     * @param string $inv_tag INV tag to move
     * @param array $input Request input data
     * @return array JSON response
     */
    public function moveInventory($inv_tag, $input) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            // Validate required fields - either location_code or manual_location
            if (empty($input['to_location_code']) && empty($input['to_location_manual'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Either to_location_code or to_location_manual is required'
                ], 400);
            }
            
            if (empty($input['quantity']) || !is_numeric($input['quantity']) || $input['quantity'] <= 0) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Valid quantity is required'
                ], 400);
            }
            
            // Use stored procedure to transfer inventory
            $stmt = $this->db->prepare("CALL sp_transfer_inventory(?, ?, ?, ?, ?, ?, ?, @result)");
            $stmt->execute([
                $inv_tag,
                $input['to_location_code'] ?? null,
                $input['to_location_manual'] ?? null,
                floatval($input['quantity']),
                $input['reference_number'] ?? null,
                $input['reason'] ?? 'Material transfer via API',
                $user['clock_number']
            ]);
            
            // Get result
            $result = $this->db->query("SELECT @result as result")->fetch();
            
            if ($result['result'] === 'SUCCESS') {
                // Log audit trail
                $this->logAuditAction('INVENTORY_TRANSFER', [
                    'inv_tag' => $inv_tag,
                    'quantity' => $input['quantity'],
                    'to_location_code' => $input['to_location_code'] ?? null,
                    'to_location_manual' => $input['to_location_manual'] ?? null,
                    'reference_number' => $input['reference_number'] ?? null
                ], $user['clock_number']);
                
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Inventory moved successfully',
                    'data' => [
                        'inv_tag' => $inv_tag,
                        'quantity' => floatval($input['quantity']),
                        'to_location_code' => $input['to_location_code'] ?? null,
                        'to_location_manual' => $input['to_location_manual'] ?? null
                    ]
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $result['result']
                ], 400);
            }
            
        } catch (Exception $e) {
            error_log('Move inventory error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to move inventory'
            ], 500);
        }
    }
    
    /**
     * Consume inventory for production
     * POST /api/v1/inventory/{inv_tag}/consume
     * 
     * @param string $inv_tag INV tag to consume
     * @param array $input Request input data
     * @return array JSON response
     */
    public function consumeInventory($inv_tag, $input) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            // Validate required fields
            if (empty($input['quantity']) || !is_numeric($input['quantity']) || $input['quantity'] <= 0) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Valid quantity is required'
                ], 400);
            }
            
            // Use stored procedure to consume inventory
            $stmt = $this->db->prepare("CALL sp_consume_inventory(?, ?, ?, ?, ?, ?, ?, @result)");
            $stmt->execute([
                $inv_tag,
                floatval($input['quantity']),
                $input['work_order'] ?? null,
                $input['job_number'] ?? null,
                $input['machine_press'] ?? null,
                $input['operator_initials'] ?? null,
                $user['clock_number']
            ]);
            
            // Get result
            $result = $this->db->query("SELECT @result as result")->fetch();
            
            if ($result['result'] === 'SUCCESS') {
                // Log audit trail
                $this->logAuditAction('INVENTORY_CONSUME', [
                    'inv_tag' => $inv_tag,
                    'quantity' => $input['quantity'],
                    'work_order' => $input['work_order'] ?? null,
                    'job_number' => $input['job_number'] ?? null,
                    'machine_press' => $input['machine_press'] ?? null
                ], $user['clock_number']);
                
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Inventory consumed successfully',
                    'data' => [
                        'inv_tag' => $inv_tag,
                        'quantity' => floatval($input['quantity']),
                        'work_order' => $input['work_order'] ?? null,
                        'job_number' => $input['job_number'] ?? null
                    ]
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $result['result']
                ], 400);
            }
            
        } catch (Exception $e) {
            error_log('Consume inventory error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to consume inventory'
            ], 500);
        }
    }
    
    /**
     * Get FIFO available inventory for material
     * GET /api/v1/inventory/fifo/{material_number}
     * 
     * @param string $material_number Material number to lookup
     * @return array JSON response
     */
    public function getFifoInventory($material_number) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            $stmt = $this->db->prepare("
                SELECT * FROM v_fifo_available_inventory 
                WHERE material_number = ? AND available_quantity > 0
                ORDER BY fifo_priority
            ");
            $stmt->execute([$material_number]);
            $fifoInventory = $stmt->fetchAll();
            
            if (empty($fifoInventory)) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'No available inventory found for this material',
                    'data' => []
                ]);
            }
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $fifoInventory,
                'summary' => [
                    'total_lots' => count($fifoInventory),
                    'total_available_quantity' => array_sum(array_column($fifoInventory, 'available_quantity')),
                    'oldest_lot_date' => $fifoInventory[0]['received_date'] ?? null,
                    'next_expiration' => $fifoInventory[0]['expiration_date'] ?? null
                ]
            ]);
            
        } catch (Exception $e) {
            error_log('Get FIFO inventory error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve FIFO inventory'
            ], 500);
        }
    }
    
    /**
     * Get inventory summary by location
     * GET /api/v1/inventory/summary/location
     * 
     * @return array JSON response
     */
    public function getLocationSummary() {
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
                    location_code,
                    location_name,
                    location_type,
                    COUNT(*) as lot_count,
                    SUM(total_quantity) as total_quantity,
                    SUM(total_value) as total_value,
                    COUNT(CASE WHEN alert_status != 'NORMAL' THEN 1 END) as alert_count
                FROM v_inventory_summary
                WHERE status = 'AVAILABLE'
                GROUP BY location_code, location_name, location_type
                ORDER BY total_value DESC
            ");
            $stmt->execute();
            $summary = $stmt->fetchAll();
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $summary
            ]);
            
        } catch (Exception $e) {
            error_log('Get location summary error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve location summary'
            ], 500);
        }
    }
    
    /**
     * Get expiring inventory alerts
     * GET /api/v1/inventory/alerts/expiring
     * 
     * @return array JSON response
     */
    public function getExpiringAlerts() {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            $stmt = $this->db->prepare("
                SELECT * FROM v_expiring_inventory
                WHERE urgency_level IN ('EXPIRED', 'CRITICAL', 'WARNING')
                ORDER BY expiration_date ASC
            ");
            $stmt->execute();
            $alerts = $stmt->fetchAll();
            
            // Group by urgency level
            $grouped = [
                'EXPIRED' => [],
                'CRITICAL' => [],
                'WARNING' => []
            ];
            
            foreach ($alerts as $alert) {
                $grouped[$alert['urgency_level']][] = $alert;
            }
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $grouped,
                'summary' => [
                    'expired_count' => count($grouped['EXPIRED']),
                    'critical_count' => count($grouped['CRITICAL']),
                    'warning_count' => count($grouped['WARNING']),
                    'total_alert_count' => count($alerts)
                ]
            ]);
            
        } catch (Exception $e) {
            error_log('Get expiring alerts error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve expiring alerts'
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
                VALUES (?, ?, 'inventory', ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $clockNumber,
                $action,
                $details['inv_tag'] ?? null,
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