<?php
// Simple login test
require_once '../config/database.php';
require_once '../app/services/AuthenticationService.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Get JSON input
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (!$data) {
        // Try to fix escaped JSON
        $json = stripslashes($json);
        $data = json_decode($json, true);
        
        if (!$data) {
            echo json_encode(['error' => 'Invalid JSON', 'received' => $json, 'json_error' => json_last_error_msg()]);
            exit;
        }
    }
    
    if (empty($data['clock_number']) || empty($data['password'])) {
        echo json_encode(['error' => 'Missing credentials']);
        exit;
    }
    
    try {
        $authService = new AuthenticationService();
        $result = $authService->authenticate(
            $data['clock_number'],
            $data['password'],
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            $_SERVER['HTTP_USER_AGENT'] ?? 'Test'
        );
        
        if ($result['success']) {
            session_start();
            $_SESSION['user'] = $result['user'];
            $_SESSION['authenticated'] = true;
            
            echo json_encode([
                'success' => true,
                'user' => $result['user'],
                'message' => 'Login successful'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $result['message']
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Authentication error: ' . $e->getMessage()
        ]);
    }
    
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Test Login</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 500px; margin: 50px auto; padding: 20px; }
        .form-group { margin: 15px 0; }
        .form-group label { display: block; margin-bottom: 5px; }
        .form-group input { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .btn { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        .result { margin: 20px 0; padding: 15px; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <h1>BEMS Login Test</h1>
    
    <form id="loginForm">
        <div class="form-group">
            <label>Clock Number:</label>
            <input type="text" id="clockNumber" value="ADMIN001">
        </div>
        
        <div class="form-group">
            <label>Password:</label>
            <input type="password" id="password" value="AdminPassword123!">
        </div>
        
        <button type="submit" class="btn">Test Login</button>
    </form>
    
    <div id="result"></div>
    
    <script>
        document.getElementById('loginForm').onsubmit = function(e) {
            e.preventDefault();
            
            const data = {
                clock_number: document.getElementById('clockNumber').value,
                password: document.getElementById('password').value
            };
            
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                const div = document.getElementById('result');
                if (result.success) {
                    div.innerHTML = `<div class="result success">
                        ✅ Login successful!<br>
                        User: ${result.user.real_name} (${result.user.clock_number})<br>
                        Role: ${result.user.role_name}
                    </div>`;
                } else {
                    div.innerHTML = `<div class="result error">
                        ❌ Login failed: ${result.message || 'Unknown error'}
                    </div>`;
                }
            })
            .catch(error => {
                document.getElementById('result').innerHTML = `<div class="result error">
                    ❌ Error: ${error.message}
                </div>`;
            });
        };
    </script>
</body>
</html>