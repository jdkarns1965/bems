<?php
/**
 * BEMS Session Management Service
 * Best ERP Manufacturing System
 * 
 * Enhanced session management with timeout handling, security features,
 * and comprehensive session lifecycle management
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';

class SessionService {
    
    private $pdo;
    private $sessionTimeout;
    private $maxConcurrentSessions;
    
    public function __construct() {
        $this->pdo = DatabaseConfig::getConnection();
        $this->sessionTimeout = AppConfig::SESSION_TIMEOUT;
        $this->maxConcurrentSessions = 3; // Max concurrent sessions per user
        
        // Initialize session security settings
        $this->initializeSecureSession();
    }
    
    /**
     * Initialize secure session configuration
     */
    private function initializeSecureSession() {
        // Configure secure session settings
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.gc_maxlifetime', $this->sessionTimeout);
        
        // Set session name
        session_name('BEMS_SESSION');
    }
    
    /**
     * Create new secure session
     * 
     * @param string $clockNumber User's clock number
     * @param array $userData User data to store in session
     * @param string $ipAddress Client IP address
     * @param string $userAgent Client user agent
     * @return array Session creation result
     */
    public function createSession($clockNumber, $userData, $ipAddress, $userAgent) {
        try {
            // Clean up expired sessions first
            $this->cleanupExpiredSessions();
            
            // Check concurrent session limit
            $this->enforceConcurrentSessionLimit($clockNumber);
            
            // Generate secure session ID
            $sessionId = $this->generateSecureSessionId();
            $expiresAt = date('Y-m-d H:i:s', time() + $this->sessionTimeout);
            
            // Start PHP session
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            // Regenerate session ID for security
            session_regenerate_id(true);
            $phpSessionId = session_id();
            
            // Store session in database
            $sql = "INSERT INTO user_sessions (
                        session_id, php_session_id, clock_number, ip_address, user_agent,
                        created_at, last_activity, expires_at, is_active
                    ) VALUES (
                        :session_id, :php_session_id, :clock_number, :ip_address, :user_agent,
                        NOW(), NOW(), :expires_at, 1
                    )";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':session_id', $sessionId);
            $stmt->bindParam(':php_session_id', $phpSessionId);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->bindParam(':ip_address', $ipAddress);
            $stmt->bindParam(':user_agent', $userAgent);
            $stmt->bindParam(':expires_at', $expiresAt);
            $stmt->execute();
            
            // Store user data in PHP session
            $_SESSION['authenticated'] = true;
            $_SESSION['user'] = $userData;
            $_SESSION['session_id'] = $sessionId;
            $_SESSION['ip_address'] = $ipAddress;
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['session_fingerprint'] = $this->generateSessionFingerprint($ipAddress, $userAgent);
            
            // Log session creation
            $this->logSessionEvent($clockNumber, 'SESSION_CREATED', $sessionId, $ipAddress);
            
            return [
                'success' => true,
                'session_id' => $sessionId,
                'expires_at' => $expiresAt,
                'php_session_id' => $phpSessionId
            ];
            
        } catch (Exception $e) {
            error_log('Session creation error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Session creation failed'
            ];
        }
    }
    
    /**
     * Validate and refresh session
     * 
     * @param string $sessionId Session ID to validate
     * @return array Validation result
     */
    public function validateSession($sessionId = null) {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            // Use session ID from parameter or session
            if (!$sessionId) {
                $sessionId = $_SESSION['session_id'] ?? null;
            }
            
            if (!$sessionId) {
                return ['valid' => false, 'message' => 'No session ID provided'];
            }
            
            // Get session from database
            $sql = "SELECT s.*, u.active_status, u.real_name, r.role_name, r.permission_level
                    FROM user_sessions s
                    INNER JOIN users u ON s.clock_number = u.clock_number
                    INNER JOIN roles r ON u.role_id = r.id
                    WHERE s.session_id = :session_id AND s.is_active = 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':session_id', $sessionId);
            $stmt->execute();
            
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$session) {
                $this->clearSession();
                return ['valid' => false, 'message' => 'Invalid session'];
            }
            
            // Check if session expired
            if (strtotime($session['expires_at']) <= time()) {
                $this->deactivateSession($sessionId);
                $this->clearSession();
                return ['valid' => false, 'message' => 'Session expired'];
            }
            
            // Check if user is still active
            if (!$session['active_status']) {
                $this->deactivateSession($sessionId);
                $this->clearSession();
                return ['valid' => false, 'message' => 'User account disabled'];
            }
            
            // Verify session fingerprint for security
            $currentFingerprint = $this->generateSessionFingerprint(
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            );
            
            $storedFingerprint = $_SESSION['session_fingerprint'] ?? '';
            
            if ($storedFingerprint && $currentFingerprint !== $storedFingerprint) {
                $this->deactivateSession($sessionId);
                $this->clearSession();
                $this->logSessionEvent($session['clock_number'], 'SESSION_HIJACK_ATTEMPT', $sessionId);
                return ['valid' => false, 'message' => 'Session security violation'];
            }
            
            // Check for session timeout based on inactivity
            $lastActivity = $_SESSION['last_activity'] ?? 0;
            $inactivityTimeout = 3600; // 1 hour of inactivity
            
            if ((time() - $lastActivity) > $inactivityTimeout) {
                $this->deactivateSession($sessionId);
                $this->clearSession();
                return ['valid' => false, 'message' => 'Session timed out due to inactivity'];
            }
            
            // Update session activity
            $this->updateSessionActivity($sessionId);
            $_SESSION['last_activity'] = time();
            
            return [
                'valid' => true,
                'session' => $session,
                'time_remaining' => strtotime($session['expires_at']) - time()
            ];
            
        } catch (Exception $e) {
            error_log('Session validation error: ' . $e->getMessage());
            return ['valid' => false, 'message' => 'Session validation error'];
        }
    }
    
    /**
     * Extend session expiration
     * 
     * @param string $sessionId Session ID to extend
     * @param int $additionalTime Additional time in seconds
     * @return bool Success status
     */
    public function extendSession($sessionId = null, $additionalTime = null) {
        try {
            if (!$sessionId) {
                $sessionId = $_SESSION['session_id'] ?? null;
            }
            
            if (!$additionalTime) {
                $additionalTime = $this->sessionTimeout;
            }
            
            $newExpiresAt = date('Y-m-d H:i:s', time() + $additionalTime);
            
            $sql = "UPDATE user_sessions 
                    SET expires_at = :expires_at, last_activity = NOW() 
                    WHERE session_id = :session_id AND is_active = 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':expires_at', $newExpiresAt);
            $stmt->bindParam(':session_id', $sessionId);
            $stmt->execute();
            
            return $stmt->rowCount() > 0;
            
        } catch (Exception $e) {
            error_log('Session extension error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Destroy session completely
     * 
     * @param string $sessionId Session ID to destroy
     * @param string $reason Reason for destruction
     * @return bool Success status
     */
    public function destroySession($sessionId = null, $reason = 'USER_LOGOUT') {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            if (!$sessionId) {
                $sessionId = $_SESSION['session_id'] ?? null;
            }
            
            $clockNumber = null;
            
            if ($sessionId) {
                // Get user info for logging
                $sql = "SELECT clock_number FROM user_sessions WHERE session_id = :session_id";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindParam(':session_id', $sessionId);
                $stmt->execute();
                $session = $stmt->fetch(PDO::FETCH_ASSOC);
                $clockNumber = $session['clock_number'] ?? 'UNKNOWN';
                
                // Deactivate session in database
                $this->deactivateSession($sessionId);
                
                // Log session destruction
                $this->logSessionEvent($clockNumber, 'SESSION_DESTROYED', $sessionId, null, $reason);
            }
            
            // Clear PHP session
            $this->clearSession();
            
            return true;
            
        } catch (Exception $e) {
            error_log('Session destruction error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all active sessions for a user
     * 
     * @param string $clockNumber User's clock number
     * @return array Active sessions
     */
    public function getUserSessions($clockNumber) {
        try {
            $sql = "SELECT session_id, ip_address, user_agent, created_at, last_activity, expires_at
                    FROM user_sessions 
                    WHERE clock_number = :clock_number 
                    AND is_active = 1 
                    AND expires_at > NOW()
                    ORDER BY last_activity DESC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log('Get user sessions error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Terminate all sessions for a user except current
     * 
     * @param string $clockNumber User's clock number
     * @param string $exceptSessionId Session ID to keep active
     * @return int Number of sessions terminated
     */
    public function terminateUserSessions($clockNumber, $exceptSessionId = null) {
        try {
            $sql = "UPDATE user_sessions 
                    SET is_active = 0 
                    WHERE clock_number = :clock_number 
                    AND is_active = 1";
            
            $params = ['clock_number' => $clockNumber];
            
            if ($exceptSessionId) {
                $sql .= " AND session_id != :except_session_id";
                $params['except_session_id'] = $exceptSessionId;
            }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            $terminatedCount = $stmt->rowCount();
            
            if ($terminatedCount > 0) {
                $this->logSessionEvent($clockNumber, 'SESSIONS_TERMINATED', null, null, 
                    "Terminated $terminatedCount sessions");
            }
            
            return $terminatedCount;
            
        } catch (Exception $e) {
            error_log('Terminate user sessions error: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Clean up expired sessions
     * 
     * @return int Number of sessions cleaned up
     */
    public function cleanupExpiredSessions() {
        try {
            // Deactivate expired sessions
            $sql = "UPDATE user_sessions 
                    SET is_active = 0 
                    WHERE expires_at <= NOW() AND is_active = 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $deactivatedCount = $stmt->rowCount();
            
            // Delete old session records (older than 30 days)
            $sql = "DELETE FROM user_sessions 
                    WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $deletedCount = $stmt->rowCount();
            
            if ($deactivatedCount > 0 || $deletedCount > 0) {
                error_log("Session cleanup: Deactivated $deactivatedCount, Deleted $deletedCount old sessions");
            }
            
            return $deactivatedCount + $deletedCount;
            
        } catch (Exception $e) {
            error_log('Session cleanup error: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get session statistics
     * 
     * @return array Session statistics
     */
    public function getSessionStats() {
        try {
            $stats = [];
            
            // Active sessions count
            $sql = "SELECT COUNT(*) as active_count FROM user_sessions WHERE is_active = 1 AND expires_at > NOW()";
            $stmt = $this->pdo->query($sql);
            $stats['active_sessions'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['active_count'];
            
            // Sessions by user
            $sql = "SELECT clock_number, COUNT(*) as session_count 
                    FROM user_sessions 
                    WHERE is_active = 1 AND expires_at > NOW()
                    GROUP BY clock_number 
                    ORDER BY session_count DESC";
            $stmt = $this->pdo->query($sql);
            $stats['sessions_by_user'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Sessions created today
            $sql = "SELECT COUNT(*) as today_count FROM user_sessions WHERE DATE(created_at) = CURDATE()";
            $stmt = $this->pdo->query($sql);
            $stats['sessions_today'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['today_count'];
            
            // Average session duration (for completed sessions in last 7 days)
            $sql = "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, 
                        CASE WHEN is_active = 0 THEN last_activity ELSE expires_at END)) as avg_duration
                    FROM user_sessions 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            $stmt = $this->pdo->query($sql);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['avg_duration_seconds'] = (int)$result['avg_duration'];
            
            return $stats;
            
        } catch (Exception $e) {
            error_log('Session stats error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Generate secure session ID
     * 
     * @return string Secure session ID
     */
    private function generateSecureSessionId() {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Generate session fingerprint for security
     * 
     * @param string $ipAddress IP address
     * @param string $userAgent User agent
     * @return string Session fingerprint
     */
    private function generateSessionFingerprint($ipAddress, $userAgent) {
        return hash('sha256', $ipAddress . '|' . $userAgent . '|' . date('Y-m-d'));
    }
    
    /**
     * Update session activity timestamp
     * 
     * @param string $sessionId Session ID
     */
    private function updateSessionActivity($sessionId) {
        try {
            $sql = "UPDATE user_sessions SET last_activity = NOW() WHERE session_id = :session_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':session_id', $sessionId);
            $stmt->execute();
            
        } catch (Exception $e) {
            error_log('Session activity update error: ' . $e->getMessage());
        }
    }
    
    /**
     * Deactivate session in database
     * 
     * @param string $sessionId Session ID
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
     * Clear PHP session data
     */
    private function clearSession() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            
            // Destroy session cookie
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            
            session_destroy();
        }
    }
    
    /**
     * Enforce concurrent session limit per user
     * 
     * @param string $clockNumber User's clock number
     */
    private function enforceConcurrentSessionLimit($clockNumber) {
        try {
            // Get current active session count
            $sql = "SELECT COUNT(*) as session_count FROM user_sessions 
                    WHERE clock_number = :clock_number AND is_active = 1 AND expires_at > NOW()";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->execute();
            
            $sessionCount = (int)$stmt->fetch(PDO::FETCH_ASSOC)['session_count'];
            
            if ($sessionCount >= $this->maxConcurrentSessions) {
                // Deactivate oldest sessions
                $sql = "UPDATE user_sessions 
                        SET is_active = 0 
                        WHERE clock_number = :clock_number 
                        AND is_active = 1 
                        ORDER BY last_activity ASC 
                        LIMIT :limit";
                
                $limit = $sessionCount - $this->maxConcurrentSessions + 1;
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindParam(':clock_number', $clockNumber);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();
                
                $this->logSessionEvent($clockNumber, 'SESSION_LIMIT_ENFORCED', null, null, 
                    "Deactivated $limit old sessions due to concurrent session limit");
            }
            
        } catch (Exception $e) {
            error_log('Concurrent session limit enforcement error: ' . $e->getMessage());
        }
    }
    
    /**
     * Log session-related events
     * 
     * @param string $clockNumber User's clock number
     * @param string $eventType Event type
     * @param string $sessionId Session ID
     * @param string $ipAddress IP address
     * @param string $details Additional details
     */
    private function logSessionEvent($clockNumber, $eventType, $sessionId = null, $ipAddress = null, $details = null) {
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
            
            $description = $details ?: $eventType;
            if (!$ipAddress) {
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            }
            
            // Insert audit log
            $sql = "INSERT INTO audit_logs (
                        clock_number, real_name, action_type, action_description,
                        table_affected, record_id, ip_address, session_id, user_agent, severity_level
                    ) VALUES (
                        :clock_number, :real_name, :action_type, :action_description,
                        :table_affected, :record_id, :ip_address, :session_id, :user_agent, :severity_level
                    )";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':clock_number', $clockNumber);
            $stmt->bindParam(':real_name', $realName);
            $stmt->bindParam(':action_type', $eventType);
            $stmt->bindParam(':action_description', $description);
            $stmt->bindParam(':table_affected', 'user_sessions');
            $stmt->bindParam(':record_id', $sessionId);
            $stmt->bindParam(':ip_address', $ipAddress);
            $stmt->bindParam(':session_id', $sessionId);
            $stmt->bindParam(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? '');
            
            $severity = in_array($eventType, ['SESSION_HIJACK_ATTEMPT', 'SESSION_SECURITY_VIOLATION']) ? 'HIGH' : 'LOW';
            $stmt->bindParam(':severity_level', $severity);
            
            $stmt->execute();
            
        } catch (Exception $e) {
            error_log('Session event logging error: ' . $e->getMessage());
        }
    }
}
?>