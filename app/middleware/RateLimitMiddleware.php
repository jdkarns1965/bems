<?php
/**
 * BEMS Rate Limiting Middleware
 * Best ERP Manufacturing System
 * 
 * Provides rate limiting protection against brute force attacks and API abuse
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';

class RateLimitMiddleware {
    
    private $pdo;
    
    // Rate limiting configurations
    const LOGIN_ATTEMPTS_LIMIT = 5;           // Max login attempts per IP
    const LOGIN_WINDOW_MINUTES = 15;          // Time window for login attempts
    const LOGIN_LOCKOUT_MINUTES = 0;          // Lockout duration after max attempts (disabled for development)
    
    const API_REQUESTS_LIMIT = 100;           // Max API requests per IP
    const API_WINDOW_MINUTES = 5;             // Time window for API requests
    const API_LOCKOUT_MINUTES = 10;           // Lockout duration for API abuse
    
    const GLOBAL_REQUESTS_LIMIT = 1000;       // Max global requests per IP per hour
    const GLOBAL_WINDOW_MINUTES = 60;         // Global rate limit window
    
    public function __construct() {
        $this->pdo = DatabaseConfig::getConnection();
        $this->initializeRateLimitTable();
    }
    
    /**
     * Handle rate limiting for login attempts
     * 
     * @param callable $next Next middleware/handler
     * @return mixed Response from next handler or rate limit error
     */
    public function loginRateLimit($next) {
        try {
            $ipAddress = $this->getClientIp();
            $endpoint = 'login';
            
            // Check if IP is currently locked out for login attempts
            if ($this->isLockedOut($ipAddress, $endpoint)) {
                $lockoutInfo = $this->getLockoutInfo($ipAddress, $endpoint);
                return $this->rateLimitResponse(
                    "Too many login attempts. Try again after " . date('H:i:s', $lockoutInfo['locked_until']),
                    429,
                    $lockoutInfo
                );
            }
            
            // Check current attempt count
            $attemptCount = $this->getAttemptCount($ipAddress, $endpoint, self::LOGIN_WINDOW_MINUTES);
            
            if ($attemptCount >= self::LOGIN_ATTEMPTS_LIMIT) {
                // Lock out the IP
                $this->lockoutIp($ipAddress, $endpoint, self::LOGIN_LOCKOUT_MINUTES);
                
                return $this->rateLimitResponse(
                    "Login rate limit exceeded. IP locked for " . self::LOGIN_LOCKOUT_MINUTES . " minutes",
                    429
                );
            }
            
            // Record this attempt
            $this->recordAttempt($ipAddress, $endpoint);
            
            // Add rate limit headers
            $this->addRateLimitHeaders(self::LOGIN_ATTEMPTS_LIMIT, $attemptCount);
            
            return $next();
            
        } catch (Exception $e) {
            error_log('Login rate limit error: ' . $e->getMessage());
            // Don't block requests on rate limiting system errors
            return $next();
        }
    }
    
    /**
     * Handle general API rate limiting
     * 
     * @param callable $next Next middleware/handler
     * @return mixed Response from next handler or rate limit error
     */
    public function apiRateLimit($next) {
        try {
            $ipAddress = $this->getClientIp();
            $endpoint = 'api';
            
            // Check if IP is currently locked out
            if ($this->isLockedOut($ipAddress, $endpoint)) {
                $lockoutInfo = $this->getLockoutInfo($ipAddress, $endpoint);
                return $this->rateLimitResponse(
                    "API rate limit exceeded. Try again after " . date('H:i:s', $lockoutInfo['locked_until']),
                    429,
                    $lockoutInfo
                );
            }
            
            // Check current request count
            $requestCount = $this->getAttemptCount($ipAddress, $endpoint, self::API_WINDOW_MINUTES);
            
            if ($requestCount >= self::API_REQUESTS_LIMIT) {
                // Lock out the IP
                $this->lockoutIp($ipAddress, $endpoint, self::API_LOCKOUT_MINUTES);
                
                return $this->rateLimitResponse(
                    "API rate limit exceeded. Too many requests",
                    429
                );
            }
            
            // Record this request
            $this->recordAttempt($ipAddress, $endpoint);
            
            // Add rate limit headers
            $this->addRateLimitHeaders(self::API_REQUESTS_LIMIT, $requestCount);
            
            return $next();
            
        } catch (Exception $e) {
            error_log('API rate limit error: ' . $e->getMessage());
            // Don't block requests on rate limiting system errors
            return $next();
        }
    }
    
    /**
     * Handle global rate limiting (all requests)
     * 
     * @param callable $next Next middleware/handler
     * @return mixed Response from next handler or rate limit error
     */
    public function globalRateLimit($next) {
        try {
            $ipAddress = $this->getClientIp();
            $endpoint = 'global';
            
            // Check current request count
            $requestCount = $this->getAttemptCount($ipAddress, $endpoint, self::GLOBAL_WINDOW_MINUTES);
            
            if ($requestCount >= self::GLOBAL_REQUESTS_LIMIT) {
                return $this->rateLimitResponse(
                    "Global rate limit exceeded. Too many requests per hour",
                    429
                );
            }
            
            // Record this request
            $this->recordAttempt($ipAddress, $endpoint);
            
            // Add rate limit headers
            $this->addRateLimitHeaders(self::GLOBAL_REQUESTS_LIMIT, $requestCount);
            
            return $next();
            
        } catch (Exception $e) {
            error_log('Global rate limit error: ' . $e->getMessage());
            // Don't block requests on rate limiting system errors
            return $next();
        }
    }
    
    /**
     * Custom rate limiting with specified limits
     * 
     * @param int $limit Request limit
     * @param int $windowMinutes Time window in minutes
     * @param string $endpoint Endpoint identifier
     * @param callable $next Next middleware/handler
     * @return mixed Response from next handler or rate limit error
     */
    public function customRateLimit($limit, $windowMinutes, $endpoint, $next) {
        try {
            $ipAddress = $this->getClientIp();
            
            // Check current request count
            $requestCount = $this->getAttemptCount($ipAddress, $endpoint, $windowMinutes);
            
            if ($requestCount >= $limit) {
                return $this->rateLimitResponse(
                    "Rate limit exceeded for $endpoint",
                    429
                );
            }
            
            // Record this request
            $this->recordAttempt($ipAddress, $endpoint);
            
            // Add rate limit headers
            $this->addRateLimitHeaders($limit, $requestCount);
            
            return $next();
            
        } catch (Exception $e) {
            error_log("Custom rate limit error for $endpoint: " . $e->getMessage());
            return $next();
        }
    }
    
    /**
     * Record failed login attempt for rate limiting
     * 
     * @param string $ipAddress Client IP address
     * @param string $clockNumber Attempted clock number
     */
    public function recordFailedLogin($ipAddress, $clockNumber = null) {
        try {
            // Record general login attempt
            $this->recordAttempt($ipAddress, 'login');
            
            // Record specific user attempt if clock number provided
            if ($clockNumber) {
                $this->recordAttempt($ipAddress, 'login_user_' . $clockNumber);
            }
            
        } catch (Exception $e) {
            error_log('Failed login recording error: ' . $e->getMessage());
        }
    }
    
    /**
     * Check if specific user has too many failed login attempts
     * 
     * @param string $clockNumber Clock number to check
     * @param string $ipAddress IP address
     * @return bool True if user should be locked out
     */
    public function isUserLoginBlocked($clockNumber, $ipAddress) {
        try {
            $userEndpoint = 'login_user_' . $clockNumber;
            $attempts = $this->getAttemptCount($ipAddress, $userEndpoint, self::LOGIN_WINDOW_MINUTES);
            
            return $attempts >= self::LOGIN_ATTEMPTS_LIMIT;
            
        } catch (Exception $e) {
            error_log('User login block check error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Initialize rate limiting table
     */
    private function initializeRateLimitTable() {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS rate_limits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                endpoint VARCHAR(100) NOT NULL,
                attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                locked_until TIMESTAMP NULL,
                INDEX idx_ip_endpoint (ip_address, endpoint),
                INDEX idx_attempt_time (attempt_time),
                INDEX idx_locked_until (locked_until)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $this->pdo->exec($sql);
            
        } catch (Exception $e) {
            error_log('Rate limit table initialization error: ' . $e->getMessage());
        }
    }
    
    /**
     * Record an attempt for rate limiting
     * 
     * @param string $ipAddress IP address
     * @param string $endpoint Endpoint identifier
     */
    private function recordAttempt($ipAddress, $endpoint) {
        try {
            $sql = "INSERT INTO rate_limits (ip_address, endpoint) VALUES (?, ?)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$ipAddress, $endpoint]);
            
        } catch (Exception $e) {
            error_log('Rate limit attempt recording error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get attempt count within time window
     * 
     * @param string $ipAddress IP address
     * @param string $endpoint Endpoint identifier
     * @param int $windowMinutes Time window in minutes
     * @return int Number of attempts
     */
    private function getAttemptCount($ipAddress, $endpoint, $windowMinutes) {
        try {
            $sql = "SELECT COUNT(*) as attempt_count 
                    FROM rate_limits 
                    WHERE ip_address = ? 
                    AND endpoint = ? 
                    AND attempt_time >= DATE_SUB(NOW(), INTERVAL ? MINUTE)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$ipAddress, $endpoint, $windowMinutes]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['attempt_count'];
            
        } catch (Exception $e) {
            error_log('Rate limit count error: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Lock out IP for specific endpoint
     * 
     * @param string $ipAddress IP address
     * @param string $endpoint Endpoint identifier
     * @param int $lockoutMinutes Lockout duration in minutes
     */
    private function lockoutIp($ipAddress, $endpoint, $lockoutMinutes) {
        try {
            $lockedUntil = date('Y-m-d H:i:s', time() + ($lockoutMinutes * 60));
            
            $sql = "INSERT INTO rate_limits (ip_address, endpoint, locked_until) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$ipAddress, $endpoint . '_lockout', $lockedUntil]);
            
        } catch (Exception $e) {
            error_log('IP lockout error: ' . $e->getMessage());
        }
    }
    
    /**
     * Check if IP is currently locked out
     * 
     * @param string $ipAddress IP address
     * @param string $endpoint Endpoint identifier
     * @return bool True if locked out
     */
    private function isLockedOut($ipAddress, $endpoint) {
        try {
            $sql = "SELECT locked_until FROM rate_limits 
                    WHERE ip_address = ? 
                    AND endpoint = ? 
                    AND locked_until > NOW() 
                    ORDER BY locked_until DESC LIMIT 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$ipAddress, $endpoint . '_lockout']);
            
            return $stmt->rowCount() > 0;
            
        } catch (Exception $e) {
            error_log('Lockout check error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get lockout information
     * 
     * @param string $ipAddress IP address
     * @param string $endpoint Endpoint identifier
     * @return array|null Lockout information
     */
    private function getLockoutInfo($ipAddress, $endpoint) {
        try {
            $sql = "SELECT locked_until FROM rate_limits 
                    WHERE ip_address = ? 
                    AND endpoint = ? 
                    AND locked_until > NOW() 
                    ORDER BY locked_until DESC LIMIT 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$ipAddress, $endpoint . '_lockout']);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return [
                    'locked_until' => strtotime($result['locked_until']),
                    'locked_until_formatted' => $result['locked_until']
                ];
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log('Lockout info error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Clean up old rate limit records
     */
    public function cleanup() {
        try {
            // Clean up old attempts (older than 24 hours)
            $sql = "DELETE FROM rate_limits 
                    WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    AND (locked_until IS NULL OR locked_until < NOW())";
            
            $this->pdo->exec($sql);
            
        } catch (Exception $e) {
            error_log('Rate limit cleanup error: ' . $e->getMessage());
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
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
    
    /**
     * Add rate limit headers to response
     * 
     * @param int $limit Request limit
     * @param int $current Current request count
     */
    private function addRateLimitHeaders($limit, $current) {
        $remaining = max(0, $limit - $current - 1);
        
        header("X-RateLimit-Limit: $limit");
        header("X-RateLimit-Remaining: $remaining");
        header("X-RateLimit-Used: " . ($current + 1));
    }
    
    /**
     * Create rate limit response
     * 
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param array $additional Additional response data
     * @return array JSON response
     */
    private function rateLimitResponse($message, $statusCode = 429, $additional = []) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        header('Retry-After: 300'); // Suggest retry after 5 minutes
        
        $response = array_merge([
            'success' => false,
            'message' => $message,
            'error_code' => 'RATE_LIMIT_EXCEEDED',
            'timestamp' => date('c'),
            'status_code' => $statusCode
        ], $additional);
        
        return $response;
    }
    
    /**
     * Create middleware for specific rate limiting
     * 
     * @param string $type Type of rate limiting (login, api, global)
     * @return callable Middleware function
     */
    public static function for($type) {
        return function($next) use ($type) {
            $rateLimit = new RateLimitMiddleware();
            switch ($type) {
                case 'login':
                    return $rateLimit->loginRateLimit($next);
                case 'api':
                    return $rateLimit->apiRateLimit($next);
                case 'global':
                    return $rateLimit->globalRateLimit($next);
                default:
                    return $next();
            }
        };
    }
}
?>