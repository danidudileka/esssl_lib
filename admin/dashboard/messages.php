<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../includes/auth_check.php';

// Handle message actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $database = new Database();
    $db = $database->getConnection();
    
    try {
        if ($_POST['action'] === 'send_message' && hasPermission('send_messages')) {
            $title = trim($_POST['title']);
            $message = trim($_POST['message']);
            $target_type = $_POST['target_type'];
            
            // Validate required fields
            if (empty($title) || empty($message)) {
                throw new Exception("Title and message are required");
            }
            
            $db->beginTransaction();
            
            // Prepare target_data based on target_type
            $json_target_data = null;
            $member_ids = [];
            
            // Get member IDs first and prepare target_data
            switch ($target_type) {
                case 'all':
                    $stmt = $db->query("SELECT member_id FROM members WHERE status = 'active'");
                    $member_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    $json_target_data = json_encode(['type' => 'all_members']);
                    break;
                    
                case 'membership_type':
                    $membership_type = $_POST['membership_type'] ?? '';
                    if (empty($membership_type)) {
                        throw new Exception("Please select a membership type");
                    }
                    $stmt = $db->prepare("SELECT member_id FROM members WHERE membership_type = ? AND status = 'active'");
                    $stmt->execute([$membership_type]);
                    $member_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    $json_target_data = json_encode(['type' => 'membership_type', 'value' => $membership_type]);
                    break;
                    
                case 'specific_members':
                    $selected_members = $_POST['selected_members'] ?? [];
                    if (empty($selected_members)) {
                        throw new Exception("Please select at least one member");
                    }
                    $member_ids = $selected_members;
                    $json_target_data = json_encode(['type' => 'specific_members', 'member_ids' => $selected_members]);
                    break;
                    
                case 'filter':
                    $filter_membership_type = $_POST['filter_membership_type'] ?? '';
                    $filter_expiry = $_POST['filter_expiry'] ?? '';
                    $filter_overdue = isset($_POST['filter_overdue']) ? 1 : 0;
                    
                    $where_clauses = ["status = 'active'"];
                    $params = [];
                    
                    if (!empty($filter_membership_type)) {
                        $where_clauses[] = "membership_type = ?";
                        $params[] = $filter_membership_type;
                    }
                    
                    if (!empty($filter_expiry)) {
                        $where_clauses[] = "membership_expiry <= DATE_ADD(CURDATE(), INTERVAL ? DAY)";
                        $params[] = $filter_expiry;
                    }
                    
                    if ($filter_overdue) {
                        $where_clauses[] = "member_id IN (
                            SELECT DISTINCT member_id FROM book_loans 
                            WHERE status IN ('active', 'overdue') AND due_date < CURDATE()
                        )";
                    }
                    
                    $query = "SELECT member_id FROM members WHERE " . implode(' AND ', $where_clauses);
                    $stmt = $db->prepare($query);
                    $stmt->execute($params);
                    $member_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    $json_target_data = json_encode([
                        'type' => 'filter',
                        'membership_type' => $filter_membership_type,
                        'expiry_within_days' => $filter_expiry,
                        'has_overdue_books' => $filter_overdue
                    ]);
                    break;
            }
            
            // Insert into admin_messages table
            $admin_msg_stmt = $db->prepare("
                INSERT INTO admin_messages (admin_id, title, message, target_type, target_data, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $admin_msg_stmt->execute([
                $_SESSION['admin_id'],
                $title,
                $message,
                $target_type,
                $json_target_data
            ]);
            
            $message_id = $db->lastInsertId();
            
            // Send notifications to selected members
            $sent_count = 0;
            $notification_stmt = $db->prepare("
                INSERT INTO member_notifications (member_id, title, message, type, is_read, created_at) 
                VALUES (?, ?, ?, 'info', FALSE, NOW())
            ");
            
            foreach ($member_ids as $member_id) {
                try {
                    $notification_stmt->execute([$member_id, $title, $message]);
                    $sent_count++;
                } catch (Exception $e) {
                    // Log error but continue with other members
                    error_log("Failed to send notification to member {$member_id}: " . $e->getMessage());
                }
            }
            
            // Update sent count in admin_messages
            $update_stmt = $db->prepare("UPDATE admin_messages SET sent_to_count = ? WHERE message_id = ?");
            $update_stmt->execute([$sent_count, $message_id]);
            
            $db->commit();
            $success = "Message sent successfully to {$sent_count} member(s)!";
            
        } elseif ($_POST['action'] === 'save_template' && hasPermission('send_messages')) {
            $template_title = trim($_POST['template_title']);
            $template_message = trim($_POST['template_message']);
            
            if (empty($template_title) || empty($template_message)) {
                throw new Exception("Template title and message are required");
            }
            
            // Save as draft/template in admin_messages with special marker
            $stmt = $db->prepare("
                INSERT INTO admin_messages (admin_id, title, message, target_type, target_data, sent_to_count, created_at) 
                VALUES (?, ?, ?, 'template', NULL, -1, NOW())
            ");
            $stmt->execute([$_SESSION['admin_id'], $template_title, $template_message]);
            
            $success = "Message template saved successfully!";
        }
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
        error_log("Message sending error: " . $e->getMessage());
    }
}

// Get message history and templates
try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get search and filter parameters
    $search = $_GET['search'] ?? '';
    $filter = $_GET['filter'] ?? 'all';
    
    $where_clauses = [];
    $params = [];
    
    if ($search) {
        $where_clauses[] = "(title LIKE ? OR message LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param]);
    }
    
    switch ($filter) {
        case 'sent':
            $where_clauses[] = "sent_to_count > 0";
            break;
        case 'templates':
            $where_clauses[] = "sent_to_count = -1";
            break;
        case 'recent':
            $where_clauses[] = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
    }
    
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    
    $sql = "
        SELECT am.*, a.full_name as admin_name
        FROM admin_messages am
        LEFT JOIN admin a ON am.admin_id = a.admin_id
        $where_sql
        ORDER BY am.created_at DESC
        LIMIT 100
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $message_history = $stmt->fetchAll();
    
    // Get member statistics for targeting
    $stats_stmt = $db->query("
        SELECT 
            COUNT(*) as total_members,
            COUNT(CASE WHEN membership_type = 'student' THEN 1 END) as student_count,
            COUNT(CASE WHEN membership_type = 'faculty' THEN 1 END) as faculty_count,
            COUNT(CASE WHEN membership_type = 'staff' THEN 1 END) as staff_count,
            COUNT(CASE WHEN membership_type = 'public' THEN 1 END) as public_count,
            COUNT(CASE WHEN membership_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as expiring_soon,
            COUNT(CASE WHEN member_id IN (
                SELECT DISTINCT member_id FROM book_loans 
                WHERE status IN ('active', 'overdue') AND due_date < CURDATE()
            ) THEN 1 END) as with_overdue
        FROM members 
        WHERE status = 'active'
    ");
    $member_stats = $stats_stmt->fetch();
    
    // Get templates
    $templates_stmt = $db->prepare("
        SELECT title, message FROM admin_messages 
        WHERE sent_to_count = -1 AND admin_id = ?
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $templates_stmt->execute([$_SESSION['admin_id']]);
    $templates = $templates_stmt->fetchAll();
    
    // Get all active members for specific targeting
    $members_stmt = $db->query("
        SELECT member_id, first_name, last_name, member_code, email, membership_type
        FROM members 
        WHERE status = 'active' 
        ORDER BY first_name, last_name
    ");
    $all_members = $members_stmt->fetchAll();
    
} catch (Exception $e) {
    $message_history = [];
    $member_stats = [];
    $templates = [];
    $all_members = [];
    $error = "Error loading messages: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - Admin Panel</title>
    
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

        .compose-btn {
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

        .compose-btn:hover {
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.25rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border-left: 4px solid var(--primary-color);
            transition: all 0.3s;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .stat-card.student {
            border-left-color: var(--success-color);
        }

        .stat-card.faculty {
            border-left-color: var(--secondary-color);
        }

        .stat-card.staff {
            border-left-color: var(--warning-color);
        }

        .stat-card.public {
            border-left-color: var(--accent-color);
        }

        .stat-card.expiring {
            border-left-color: var(--danger-color);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--gray-color);
            font-weight: 500;
        }

        .stat-icon {
            float: right;
            font-size: 1.25rem;
            opacity: 0.3;
            margin-top: -0.25rem;
        }

        /* Message History */
        .messages-section {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            overflow: hidden;
        }

        .messages-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #f3f4f6;
            background: #f8fafc;
        }

        .messages-header h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark-color);
            margin: 0;
        }

        .messages-list {
            max-height: 600px;
            overflow-y: auto;
        }

        .message-item {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #f3f4f6;
            transition: all 0.2s;
            cursor: pointer;
        }

        .message-item:hover {
            background: #f8fafc;
        }

        .message-item:last-child {
            border-bottom: none;
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 0.75rem;
        }

        .message-title {
            font-weight: 600;
            color: var(--dark-color);
            font-size: 1rem;
            margin: 0;
        }

        .message-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.25rem;
        }

        .message-time {
            font-size: 0.8rem;
            color: var(--gray-color);
        }

        .message-stats {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .message-stat {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.8rem;
            color: var(--gray-color);
        }

        .message-content {
            color: var(--gray-color);
            font-size: 0.9rem;
            line-height: 1.5;
            margin: 0.5rem 0;
            max-height: 0;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .message-content.expanded {
            max-height: 300px;
        }

        .message-target {
            display: inline-block;
            background: #f3f4f6;
            color: var(--primary-color);
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }

        .message-target.template {
            background: rgba(124, 58, 237, 0.1);
            color: var(--secondary-color);
        }

        /* Modal Styles */
        .modal-dialog {
            max-width: 800px;
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

        .targeting-section {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
        }

        .targeting-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .targeting-option {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            background: white;
        }

        .targeting-option:hover {
            border-color: var(--primary-color);
            background: rgba(37, 99, 235, 0.05);
        }

        .targeting-option input[type="radio"] {
            margin-right: 0.5rem;
        }

        .specific-members {
            display: none;
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 0.5rem;
            margin-top: 1rem;
        }

        .member-checkbox {
            display: flex;
            align-items: center;
            padding: 0.5rem;
            border-radius: 4px;
            transition: all 0.2s;
        }

        .member-checkbox:hover {
            background: #f3f4f6;
        }

        .member-checkbox input {
            margin-right: 0.5rem;
        }

        .template-shortcuts {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .template-btn {
            padding: 0.5rem 1rem;
            border: 1px solid #e5e7eb;
            background: #f8fafc;
            border-radius: 6px;
            color: var(--dark-color);
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .template-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .message-item {
                padding: 1rem;
            }

            .targeting-options {
                grid-template-columns: 1fr;
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
                    <a href="javascript:void(0)" onclick="navigateTo('messages')" class="nav-link active">
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
                        <h1>Messages</h1>
                        <p>Send notifications and announcements to library members</p>
                    </div>

                    <div class="header-actions">
                        <div class="header-search">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchInput" placeholder="Search messages..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>

                        <div style="position: relative;">
                            <button class="filter-btn" onclick="toggleFilterDropdown()">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                            <div class="filter-dropdown" id="filterDropdown">
                                <div class="filter-section">
                                    <h6>Message Type</h6>
                                    <div class="filter-option">
                                        <input type="radio" name="filter" value="all" <?php echo $filter === 'all' ? 'checked' : ''; ?>>
                                        <label>All Messages</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="filter" value="sent" <?php echo $filter === 'sent' ? 'checked' : ''; ?>>
                                        <label>Sent Messages</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="filter" value="templates" <?php echo $filter === 'templates' ? 'checked' : ''; ?>>
                                        <label>Templates</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="filter" value="recent" <?php echo $filter === 'recent' ? 'checked' : ''; ?>>
                                        <label>Recent (7 days)</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (hasPermission('send_messages')): ?>
                            <button class="compose-btn" onclick="showComposeModal()">
                                <i class="fas fa-plus"></i>Compose Message
                            </button>
                        <?php endif; ?>
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

                <!-- Member Statistics for Targeting -->
                <div class="stats-section">
                    <div class="stats-grid">
                        <div class="stat-card" onclick="setTargeting('all')">
                            <div class="stat-value"><?php echo number_format($member_stats['total_members']); ?></div>
                            <div class="stat-label">Total Members</div>
                            <i class="fas fa-users stat-icon"></i>
                        </div>
                        <div class="stat-card student" onclick="setTargeting('student')">
                            <div class="stat-value"><?php echo number_format($member_stats['student_count']); ?></div>
                            <div class="stat-label">Students</div>
                            <i class="fas fa-user-graduate stat-icon"></i>
                        </div>
                        <div class="stat-card faculty" onclick="setTargeting('faculty')">
                            <div class="stat-value"><?php echo number_format($member_stats['faculty_count']); ?></div>
                            <div class="stat-label">Faculty</div>
                            <i class="fas fa-chalkboard-teacher stat-icon"></i>
                        </div>
                        <div class="stat-card staff" onclick="setTargeting('staff')">
                            <div class="stat-value"><?php echo number_format($member_stats['staff_count']); ?></div>
                            <div class="stat-label">Staff</div>
                            <i class="fas fa-id-badge stat-icon"></i>
                        </div>
                        <div class="stat-card public" onclick="setTargeting('public')">
                            <div class="stat-value"><?php echo number_format($member_stats['public_count']); ?></div>
                            <div class="stat-label">Public</div>
                            <i class="fas fa-user stat-icon"></i>
                        </div>
                        <div class="stat-card expiring" onclick="setTargeting('expiring')">
                            <div class="stat-value"><?php echo number_format($member_stats['expiring_soon']); ?></div>
                            <div class="stat-label">Expiring Soon</div>
                            <i class="fas fa-exclamation-triangle stat-icon"></i>
                        </div>
                        <div class="stat-card expiring" onclick="setTargeting('overdue')">
                            <div class="stat-value"><?php echo number_format($member_stats['with_overdue']); ?></div>
                            <div class="stat-label">With Overdue</div>
                            <i class="fas fa-clock stat-icon"></i>
                        </div>
                    </div>
                </div>

                <!-- Message History -->
                <div class="messages-section">
                    <div class="messages-header">
                        <h3>Message History</h3>
                    </div>
                    <div class="messages-list">
                        <?php if (empty($message_history)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-envelope" style="font-size: 4rem; color: var(--gray-color); opacity: 0.5;"></i>
                                <h3 class="mt-3 text-muted">No messages found</h3>
                                <p class="text-muted">Start by composing your first message</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($message_history as $message): ?>
                                <div class="message-item" onclick="toggleMessageContent(<?php echo $message['message_id']; ?>)">
                                    <div class="message-header">
                                        <h4 class="message-title"><?php echo htmlspecialchars($message['title']); ?></h4>
                                        <div class="message-meta">
                                            <div class="message-time">
                                                <?php echo date('M j, Y g:i A', strtotime($message['created_at'])); ?>
                                            </div>
                                            <div class="message-stats">
                                                <?php if ($message['sent_to_count'] == -1): ?>
                                                    <span class="message-target template">Template</span>
                                                <?php else: ?>
                                                    <div class="message-stat">
                                                        <i class="fas fa-users"></i>
                                                        <span><?php echo $message['sent_to_count']; ?> recipients</span>
                                                    </div>
                                                    <div class="message-stat">
                                                        <i class="fas fa-user"></i>
                                                        <span><?php echo htmlspecialchars($message['admin_name']); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="message-content" id="message-content-<?php echo $message['message_id']; ?>">
                                        <?php echo nl2br(htmlspecialchars($message['message'])); ?>
                                        
                                        <?php if ($message['target_type'] && $message['sent_to_count'] != -1): ?>
                                            <div class="mt-2">
                                                <span class="message-target">
                                                    Target: <?php echo ucfirst(str_replace('_', ' ', $message['target_type'])); ?>
                                                    <?php if ($message['target_data'] && $message['target_type'] == 'membership_type'): ?>
                                                        <?php 
                                                        $target_info = json_decode($message['target_data'], true);
                                                        if (isset($target_info['value'])) {
                                                            echo '(' . ucfirst($target_info['value']) . ')';
                                                        }
                                                        ?>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Compose Message Modal -->
    <div class="modal fade" id="composeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-envelope me-2"></i>Compose Message
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="composeForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="send_message">
                        
                        <!-- Message Templates -->
                        <?php if (!empty($templates)): ?>
                            <div class="form-group">
                                <label class="form-label">Quick Templates</label>
                                <div class="template-shortcuts">
                                    <?php foreach ($templates as $template): ?>
                                        <button type="button" class="template-btn" 
                                                onclick="loadTemplate('<?php echo htmlspecialchars($template['title']); ?>', '<?php echo htmlspecialchars($template['message']); ?>')">
                                            <?php echo htmlspecialchars($template['title']); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label class="form-label">Message Title *</label>
                                    <input type="text" name="title" id="messageTitle" class="form-control" 
                                           placeholder="Enter message title..." required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Message Content *</label>
                            <textarea name="message" id="messageContent" class="form-control" rows="6" 
                                      placeholder="Type your message here..." required></textarea>
                        </div>
                        
                        <!-- Targeting Section -->
                        <div class="targeting-section">
                            <h6><i class="fas fa-bullseye me-2"></i>Target Audience</h6>
                            <div class="targeting-options">
                                <div class="targeting-option">
                                    <input type="radio" name="target_type" value="all" id="target_all" checked>
                                    <label for="target_all">All Members (<?php echo $member_stats['total_members']; ?>)</label>
                                </div>
                                <div class="targeting-option">
                                    <input type="radio" name="target_type" value="membership_type" id="target_membership">
                                    <label for="target_membership">By Membership Type</label>
                                </div>
                                <div class="targeting-option">
                                    <input type="radio" name="target_type" value="specific_members" id="target_specific">
                                    <label for="target_specific">Specific Members</label>
                                </div>
                                <div class="targeting-option">
                                    <input type="radio" name="target_type" value="filter" id="target_filter">
                                    <label for="target_filter">Advanced Filter</label>
                                </div>
                            </div>
                            
                            <!-- Membership Type Selection -->
                            <div id="membershipTypeSelect" style="display: none;">
                                <label class="form-label mt-3">Select Membership Type</label>
                                <select name="membership_type" class="form-control">
                                    <option value="student">Students (<?php echo $member_stats['student_count']; ?>)</option>
                                    <option value="faculty">Faculty (<?php echo $member_stats['faculty_count']; ?>)</option>
                                    <option value="staff">Staff (<?php echo $member_stats['staff_count']; ?>)</option>
                                    <option value="public">Public (<?php echo $member_stats['public_count']; ?>)</option>
                                </select>
                            </div>
                            
                            <!-- Specific Members Selection -->
                            <div id="specificMembers" class="specific-members">
                                <label class="form-label">Select Members</label>
                                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 6px; padding: 0.5rem;">
                                    <?php foreach ($all_members as $member): ?>
                                        <div class="member-checkbox">
                                            <input type="checkbox" name="selected_members[]" value="<?php echo $member['member_id']; ?>" 
                                                   id="member_<?php echo $member['member_id']; ?>">
                                            <label for="member_<?php echo $member['member_id']; ?>">
                                                <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?> 
                                                (<?php echo $member['member_code']; ?>) - <?php echo ucfirst($member['membership_type']); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <!-- Advanced Filter Options -->
                            <div id="advancedFilter" style="display: none;">
                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Membership Type</label>
                                        <select name="filter_membership_type" class="form-control">
                                            <option value="">All Types</option>
                                            <option value="student">Students</option>
                                            <option value="faculty">Faculty</option>
                                            <option value="staff">Staff</option>
                                            <option value="public">Public</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Membership Expiring Within</label>
                                        <select name="filter_expiry" class="form-control">
                                            <option value="">No Filter</option>
                                            <option value="7">7 Days</option>
                                            <option value="30">30 Days</option>
                                            <option value="90">90 Days</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-md-12">
                                        <div class="form-check">
                                            <input type="checkbox" name="filter_overdue" value="1" class="form-check-input" id="filterOverdue">
                                            <label class="form-check-label" for="filterOverdue">
                                                Only members with overdue books
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="saveAsTemplate()">
                            <i class="fas fa-save me-1"></i>Save as Template
                        </button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-1"></i>Send Message
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Navigation function
        function navigateTo(page) {
            if (page === 'messages') return;
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
            document.querySelectorAll('input[name="filter"]').forEach(input => {
                input.addEventListener('change', function() {
                    const url = new URL(window.location);
                    if (this.value === 'all') {
                        url.searchParams.delete('filter');
                    } else {
                        url.searchParams.set('filter', this.value);
                    }
                    window.location.href = url.toString();
                });
            });
        });

        // Show compose modal
        function showComposeModal() {
            const modal = new bootstrap.Modal(document.getElementById('composeModal'));
            modal.show();
        }

        // Toggle message content
        function toggleMessageContent(messageId) {
            const content = document.getElementById('message-content-' + messageId);
            content.classList.toggle('expanded');
        }

        // Load template
        function loadTemplate(title, message) {
            document.getElementById('messageTitle').value = title;
            document.getElementById('messageContent').value = message;
        }

        // Set targeting from stats cards
        function setTargeting(type) {
            showComposeModal();
            
            setTimeout(() => {
                switch(type) {
                    case 'all':
                        document.getElementById('target_all').checked = true;
                        break;
                    case 'student':
                    case 'faculty':
                    case 'staff':
                    case 'public':
                        document.getElementById('target_membership').checked = true;
                        document.querySelector('select[name="membership_type"]').value = type;
                        updateTargetingDisplay();
                        break;
                    case 'expiring':
                        document.getElementById('target_filter').checked = true;
                        document.querySelector('select[name="filter_expiry"]').value = '30';
                        updateTargetingDisplay();
                        break;
                    case 'overdue':
                        document.getElementById('target_filter').checked = true;
                        document.getElementById('filterOverdue').checked = true;
                        updateTargetingDisplay();
                        break;
                }
            }, 100);
        }

        // Update targeting display
        function updateTargetingDisplay() {
            const targetType = document.querySelector('input[name="target_type"]:checked').value;
            
            // Hide all targeting options
            document.getElementById('membershipTypeSelect').style.display = 'none';
            document.getElementById('specificMembers').style.display = 'none';
            document.getElementById('advancedFilter').style.display = 'none';
            
            // Show relevant option
            switch(targetType) {
                case 'membership_type':
                    document.getElementById('membershipTypeSelect').style.display = 'block';
                    break;
                case 'specific_members':
                    document.getElementById('specificMembers').style.display = 'block';
                    break;
                case 'filter':
                    document.getElementById('advancedFilter').style.display = 'block';
                    break;
            }
        }

        // Setup targeting listeners
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('input[name="target_type"]').forEach(input => {
                input.addEventListener('change', updateTargetingDisplay);
            });
        });

        // Save as template
        function saveAsTemplate() {
            const title = document.getElementById('messageTitle').value.trim();
            const message = document.getElementById('messageContent').value.trim();
            
            if (!title || !message) {
                alert('Please enter both title and message to save as template');
                return;
            }
            
            if (!confirm('Save this message as a template?')) {
                return;
            }
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.name = 'action';
            actionInput.value = 'save_template';
            form.appendChild(actionInput);
            
            const titleInput = document.createElement('input');
            titleInput.name = 'template_title';
            titleInput.value = title;
            form.appendChild(titleInput);
            
            const messageInput = document.createElement('input');
            messageInput.name = 'template_message';
            messageInput.value = message;
            form.appendChild(messageInput);
            
            document.body.appendChild(form);
            form.submit();
        }

        // Initialize page
        console.log('Admin Messages Page initialized successfully');
        console.log('Total message history loaded:', <?php echo count($message_history); ?>);
    </script>
</body>
</html>