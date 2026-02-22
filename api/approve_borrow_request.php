<?php
header('Content-Type: application/json');
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_logged_in']) && !isset($_SESSION['librarian_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (empty($_POST['loan_id']) || empty($_POST['action'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $loan_id = $_POST['loan_id'];
    $action = $_POST['action']; // 'approve' or 'reject'
    
    if ($action === 'approve') {
        $db->beginTransaction();
        
        // Approve the loan
        $stmt = $db->prepare("UPDATE book_loans SET approval_status = 'approved', status = 'active', approved_date = NOW(), approved_by = ? WHERE loan_id = ?");
        $admin_id = $_SESSION['admin_id'] ?? $_SESSION['librarian_id'] ?? 1;
        $result = $stmt->execute([$admin_id, $loan_id]);
        
        if ($result) {
            // Get loan details for notification
            $loan_stmt = $db->prepare("
                SELECT bl.*, b.title, b.author, b.rack_number, b.dewey_decimal_number, 
                       b.dewey_classification, b.shelf_position, b.floor_level,
                       m.first_name, m.last_name, m.email
                FROM book_loans bl 
                JOIN books b ON bl.book_id = b.book_id 
                JOIN members m ON bl.member_id = m.member_id 
                WHERE bl.loan_id = ?
            ");
            $loan_stmt->execute([$loan_id]);
            $loan = $loan_stmt->fetch();
            
            if ($loan) {
                // Create location info for notification
                $location_info = [];
                if ($loan['rack_number']) $location_info['rack_number'] = $loan['rack_number'];
                if ($loan['shelf_position']) $location_info['shelf_position'] = $loan['shelf_position'];
                if ($loan['floor_level']) $location_info['floor_level'] = $loan['floor_level'];
                if ($loan['dewey_decimal_number']) {
                    $location_info['dewey_decimal'] = $loan['dewey_decimal_number'];
                    $location_info['dewey_classification'] = $loan['dewey_classification'];
                }
                
                // FIXED: Simple message without emojis
                $message = "BOOK APPROVED - Ready for Collection!\n\n";
                $message .= "Book: {$loan['title']}\n";
                $message .= "Author: {$loan['author']}\n";
                $message .= "Book ID: {$loan['book_id']}\n";
                $message .= "Due Date: " . date('M j, Y', strtotime($loan['due_date'])) . "\n\n";
                
                if (!empty($location_info)) {
                    $message .= "Location Details:\n";
                    if (isset($location_info['dewey_decimal'])) {
                        $message .= "Dewey Decimal: {$location_info['dewey_decimal']}\n";
                    }
                    if (isset($location_info['dewey_classification'])) {
                        $message .= "Classification: {$location_info['dewey_classification']}\n";
                    }
                    if (isset($location_info['rack_number'])) {
                        $message .= "Rack Number: {$location_info['rack_number']}\n";
                    }
                    if (isset($location_info['shelf_position'])) {
                        $message .= "Shelf Position: {$location_info['shelf_position']}\n";
                    }
                    if (isset($location_info['floor_level'])) {
                        $message .= "Floor Level: {$location_info['floor_level']}\n";
                    }
                    $message .= "\nCollection Instructions:\n";
                    $message .= "• Visit the library during operating hours\n";
                    $message .= "• Go to Floor {$location_info['floor_level']}\n";
                    $message .= "• Find Rack {$location_info['rack_number']}\n";
                    $message .= "• Look for the book on {$location_info['shelf_position']} shelf\n";
                    $message .= "• Present your member ID at the counter";
                }
                
                $notif_stmt = $db->prepare("
                    INSERT INTO member_notifications (member_id, book_id, loan_id, title, message, type, rack_number, additional_data, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $notif_stmt->execute([
                    $loan['member_id'],
                    $loan['book_id'],
                    $loan_id,
                    'Book Approved - Ready for Collection',
                    $message,
                    'success',
                    $loan['rack_number'],
                    json_encode($location_info)
                ]);
            }
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Book loan approved successfully']);
        } else {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Failed to approve loan']);
        }
        
    } else if ($action === 'reject') {
        $rejection_reason = $_POST['rejection_reason'] ?? '';
        
        $db->beginTransaction();
        
        // Reject the loan and update book availability
        $stmt = $db->prepare("UPDATE book_loans SET approval_status = 'rejected', status = 'cancelled', rejection_reason = ? WHERE loan_id = ?");
        $result = $stmt->execute([$rejection_reason, $loan_id]);
        
        if ($result) {
            // Increase book availability
            $book_stmt = $db->prepare("UPDATE books SET available_copies = available_copies + 1 WHERE book_id = (SELECT book_id FROM book_loans WHERE loan_id = ?)");
            $book_stmt->execute([$loan_id]);
            
            // Get loan details for notification
            $loan_stmt = $db->prepare("
                SELECT bl.*, b.title, b.author, m.first_name, m.last_name 
                FROM book_loans bl 
                JOIN books b ON bl.book_id = b.book_id 
                JOIN members m ON bl.member_id = m.member_id 
                WHERE bl.loan_id = ?
            ");
            $loan_stmt->execute([$loan_id]);
            $loan = $loan_stmt->fetch();
            
            if ($loan) {
                // FIXED: Simple rejection message without emojis
                $message = "BOOK LOAN REQUEST REJECTED\n\n";
                $message .= "Book: {$loan['title']}\n";
                $message .= "Author: {$loan['author']}\n";
                $message .= "Book ID: {$loan['book_id']}\n\n";
                if ($rejection_reason) {
                    $message .= "Reason: {$rejection_reason}\n\n";
                }
                $message .= "Next Steps:\n";
                $message .= "• You can try borrowing other available books\n";
                $message .= "• Contact the library for more information\n";
                $message .= "• Check back later for availability";
                
                $notif_stmt = $db->prepare("
                    INSERT INTO member_notifications (member_id, book_id, loan_id, title, message, type, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $notif_stmt->execute([
                    $loan['member_id'],
                    $loan['book_id'],
                    $loan_id,
                    'Book Loan Request Rejected',
                    $message,
                    'warning'
                ]);
            }
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Book loan rejected successfully']);
        } else {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Failed to reject loan']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Approve borrow error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Approve borrow error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error']);
}
?>