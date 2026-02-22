<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../includes/auth_check.php';

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'approve' && hasPermission('approve_loans')) {
        $loan_id = (int)$_POST['loan_id'];
        
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            $db->beginTransaction();
            
            // Update loan status
            $stmt = $db->prepare("UPDATE book_loans SET approval_status = 'approved', status = 'active', approved_date = NOW(), approved_by = ? WHERE loan_id = ?");
            $stmt->execute([$_SESSION['admin_id'], $loan_id]);
            
            // Get loan details for notification
            $loan_stmt = $db->prepare("
                SELECT bl.*, b.title, b.author, b.rack_number, b.dewey_decimal_number, b.dewey_classification, b.shelf_position, b.floor_level,
                       m.first_name, m.last_name, m.email
                FROM book_loans bl 
                JOIN books b ON bl.book_id = b.book_id 
                JOIN members m ON bl.member_id = m.member_id 
                WHERE bl.loan_id = ?
            ");
            $loan_stmt->execute([$loan_id]);
            $loan = $loan_stmt->fetch();
            
            if ($loan) {
                // Create detailed notification with location info
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
            $success = "Book loan approved successfully and member has been notified!";
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error approving loan: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'reject' && hasPermission('approve_loans')) {
        $loan_id = (int)$_POST['loan_id'];
        $rejection_reason = $_POST['rejection_reason'] ?? '';
        
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            $db->beginTransaction();
            
            // Update loan status
            $stmt = $db->prepare("UPDATE book_loans SET approval_status = 'rejected', status = 'cancelled', rejection_reason = ? WHERE loan_id = ?");
            $stmt->execute([$rejection_reason, $loan_id]);
            
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
            $success = "Book loan rejected and member has been notified.";
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error rejecting loan: " . $e->getMessage();
        }
    }
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get search and filter parameters
    $search = $_GET['search'] ?? '';
    $filter = $_GET['filter'] ?? 'recent';
    
    $where_clauses = ["bl.approval_status = 'pending'"];
    $params = [];
    
    if ($search) {
        $where_clauses[] = "(b.title LIKE ? OR b.author LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ? OR m.member_code LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
    }
    
    $where_sql = implode(' AND ', $where_clauses);
    
    switch($filter) {
        case 'oldest':
            $order_by = 'bl.created_at ASC';
            break;
        case 'az_asc':
            $order_by = 'b.title ASC';
            break;
        case 'az_desc':
            $order_by = 'b.title DESC';
            break;
        default:
            $order_by = 'bl.created_at DESC';
    }
    
    $sql = "
        SELECT bl.*, b.title, b.author, b.isbn, b.cover_image, b.rack_number, b.dewey_decimal_number,
               b.dewey_classification, b.shelf_position, b.floor_level, b.description, b.genre,
               m.first_name, m.last_name, m.email, m.phone, m.member_code, m.address, m.membership_type
        FROM book_loans bl
        JOIN books b ON bl.book_id = b.book_id
        JOIN members m ON bl.member_id = m.member_id
        WHERE $where_sql
        ORDER BY $order_by
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $pending_loans = $stmt->fetchAll();
    
} catch (Exception $e) {
    $pending_loans = [];
    $error = "Error loading pending requests: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Approvals - Admin Panel</title>
    
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

        .filter-btn {
            padding: 0.7rem 1.2rem;
            border: 1px solid #e5e7eb;
            background: white;
            border-radius: 10px;
            color: var(--dark-color);
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }

        .filter-btn:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .filter-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 0.5rem;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            min-width: 250px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 200;
            display: none;
        }

        .filter-dropdown.show {
            display: block;
        }

        .filter-section {
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .filter-section:last-child {
            border-bottom: none;
        }

        .filter-section h6 {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.75rem;
        }

        .filter-option {
            display: flex;
            align-items: center;
            padding: 0.5rem;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .filter-option:hover {
            background: #f3f4f6;
        }

        .filter-option input[type="radio"] {
            margin-right: 0.5rem;
        }

        .filter-option label {
            cursor: pointer;
            margin: 0;
            color: var(--gray-color);
            font-size: 0.875rem;
        }

        /* Content Area */
        .content-area {
            padding: 2rem;
        }

        /* Pending Grid - Updated like explore/reservations */
        .pending-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            align-items: start;
        }

        .pending-grid.list-view {
            grid-template-columns: 1fr;
        }

        .pending-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #f3f4f6;
            border-left: 4px solid var(--warning-color);
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .pending-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .pending-header {
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

        .book-info {
            flex: 1;
            min-width: 0;
        }

        .book-info h3 {
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

        .book-info .author {
            color: var(--gray-color);
            font-size: 0.875rem;
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .pending-status {
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
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }

        .pending-details {
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

        .btn-approve {
            background: var(--success-color);
            color: white;
        }

        .btn-approve:hover {
            background: #059669;
            color: white;
            transform: translateY(-2px);
        }

        .btn-reject {
            background: var(--danger-color);
            color: white;
        }

        .btn-reject:hover {
            background: #dc2626;
            color: white;
            transform: translateY(-2px);
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
        }

        /* Custom Confirmation Modal */
        .confirmation-modal {
            background: rgba(0,0,0,0.5);
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .confirmation-content {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            text-align: center;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .confirmation-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .confirmation-icon.approve {
            color: var(--success-color);
        }

        .confirmation-icon.reject {
            color: var(--danger-color);
        }

        .confirmation-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .confirmation-buttons button {
            flex: 1;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-confirm {
            background: var(--success-color);
            color: white;
        }

        .btn-confirm.reject {
            background: var(--danger-color);
        }

        .btn-cancel {
            background: #f3f4f6;
            color: var(--gray-color);
        }

        .btn-cancel:hover {
            background: #e5e7eb;
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

            .pending-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .pending-card {
                padding: 1rem;
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
            <a href="javascript:void(0)" onclick="navigateTo('pending')" class="nav-link active <?php echo $current_page === 'pending' ? 'active' : ''; ?>">
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
                    <a href="javascript:void(0)" onclick="navigateTo('loan-history')" class="nav-link">
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
                        <h1>Pending Approvals</h1>
                        <p>Review and approve book borrow requests</p>
                    </div>

                    <div class="header-actions">
                        <div class="header-search">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchInput" placeholder="Search pending requests..." 
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

                        <div style="position: relative;">
                            <button class="filter-btn" onclick="toggleFilterDropdown()">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                            <div class="filter-dropdown" id="filterDropdown">
                                <div class="filter-section">
                                    <h6>Sort By</h6>
                                    <div class="filter-option">
                                        <input type="radio" name="sort" value="recent" <?php echo $filter === 'recent' ? 'checked' : ''; ?>>
                                        <label>Most Recent</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="sort" value="oldest" <?php echo $filter === 'oldest' ? 'checked' : ''; ?>>
                                        <label>Oldest First</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="sort" value="az_asc" <?php echo $filter === 'az_asc' ? 'checked' : ''; ?>>
                                        <label>A-Z Title</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="sort" value="az_desc" <?php echo $filter === 'az_desc' ? 'checked' : ''; ?>>
                                        <label>Z-A Title</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Content Area -->
            <div class="content-area">
                <?php if (isset($success)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Pending Grid -->
                <div class="pending-grid" id="pendingGrid">
                    <?php if (empty($pending_loans)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-check-circle" style="font-size: 4rem; color: var(--success-color); opacity: 0.5;"></i>
                            <h3 class="mt-3 text-muted">No pending requests</h3>
                            <p class="text-muted">All book requests have been processed</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pending_loans as $loan): ?>
                            <div class="pending-card">
                                <div class="pending-header">
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
                                    <div class="book-info">
                                        <h3><?php echo htmlspecialchars($loan['title']); ?></h3>
                                        <div class="author">by <?php echo htmlspecialchars($loan['author']); ?></div>
                                    </div>
                                    <div class="pending-status">
                                        <div class="status-badge">
                                            Pending Approval
                                        </div>
                                    </div>
                                </div>

                                <div class="pending-details">
                                    <div class="detail-row">
                                        <span class="detail-label">Request Date:</span>
                                        <span class="detail-value"><?php echo date('M j, Y', strtotime($loan['created_at'])); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Book ID:</span>
                                        <span class="detail-value"><?php echo $loan['book_id']; ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Due Date:</span>
                                        <span class="detail-value"><?php echo date('M j, Y', strtotime($loan['due_date'])); ?></span>
                                    </div>
                                    <?php if ($loan['rack_number']): ?>
                                        <div class="detail-row">
                                            <span class="detail-label">Location:</span>
                                            <span class="detail-value"><?php echo $loan['rack_number']; ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="member-info">
                                    <h6>Requested By</h6>
                                    <div class="detail-row">
                                        <span class="detail-label">Name:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($loan['first_name'] . ' ' . $loan['last_name']); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Member Code:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($loan['member_code']); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Type:</span>
                                        <span class="detail-value"><?php echo ucfirst($loan['membership_type']); ?></span>
                                    </div>
                                </div>

                                <div class="action-buttons">
                                    <button class="btn-action btn-view" onclick="showPendingDetails(<?php echo $loan['loan_id']; ?>)" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if (hasPermission('approve_loans')): ?>
                                        <button class="btn-action btn-approve" onclick="showApproveConfirmation(<?php echo $loan['loan_id']; ?>, '<?php echo htmlspecialchars($loan['title']); ?>')" title="Approve Request">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="btn-action btn-reject" onclick="showRejectConfirmation(<?php echo $loan['loan_id']; ?>, '<?php echo htmlspecialchars($loan['title']); ?>')" title="Reject Request">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Pending Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-clock me-2"></i>Pending Request Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailsContent">
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

    <!-- Custom Confirmation Modal -->
    <div class="confirmation-modal" id="confirmationModal">
        <div class="confirmation-content">
            <div class="confirmation-icon" id="confirmationIcon">
                <i class="fas fa-question-circle"></i>
            </div>
            <h4 id="confirmationTitle">Confirm Action</h4>
            <p id="confirmationMessage"></p>
            <div id="rejectionReasonDiv" style="display: none;">
                <textarea id="rejectionReason" class="form-control" rows="3" placeholder="Please provide a reason for rejection (optional)" style="margin-top: 1rem;"></textarea>
            </div>
            <div class="confirmation-buttons">
                <button type="button" class="btn-cancel" onclick="hideConfirmation()">Cancel</button>
                <button type="button" class="btn-confirm" id="confirmButton" onclick="executeAction()">Confirm</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        let currentAction = null;
        let currentLoanId = null;
        
        // Navigation without .php extension
        function navigateTo(page) {
            if (page === 'pending') return;
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

        // Filter dropdown toggle
        function toggleFilterDropdown() {
            const dropdown = document.getElementById('filterDropdown');
            dropdown.classList.toggle('show');
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.filter-btn') && !e.target.closest('.filter-dropdown')) {
                document.getElementById('filterDropdown').classList.remove('show');
            }
            
            if (window.innerWidth <= 768) {
                const sidebar = document.getElementById('adminSidebar');
                const toggle = document.querySelector('.mobile-menu-toggle');
                if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
                    sidebar.classList.remove('show');
                }
            }
        });

        // Search functionality with debounce - FIXED
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
                        window.location.href = url.toString();
                    }, 1000);
                });
            }
        });

        // Filter change handlers - FIXED
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('input[name="sort"]').forEach(input => {
                input.addEventListener('change', function() {
                    const url = new URL(window.location);
                    url.searchParams.set('filter', this.value);
                    window.location.href = url.toString();
                });
            });

            // View toggle - FIXED
            document.querySelectorAll('.view-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    
                    const view = this.dataset.view;
                    const grid = document.getElementById('pendingGrid');
                    
                    if (view === 'list') {
                        grid.classList.add('list-view');
                    } else {
                        grid.classList.remove('list-view');
                    }
                });
            });
        });

        // Show pending details modal - FIXED
        function showPendingDetails(loanId) {
            try {
                const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
                const content = document.getElementById('detailsContent');
                
                content.innerHTML = '<div class="text-center py-4"><div class="spinner-border" role="status"></div><p class="mt-2">Loading...</p></div>';
                modal.show();
                
                // Find the loan data from PHP
                const pendingLoans = <?php echo json_encode($pending_loans); ?>;
                const loan = pendingLoans.find(l => l.loan_id == loanId);
                
                setTimeout(() => {
                    if (loan) {
                        content.innerHTML = generatePendingDetailsHTML(loan);
                    } else {
                        content.innerHTML = '<div class="text-center py-4"><p class="text-danger">Pending request not found!</p></div>';
                    }
                }, 500);
            } catch (error) {
                console.error('Error showing pending details:', error);
                alert('Error loading pending details. Please try again.');
            }
        }

        // Generate pending details HTML - FIXED
        function generatePendingDetailsHTML(loan) {
            try {
                return `
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6><i class="fas fa-book me-2"></i>Book Information</h6>
                                </div>
                                <div class="card-body">
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
                                    <p><strong>Membership Type:</strong> ${loan.membership_type.charAt(0).toUpperCase() + loan.membership_type.slice(1)}</p>
                                    <p><strong>Address:</strong> ${loan.address || 'N/A'}</p>
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
                                            <p><strong>Request Date:</strong><br>${new Date(loan.created_at).toLocaleDateString()}</p>
                                        </div>
                                        <div class="col-md-3">
                                            <p><strong>Loan Date:</strong><br>${new Date(loan.loan_date).toLocaleDateString()}</p>
                                        </div>
                                        <div class="col-md-3">
                                            <p><strong>Due Date:</strong><br>${new Date(loan.due_date).toLocaleDateString()}</p>
                                        </div>
                                        <div class="col-md-3">
                                            <p><strong>Status:</strong><br><span class="badge bg-warning">Pending Approval</span></p>
                                        </div>
                                    </div>
                                    ${loan.description ? `<div class="mt-3"><p><strong>Book Description:</strong></p><p class="text-muted">${loan.description}</p></div>` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } catch (error) {
                console.error('Error generating pending details HTML:', error);
                return '<div class="text-center py-4"><p class="text-danger">Error loading pending details!</p></div>';
            }
        }

        // Show approve confirmation - FIXED
        function showApproveConfirmation(loanId, bookTitle) {
            try {
                currentAction = 'approve';
                currentLoanId = loanId;
                
                document.getElementById('confirmationIcon').innerHTML = '<i class="fas fa-check-circle"></i>';
                document.getElementById('confirmationIcon').className = 'confirmation-icon approve';
                document.getElementById('confirmationTitle').textContent = 'Approve Book Loan';
                document.getElementById('confirmationMessage').innerHTML = `Are you sure you want to approve the loan request for "<strong>${bookTitle}</strong>"?<br><br>The member will be notified and can collect the book.`;
                document.getElementById('rejectionReasonDiv').style.display = 'none';
                document.getElementById('confirmButton').textContent = 'Approve';
                document.getElementById('confirmButton').className = 'btn-confirm';
                
                document.getElementById('confirmationModal').style.display = 'flex';
            } catch (error) {
                console.error('Error showing approve confirmation:', error);
                alert('Error processing approve request. Please try again.');
            }
        }

        // Show reject confirmation - FIXED
        function showRejectConfirmation(loanId, bookTitle) {
            try {
                currentAction = 'reject';
                currentLoanId = loanId;
                
                document.getElementById('confirmationIcon').innerHTML = '<i class="fas fa-times-circle"></i>';
                document.getElementById('confirmationIcon').className = 'confirmation-icon reject';
                document.getElementById('confirmationTitle').textContent = 'Reject Book Loan';
                document.getElementById('confirmationMessage').innerHTML = `Are you sure you want to reject the loan request for "<strong>${bookTitle}</strong>"?<br><br>The member will be notified of the rejection.`;
                document.getElementById('rejectionReasonDiv').style.display = 'block';
                document.getElementById('confirmButton').textContent = 'Reject';
                document.getElementById('confirmButton').className = 'btn-confirm reject';
                
                document.getElementById('confirmationModal').style.display = 'flex';
            } catch (error) {
                console.error('Error showing reject confirmation:', error);
                alert('Error processing reject request. Please try again.');
            }
        }

        // Hide confirmation modal
        function hideConfirmation() {
            document.getElementById('confirmationModal').style.display = 'none';
            document.getElementById('rejectionReason').value = '';
            currentAction = null;
            currentLoanId = null;
        }

        // Execute the confirmed action - FIXED
        function executeAction() {
            try {
                if (!currentAction || !currentLoanId) return;
                
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.name = 'action';
                actionInput.value = currentAction;
                form.appendChild(actionInput);
                
                const loanIdInput = document.createElement('input');
                loanIdInput.name = 'loan_id';
                loanIdInput.value = currentLoanId;
                form.appendChild(loanIdInput);
                
                if (currentAction === 'reject') {
                    const reasonInput = document.createElement('input');
                    reasonInput.name = 'rejection_reason';
                    reasonInput.value = document.getElementById('rejectionReason').value;
                    form.appendChild(reasonInput);
                }
                
                document.body.appendChild(form);
                form.submit();
            } catch (error) {
                console.error('Error executing action:', error);
                alert('Error processing request. Please try again.');
            }
        }

        // Print modal content - FIXED
        function printModalContent() {
            try {
                const content = document.getElementById('detailsContent').innerHTML;
                const printWindow = window.open('', '_blank', 'width=800,height=600');
                printWindow.document.write(`
                    <html>
                    <head>
                        <title>Pending Request Details - Print</title>
                        <style>
                            body { font-family: Arial, sans-serif; margin: 20px; }
                            .header { text-align: center; border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 20px; }
                            .card { border: 1px solid #ddd; margin-bottom: 20px; }
                            .card-header { background: #f8f9fa; padding: 10px; font-weight: bold; }
                            .card-body { padding: 15px; }
                            .row { display: flex; flex-wrap: wrap; }
                            .col-lg-6, .col-12 { flex: 1; padding: 0 10px; }
                            .col-md-3 { flex: 0 0 25%; padding: 0 10px; }
                            @media print { body { margin: 0; } }
                        </style>
                    </head>
                    <body>
                        <div class="header">
                            <h1>ESSSL Library</h1>
                            <h2>Pending Request Details</h2>
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
                console.error('Error printing:', error);
                alert('Error printing. Please try again.');
            }
        }

        // Close confirmation modal when clicking outside
        document.getElementById('confirmationModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideConfirmation();
            }
        });

        // Error handling for image loading - FIXED
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('img').forEach(img => {
                img.addEventListener('error', function() {
                    this.src = '../../assets/images/default-book.jpg';
                });
            });
        });

        // Initialize page
        console.log('Admin Pending Page initialized successfully');
        console.log('Total pending loans loaded:', <?php echo count($pending_loans); ?>);
    </script>
</body>
</html>