<?php
/**
 * BEMS Authentication Controller
 * Best ERP Manufacturing System
 * 
 * Handles authentication API endpoints for login, logout, status check,
 * and password management operations.
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__) . '/services/AuthenticationService.php';

class AuthController {
    
    private $authService;
    
    public function __construct() {
        $this->authService = new AuthenticationService();
    }
    
    /**
     * Handle user login
     * POST /api/v1/auth/login
     * 
     * @param array $input Request input data
     * @return array JSON response
     */
    public function login($input) {
        try {
            
            // Validate required fields
            if (empty($input['clock_number']) || empty($input['password'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Clock number and password are required',
                    'code' => 'MISSING_CREDENTIALS'
                ], 400);
            }
            
            // Get client information
            $ipAddress = $this->getClientIp();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            // Attempt authentication
            $result = $this->authService->authenticateUser(
                $input['clock_number'],
                $input['password'],
                $ipAddress,
                $userAgent
            );
            
            if ($result['success']) {
                // Set secure session cookie parameters BEFORE starting session
                if (session_status() === PHP_SESSION_NONE) {
                    $this->setSecureSessionCookie();
                    session_start();
                }
                
                // Store session data
                $_SESSION['user'] = $result['user'];
                $_SESSION['authenticated'] = true;
                $_SESSION['session_id'] = $result['user']['session_id'];
                $_SESSION['ip_address'] = $ipAddress;
                $_SESSION['login_time'] = time();
                
                return $this->jsonResponse([
                    'success' => true,
                    'message' => $result['message'],
                    'user' => [
                        'clock_number' => $result['user']['clock_number'],
                        'real_name' => $result['user']['real_name'],
                        'initials' => $result['user']['initials'],
                        'role_name' => $result['user']['role_name'],
                        'permission_level' => $result['user']['permission_level'],
                        'permissions' => [
                            'can_manage_users' => $result['user']['can_manage_users'],
                            'can_change_passwords' => $result['user']['can_change_passwords'],
                            'can_view_audit' => $result['user']['can_view_audit']
                        ]
                    ],
                    'force_password_change' => $result['force_password_change'],
                    'session_expires' => time() + AppConfig::SESSION_TIMEOUT
                ], 200);
                
            } else {
                $statusCode = $this->getStatusCodeForError($result['code'] ?? 'UNKNOWN');
                return $this->jsonResponse($result, $statusCode);
            }
            
        } catch (Exception $e) {
            error_log('Login error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Authentication system error',
                'code' => 'SYSTEM_ERROR'
            ], 500);
        }
    }
    
    /**
     * Handle user logout
     * POST /api/v1/auth/logout
     * 
     * @return array JSON response
     */
    public function logout() {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            $sessionId = $_SESSION['session_id'] ?? null;
            $clockNumber = $_SESSION['user']['clock_number'] ?? null;
            
            // Logout through service
            if ($sessionId) {
                $this->authService->logout($sessionId, $clockNumber);
            }
            
            // Clear PHP session
            $_SESSION = array();
            
            // Destroy session cookie
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            
            session_destroy();
            
            return $this->jsonResponse([
                'success' => true,
                'message' => 'Logged out successfully'
            ], 200);
            
        } catch (Exception $e) {
            error_log('Logout error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Logout error occurred'
            ], 500);
        }
    }
    
    /**
     * Check authentication status
     * GET /api/v1/auth/status
     * 
     * @return array JSON response
     */
    public function status() {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            // Check if user is authenticated
            if (empty($_SESSION['authenticated']) || empty($_SESSION['session_id'])) {
                return $this->jsonResponse([
                    'authenticated' => false,
                    'message' => 'Not authenticated'
                ], 401);
            }
            
            // Validate session
            $sessionValidation = $this->authService->validateSession($_SESSION['session_id']);
            
            if (!$sessionValidation['valid']) {
                // Clear invalid session
                $_SESSION = array();
                session_destroy();
                
                return $this->jsonResponse([
                    'authenticated' => false,
                    'message' => $sessionValidation['message']
                ], 401);
            }
            
            // Check session timeout (8 hours)
            $loginTime = $_SESSION['login_time'] ?? 0;
            $sessionTimeout = AppConfig::SESSION_TIMEOUT;
            
            if ((time() - $loginTime) > $sessionTimeout) {
                // Session expired
                $this->authService->logout($_SESSION['session_id']);
                $_SESSION = array();
                session_destroy();
                
                return $this->jsonResponse([
                    'authenticated' => false,
                    'message' => 'Session expired'
                ], 401);
            }
            
            // Return current user status
            return $this->jsonResponse([
                'authenticated' => true,
                'user' => [
                    'clock_number' => $_SESSION['user']['clock_number'],
                    'real_name' => $_SESSION['user']['real_name'],
                    'initials' => $_SESSION['user']['initials'],
                    'role_name' => $_SESSION['user']['role_name'],
                    'permission_level' => $_SESSION['user']['permission_level'],
                    'permissions' => [
                        'can_manage_users' => $_SESSION['user']['can_manage_users'],
                        'can_change_passwords' => $_SESSION['user']['can_change_passwords'],
                        'can_view_audit' => $_SESSION['user']['can_view_audit']
                    ]
                ],
                'session_expires' => $loginTime + $sessionTimeout,
                'time_remaining' => ($loginTime + $sessionTimeout) - time()
            ], 200);
            
        } catch (Exception $e) {
            error_log('Status check error: ' . $e->getMessage());
            return $this->jsonResponse([
                'authenticated' => false,
                'message' => 'Status check error'
            ], 500);
        }
    }
    
    /**
     * Change user password (Admin/Manager only)
     * POST /api/v1/auth/change-password
     * 
     * @param array $input Request input data
     * @return array JSON response
     */
    public function changePassword($input) {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            // Check authentication
            if (empty($_SESSION['authenticated']) || empty($_SESSION['user'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Authentication required'
                ], 401);
            }
            
            // Validate required fields
            if (empty($input['target_clock_number']) || empty($input['new_password'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Target clock number and new password are required'
                ], 400);
            }
            
            // Check if user has permission to change passwords
            if (!$_SESSION['user']['can_change_passwords']) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Insufficient permissions to change passwords'
                ], 403);
            }
            
            // Validate password strength
            $passwordValidation = $this->validatePassword($input['new_password']);
            if (!$passwordValidation['valid']) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $passwordValidation['message']
                ], 400);
            }
            
            // Force password change flag
            $forceChange = isset($input['force_change']) ? (bool)$input['force_change'] : false;
            
            // Change password through service
            $result = $this->authService->changeUserPassword(
                $_SESSION['user']['clock_number'],
                $input['target_clock_number'],
                $input['new_password'],
                $forceChange
            );
            
            if ($result['success']) {
                return $this->jsonResponse($result, 200);
            } else {
                $statusCode = $result['message'] === 'Insufficient permissions' ? 403 : 400;
                return $this->jsonResponse($result, $statusCode);
            }
            
        } catch (Exception $e) {
            error_log('Password change error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Password change system error'
            ], 500);
        }
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
     * Set secure session cookie parameters
     */
    private function setSecureSessionCookie() {
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        $httpOnly = true;
        $sameSite = 'Strict';
        
        session_set_cookie_params([
            'lifetime' => AppConfig::SESSION_TIMEOUT,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => $sameSite
        ]);
    }
    
    /**
     * Validate password strength
     * 
     * @param string $password Password to validate
     * @return array Validation result
     */
    private function validatePassword($password) {
        if (strlen($password) < 8) {
            return [
                'valid' => false,
                'message' => 'Password must be at least 8 characters long'
            ];
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            return [
                'valid' => false,
                'message' => 'Password must contain at least one uppercase letter'
            ];
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            return [
                'valid' => false,
                'message' => 'Password must contain at least one lowercase letter'
            ];
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            return [
                'valid' => false,
                'message' => 'Password must contain at least one number'
            ];
        }
        
        if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
            return [
                'valid' => false,
                'message' => 'Password must contain at least one special character'
            ];
        }
        
        return ['valid' => true, 'message' => 'Password is valid'];
    }
    
    /**
     * Get HTTP status code for error
     * 
     * @param string $errorCode Error code
     * @return int HTTP status code
     */
    private function getStatusCodeForError($errorCode) {
        switch ($errorCode) {
            case 'USER_NOT_FOUND':
            case 'INVALID_CREDENTIALS':
            case 'INVALID_PASSWORD':
                return 401;
            case 'ACCOUNT_DISABLED':
            case 'ACCOUNT_LOCKED':
                return 403;
            case 'MISSING_CREDENTIALS':
                return 400;
            case 'SYSTEM_ERROR':
                return 500;
            default:
                return 400;
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