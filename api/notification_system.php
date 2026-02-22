<?php
require_once '../config/database.php';

function sendOverdueNotifications() {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Find overdue books that haven't been notified in the last 24 hours
        $overdue_stmt = $db->prepare("
            SELECT bl.*, b.title, b.author, m.member_id, m.first_name, m.last_name, m.email,
                   DATEDIFF(CURDATE(), bl.due_date) as days_overdue
            FROM book_loans bl
            JOIN books b ON bl.book_id = b.book_id
            JOIN members m ON bl.member_id = m.member_id
            WHERE bl.status IN ('active', 'overdue') 
            AND bl.due_date < CURDATE()
            AND bl.approval_status = 'approved'
            AND NOT EXISTS (
                SELECT 1 FROM member_notifications mn 
                WHERE mn.member_id = bl.member_id 
                AND mn.loan_id = bl.loan_id 
                AND mn.type = 'danger' 
                AND mn.title LIKE '%Overdue%'
                AND mn.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            )
        ");
        $overdue_stmt->execute();
        $overdue_books = $overdue_stmt->fetchAll();
        
        foreach ($overdue_books as $loan) {
            // Update loan status to overdue
            $update_stmt = $db->prepare("UPDATE book_loans SET status = 'overdue' WHERE loan_id = ?");
            $update_stmt->execute([$loan['loan_id']]);
            
            // Calculate fine (e.g., $0.50 per day)
            $fine_amount = $loan['days_overdue'] * 0.50;
            $update_fine_stmt = $db->prepare("UPDATE book_loans SET fine_amount = ? WHERE loan_id = ?");
            $update_fine_stmt->execute([$fine_amount, $loan['loan_id']]);
            
            // Create overdue notification
            $message = "BOOK OVERDUE NOTICE\n\n";
            $message .= "Book: {$loan['title']}\n";
            $message .= "Author: {$loan['author']}\n";
            $message .= "Due Date: " . date('M j, Y', strtotime($loan['due_date'])) . "\n";
            $message .= "Days Overdue: {$loan['days_overdue']} days\n";
            $message .= "Fine Amount: $" . number_format($fine_amount, 2) . "\n\n";
            $message .= "Please return this book immediately to avoid additional fines.\n";
            $message .= "Contact the library if you need assistance.";
            
            $notif_stmt = $db->prepare("
                INSERT INTO member_notifications (member_id, book_id, loan_id, title, message, type, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $notif_stmt->execute([
                $loan['member_id'],
                $loan['book_id'],
                $loan['loan_id'],
                'Book Overdue - Action Required',
                $message,
                'danger'
            ]);
        }
        
        return count($overdue_books);
        
    } catch (Exception $e) {
        error_log("Overdue notification error: " . $e->getMessage());
        return 0;
    }
}

function sendBookStatusUpdates() {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Find recently returned books that haven't been notified
        $returned_stmt = $db->prepare("
            SELECT bl.*, b.title, b.author, m.member_id
            FROM book_loans bl
            JOIN books b ON bl.book_id = b.book_id
            JOIN members m ON bl.member_id = m.member_id
            WHERE bl.status = 'returned' 
            AND bl.return_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND NOT EXISTS (
                SELECT 1 FROM member_notifications mn 
                WHERE mn.member_id = bl.member_id 
                AND mn.loan_id = bl.loan_id 
                AND mn.title LIKE '%returned%'
                AND mn.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            )
        ");
        $returned_stmt->execute();
        $returned_books = $returned_stmt->fetchAll();
        
        foreach ($returned_books as $loan) {
            $message = "Book successfully returned: '{$loan['title']}' by {$loan['author']}. Thank you for using our library!";
            
            $notif_stmt = $db->prepare("
                INSERT INTO member_notifications (member_id, book_id, loan_id, title, message, type, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $notif_stmt->execute([
                $loan['member_id'],
                $loan['book_id'],
                $loan['loan_id'],
                'Book Returned Successfully',
                $message,
                'success'
            ]);
        }
        
        return count($returned_books);
        
    } catch (Exception $e) {
        error_log("Status update notification error: " . $e->getMessage());
        return 0;
    }
}

// If called directly, run the notifications
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    $overdue_count = sendOverdueNotifications();
    $status_count = sendBookStatusUpdates();
    
    echo json_encode([
        'success' => true,
        'overdue_notifications' => $overdue_count,
        'status_notifications' => $status_count,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>