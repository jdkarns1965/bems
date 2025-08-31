<?php
// Simple working API for BEMS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$route = $_GET['route'] ?? '';

// Simple database connection
try {
    $pdo = new PDO('mysql:host=localhost;dbname=BEMS;charset=utf8mb4', 'root', 'passgas1989');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

switch ($route) {
    case 'materials':
        $stmt = $pdo->query("SELECT * FROM materials LIMIT 50");
        $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($materials);
        break;
        
    case 'locations':
        $stmt = $pdo->query("SELECT * FROM locations LIMIT 50");
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($locations);
        break;
        
    case 'inventory':
        $stmt = $pdo->query("
            SELECT i.*, m.material_name, l.location_name 
            FROM inventory i
            LEFT JOIN materials m ON i.material_number = m.material_number
            LEFT JOIN locations l ON i.location_code = l.location_code
            ORDER BY i.created_at DESC 
            LIMIT 100
        ");
        $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($inventory);
        break;
        
    case 'bom':
        $stmt = $pdo->query("
            SELECT bh.*, COUNT(bl.id) as component_count
            FROM bom_headers bh
            LEFT JOIN bom_lines bl ON bh.bom_id = bl.bom_id
            GROUP BY bh.bom_id
            ORDER BY bh.created_at DESC
            LIMIT 50
        ");
        $boms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($boms);
        break;
        
    default:
        echo json_encode(['error' => 'Route not found: ' . $route]);
        break;
}
?>