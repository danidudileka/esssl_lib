<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../includes/auth_check.php';

// Handle delete request with super admin verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_loan') {
        $loan_id = (int)($_POST['loan_id'] ?? 0);
        $sa_username = $_POST['sa_username'] ?? '';
        $sa_password = $_POST['sa_password'] ?? '';

        try {
            $database = new Database();
            $db = $database->getConnection();

            // Check if current admin is already super admin
            $current_admin_stmt = $db->prepare("SELECT role FROM admin WHERE admin_id = ?");
            $current_admin_stmt->execute([$_SESSION['admin_id']]);
            $current_admin = $current_admin_stmt->fetch();
            $is_super_admin = ($current_admin && $current_admin['role'] === 'super_admin');

            if (!$is_super_admin) {
                // Verify super admin credentials
                if (empty($sa_username) || empty($sa_password)) {
                    throw new Exception("Super admin credentials required for deletion.");
                }

                $stmt = $db->prepare("SELECT admin_id, password_hash FROM admin WHERE username = ? AND role = 'super_admin' AND status = 'active'");
                $stmt->execute([$sa_username]);
                $super_admin = $stmt->fetch();

                if (!$super_admin || !password_verify($sa_password, $super_admin['password_hash'])) {
                    throw new Exception("Invalid super admin credentials.");
                }
            }

            // Only begin transaction after credential verification
            $db->beginTransaction();

            // Get loan details before deletion for updating available copies
            $stmt = $db->prepare("SELECT book_id, status FROM book_loans WHERE loan_id = ?");
            $stmt->execute([$loan_id]);
            $loan_details = $stmt->fetch();

            if (!$loan_details) {
                throw new Exception("Loan not found.");
            }

            // Delete the loan record
            $stmt = $db->prepare("DELETE FROM book_loans WHERE loan_id = ?");
            $stmt->execute([$loan_id]);

            // Update book availability if the book was not returned
            if (in_array($loan_details['status'], ['active', 'overdue'])) {
                $stmt = $db->prepare("UPDATE books SET available_copies = available_copies + 1 WHERE book_id = ?");
                $stmt->execute([$loan_details['book_id']]);
            }

            // Delete related notifications
            $stmt = $db->prepare("DELETE FROM member_notifications WHERE loan_id = ?");
            $stmt->execute([$loan_id]);

            $db->commit();
            $success = "Loan record deleted successfully!";
        } catch (Exception $e) {
            if (isset($db)) {
                try {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                } catch (Exception $rollbackError) {
                    error_log("Rollback error: " . $rollbackError->getMessage());
                }
            }
            $error = $e->getMessage();
        }
    }
}



try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get filter parameters
    $search = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? 'all';
    $member_filter = $_GET['member'] ?? 'all';
    $year_filter = $_GET['year'] ?? 'all';
    $month_filter = $_GET['month'] ?? 'all';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $sort_filter = $_GET['sort'] ?? 'recent';
    $per_page = (int)($_GET['per_page'] ?? 50);
    $page = (int)($_GET['page'] ?? 1);
    
    // Build WHERE clauses
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
    
    // Get total count for pagination
    $count_sql = "
        SELECT COUNT(*) as total
        FROM book_loans bl
        JOIN books b ON bl.book_id = b.book_id
        JOIN members m ON bl.member_id = m.member_id
        $where_sql
    ";
    
    $count_stmt = $db->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetch()['total'];
    $total_pages = ceil($total_records / $per_page);
    
    // Calculate offset
    $offset = ($page - 1) * $per_page;
    
    // Main query with pagination
    $sql = "
        SELECT bl.loan_id, bl.member_id, bl.book_id, bl.loan_date, bl.due_date, bl.return_date,
               bl.status, bl.approval_status, bl.approved_date, bl.approved_by, bl.rejection_reason,
               bl.fine_amount, bl.renewal_count, bl.notes, bl.created_at, bl.updated_at,
               b.title, b.author, b.isbn, b.cover_image, b.rack_number, b.dewey_decimal_number,
               b.dewey_classification, b.shelf_position, b.floor_level, b.genre,
               m.first_name, m.last_name, m.email, m.phone, m.member_code, m.membership_type,
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
        LIMIT $per_page OFFSET $offset
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $loan_history = $stmt->fetchAll();
    
    // Get filter options
    $years_stmt = $db->query("SELECT DISTINCT YEAR(loan_date) as year FROM book_loans ORDER BY year DESC");
    $years = $years_stmt->fetchAll();
    
    $members_stmt = $db->query("SELECT member_id, first_name, last_name, member_code FROM members WHERE status = 'active' ORDER BY first_name, last_name");
    $members = $members_stmt->fetchAll();
    
    // Get statistics
    $stats_stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_loans,
            COUNT(CASE WHEN bl.status = 'active' THEN 1 END) as active_loans,
            COUNT(CASE WHEN bl.status = 'returned' THEN 1 END) as returned_loans,
            COUNT(CASE WHEN bl.status = 'overdue' OR (bl.status = 'active' AND bl.due_date < CURDATE()) THEN 1 END) as overdue_loans,
            AVG(CASE WHEN bl.status = 'returned' AND bl.return_date IS NOT NULL 
                THEN DATEDIFF(bl.return_date, bl.loan_date) END) as avg_loan_duration
        FROM book_loans bl
        JOIN books b ON bl.book_id = b.book_id
        JOIN members m ON bl.member_id = m.member_id
        $where_sql
    ");
    $stats_stmt->execute($params);
    $statistics = $stats_stmt->fetch();
    
} catch (Exception $e) {
    $loan_history = [];
    $years = [];
    $members = [];
    $statistics = [
        'total_loans' => 0,
        'active_loans' => 0,
        'returned_loans' => 0,
        'overdue_loans' => 0,
        'avg_loan_duration' => 0
    ];
    $total_records = 0;
    $total_pages = 0;
    $error = "Error loading loan history: " . $e->getMessage();
}

