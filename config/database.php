<?php
/**
 * BEMS Database Configuration
 * Best ERP Manufacturing System
 * 
 * Database connection settings for MySQL/MariaDB
 * Now uses environment variables for security
 */

require_once __DIR__ . '/env.php';

class DatabaseConfig {
    
    // Database connection parameters - loaded from environment
    public static function getDbHost() { return EnvLoader::get('DB_HOST', 'localhost'); }
    public static function getDbName() { return EnvLoader::get('DB_NAME', 'BEMS'); }
    public static function getDbUser() { return EnvLoader::get('DB_USER', 'root'); }
    public static function getDbPass() { return EnvLoader::get('DB_PASS', ''); }
    public static function getDbCharset() { return EnvLoader::get('DB_CHARSET', 'utf8mb4'); }
    public static function getDbCollation() { return EnvLoader::get('DB_COLLATION', 'utf8mb4_unicode_ci'); }
    
    // Legacy constants for backward compatibility (deprecated)
    const DB_HOST = 'localhost';  // @deprecated Use getDbHost() instead
    const DB_NAME = 'BEMS';       // @deprecated Use getDbName() instead
    const DB_USER = 'root';       // @deprecated Use getDbUser() instead
    const DB_PASS = '';           // @deprecated Use getDbPass() instead - removed hardcoded password
    const DB_CHARSET = 'utf8mb4'; // @deprecated Use getDbCharset() instead
    const DB_COLLATION = 'utf8mb4_unicode_ci'; // @deprecated Use getDbCollation() instead
    
    // Connection options
    const DB_OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ];
    
    /**
     * Get database connection
     * @return PDO Database connection instance
     */
    public static function getConnection() {
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                self::getDbHost(),
                self::getDbName(),
                self::getDbCharset()
            );
            
            return new PDO($dsn, self::getDbUser(), self::getDbPass(), self::DB_OPTIONS);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new Exception('Database connection failed');
        }
    }
}