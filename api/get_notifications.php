<?php
header('Content-Type: application/json');
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['reader_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $reader_id = $_SESSION['reader_id'];
    
    $stmt = $db->prepare("
        SELECT * FROM member_notifications 
        WHERE member_id = ? 
        ORDER BY created_at DESC 
        LIMIT 50
    ");
    $stmt->execute([$reader_id]);
    
    $notifications = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $notifications[] = [
            'notification_id' => (int)$row['notification_id'],
            'title' => $row['title'],
            'message' => $row['message'],
            'type' => $row['type'],
            'is_read' => (bool)$row['is_read'],
            'created_at' => $row['created_at'],
            'read_at' => $row['read_at'],
            'book_id' => $row['book_id'],
            'loan_id' => $row['loan_id'],
            'rack_number' => $row['rack_number'],
            'additional_data' => $row['additional_data']
        ];
    }
    
    echo json_encode(['success' => true, 'notifications' => $notifications]);
    
} catch (Exception $e) {
    error_log("Get notifications error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error loading notifications']);
}
?>