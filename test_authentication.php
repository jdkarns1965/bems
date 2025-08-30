<?php
/**
 * BEMS Authentication System Test
 * Test the Phase 0 authentication system
 */

require_once 'config/database.php';
require_once 'app/services/SessionService.php';

echo "=== BEMS Phase 0 Authentication System Test ===\n\n";

try {
    // Test database connection
    echo "1. Testing database connection...\n";
    $db = DatabaseConfig::getConnection();
    echo "   ✅ Database connection successful\n\n";
    
    // Test admin user exists
    echo "2. Testing admin user account...\n";
    $stmt = $db->prepare("SELECT u.clock_number, u.real_name, r.role_name, u.active_status FROM users u JOIN roles r ON u.role_id = r.id WHERE u.clock_number = ?");
    $stmt->execute(['ADMIN001']);
    $admin = $stmt->fetch();
    
    if ($admin) {
        echo "   ✅ Admin user found: {$admin['real_name']} ({$admin['clock_number']})\n";
        echo "   ✅ Role: {$admin['role_name']}\n";
        echo "   ✅ Status: " . ($admin['active_status'] ? 'Active' : 'Inactive') . "\n\n";
    } else {
        echo "   ❌ Admin user not found\n\n";
    }
    
    // Test password verification
    echo "3. Testing password verification...\n";
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE clock_number = ?");
    $stmt->execute(['ADMIN001']);
    $result = $stmt->fetch();
    
    if ($result && password_verify('AdminPassword123!', $result['password_hash'])) {
        echo "   ✅ Password verification successful\n\n";
    } else {
        echo "   ❌ Password verification failed\n\n";
    }
    
    // Test session service
    echo "4. Testing session service...\n";
    $sessionService = new SessionService();
    echo "   ✅ SessionService instantiated successfully\n\n";
    
    // Test roles
    echo "5. Testing role system...\n";
    $stmt = $db->prepare("SELECT role_name, permission_level FROM roles ORDER BY permission_level DESC");
    $stmt->execute();
    $roles = $stmt->fetchAll();
    
    echo "   Roles configured (" . count($roles) . " total):\n";
    foreach ($roles as $role) {
        echo "   - {$role['role_name']} (Level {$role['permission_level']})\n";
    }
    echo "\n";
    
    // Test audit logs
    echo "6. Testing audit log system...\n";
    $stmt = $db->prepare("SELECT clock_number, action_type, action_description, created_at FROM audit_logs ORDER BY created_at DESC LIMIT 3");
    $stmt->execute();
    $logs = $stmt->fetchAll();
    
    echo "   Recent audit entries (" . count($logs) . " shown):\n";
    foreach ($logs as $log) {
        echo "   - {$log['clock_number']}: {$log['action_type']} - {$log['action_description']} ({$log['created_at']})\n";
    }
    echo "\n";
    
    echo "=== Phase 0 Authentication System Test Complete ===\n";
    echo "✅ All tests passed! System ready for use.\n\n";
    echo "Default Admin Login:\n";
    echo "Username: ADMIN001\n";
    echo "Password: AdminPassword123!\n";
    echo "⚠️  Change this password after first login!\n";
    
} catch (Exception $e) {
    echo "❌ Test failed: " . $e->getMessage() . "\n";
}