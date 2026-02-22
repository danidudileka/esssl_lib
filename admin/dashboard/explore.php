<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../includes/auth_check.php';

// Handle book actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $database = new Database();
    $db = $database->getConnection();
    
    try {
        if ($_POST['action'] === 'update_book' && hasPermission('manage_books')) {
            $book_id = (int)$_POST['book_id'];
            $title = trim($_POST['title']);
            $author = trim($_POST['author']);
            $publisher = trim($_POST['publisher']);
            $publication_year = (int)$_POST['publication_year'];
            $genre = trim($_POST['genre']);
            $rack_number = trim($_POST['rack_number']);
            $shelf_position = $_POST['shelf_position'];
            $floor_level = (int)$_POST['floor_level'];
            $total_copies = (int)$_POST['total_copies'];
            $available_copies = (int)$_POST['available_copies'];
            $description = trim($_POST['description']);
            $language = trim($_POST['language']);
            
            $stmt = $db->prepare("
                UPDATE books SET 
                title = ?, author = ?, publisher = ?, publication_year = ?, genre = ?, 
                rack_number = ?, shelf_position = ?, floor_level = ?, total_copies = ?, 
                available_copies = ?, description = ?, language = ?, updated_date = NOW()
                WHERE book_id = ?
            ");
            
            $stmt->execute([
                $title, $author, $publisher, $publication_year, $genre,
                $rack_number, $shelf_position, $floor_level, $total_copies,
                $available_copies, $description, $language, $book_id
            ]);
            
            $success = "Book updated successfully!";
        }
        
        if ($_POST['action'] === 'toggle_status' && hasPermission('manage_books')) {
            $book_id = (int)$_POST['book_id'];
            $new_status = $_POST['new_status'];
            
            $stmt = $db->prepare("UPDATE books SET status = ?, updated_date = NOW() WHERE book_id = ?");
            $stmt->execute([$new_status, $book_id]);
            
            $success = "Book status updated successfully!";
        }
        
        if ($_POST['action'] === 'delete_book') {
            $book_id = (int)$_POST['book_id'];
            $sa_username = $_POST['sa_username'] ?? '';
            $sa_password = $_POST['sa_password'] ?? '';
            
            // Check if current admin is already super admin
            $current_admin_stmt = $db->prepare("SELECT role FROM admin WHERE admin_id = ?");
            $current_admin_stmt->execute([$_SESSION['admin_id']]);
            $current_admin = $current_admin_stmt->fetch();
            
            $is_super_admin = ($current_admin && $current_admin['role'] === 'super_admin');
            
            if (!$is_super_admin) {
                // Verify super admin credentials
                if (empty($sa_username) || empty($sa_password)) {
                    throw new Exception("Super admin credentials required for book deletion.");
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
            
            try {
                // Check if book has active loans
                $check_stmt = $db->prepare("SELECT COUNT(*) FROM book_loans WHERE book_id = ? AND status IN ('active', 'overdue')");
                $check_stmt->execute([$book_id]);
                $active_loans = $check_stmt->fetchColumn();
                
                if ($active_loans > 0) {
                    throw new Exception("Cannot delete book with active loans. Please return all copies first.");
                }
                
                // Delete related notifications first
                $stmt = $db->prepare("DELETE FROM member_notifications WHERE book_id = ?");
                $stmt->execute([$book_id]);
                
                // Delete related book loans (historical records)
                $stmt = $db->prepare("DELETE FROM book_loans WHERE book_id = ?");
                $stmt->execute([$book_id]);
                
                // Delete related reservations
                $stmt = $db->prepare("DELETE FROM book_reservations WHERE book_id = ?");
                $stmt->execute([$book_id]);
                
                // Delete related favorites
                $stmt = $db->prepare("DELETE FROM member_favorites WHERE book_id = ?");
                $stmt->execute([$book_id]);
                
                // Finally delete the book
                $stmt = $db->prepare("DELETE FROM books WHERE book_id = ?");
                $stmt->execute([$book_id]);
                
                $db->commit();
                $success = "Book deleted successfully!";
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        }
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $error = "Error: " . $e->getMessage();
    }
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get search and filter parameters
    $search = $_GET['search'] ?? '';
    $genre_filter = $_GET['genre'] ?? 'all';
    $status_filter = $_GET['status'] ?? 'active';
    $sort_filter = $_GET['sort'] ?? 'recent';
    $letter_filter = $_GET['letter'] ?? 'all';
    
    $where_clauses = [];
    $params = [];
    
    // Status filter
    if ($status_filter !== 'all') {
        $where_clauses[] = "b.status = ?";
        $params[] = $status_filter;
    }
    
    // Search filter
    if ($search) {
        $where_clauses[] = "(b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ? OR b.genre LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    }
    
    // Genre filter
    if ($genre_filter !== 'all') {
        $where_clauses[] = "b.genre = ?";
        $params[] = $genre_filter;
    }
    
    // Letter filter
    if ($letter_filter !== 'all') {
        $where_clauses[] = "b.title LIKE ?";
        $params[] = $letter_filter . '%';
    }
    
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    
    // Sort order
    switch($sort_filter) {
        case 'oldest':
            $order_by = 'b.added_date ASC';
            break;
        case 'az_asc':
            $order_by = 'b.title ASC';
            break;
        case 'az_desc':
            $order_by = 'b.title DESC';
            break;
        case 'popular':
            $order_by = 'b.rating DESC, loan_count DESC';
            break;
        default:
            $order_by = 'b.added_date DESC';
    }
    
    $sql = "
        SELECT b.*, 
               COUNT(bl.loan_id) as loan_count,
               (SELECT COUNT(*) FROM book_loans bl2 WHERE bl2.book_id = b.book_id AND bl2.status IN ('active', 'overdue')) as active_loans
        FROM books b
        LEFT JOIN book_loans bl ON b.book_id = bl.book_id
        $where_sql
        GROUP BY b.book_id
        ORDER BY $order_by
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $books = $stmt->fetchAll();
    
    // Get genres for filter
    $genre_stmt = $db->query("SELECT DISTINCT genre FROM books WHERE genre IS NOT NULL AND genre != '' ORDER BY genre");
    $genres = $genre_stmt->fetchAll();
    
} catch (Exception $e) {
    $books = [];
    $genres = [];
    $error = "Error loading books: " . $e->getMessage();
}

// Check if current user is super admin
$is_current_super_admin = ($_SESSION['admin_role'] ?? '') === 'super_admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Explore Books - Admin Panel</title>
    
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
            min-width: 300px;
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

        .add-book-btn {
            background: var(--success-color);
            color: white;
            border: none;
            padding: 0.7rem 1.5rem;
            border-radius: 10px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .add-book-btn:hover {
            background: #059669;
            transform: translateY(-2px);
            color: white;
        }

        /* Letter Filter */
        .letter-filter {
            background: white;
            padding: 1rem 2rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .letter-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
        }

        .letter-buttons span {
            font-weight: 500;
            color: var(--dark-color);
            margin-right: 1rem;
        }

        .letter-btn {
            padding: 0.4rem 0.8rem;
            border: 1px solid #e5e7eb;
            background: white;
            border-radius: 6px;
            color: var(--gray-color);
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .letter-btn:hover,
        .letter-btn.active {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        /* Content Area */
        .content-area {
            padding: 2rem;
        }

        /* Books Grid */
        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
            align-items: start;
        }

        .books-grid.list-view {
            grid-template-columns: 1fr;
        }

        .book-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #f3f4f6;
            position: relative;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .book-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .book-header {
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

        .book-status {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        .status-badge {
            padding: 0.3rem 0.6rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-badge.active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .status-badge.inactive {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-color);
        }

        .status-badge.damaged {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }

        .copies-info {
            font-size: 0.75rem;
            color: var(--gray-color);
        }

        .book-details {
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

        .book-location {
            background: #f8fafc;
            padding: 0.75rem;
            border-radius: 8px;
            margin: 1rem 0;
            font-size: 0.875rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: auto;
            flex-wrap: wrap;
        }

        .btn-action {
            flex: 1;
            padding: 0.5rem 0.75rem;
            border: none;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            text-align: center;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
        }

        .btn-view {
            background: var(--primary-color);
            color: white;
        }

        .btn-view:hover {
            background: var(--primary-dark);
            color: white;
        }

        .btn-edit {
            background: var(--warning-color);
            color: white;
        }

        .btn-edit:hover {
            background: #d97706;
            color: white;
        }

        .btn-delete {
            background: var(--danger-color);
            color: white;
        }

        .btn-delete:hover {
            background: #dc2626;
            color: white;
        }

        .btn-toggle {
            background: var(--secondary-color);
            color: white;
        }

        .btn-toggle:hover {
            background: #6d28d9;
            color: white;
        }

        /* Alert Styles */
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

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            font-weight: 500;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
        }

        .form-control {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 0.75rem;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
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

            .books-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .book-card {
                padding: 1rem;
            }

            .letter-buttons {
                justify-content: center;
            }

            .filter-dropdown {
                left: 0;
                right: 0;
                min-width: auto;
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
                    <a href="javascript:void(0)" onclick="navigateTo('reservations')" class="nav-link">
                        <i class="fas fa-book-open nav-icon"></i>
                        <span>Reservations</span>
                        <?php if (isset($sidebar_counts['reservations']) && $sidebar_counts['reservations'] > 0): ?>
                            <span class="nav-badge"><?php echo $sidebar_counts['reservations']; ?></span>
                        <?php endif; ?>
                    </a>
                </div>

                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('pending')" class="nav-link">
                        <i class="fas fa-clock nav-icon"></i>
                        <span>Pending</span>
                        <?php if (isset($sidebar_counts['pending']) && $sidebar_counts['pending'] > 0): ?>
                            <span class="nav-badge"><?php echo $sidebar_counts['pending']; ?></span>
                        <?php endif; ?>
                    </a>
                </div>

                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('explore')" class="nav-link active">
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
                        <h1>Explore Books</h1>
                        <p>Manage your library book collection</p>
                    </div>

                    <div class="header-actions">
                        <div class="header-search">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchInput" placeholder="Search books..." 
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
                                        <input type="radio" name="sort" value="recent" <?php echo $sort_filter === 'recent' ? 'checked' : ''; ?>>
                                        <label>Most Recent</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="sort" value="oldest" <?php echo $sort_filter === 'oldest' ? 'checked' : ''; ?>>
                                        <label>Oldest First</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="sort" value="az_asc" <?php echo $sort_filter === 'az_asc' ? 'checked' : ''; ?>>
                                        <label>A-Z Title</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="sort" value="az_desc" <?php echo $sort_filter === 'az_desc' ? 'checked' : ''; ?>>
                                        <label>Z-A Title</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="sort" value="popular" <?php echo $sort_filter === 'popular' ? 'checked' : ''; ?>>
                                        <label>Most Popular</label>
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
                                        <input type="radio" name="status" value="inactive" <?php echo $status_filter === 'inactive' ? 'checked' : ''; ?>>
                                        <label>Inactive</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="status" value="damaged" <?php echo $status_filter === 'damaged' ? 'checked' : ''; ?>>
                                        <label>Damaged</label>
                                    </div>
                                </div>
                                <div class="filter-section">
                                    <h6>Genre</h6>
                                    <div class="filter-option">
                                        <input type="radio" name="genre" value="all" <?php echo $genre_filter === 'all' ? 'checked' : ''; ?>>
                                        <label>All Genres</label>
                                    </div>
                                    <?php foreach ($genres as $genre): ?>
                                        <div class="filter-option">
                                            <input type="radio" name="genre" value="<?php echo htmlspecialchars($genre['genre']); ?>" 
                                                   <?php echo $genre_filter === $genre['genre'] ? 'checked' : ''; ?>>
                                            <label><?php echo htmlspecialchars($genre['genre']); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <a href="javascript:void(0)" onclick="navigateTo('add-items')" class="add-book-btn">
                            <i class="fas fa-plus"></i>Add Book
                        </a>
                    </div>
                </div>
            </header>

            <!-- Letter Filter -->
            <div class="letter-filter">
                <div class="letter-buttons">
                    <span>Filter by letter:</span>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['letter' => 'all'])); ?>" 
                       class="letter-btn <?php echo $letter_filter === 'all' ? 'active' : ''; ?>">All</a>
                    <?php for ($i = ord('A'); $i <= ord('Z'); $i++): ?>
                        <?php $letter = chr($i); ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['letter' => $letter])); ?>" 
                           class="letter-btn <?php echo $letter_filter === $letter ? 'active' : ''; ?>"><?php echo $letter; ?></a>
                    <?php endfor; ?>
                </div>
            </div>

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

                <!-- Books Grid -->
                <div class="books-grid" id="booksGrid">
                    <?php if (empty($books)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-book" style="font-size: 4rem; color: var(--gray-color); opacity: 0.5;"></i>
                            <h3 class="mt-3 text-muted">No books found</h3>
                            <p class="text-muted">Try adjusting your search or filter criteria</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($books as $book): ?>
                            <div class="book-card">
                                <div class="book-header">
                                <div class="book-cover">
    <?php 
    // Check if cover_image is a full URL or just a filename
    $cover_src = '';
    if ($book['cover_image']) {
        if (filter_var($book['cover_image'], FILTER_VALIDATE_URL)) {
            // It's a full URL, use it directly
            $cover_src = htmlspecialchars($book['cover_image']);
        } else {
            // It's a local filename, prepend the local path
            $cover_src = '../../assets/images/books/' . htmlspecialchars($book['cover_image']);
        }
    } else {
        // No cover image, use default
        $cover_src = '../../assets/images/default-book.jpg';
    }
    ?>
    <img src="<?php echo $cover_src; ?>" 
         alt="<?php echo htmlspecialchars($book['title']); ?>" 
         onerror="this.src='../../assets/images/default-book.jpg'">
</div>
                                    <div class="book-info">
                                        <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                        <div class="author">by <?php echo htmlspecialchars($book['author']); ?></div>
                                    </div>
                                    <div class="book-status">
                                        <div class="status-badge <?php echo $book['status']; ?>">
                                            <?php echo ucfirst($book['status']); ?>
                                        </div>
                                        <div class="copies-info">
                                            <?php echo $book['available_copies']; ?>/<?php echo $book['total_copies']; ?> available
                                        </div>
                                    </div>
                                </div>

                                <div class="book-details">
                                    <div class="detail-row">
                                        <span class="detail-label">Book ID:</span>
                                        <span class="detail-value"><?php echo $book['book_id']; ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">ISBN:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($book['isbn'] ?: 'N/A'); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Genre:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($book['genre'] ?: 'N/A'); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Publication:</span>
                                        <span class="detail-value"><?php echo $book['publication_year'] ?: 'N/A'; ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Rating:</span>
                                        <span class="detail-value">
                                            <?php if ($book['rating'] > 0): ?>
                                                <i class="fas fa-star" style="color: #fbbf24;"></i> <?php echo $book['rating']; ?>
                                            <?php else: ?>
                                                Not rated
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Total Loans:</span>
                                        <span class="detail-value"><?php echo $book['loan_count']; ?></span>
                                    </div>
                                </div>

                                <?php if ($book['rack_number'] || $book['dewey_decimal_number']): ?>
                                    <div class="book-location">
                                        <strong><i class="fas fa-map-marker-alt"></i> Location:</strong>
                                        <?php if ($book['rack_number']): ?>
                                            Rack <?php echo htmlspecialchars($book['rack_number']); ?>
                                        <?php endif; ?>
                                        <?php if ($book['shelf_position']): ?>
                                            - <?php echo $book['shelf_position']; ?> shelf
                                        <?php endif; ?>
                                        <?php if ($book['floor_level']): ?>
                                            - Floor <?php echo $book['floor_level']; ?>
                                        <?php endif; ?>
                                        <?php if ($book['dewey_decimal_number']): ?>
                                            <br><small>Dewey: <?php echo htmlspecialchars($book['dewey_decimal_number']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="action-buttons">
                                    <button class="btn-action btn-view" onclick="showBookDetails(<?php echo $book['book_id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if (hasPermission('manage_books')): ?>
                                        <button class="btn-action btn-edit" onclick="showEditModal(<?php echo $book['book_id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-action btn-toggle" onclick="toggleBookStatus(<?php echo $book['book_id']; ?>, '<?php echo $book['status'] === 'active' ? 'inactive' : 'active'; ?>')">
                                            <i class="fas fa-<?php echo $book['status'] === 'active' ? 'eye-slash' : 'eye'; ?>"></i>
                                        </button>
                                        <button class="btn-action btn-delete" onclick="showDeleteModal(<?php echo $book['book_id']; ?>, '<?php echo htmlspecialchars($book['title']); ?>')">
                                            <i class="fas fa-trash"></i>
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

    <!-- Book Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-book me-2"></i>Book Details
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

    <!-- Edit Book Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Book
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_book">
                        <input type="hidden" name="book_id" id="editBookId">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Title *</label>
                                    <input type="text" name="title" id="editTitle" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Author *</label>
                                    <input type="text" name="author" id="editAuthor" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Publisher</label>
                                    <input type="text" name="publisher" id="editPublisher" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Publication Year</label>
                                    <input type="number" name="publication_year" id="editYear" class="form-control" min="1000" max="2030">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">Genre</label>
                                    <input type="text" name="genre" id="editGenre" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">Rack Number</label>
                                    <input type="text" name="rack_number" id="editRack" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">Shelf Position</label>
                                    <select name="shelf_position" id="editShelf" class="form-control">
                                        <option value="Top">Top</option>
                                        <option value="Middle">Middle</option>
                                        <option value="Bottom">Bottom</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">Floor Level</label>
                                    <input type="number" name="floor_level" id="editFloor" class="form-control" min="1" max="10">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">Total Copies</label>
                                    <input type="number" name="total_copies" id="editTotal" class="form-control" min="1" max="100">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">Available Copies</label>
                                    <input type="number" name="available_copies" id="editAvailable" class="form-control" min="0">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label class="form-label">Language</label>
                                    <input type="text" name="language" id="editLanguage" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="editDescription" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Book Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>Delete Book
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="deleteForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete_book">
                        <input type="hidden" name="book_id" id="deleteBookId">
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> This action cannot be undone. The book and all related data will be permanently deleted.
                        </div>
                        
                        <p>Are you sure you want to delete "<strong id="deleteBookTitle"></strong>"?</p>
                        
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
                            <i class="fas fa-trash me-1"></i>Delete Book
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
        
        // Navigation with .php extension
        function navigateTo(page) {
            if (page === 'explore') return;
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
                    url.searchParams.set('sort', this.value);
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

            document.querySelectorAll('input[name="genre"]').forEach(input => {
                input.addEventListener('change', function() {
                    const url = new URL(window.location);
                    if (this.value === 'all') {
                        url.searchParams.delete('genre');
                    } else {
                        url.searchParams.set('genre', this.value);
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
                    const grid = document.getElementById('booksGrid');
                    
                    if (view === 'list') {
                        grid.classList.add('list-view');
                    } else {
                        grid.classList.remove('list-view');
                    }
                });
            });
        });

        // Show book details modal
        function showBookDetails(bookId) {
            try {
                const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
                const content = document.getElementById('detailsContent');
                
                content.innerHTML = '<div class="text-center py-4"><div class="spinner-border" role="status"></div><p class="mt-2">Loading...</p></div>';
                modal.show();
                
                // Find the book data from PHP
                const books = <?php echo json_encode($books); ?>;
                const book = books.find(b => b.book_id == bookId);
                
                setTimeout(() => {
                    if (book) {
                        content.innerHTML = generateBookDetailsHTML(book);
                    } else {
                        content.innerHTML = '<div class="text-center py-4"><p class="text-danger">Book not found!</p></div>';
                    }
                }, 500);
            } catch (error) {
                console.error('Error showing book details:', error);
                alert('Error loading book details. Please try again.');
            }
        }

        // Generate book details HTML
        function generateBookDetailsHTML(book) {
            try {
                const borrowedBy = book.active_loans > 0 ? 'Currently borrowed' : 'Available';
                
                return `
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6><i class="fas fa-book me-2"></i>Book Information</h6>
                                </div>
                                <div class="card-body">
                                    <p><strong>Title:</strong> ${book.title || 'N/A'}</p>
                                    <p><strong>Author:</strong> ${book.author || 'N/A'}</p>
                                    <p><strong>ISBN:</strong> ${book.isbn || 'N/A'}</p>
                                    <p><strong>Publisher:</strong> ${book.publisher || 'N/A'}</p>
                                    <p><strong>Publication Year:</strong> ${book.publication_year || 'N/A'}</p>
                                    <p><strong>Genre:</strong> ${book.genre || 'N/A'}</p>
                                    <p><strong>Language:</strong> ${book.language || 'N/A'}</p>
                                    <p><strong>Pages:</strong> ${book.pages || 'N/A'}</p>
                                    <p><strong>Rating:</strong> ${book.rating > 0 ? book.rating + '/5' : 'Not rated'}</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6><i class="fas fa-map-marker-alt me-2"></i>Location & Availability</h6>
                                </div>
                                <div class="card-body">
                                    <p><strong>Book ID:</strong> ${book.book_id}</p>
                                    <p><strong>Dewey Decimal:</strong> ${book.dewey_decimal_number || 'N/A'}</p>
                                    <p><strong>Classification:</strong> ${book.dewey_classification || 'N/A'}</p>
                                    <p><strong>Rack Number:</strong> ${book.rack_number || 'N/A'}</p>
                                    <p><strong>Shelf Position:</strong> ${book.shelf_position || 'N/A'}</p>
                                    <p><strong>Floor Level:</strong> ${book.floor_level || 'N/A'}</p>
                                    <p><strong>Status:</strong> <span class="badge bg-${book.status === 'active' ? 'success' : 'secondary'}">${book.status.charAt(0).toUpperCase() + book.status.slice(1)}</span></p>
                                    <p><strong>Copies:</strong> ${book.available_copies}/${book.total_copies} available</p>
                                    <p><strong>Current Status:</strong> ${borrowedBy}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h6><i class="fas fa-chart-bar me-2"></i>Statistics</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p><strong>Total Loans:</strong><br>${book.loan_count || 0}</p>
                                        </div>
                                        <div class="col-md-3">
                                            <p><strong>Active Loans:</strong><br>${book.active_loans || 0}</p>
                                        </div>
                                        <div class="col-md-3">
                                            <p><strong>Added Date:</strong><br>${book.added_date ? new Date(book.added_date).toLocaleDateString() : 'N/A'}</p>
                                        </div>
                                        <div class="col-md-3">
                                            <p><strong>Last Updated:</strong><br>${book.updated_date ? new Date(book.updated_date).toLocaleDateString() : 'N/A'}</p>
                                        </div>
                                    </div>
                                    ${book.description ? `<div class="mt-3"><p><strong>Description:</strong></p><p class="text-muted">${book.description}</p></div>` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } catch (error) {
                console.error('Error generating book details HTML:', error);
                return '<div class="text-center py-4"><p class="text-danger">Error loading book details!</p></div>';
            }
        }

        // Show edit modal
        function showEditModal(bookId) {
            try {
                const books = <?php echo json_encode($books); ?>;
                const book = books.find(b => b.book_id == bookId);
                
                if (book) {
                    document.getElementById('editBookId').value = book.book_id;
                    document.getElementById('editTitle').value = book.title || '';
                    document.getElementById('editAuthor').value = book.author || '';
                    document.getElementById('editPublisher').value = book.publisher || '';
                    document.getElementById('editYear').value = book.publication_year || '';
                    document.getElementById('editGenre').value = book.genre || '';
                    document.getElementById('editRack').value = book.rack_number || '';
                    document.getElementById('editShelf').value = book.shelf_position || 'Middle';
                    document.getElementById('editFloor').value = book.floor_level || '';
                    document.getElementById('editTotal').value = book.total_copies || '';
                    document.getElementById('editAvailable').value = book.available_copies || '';
                    document.getElementById('editLanguage').value = book.language || '';
                    document.getElementById('editDescription').value = book.description || '';
                    
                    const modal = new bootstrap.Modal(document.getElementById('editModal'));
                    modal.show();
                } else {
                    alert('Book not found!');
                }
            } catch (error) {
                console.error('Error showing edit modal:', error);
                alert('Error loading edit form. Please try again.');
            }
        }

        // Toggle book status
        function toggleBookStatus(bookId, newStatus) {
            try {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.name = 'action';
                actionInput.value = 'toggle_status';
                form.appendChild(actionInput);
                
                const bookIdInput = document.createElement('input');
                bookIdInput.name = 'book_id';
                bookIdInput.value = bookId;
                form.appendChild(bookIdInput);
                
                const statusInput = document.createElement('input');
                statusInput.name = 'new_status';
                statusInput.value = newStatus;
                form.appendChild(statusInput);
                
                document.body.appendChild(form);
                form.submit();
            } catch (error) {
                console.error('Error toggling book status:', error);
                alert('Error processing request. Please try again.');
            }
        }

        // Show delete modal
        function showDeleteModal(bookId, bookTitle) {
            try {
                document.getElementById('deleteBookId').value = bookId;
                document.getElementById('deleteBookTitle').textContent = bookTitle;
                
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

        // Print modal content
        function printModalContent() {
            try {
                const content = document.getElementById('detailsContent').innerHTML;
                const printWindow = window.open('', '_blank', 'width=800,height=600');
                printWindow.document.write(`
                    <html>
                    <head>
                        <title>Book Details - Print</title>
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
                            <h2>Book Details</h2>
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

        // Form validation and submission
        document.addEventListener('DOMContentLoaded', function() {
            const editForm = document.getElementById('editForm');
            if (editForm) {
                editForm.addEventListener('submit', function(e) {
                    const totalCopies = parseInt(document.getElementById('editTotal').value) || 0;
                    const availableCopies = parseInt(document.getElementById('editAvailable').value) || 0;
                    
                    if (availableCopies > totalCopies) {
                        e.preventDefault();
                        alert('Available copies cannot be more than total copies!');
                        return false;
                    }
                    
                    if (totalCopies < 1) {
                        e.preventDefault();
                        alert('Total copies must be at least 1!');
                        return false;
                    }
                });
            }

            // Delete form submission with super admin verification
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

            // Auto-adjust available copies when total copies change
            const editTotalInput = document.getElementById('editTotal');
            if (editTotalInput) {
                editTotalInput.addEventListener('change', function() {
                    const totalCopies = parseInt(this.value) || 0;
                    const availableInput = document.getElementById('editAvailable');
                    const currentAvailable = parseInt(availableInput.value) || 0;
                    
                    if (currentAvailable > totalCopies) {
                        availableInput.value = totalCopies;
                    }
                    
                    availableInput.max = totalCopies;
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
        console.log('Admin Explore Page with Super Admin Protection initialized successfully');
        console.log('Total books loaded:', <?php echo count($books); ?>);
    </script>
</body>
</html>