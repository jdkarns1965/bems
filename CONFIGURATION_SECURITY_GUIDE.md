# BEMS Configuration Security Guide

## Overview

This document describes the centralized configuration system implemented in BEMS to eliminate hardcoded database credentials and improve security.

## Security Improvements Made

### ✅ Issues Fixed

1. **Hardcoded Database Password Removed**
   - Removed `passgas1989` from `/var/www/html/bems/config/database.php`
   - Updated `initialize_inventory_module.php` to use centralized config
   - All database connections now use environment variables

2. **Environment-Based Configuration**
   - Created `.env` file for production credentials
   - Created `.env.example` template for setup
   - Implemented `EnvLoader` class for environment variable management

3. **Git Security**
   - Updated `.gitignore` to prevent credential leaks
   - `.env` files are excluded from version control
   - Database config file is now safe to commit

## File Structure

```
/var/www/html/bems/
├── .env                    # Production environment (SENSITIVE - NOT IN GIT)
├── .env.example           # Template file (safe to commit)
├── .gitignore            # Updated to exclude .env files
├── config/
│   ├── database.php      # Updated to use environment variables
│   └── env.php           # Environment variable loader
└── CONFIGURATION_SECURITY_GUIDE.md
```

## Environment Variables

### Database Configuration
```bash
DB_HOST=localhost
DB_NAME=BEMS
DB_USER=root
DB_PASS=passgas1989
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```

### Application Configuration
```bash
APP_ENV=production
APP_DEBUG=false
APP_URL=http://localhost
SESSION_TIMEOUT=28800
```

## Setup Instructions

### For New Installations

1. **Copy environment template:**
   ```bash
   cd /var/www/html/bems
   cp .env.example .env
   ```

2. **Configure database credentials:**
   ```bash
   nano .env
   # Update DB_* variables with your database credentials
   ```

3. **Secure the .env file:**
   ```bash
   chmod 600 .env
   chown www-data:www-data .env
   ```

### For Existing Installations

The system includes backward compatibility:
- Legacy constants still work but are deprecated
- Environment variables take precedence
- No immediate code changes required

## Usage Examples

### Using Environment Variables in Code

```php
// Load environment variables
require_once '/var/www/html/bems/config/env.php';

// Get database configuration
$host = EnvLoader::get('DB_HOST', 'localhost');
$password = EnvLoader::get('DB_PASS', '');

// Check if variable exists
if (EnvLoader::has('DB_PASS')) {
    // Variable is set
}
```

### Using Updated Database Config

```php
// Old way (deprecated but still works)
$host = DatabaseConfig::DB_HOST;
$pass = DatabaseConfig::DB_PASS; // Now returns empty string

// New way (recommended)
$host = DatabaseConfig::getDbHost();
$pass = DatabaseConfig::getDbPass();

// Best way (direct connection)
$pdo = DatabaseConfig::getConnection();
```

## Security Benefits

1. **No Hardcoded Credentials**
   - Database passwords are no longer in source code
   - Different environments can use different credentials
   - Credentials can be rotated without code changes

2. **Environment Separation**
   - Development, staging, and production use separate .env files
   - Prevents accidental credential exposure

3. **Version Control Safety**
   - .env files are excluded from Git
   - Only templates (.env.example) are committed
   - Eliminates credential leaks in repositories

4. **Principle of Least Privilege**
   - Environment variables are only accessible to the web server
   - File permissions can restrict access to .env files

## Migration Status

### ✅ Completed
- `config/database.php` - Updated to use environment variables
- `database/initialize_inventory_module.php` - Updated to use centralized config
- `database/initialize_bems_database.php` - Already uses centralized config
- `app/services/AuthenticationService.php` - Already uses centralized config
- All other core files - Already use centralized config

### ⚠️ Remaining Files (Reference Only)
- `public/test-login.php` - Test file, uses centralized config
- `public/login-fixed.html` - Frontend file, hardcoded test passwords are UI defaults
- `public/working-login.html` - Frontend file, hardcoded test passwords are UI defaults
- Database migration files - Contain example passwords in comments

## Best Practices

### Environment File Management

1. **Never commit .env files to Git**
2. **Use strong, unique passwords in production**
3. **Restrict file permissions on .env files**
4. **Keep .env.example updated with new variables**

### Code Standards

```php
// ✅ Good - Use environment loader
$password = EnvLoader::get('DB_PASS');

// ✅ Good - Use centralized config methods
$password = DatabaseConfig::getDbPass();

// ❌ Bad - Direct environment access
$password = $_ENV['DB_PASS'];

// ❌ Bad - Hardcoded values
$password = 'hardcoded_password';
```

### Error Handling

```php
// Check if environment loaded successfully
if (!EnvLoader::load()) {
    error_log('Failed to load environment configuration');
    throw new Exception('Configuration error');
}

// Use defaults for missing variables
$timeout = EnvLoader::get('SESSION_TIMEOUT', 3600);
```

## Troubleshooting

### Common Issues

1. **"Configuration error" when connecting**
   - Check if `.env` file exists
   - Verify database credentials in `.env`
   - Check file permissions on `.env`

2. **Environment variables not loading**
   - Ensure `config/env.php` is included
   - Check for syntax errors in `.env` file
   - Verify file path in EnvLoader::load()

3. **Database connection fails**
   - Test credentials manually: `mysql -h localhost -u root -p BEMS`
   - Check if MySQL service is running
   - Verify database name exists

### Debugging

```php
// Enable environment debugging
EnvLoader::load();
var_dump($_ENV); // Show all loaded environment variables

// Test specific values
echo "DB Host: " . EnvLoader::get('DB_HOST') . "\n";
echo "DB User: " . EnvLoader::get('DB_USER') . "\n";
echo "DB Pass set: " . (EnvLoader::has('DB_PASS') ? 'Yes' : 'No') . "\n";
```

## Security Checklist

- [ ] `.env` file exists and contains correct credentials
- [ ] `.env` file has restricted permissions (600)
- [ ] `.gitignore` excludes `.env` files
- [ ] No hardcoded passwords in source code
- [ ] Environment variables are used for all sensitive configuration
- [ ] `.env.example` is kept updated
- [ ] Production uses different credentials than development

## Support

For questions or issues with the configuration system:

1. Check this documentation
2. Review the `.env.example` file
3. Test with the provided debugging code
4. Check system logs for error messages

---

**Important:** Always change default passwords in production environments and never commit `.env` files to version control.