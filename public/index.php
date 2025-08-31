<?php
/**
 * BEMS Main API Router
 * Best ERP Manufacturing System
 * 
 * Central routing system for all API endpoints with middleware support,
 * error handling, and security features
 */

// Set error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to users
ini_set('log_errors', 1);

// Set default timezone
date_default_timezone_set('America/New_York');

// Include required files
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/app/controllers/AuthController.php';
require_once dirname(__DIR__) . '/app/controllers/UserController.php';
require_once dirname(__DIR__) . '/app/controllers/InventoryController.php';
require_once dirname(__DIR__) . '/app/controllers/MaterialsController.php';
require_once dirname(__DIR__) . '/app/controllers/LocationsController.php';
require_once dirname(__DIR__) . '/app/controllers/BomController.php';
require_once dirname(__DIR__) . '/app/controllers/MrpController.php';
require_once dirname(__DIR__) . '/app/middleware/AuthMiddleware.php';
require_once dirname(__DIR__) . '/app/middleware/RoleMiddleware.php';
require_once dirname(__DIR__) . '/app/middleware/CSRFMiddleware.php';
require_once dirname(__DIR__) . '/app/middleware/RateLimitMiddleware.php';
require_once dirname(__DIR__) . '/app/services/SessionService.php';

class ApiRouter {
    
    private $routes = [];
    private $middleware = [];
    private $globalMiddleware = [];
    
    public function __construct() {
        // Set security headers
        $this->setSecurityHeaders();
        
        // Add global middleware
        $this->addGlobalMiddleware();
        
        // Register API routes
        $this->registerRoutes();
        
        // Handle CORS preflight
        $this->handleCors();
    }
    
