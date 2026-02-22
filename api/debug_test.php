<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

echo json_encode([
    'success' => true,
    'method' => $_SERVER['REQUEST_METHOD'],
    'post_data' => $_POST,
    'raw_input' => file_get_contents('php://input'),
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
    'session_active' => session_status() === PHP_SESSION_ACTIVE,
    'timestamp' => date('Y-m-d H:i:s')
]);
?>