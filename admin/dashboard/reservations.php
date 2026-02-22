<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../includes/auth_check.php';

// Handle delete request with super admin verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_reservation') {
        $loan_id = (int)$_POST['loan_id'];
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
            
            // Get book details before deletion for updating available copies
            $stmt = $db->prepare("SELECT book_id, status FROM book_loans WHERE loan_id = ?");
            $stmt->execute([$loan_id]);
            $loan_details = $stmt->fetch();
            
            if (!$loan_details) {
                throw new Exception("Reservation not found.");
            }
            
            // Delete the reservation
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
            $success = "Reservation deleted successfully!";
            
        } catch (Exception $e) {
            // Only rollback if a transaction was started
            if (isset($db)) {
                try {
                    // Check if there's an active transaction before rolling back
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                } catch (Exception $rollbackError) {
                    // Log rollback error but don't override original error
                    error_log("Rollback error: " . $rollbackError->getMessage());
                }
            }
            $error = $e->getMessage();
        }
    }
    
    // Handle status update
    if ($_POST['action'] === 'update_status' && hasPermission('manage_loans')) {
        $loan_id = (int)$_POST['loan_id'];
        $new_status = $_POST['new_status'];
        $notes = $_POST['notes'] ?? '';
        
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            $db->beginTransaction();
            
            $stmt = $db->prepare("UPDATE book_loans SET status = ?, notes = ?, updated_at = NOW() WHERE loan_id = ?");
            $stmt->execute([$new_status, $notes, $loan_id]);
            
            if ($new_status === 'returned' || $new_status === 'returned_damaged') {
                $stmt = $db->prepare("UPDATE books SET available_copies = available_copies + 1 WHERE book_id = (SELECT book_id FROM book_loans WHERE loan_id = ?)");
                $stmt->execute([$loan_id]);
            }
            
            // Send notification
            $stmt = $db->prepare("
                INSERT INTO member_notifications (member_id, title, message, type, created_at) 
                SELECT member_id, 'Book Status Updated', 
                CONCAT('Your book reservation status has been updated to: ', ?), 'info', NOW()
                FROM book_loans WHERE loan_id = ?
            ");
            $stmt->execute([$new_status, $loan_id]);
            
            $db->commit();
            $success = "Status updated successfully!";
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error updating status: " . $e->getMessage();
        }
    }
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get search and filter parameters
    $search = $_GET['search'] ?? '';
    $filter = $_GET['filter'] ?? 'status_order'; // Default to status-based ordering
    $status_filter = $_GET['status'] ?? 'all';
    
    $where_clauses = ["bl.status IN ('active', 'overdue', 'returned', 'returned_damaged')"];
    $params = [];
    
    if ($search) {
        $where_clauses[] = "(b.title LIKE ? OR b.author LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    }
    
    if ($status_filter !== 'all') {
        $where_clauses[] = "bl.status = ?";
        $params[] = $status_filter;
    }
    
    $where_sql = implode(' AND ', $where_clauses);
    
    // Updated sorting with status priority as default
    switch($filter) {
        case 'oldest':
            $order_by = 'bl.loan_date ASC';
            break;
        case 'az_asc':
            $order_by = 'b.title ASC';
            break;
        case 'az_desc':
            $order_by = 'b.title DESC';
            break;
        case 'overdue':
            $order_by = 'bl.due_date ASC';
            break;
        case 'recent':
            $order_by = 'bl.loan_date DESC';
            break;
        default: // status_order
            $order_by = "
                CASE 
                    WHEN bl.due_date < NOW() AND bl.status = 'active' THEN 1
                    WHEN bl.status = 'active' THEN 2
                    WHEN bl.status = 'overdue' THEN 3
                    WHEN bl.status = 'returned' THEN 4
                    WHEN bl.status = 'returned_damaged' THEN 5
                    ELSE 6
                END, bl.loan_date DESC";
    }
    
    $sql = "
        SELECT bl.*, b.title, b.author, b.isbn, b.cover_image, b.rack_number, b.dewey_decimal_number,
               b.dewey_classification, b.shelf_position, b.floor_level,
               m.first_name, m.last_name, m.email, m.phone, m.member_code, m.address,
               CASE 
                   WHEN bl.due_date < NOW() AND bl.status = 'active' THEN 'overdue'
                   ELSE bl.status 
               END as current_status,
               DATEDIFF(NOW(), bl.due_date) as days_overdue
        FROM book_loans bl
        JOIN books b ON bl.book_id = b.book_id
        JOIN members m ON bl.member_id = m.member_id
        WHERE $where_sql
        ORDER BY $order_by
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll();
    
} catch (Exception $e) {
    $reservations = [];
    $error = "Error loading reservations: " . $e->getMessage();
}

