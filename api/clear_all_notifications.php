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
    
    // Delete all notifications for this member
    $stmt = $db->prepare("DELETE FROM member_notifications WHERE member_id = ?");
    $stmt->execute([$member_id]);
    $deleted_count = $stmt->rowCount();
    
    echo json_encode([
        'success' => true, 
        'deleted_count' => $deleted_count,
        'message' => "Cleared {$deleted_count} notifications"
    ]);
    
} catch (Exception $e) {
    error_log("Clear notifications error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error clearing notifications']);
}
?>