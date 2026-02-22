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
    $member_id = $_SESSION['reader_id'];
    
    // Mark all notifications as read
    $stmt = $db->prepare("
        UPDATE member_notifications 
        SET is_read = 1, read_at = NOW() 
        WHERE member_id = ? AND is_read = 0
    ");
    $stmt->execute([$member_id]);
    $affected_rows = $stmt->rowCount();
    
    echo json_encode([
        'success' => true, 
        'unread_count' => 0,
        'marked_count' => $affected_rows,
        'message' => "Marked {$affected_rows} notifications as read"
    ]);
    
} catch (Exception $e) {
    error_log("Mark all notifications error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error updating notifications']);
}
?>