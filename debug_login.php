<?php
// Debug login endpoint
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== DEBUG LOGIN ===\n";
echo "Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
echo "Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set') . "\n";

$rawInput = file_get_contents('php://input');
echo "Raw input: " . $rawInput . "\n";
echo "Raw input length: " . strlen($rawInput) . "\n";

$input = json_decode($rawInput, true);
echo "Decoded input: " . print_r($input, true) . "\n";
echo "JSON error: " . json_last_error_msg() . "\n";

echo "POST data: " . print_r($_POST, true) . "\n";
echo "GET data: " . print_r($_GET, true) . "\n";

// Test direct authentication
if (!empty($input['clock_number']) && !empty($input['password'])) {
    require_once 'config/database.php';
    
    $db = DatabaseConfig::getConnection();
    $stmt = $db->prepare('SELECT password_hash FROM users WHERE clock_number = ?');
    $stmt->execute([$input['clock_number']]);
    $result = $stmt->fetch();
    
    if ($result && password_verify($input['password'], $result['password_hash'])) {
        echo "AUTH SUCCESS: Password matches!\n";
    } else {
        echo "AUTH FAILED: Password does not match\n";
    }
}
?>