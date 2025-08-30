<?php
/**
 * BEMS User Management Controller
 * Best ERP Manufacturing System
 * 
 * Handles user management API endpoints for CRUD operations on users
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__) . '/services/AuthenticationService.php';

class UserController {
    
    private $pdo;
    private $authService;
    
    public function __construct() {
        $this->pdo = DatabaseConfig::getConnection();
        $this->authService = new AuthenticationService();
    }
    
    /**
     * Get all users (Admin/Manager only)
     * GET /api/v1/users
     * 
     * @return array JSON response with user list
     */
    public function getUsers() {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            // Check authentication and permissions
            if (empty($_SESSION['authenticated']) || empty($_SESSION['user'])) {
                return $this->unauthorizedResponse('Authentication required');
            }
            
            $currentUser = $_SESSION['user'];
            
            // Check if user can manage users or view audit (both allow user listing)
            if (!$currentUser['can_manage_users'] && !$currentUser['can_view_audit']) {
                return $this->forbiddenResponse('Insufficient permissions to view users');
            }
            
            // Get all users with role information
            $sql = "SELECT u.id, u.clock_number, u.real_name, u.initials, u.active_status,
                           u.last_login, u.created_at, u.password_changed_at, u.force_password_change,
                           u.login_attempts, 
                           CASE WHEN u.locked_until > NOW() THEN 1 ELSE 0 END as is_locked,
                           u.locked_until,
                           r.role_name, r.permission_level, r.can_manage_users, 
                           r.can_change_passwords, r.can_view_audit
                    FROM users u
                    INNER JOIN roles r ON u.role_id = r.id
                    ORDER BY u.real_name";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format user data
            foreach ($users as &$user) {
                $user['is_locked'] = (bool)$user['is_locked'];
                $user['active_status'] = (bool)$user['active_status'];
                $user['force_password_change'] = (bool)$user['force_password_change'];
                $user['can_manage_users'] = (bool)$user['can_manage_users'];
                $user['can_change_passwords'] = (bool)$user['can_change_passwords'];
                $user['can_view_audit'] = (bool)$user['can_view_audit'];
                
                // Don't expose sensitive data
                unset($user['id']);
            }
            
            return $this->jsonResponse([
                'success' => true,
                'users' => $users,
                'total_count' => count($users)
            ], 200);
            
        } catch (Exception $e) {
            error_log('Get users error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Error retrieving users'
            ], 500);
        }
    }
    
    /**
     * Get specific user details (Admin/Manager or own data)
     * GET /api/v1/users/{clock_number}
     * 
     * @param string $clockNumber Clock number of user to retrieve
     * @return array JSON response with user details
     */
    public function getUser($clockNumber) {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            if (empty($_SESSION['authenticated']) || empty($_SESSION['user'])) {
                return $this->unauthorizedResponse('Authentication required');
            }
            
            $currentUser = $_SESSION['user'];
            
            // Check permissions - can view own data or must have management permissions
            $canView = ($currentUser['clock_number'] === $clockNumber) ||
                      $currentUser['can_manage_users'] ||
                      ($currentUser['permission_level'] >= 90); // Manager or above
            
            if (!$canView) {
                return $this->forbiddenResponse('Insufficient permissions to view user details');
            }
            
            // Get user details
            $sql = "SELECT u.clock_number, u.real_name, u.initials, u.active_status,
                           u.last_login, u.created_at, u.password_changed_at, u.force_password_change,
                           u.login_attempts,
                           CASE WHEN u.locked_until > NOW() THEN 1 ELSE 0 END as is_locked,
                           u.locked_until,
                           r.role_name, r.permission_level, r.can_manage_users,
                           r.can_change_passwords, r.can_view_audit
                    FROM users u
                    INNER JOIN roles r ON u.role_id = r.id
                    WHERE u.clock_number = :clock_number";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->execute();
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }
            
            // Format user data
            $user['is_locked'] = (bool)$user['is_locked'];
            $user['active_status'] = (bool)$user['active_status'];
            $user['force_password_change'] = (bool)$user['force_password_change'];
            $user['can_manage_users'] = (bool)$user['can_manage_users'];
            $user['can_change_passwords'] = (bool)$user['can_change_passwords'];
            $user['can_view_audit'] = (bool)$user['can_view_audit'];
            
            return $this->jsonResponse([
                'success' => true,
                'user' => $user
            ], 200);
            
        } catch (Exception $e) {
            error_log('Get user error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Error retrieving user details'
            ], 500);
        }
    }
    
    /**
     * Create new user (Admin only)
     * POST /api/v1/users
     * 
     * @param array $input Request input data
     * @return array JSON response
     */
    public function createUser($input) {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            if (empty($_SESSION['authenticated']) || empty($_SESSION['user'])) {
                return $this->unauthorizedResponse('Authentication required');
            }
            
            $currentUser = $_SESSION['user'];
            
            // Only admins can create users
            if ($currentUser['permission_level'] < 100) {
                return $this->forbiddenResponse('Only administrators can create users');
            }
            
            // Validate required fields
            $requiredFields = ['clock_number', 'real_name', 'initials', 'role_id', 'password'];
            foreach ($requiredFields as $field) {
                if (empty($input[$field])) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => "Field '$field' is required"
                    ], 400);
                }
            }
            
            // Validate password strength
            $passwordValidation = $this->validatePassword($input['password']);
            if (!$passwordValidation['valid']) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $passwordValidation['message']
                ], 400);
            }
            
            // Check if clock number already exists
            $sql = "SELECT clock_number FROM users WHERE clock_number = :clock_number";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $input['clock_number']);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Clock number already exists'
                ], 400);
            }
            
            // Validate role exists
            $sql = "SELECT id FROM roles WHERE id = :role_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':role_id', $input['role_id'], PDO::PARAM_INT);
            $stmt->execute();
            
            if ($stmt->rowCount() === 0) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Invalid role ID'
                ], 400);
            }
            
            // Hash password
            $passwordHash = password_hash($input['password'], AppConfig::PASSWORD_HASH_ALGO);
            
            // Create user
            $sql = "INSERT INTO users (
                        clock_number, real_name, initials, role_id, password_hash,
                        active_status, force_password_change, created_at, password_changed_at
                    ) VALUES (
                        :clock_number, :real_name, :initials, :role_id, :password_hash,
                        :active_status, :force_password_change, NOW(), NOW()
                    )";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $input['clock_number']);
            $stmt->bindParam(':real_name', $input['real_name']);
            $stmt->bindParam(':initials', $input['initials']);
            $stmt->bindParam(':role_id', $input['role_id'], PDO::PARAM_INT);
            $stmt->bindParam(':password_hash', $passwordHash);
            
            $activeStatus = isset($input['active_status']) ? (bool)$input['active_status'] : true;
            $stmt->bindParam(':active_status', $activeStatus, PDO::PARAM_BOOL);
            
            $forcePasswordChange = isset($input['force_password_change']) ? (bool)$input['force_password_change'] : true;
            $stmt->bindParam(':force_password_change', $forcePasswordChange, PDO::PARAM_BOOL);
            
            $stmt->execute();
            
            // Log user creation
            $this->logAuditEvent(
                $currentUser['clock_number'],
                'USER_CREATED',
                "Created new user: {$input['clock_number']} ({$input['real_name']})",
                'users',
                $input['clock_number'],
                null,
                json_encode([
                    'clock_number' => $input['clock_number'],
                    'real_name' => $input['real_name'],
                    'role_id' => $input['role_id'],
                    'active_status' => $activeStatus
                ]),
                'HIGH'
            );
            
            return $this->jsonResponse([
                'success' => true,
                'message' => 'User created successfully',
                'user' => [
                    'clock_number' => $input['clock_number'],
                    'real_name' => $input['real_name'],
                    'initials' => $input['initials'],
                    'active_status' => $activeStatus,
                    'force_password_change' => $forcePasswordChange
                ]
            ], 201);
            
        } catch (Exception $e) {
            error_log('Create user error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Error creating user'
            ], 500);
        }
    }
    
    /**
     * Update user details (Admin/Manager only)
     * PUT /api/v1/users/{clock_number}
     * 
     * @param string $clockNumber Clock number of user to update
     * @param array $input Request input data
     * @return array JSON response
     */
    public function updateUser($clockNumber, $input) {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            if (empty($_SESSION['authenticated']) || empty($_SESSION['user'])) {
                return $this->unauthorizedResponse('Authentication required');
            }
            
            $currentUser = $_SESSION['user'];
            
            // Check permissions to modify users
            if (!$currentUser['can_manage_users'] && $currentUser['permission_level'] < 90) {
                return $this->forbiddenResponse('Insufficient permissions to modify users');
            }
            
            // Get target user information
            $sql = "SELECT u.*, r.permission_level as target_permission_level
                    FROM users u
                    INNER JOIN roles r ON u.role_id = r.id
                    WHERE u.clock_number = :clock_number";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->execute();
            
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$targetUser) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }
            
            // Check if current user can modify target user
            if ($currentUser['permission_level'] < 100 && 
                (int)$targetUser['target_permission_level'] >= 100) {
                return $this->forbiddenResponse('Cannot modify admin users');
            }
            
            // Build update query dynamically
            $updateFields = [];
            $params = [];
            $oldValues = [];
            $newValues = [];
            
            // Allowed fields for update
            $allowedFields = ['real_name', 'initials', 'role_id', 'active_status'];
            
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $input)) {
                    $updateFields[] = "$field = :$field";
                    $params[$field] = $input[$field];
                    $oldValues[$field] = $targetUser[$field];
                    $newValues[$field] = $input[$field];
                    
                    // Special handling for role_id validation
                    if ($field === 'role_id') {
                        $roleCheck = $this->pdo->prepare("SELECT id FROM roles WHERE id = ?");
                        $roleCheck->execute([$input[$field]]);
                        if ($roleCheck->rowCount() === 0) {
                            return $this->jsonResponse([
                                'success' => false,
                                'message' => 'Invalid role ID'
                            ], 400);
                        }
                    }
                }
            }
            
            if (empty($updateFields)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'No valid fields to update'
                ], 400);
            }
            
            // Execute update
            $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE clock_number = :clock_number";
            $stmt = $this->pdo->prepare($sql);
            $params['clock_number'] = $clockNumber;
            $stmt->execute($params);
            
            if ($stmt->rowCount() > 0) {
                // Log user update
                $this->logAuditEvent(
                    $currentUser['clock_number'],
                    'USER_UPDATED',
                    "Updated user: $clockNumber",
                    'users',
                    $clockNumber,
                    json_encode($oldValues),
                    json_encode($newValues),
                    'MEDIUM'
                );
                
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'User updated successfully'
                ], 200);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'No changes made'
                ], 200);
            }
            
        } catch (Exception $e) {
            error_log('Update user error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Error updating user'
            ], 500);
        }
    }
    
    /**
     * Deactivate user (Admin only)
     * DELETE /api/v1/users/{clock_number}
     * 
     * @param string $clockNumber Clock number of user to deactivate
     * @return array JSON response
     */
    public function deleteUser($clockNumber) {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            if (empty($_SESSION['authenticated']) || empty($_SESSION['user'])) {
                return $this->unauthorizedResponse('Authentication required');
            }
            
            $currentUser = $_SESSION['user'];
            
            // Only admins can delete users
            if ($currentUser['permission_level'] < 100) {
                return $this->forbiddenResponse('Only administrators can deactivate users');
            }
            
            // Cannot delete self
            if ($currentUser['clock_number'] === $clockNumber) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Cannot deactivate your own account'
                ], 400);
            }
            
            // Check if user exists
            $sql = "SELECT clock_number, real_name, active_status FROM users WHERE clock_number = :clock_number";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->execute();
            
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$targetUser) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }
            
            if (!$targetUser['active_status']) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'User is already deactivated'
                ], 400);
            }
            
            // Deactivate user (soft delete)
            $sql = "UPDATE users SET active_status = 0 WHERE clock_number = :clock_number";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->execute();
            
            // Deactivate all user sessions
            $sql = "UPDATE user_sessions SET is_active = 0 WHERE clock_number = :clock_number";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->execute();
            
            // Log user deactivation
            $this->logAuditEvent(
                $currentUser['clock_number'],
                'USER_DEACTIVATED',
                "Deactivated user: $clockNumber ({$targetUser['real_name']})",
                'users',
                $clockNumber,
                json_encode(['active_status' => true]),
                json_encode(['active_status' => false]),
                'HIGH'
            );
            
            return $this->jsonResponse([
                'success' => true,
                'message' => 'User deactivated successfully'
            ], 200);
            
        } catch (Exception $e) {
            error_log('Delete user error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Error deactivating user'
            ], 500);
        }
    }
    
    /**
     * Reactivate user (Admin only)
     * POST /api/v1/users/{clock_number}/activate
     * 
     * @param string $clockNumber Clock number of user to reactivate
     * @return array JSON response
     */
    public function activateUser($clockNumber) {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            if (empty($_SESSION['authenticated']) || empty($_SESSION['user'])) {
                return $this->unauthorizedResponse('Authentication required');
            }
            
            $currentUser = $_SESSION['user'];
            
            // Only admins can reactivate users
            if ($currentUser['permission_level'] < 100) {
                return $this->forbiddenResponse('Only administrators can reactivate users');
            }
            
            // Check if user exists
            $sql = "SELECT clock_number, real_name, active_status FROM users WHERE clock_number = :clock_number";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->execute();
            
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$targetUser) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }
            
            if ($targetUser['active_status']) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'User is already active'
                ], 400);
            }
            
            // Reactivate user
            $sql = "UPDATE users SET active_status = 1, login_attempts = 0, locked_until = NULL 
                    WHERE clock_number = :clock_number";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->execute();
            
            // Log user reactivation
            $this->logAuditEvent(
                $currentUser['clock_number'],
                'USER_ACTIVATED',
                "Reactivated user: $clockNumber ({$targetUser['real_name']})",
                'users',
                $clockNumber,
                json_encode(['active_status' => false]),
                json_encode(['active_status' => true]),
                'HIGH'
            );
            
            return $this->jsonResponse([
                'success' => true,
                'message' => 'User reactivated successfully'
            ], 200);
            
        } catch (Exception $e) {
            error_log('Activate user error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Error reactivating user'
            ], 500);
        }
    }
    
    /**
     * Unlock user account (Admin/Manager only)
     * POST /api/v1/users/{clock_number}/unlock
     * 
     * @param string $clockNumber Clock number of user to unlock
     * @return array JSON response
     */
    public function unlockUser($clockNumber) {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            if (empty($_SESSION['authenticated']) || empty($_SESSION['user'])) {
                return $this->unauthorizedResponse('Authentication required');
            }
            
            $currentUser = $_SESSION['user'];
            
            // Check permissions
            if ($currentUser['permission_level'] < 90) {
                return $this->forbiddenResponse('Insufficient permissions to unlock users');
            }
            
            // Reset login attempts and unlock
            $sql = "UPDATE users SET login_attempts = 0, locked_until = NULL 
                    WHERE clock_number = :clock_number";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                // Log user unlock
                $this->logAuditEvent(
                    $currentUser['clock_number'],
                    'USER_UNLOCKED',
                    "Unlocked user account: $clockNumber",
                    'users',
                    $clockNumber,
                    null,
                    json_encode(['login_attempts' => 0, 'locked_until' => null]),
                    'MEDIUM'
                );
                
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'User account unlocked successfully'
                ], 200);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }
            
        } catch (Exception $e) {
            error_log('Unlock user error: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Error unlocking user'
            ], 500);
        }
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
     * Log audit event
     */
    private function logAuditEvent($clockNumber, $actionType, $description, $tableAffected = null, 
                                  $recordId = null, $oldValues = null, $newValues = null, $severity = 'LOW') {
        try {
            // Get real name
            $realName = 'Unknown User';
            if ($clockNumber !== 'SYSTEM' && $clockNumber !== 'UNKNOWN') {
                $sql = "SELECT real_name FROM users WHERE clock_number = :clock_number";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindParam(':clock_number', $clockNumber);
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    $realName = $user['real_name'];
                }
            }
            
            // Insert audit log
            $sql = "INSERT INTO audit_logs (
                        clock_number, real_name, action_type, action_description,
                        table_affected, record_id, old_values, new_values,
                        ip_address, session_id, user_agent, severity_level
                    ) VALUES (
                        :clock_number, :real_name, :action_type, :action_description,
                        :table_affected, :record_id, :old_values, :new_values,
                        :ip_address, :session_id, :user_agent, :severity_level
                    )";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->bindParam(':real_name', $realName);
            $stmt->bindParam(':action_type', $actionType);
            $stmt->bindParam(':action_description', $description);
            $stmt->bindParam(':table_affected', $tableAffected);
            $stmt->bindParam(':record_id', $recordId);
            $stmt->bindParam(':old_values', $oldValues);
            $stmt->bindParam(':new_values', $newValues);
            $stmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            $stmt->bindParam(':session_id', $_SESSION['session_id'] ?? null);
            $stmt->bindParam(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? '');
            $stmt->bindParam(':severity_level', $severity);
            $stmt->execute();
            
        } catch (Exception $e) {
            error_log('Audit logging error: ' . $e->getMessage());
        }
    }
    
    /**
     * Create unauthorized response
     * 
     * @param string $message Error message
     * @return array JSON response
     */
    private function unauthorizedResponse($message) {
        return $this->jsonResponse(['success' => false, 'message' => $message], 401);
    }
    
    /**
     * Create forbidden response
     * 
     * @param string $message Error message
     * @return array JSON response
     */
    private function forbiddenResponse($message) {
        return $this->jsonResponse(['success' => false, 'message' => $message], 403);
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