// Check if current user is super admin
$is_current_super_admin = ($_SESSION['admin_role'] ?? '') === 'super_admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan History - Admin Panel</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="./admin-style/sidebar.css">
    
    <style>


        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--light-bg);
            color: var(--dark-color);
        }

        .admin-layout {
            display: flex;
            min-height: 100vh;
        }

    

        /* Main Content */
        .admin-main {
            flex: 1;
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        /* Header */
        .admin-header {
            background: white;
            padding: 1.5rem 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header-title h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
            color: var(--dark-color);
        }

        .header-title p {
            margin: 0;
            color: var(--gray-color);
            font-size: 0.95rem;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .header-search {
            position: relative;
        }

        .header-search input {
            padding: 0.7rem 1rem 0.7rem 2.8rem;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            width: 280px;
            font-size: 0.9rem;
            transition: all 0.3s;
        }

        .header-search input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            outline: none;
        }

        .header-search i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-color);
        }

        .view-toggle {
            display: flex;
            background: #f3f4f6;
            border-radius: 8px;
            padding: 0.25rem;
        }

        .view-btn {
            padding: 0.5rem 0.75rem;
            border: none;
            background: transparent;
            color: var(--gray-color);
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .view-btn.active,
        .view-btn:hover {
            background: white;
            color: var(--dark-color);
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .export-btn {
            background: var(--success-color);
            color: white;
            border: none;
            padding: 0.7rem 1.2rem;
            border-radius: 10px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .export-btn:hover {
            background: #059669;
            transform: translateY(-2px);
            color: white;
        }

        /* Content Area */
        .content-area {
            padding: 2rem;
        }

        /* Statistics Cards */
        .stats-section {
            margin-bottom: 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border-left: 4px solid var(--primary-color);
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .stat-card.active {
            border-left-color: var(--success-color);
        }

        .stat-card.returned {
            border-left-color: var(--gray-color);
        }

        .stat-card.overdue {
            border-left-color: var(--danger-color);
        }

        .stat-card.average {
            border-left-color: var(--accent-color);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--gray-color);
            font-weight: 500;
        }

        .stat-icon {
            float: right;
            font-size: 1.5rem;
            opacity: 0.3;
            margin-top: -0.5rem;
        }

        /* Advanced Filters */
        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }

        .filters-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1rem;
            cursor: pointer;
        }

        .filters-header h5 {
            margin: 0;
            font-weight: 600;
        }

        .filters-content {
            display: none;
        }

        .filters-content.show {
            display: block;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
        }

        .filter-input {
            padding: 0.5rem;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 0.875rem;
        }

        .filter-input:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
        }

        .btn-filter {
            padding: 0.5rem 1rem;
            border: 1px solid var(--primary-color);
            background: var(--primary-color);
            color: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.2s;
        }

        .btn-filter:hover {
            background: var(--primary-dark);
        }

        .btn-clear {
            padding: 0.5rem 1rem;
            border: 1px solid #e5e7eb;
            background: white;
            color: var(--gray-color);
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.2s;
        }

        .btn-clear:hover {
            background: #f3f4f6;
        }

        /* Loan History Grid */
        .loans-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 1.5rem;
            align-items: start;
        }

        .loans-grid.list-view {
            grid-template-columns: 1fr;
        }

        .loan-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #f3f4f6;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .loan-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .loan-header {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .book-cover {
            flex-shrink: 0;
            width: 60px;
            height: 80px;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
            background: #f8fafc;
        }

        .book-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .loan-info {
            flex: 1;
            min-width: 0;
        }

        .loan-info h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-wrap: break-word;
        }

        .loan-info .author {
            color: var(--gray-color);
            font-size: 0.875rem;
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .loan-status {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        .status-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-badge.active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .status-badge.overdue {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }

        .status-badge.returned {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-color);
        }

        .status-badge.pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }

        .loan-id {
            font-size: 0.75rem;
            color: var(--gray-color);
        }

        .loan-details {
            margin: 1rem 0;
            flex: 1;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }

        .detail-label {
            color: var(--gray-color);
            font-weight: 500;
        }

        .detail-value {
            color: var(--dark-color);
            font-weight: 500;
            text-align: right;
        }

        .detail-value.overdue {
            color: var(--danger-color);
        }

        .member-info {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 10px;
            margin: 1rem 0;
        }

        .member-info h6 {
            color: var(--dark-color);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: auto;
            flex-wrap: wrap;
        }

        .btn-action {
            flex: 1;
            padding: 0.6rem;
            border: none;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            text-align: center;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
        }

        .btn-view {
            background: var(--primary-color);
            color: white;
        }

        .btn-view:hover {
            background: var(--primary-dark);
            color: white;
            transform: translateY(-2px);
        }

        .btn-print {
            background: #f3f4f6;
            color: var(--gray-color);
        }

        .btn-print:hover {
            background: #e5e7eb;
            color: var(--dark-color);
            transform: translateY(-2px);
        }

        /* Pagination */
        .pagination-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-top: 2rem;
        }

        .pagination-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .records-info {
            color: var(--gray-color);
            font-size: 0.875rem;
        }

        .per-page-selector {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .per-page-selector select {
            padding: 0.25rem 0.5rem;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            font-size: 0.875rem;
        }

        .pagination {
            justify-content: center;
        }

        .page-link {
            color: var(--primary-color);
            border-color: #e5e7eb;
            padding: 0.5rem 0.75rem;
        }

        .page-link:hover {
            color: var(--primary-dark);
            background: #f3f4f6;
            border-color: #d1d5db;
        }

        .page-item.active .page-link {
            background: var(--primary-color);
            border-color: var(--primary-color);
        }

        .page-item.disabled .page-link {
            color: #9ca3af;
            background: #f9fafb;
        }

        /* Modal Styles */
        .modal-dialog {
            max-width: 900px;
        }

        .modal-content {
            border: none;
            border-radius: 15px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .modal-header {
            border-bottom: 1px solid #f3f4f6;
            padding: 1.5rem;
            border-radius: 15px 15px 0 0;
        }

        .modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            max-height: calc(90vh - 140px);
        }

        .alert {
            border-radius: 10px;
            border: none;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }

        /* Mobile Responsive */
        .mobile-menu-toggle {
            display: none;
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 0.7rem;
            border-radius: 10px;
            cursor: pointer;
            font-size: 1.1rem;
        }

        @media (max-width: 768px) {
            .admin-sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }

            .admin-sidebar.show {
                transform: translateX(0);
            }

            .admin-main {
                margin-left: 0;
            }

            .mobile-menu-toggle {
                display: block;
            }

            .header-search {
                width: 100%;
            }

            .header-search input {
                width: 100%;
            }

            .header-actions {
                width: 100%;
                justify-content: space-between;
            }

            .loans-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .loan-card {
                padding: 1rem;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .pagination-info {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

    <div class="admin-layout">
        <!-- Sidebar -->
        <aside class="admin-sidebar" id="adminSidebar">
            <div class="sidebar-header">
                <a href="javascript:void(0)" class="sidebar-logo">
                    <img src="../../assets/images/logo.png" alt="Logo">
                    <div>
                        <h3>ESSSL Library</h3>
                        <div class="admin-badge">ADMIN PANEL</div>
                    </div>
                </a>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('')" class="nav-link">
                        <i class="fas fa-tachometer-alt nav-icon"></i>
                        <span>Dashboard</span>
                    </a>
                </div>

                <div class="nav-item">
            <a href="javascript:void(0)" onclick="navigateTo('reservations')" class="nav-link <?php echo $current_page === 'reservations' ? 'active' : ''; ?>">
                <i class="fas fa-book-open nav-icon"></i>
                <span>Reservations</span>
                <?php if ($sidebar_counts['reservations'] > 0): ?>
                    <span class="nav-badge"><?php echo $sidebar_counts['reservations']; ?></span>
                <?php endif; ?>
            </a>
        </div>

        <div class="nav-item">
            <a href="javascript:void(0)" onclick="navigateTo('pending')" class="nav-link <?php echo $current_page === 'pending' ? 'active' : ''; ?>">
                <i class="fas fa-clock nav-icon"></i>
                <span>Pending</span>
                <?php if ($sidebar_counts['pending'] > 0): ?>
                    <span class="nav-badge"><?php echo $sidebar_counts['pending']; ?></span>
                <?php endif; ?>
            </a>
        </div>

                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('explore')" class="nav-link">
                        <i class="fas fa-search nav-icon"></i>
                        <span>Explore</span>
                    </a>
                </div>

                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('add-items')" class="nav-link">
                        <i class="fas fa-plus-circle nav-icon"></i>
                        <span>Add Items</span>
                    </a>
                </div>

                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('loan-history')" class="nav-link active">
                        <i class="fas fa-history nav-icon"></i>
                        <span>Loan History</span>
                    </a>
                </div>

                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('members')" class="nav-link">
                        <i class="fas fa-users nav-icon"></i>
                        <span>Members</span>
                    </a>
                </div>

                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('messages')" class="nav-link">
                        <i class="fas fa-envelope nav-icon"></i>
                        <span>Messages</span>
                    </a>
                </div>

                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('membership')" class="nav-link">
                        <i class="fas fa-id-card nav-icon"></i>
                        <span>Membership</span>
                    </a>
                </div>

                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('settings')" class="nav-link">
                        <i class="fas fa-cog nav-icon"></i>
                        <span>Settings</span>
                    </a>
                </div>
            </nav>

            <div class="sidebar-footer">
                <div class="admin-info">
                    <div class="admin-avatar">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="admin-details">
                        <h6><?php echo htmlspecialchars($_SESSION['admin_name']); ?></h6>
                        <p><?php echo ucfirst($_SESSION['admin_role']); ?></p>
                    </div>
                    <a href="../logout.php" class="ms-auto text-white" style="text-decoration: none;">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="admin-main">
            <!-- Header -->
            <header class="admin-header">
                <div class="header-content">
                    <button class="mobile-menu-toggle" onclick="toggleSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>

                    <div class="header-title">
                        <h1>Loan History</h1>
                        <p>Complete record of all book loans and transactions</p>
                    </div>

                    <div class="header-actions">
                        <div class="header-search">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchInput" placeholder="Search loans..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>

                        <div class="view-toggle">
                            <button class="view-btn active" data-view="grid">
                                <i class="fas fa-th"></i>
                            </button>
                            <button class="view-btn" data-view="list">
                                <i class="fas fa-list"></i>
                            </button>
                        </div>

                        <button class="export-btn" onclick="exportData()">
                            <i class="fas fa-download"></i>Export Data
                        </button>
                    </div>
                </div>
            </header>

            <!-- Content Area -->
            <div class="content-area">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Statistics Section -->
                <div class="stats-section">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo number_format($statistics['total_loans']); ?></div>
                            <div class="stat-label">Total Loans</div>
                            <i class="fas fa-book-open stat-icon"></i>
                        </div>
                        <div class="stat-card active">
                            <div class="stat-value"><?php echo number_format($statistics['active_loans']); ?></div>
                            <div class="stat-label">Active Loans</div>
                            <i class="fas fa-bookmark stat-icon"></i>
                        </div>
                        <div class="stat-card returned">
                            <div class="stat-value"><?php echo number_format($statistics['returned_loans']); ?></div>
                            <div class="stat-label">Returned Books</div>
                            <i class="fas fa-check-circle stat-icon"></i>
                        </div>
                        <div class="stat-card overdue">
                            <div class="stat-value"><?php echo number_format($statistics['overdue_loans']); ?></div>
                            <div class="stat-label">Overdue Books</div>
                            <i class="fas fa-exclamation-triangle stat-icon"></i>
                        </div>
                        <div class="stat-card average">
                            <div class="stat-value"><?php echo number_format($statistics['avg_loan_duration'] ?? 0, 1); ?></div>
                            <div class="stat-label">Avg. Loan Days</div>
                            <i class="fas fa-calendar-alt stat-icon"></i>
                        </div>
                    </div>
                </div>

                <!-- Advanced Filters -->
                <div class="filters-section">
                    <div class="filters-header" onclick="toggleFilters()">
                        <h5><i class="fas fa-filter me-2"></i>Advanced Filters</h5>
                        <i class="fas fa-chevron-down" id="filtersToggle"></i>
                    </div>
                    <div class="filters-content" id="filtersContent">
                        <form method="GET" id="filtersForm">
                            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                            
                            <div class="filters-grid">
                                <div class="filter-group">
                                    <label class="filter-label">Status</label>
                                    <select name="status" class="filter-input">
                                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="returned" <?php echo $status_filter === 'returned' ? 'selected' : ''; ?>>Returned</option>
                                        <option value="overdue" <?php echo $status_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    </select>
                                </div>

                                <div class="filter-group">
                                    <label class="filter-label">Member</label>
                                    <select name="member" class="filter-input">
                                        <option value="all">All Members</option>
                                        <?php foreach ($members as $member): ?>
                                            <option value="<?php echo $member['member_id']; ?>" 
                                                    <?php echo $member_filter == $member['member_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name'] . ' (' . $member['member_code'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="filter-group">
                                    <label class="filter-label">Year</label>
                                    <select name="year" class="filter-input">
                                        <option value="all">All Years</option>
                                        <?php foreach ($years as $year): ?>
                                            <option value="<?php echo $year['year']; ?>" 
                                                    <?php echo $year_filter == $year['year'] ? 'selected' : ''; ?>>
                                                <?php echo $year['year']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="filter-group">
                                    <label class="filter-label">Month</label>
                                    <select name="month" class="filter-input">
                                        <option value="all">All Months</option>
                                        <option value="1" <?php echo $month_filter == '1' ? 'selected' : ''; ?>>January</option>
                                        <option value="2" <?php echo $month_filter == '2' ? 'selected' : ''; ?>>February</option>
                                        <option value="3" <?php echo $month_filter == '3' ? 'selected' : ''; ?>>March</option>
                                        <option value="4" <?php echo $month_filter == '4' ? 'selected' : ''; ?>>April</option>
                                        <option value="5" <?php echo $month_filter == '5' ? 'selected' : ''; ?>>May</option>
                                        <option value="6" <?php echo $month_filter == '6' ? 'selected' : ''; ?>>June</option>
                                        <option value="7" <?php echo $month_filter == '7' ? 'selected' : ''; ?>>July</option>
                                        <option value="8" <?php echo $month_filter == '8' ? 'selected' : ''; ?>>August</option>
                                        <option value="9" <?php echo $month_filter == '9' ? 'selected' : ''; ?>>September</option>
                                        <option value="10" <?php echo $month_filter == '10' ? 'selected' : ''; ?>>October</option>
                                        <option value="11" <?php echo $month_filter == '11' ? 'selected' : ''; ?>>November</option>
                                        <option value="12" <?php echo $month_filter == '12' ? 'selected' : ''; ?>>December</option>
                                    </select>
                                </div>

                                <div class="filter-group">
                                    <label class="filter-label">Date From</label>
                                    <input type="date" name="date_from" class="filter-input" 
                                           value="<?php echo htmlspecialchars($date_from); ?>">
                                </div>

                                <div class="filter-group">
                                    <label class="filter-label">Date To</label>
                                    <input type="date" name="date_to" class="filter-input" 
                                           value="<?php echo htmlspecialchars($date_to); ?>">
                                </div>

                                <div class="filter-group">
                                    <label class="filter-label">Sort By</label>
                                    <select name="sort" class="filter-input">
                                        <option value="recent" <?php echo $sort_filter === 'recent' ? 'selected' : ''; ?>>Most Recent</option>
                                        <option value="oldest" <?php echo $sort_filter === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                        <option value="due_date" <?php echo $sort_filter === 'due_date' ? 'selected' : ''; ?>>Due Date</option>
                                        <option value="return_date" <?php echo $sort_filter === 'return_date' ? 'selected' : ''; ?>>Return Date</option>
                                        <option value="member" <?php echo $sort_filter === 'member' ? 'selected' : ''; ?>>Member Name</option>
                                        <option value="book" <?php echo $sort_filter === 'book' ? 'selected' : ''; ?>>Book Title</option>
                                        <option value="status" <?php echo $sort_filter === 'status' ? 'selected' : ''; ?>>Status</option>
                                    </select>
                                </div>

                                <div class="filter-group">
                                    <label class="filter-label">Records per page</label>
                                    <select name="per_page" class="filter-input">
                                        <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25</option>
                                        <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                                        <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100</option>
                                        <option value="250" <?php echo $per_page == 250 ? 'selected' : ''; ?>>250</option>
                                    </select>
                                </div>
                            </div>

                            <div class="filter-actions">
                                <button type="submit" class="btn-filter">
                                    <i class="fas fa-search me-1"></i>Apply Filters
                                </button>
                                <button type="button" class="btn-clear" onclick="clearFilters()">
                                    <i class="fas fa-eraser me-1"></i>Clear All
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Loan History Grid -->
                <div class="loans-grid" id="loansGrid">
                    <?php if (empty($loan_history)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-history" style="font-size: 4rem; color: var(--gray-color); opacity: 0.5;"></i>
                            <h3 class="mt-3 text-muted">No loan records found</h3>
                            <p class="text-muted">Try adjusting your search or filter criteria</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($loan_history as $loan): ?>
                            <div class="loan-card">
                                <div class="loan-header">
                                <div class="book-cover">
    <?php 
    // Check if cover_image is a full URL or just a filename
    $cover_src = '';
    if ($loan['cover_image']) {
        if (filter_var($loan['cover_image'], FILTER_VALIDATE_URL)) {
            // It's a full URL, use it directly
            $cover_src = htmlspecialchars($loan['cover_image']);
        } else {
            // It's a local filename, prepend the local path
            $cover_src = '../../assets/images/books/' . htmlspecialchars($loan['cover_image']);
        }
    } else {
        // No cover image, use default
        $cover_src = '../../assets/images/default-book.jpg';
    }
    ?>
    <img src="<?php echo $cover_src; ?>" 
         alt="<?php echo htmlspecialchars($loan['title']); ?>" 
         onerror="this.src='../../assets/images/default-book.jpg'">
</div>
                                    <div class="loan-info">
                                        <h3><?php echo htmlspecialchars($loan['title']); ?></h3>
                                        <div class="author">by <?php echo htmlspecialchars($loan['author']); ?></div>
                                    </div>
                                    <div class="loan-status">
                                        <div class="status-badge <?php echo $loan['current_status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $loan['current_status'])); ?>
                                        </div>
                                        <div class="loan-id">ID: <?php echo $loan['loan_id']; ?></div>
                                    </div>
                                </div>

                                <div class="loan-details">
                                    <div class="detail-row">
                                        <span class="detail-label">Loan Date:</span>
                                        <span class="detail-value"><?php echo date('M j, Y', strtotime($loan['loan_date'])); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Due Date:</span>
                                        <span class="detail-value"><?php echo date('M j, Y', strtotime($loan['due_date'])); ?></span>
                                    </div>
                                    <?php if ($loan['return_date']): ?>
                                        <div class="detail-row">
                                            <span class="detail-label">Return Date:</span>
                                            <span class="detail-value"><?php echo date('M j, Y', strtotime($loan['return_date'])); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($loan['days_overdue'] > 0): ?>
                                        <div class="detail-row">
                                            <span class="detail-label">Days Overdue:</span>
                                            <span class="detail-value overdue"><?php echo $loan['days_overdue']; ?> days</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($loan['fine_amount'] > 0): ?>
                                        <div class="detail-row">
                                            <span class="detail-label">Fine Amount:</span>
                                            <span class="detail-value overdue">$<?php echo number_format($loan['fine_amount'], 2); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($loan['rack_number']): ?>
                                        <div class="detail-row">
                                            <span class="detail-label">Location:</span>
                                            <span class="detail-value"><?php echo htmlspecialchars($loan['rack_number']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="member-info">
                                    <h6>Member Information</h6>
                                    <div class="detail-row">
                                        <span class="detail-label">Name:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Member Code:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($loan['member_code']); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Email:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($loan['email']); ?></span>
                                    </div>
                                </div>

                                <div class="action-buttons">
                                    <button class="btn-action btn-view" onclick="showLoanDetails(<?php echo $loan['loan_id']; ?>)" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn-action btn-print" onclick="printLoan(<?php echo $loan['loan_id']; ?>)" title="Print Details">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <button class="btn-action btn-delete" onclick="showDeleteModal(<?php echo $loan['loan_id']; ?>)" title="Delete Loan">
                                        <i class="fas fa-trash"></i>
                                    </button>

                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination-section">
                        <div class="pagination-info">
                            <div class="records-info">
                                Showing <?php echo ($page - 1) * $per_page + 1; ?> to 
                                <?php echo min($page * $per_page, $total_records); ?> of 
                                <?php echo number_format($total_records); ?> records
                            </div>
                            <div class="per-page-selector">
                                <label>Records per page:</label>
                                <select onchange="changePerPage(this.value)">
                                    <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25</option>
                                    <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                                    <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100</option>
                                    <option value="250" <?php echo $per_page == 250 ? 'selected' : ''; ?>>250</option>
                                </select>
                            </div>
                        </div>

                        <nav>
                            <ul class="pagination">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">
                                            <i class="fas fa-angle-double-left"></i>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                            <i class="fas fa-angle-left"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>

                                <?php
                                $start = max(1, $page - 2);
                                $end = min($total_pages, $page + 2);
                                
                                for ($i = $start; $i <= $end; $i++):
                                ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                            <i class="fas fa-angle-right"></i>
                                        </a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">
                                            <i class="fas fa-angle-double-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Loan Details Modal -->
    <div class="modal fade" id="loanDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-history me-2"></i>Loan Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="loanDetailsContent">
                    <!-- Content loaded via JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="printModalContent()">
                        <i class="fas fa-print me-1"></i>Print Details
                    </button>
                </div>
            </div>
        </div>
    </div>

    
    <!-- Delete Loan Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>Delete Loan
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="deleteForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete_loan">
                        <input type="hidden" name="loan_id" id="deleteLoanId">

                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> This action cannot be undone. The loan record will be permanently deleted.
                        </div>

                        <p>Are you sure you want to delete this loan record?</p>

                        <?php if (!$is_current_super_admin): ?>
                        <div class="mt-3" id="saVerificationFields">
                            <h6 class="text-danger">Super Admin Verification Required</h6>
                            <div class="mb-3">
                                <label class="form-label">Super Admin Username:</label>
                                <input type="text" name="sa_username" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Super Admin Password:</label>
                                <input type="password" name="sa_password" class="form-control" required>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash me-1"></i>Delete Loan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Store super admin access temporarily
        let tempSuperAdminAccess = false;
        let tempAccessExpiry = 0;
        const TEMP_ACCESS_DURATION = 5 * 60 * 1000; // 5 minutes

        // Show delete modal
        function showDeleteModal(loanId) {
            try {
                document.getElementById('deleteLoanId').value = loanId;

                // Check if user has temporary super admin access
                const currentTime = Date.now();
                const isCurrentSuperAdmin = <?php echo $is_current_super_admin ? 'true' : 'false'; ?>;

                if (!isCurrentSuperAdmin && (!tempSuperAdminAccess || currentTime > tempAccessExpiry)) {
                    // Reset temp access if expired
                    tempSuperAdminAccess = false;
                    tempAccessExpiry = 0;

                    // Show verification fields
                    const saFields = document.getElementById('saVerificationFields');
                    if (saFields) {
                        saFields.style.display = 'block';
                        // Clear previous values
                        const usernameField = document.querySelector('input[name="sa_username"]');
                        const passwordField = document.querySelector('input[name="sa_password"]');
                        if (usernameField) usernameField.value = '';
                        if (passwordField) passwordField.value = '';
                    }
                } else if (!isCurrentSuperAdmin && tempSuperAdminAccess && currentTime <= tempAccessExpiry) {
                    // Hide verification fields for temp access
                    const saFields = document.getElementById('saVerificationFields');
                    if (saFields) saFields.style.display = 'none';
                }

                const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
                modal.show();
            } catch (error) {
                console.error('Error showing delete modal:', error);
                alert('Error loading delete form. Please try again.');
            }
        }


        // Navigation function
        function navigateTo(page) {
            if (page === 'loan-history') return;
            if (page === '') {
                window.location.href = './';
                return;
            }
            window.location.href = './' + page + '.php';
        }

        // Mobile sidebar toggle
        function toggleSidebar() {
            document.getElementById('adminSidebar').classList.toggle('show');
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                const sidebar = document.getElementById('adminSidebar');
                const toggle = document.querySelector('.mobile-menu-toggle');
                if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
                    sidebar.classList.remove('show');
                }
            }
        });

        // Search functionality with debounce
        let searchTimeout;
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const value = this.value;
                    clearTimeout(searchTimeout);
                    
                    searchTimeout = setTimeout(() => {
                        const url = new URL(window.location);
                        if (value.trim()) {
                            url.searchParams.set('search', value.trim());
                        } else {
                            url.searchParams.delete('search');
                        }
                        url.searchParams.delete('page'); // Reset to page 1
                        window.location.href = url.toString();
                    }, 1000);
                });
            }
        });

        // View toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.view-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    
                    const view = this.dataset.view;
                    const grid = document.getElementById('loansGrid');
                    
                    if (view === 'list') {
                        grid.classList.add('list-view');
                    } else {
                        grid.classList.remove('list-view');
                    }
                });
            });
        });

        // Toggle filters
        function toggleFilters() {
            const content = document.getElementById('filtersContent');
            const toggle = document.getElementById('filtersToggle');
            
            if (content.classList.contains('show')) {
                content.classList.remove('show');
                toggle.style.transform = 'rotate(0deg)';
            } else {
                content.classList.add('show');
                toggle.style.transform = 'rotate(180deg)';
            }
        }

        // Clear filters
        function clearFilters() {
            window.location.href = window.location.pathname;
        }

        // Change per page
        function changePerPage(perPage) {
            const url = new URL(window.location);
            url.searchParams.set('per_page', perPage);
            url.searchParams.delete('page'); // Reset to page 1
            window.location.href = url.toString();
        }

        // Show loan details modal
        function showLoanDetails(loanId) {
            try {
                const modal = new bootstrap.Modal(document.getElementById('loanDetailsModal'));
                const content = document.getElementById('loanDetailsContent');
                
                content.innerHTML = '<div class="text-center py-4"><div class="spinner-border" role="status"></div><p class="mt-2">Loading...</p></div>';
                modal.show();
                
                // Find the loan data from PHP
                const loans = <?php echo json_encode($loan_history); ?>;
                const loan = loans.find(l => l.loan_id == loanId);
                
                setTimeout(() => {
                    if (loan) {
                        content.innerHTML = generateLoanDetailsHTML(loan);
                    } else {
                        content.innerHTML = '<div class="text-center py-4"><p class="text-danger">Loan details not found!</p></div>';
                    }
                }, 500);
            } catch (error) {
                console.error('Error showing loan details:', error);
                alert('Error loading loan details. Please try again.');
            }
        }

        // Generate loan details HTML
        function generateLoanDetailsHTML(loan) {
            try {
                const loanDuration = loan.return_date ? 
                    Math.floor((new Date(loan.return_date) - new Date(loan.loan_date)) / (1000 * 60 * 60 * 24)) : 
                    Math.floor((new Date() - new Date(loan.loan_date)) / (1000 * 60 * 60 * 24));
                
                return `
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6><i class="fas fa-book me-2"></i>Book Information</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="book-cover-detail text-center mb-3">
                                                <img src="${loan.cover_image ? '../../assets/images/books/' + loan.cover_image : '../../assets/images/default-book.jpg'}" 
                                                     alt="${loan.title}" class="img-fluid rounded" style="max-height: 200px;" 
                                                     onerror="this.src='../../assets/images/default-book.jpg'">
                                            </div>
                                        </div>
                                        <div class="col-md-8">
                                            <p><strong>Title:</strong> ${loan.title || 'N/A'}</p>
                                            <p><strong>Author:</strong> ${loan.author || 'N/A'}</p>
                                            <p><strong>ISBN:</strong> ${loan.isbn || 'N/A'}</p>
                                            <p><strong>Genre:</strong> ${loan.genre || 'N/A'}</p>
                                            <p><strong>Book ID:</strong> ${loan.book_id}</p>
                                            ${loan.rack_number ? `<p><strong>Location:</strong> ${loan.rack_number}</p>` : ''}
                                            ${loan.dewey_decimal_number ? `<p><strong>Dewey Decimal:</strong> ${loan.dewey_decimal_number}</p>` : ''}
                                            ${loan.floor_level ? `<p><strong>Floor Level:</strong> ${loan.floor_level}</p>` : ''}
                                            ${loan.shelf_position ? `<p><strong>Shelf Position:</strong> ${loan.shelf_position}</p>` : ''}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6><i class="fas fa-user me-2"></i>Member Information</h6>
                                </div>
                                <div class="card-body">
                                    <p><strong>Name:</strong> ${loan.first_name} ${loan.last_name}</p>
                                    <p><strong>Member Code:</strong> ${loan.member_code}</p>
                                    <p><strong>Email:</strong> ${loan.email}</p>
                                    <p><strong>Phone:</strong> ${loan.phone || 'N/A'}</p>
                                    <p><strong>Membership Type:</strong> ${loan.membership_type ? loan.membership_type.charAt(0).toUpperCase() + loan.membership_type.slice(1) : 'N/A'}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h6><i class="fas fa-calendar me-2"></i>Loan Details</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p><strong>Loan ID:</strong><br>${loan.loan_id}</p>
                                        </div>
                                        <div class="col-md-3">
                                            <p><strong>Loan Date:</strong><br>${new Date(loan.loan_date).toLocaleDateString()}</p>
                                        </div>
                                        <div class="col-md-3">
                                            <p><strong>Due Date:</strong><br>${new Date(loan.due_date).toLocaleDateString()}</p>
                                        </div>
                                        <div class="col-md-3">
                                            <p><strong>Status:</strong><br><span class="badge bg-${getStatusColor(loan.current_status)}">${loan.current_status.charAt(0).toUpperCase() + loan.current_status.slice(1).replace('_', ' ')}</span></p>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p><strong>Loan Duration:</strong><br>${loanDuration} days</p>
                                        </div>
                                        ${loan.return_date ? `
                                            <div class="col-md-3">
                                                <p><strong>Return Date:</strong><br>${new Date(loan.return_date).toLocaleDateString()}</p>
                                            </div>
                                        ` : ''}
                                        ${loan.days_overdue > 0 ? `
                                            <div class="col-md-3">
                                                <p><strong>Days Overdue:</strong><br><span class="text-danger">${loan.days_overdue} days</span></p>
                                            </div>
                                        ` : ''}
                                        ${loan.fine_amount > 0 ? `
                                            <div class="col-md-3">
                                                <p><strong>Fine Amount:</strong><br><span class="text-danger">${parseFloat(loan.fine_amount).toFixed(2)}</span></p>
                                            </div>
                                        ` : ''}
                                    </div>
                                    ${loan.notes ? `
                                        <div class="mt-3">
                                            <p><strong>Notes:</strong></p>
                                            <p class="text-muted">${loan.notes}</p>
                                        </div>
                                    ` : ''}
                                    ${loan.approval_status ? `
                                        <div class="mt-3">
                                            <p><strong>Approval Status:</strong> <span class="badge bg-${getApprovalStatusColor(loan.approval_status)}">${loan.approval_status.charAt(0).toUpperCase() + loan.approval_status.slice(1)}</span></p>
                                            ${loan.approved_date ? `<p><strong>Approved Date:</strong> ${new Date(loan.approved_date).toLocaleDateString()}</p>` : ''}
                                            ${loan.rejection_reason ? `<p><strong>Rejection Reason:</strong> ${loan.rejection_reason}</p>` : ''}
                                        </div>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } catch (error) {
                console.error('Error generating loan details HTML:', error);
                return '<div class="text-center py-4"><p class="text-danger">Error loading loan details!</p></div>';
            }
        }

        // Helper function to get status color
        function getStatusColor(status) {
            switch(status) {
                case 'active': return 'success';
                case 'returned': return 'secondary';
                case 'overdue': return 'danger';
                case 'pending': return 'warning';
                default: return 'secondary';
            }
        }

        // Helper function to get approval status color
        function getApprovalStatusColor(status) {
            switch(status) {
                case 'approved': return 'success';
                case 'pending': return 'warning';
                case 'rejected': return 'danger';
                default: return 'secondary';
            }
        }

        // Print individual loan
        function printLoan(loanId) {
            try {
                const loans = <?php echo json_encode($loan_history); ?>;
                const loan = loans.find(l => l.loan_id == loanId);
                
                if (loan) {
                    const printWindow = window.open('', '_blank', 'width=800,height=600');
                    printWindow.document.write(`
                        <html>
                        <head>
                            <title>Loan Record #${loanId} - Print</title>
                            <style>
                                body { font-family: Arial, sans-serif; margin: 20px; }
                                .header { text-align: center; border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 20px; }
                                .section { margin-bottom: 20px; }
                                .section h3 { color: #2563eb; border-bottom: 1px solid #e5e7eb; padding-bottom: 5px; }
                                .detail-row { display: flex; justify-content: space-between; margin-bottom: 5px; }
                                .detail-label { font-weight: bold; }
                                .status { padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
                                .status.active { background: #d1fae5; color: #065f46; }
                                .status.returned { background: #f3f4f6; color: #374151; }
                                .status.overdue { background: #fee2e2; color: #dc2626; }
                                .status.pending { background: #fef3c7; color: #92400e; }
                            </style>
                        </head>
                        <body>
                            <div class="header">
                                <h1>ESSSL Library</h1>
                                <h2>Loan Record #${loanId}</h2>
                                <p>Printed on: ${new Date().toLocaleString()}</p>
                            </div>
                            <div class="section">
                                <h3>Book Information</h3>
                                <div class="detail-row"><span class="detail-label">Title:</span> <span>${loan.title}</span></div>
                                <div class="detail-row"><span class="detail-label">Author:</span> <span>${loan.author}</span></div>
                                <div class="detail-row"><span class="detail-label">Book ID:</span> <span>${loan.book_id}</span></div>
                                <div class="detail-row"><span class="detail-label">ISBN:</span> <span>${loan.isbn || 'N/A'}</span></div>
                                <div class="detail-row"><span class="detail-label">Location:</span> <span>${loan.rack_number || 'N/A'}</span></div>
                            </div>
                            <div class="section">
                                <h3>Member Information</h3>
                                <div class="detail-row"><span class="detail-label">Name:</span> <span>${loan.first_name} ${loan.last_name}</span></div>
                                <div class="detail-row"><span class="detail-label">Member Code:</span> <span>${loan.member_code}</span></div>
                                <div class="detail-row"><span class="detail-label">Email:</span> <span>${loan.email}</span></div>
                                <div class="detail-row"><span class="detail-label">Phone:</span> <span>${loan.phone || 'N/A'}</span></div>
                            </div>
                            <div class="section">
                                <h3>Loan Details</h3>
                                <div class="detail-row"><span class="detail-label">Loan Date:</span> <span>${new Date(loan.loan_date).toLocaleDateString()}</span></div>
                                <div class="detail-row"><span class="detail-label">Due Date:</span> <span>${new Date(loan.due_date).toLocaleDateString()}</span></div>
                                ${loan.return_date ? `<div class="detail-row"><span class="detail-label">Return Date:</span> <span>${new Date(loan.return_date).toLocaleDateString()}</span></div>` : ''}
                                <div class="detail-row"><span class="detail-label">Status:</span> <span class="status ${loan.current_status}">${loan.current_status.charAt(0).toUpperCase() + loan.current_status.slice(1).replace('_', ' ')}</span></div>
                                ${loan.days_overdue > 0 ? `<div class="detail-row"><span class="detail-label">Days Overdue:</span> <span style="color: #dc2626;">${loan.days_overdue} days</span></div>` : ''}
                                ${loan.fine_amount > 0 ? `<div class="detail-row"><span class="detail-label">Fine Amount:</span> <span style="color: #dc2626;">${parseFloat(loan.fine_amount).toFixed(2)}</span></div>` : ''}
                            </div>
                        </body>
                        </html>
                    `);
                    printWindow.document.close();
                    printWindow.onload = function() {
                        printWindow.print();
                        printWindow.close();
                    };
                } else {
                    alert('Loan record not found!');
                }
            } catch (error) {
                console.error('Error printing loan:', error);
                alert('Error printing loan record. Please try again.');
            }
        }

        // Print modal content
        function printModalContent() {
            try {
                const content = document.getElementById('loanDetailsContent').innerHTML;
                const printWindow = window.open('', '_blank', 'width=800,height=600');
                printWindow.document.write(`
                    <html>
                    <head>
                        <title>Loan Details - Print</title>
                        <style>
                            body { font-family: Arial, sans-serif; margin: 20px; }
                            .header { text-align: center; border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 20px; }
                            .card { border: 1px solid #ddd; margin-bottom: 20px; }
                            .card-header { background: #f8f9fa; padding: 10px; font-weight: bold; }
                            .card-body { padding: 15px; }
                            .row { display: flex; flex-wrap: wrap; }
                            .col-lg-6, .col-12 { flex: 1; padding: 0 10px; }
                            .col-md-3, .col-md-4, .col-md-8 { flex: 1; padding: 0 10px; }
                            @media print { body { margin: 0; } }
                        </style>
                    </head>
                    <body>
                        <div class="header">
                            <h1>ESSSL Library</h1>
                            <h2>Loan Details</h2>
                            <p>Printed on: ${new Date().toLocaleString()}</p>
                        </div>
                        ${content}
                    </body>
                    </html>
                `);
                printWindow.document.close();
                printWindow.onload = function() {
                    printWindow.print();
                    printWindow.close();
                };
            } catch (error) {
                console.error('Error printing modal content:', error);
                alert('Error printing. Please try again.');
            }
        }

        // Export data function
        function exportData() {
            try {
                // Get current filter parameters
                const params = new URLSearchParams(window.location.search);
                params.set('export', 'csv');
                
                // Create download link
                const exportUrl = '../../api/export_loan_history.php?' + params.toString();
                
                // Create temporary link and trigger download
                const link = document.createElement('a');
                link.href = exportUrl;
                link.download = 'loan_history_' + new Date().toISOString().split('T')[0] + '.csv';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                // Show success message
                showAlert('Export started! Your download should begin shortly.', 'success');
                
            } catch (error) {
                console.error('Error exporting data:', error);
                showAlert('Error exporting data. Please try again.', 'error');
            }
        }

        // Simple alert function
        function showAlert(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type === 'error' ? 'danger' : type} alert-dismissible fade show`;
            alertDiv.style.position = 'fixed';
            alertDiv.style.top = '20px';
            alertDiv.style.right = '20px';
            alertDiv.style.zIndex = '9999';
            alertDiv.style.maxWidth = '400px';
            
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
            `;
            
            document.body.appendChild(alertDiv);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        // Initialize page
        console.log('Admin Loan History Page initialized successfully');
        console.log('Total loan records loaded:', <?php echo count($loan_history); ?>);
        
        // Auto-expand filters if any are active
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const hasActiveFilters = urlParams.has('status') && urlParams.get('status') !== 'all' ||
                                   urlParams.has('member') && urlParams.get('member') !== 'all' ||
                                   urlParams.has('year') && urlParams.get('year') !== 'all' ||
                                   urlParams.has('month') && urlParams.get('month') !== 'all' ||
                                   urlParams.has('date_from') || urlParams.has('date_to');
            
            if (hasActiveFilters) {
                toggleFilters();
            }
        });
    
        // Delete form submission
        const deleteForm = document.getElementById('deleteForm');
        if (deleteForm) {
            deleteForm.addEventListener('submit', function(e) {
                e.preventDefault();

                const isCurrentSuperAdmin = <?php echo $is_current_super_admin ? 'true' : 'false'; ?>;
                const currentTime = Date.now();

                // If not current super admin and no temp access, validate credentials
                if (!isCurrentSuperAdmin && (!tempSuperAdminAccess || currentTime > tempAccessExpiry)) {
                    const saUsername = document.querySelector('input[name="sa_username"]')?.value;
                    const saPassword = document.querySelector('input[name="sa_password"]')?.value;

                    if (!saUsername || !saPassword) {
                        alert('Please enter super admin credentials to proceed with deletion.');
                        return;
                    }

                    // Grant temporary access for successful verification (server will verify again)
                    tempSuperAdminAccess = true;
                    tempAccessExpiry = currentTime + TEMP_ACCESS_DURATION;
                }

                // Submit the form
                this.submit();
            });
        }
</script>

<?php if (isset($success)): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        showAlert('<?php echo addslashes($success); ?>', 'success');
        // Optionally refresh page to reflect deletion
        setTimeout(() => { window.location.href = window.location.pathname + window.location.search; }, 800);
    });
</script>
<?php endif; ?>
<?php if (isset($error)): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        showAlert('<?php echo addslashes($error); ?>', 'error');
    });
</script>
<?php endif; ?>
</body>
</html>