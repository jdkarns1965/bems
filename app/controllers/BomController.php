<?php
/**
 * BEMS BOM Controller
 * Best ERP Manufacturing System
 * 
 * Handles Bill of Materials (BOM) management API endpoints for creating,
 * reading, updating BOM structures with multi-level explosion capabilities.
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__) . '/middleware/AuthMiddleware.php';
require_once dirname(__DIR__) . '/services/BomService.php';

class BomController {
    
    private $db;
    private $authMiddleware;
    private $bomService;
    
    public function __construct() {
        $this->db = DatabaseConfig::getConnection();
        $this->authMiddleware = new AuthMiddleware();
        $this->bomService = new BomService();
    }
    
    /**
     * Create a new BOM
     * POST /api/v1/bom/create
     * 
     * @param array $input Request input data
     * @return array JSON response
     */
    public function create($input) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            // Validate required fields
            $required = ['bom_number', 'parent_material_number', 'description', 'base_quantity'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ], 400);
                }
            }
            
            // Validate parent material exists
            if (!$this->materialExists($input['parent_material_number'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Parent material does not exist'
                ], 400);
            }
            
            // Check for duplicate BOM number
            if ($this->bomNumberExists($input['bom_number'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'BOM number already exists'
                ], 409);
            }
            
            $result = $this->bomService->createBom($input, $user['clock_number']);
            
            if ($result['success']) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'BOM created successfully',
                    'data' => ['bom_id' => $result['bom_id']]
                ], 201);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $result['message']
                ], 400);
            }
            
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to create BOM: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get BOM structure with components
     * GET /api/v1/bom/structure/{bom_id}
     * 
     * @param int $bomId BOM ID
     * @return array JSON response
     */
    public function getStructure($bomId) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            if (!is_numeric($bomId)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Invalid BOM ID'
                ], 400);
            }
            
            $structure = $this->bomService->getBomStructure($bomId);
            
            if ($structure) {
                return $this->jsonResponse([
                    'success' => true,
                    'data' => $structure
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'BOM not found'
                ], 404);
            }
            
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve BOM structure: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Add component to BOM
     * POST /api/v1/bom/add-component
     * 
     * @param array $input Request input data
     * @return array JSON response
     */
    public function addComponent($input) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            // Validate required fields
            $required = ['bom_id', 'component_material_number', 'required_quantity', 'line_number'];
            foreach ($required as $field) {
                if (!isset($input[$field]) || $input[$field] === '') {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ], 400);
                }
            }
            
            // Validate component material exists
            if (!$this->materialExists($input['component_material_number'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Component material does not exist'
                ], 400);
            }
            
            // Check for circular reference
            if ($this->wouldCreateCircularReference($input['bom_id'], $input['component_material_number'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Cannot add component: would create circular reference'
                ], 400);
            }
            
            $result = $this->bomService->addBomComponent($input, $user['clock_number']);
            
            if ($result['success']) {
                // Clear explosion cache for this BOM
                $this->bomService->clearExplosionCache($input['bom_id']);
                
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Component added successfully',
                    'data' => ['line_id' => $result['line_id']]
                ], 201);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $result['message']
                ], 400);
            }
            
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to add component: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update BOM component
     * PUT /api/v1/bom/update-component
     * 
     * @param array $input Request input data
     * @return array JSON response
     */
    public function updateComponent($input) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            if (empty($input['line_id'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Missing required field: line_id'
                ], 400);
            }
            
            $result = $this->bomService->updateBomComponent($input, $user['clock_number']);
            
            if ($result['success']) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Component updated successfully'
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $result['message']
                ], 400);
            }
            
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to update component: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Remove component from BOM
     * DELETE /api/v1/bom/remove-component/{line_id}
     * 
     * @param int $lineId BOM line ID
     * @return array JSON response
     */
    public function removeComponent($lineId) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            if (!is_numeric($lineId)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Invalid line ID'
                ], 400);
            }
            
            $result = $this->bomService->removeBomComponent($lineId, $user['clock_number']);
            
            if ($result['success']) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Component removed successfully'
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $result['message']
                ], 404);
            }
            
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to remove component: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get BOM explosion (multi-level breakdown)
     * GET /api/v1/bom/explosion/{parent_material}
     * 
     * @param string $parentMaterial Parent material number
     * @return array JSON response
     */
    public function getExplosion($parentMaterial) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            $quantity = $_GET['quantity'] ?? 1;
            $levels = $_GET['levels'] ?? 10; // Max levels to explode
            
            if (!is_numeric($quantity) || $quantity <= 0) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Invalid quantity parameter'
                ], 400);
            }
            
            $explosion = $this->bomService->explodeBom($parentMaterial, $quantity, $levels);
            
            return $this->jsonResponse([
                'success' => true,
                'data' => [
                    'parent_material' => $parentMaterial,
                    'explosion_quantity' => $quantity,
                    'max_levels' => $levels,
                    'components' => $explosion
                ]
            ]);
            
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to explode BOM: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get cost rollup for BOM
     * GET /api/v1/bom/cost-rollup/{bom_id}
     * 
     * @param int $bomId BOM ID
     * @return array JSON response
     */
    public function getCostRollup($bomId) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            if (!is_numeric($bomId)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Invalid BOM ID'
                ], 400);
            }
            
            $costRollup = $this->bomService->calculateCostRollup($bomId);
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $costRollup
            ]);
            
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to calculate cost rollup: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * List all BOMs with optional filtering
     * GET /api/v1/bom/list
     * 
     * @return array JSON response
     */
    public function listBoms() {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            $filters = [
                'status' => $_GET['status'] ?? null,
                'parent_material' => $_GET['parent_material'] ?? null,
                'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 50,
                'offset' => isset($_GET['offset']) ? (int)$_GET['offset'] : 0
            ];
            
            $boms = $this->bomService->listBoms($filters);
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $boms
            ]);
            
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve BOMs: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Helper: Check if material exists
     */
    private function materialExists($materialNumber) {
        $sql = "SELECT COUNT(*) FROM materials WHERE material_number = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$materialNumber]);
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Helper: Check if BOM number exists
     */
    private function bomNumberExists($bomNumber) {
        $sql = "SELECT COUNT(*) FROM bom_headers WHERE bom_number = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$bomNumber]);
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Helper: Check for circular reference
     */
    private function wouldCreateCircularReference($bomId, $componentMaterial) {
        // Get parent material for this BOM
        $sql = "SELECT parent_material_number FROM bom_headers WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$bomId]);
        $parentMaterial = $stmt->fetchColumn();
        
        if (!$parentMaterial) {
            return false;
        }
        
        // Check if component material has a BOM that uses parent material
        return $this->bomService->hasCircularReference($parentMaterial, $componentMaterial);
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