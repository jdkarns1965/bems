<?php
/**
 * BEMS CSRF Protection Middleware
 * Best ERP Manufacturing System
 * 
 * Provides Cross-Site Request Forgery protection for state-changing operations
 */

require_once dirname(__DIR__, 2) . '/config/app.php';

class CSRFMiddleware {
    
    private $tokenName = 'csrf_token';
    private $tokenExpiry;
    
    public function __construct() {
        $this->tokenExpiry = AppConfig::CSRF_TOKEN_EXPIRY;
    }
    
    /**
     * Handle CSRF protection for state-changing requests
     * 
     * @param callable $next Next middleware/handler
     * @param array $methods HTTP methods to protect (default: POST, PUT, DELETE, PATCH)
     * @return mixed Response from next handler or CSRF error
     */
    public function handle($next, $methods = ['POST', 'PUT', 'DELETE', 'PATCH']) {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            
            // Skip CSRF check for safe methods (GET, HEAD, OPTIONS)
            if (!in_array($requestMethod, $methods)) {
                return $next();
            }
            
            // Skip CSRF check for login endpoint to avoid chicken-and-egg problem
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($requestUri, '/auth/login') !== false) {
                return $next();
            }
            
            // Verify CSRF token for state-changing methods
            if (!$this->verifyCSRFToken()) {
                return $this->forbiddenResponse('CSRF token validation failed');
            }
            
            return $next();
            
        } catch (Exception $e) {
            error_log('CSRF middleware error: ' . $e->getMessage());
            return $this->errorResponse('CSRF protection system error', 500);
        }
    }
    
    /**
     * Generate CSRF token for current session
     * 
     * @return string CSRF token
     */
    public function generateToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Generate token if it doesn't exist or is expired
        if (!$this->hasValidToken()) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[$this->tokenName] = $token;
            $_SESSION[$this->tokenName . '_time'] = time();
        }
        
        return $_SESSION[$this->tokenName];
    }
    
    /**
     * Verify CSRF token from request
     * 
     * @return bool True if token is valid, false otherwise
     */
    public function verifyCSRFToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Check if session has valid token
        if (!$this->hasValidToken()) {
            return false;
        }
        
        $sessionToken = $_SESSION[$this->tokenName];
        
        // Get token from various sources
        $requestToken = $this->getTokenFromRequest();
        
        if (!$requestToken) {
            return false;
        }
        
        // Use hash_equals for timing attack protection
        return hash_equals($sessionToken, $requestToken);
    }
    
    /**
     * Check if session has valid token
     * 
     * @return bool True if token is valid and not expired
     */
    private function hasValidToken() {
        if (empty($_SESSION[$this->tokenName]) || empty($_SESSION[$this->tokenName . '_time'])) {
            return false;
        }
        
        // Check if token is expired
        $tokenTime = $_SESSION[$this->tokenName . '_time'];
        if ((time() - $tokenTime) > $this->tokenExpiry) {
            // Clear expired token
            unset($_SESSION[$this->tokenName]);
            unset($_SESSION[$this->tokenName . '_time']);
            return false;
        }
        
        return true;
    }
    
    /**
     * Get CSRF token from request
     * 
     * @return string|null Token from request or null if not found
     */
    private function getTokenFromRequest() {
        // Check POST data
        if (isset($_POST[$this->tokenName])) {
            return $_POST[$this->tokenName];
        }
        
        // Check JSON body
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input[$this->tokenName])) {
            return $input[$this->tokenName];
        }
        
        // Check headers
        $headers = $this->getAllHeaders();
        
        // Check X-CSRF-Token header
        if (isset($headers['X-CSRF-Token'])) {
            return $headers['X-CSRF-Token'];
        }
        
        // Check X-XSRF-Token header (alternative naming)
        if (isset($headers['X-XSRF-Token'])) {
            return $headers['X-XSRF-Token'];
        }
        
        return null;
    }
    
    /**
     * Get all HTTP headers (case-insensitive)
     * 
     * @return array Headers array
     */
    private function getAllHeaders() {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }
        
        // Fallback for servers without getallheaders
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $headerName = str_replace(' ', '-', 
                    ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$headerName] = $value;
            }
        }
        
        return $headers;
    }
    
    /**
     * Get CSRF token for client use
     * 
     * @return array Token information
     */
    public function getTokenInfo() {
        $token = $this->generateToken();
        
        return [
            'token' => $token,
            'token_name' => $this->tokenName,
            'expires_at' => time() + $this->tokenExpiry,
            'header_name' => 'X-CSRF-Token'
        ];
    }
    
    /**
     * Refresh CSRF token (useful after successful operations)
     * 
     * @return string New CSRF token
     */
    public function refreshToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Clear existing token
        unset($_SESSION[$this->tokenName]);
        unset($_SESSION[$this->tokenName . '_time']);
        
        // Generate new token
        return $this->generateToken();
    }
    
    /**
     * Clear CSRF token from session
     */
    public function clearToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        unset($_SESSION[$this->tokenName]);
        unset($_SESSION[$this->tokenName . '_time']);
    }
    
    /**
     * Create middleware for specific HTTP methods
     * 
     * @param array $methods Methods to protect
     * @return callable Middleware function
     */
    public static function forMethods($methods) {
        return function($next) use ($methods) {
            $csrf = new CSRFMiddleware();
            return $csrf->handle($next, $methods);
        };
    }
    
    /**
     * Create middleware for POST requests only
     * 
     * @return callable Middleware function
     */
    public static function forPost() {
        return self::forMethods(['POST']);
    }
    
    /**
     * Create middleware for all state-changing methods
     * 
     * @return callable Middleware function
     */
    public static function forAll() {
        return self::forMethods(['POST', 'PUT', 'DELETE', 'PATCH']);
    }
    
    /**
     * API endpoint to get CSRF token
     * GET /api/v1/csrf/token
     * 
     * @return array JSON response with token
     */
    public function getToken() {
        try {
            $tokenInfo = $this->getTokenInfo();
            
            return [
                'success' => true,
                'csrf' => $tokenInfo,
                'message' => 'CSRF token generated successfully',
                'timestamp' => date('c'),
                'status_code' => 200
            ];
            
        } catch (Exception $e) {
            error_log('CSRF token generation error: ' . $e->getMessage());
            return $this->errorResponse('Token generation error', 500);
        }
    }
    
    /**
     * Create forbidden response
     * 
     * @param string $message Error message
     * @return array JSON response
     */
    private function forbiddenResponse($message) {
        return $this->errorResponse($message, 403);
    }
    
    /**
     * Create error response
     * 
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @return array JSON response
     */
    private function errorResponse($message, $statusCode) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        
        return [
            'success' => false,
            'message' => $message,
            'timestamp' => date('c'),
            'status_code' => $statusCode
        ];
    }
}
?>