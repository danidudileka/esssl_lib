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
    
    // Get total borrowed books
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM book_loans WHERE member_id = ?");
    $stmt->execute([$reader_id]);
    $total_borrowed = $stmt->fetch()['total'];
    
    // Get total read books (returned)
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM book_loans WHERE member_id = ? AND status = 'returned'");
    $stmt->execute([$reader_id]);
    $total_read = $stmt->fetch()['total'];
    
    // Get total favorites
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM member_favorites WHERE member_id = ?");
    $stmt->execute([$reader_id]);
    $total_favorites = $stmt->fetch()['total'];
    
    // Get current active loans
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM book_loans WHERE member_id = ? AND status IN ('active', 'overdue')");
    $stmt->execute([$reader_id]);
    $current_loans = $stmt->fetch()['total'];
    
    // Get overdue books
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM book_loans WHERE member_id = ? AND status = 'overdue'");
    $stmt->execute([$reader_id]);
    $overdue_books = $stmt->fetch()['total'];
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'total_borrowed' => (int)$total_borrowed,
            'total_read' => (int)$total_read,
            'total_favorites' => (int)$total_favorites,
            'current_loans' => (int)$current_loans,
            'overdue_books' => (int)$overdue_books
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Get user stats error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error loading stats']);
}
?>