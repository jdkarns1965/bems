<?php
/**
 * BEMS Product Controller
 * Best ERP Manufacturing System
 * 
 * Handles product/finished goods management with multi-level part number
 * cross-referencing for automotive supply chain requirements.
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__) . '/middleware/AuthMiddleware.php';
require_once dirname(__DIR__) . '/models/Product.php';

class ProductController {
    
    private $db;
    private $authMiddleware;
    private $productModel;
    
    public function __construct() {
        $this->db = DatabaseConfig::getConnection();
        $this->authMiddleware = new AuthMiddleware();
        $this->productModel = new Product();
    }
    
    /**
     * Get all products with part number cross-references
     * GET /api/v1/products
     * 
     * @return array JSON response
     */
    public function getProducts() {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            // Get query parameters
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? 'active';
            $family = $_GET['family'] ?? '';
            $limit = min(max((int)($_GET['limit'] ?? 100), 1), 500);
            $offset = max((int)($_GET['offset'] ?? 0), 0);
            
            // Build query with cross-reference joins
            $query = "
                SELECT DISTINCT p.*, 
                       GROUP_CONCAT(
                           DISTINCT CONCAT(
                               ppn.part_number_type, ':', ppn.part_number_value,
                               CASE 
                                   WHEN ppn.customer_name IS NOT NULL THEN CONCAT(' (', ppn.customer_name, ')')
                                   WHEN ppn.oem_name IS NOT NULL THEN CONCAT(' (', ppn.oem_name, ')')
                                   ELSE ''
                               END
                           ) 
                           ORDER BY ppn.priority DESC 
                           SEPARATOR '; '
                       ) AS all_part_numbers,
                       MAX(CASE WHEN ppn.part_number_type = 'customer' AND ppn.is_primary = TRUE 
                           THEN ppn.part_number_value END) AS primary_customer_part,
                       MAX(CASE WHEN ppn.part_number_type = 'oem' AND ppn.is_primary = TRUE 
                           THEN ppn.part_number_value END) AS primary_oem_part
                FROM products p
                LEFT JOIN product_part_numbers ppn ON p.internal_part_number = ppn.internal_part_number
                    AND (ppn.expiry_date IS NULL OR ppn.expiry_date >= CURDATE())
                    AND ppn.effective_date <= CURDATE()
                WHERE 1=1
            ";
            
            $params = [];
            
            // Add search condition
            if ($search) {
                $query .= " AND (p.internal_part_number LIKE :search 
                           OR p.product_name LIKE :search 
                           OR p.product_description LIKE :search
                           OR ppn.part_number_value LIKE :search) ";
                $params[':search'] = '%' . $search . '%';
            }
            
            // Add status filter
            if ($status && $status !== 'all') {
                $query .= " AND p.product_status = :status ";
                $params[':status'] = $status;
            }
            
            // Add family filter
            if ($family) {
                $query .= " AND p.product_family = :family ";
                $params[':family'] = $family;
            }
            
            $query .= " GROUP BY p.internal_part_number ";
            
            // Add ordering for search relevance
            if ($search) {
                $query .= " ORDER BY 
                    CASE 
                        WHEN p.internal_part_number = :exact THEN 1
                        WHEN p.internal_part_number LIKE :starts THEN 2
                        WHEN p.product_name LIKE :starts THEN 3
                        WHEN ppn.part_number_value = :exact THEN 4
                        WHEN ppn.part_number_value LIKE :starts THEN 5
                        ELSE 6
                    END,
                    p.internal_part_number ";
                $params[':exact'] = $search;
                $params[':starts'] = $search . '%';
            } else {
                $query .= " ORDER BY p.created_at DESC ";
            }
            
            $query .= " LIMIT :limit OFFSET :offset";
            
            $stmt = $this->db->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count for pagination
            $countQuery = "SELECT COUNT(DISTINCT p.internal_part_number) as total 
                          FROM products p 
                          LEFT JOIN product_part_numbers ppn ON p.internal_part_number = ppn.internal_part_number
                          WHERE 1=1";
            
            if ($search) {
                $countQuery .= " AND (p.internal_part_number LIKE :search 
                               OR p.product_name LIKE :search 
                               OR ppn.part_number_value LIKE :search) ";
            }
            if ($status && $status !== 'all') {
                $countQuery .= " AND p.product_status = :status ";
            }
            if ($family) {
                $countQuery .= " AND p.product_family = :family ";
            }
            
            $countStmt = $this->db->prepare($countQuery);
            foreach ($params as $key => $value) {
                if ($key !== ':limit' && $key !== ':offset' && $key !== ':exact' && $key !== ':starts') {
                    $countStmt->bindValue($key, $value);
                }
            }
            $countStmt->execute();
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $products,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            
        } catch (Exception $e) {
            error_log('Error in getProducts: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve products',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get single product by internal part number
     * GET /api/v1/products/{internal_part_number}
     * 
     * @param string $internalPartNumber Internal part number
     * @return array JSON response
     */
    public function getProduct($internalPartNumber) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            // Get product with all cross-references
            $query = "
                SELECT p.*,
                       GROUP_CONCAT(
                           DISTINCT CONCAT(
                               ppn.part_number_type, ':', ppn.part_number_value,
                               CASE 
                                   WHEN ppn.customer_name IS NOT NULL THEN CONCAT(' (', ppn.customer_name, ')')
                                   WHEN ppn.oem_name IS NOT NULL THEN CONCAT(' (', ppn.oem_name, ')')
                                   ELSE ''
                               END
                           ) 
                           ORDER BY ppn.priority DESC 
                           SEPARATOR '; '
                       ) AS all_part_numbers
                FROM products p
                LEFT JOIN product_part_numbers ppn ON p.internal_part_number = ppn.internal_part_number
                    AND (ppn.expiry_date IS NULL OR ppn.expiry_date >= CURDATE())
                    AND ppn.effective_date <= CURDATE()
                WHERE p.internal_part_number = :internal_part_number
                GROUP BY p.internal_part_number
            ";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':internal_part_number', $internalPartNumber);
            $stmt->execute();
            
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Product not found'
                ], 404);
            }
            
            // Get detailed part numbers
            $partNumberQuery = "
                SELECT * FROM product_part_numbers 
                WHERE internal_part_number = :internal_part_number
                  AND (expiry_date IS NULL OR expiry_date >= CURDATE())
                ORDER BY priority DESC, part_number_type
            ";
            
            $partStmt = $this->db->prepare($partNumberQuery);
            $partStmt->bindParam(':internal_part_number', $internalPartNumber);
            $partStmt->execute();
            
            $product['part_numbers'] = $partStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get specifications if any
            $specQuery = "
                SELECT * FROM product_specifications 
                WHERE internal_part_number = :internal_part_number
                  AND (expiry_date IS NULL OR expiry_date >= CURDATE())
                ORDER BY specification_type, specification_name
            ";
            
            $specStmt = $this->db->prepare($specQuery);
            $specStmt->bindParam(':internal_part_number', $internalPartNumber);
            $specStmt->execute();
            
            $product['specifications'] = $specStmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $product
            ]);
            
        } catch (Exception $e) {
            error_log('Error in getProduct: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve product',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Create new product with part number cross-references
     * POST /api/v1/products
     * 
     * @param array $input Request input data
     * @return array JSON response
     */
    public function createProduct($input) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            // Validate required fields
            if (empty($input['product_name'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Product name is required'
                ], 400);
            }
            
            $this->db->beginTransaction();
            
            try {
                // Generate internal part number if not provided
                $internalPartNumber = $input['internal_part_number'] ?? null;
                if (empty($internalPartNumber)) {
                    // Call the stored function to generate part number
                    $stmt = $this->db->prepare("SELECT fn_get_next_product_number(:type) as part_number");
                    $type = $input['product_type'] ?? 'STANDARD';
                    $stmt->bindParam(':type', $type);
                    $stmt->execute();
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $internalPartNumber = $result['part_number'];
                }
                
                // Insert main product record (only existing columns)
                $insertQuery = "
                    INSERT INTO products (
                        internal_part_number, product_name, product_description,
                        product_family, base_unit, standard_pack_quantity,
                        weight_per_unit, product_status, standard_cost, 
                        standard_price, created_by
                    ) VALUES (
                        :internal_part_number, :product_name, :product_description,
                        :product_family, :base_unit, :standard_pack_quantity,
                        :weight_per_unit, :product_status, :standard_cost, 
                        :standard_price, :created_by
                    )
                ";
                
                $stmt = $this->db->prepare($insertQuery);
                $stmt->execute([
                    ':internal_part_number' => $internalPartNumber,
                    ':product_name' => $input['product_name'],
                    ':product_description' => $input['product_description'] ?? null,
                    ':product_family' => $input['product_family'] ?? null,
                    ':base_unit' => $input['base_unit'] ?? 'EA',
                    ':standard_pack_quantity' => $input['standard_pack_quantity'] ?? 1.00,
                    ':weight_per_unit' => $input['weight_per_unit'] ?? null,
                    ':product_status' => $input['product_status'] ?? 'active',
                    ':standard_cost' => $input['standard_cost'] ?? null,
                    ':standard_price' => $input['standard_price'] ?? null,
                    ':created_by' => $user['clock_number']
                ]);
                
                // Insert part number cross-references if provided
                if (!empty($input['part_numbers']) && is_array($input['part_numbers'])) {
                    $partNumberQuery = "
                        INSERT INTO product_part_numbers (
                            internal_part_number, part_number_type, part_number_value,
                            customer_name, oem_name, priority, is_primary,
                            effective_date, usage_context, notes, created_by
                        ) VALUES (
                            :internal_part_number, :part_number_type, :part_number_value,
                            :customer_name, :oem_name, :priority, :is_primary,
                            :effective_date, :usage_context, :notes, :created_by
                        )
                    ";
                    
                    $partStmt = $this->db->prepare($partNumberQuery);
                    
                    foreach ($input['part_numbers'] as $partNumber) {
                        if (!empty($partNumber['part_number_value']) && !empty($partNumber['part_number_type'])) {
                            $partStmt->execute([
                                ':internal_part_number' => $internalPartNumber,
                                ':part_number_type' => $partNumber['part_number_type'],
                                ':part_number_value' => $partNumber['part_number_value'],
                                ':customer_name' => $partNumber['customer_name'] ?? null,
                                ':oem_name' => $partNumber['oem_name'] ?? null,
                                ':priority' => $partNumber['priority'] ?? 50,
                                ':is_primary' => !empty($partNumber['is_primary']) ? 1 : 0,
                                ':effective_date' => $partNumber['effective_date'] ?? date('Y-m-d'),
                                ':usage_context' => $partNumber['usage_context'] ?? 'all',
                                ':notes' => $partNumber['notes'] ?? null,
                                ':created_by' => $user['clock_number']
                            ]);
                        }
                    }
                }
                
                // Insert specifications if provided
                if (!empty($input['specifications']) && is_array($input['specifications'])) {
                    $specQuery = "
                        INSERT INTO product_specifications (
                            internal_part_number, specification_type, specification_name,
                            specification_value, tolerance, measurement_unit,
                            test_method, is_critical, is_customer_requirement,
                            effective_date, notes, created_by
                        ) VALUES (
                            :internal_part_number, :specification_type, :specification_name,
                            :specification_value, :tolerance, :measurement_unit,
                            :test_method, :is_critical, :is_customer_requirement,
                            :effective_date, :notes, :created_by
                        )
                    ";
                    
                    $specStmt = $this->db->prepare($specQuery);
                    
                    foreach ($input['specifications'] as $spec) {
                        if (!empty($spec['specification_name']) && !empty($spec['specification_value'])) {
                            $specStmt->execute([
                                ':internal_part_number' => $internalPartNumber,
                                ':specification_type' => $spec['specification_type'] ?? 'quality',
                                ':specification_name' => $spec['specification_name'],
                                ':specification_value' => $spec['specification_value'],
                                ':tolerance' => $spec['tolerance'] ?? null,
                                ':measurement_unit' => $spec['measurement_unit'] ?? null,
                                ':test_method' => $spec['test_method'] ?? null,
                                ':is_critical' => !empty($spec['is_critical']) ? 1 : 0,
                                ':is_customer_requirement' => !empty($spec['is_customer_requirement']) ? 1 : 0,
                                ':effective_date' => $spec['effective_date'] ?? date('Y-m-d'),
                                ':notes' => $spec['notes'] ?? null,
                                ':created_by' => $user['clock_number']
                            ]);
                        }
                    }
                }
                
                // Log audit trail
                $this->logAudit('CREATE_PRODUCT', $internalPartNumber, $user['clock_number'], 
                    json_encode(['product_name' => $input['product_name']]));
                
                $this->db->commit();
                
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Product created successfully',
                    'internal_part_number' => $internalPartNumber
                ], 201);
                
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            error_log('Error in createProduct: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to create product',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update existing product
     * PUT /api/v1/products/{internal_part_number}
     * 
     * @param string $internalPartNumber Internal part number
     * @param array $input Request input data
     * @return array JSON response
     */
    public function updateProduct($internalPartNumber, $input) {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            // Check if product exists
            $checkQuery = "SELECT * FROM products WHERE internal_part_number = :internal_part_number";
            $checkStmt = $this->db->prepare($checkQuery);
            $checkStmt->bindParam(':internal_part_number', $internalPartNumber);
            $checkStmt->execute();
            
            if (!$checkStmt->fetch()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Product not found'
                ], 404);
            }
            
            $this->db->beginTransaction();
            
            try {
                // Build dynamic update query based on provided fields
                $updateFields = [];
                $updateParams = [':internal_part_number' => $internalPartNumber];
                
                $allowedFields = [
                    'product_name', 'product_description', 'product_family',
                    'base_unit', 'standard_pack_quantity', 'weight_per_unit',
                    'product_status', 'standard_cost', 'standard_price'
                ];
                
                foreach ($allowedFields as $field) {
                    if (isset($input[$field])) {
                        $updateFields[] = "$field = :$field";
                        $updateParams[":$field"] = $input[$field];
                    }
                }
                
                if (!empty($updateFields)) {
                    $updateFields[] = "updated_by = :updated_by";
                    $updateFields[] = "updated_at = NOW()";
                    $updateParams[':updated_by'] = $user['clock_number'];
                    
                    $updateQuery = "UPDATE products SET " . implode(', ', $updateFields) . 
                                  " WHERE internal_part_number = :internal_part_number";
                    
                    $updateStmt = $this->db->prepare($updateQuery);
                    $updateStmt->execute($updateParams);
                }
                
                // Update part numbers if provided
                if (isset($input['part_numbers']) && is_array($input['part_numbers'])) {
                    // This is a simplified approach - in production you might want to handle updates more granularly
                    // Delete existing part numbers
                    $deleteQuery = "DELETE FROM product_part_numbers WHERE internal_part_number = :internal_part_number";
                    $deleteStmt = $this->db->prepare($deleteQuery);
                    $deleteStmt->bindParam(':internal_part_number', $internalPartNumber);
                    $deleteStmt->execute();
                    
                    // Insert new part numbers
                    $partNumberQuery = "
                        INSERT INTO product_part_numbers (
                            internal_part_number, part_number_type, part_number_value,
                            customer_name, oem_name, priority, is_primary,
                            effective_date, usage_context, notes, created_by
                        ) VALUES (
                            :internal_part_number, :part_number_type, :part_number_value,
                            :customer_name, :oem_name, :priority, :is_primary,
                            :effective_date, :usage_context, :notes, :created_by
                        )
                    ";
                    
                    $partStmt = $this->db->prepare($partNumberQuery);
                    
                    foreach ($input['part_numbers'] as $partNumber) {
                        if (!empty($partNumber['part_number_value']) && !empty($partNumber['part_number_type'])) {
                            $partStmt->execute([
                                ':internal_part_number' => $internalPartNumber,
                                ':part_number_type' => $partNumber['part_number_type'],
                                ':part_number_value' => $partNumber['part_number_value'],
                                ':customer_name' => $partNumber['customer_name'] ?? null,
                                ':oem_name' => $partNumber['oem_name'] ?? null,
                                ':priority' => $partNumber['priority'] ?? 50,
                                ':is_primary' => !empty($partNumber['is_primary']) ? 1 : 0,
                                ':effective_date' => $partNumber['effective_date'] ?? date('Y-m-d'),
                                ':usage_context' => $partNumber['usage_context'] ?? 'all',
                                ':notes' => $partNumber['notes'] ?? null,
                                ':created_by' => $user['clock_number']
                            ]);
                        }
                    }
                }
                
                // Log audit trail
                $this->logAudit('UPDATE_PRODUCT', $internalPartNumber, $user['clock_number'], 
                    json_encode($input));
                
                $this->db->commit();
                
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Product updated successfully'
                ]);
                
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            error_log('Error in updateProduct: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to update product',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Search products by any part number
     * GET /api/v1/products/search
     * 
     * @return array JSON response
     */
    public function searchProducts() {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            $searchTerm = $_GET['q'] ?? '';
            
            if (empty($searchTerm)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Search term is required'
                ], 400);
            }
            
            // Search across all part number types and product fields
            $query = "
                SELECT DISTINCT 
                    p.*,
                    ppn.part_number_type as matched_type,
                    ppn.part_number_value as matched_value,
                    ppn.customer_name,
                    ppn.oem_name,
                    CASE
                        WHEN p.internal_part_number = :exact THEN 1
                        WHEN ppn.part_number_value = :exact THEN 2
                        WHEN p.internal_part_number LIKE :starts THEN 3
                        WHEN ppn.part_number_value LIKE :starts THEN 4
                        WHEN p.product_name LIKE :contains THEN 5
                        ELSE 6
                    END as relevance
                FROM products p
                LEFT JOIN product_part_numbers ppn ON p.internal_part_number = ppn.internal_part_number
                WHERE (
                    p.internal_part_number LIKE :contains
                    OR p.product_name LIKE :contains
                    OR p.product_description LIKE :contains
                    OR ppn.part_number_value LIKE :contains
                )
                AND p.product_status IN ('active', 'development')
                ORDER BY relevance, p.internal_part_number
                LIMIT 50
            ";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':exact', $searchTerm);
            $stmt->bindValue(':starts', $searchTerm . '%');
            $stmt->bindValue(':contains', '%' . $searchTerm . '%');
            $stmt->execute();
            
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $results,
                'count' => count($results),
                'search_term' => $searchTerm
            ]);
            
        } catch (Exception $e) {
            error_log('Error in searchProducts: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to search products',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get product families for dropdown
     * GET /api/v1/products/families
     * 
     * @return array JSON response
     */
    public function getProductFamilies() {
        try {
            $user = $this->authMiddleware->getCurrentUser();
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            $query = "SELECT DISTINCT product_family, COUNT(*) as count 
                     FROM products 
                     WHERE product_family IS NOT NULL 
                     GROUP BY product_family 
                     ORDER BY product_family";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            
            $families = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $families
            ]);
            
        } catch (Exception $e) {
            error_log('Error in getProductFamilies: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to retrieve product families',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Log audit trail
     * 
     * @param string $action Action performed
     * @param string $recordId Record identifier
     * @param string $userId User clock number
     * @param string $details JSON details
     */
    private function logAudit($action, $recordId, $userId, $details = null) {
        try {
            $query = "INSERT INTO audit_log (table_name, record_id, operation, changed_by, operation_timestamp, description) 
                     VALUES ('products', :record_id, :operation, :changed_by, NOW(), :description)";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':record_id' => $recordId,
                ':operation' => $action,
                ':changed_by' => $userId,
                ':description' => $details
            ]);
        } catch (Exception $e) {
            error_log('Audit log failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Create JSON response
     * 
     * @param array $data Response data
     * @param int $statusCode HTTP status code
     * @return array
     */
    private function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}