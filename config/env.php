<?php
/**
 * BEMS Environment Configuration Loader
 * Best ERP Manufacturing System
 * 
 * Simple .env file loader for database and application configuration
 */

class EnvLoader {
    
    private static $loaded = false;
    
    /**
     * Load environment variables from .env file
     * 
     * @param string $path Path to .env file
     * @return bool True if loaded successfully
     */
    public static function load($path = null) {
        if (self::$loaded) {
            return true;
        }
        
        if ($path === null) {
            $path = dirname(__DIR__) . '/.env';
        }
        
        if (!file_exists($path)) {
            // Try to use .env.example as fallback
            $examplePath = dirname(__DIR__) . '/.env.example';
            if (file_exists($examplePath)) {
                error_log('Warning: .env file not found, using .env.example. Copy .env.example to .env and configure.');
                $path = $examplePath;
            } else {
                error_log('Error: No .env file found at: ' . $path);
                return false;
            }
        }
        
        try {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            
            foreach ($lines as $line) {
                // Skip comments and empty lines
                if (strpos(trim($line), '#') === 0 || empty(trim($line))) {
                    continue;
                }
                
                // Parse KEY=VALUE format
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    
                    // Remove quotes if present
                    $value = self::parseValue($value);
                    
                    // Set environment variable if not already set
                    if (!isset($_ENV[$key])) {
                        $_ENV[$key] = $value;
                        putenv($key . '=' . $value);
                    }
                }
            }
            
            self::$loaded = true;
            return true;
            
        } catch (Exception $e) {
            error_log('Error loading .env file: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Parse environment value
     * 
     * @param string $value Raw value from .env file
     * @return string Parsed value
     */
    private static function parseValue($value) {
        // Remove surrounding quotes
        if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
            (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }
        
        // Handle boolean values
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'null':
            case '(null)':
                return null;
        }
        
        return $value;
    }
    
    /**
     * Get environment variable with optional default
     * 
     * @param string $key Environment variable key
     * @param mixed $default Default value if key not found
     * @return mixed Environment variable value or default
     */
    public static function get($key, $default = null) {
        return $_ENV[$key] ?? $default;
    }
    
    /**
     * Check if environment variable exists
     * 
     * @param string $key Environment variable key
     * @return bool True if exists
     */
    public static function has($key) {
        return isset($_ENV[$key]);
    }
}

// Auto-load environment on include
EnvLoader::load();
?>