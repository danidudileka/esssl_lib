<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

session_start();
require_once '../config/database.php';

if (!isset($_SESSION['reader_logged_in']) || $_SESSION['reader_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$reader_id = $_SESSION['reader_id'];

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get books with favorites info
    $books_stmt = $db->prepare("
        SELECT b.*, 
               CASE WHEN f.book_id IS NOT NULL THEN 1 ELSE 0 END as is_favorite
        FROM books b
        LEFT JOIN member_favorites f ON b.book_id = f.book_id AND f.member_id = ?
        WHERE b.status = 'active'
        ORDER BY b.title ASC
        LIMIT 100
    ");
    $books_stmt->execute([$reader_id]);
    $books = $books_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get my books
    $my_books_stmt = $db->prepare("
        SELECT 
            bl.*,
            b.title, 
            b.author, 
            b.cover_image,
            b.isbn,
            b.rack_number,
            b.dewey_decimal_number,
            b.dewey_classification,
            b.shelf_position,
            b.floor_level,
            CASE 
                WHEN bl.approval_status = 'pending' THEN 'pending'
                WHEN bl.status = 'active' AND bl.due_date < CURDATE() THEN 'overdue'
                WHEN bl.status = 'overdue' THEN 'overdue'
                WHEN bl.status = 'active' THEN 'active'
                ELSE bl.status
            END as display_status
        FROM book_loans bl
        JOIN books b ON bl.book_id = b.book_id
        WHERE bl.member_id = ? 
        AND (bl.status IN ('active', 'overdue') OR bl.approval_status = 'pending')
        ORDER BY bl.loan_date DESC
    ");
    $my_books_stmt->execute([$reader_id]);
    $my_books = $my_books_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get history
    $history_stmt = $db->prepare("
        SELECT bl.*, b.title, b.author, b.cover_image, b.isbn,
               CASE WHEN f.book_id IS NOT NULL THEN 1 ELSE 0 END as is_favorite
        FROM book_loans bl
        JOIN books b ON bl.book_id = b.book_id
        LEFT JOIN member_favorites f ON b.book_id = f.book_id AND f.member_id = ?
        WHERE bl.member_id = ? AND bl.status = 'returned'
        ORDER BY bl.return_date DESC
        LIMIT 50
    ");
    $history_stmt->execute([$reader_id, $reader_id]);
    $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get favorites
    $favorites_stmt = $db->prepare("
        SELECT b.*, f.added_date
        FROM member_favorites f
        JOIN books b ON f.book_id = b.book_id
        WHERE f.member_id = ? AND b.status = 'active'
        ORDER BY f.added_date DESC
    ");
    $favorites_stmt->execute([$reader_id]);
    $favorites = $favorites_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get notifications
    $notifications_stmt = $db->prepare("
        SELECT * FROM member_notifications 
        WHERE member_id = ? 
        ORDER BY created_at DESC 
        LIMIT 50
    ");
    $notifications_stmt->execute([$reader_id]);
    $notifications = $notifications_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'books' => $books,
        'myBooks' => $my_books,
        'history' => $history,
        'favorites' => $favorites,
        'notifications' => $notifications
    ]);
    
} catch (PDOException $e) {
    error_log("Live data error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
} catch (Exception $e) {
    error_log("Live data error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error']);
}
?>