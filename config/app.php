<?php
/**
 * BEMS Application Configuration
 * Best ERP Manufacturing System
 * 
 * Core application settings and constants
 */

class AppConfig {
    
    // Application info
    const APP_NAME = 'BEMS';
    const APP_VERSION = '1.0.0';
    const APP_DESCRIPTION = 'Best ERP Manufacturing System';
    
    // Environment settings
    const ENVIRONMENT = 'development'; // development, staging, production
    const DEBUG_MODE = true;
    
    // Security settings
    const SESSION_TIMEOUT = 3600; // 1 hour in seconds
    const PASSWORD_HASH_ALGO = PASSWORD_ARGON2ID;
    const CSRF_TOKEN_EXPIRY = 1800; // 30 minutes
    
    // API settings
    const API_VERSION = 'v1';
    const API_BASE_URL = '/api/v1';
    
    // File upload settings
    const MAX_UPLOAD_SIZE = '10M';
    const ALLOWED_UPLOAD_TYPES = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
    
    // Logging settings
    const LOG_LEVEL = 'DEBUG'; // DEBUG, INFO, WARNING, ERROR
    const LOG_FILE = '/logs/bems.log';
    
    // Audit settings
    const AUDIT_LOG_RETENTION_DAYS = 365;
    const AUDIT_SENSITIVE_FIELDS = ['password', 'hash', 'token'];
    
    /**
     * Get application base URL
     * @return string Base URL for the application
     */
    public static function getBaseUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $protocol . '://' . $host . '/bems';
    }
    
    /**
     * Get full log file path
     * @return string Complete path to log file
     */
    public static function getLogPath() {
        return dirname(__DIR__) . self::LOG_FILE;
    }
}