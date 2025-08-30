<?php
/**
 * BEMS Database Configuration
 * Best ERP Manufacturing System
 * 
 * Database connection settings for MySQL/MariaDB
 */

class DatabaseConfig {
    
    // Database connection parameters
    const DB_HOST = 'localhost';
    const DB_NAME = 'BEMS';
    const DB_USER = 'root';
    const DB_PASS = 'passgas1989';
    const DB_CHARSET = 'utf8mb4';
    const DB_COLLATION = 'utf8mb4_unicode_ci';
    
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
                self::DB_HOST,
                self::DB_NAME,
                self::DB_CHARSET
            );
            
            return new PDO($dsn, self::DB_USER, self::DB_PASS, self::DB_OPTIONS);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new Exception('Database connection failed');
        }
    }
}