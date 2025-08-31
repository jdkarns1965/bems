<?php
/**
 * BEMS MRP Controller
 * Best ERP Manufacturing System
 * 
 * Handles Material Requirements Planning (MRP) API endpoints for planning runs,
 * requirements analysis, and planned order management.
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__) . '/middleware/AuthMiddleware.php';
require_once dirname(__DIR__) . '/services/MrpService.php';

class MrpController {
    
    private $db;
    private $authMiddleware;
    private $mrpService;
    
    public function __construct() {
        $this->db = DatabaseConfig::getConnection();
        $this->authMiddleware = new AuthMiddleware();
        $this->mrpService = new MrpService();
    }
    
    /**
     * Execute MRP planning run
     * POST /api/v1/mrp/run
     * 
     * @param array $input Request input data
     * @return array JSON response
     */
    public function executePlanningRun($input) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            // Set default parameters
            $parameters = [
                'plan_version' => $input['plan_version'] ?? 'ACTIVE',
                'run_type' => $input['run_type'] ?? 'FULL_REGENERATIVE',
                'planning_horizon_days' => $input['planning_horizon_days'] ?? 365,
                'planning_start_date' => $input['planning_start_date'] ?? date('Y-m-d'),
                'planning_end_date' => $input['planning_end_date'] ?? date('Y-m-d', strtotime('+365 days'))
            ];
            
            $result = $this->mrpService->executeMrpRun($parameters, $user['clock_number']);
            
            if ($result['success']) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'MRP planning run completed successfully',
                    'data' => $result
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'MRP planning run failed: ' . $result['error'],
                    'data' => $result
                ], 500);
            }
            
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to execute MRP run: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get MRP requirements summary
     * GET /api/v1/mrp/requirements
     * 
     * @return array JSON response
     */
    public function getRequirements() {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            $filters = [
                'planning_run_id' => $_GET['planning_run_id'] ?? null,
                'item_number' => $_GET['item_number'] ?? null,
                'action_required' => $_GET['action_required'] ?? null,
                'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 100
            ];
            
            $requirements = $this->mrpService->getMrpRequirementsSummary($filters);
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $requirements,
                'count' => count($requirements)
            ]);
            
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve MRP requirements: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get planned orders summary
     * GET /api/v1/mrp/planned-orders
     * 
     * @return array JSON response
     */
    public function getPlannedOrders() {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            $filters = [
                'item_number' => $_GET['item_number'] ?? null,
                'order_type' => $_GET['order_type'] ?? null,
                'days_ahead' => isset($_GET['days_ahead']) ? (int)$_GET['days_ahead'] : null,
                'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 100
            ];
            
            $plannedOrders = $this->mrpService->getPlannedOrdersSummary($filters);
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $plannedOrders,
                'count' => count($plannedOrders)
            ]);
            
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve planned orders: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get MRP action messages
     * GET /api/v1/mrp/action-messages
     * 
     * @return array JSON response
     */
    public function getActionMessages() {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            $filters = [
                'planning_run_id' => $_GET['planning_run_id'] ?? null,
                'action_required' => $_GET['action_required'] ?? null,
                'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 50
            ];
            
            $actionMessages = $this->mrpService->getMrpActionMessages($filters);
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $actionMessages,
                'count' => count($actionMessages)
            ]);
            
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve action messages: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get latest planning run status
     * GET /api/v1/mrp/latest-run
     * 
     * @return array JSON response
     */
    public function getLatestRun() {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            $planVersion = $_GET['plan_version'] ?? 'ACTIVE';
            $latestRun = $this->mrpService->getLatestPlanningRun($planVersion);
            
            if ($latestRun) {
                return $this->jsonResponse([
                    'success' => true,
                    'data' => $latestRun
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'No completed MRP runs found',
                    'data' => null
                ], 404);
            }
            
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve latest run: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Create/Update Master Production Schedule entry
     * POST /api/v1/mrp/mps
     * 
     * @param array $input Request input data
     * @return array JSON response
     */
    public function createMpsEntry($input) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            // Validate required fields
            $required = ['item_number', 'demand_date', 'planned_quantity'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ], 400);
                }
            }
            
            // Validate item exists
            if (!$this->materialExists($input['item_number'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Item does not exist in materials master'
                ], 400);
            }
            
            // Validate quantity
            if ($input['planned_quantity'] <= 0) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Planned quantity must be greater than zero'
                ], 400);
            }
            
            $sql = "INSERT INTO master_production_schedule (
                item_number, plan_version, planning_period_start, planning_period_end,
                demand_date, planned_quantity, firm_planned_flag, priority_code,
                customer_order_ref, notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                planned_quantity = VALUES(planned_quantity),
                firm_planned_flag = VALUES(firm_planned_flag),
                priority_code = VALUES(priority_code),
                customer_order_ref = VALUES(customer_order_ref),
                notes = VALUES(notes),
                updated_by = VALUES(created_by),
                updated_at = CURRENT_TIMESTAMP";
            
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([
                $input['item_number'],
                $input['plan_version'] ?? 'ACTIVE',
                $input['planning_period_start'] ?? date('Y-m-d'),
                $input['planning_period_end'] ?? date('Y-m-d', strtotime('+30 days')),
                $input['demand_date'],
                $input['planned_quantity'],
                isset($input['firm_planned_flag']) ? (bool)$input['firm_planned_flag'] : false,
                $input['priority_code'] ?? 'NORMAL',
                $input['customer_order_ref'] ?? null,
                $input['notes'] ?? null,
                $user['clock_number']
            ]);
            
            if ($result) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'MPS entry created/updated successfully',
                    'data' => ['mps_id' => $this->db->lastInsertId()]
                ], 201);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Failed to create MPS entry'
                ], 500);
            }
            
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to create MPS entry: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get Master Production Schedule
     * GET /api/v1/mrp/mps
     * 
     * @return array JSON response
     */
    public function getMasterProductionSchedule() {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            $planVersion = $_GET['plan_version'] ?? 'ACTIVE';
            $startDate = $_GET['start_date'] ?? date('Y-m-d');
            $endDate = $_GET['end_date'] ?? date('Y-m-d', strtotime('+30 days'));
            
            $sql = "SELECT 
                mps.*,
                m.material_name,
                m.material_description,
                m.base_unit
            FROM master_production_schedule mps
            LEFT JOIN materials m ON mps.item_number = m.material_number
            WHERE mps.plan_version = ?
              AND mps.demand_date BETWEEN ? AND ?
            ORDER BY mps.demand_date, mps.priority_code DESC, mps.item_number";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$planVersion, $startDate, $endDate]);
            $mpsEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $mpsEntries,
                'count' => count($mpsEntries),
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'plan_version' => $planVersion
                ]
            ]);
            
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve MPS: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update/Create MRP planning parameters
     * POST /api/v1/mrp/planning-parameters
     * 
     * @param array $input Request input data
     * @return array JSON response
     */
    public function updatePlanningParameters($input) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            if (empty($input['item_number'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Item number is required'
                ], 400);
            }
            
            // Validate item exists
            if (!$this->materialExists($input['item_number'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Item does not exist in materials master'
                ], 400);
            }
            
            $sql = "INSERT INTO mrp_planning_parameters (
                item_number, planning_method, lot_sizing_rule, fixed_lot_size,
                minimum_order_quantity, maximum_order_quantity, order_multiple,
                safety_stock_quantity, safety_lead_time_days, planning_lead_time_days,
                yield_percentage, shrinkage_percentage, make_buy_code, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                planning_method = VALUES(planning_method),
                lot_sizing_rule = VALUES(lot_sizing_rule),
                fixed_lot_size = VALUES(fixed_lot_size),
                minimum_order_quantity = VALUES(minimum_order_quantity),
                maximum_order_quantity = VALUES(maximum_order_quantity),
                order_multiple = VALUES(order_multiple),
                safety_stock_quantity = VALUES(safety_stock_quantity),
                safety_lead_time_days = VALUES(safety_lead_time_days),
                planning_lead_time_days = VALUES(planning_lead_time_days),
                yield_percentage = VALUES(yield_percentage),
                shrinkage_percentage = VALUES(shrinkage_percentage),
                make_buy_code = VALUES(make_buy_code),
                updated_by = VALUES(created_by),
                updated_at = CURRENT_TIMESTAMP";
            
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([
                $input['item_number'],
                $input['planning_method'] ?? 'MRP',
                $input['lot_sizing_rule'] ?? 'LOT_FOR_LOT',
                $input['fixed_lot_size'] ?? null,
                $input['minimum_order_quantity'] ?? 0,
                $input['maximum_order_quantity'] ?? null,
                $input['order_multiple'] ?? 1,
                $input['safety_stock_quantity'] ?? 0,
                $input['safety_lead_time_days'] ?? 0,
                $input['planning_lead_time_days'] ?? 0,
                $input['yield_percentage'] ?? 100.00,
                $input['shrinkage_percentage'] ?? 0.00,
                $input['make_buy_code'] ?? 'BUY',
                $user['clock_number']
            ]);
            
            if ($result) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Planning parameters updated successfully'
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Failed to update planning parameters'
                ], 500);
            }
            
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to update planning parameters: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get MRP planning parameters
     * GET /api/v1/mrp/planning-parameters
     * 
     * @return array JSON response
     */
    public function getPlanningParameters() {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            $itemNumber = $_GET['item_number'] ?? null;
            
            $sql = "SELECT 
                pp.*,
                m.material_name,
                m.material_description,
                m.base_unit
            FROM mrp_planning_parameters pp
            LEFT JOIN materials m ON pp.item_number = m.material_number
            WHERE pp.active_flag = TRUE";
            
            $params = [];
            
            if ($itemNumber) {
                $sql .= " AND pp.item_number = ?";
                $params[] = $itemNumber;
            }
            
            $sql .= " ORDER BY pp.item_number";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $parameters = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $parameters,
                'count' => count($parameters)
            ]);
            
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve planning parameters: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Firm planned order
     * POST /api/v1/mrp/firm-order/{order_id}
     * 
     * @param int $orderId Planned order ID
     * @return array JSON response
     */
    public function firmPlannedOrder($orderId) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            $sql = "UPDATE planned_orders 
                    SET firm_flag = TRUE, status = 'FIRMED', updated_by = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([$user['clock_number'], $orderId]);
            
            if ($result && $stmt->rowCount() > 0) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Planned order firmed successfully'
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Planned order not found or already firmed'
                ], 404);
            }
            
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to firm planned order: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Helper: Check if material exists
     */
    private function materialExists($materialNumber) {
        $sql = "SELECT COUNT(*) FROM materials WHERE material_number = ? AND active_status = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$materialNumber]);
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Helper: Format JSON response
     */
    private function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        return $data;
    }
}
?>