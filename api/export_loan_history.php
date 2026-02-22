<?php
// Start output buffering to prevent any accidental output
ob_start();

session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
require_once '../admin/includes/auth_check.php';

// Check if user has permission to export data
if (!hasPermission('view_reports')) {
    ob_end_clean();
    http_response_code(403);
    exit('Access denied');
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get filter parameters (same as loan-history.php)
    $search = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? 'all';
    $member_filter = $_GET['member'] ?? 'all';
    $year_filter = $_GET['year'] ?? 'all';
    $month_filter = $_GET['month'] ?? 'all';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $sort_filter = $_GET['sort'] ?? 'recent';
    
    // Build WHERE clauses (same logic as loan-history.php)
    $where_clauses = [];
    $params = [];
    
    // Search filter
    if ($search) {
        $where_clauses[] = "(b.title LIKE ? OR b.author LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ? OR m.member_code LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
    }
    
    // Status filter
    if ($status_filter !== 'all') {
        if ($status_filter === 'overdue') {
            $where_clauses[] = "(bl.status = 'overdue' OR (bl.status = 'active' AND bl.due_date < CURDATE()))";
        } else {
            $where_clauses[] = "bl.status = ?";
            $params[] = $status_filter;
        }
    }
    
    // Member filter
    if ($member_filter !== 'all') {
        $where_clauses[] = "bl.member_id = ?";
        $params[] = $member_filter;
    }
    
    // Year filter
    if ($year_filter !== 'all') {
        $where_clauses[] = "YEAR(bl.loan_date) = ?";
        $params[] = $year_filter;
    }
    
    // Month filter
    if ($month_filter !== 'all') {
        $where_clauses[] = "MONTH(bl.loan_date) = ?";
        $params[] = $month_filter;
    }
    
    // Date range filter
    if ($date_from) {
        $where_clauses[] = "bl.loan_date >= ?";
        $params[] = $date_from;
    }
    
    if ($date_to) {
        $where_clauses[] = "bl.loan_date <= ?";
        $params[] = $date_to;
    }
    
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    
    // Sort order
    switch($sort_filter) {
        case 'oldest':
            $order_by = 'bl.loan_date ASC';
            break;
        case 'due_date':
            $order_by = 'bl.due_date ASC';
            break;
        case 'return_date':
            $order_by = 'bl.return_date DESC';
            break;
        case 'member':
            $order_by = 'm.first_name ASC, m.last_name ASC';
            break;
        case 'book':
            $order_by = 'b.title ASC';
            break;
        case 'status':
            $order_by = 'bl.status ASC';
            break;
        default:
            $order_by = 'bl.loan_date DESC';
    }
    
    // Query for export (no pagination)
    $sql = "
        SELECT 
            bl.loan_id,
            bl.loan_date,
            bl.due_date,
            bl.return_date,
            bl.status,
            bl.approval_status,
            bl.fine_amount,
            bl.notes,
            b.book_id,
            b.title,
            b.author,
            b.isbn,
            b.genre,
            b.rack_number,
            b.dewey_decimal_number,
            m.member_id,
            m.first_name,
            m.last_name,
            m.email,
            m.member_code,
            m.membership_type,
            CASE 
                WHEN bl.due_date < NOW() AND bl.status = 'active' THEN 'overdue'
                ELSE bl.status 
            END as current_status,
            CASE 
                WHEN bl.due_date < NOW() AND bl.status = 'active' THEN DATEDIFF(NOW(), bl.due_date)
                ELSE 0
            END as days_overdue
        FROM book_loans bl
        JOIN books b ON bl.book_id = b.book_id
        JOIN members m ON bl.member_id = m.member_id
        $where_sql
        ORDER BY $order_by
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $loan_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Clear output buffer before sending headers
    ob_end_clean();
    
    // Set headers for CSV download
    $filename = 'ABC_Library_Loan_History_' . date('Y-m-d_H-i-s') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8 to ensure proper character encoding in Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write CSV headers
    $headers = [
        'Loan ID',
        'Book ID',
        'Book Title',
        'Author',
        'ISBN',
        'Genre',
        'Location (Rack)',
        'Dewey Decimal',
        'Member ID',
        'Member Code',
        'Member Name',
        'Member Email',
        'Membership Type',
        'Loan Date',
        'Due Date',
        'Return Date',
        'Current Status',
        'Approval Status',
        'Days Overdue',
        'Fine Amount',
        'Notes'
    ];
    
    fputcsv($output, $headers);
    
    // Write data rows
    foreach ($loan_history as $loan) {
        $row = [
            $loan['loan_id'],
            $loan['book_id'],
            $loan['title'],
            $loan['author'],
            $loan['isbn'] ?: 'N/A',
            $loan['genre'] ?: 'N/A',
            $loan['rack_number'] ?: 'N/A',
            $loan['dewey_decimal_number'] ?: 'N/A',
            $loan['member_id'],
            $loan['member_code'],
            trim($loan['first_name'] . ' ' . $loan['last_name']),
            $loan['email'],
            ucfirst($loan['membership_type']),
            date('Y-m-d', strtotime($loan['loan_date'])),
            date('Y-m-d', strtotime($loan['due_date'])),
            $loan['return_date'] ? date('Y-m-d', strtotime($loan['return_date'])) : 'Not returned',
            ucfirst(str_replace('_', ' ', $loan['current_status'])),
            ucfirst($loan['approval_status']),
            $loan['days_overdue'] > 0 ? $loan['days_overdue'] : '0',
            $loan['fine_amount'] > 0 ? '$' . number_format($loan['fine_amount'], 2) : '$0.00',
            $loan['notes'] ? str_replace(["\r", "\n"], ' ', $loan['notes']) : 'N/A'
        ];
        
        fputcsv($output, $row);
    }
    
    // Add summary footer
    $total_records = count($loan_history);
    $total_fines = array_sum(array_column($loan_history, 'fine_amount'));
    $active_loans = count(array_filter($loan_history, function($loan) {
        return $loan['current_status'] === 'active';
    }));
    $overdue_loans = count(array_filter($loan_history, function($loan) {
        return $loan['current_status'] === 'overdue';
    }));
    
    // Add empty row
    fputcsv($output, []);
    
    // Add summary
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Records:', $total_records]);
    fputcsv($output, ['Active Loans:', $active_loans]);
    fputcsv($output, ['Overdue Loans:', $overdue_loans]);
    fputcsv($output, ['Total Fines:', '$' . number_format($total_fines, 2)]);
    fputcsv($output, ['Export Date:', date('Y-m-d H:i:s')]);
    fputcsv($output, ['Generated by:', $_SESSION['admin_name']]);
    
    fclose($output);
    exit();
    
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    
    // Log error for debugging
    error_log("CSV Export Error: " . $e->getMessage());
    
    // Return JSON error for AJAX calls or plain text for direct access
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Export failed: ' . $e->getMessage()]);
    } else {
        header('Content-Type: text/plain');
        echo 'Error exporting data: ' . $e->getMessage();
    }
    exit();
}
?>