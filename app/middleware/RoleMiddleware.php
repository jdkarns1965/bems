<?php
/**
 * BEMS Role-Based Access Control Middleware
 * Best ERP Manufacturing System
 * 
 * Manages role-based permissions and access control for different user roles
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__) . '/services/AuthenticationService.php';

class RoleMiddleware {
    
    private $authService;
    
    // Role permission levels
    const ADMIN_LEVEL = 100;
    const MANAGER_LEVEL = 90;
    const SUPERVISOR_LEVEL = 80;
    const LEAD_LEVEL = 70;
    const SENIOR_LEVEL = 60;
    const ASSOCIATE_LEVEL = 50;
    const JUNIOR_LEVEL = 40;
    const TRAINEE_LEVEL = 30;
    const INTERN_LEVEL = 20;
    const TEMP_LEVEL = 10;
    const READONLY_LEVEL = 0;
    
    public function __construct() {
        $this->authService = new AuthenticationService();
    }
    
    /**
     * Check if user has required role level or higher
     * 
     * @param int $requiredLevel Minimum permission level required
     * @param callable $next Next middleware/handler
     * @return mixed Response from next handler or permission error
     */
    public function requireLevel($requiredLevel, $next) {
        try {
            // Start session if not already started
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            // Check authentication first
            if (empty($_SESSION['authenticated']) || empty($_SESSION['user'])) {
                return $this->unauthorizedResponse('Authentication required');
            }
            
            $user = $_SESSION['user'];
            $userLevel = (int)$user['permission_level'];
            
            if ($userLevel < $requiredLevel) {
                return $this->forbiddenResponse(
                    "Access denied. Required permission level: $requiredLevel, User level: $userLevel"
                );
            }
            
            return $next();
            
        } catch (Exception $e) {
            error_log('Role middleware error: ' . $e->getMessage());
            return $this->errorResponse('Role system error', 500);
        }
    }
    
    /**
     * Admin only access (level 100)
     * 
     * @param callable $next Next middleware/handler
     * @return mixed Response from next handler or admin error
     */
    public function adminOnly($next) {
        return $this->requireLevel(self::ADMIN_LEVEL, $next);
    }
    
    /**
     * Manager or higher access (level 90+)
     * 
     * @param callable $next Next middleware/handler
     * @return mixed Response from next handler or manager error
     */
    public function managerOrHigher($next) {
        return $this->requireLevel(self::MANAGER_LEVEL, $next);
    }
    
    /**
     * Supervisor or higher access (level 80+)
     * 
     * @param callable $next Next middleware/handler
     * @return mixed Response from next handler or supervisor error
     */
    public function supervisorOrHigher($next) {
        return $this->requireLevel(self::SUPERVISOR_LEVEL, $next);
    }
    
    /**
     * Lead or higher access (level 70+)
     * 
     * @param callable $next Next middleware/handler
     * @return mixed Response from next handler or lead error
     */
    public function leadOrHigher($next) {
        return $this->requireLevel(self::LEAD_LEVEL, $next);
    }
    
    /**
     * Senior or higher access (level 60+)
     * 
     * @param callable $next Next middleware/handler
     * @return mixed Response from next handler or senior error
     */
    public function seniorOrHigher($next) {
        return $this->requireLevel(self::SENIOR_LEVEL, $next);
    }
    
    /**
     * Check specific capability requirement
     * 
     * @param string $capability Required capability
     * @param callable $next Next middleware/handler
     * @return mixed Response from next handler or capability error
     */
    public function requireCapability($capability, $next) {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            if (empty($_SESSION['authenticated']) || empty($_SESSION['user'])) {
                return $this->unauthorizedResponse('Authentication required');
            }
            
            $user = $_SESSION['user'];
            
            if (empty($user[$capability]) || !$user[$capability]) {
                return $this->forbiddenResponse("Access denied. Required capability: $capability");
            }
            
            return $next();
            
        } catch (Exception $e) {
            error_log('Capability middleware error: ' . $e->getMessage());
            return $this->errorResponse('Capability system error', 500);
        }
    }
    
    /**
     * Require user management capability
     * 
     * @param callable $next Next middleware/handler
     * @return mixed Response from next handler or capability error
     */
    public function requireUserManagement($next) {
        return $this->requireCapability('can_manage_users', $next);
    }
    
    /**
     * Require password change capability
     * 
     * @param callable $next Next middleware/handler
     * @return mixed Response from next handler or capability error
     */
    public function requirePasswordChange($next) {
        return $this->requireCapability('can_change_passwords', $next);
    }
    
    /**
     * Require audit viewing capability
     * 
     * @param callable $next Next middleware/handler
     * @return mixed Response from next handler or capability error
     */
    public function requireAuditView($next) {
        return $this->requireCapability('can_view_audit', $next);
    }
    
    /**
     * Check if user can access own data or is manager/admin
     * 
     * @param string $targetClockNumber Target user's clock number
     * @param callable $next Next middleware/handler
     * @return mixed Response from next handler or access error
     */
    public function canAccessUserData($targetClockNumber, $next) {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            if (empty($_SESSION['authenticated']) || empty($_SESSION['user'])) {
                return $this->unauthorizedResponse('Authentication required');
            }
            
            $user = $_SESSION['user'];
            $userLevel = (int)$user['permission_level'];
            
            // Allow access if:
            // 1. User is accessing their own data
            // 2. User is manager level or higher (90+)
            if ($user['clock_number'] === $targetClockNumber || $userLevel >= self::MANAGER_LEVEL) {
                return $next();
            }
            
            return $this->forbiddenResponse('Access denied. Can only access own data or require manager privileges.');
            
        } catch (Exception $e) {
            error_log('User data access middleware error: ' . $e->getMessage());
            return $this->errorResponse('Access control system error', 500);
        }
    }
    
    /**
     * Check if user can modify user data
     * 
     * @param string $targetClockNumber Target user's clock number
     * @param callable $next Next middleware/handler
     * @return mixed Response from next handler or modification error
     */
    public function canModifyUserData($targetClockNumber, $next) {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            if (empty($_SESSION['authenticated']) || empty($_SESSION['user'])) {
                return $this->unauthorizedResponse('Authentication required');
            }
            
            $user = $_SESSION['user'];
            $userLevel = (int)$user['permission_level'];
            
            // Only managers and above can modify user data
            // Admins can modify anyone, managers can modify non-admin users
            if ($userLevel >= self::ADMIN_LEVEL) {
                // Admin can modify anyone
                return $next();
            } else if ($userLevel >= self::MANAGER_LEVEL) {
                // Manager can modify non-admin users
                // Check target user's permission level
                $targetPermissions = $this->authService->getUserPermissions($targetClockNumber);
                
                if (!$targetPermissions) {
                    return $this->errorResponse('Target user not found', 404);
                }
                
                $targetLevel = (int)$targetPermissions['permission_level'];
                
                if ($targetLevel >= self::ADMIN_LEVEL) {
                    return $this->forbiddenResponse('Cannot modify admin users');
                }
                
                return $next();
            }
            
            return $this->forbiddenResponse('Insufficient privileges to modify user data');
            
        } catch (Exception $e) {
            error_log('User modification middleware error: ' . $e->getMessage());
            return $this->errorResponse('Modification control system error', 500);
        }
    }
    
    /**
     * Get current user's role information
     * 
     * @return array|null Role information or null if not authenticated
     */
    public function getCurrentUserRole() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (empty($_SESSION['authenticated']) || empty($_SESSION['user'])) {
            return null;
        }
        
        return [
            'role_name' => $_SESSION['user']['role_name'],
            'permission_level' => $_SESSION['user']['permission_level'],
            'can_manage_users' => $_SESSION['user']['can_manage_users'],
            'can_change_passwords' => $_SESSION['user']['can_change_passwords'],
            'can_view_audit' => $_SESSION['user']['can_view_audit']
        ];
    }
    
    /**
     * Check multiple role conditions (AND logic)
     * 
     * @param array $conditions Array of conditions to check
     * @param callable $next Next middleware/handler
     * @return mixed Response from next handler or access error
     */
    public function requireMultiple($conditions, $next) {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            if (empty($_SESSION['authenticated']) || empty($_SESSION['user'])) {
                return $this->unauthorizedResponse('Authentication required');
            }
            
            $user = $_SESSION['user'];
            $userLevel = (int)$user['permission_level'];
            
            foreach ($conditions as $condition) {
                switch ($condition['type']) {
                    case 'level':
                        if ($userLevel < $condition['value']) {
                            return $this->forbiddenResponse("Required level: {$condition['value']}");
                        }
                        break;
                    case 'capability':
                        if (empty($user[$condition['value']]) || !$user[$condition['value']]) {
                            return $this->forbiddenResponse("Required capability: {$condition['value']}");
                        }
                        break;
                }
            }
            
            return $next();
            
        } catch (Exception $e) {
            error_log('Multiple role middleware error: ' . $e->getMessage());
            return $this->errorResponse('Role validation system error', 500);
        }
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