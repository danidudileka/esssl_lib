<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$book_id = $_POST['book_id'] ?? null;

if (!isset($_SESSION['reader_logged_in']) || $_SESSION['reader_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Please login to borrow books']);
    exit();
}

if (!$book_id || !is_numeric($book_id)) {
    echo json_encode(['success' => false, 'message' => 'Valid book ID is required']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $member_id = $_SESSION['reader_id'];
    
    // Start transaction
    $db->beginTransaction();
    
    // Check if member has any pending or active loans for this book
    $existing_loan_stmt = $db->prepare("
        SELECT loan_id FROM book_loans 
        WHERE member_id = ? AND book_id = ? 
        AND (status IN ('active', 'overdue') OR approval_status = 'pending')
    ");
    $existing_loan_stmt->execute([$member_id, $book_id]);
    
    if ($existing_loan_stmt->fetch()) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'You already have a pending or active loan for this book']);
        exit();
    }
    
    // Get book details and check availability
    $book_stmt = $db->prepare("SELECT title, author, available_copies FROM books WHERE book_id = ? AND status = 'active'");
    $book_stmt->execute([$book_id]);
    $book = $book_stmt->fetch();
    
    if (!$book) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Book not found or inactive']);
        exit();
    }
    
    if ($book['available_copies'] <= 0) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Book is currently not available']);
        exit();
    }
    
    // Update book availability first
    $update_stmt = $db->prepare("UPDATE books SET available_copies = available_copies - 1 WHERE book_id = ? AND available_copies > 0");
    $update_result = $update_stmt->execute([$book_id]);
    
    if (!$update_result || $update_stmt->rowCount() === 0) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Book is no longer available']);
        exit();
    }
    
    // Create loan record
    $loan_date = date('Y-m-d');
    $due_date = date('Y-m-d', strtotime('+14 days'));
    
    $loan_stmt = $db->prepare("
        INSERT INTO book_loans (member_id, book_id, loan_date, due_date, status, approval_status, created_at) 
        VALUES (?, ?, ?, ?, 'active', 'pending', NOW())
    ");
    $loan_result = $loan_stmt->execute([$member_id, $book_id, $loan_date, $due_date]);
    
    if ($loan_result) {
        $loan_id = $db->lastInsertId();
        
        // Get book location details for notification
        $book_details_stmt = $db->prepare("
            SELECT rack_number, dewey_decimal_number, dewey_classification, 
                   shelf_position, floor_level 
            FROM books WHERE book_id = ?
        ");
        $book_details_stmt->execute([$book_id]);
        $book_details = $book_details_stmt->fetch();
        
        // FIXED: Create simple notification without emojis
        $message = "Book reservation request submitted for '{$book['title']}' by {$book['author']}. Waiting for librarian approval.";
        
        $additional_data = [];
        if ($book_details) {
            if ($book_details['rack_number']) $additional_data['rack_number'] = $book_details['rack_number'];
            if ($book_details['shelf_position']) $additional_data['shelf_position'] = $book_details['shelf_position'];
            if ($book_details['floor_level']) $additional_data['floor_level'] = $book_details['floor_level'];
            if ($book_details['dewey_decimal_number']) {
                $additional_data['dewey_decimal'] = $book_details['dewey_decimal_number'];
                $additional_data['dewey_classification'] = $book_details['dewey_classification'];
            }
        }
        
        $notif_stmt = $db->prepare("
            INSERT INTO member_notifications (member_id, book_id, loan_id, title, message, type, rack_number, additional_data, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $notif_stmt->execute([
            $member_id,
            $book_id,
            $loan_id,
            'Book Reservation Submitted',
            $message,
            'info',
            $book_details['rack_number'] ?? null,
            json_encode($additional_data)
        ]);
        
        $db->commit();
        
        echo json_encode(['success' => true, 'message' => 'Book reservation submitted successfully! Please wait for approval.']);
    } else {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Failed to submit reservation']);
    }
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Borrow book error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while processing your request']);
}
?>