    /**
     * Set security headers
     */
    private function setSecurityHeaders() {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
    
    /**
     * Handle CORS preflight requests
     */
    private function handleCors() {
        // Configure CORS for development (adjust for production)
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        
        // In production, specify exact origins instead of *
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Requested-With');
        header('Access-Control-Max-Age: 86400'); // 24 hours
        
        // Handle preflight OPTIONS request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }
    
    /**
     * Add global middleware that applies to all routes
     */
    private function addGlobalMiddleware() {
        $this->globalMiddleware[] = [RateLimitMiddleware::class, 'globalRateLimit'];
    }
    
    /**
     * Register all API routes
     */
    private function registerRoutes() {
        $apiBase = AppConfig::API_BASE_URL;
        
        // Base route - redirect to login
        $this->get('/', function() {
            header('Location: /bems/public/login.html');
            exit;
        });
        
        // Also handle /bems/public/ directly
        $this->get('/bems/public/', function() {
            header('Location: /bems/public/login.html');
            exit;
        });
        
        // Authentication routes
        $this->post("$apiBase/auth/login", [AuthController::class, 'login'], [
            [RateLimitMiddleware::class, 'loginRateLimit']
        ]);
        
        $this->post("$apiBase/auth/logout", [AuthController::class, 'logout'], [
            [AuthMiddleware::class, 'handle']
        ]);
        
        $this->get("$apiBase/auth/status", [AuthController::class, 'status']);
        
        $this->post("$apiBase/auth/change-password", [AuthController::class, 'changePassword'], [
            [AuthMiddleware::class, 'handle'],
            [RoleMiddleware::class, 'requirePasswordChange'],
            [CSRFMiddleware::class, 'handle']
        ]);
        
        // CSRF token endpoint
        $this->get("$apiBase/csrf/token", function() {
            $csrf = new CSRFMiddleware();
            return $csrf->getToken();
        });
        
        // User management routes
        $this->get("$apiBase/users", [UserController::class, 'getUsers'], [
            [AuthMiddleware::class, 'handle'],
            [RoleMiddleware::class, 'managerOrHigher'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/users/{clock_number}", [UserController::class, 'getUser'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->post("$apiBase/users", [UserController::class, 'createUser'], [
            [AuthMiddleware::class, 'handle'],
            [RoleMiddleware::class, 'adminOnly'],
            [CSRFMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->put("$apiBase/users/{clock_number}", [UserController::class, 'updateUser'], [
            [AuthMiddleware::class, 'handle'],
            [RoleMiddleware::class, 'managerOrHigher'],
            [CSRFMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->delete("$apiBase/users/{clock_number}", [UserController::class, 'deleteUser'], [
            [AuthMiddleware::class, 'handle'],
            [RoleMiddleware::class, 'adminOnly'],
            [CSRFMiddleware::class, 'handle']
        ]);
        
        $this->post("$apiBase/users/{clock_number}/activate", [UserController::class, 'activateUser'], [
            [AuthMiddleware::class, 'handle'],
            [RoleMiddleware::class, 'adminOnly'],
            [CSRFMiddleware::class, 'handle']
        ]);
        
        $this->post("$apiBase/users/{clock_number}/unlock", [UserController::class, 'unlockUser'], [
            [AuthMiddleware::class, 'handle'],
            [RoleMiddleware::class, 'managerOrHigher'],
            [CSRFMiddleware::class, 'handle']
        ]);
        
        // ==========================================
        // INVENTORY MANAGEMENT ROUTES - Phase 1
        // ==========================================
        
        // Inventory operations
        $this->post("$apiBase/inventory/receive", [InventoryController::class, 'receive'], [
            [AuthMiddleware::class, 'handle'],
            [CSRFMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/inventory", [InventoryController::class, 'getInventory'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/inventory/{inv_tag}", [InventoryController::class, 'getInventoryByTag'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->post("$apiBase/inventory/{inv_tag}/move", [InventoryController::class, 'moveInventory'], [
            [AuthMiddleware::class, 'handle'],
            [CSRFMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->post("$apiBase/inventory/{inv_tag}/consume", [InventoryController::class, 'consumeInventory'], [
            [AuthMiddleware::class, 'handle'],
            [CSRFMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/inventory/fifo/{material_number}", [InventoryController::class, 'getFifoInventory'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/inventory/summary/location", [InventoryController::class, 'getLocationSummary'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/inventory/alerts/expiring", [InventoryController::class, 'getExpiringAlerts'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        // Materials master data
        $this->get("$apiBase/materials", [MaterialsController::class, 'getMaterials'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/materials/dropdown", [MaterialsController::class, 'getDropdownMaterials'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/materials/categories", [MaterialsController::class, 'getCategories'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/materials/{material_number}", [MaterialsController::class, 'getMaterial'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->post("$apiBase/materials", [MaterialsController::class, 'createMaterial'], [
            [AuthMiddleware::class, 'handle'],
            [RoleMiddleware::class, 'adminOnly'],
            [CSRFMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->put("$apiBase/materials/{material_number}", [MaterialsController::class, 'updateMaterial'], [
            [AuthMiddleware::class, 'handle'],
            [RoleMiddleware::class, 'adminOnly'],
            [CSRFMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        // Locations master data
        $this->get("$apiBase/locations", [LocationsController::class, 'getLocations'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/locations/dropdown", [LocationsController::class, 'getDropdownLocations'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/locations/types", [LocationsController::class, 'getLocationTypes'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/locations/{location_code}", [LocationsController::class, 'getLocation'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->post("$apiBase/locations", [LocationsController::class, 'createLocation'], [
            [AuthMiddleware::class, 'handle'],
            [RoleMiddleware::class, 'adminOnly'],
            [CSRFMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->put("$apiBase/locations/{location_code}", [LocationsController::class, 'updateLocation'], [
            [AuthMiddleware::class, 'handle'],
            [RoleMiddleware::class, 'adminOnly'],
            [CSRFMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        // BOM (Bill of Materials) routes
        $this->get("$apiBase/bom", [BomController::class, 'listBoms'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->post("$apiBase/bom", [BomController::class, 'create'], [
            [AuthMiddleware::class, 'handle'],
            [CSRFMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/bom/{bom_id}/structure", [BomController::class, 'getStructure'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/bom/{bom_id}/cost", [BomController::class, 'getCostRollup'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->post("$apiBase/bom/component", [BomController::class, 'addComponent'], [
            [AuthMiddleware::class, 'handle'],
            [CSRFMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        // MRP (Material Requirements Planning) routes
        $this->post("$apiBase/mrp/run", [MrpController::class, 'executePlanningRun'], [
            [AuthMiddleware::class, 'handle'],
            [CSRFMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/mrp/latest", [MrpController::class, 'getLatestRun'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/mrp/requirements", [MrpController::class, 'getRequirements'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/mrp/orders", [MrpController::class, 'getPlannedOrders'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/mrp/messages", [MrpController::class, 'getActionMessages'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->get("$apiBase/mrp/mps", [MrpController::class, 'getMasterProductionSchedule'], [
            [AuthMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        $this->post("$apiBase/mrp/mps", [MrpController::class, 'createMpsEntry'], [
            [AuthMiddleware::class, 'handle'],
            [CSRFMiddleware::class, 'handle'],
            [RateLimitMiddleware::class, 'apiRateLimit']
        ]);
        
        // Health check endpoint
        $this->get("$apiBase/health", function() {
            return [
                'status' => 'healthy',
                'timestamp' => date('c'),
                'version' => AppConfig::APP_VERSION,
                'environment' => AppConfig::ENVIRONMENT
            ];
        });
        
        // API info endpoint
        $this->get("$apiBase/info", function() {
            return [
                'application' => AppConfig::APP_NAME,
                'version' => AppConfig::APP_VERSION,
                'description' => AppConfig::APP_DESCRIPTION,
                'api_version' => AppConfig::API_VERSION,
                'timestamp' => date('c')
            ];
        });
    }
    
    /**
     * Register GET route
     */
    private function get($pattern, $handler, $middleware = []) {
        $this->addRoute('GET', $pattern, $handler, $middleware);
    }
    
    /**
     * Register POST route
     */
    private function post($pattern, $handler, $middleware = []) {
        $this->addRoute('POST', $pattern, $handler, $middleware);
    }
    
    /**
     * Register PUT route
     */
    private function put($pattern, $handler, $middleware = []) {
        $this->addRoute('PUT', $pattern, $handler, $middleware);
    }
    
    /**
     * Register DELETE route
     */
    private function delete($pattern, $handler, $middleware = []) {
        $this->addRoute('DELETE', $pattern, $handler, $middleware);
    }
    
    /**
     * Add route to routing table
     */
    private function addRoute($method, $pattern, $handler, $middleware = []) {
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'handler' => $handler,
            'middleware' => array_merge($this->globalMiddleware, $middleware)
        ];
    }
    
    /**
     * Route incoming request
     */
    public function route() {
        try {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            
            // Check if route is passed as query parameter (for when .htaccess doesn't work)
            if (isset($_GET['route'])) {
                $uri = '/' . ltrim($_GET['route'], '/');
            } else {
                $uri = $_SERVER['REQUEST_URI'] ?? '/';
                // Remove query string
                $uri = strtok($uri, '?');
            }
            
            // Find matching route
            foreach ($this->routes as $route) {
                if ($route['method'] !== $method) {
                    continue;
                }
                
                $params = $this->matchRoute($route['pattern'], $uri);
                if ($params !== false) {
                    return $this->executeRoute($route, $params);
                }
            }
            
            // No route found
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Endpoint not found',
                'error_code' => 'NOT_FOUND'
            ], 404);
            
        } catch (Exception $e) {
            error_log('Routing error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Internal server error',
                'error_code' => 'INTERNAL_ERROR'
            ], 500);
        }
    }
    
    /**
     * Match route pattern against URI
     */
    private function matchRoute($pattern, $uri) {
        // Convert pattern to regex
        $regex = preg_replace('/\{([^}]+)\}/', '([^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        
        if (preg_match($regex, $uri, $matches)) {
            // Extract parameter names from pattern
            preg_match_all('/\{([^}]+)\}/', $pattern, $paramNames);
            
            $params = [];
            for ($i = 1; $i < count($matches); $i++) {
                $paramName = $paramNames[1][$i - 1];
                $params[$paramName] = urldecode($matches[$i]);
            }
            
            return $params;
        }
        
        return false;
    }
    
    /**
     * Execute route with middleware
     */
    private function executeRoute($route, $params) {
        try {
            $handler = $route['handler'];
            $middleware = $route['middleware'];
            
            // Create the final handler
            $finalHandler = function() use ($handler, $params) {
                return $this->callHandler($handler, $params);
            };
            
            // Apply middleware in reverse order (last middleware first)
            $chain = $finalHandler;
            
            for ($i = count($middleware) - 1; $i >= 0; $i--) {
                $middlewareHandler = $middleware[$i];
                $currentChain = $chain;
                
                $chain = function() use ($middlewareHandler, $currentChain) {
                    return $this->callMiddleware($middlewareHandler, $currentChain);
                };
            }
            
            // Execute the middleware chain
            $result = $chain();
            
            // Handle different response types
            if (is_array($result) || is_object($result)) {
                return $this->jsonResponse($result);
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log('Route execution error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Request processing error',
                'error_code' => 'PROCESSING_ERROR'
            ], 500);
        }
    }
    
    /**
     * Call middleware
     */
    private function callMiddleware($middlewareHandler, $next) {
        if (is_callable($middlewareHandler)) {
            return call_user_func($middlewareHandler, $next);
        }
        
        if (is_array($middlewareHandler) && count($middlewareHandler) === 2) {
            [$className, $methodName] = $middlewareHandler;
            $instance = new $className();
            return call_user_func([$instance, $methodName], $next);
        }
        
        throw new Exception('Invalid middleware handler');
    }
    
    /**
     * Call route handler
     */
    private function callHandler($handler, $params) {
        if (is_callable($handler)) {
            return call_user_func($handler);
        }
        
        if (is_array($handler) && count($handler) === 2) {
            [$className, $methodName] = $handler;
            $instance = new $className();
            
            // Get request input
            $input = $this->getRequestInput();
            
            // Call handler based on parameter count
            $reflection = new ReflectionMethod($className, $methodName);
            $paramCount = $reflection->getNumberOfParameters();
            
            if ($paramCount === 0) {
                return call_user_func([$instance, $methodName]);
            } elseif ($paramCount === 1) {
                // Check if first parameter expects input or route param
                $firstParam = $reflection->getParameters()[0];
                if (!empty($params) && count($params) === 1) {
                    return call_user_func([$instance, $methodName], reset($params));
                } else {
                    return call_user_func([$instance, $methodName], $input);
                }
            } elseif ($paramCount === 2) {
                // Two parameters: route param and input
                $routeParam = !empty($params) ? reset($params) : null;
                return call_user_func([$instance, $methodName], $routeParam, $input);
            }
        }
        
        throw new Exception('Invalid route handler');
    }
    
    /**
     * Get request input data
     */
    private function getRequestInput() {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($contentType, 'application/json') !== false) {
            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true);
            
            
            return $input ?: [];
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
            return array_merge($_POST, $_GET);
        }
        
        return $_GET;
    }
    
    /**
     * Create JSON response
     */
    private function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        
        if (!is_array($data)) {
            $data = ['data' => $data];
        }
        
        if (!isset($data['timestamp'])) {
            $data['timestamp'] = date('c');
        }
        
        if (!isset($data['status_code'])) {
            $data['status_code'] = $statusCode;
        }
        
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    /**
     * Handle uncaught errors
     */
    public function handleError($errno, $errstr, $errfile, $errline) {
        error_log("PHP Error: [$errno] $errstr in $errfile on line $errline");
        
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        $this->jsonResponse([
            'success' => false,
            'message' => 'Server error occurred',
            'error_code' => 'SERVER_ERROR'
        ], 500);
        
        return true;
    }
    
    /**
     * Handle uncaught exceptions
     */
    public function handleException($exception) {
        error_log("Uncaught Exception: " . $exception->getMessage() . "\n" . $exception->getTraceAsString());
        
        $this->jsonResponse([
            'success' => false,
            'message' => 'Unexpected error occurred',
            'error_code' => 'UNEXPECTED_ERROR'
        ], 500);
    }
}

// Initialize error handling
set_error_handler([new ApiRouter(), 'handleError']);
set_exception_handler([new ApiRouter(), 'handleException']);

// Initialize session service before router to ensure proper session configuration
$sessionService = new SessionService();

// Create router instance and handle request
$router = new ApiRouter();

// Handle the request
$router->route();
?>