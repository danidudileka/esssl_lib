<?php
header('Content-Type: application/json');
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['reader_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$notification_id = $_POST['notification_id'] ?? $_GET['notification_id'] ?? null;

if (!$notification_id) {
    echo json_encode(['success' => false, 'message' => 'Missing notification ID']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $stmt = $db->prepare("UPDATE member_notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ? AND member_id = ?");
    $result = $stmt->execute([$notification_id, $_SESSION['reader_id']]);
    
    if ($result && $stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Notification not found or already read']);
    }
} catch (PDOException $e) {
    error_log("Mark notification read error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
} catch (Exception $e) {
    error_log("Mark notification read error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error']);
}
?>