// Check if current user is super admin
$is_current_super_admin = ($_SESSION['admin_role'] ?? '') === 'super_admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservations - Admin Panel</title>
    
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

        /* Reservations Grid */
        .reservations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            align-items: start;
        }

        .reservations-grid.list-view {
            grid-template-columns: 1fr;
        }

        .reservation-card {
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

        .reservation-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .reservation-header {
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

        .reservation-status {
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

        .status-badge.returned_damaged {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }

        .reservation-details {
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

        .btn-edit {
            background: var(--warning-color);
            color: white;
        }

        .btn-edit:hover {
            background: #d97706;
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

        .btn-delete {
            background: var(--danger-color);
            color: white;
        }

        .btn-delete:hover {
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

        .alert-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
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

            .reservations-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .reservation-card {
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
                    <a href="javascript:void(0)" onclick="navigateTo('dashboard')" class="nav-link">
                        <i class="fas fa-tachometer-alt nav-icon"></i>
                        <span>Dashboard</span>
                    </a>
                </div>

                <div class="nav-item">
            <a href="javascript:void(0)" onclick="navigateTo('reservations')" class="nav-link active<?php echo $current_page === 'reservations' ? 'active' : ''; ?>">
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
                        <h1>Reservations</h1>
                        <p>Manage active book reservations</p>
                    </div>

                    <div class="header-actions">
                        <div class="header-search">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchInput" placeholder="Search reservations..." 
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
                                        <input type="radio" name="sort" value="status_order" <?php echo $filter === 'status_order' ? 'checked' : ''; ?>>
                                        <label>Status Priority (Default)</label>
                                    </div>
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
                                    <div class="filter-option">
                                        <input type="radio" name="sort" value="overdue" <?php echo $filter === 'overdue' ? 'checked' : ''; ?>>
                                        <label>Overdue First</label>
                                    </div>
                                </div>
                                <div class="filter-section">
                                    <h6>Status</h6>
                                    <div class="filter-option">
                                        <input type="radio" name="status" value="all" <?php echo $status_filter === 'all' ? 'checked' : ''; ?>>
                                        <label>All Status</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="status" value="active" <?php echo $status_filter === 'active' ? 'checked' : ''; ?>>
                                        <label>Active</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="status" value="overdue" <?php echo $status_filter === 'overdue' ? 'checked' : ''; ?>>
                                        <label>Overdue</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="status" value="returned" <?php echo $status_filter === 'returned' ? 'checked' : ''; ?>>
                                        <label>Returned</label>
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

                <!-- Reservations Grid -->
                <div class="reservations-grid" id="reservationsGrid">
                    <?php if (empty($reservations)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-book-open" style="font-size: 4rem; color: var(--gray-color); opacity: 0.5;"></i>
                            <h3 class="mt-3 text-muted">No reservations found</h3>
                            <p class="text-muted">Try adjusting your search or filter criteria</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($reservations as $reservation): ?>
                            <div class="reservation-card">
                                <div class="reservation-header">
                                <div class="book-cover">
    <?php 
    // Check if cover_image is a full URL or just a filename
    $cover_src = '';
    if ($reservation['cover_image']) {
        if (filter_var($reservation['cover_image'], FILTER_VALIDATE_URL)) {
            // It's a full URL, use it directly
            $cover_src = htmlspecialchars($reservation['cover_image']);
        } else {
            // It's a local filename, prepend the local path
            $cover_src = '../../assets/images/books/' . htmlspecialchars($reservation['cover_image']);
        }
    } else {
        // No cover image, use default
        $cover_src = '../../assets/images/default-book.jpg';
    }
    ?>
    <img src="<?php echo $cover_src; ?>" 
         alt="<?php echo htmlspecialchars($reservation['title']); ?>" 
         onerror="this.src='../../assets/images/default-book.jpg'">
</div>
                                    <div class="book-info">
                                        <h3><?php echo htmlspecialchars($reservation['title']); ?></h3>
                                        <div class="author">by <?php echo htmlspecialchars($reservation['author']); ?></div>
                                    </div>
                                    <div class="reservation-status">
                                        <div class="status-badge <?php echo $reservation['current_status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $reservation['current_status'])); ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="reservation-details">
                                    <div class="detail-row">
                                        <span class="detail-label">Book ID:</span>
                                        <span class="detail-value"><?php echo $reservation['book_id']; ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Loan Date:</span>
                                        <span class="detail-value"><?php echo date('M j, Y', strtotime($reservation['loan_date'])); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Due Date:</span>
                                        <span class="detail-value"><?php echo date('M j, Y', strtotime($reservation['due_date'])); ?></span>
                                    </div>
                                    <?php if ($reservation['days_overdue'] > 0): ?>
                                        <div class="detail-row">
                                            <span class="detail-label">Days Overdue:</span>
                                            <span class="detail-value" style="color: var(--danger-color);"><?php echo $reservation['days_overdue']; ?> days</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($reservation['rack_number']): ?>
                                        <div class="detail-row">
                                            <span class="detail-label">Location:</span>
                                            <span class="detail-value"><?php echo $reservation['rack_number']; ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="member-info">
                                    <h6>Member Information</h6>
                                    <div class="detail-row">
                                        <span class="detail-label">Name:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($reservation['first_name'] . ' ' . $reservation['last_name']); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Member Code:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($reservation['member_code']); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Email:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($reservation['email']); ?></span>
                                    </div>
                                </div>

                                <div class="action-buttons">
                                    <button class="btn-action btn-view" onclick="showReservationDetails(<?php echo $reservation['loan_id']; ?>)" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if (hasPermission('manage_loans')): ?>
                                        <button class="btn-action btn-edit" onclick="showStatusModal(<?php echo $reservation['loan_id']; ?>)" title="Update Status">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn-action btn-print" onclick="printReservation(<?php echo $reservation['loan_id']; ?>)" title="Print Details">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <button class="btn-action btn-delete" onclick="showDeleteModal(<?php echo $reservation['loan_id']; ?>)" title="Delete Reservation">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Reservation Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-book me-2"></i>Reservation Details
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

    <!-- Status Update Modal -->
    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Update Status
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="statusForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="loan_id" id="statusLoanId">
                        
                        <div class="mb-3">
                            <label class="form-label">New Status:</label>
                            <select name="new_status" class="form-select" required>
                                <option value="active">Active</option>
                                <option value="returned">Returned</option>
                                <option value="returned_damaged">Returned (Damaged)</option>
                                <option value="overdue">Overdue</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notes:</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>Delete Reservation
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="deleteForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete_reservation">
                        <input type="hidden" name="loan_id" id="deleteLoanId">
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> This action cannot be undone. The reservation will be permanently deleted.
                        </div>
                        
                        <p>Are you sure you want to delete this reservation?</p>
                        
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
                            <i class="fas fa-trash me-1"></i>Delete Reservation
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Custom Confirmation Modal -->
    <div class="modal fade" id="confirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-question-circle me-2"></i>Confirm Action
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="confirmMessage"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmAction">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Store super admin access temporarily
        let tempSuperAdminAccess = false;
        let tempAccessExpiry = 0;
        const TEMP_ACCESS_DURATION = 5 * 60 * 1000; // 5 minutes

        // Navigation without .php extension
        function navigateTo(page) {
            if (page === 'reservations') return;
            if (page === 'dashboard') {
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
                        window.location.href = url.toString();
                    }, 1000);
                });
            }
        });

        // Filter change handlers
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('input[name="sort"]').forEach(input => {
                input.addEventListener('change', function() {
                    const url = new URL(window.location);
                    url.searchParams.set('filter', this.value);
                    window.location.href = url.toString();
                });
            });

            document.querySelectorAll('input[name="status"]').forEach(input => {
                input.addEventListener('change', function() {
                    const url = new URL(window.location);
                    if (this.value === 'all') {
                        url.searchParams.delete('status');
                    } else {
                        url.searchParams.set('status', this.value);
                    }
                    window.location.href = url.toString();
                });
            });

            // View toggle
            document.querySelectorAll('.view-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    
                    const view = this.dataset.view;
                    const grid = document.getElementById('reservationsGrid');
                    
                    if (view === 'list') {
                        grid.classList.add('list-view');
                    } else {
                        grid.classList.remove('list-view');
                    }
                });
            });
        });

        // Show reservation details modal
        function showReservationDetails(loanId) {
            try {
                const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
                const content = document.getElementById('detailsContent');
                
                content.innerHTML = '<div class="text-center py-4"><div class="spinner-border" role="status"></div><p class="mt-2">Loading...</p></div>';
                modal.show();
                
                // Find the reservation data from PHP
                const reservations = <?php echo json_encode($reservations); ?>;
                const reservation = reservations.find(r => r.loan_id == loanId);
                
                setTimeout(() => {
                    if (reservation) {
                        content.innerHTML = generateReservationDetailsHTML(reservation);
                    } else {
                        content.innerHTML = '<div class="text-center py-4"><p class="text-danger">Reservation not found!</p></div>';
                    }
                }, 500);
            } catch (error) {
                console.error('Error showing reservation details:', error);
                alert('Error loading reservation details. Please try again.');
            }
        }

        // Generate reservation details HTML
        function generateReservationDetailsHTML(reservation) {
            try {
                const overdueText = reservation.days_overdue > 0 ? `${reservation.days_overdue} days overdue` : 'On time';
                
                return `
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6><i class="fas fa-book me-2"></i>Book Information</h6>
                                </div>
                                <div class="card-body">
                                    <p><strong>Title:</strong> ${reservation.title || 'N/A'}</p>
                                    <p><strong>Author:</strong> ${reservation.author || 'N/A'}</p>
                                    <p><strong>ISBN:</strong> ${reservation.isbn || 'N/A'}</p>
                                    <p><strong>Book ID:</strong> ${reservation.book_id}</p>
                                    <p><strong>Dewey Decimal:</strong> ${reservation.dewey_decimal_number || 'N/A'}</p>
                                    <p><strong>Classification:</strong> ${reservation.dewey_classification || 'N/A'}</p>
                                    <p><strong>Rack Number:</strong> ${reservation.rack_number || 'N/A'}</p>
                                    <p><strong>Shelf Position:</strong> ${reservation.shelf_position || 'N/A'}</p>
                                    <p><strong>Floor Level:</strong> ${reservation.floor_level || 'N/A'}</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6><i class="fas fa-user me-2"></i>Member Information</h6>
                                </div>
                                <div class="card-body">
                                    <p><strong>Name:</strong> ${reservation.first_name} ${reservation.last_name}</p>
                                    <p><strong>Member Code:</strong> ${reservation.member_code}</p>
                                    <p><strong>Email:</strong> ${reservation.email}</p>
                                    <p><strong>Phone:</strong> ${reservation.phone || 'N/A'}</p>
                                    <p><strong>Address:</strong> ${reservation.address || 'N/A'}</p>
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
                                            <p><strong>Loan Date:</strong><br>${new Date(reservation.loan_date).toLocaleDateString()}</p>
                                        </div>
                                        <div class="col-md-3">
                                            <p><strong>Due Date:</strong><br>${new Date(reservation.due_date).toLocaleDateString()}</p>
                                        </div>
                                        <div class="col-md-3">
                                            <p><strong>Status:</strong><br><span class="badge bg-${reservation.current_status === 'active' ? 'success' : reservation.current_status === 'overdue' ? 'danger' : 'secondary'}">${reservation.current_status.charAt(0).toUpperCase() + reservation.current_status.slice(1).replace('_', ' ')}</span></p>
                                        </div>
                                        <div class="col-md-3">
                                            <p><strong>Time Status:</strong><br>${overdueText}</p>
                                        </div>
                                    </div>
                                    ${reservation.notes ? `<div class="mt-3"><p><strong>Notes:</strong></p><p class="text-muted">${reservation.notes}</p></div>` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } catch (error) {
                console.error('Error generating reservation details HTML:', error);
                return '<div class="text-center py-4"><p class="text-danger">Error loading reservation details!</p></div>';
            }
        }

        // Show status update modal
        function showStatusModal(loanId) {
            try {
                document.getElementById('statusLoanId').value = loanId;
                const modal = new bootstrap.Modal(document.getElementById('statusModal'));
                modal.show();
            } catch (error) {
                console.error('Error showing status modal:', error);
                alert('Error loading status form. Please try again.');
            }
        }

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

        // Print reservation
        function printReservation(loanId) {
            try {
                const reservations = <?php echo json_encode($reservations); ?>;
                const reservation = reservations.find(r => r.loan_id == loanId);
                
                if (reservation) {
                    const printWindow = window.open('', '_blank', 'width=800,height=600');
                    printWindow.document.write(`
                        <html>
                        <head>
                            <title>Reservation #${loanId} - Print</title>
                            <style>
                                body { font-family: Arial, sans-serif; margin: 20px; }
                                .header { text-align: center; border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 20px; }
                                .section { margin-bottom: 20px; }
                                .detail-row { display: flex; justify-content: space-between; margin-bottom: 5px; }
                                .detail-label { font-weight: bold; }
                            </style>
                        </head>
                        <body>
                            <div class="header">
                                <h1>ESSSL Library</h1>
                                <h2>Reservation Details #${loanId}</h2>
                                <p>Printed on: ${new Date().toLocaleString()}</p>
                            </div>
                            <div class="section">
                                <h3>Book Information</h3>
                                <div class="detail-row"><span class="detail-label">Title:</span> <span>${reservation.title}</span></div>
                                <div class="detail-row"><span class="detail-label">Author:</span> <span>${reservation.author}</span></div>
                                <div class="detail-row"><span class="detail-label">Book ID:</span> <span>${reservation.book_id}</span></div>
                                <div class="detail-row"><span class="detail-label">Location:</span> <span>${reservation.rack_number || 'N/A'}</span></div>
                            </div>
                            <div class="section">
                                <h3>Member Information</h3>
                                <div class="detail-row"><span class="detail-label">Name:</span> <span>${reservation.first_name} ${reservation.last_name}</span></div>
                                <div class="detail-row"><span class="detail-label">Member Code:</span> <span>${reservation.member_code}</span></div>
                                <div class="detail-row"><span class="detail-label">Email:</span> <span>${reservation.email}</span></div>
                            </div>
                            <div class="section">
                                <h3>Loan Details</h3>
                                <div class="detail-row"><span class="detail-label">Loan Date:</span> <span>${new Date(reservation.loan_date).toLocaleDateString()}</span></div>
                                <div class="detail-row"><span class="detail-label">Due Date:</span> <span>${new Date(reservation.due_date).toLocaleDateString()}</span></div>
                                <div class="detail-row"><span class="detail-label">Status:</span> <span>${reservation.current_status.charAt(0).toUpperCase() + reservation.current_status.slice(1).replace('_', ' ')}</span></div>
                                ${reservation.days_overdue > 0 ? `<div class="detail-row"><span class="detail-label">Days Overdue:</span> <span>${reservation.days_overdue} days</span></div>` : ''}
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
                    alert('Reservation not found!');
                }
            } catch (error) {
                console.error('Error printing reservation:', error);
                alert('Error printing reservation. Please try again.');
            }
        }

        // Print modal content
        function printModalContent() {
            try {
                const content = document.getElementById('detailsContent').innerHTML;
                const printWindow = window.open('', '_blank', 'width=800,height=600');
                printWindow.document.write(`
                    <html>
                    <head>
                        <title>Reservation Details - Print</title>
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
                            <h2>Reservation Details</h2>
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

        // Status form submission with confirmation
        document.addEventListener('DOMContentLoaded', function() {
            const statusForm = document.getElementById('statusForm');
            if (statusForm) {
                statusForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    const status = formData.get('new_status');
                    
                    document.getElementById('confirmMessage').textContent = `Are you sure you want to update the status to "${status.replace('_', ' ')}"?`;
                    
                    const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
                    confirmModal.show();
                    
                    document.getElementById('confirmAction').onclick = () => {
                        confirmModal.hide();
                        statusForm.submit();
                    };
                });
            }

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
                        
                        // Grant temporary access for successful verification
                        tempSuperAdminAccess = true;
                        tempAccessExpiry = currentTime + TEMP_ACCESS_DURATION;
                    }
                    
                    // Submit the form
                    this.submit();
                });
            }
        });

        // Error handling for image loading
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('img').forEach(img => {
                img.addEventListener('error', function() {
                    this.src = '../../assets/images/default-book.jpg';
                });
            });
        });

        // Initialize page
        console.log('Admin Reservations Page initialized successfully');
        console.log('Total reservations loaded:', <?php echo count($reservations); ?>);
    </script>
</body>
</html>