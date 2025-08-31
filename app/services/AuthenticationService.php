<?php
/**
 * BEMS Authentication Service
 * Best ERP Manufacturing System
 * 
 * Handles user authentication, session management, and audit logging
 * for the BEMS Phase 0 authentication system.
 */

require_once dirname(__DIR__, 2) . '/config/database.php';

class AuthenticationService {
    
    private $pdo;
    private $sessionTimeout = 28800; // 8 hours in seconds
    
    public function __construct() {
        $this->pdo = DatabaseConfig::getConnection();
    }
    
    /**
     * Authenticate user with clock number and password
     * 
     * @param string $clockNumber User's clock number (username)
     * @param string $password Plain text password
     * @param string $ipAddress Client IP address
     * @param string $userAgent Client user agent string
     * @return array Authentication result with user data or error message
     */
    public function authenticateUser($clockNumber, $password, $ipAddress = '127.0.0.1', $userAgent = '') {
        try {
            // Get user data
            $sql = "SELECT u.id, u.clock_number, u.real_name, u.initials, u.password_hash,
                           u.active_status, u.login_attempts, u.locked_until, u.force_password_change,
                           r.id as role_id, r.role_name, r.permission_level,
                           r.can_manage_users, r.can_change_passwords, r.can_view_audit
                    FROM users u
                    INNER JOIN roles r ON u.role_id = r.id
                    WHERE u.clock_number = :clock_number";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->execute();
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if user exists
            if (!$user) {
                $this->logFailedLogin($clockNumber, $ipAddress, $userAgent, 'USER_NOT_FOUND');
                return ['success' => false, 'message' => 'Invalid credentials', 'code' => 'USER_NOT_FOUND'];
            }
            
            // Check if user is active
            if (!$user['active_status']) {
                $this->logFailedLogin($clockNumber, $ipAddress, $userAgent, 'ACCOUNT_DISABLED');
                return ['success' => false, 'message' => 'Account is disabled', 'code' => 'ACCOUNT_DISABLED'];
            }
            
            // Check if account is locked
            if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                $this->logFailedLogin($clockNumber, $ipAddress, $userAgent, 'ACCOUNT_LOCKED');
                return [
                    'success' => false, 
                    'message' => 'Account is locked until ' . date('H:i:s', strtotime($user['locked_until'])),
                    'code' => 'ACCOUNT_LOCKED'
                ];
            }
            
            // Verify password
            if (!password_verify($password, $user['password_hash'])) {
                $this->incrementLoginAttempts($clockNumber);
                $this->logFailedLogin($clockNumber, $ipAddress, $userAgent, 'INVALID_PASSWORD');
                return ['success' => false, 'message' => 'Invalid credentials', 'code' => 'INVALID_CREDENTIALS'];
            }
            
            // Reset login attempts on successful authentication
            $this->resetLoginAttempts($clockNumber);
            
            // Update last login time
            $this->updateLastLogin($clockNumber);
            
            // Create session
            $sessionId = $this->createSession($clockNumber, $ipAddress, $userAgent);
            
            // Log successful login
            $this->logSuccessfulLogin($clockNumber, $user['real_name'], $ipAddress, $userAgent, $sessionId);
            
            // Prepare user data for session
            $userData = [
                'user_id' => $user['id'],
                'clock_number' => $user['clock_number'],
                'real_name' => $user['real_name'],
                'initials' => $user['initials'],
                'role_id' => $user['role_id'],
                'role_name' => $user['role_name'],
                'permission_level' => $user['permission_level'],
                'can_manage_users' => (bool)$user['can_manage_users'],
                'can_change_passwords' => (bool)$user['can_change_passwords'],
                'can_view_audit' => (bool)$user['can_view_audit'],
                'force_password_change' => (bool)$user['force_password_change'],
                'session_id' => $sessionId
            ];
            
            return [
                'success' => true,
                'user' => $userData,
                'force_password_change' => (bool)$user['force_password_change'],
                'message' => 'Login successful'
            ];
            
        } catch (Exception $e) {
            error_log('Authentication error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Authentication system error', 'code' => 'SYSTEM_ERROR'];
        }
    }
    
    /**
     * Create user session
     */
    private function createSession($clockNumber, $ipAddress, $userAgent) {
        $sessionId = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + $this->sessionTimeout);
        
        $sql = "INSERT INTO user_sessions (session_id, clock_number, ip_address, user_agent, expires_at)
                VALUES (:session_id, :clock_number, :ip_address, :user_agent, :expires_at)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':session_id', $sessionId);
        $stmt->bindParam(':clock_number', $clockNumber);
        $stmt->bindParam(':ip_address', $ipAddress);
        $stmt->bindParam(':user_agent', $userAgent);
        $stmt->bindParam(':expires_at', $expiresAt);
        $stmt->execute();
        
        return $sessionId;
    }
    
    /**
     * Validate session
     */
    public function validateSession($sessionId) {
        try {
            $sql = "SELECT s.clock_number, s.expires_at, s.ip_address,
                           u.real_name, u.initials, u.active_status,
                           r.role_name, r.permission_level
                    FROM user_sessions s
                    INNER JOIN users u ON s.clock_number = u.clock_number
                    INNER JOIN roles r ON u.role_id = r.id
                    WHERE s.session_id = :session_id AND s.is_active = 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':session_id', $sessionId);
            $stmt->execute();
            
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$session) {
                return ['valid' => false, 'message' => 'Invalid session'];
            }
            
            // Check if session expired
            if (strtotime($session['expires_at']) <= time()) {
                $this->deactivateSession($sessionId);
                return ['valid' => false, 'message' => 'Session expired'];
            }
            
            // Check if user is still active
            if (!$session['active_status']) {
                $this->deactivateSession($sessionId);
                return ['valid' => false, 'message' => 'Account disabled'];
            }
            
            // Update session activity
            $this->updateSessionActivity($sessionId);
            
            return [
                'valid' => true,
                'user' => [
                    'clock_number' => $session['clock_number'],
                    'real_name' => $session['real_name'],
                    'initials' => $session['initials'],
                    'role_name' => $session['role_name'],
                    'permission_level' => $session['permission_level'],
                    'ip_address' => $session['ip_address']
                ]
            ];
            
        } catch (Exception $e) {
            error_log('Session validation error: ' . $e->getMessage());
            return ['valid' => false, 'message' => 'Session validation error'];
        }
    }
    
    /**
     * Logout user and deactivate session
     */
    public function logout($sessionId, $clockNumber = null) {
        try {
            // Get session info for audit log
            if (!$clockNumber) {
                $sql = "SELECT clock_number FROM user_sessions WHERE session_id = :session_id";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindParam(':session_id', $sessionId);
                $stmt->execute();
                $session = $stmt->fetch(PDO::FETCH_ASSOC);
                $clockNumber = $session ? $session['clock_number'] : 'UNKNOWN';
            }
            
            // Deactivate session
            $this->deactivateSession($sessionId);
            
            // Log logout
            $this->logAuditEvent(
                $clockNumber,
                'LOGOUT',
                'User logged out successfully',
                'user_sessions',
                $sessionId,
                null,
                null,
                'LOW'
            );
            
            return ['success' => true, 'message' => 'Logged out successfully'];
            
        } catch (Exception $e) {
            error_log('Logout error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Logout error'];
        }
    }
    
    /**
     * Change user password (Admin/Manager only)
     */
    public function changeUserPassword($adminClockNumber, $targetClockNumber, $newPassword, $forceChange = false) {
        try {
            // Verify admin has permission
            if (!$this->canChangePasswords($adminClockNumber)) {
                return ['success' => false, 'message' => 'Insufficient permissions'];
            }
            
            // Hash new password
            $passwordHash = password_hash($newPassword, PASSWORD_ARGON2ID);
            
            // Update password
            $sql = "UPDATE users 
                    SET password_hash = :password_hash,
                        password_changed_at = NOW(),
                        force_password_change = :force_change,
                        login_attempts = 0,
                        locked_until = NULL
                    WHERE clock_number = :clock_number";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':password_hash', $passwordHash);
            $stmt->bindParam(':force_change', $forceChange, PDO::PARAM_INT);
            $stmt->bindParam(':clock_number', $targetClockNumber);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                // Log password change
                $this->logAuditEvent(
                    $adminClockNumber,
                    'PASSWORD_CHANGE',
                    "Changed password for user: $targetClockNumber",
                    'users',
                    $targetClockNumber,
                    null,
                    json_encode(['force_change' => $forceChange]),
                    'HIGH'
                );
                
                return ['success' => true, 'message' => 'Password changed successfully'];
            } else {
                return ['success' => false, 'message' => 'User not found'];
            }
            
        } catch (Exception $e) {
            error_log('Password change error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Password change error'];
        }
    }
    
    /**
     * Check if user can change passwords
     */
    private function canChangePasswords($clockNumber) {
        try {
            $sql = "SELECT r.can_change_passwords 
                    FROM users u
                    INNER JOIN roles r ON u.role_id = r.id
                    WHERE u.clock_number = :clock_number AND u.active_status = 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result && $result['can_change_passwords'];
            
        } catch (Exception $e) {
            error_log('Permission check error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Increment login attempts and lock account if necessary
     */
    private function incrementLoginAttempts($clockNumber) {
        try {
            $sql = "UPDATE users 
                    SET login_attempts = login_attempts + 1,
                        locked_until = CASE 
                            WHEN login_attempts >= 4 THEN DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                            ELSE locked_until
                        END
                    WHERE clock_number = :clock_number";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->execute();
            
        } catch (Exception $e) {
            error_log('Login attempt increment error: ' . $e->getMessage());
        }
    }
    
    /**
     * Reset login attempts
     */
    private function resetLoginAttempts($clockNumber) {
        try {
            $sql = "UPDATE users 
                    SET login_attempts = 0, locked_until = NULL 
                    WHERE clock_number = :clock_number";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->execute();
            
        } catch (Exception $e) {
            error_log('Login attempt reset error: ' . $e->getMessage());
        }
    }
    
    /**
     * Update last login time
     */
    private function updateLastLogin($clockNumber) {
        try {
            $sql = "UPDATE users SET last_login = NOW() WHERE clock_number = :clock_number";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->execute();
            
        } catch (Exception $e) {
            error_log('Last login update error: ' . $e->getMessage());
        }
    }
    
    /**
     * Update session activity timestamp
     */
    private function updateSessionActivity($sessionId) {
        try {
            // Extend session expiration
            $newExpiresAt = date('Y-m-d H:i:s', time() + $this->sessionTimeout);
            
            $sql = "UPDATE user_sessions 
                    SET last_activity = NOW(), expires_at = :expires_at 
                    WHERE session_id = :session_id";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':expires_at', $newExpiresAt);
            $stmt->bindParam(':session_id', $sessionId);
            $stmt->execute();
            
        } catch (Exception $e) {
            error_log('Session activity update error: ' . $e->getMessage());
        }
    }
    
    /**
     * Deactivate session
     */
    private function deactivateSession($sessionId) {
        try {
            $sql = "UPDATE user_sessions SET is_active = 0 WHERE session_id = :session_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':session_id', $sessionId);
            $stmt->execute();
            
        } catch (Exception $e) {
            error_log('Session deactivation error: ' . $e->getMessage());
        }
    }
    
    /**
     * Log failed login attempt
     */
    private function logFailedLogin($clockNumber, $ipAddress, $userAgent, $reason) {
        $this->logAuditEvent(
            $clockNumber,
            'LOGIN_FAILED',
            "Failed login attempt - Reason: $reason",
            'users',
            $clockNumber,
            null,
            json_encode([
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'failure_reason' => $reason
            ]),
            'MEDIUM'
        );
    }
    
    /**
     * Log successful login
     */
    private function logSuccessfulLogin($clockNumber, $realName, $ipAddress, $userAgent, $sessionId) {
        $this->logAuditEvent(
            $clockNumber,
            'LOGIN',
            'User successfully logged in',
            'users',
            $clockNumber,
            null,
            json_encode([
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'session_id' => $sessionId
            ]),
            'LOW'
        );
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
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $sessionId = $_SERVER['PHP_SELF'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            $stmt->bindParam(':ip_address', $ipAddress);
            $stmt->bindParam(':session_id', $sessionId);
            $stmt->bindParam(':user_agent', $userAgent);
            $stmt->bindParam(':severity_level', $severity);
            $stmt->execute();
            
        } catch (Exception $e) {
            error_log('Audit logging error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get user permissions
     */
    public function getUserPermissions($clockNumber) {
        try {
            $sql = "SELECT r.* 
                    FROM users u
                    INNER JOIN roles r ON u.role_id = r.id
                    WHERE u.clock_number = :clock_number AND u.active_status = 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log('Permission retrieval error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all active users (Admin only)
     */
    public function getAllUsers($adminClockNumber) {
        try {
            // Verify admin permissions
            $sql = "SELECT r.can_manage_users, r.can_view_audit
                    FROM users u
                    INNER JOIN roles r ON u.role_id = r.id
                    WHERE u.clock_number = :clock_number";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $adminClockNumber);
            $stmt->execute();
            
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$admin || (!$admin['can_manage_users'] && !$admin['can_view_audit'])) {
                return ['success' => false, 'message' => 'Insufficient permissions'];
            }
            
            // Get all users
            $sql = "SELECT u.clock_number, u.real_name, u.initials, u.active_status,
                           u.last_login, u.created_at, r.role_name, r.permission_level,
                           CASE WHEN u.locked_until > NOW() THEN 1 ELSE 0 END as is_locked
                    FROM users u
                    INNER JOIN roles r ON u.role_id = r.id
                    ORDER BY u.real_name";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            return [
                'success' => true,
                'users' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
            
        } catch (Exception $e) {
            error_log('User list retrieval error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error retrieving users'];
        }
    }
}
?>