<?php
/**
 * BEMS Authentication Middleware
 * Best ERP Manufacturing System
 * 
 * Verifies user authentication and session validity for protected routes
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__) . '/services/AuthenticationService.php';

class AuthMiddleware {
    
    private $authService;
    
    public function __construct() {
        $this->authService = new AuthenticationService();
    }
    
    /**
     * Handle authentication check
     * 
     * @param callable $next Next middleware/handler
     * @return mixed Response from next handler or authentication error
     */
    public function handle($next) {
        try {
            // Start session if not already started
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            // Check if user is authenticated
            if (empty($_SESSION['authenticated']) || empty($_SESSION['session_id'])) {
                return $this->unauthorizedResponse('Authentication required');
            }
            
            // Validate session with database
            $sessionValidation = $this->authService->validateSession($_SESSION['session_id']);
            
            if (!$sessionValidation['valid']) {
                // Clear invalid session
                $this->clearSession();
                return $this->unauthorizedResponse($sessionValidation['message']);
            }
            
            // Check session timeout (8 hours from login)
            $loginTime = $_SESSION['login_time'] ?? 0;
            $sessionTimeout = AppConfig::SESSION_TIMEOUT;
            
            if ((time() - $loginTime) > $sessionTimeout) {
                // Session expired
                $this->authService->logout($_SESSION['session_id']);
                $this->clearSession();
                return $this->unauthorizedResponse('Session expired');
            }
            
            // Check IP address consistency for security
            $currentIp = $this->getClientIp();
            $sessionIp = $_SESSION['ip_address'] ?? '';
            
            if ($sessionIp !== $currentIp) {
                // IP address changed - potential security issue
                $this->authService->logout($_SESSION['session_id']);
                $this->clearSession();
                return $this->unauthorizedResponse('Session security violation');
            }
            
            // Update session activity timestamp
            $_SESSION['last_activity'] = time();
            
            // Add user information to request context
            $GLOBALS['current_user'] = $_SESSION['user'];
            
            // Continue to next middleware or handler
            return $next();
            
        } catch (Exception $e) {
            error_log('Auth middleware error: ' . $e->getMessage());
            return $this->errorResponse('Authentication system error', 500);
        }
    }
    
    /**
     * Check if current user is authenticated (without enforcing)
     * 
     * @return bool True if authenticated, false otherwise
     */
    public function isAuthenticated() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (empty($_SESSION['authenticated']) || empty($_SESSION['session_id'])) {
            return false;
        }
        
        // Quick session validation
        $sessionValidation = $this->authService->validateSession($_SESSION['session_id']);
        return $sessionValidation['valid'];
    }
    
    /**
     * Get current authenticated user data
     * 
     * @return array|null User data or null if not authenticated
     */
    public function getCurrentUser() {
        if (!$this->isAuthenticated()) {
            return null;
        }
        
        return $_SESSION['user'] ?? null;
    }
    
    /**
     * Check if current user has specific permission level
     * 
     * @param int $requiredLevel Minimum permission level required
     * @return bool True if user has sufficient permission
     */
    public function hasPermissionLevel($requiredLevel) {
        $user = $this->getCurrentUser();
        if (!$user) {
            return false;
        }
        
        return (int)$user['permission_level'] >= $requiredLevel;
    }
    
    /**
     * Check if current user has specific capability
     * 
     * @param string $capability Capability to check (can_manage_users, can_change_passwords, can_view_audit)
     * @return bool True if user has capability
     */
    public function hasCapability($capability) {
        $user = $this->getCurrentUser();
        if (!$user) {
            return false;
        }
        
        return isset($user[$capability]) && $user[$capability];
    }
    
    /**
     * Require specific permission level
     * 
     * @param int $requiredLevel Minimum permission level required
     * @param callable $next Next handler
     * @return mixed Response from next handler or permission error
     */
    public function requirePermissionLevel($requiredLevel, $next) {
        try {
            // First check authentication
            $authResult = $this->handle(function() { return true; });
            if ($authResult !== true) {
                return $authResult; // Return auth error
            }
            
            // Check permission level
            if (!$this->hasPermissionLevel($requiredLevel)) {
                return $this->forbiddenResponse('Insufficient permission level');
            }
            
            return $next();
            
        } catch (Exception $e) {
            error_log('Permission middleware error: ' . $e->getMessage());
            return $this->errorResponse('Permission system error', 500);
        }
    }
    
    /**
     * Require specific capability
     * 
     * @param string $capability Required capability
     * @param callable $next Next handler
     * @return mixed Response from next handler or capability error
     */
    public function requireCapability($capability, $next) {
        try {
            // First check authentication
            $authResult = $this->handle(function() { return true; });
            if ($authResult !== true) {
                return $authResult; // Return auth error
            }
            
            // Check capability
            if (!$this->hasCapability($capability)) {
                return $this->forbiddenResponse("Required capability: $capability");
            }
            
            return $next();
            
        } catch (Exception $e) {
            error_log('Capability middleware error: ' . $e->getMessage());
            return $this->errorResponse('Capability system error', 500);
        }
    }
    
    /**
     * Admin only access (permission level 100)
     * 
     * @param callable $next Next handler
     * @return mixed Response from next handler or admin error
     */
    public function adminOnly($next) {
        return $this->requirePermissionLevel(100, $next);
    }
    
    /**
     * Manager or Admin access (permission level 90+)
     * 
     * @param callable $next Next handler
     * @return mixed Response from next handler or manager error
     */
    public function managerOrAdmin($next) {
        return $this->requirePermissionLevel(90, $next);
    }
    
    /**
     * Clear session data
     */
    private function clearSession() {
        $_SESSION = array();
        
        // Destroy session cookie if it exists
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }
    
    /**
     * Get client IP address
     * 
     * @return string Client IP address
     */
    private function getClientIp() {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim($_SERVER[$key]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
    
    /**
     * Create unauthorized response
     * 
     * @param string $message Error message
     * @return array JSON response
     */
    private function unauthorizedResponse($message) {
        return $this->errorResponse($message, 401);
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