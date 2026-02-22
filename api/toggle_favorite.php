<?php
// toggle_favorite.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../config/database.php';

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Log request details
error_log("Toggle favorite request - Method: " . $_SERVER['REQUEST_METHOD']);
error_log("POST data: " . print_r($_POST, true));
error_log("Session data: " . print_r($_SESSION, true));

// Check if user is logged in
if (!isset($_SESSION['reader_logged_in']) || $_SESSION['reader_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit();
}

// Get book_id - handle both POST and GET for flexibility
$book_id = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $book_id = intval($_POST['book_id'] ?? 0);
} else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $book_id = intval($_GET['book_id'] ?? 0);
}

$member_id = $_SESSION['reader_id'];

error_log("Processing favorite toggle - Member: $member_id, Book: $book_id");

if (!$book_id || $book_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid book ID provided']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Start transaction
    $db->beginTransaction();
    
    // Verify book exists and is active
    $book_check_stmt = $db->prepare("SELECT book_id, title FROM books WHERE book_id = ? AND status = 'active'");
    $book_check_stmt->execute([$book_id]);
    $book_exists = $book_check_stmt->fetch();
    
    if (!$book_exists) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Book not found or inactive']);
        exit();
    }
    
    // Check if already in favorites
    $stmt = $db->prepare("SELECT favorite_id FROM member_favorites WHERE member_id = ? AND book_id = ?");
    $stmt->execute([$member_id, $book_id]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Remove from favorites
        $delete_stmt = $db->prepare("DELETE FROM member_favorites WHERE member_id = ? AND book_id = ?");
        $delete_result = $delete_stmt->execute([$member_id, $book_id]);
        
        if ($delete_result && $delete_stmt->rowCount() > 0) {
            $db->commit();
            
            error_log("Book removed from favorites - Book: $book_id, Member: $member_id");
            
            echo json_encode([
                'success' => true, 
                'action' => 'removed', 
                'message' => "'{$book_exists['title']}' removed from favorites"
            ]);
        } else {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Failed to remove from favorites']);
        }
    } else {
        // Add to favorites
        $insert_stmt = $db->prepare("INSERT INTO member_favorites (member_id, book_id, added_date) VALUES (?, ?, NOW())");
        $insert_result = $insert_stmt->execute([$member_id, $book_id]);
        
        if ($insert_result) {
            $db->commit();
            
            error_log("Book added to favorites - Book: $book_id, Member: $member_id");
            
            echo json_encode([
                'success' => true, 
                'action' => 'added', 
                'message' => "'{$book_exists['title']}' added to favorites"
            ]);
        } else {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Failed to add to favorites']);
        }
    }
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Favorite toggle error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to update favorites: ' . $e->getMessage()]);
}
?>