<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../includes/auth_check.php';

// Handle payment processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $database = new Database();
    $db = $database->getConnection();
    
    try {
        if ($_POST['action'] === 'process_payment' && hasPermission('process_payments')) {
            $member_id = (int)$_POST['member_id'];
            $payment_type = $_POST['payment_type'];
            $renewal_type = $_POST['renewal_type'] ?? null;
            $amount = (float)$_POST['amount'];
            $payment_method = $_POST['payment_method'];
            $notes = trim($_POST['notes']) ?? '';
            
            // Validate required fields
            if (empty($member_id) || empty($payment_type) || $amount <= 0) {
                throw new Exception("Please fill in all required fields with valid values");
            }
            
            // Validate member exists
            $member_stmt = $db->prepare("SELECT * FROM members WHERE member_id = ?");
            $member_stmt->execute([$member_id]);
            $member = $member_stmt->fetch();
            
            if (!$member) {
                throw new Exception("Member not found");
            }
            
            $db->beginTransaction();
            
            // Insert payment record
            $payment_stmt = $db->prepare("
                INSERT INTO payments (member_id, payment_type, payment_method, amount, renewal_type, payment_date, notes, processed_by, created_at)
                VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, NOW())
            ");
            
            $payment_stmt->execute([
                $member_id,
                $payment_type,
                $payment_method,
                $amount,
                $renewal_type,
                $notes,
                $_SESSION['admin_id']
            ]);
            
            $payment_id = $db->lastInsertId();
            
            // Update membership expiry if it's a renewal
            if ($payment_type === 'renewal' && !empty($renewal_type)) {
                $current_expiry = $member['membership_expiry'];
                $new_expiry_date = null;
                
                // Calculate new expiry date
                $base_date = (strtotime($current_expiry) > time()) ? $current_expiry : date('Y-m-d');
                
                switch ($renewal_type) {
                    case 'weekly':
                        $new_expiry_date = date('Y-m-d', strtotime($base_date . ' + 1 week'));
                        break;
                    case 'monthly':
                        $new_expiry_date = date('Y-m-d', strtotime($base_date . ' + 1 month'));
                        break;
                    case 'yearly':
                        $new_expiry_date = date('Y-m-d', strtotime($base_date . ' + 1 year'));
                        break;
                }
                
                if ($new_expiry_date) {
                    $update_stmt = $db->prepare("UPDATE members SET membership_expiry = ?, status = 'active' WHERE member_id = ?");
                    $update_stmt->execute([$new_expiry_date, $member_id]);
                    
                    // Send notification to member
                    $notification_stmt = $db->prepare("
                        INSERT INTO member_notifications (member_id, title, message, type, is_read, created_at)
                        VALUES (?, ?, ?, 'success', FALSE, NOW())
                    ");
                    
                    $notification_title = "Membership Renewed";
                    $notification_message = "Your membership has been successfully renewed!\n\n";
                    $notification_message .= "Renewal Type: " . ucfirst($renewal_type) . "\n";
                    $notification_message .= "New Expiry Date: " . date('M j, Y', strtotime($new_expiry_date)) . "\n";
                    $notification_message .= "Amount Paid: LKR " . number_format($amount, 2) . "\n";
                    $notification_message .= "Payment Method: " . ucfirst($payment_method);
                    
                    $notification_stmt->execute([$member_id, $notification_title, $notification_message]);
                }
            }
            
            // For new registration, update member status
            if ($payment_type === 'new_registration') {
                $update_stmt = $db->prepare("UPDATE members SET status = 'active' WHERE member_id = ?");
                $update_stmt->execute([$member_id]);
                
                // Send welcome notification
                $notification_stmt = $db->prepare("
                    INSERT INTO member_notifications (member_id, title, message, type, is_read, created_at)
                    VALUES (?, ?, ?, 'success', FALSE, NOW())
                ");
                
                $notification_title = "Registration Payment Processed";
                $notification_message = "Welcome to ESSSL Library!\n\n";
                $notification_message .= "Your registration payment has been processed successfully.\n";
                $notification_message .= "Amount: LKR " . number_format($amount, 2) . "\n";
                $notification_message .= "Payment Method: " . ucfirst($payment_method) . "\n\n";
                $notification_message .= "You can now enjoy all library services!";
                
                $notification_stmt->execute([$member_id, $notification_title, $notification_message]);
            }
            
            $db->commit();
            $success = "Payment processed successfully! Payment ID: {$payment_id}";
            
        }
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
    }
}

// Get recent payments
try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get search parameters
    $search = $_GET['search'] ?? '';
    $filter_type = $_GET['filter_type'] ?? '';
    $filter_method = $_GET['filter_method'] ?? '';
    
    $where_clauses = [];
    $params = [];
    
    if ($search) {
        $where_clauses[] = "(CONCAT(m.first_name, ' ', m.last_name) LIKE ? OR m.member_code LIKE ? OR m.email LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param]);
    }
    
    if ($filter_type) {
        $where_clauses[] = "p.payment_type = ?";
        $params[] = $filter_type;
    }
    
    if ($filter_method) {
        $where_clauses[] = "p.payment_method = ?";
        $params[] = $filter_method;
    }
    
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    
    $sql = "
        SELECT p.*, 
               CONCAT(m.first_name, ' ', m.last_name) as member_name,
               m.member_code, m.email, m.membership_type, m.membership_expiry,
               CONCAT(a.full_name) as processed_by_name
        FROM payments p
        JOIN members m ON p.member_id = m.member_id
        LEFT JOIN admin a ON p.processed_by = a.admin_id
        $where_sql
        ORDER BY p.payment_date DESC
        LIMIT 100
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $recent_payments = $stmt->fetchAll();
    
    // Get payment statistics
    $stats_stmt = $db->query("
        SELECT 
            COUNT(*) as total_payments,
            SUM(amount) as total_revenue,
            SUM(CASE WHEN payment_type = 'renewal' THEN amount ELSE 0 END) as renewal_revenue,
            SUM(CASE WHEN payment_type = 'fine' THEN amount ELSE 0 END) as fine_revenue,
            SUM(CASE WHEN payment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN amount ELSE 0 END) as monthly_revenue,
            COUNT(CASE WHEN payment_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as weekly_payments
        FROM payments
    ");
    $payment_stats = $stats_stmt->fetch();
    
} catch (Exception $e) {
    $recent_payments = [];
    $payment_stats = [
        'total_payments' => 0,
        'total_revenue' => 0,
        'renewal_revenue' => 0,
        'fine_revenue' => 0,
        'monthly_revenue' => 0,
        'weekly_payments' => 0
    ];
    $error = "Error loading payment data: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership - Admin Panel</title>
    
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

        .process-payment-btn {
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

        .process-payment-btn:hover {
            background: #059669;
            transform: translateY(-2px);
            color: white;
        }

        /* Content Area */
        .content-area {
            padding: 2rem;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border-left: 4px solid var(--primary-color);
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .stat-card.revenue {
            border-left-color: var(--success-color);
        }

        .stat-card.renewal {
            border-left-color: var(--secondary-color);
        }

        .stat-card.fine {
            border-left-color: var(--warning-color);
        }

        .stat-card.monthly {
            border-left-color: var(--accent-color);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .stat-icon.revenue {
            background: var(--success-color);
        }

        .stat-icon.renewal {
            background: var(--secondary-color);
        }

        .stat-icon.fine {
            background: var(--warning-color);
        }

        .stat-icon.monthly {
            background: var(--accent-color);
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--gray-color);
            font-weight: 500;
        }

        .stat-change {
            font-size: 0.8rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }

        .stat-change.positive {
            color: var(--success-color);
        }

        .stat-change.negative {
            color: var(--danger-color);
        }

        /* Member Search Section */
        .member-search-section {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }

        .search-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .search-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark-color);
        }

        .member-search-form {
            margin-bottom: 2rem;
        }

        .search-input-group {
            position: relative;
            max-width: 400px;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 3rem;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            font-size: 0.9rem;
            transition: all 0.3s;
        }

        .search-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            outline: none;
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-color);
        }

        .member-results {
            display: none;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            max-height: 300px;
            overflow-y: auto;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            z-index: 10;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .member-result-item {
            padding: 1rem;
            border-bottom: 1px solid #f3f4f6;
            cursor: pointer;
            transition: all 0.2s;
        }

        .member-result-item:hover {
            background: #f8fafc;
        }

        .member-result-item:last-child {
            border-bottom: none;
        }

        .member-result-name {
            font-weight: 600;
            color: var(--dark-color);
        }

        .member-result-details {
            font-size: 0.8rem;
            color: var(--gray-color);
            margin-top: 0.25rem;
        }

        .member-status {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .member-status.active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .member-status.expired {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }

        /* Selected Member Display */
        .selected-member {
            display: none;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 1.5rem;
            margin: 1rem 0;
        }

        .member-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .member-info-item {
            display: flex;
            flex-direction: column;
        }

        .member-info-label {
            font-size: 0.8rem;
            color: var(--gray-color);
            font-weight: 500;
            margin-bottom: 0.25rem;
        }

        .member-info-value {
            font-weight: 600;
            color: var(--dark-color);
        }

        /* Payment Form */
        .payment-form {
            display: none;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 2rem;
            margin-top: 1rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            font-weight: 500;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
            display: block;
        }

        .form-control {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 0.75rem;
            transition: all 0.3s;
            width: 100%;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            outline: none;
        }

        .renewal-shortcuts {
            display: none;
            margin-top: 1rem;
        }

        .shortcut-btns {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .shortcut-btn {
            padding: 0.5rem 1rem;
            border: 1px solid #e5e7eb;
            background: #f8fafc;
            border-radius: 6px;
            color: var(--dark-color);
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .shortcut-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        /* Payment History */
        .payments-section {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-top: 2rem;
        }

        .payments-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #f3f4f6;
            background: #f8fafc;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .payments-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark-color);
            margin: 0;
        }

        .payments-list {
            max-height: 600px;
            overflow-y: auto;
        }

        .payment-item {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #f3f4f6;
            transition: all 0.2s;
        }

        .payment-item:hover {
            background: #f8fafc;
        }

        .payment-item:last-child {
            border-bottom: none;
        }

        .payment-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 0.75rem;
        }

        .payment-member {
            font-weight: 600;
            color: var(--dark-color);
            font-size: 1rem;
        }

        .payment-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.25rem;
        }

        .payment-amount {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--success-color);
        }

        .payment-date {
            font-size: 0.8rem;
            color: var(--gray-color);
        }

        .payment-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            margin-top: 0.5rem;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            border-radius: 6px;
        }

        .payment-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .payment-detail {
            font-size: 0.85rem;
        }

        .payment-detail-label {
            color: var(--gray-color);
            font-weight: 500;
        }

        .payment-detail-value {
            color: var(--dark-color);
            font-weight: 600;
        }

        .payment-type-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .payment-type-badge.renewal {
            background: rgba(124, 58, 237, 0.1);
            color: var(--secondary-color);
        }

        .payment-type-badge.new_registration {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .payment-type-badge.fine {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
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

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .payment-details {
                grid-template-columns: 1fr;
            }

            .header-content {
                flex-direction: column;
                align-items: stretch;
            }

            .shortcut-btns {
                justify-content: center;
            }
        }
        .nav-badge {
            margin-left: auto;
            background: var(--danger-color);
            color: white;
            font-size: 0.75rem;
            padding: 0.3rem 0.6rem;
            border-radius: 15px;
            font-weight: 600;
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
                    <a href="javascript:void(0)" onclick="navigateTo('messages')" class="nav-link">
                        <i class="fas fa-envelope nav-icon"></i>
                        <span>Messages</span>
                    </a>
                </div>

                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('membership')" class="nav-link active">
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
                        <h1>Membership Management</h1>
                        <p>Process payments, renewals, and membership fees</p>
                    </div>

                    <div class="header-actions">
                        <button class="process-payment-btn" onclick="showMemberSearch()">
                            <i class="fas fa-credit-card"></i>Process Payment
                        </button>
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

                <!-- Payment Statistics -->
                <div class="stats-grid">
                    <div class="stat-card revenue">
                        <div class="stat-header">
                            <div>
                                <div class="stat-value">LKR <?php echo number_format($payment_stats['total_revenue'], 2); ?></div>
                                <div class="stat-label">Total Revenue</div>
                            </div>
                            <div class="stat-icon revenue">
                                <i class="fas fa-dollar-sign"></i>
                            </div>
                        </div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i> <?php echo $payment_stats['total_payments']; ?> payments
                        </div>
                    </div>

                    <div class="stat-card renewal">
                        <div class="stat-header">
                            <div>
                                <div class="stat-value">LKR <?php echo number_format($payment_stats['renewal_revenue'], 2); ?></div>
                                <div class="stat-label">Renewal Revenue</div>
                            </div>
                            <div class="stat-icon renewal">
                                <i class="fas fa-sync-alt"></i>
                            </div>
                        </div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i> From memberships
                        </div>
                    </div>

                    <div class="stat-card fine">
                        <div class="stat-header">
                            <div>
                                <div class="stat-value">LKR <?php echo number_format($payment_stats['fine_revenue'], 2); ?></div>
                                <div class="stat-label">Fine Revenue</div>
                            </div>
                            <div class="stat-icon fine">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                        </div>
                        <div class="stat-change">
                            <i class="fas fa-clock"></i> Late returns
                        </div>
                    </div>

                    <div class="stat-card monthly">
                        <div class="stat-header">
                            <div>
                                <div class="stat-value">LKR <?php echo number_format($payment_stats['monthly_revenue'], 2); ?></div>
                                <div class="stat-label">Monthly Revenue</div>
                            </div>
                            <div class="stat-icon monthly">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                        </div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i> <?php echo $payment_stats['weekly_payments']; ?> this week
                        </div>
                    </div>
                </div>

                <!-- Member Search Section -->
                <div class="member-search-section" id="memberSearchSection" style="display: none;">
                    <div class="search-header">
                        <h3 class="search-title">
                            <i class="fas fa-search me-2"></i>Find Member
                        </h3>
                        <button type="button" class="btn btn-secondary" onclick="hideMemberSearch()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>

                    <div class="member-search-form">
                        <div class="search-input-group">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" id="memberSearch" class="search-input" 
                                   placeholder="Search by name, member code, or email..." 
                                   autocomplete="off">
                            <div class="member-results" id="memberResults"></div>
                        </div>
                    </div>

                    <!-- Selected Member Display -->
                    <div class="selected-member" id="selectedMember">
                        <h4 style="margin-bottom: 1rem; color: var(--dark-color);">
                            <i class="fas fa-user me-2"></i>Selected Member
                        </h4>
                        <div class="member-info" id="memberInfo">
                            <!-- Member details will be populated here -->
                        </div>
                    </div>

                    <!-- Payment Form -->
                    <div class="payment-form" id="paymentForm">
                        <h4 style="margin-bottom: 1.5rem; color: var(--dark-color);">
                            <i class="fas fa-credit-card me-2"></i>Payment Details
                        </h4>
                        
                        <form method="POST" id="processPaymentForm">
                            <input type="hidden" name="action" value="process_payment">
                            <input type="hidden" name="member_id" id="selectedMemberId">
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Payment Type *</label>
                                    <select name="payment_type" id="paymentType" class="form-control" required onchange="toggleRenewalOptions()">
                                        <option value="">Select Payment Type</option>
                                        <option value="new_registration">New Registration</option>
                                        <option value="renewal">Membership Renewal</option>
                                        <option value="fine">Late Fee/Fine</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Payment Method *</label>
                                    <select name="payment_method" class="form-control" required>
                                        <option value="">Select Method</option>
                                        <option value="cash">Cash</option>
                                        <option value="card">Card</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Amount *</label>
                                    <div class="input-group">
                                        <span class="input-group-text">LKR</span>
                                        <input type="number" name="amount" id="paymentAmount" class="form-control" 
                                               step="0.01" min="0" required placeholder="0.00">
                                    </div>
                                </div>

                                <div class="form-group renewal-only" id="renewalTypeGroup" style="display: none;">
                                    <label class="form-label">Renewal Period *</label>
                                    <select name="renewal_type" id="renewalType" class="form-control">
                                        <option value="">Select Period</option>
                                        <option value="weekly">Weekly (LKR 2,500)</option>
                                        <option value="monthly">Monthly (LKR 7,500)</option>
                                        <option value="yearly">Yearly (LKR 75,000)</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Renewal Shortcuts -->
                            <div class="renewal-shortcuts" id="renewalShortcuts">
                                <label class="form-label">Quick Select:</label>
                                <div class="shortcut-btns">
                                    <button type="button" class="shortcut-btn" onclick="setRenewal('weekly', 2500)">
                                        1 Week - LKR 2,500
                                    </button>
                                    <button type="button" class="shortcut-btn" onclick="setRenewal('monthly', 7500)">
                                        1 Month - LKR 7,500
                                    </button>
                                    <button type="button" class="shortcut-btn" onclick="setRenewal('yearly', 75000)">
                                        1 Year - LKR 75,000
                                    </button>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="3" 
                                          placeholder="Additional notes or comments..."></textarea>
                            </div>

                            <div class="form-actions" style="margin-top: 2rem; text-align: right;">
                                <button type="button" class="btn btn-secondary me-2" onclick="resetPaymentForm()">
                                    Reset Form
                                </button>
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-credit-card me-1"></i>Process Payment
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Payment History -->
                <div class="payments-section">
                    <div class="payments-header">
                        <h3 class="payments-title">Recent Payments</h3>
                        <div class="d-flex gap-2">
                            <select class="form-control" style="width: auto;" onchange="filterPayments(this.value)">
                                <option value="">All Types</option>
                                <option value="renewal">Renewals</option>
                                <option value="new_registration">New Registration</option>
                                <option value="fine">Fines</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="payments-list">
                        <?php if (empty($recent_payments)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-credit-card" style="font-size: 4rem; color: var(--gray-color); opacity: 0.5;"></i>
                                <h3 class="mt-3 text-muted">No payments found</h3>
                                <p class="text-muted">Start by processing your first payment</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_payments as $payment): ?>
                                <div class="payment-item">
                                    <div class="payment-header">
                                        <div>
                                            <div class="payment-member"><?php echo htmlspecialchars($payment['member_name']); ?></div>
                                            <div style="font-size: 0.85rem; color: var(--gray-color); margin-top: 0.25rem;">
                                                <?php echo htmlspecialchars($payment['member_code']); ?> • <?php echo htmlspecialchars($payment['email']); ?>
                                            </div>
                                        </div>
                                        <div class="payment-meta">
                                            <div class="payment-amount">LKR <?php echo number_format($payment['amount'], 2); ?></div>
                                            <div class="payment-date">
                                                <?php echo date('M j, Y g:i A', strtotime($payment['payment_date'])); ?>
                                            </div>
                                            <div class="payment-actions">
                                                <button class="btn btn-sm btn-outline-primary" onclick="exportPaymentPDF(<?php echo $payment['payment_id']; ?>)" title="Export PDF">
                                                    <i class="fas fa-file-pdf"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="payment-details">
                                        <div class="payment-detail">
                                            <span class="payment-detail-label">Type: </span>
                                            <span class="payment-type-badge <?php echo $payment['payment_type']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $payment['payment_type'])); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="payment-detail">
                                            <span class="payment-detail-label">Method: </span>
                                            <span class="payment-detail-value"><?php echo ucfirst($payment['payment_method']); ?></span>
                                        </div>
                                        
                                        <?php if ($payment['renewal_type']): ?>
                                            <div class="payment-detail">
                                                <span class="payment-detail-label">Period: </span>
                                                <span class="payment-detail-value"><?php echo ucfirst($payment['renewal_type']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="payment-detail">
                                            <span class="payment-detail-label">Processed by: </span>
                                            <span class="payment-detail-value"><?php echo htmlspecialchars($payment['processed_by_name']); ?></span>
                                        </div>
                                        
                                        <?php if ($payment['notes']): ?>
                                            <div class="payment-detail" style="grid-column: 1 / -1;">
                                                <span class="payment-detail-label">Notes: </span>
                                                <span class="payment-detail-value"><?php echo htmlspecialchars($payment['notes']); ?></span>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Navigation function
        function navigateTo(page) {
            if (page === 'membership') return;
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

        // Show member search section
        function showMemberSearch() {
            document.getElementById('memberSearchSection').style.display = 'block';
            document.getElementById('memberSearch').focus();
        }

        // Hide member search section
        function hideMemberSearch() {
            document.getElementById('memberSearchSection').style.display = 'none';
            resetPaymentForm();
        }

        // Member search functionality
        let searchTimeout;
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('memberSearch');
            const resultsDiv = document.getElementById('memberResults');
            
            searchInput.addEventListener('input', function() {
                const query = this.value.trim();
                clearTimeout(searchTimeout);
                
                if (query.length < 2) {
                    resultsDiv.style.display = 'none';
                    return;
                }
                
                searchTimeout = setTimeout(() => {
                    searchMembers(query);
                }, 300);
            });
            
            // Hide results when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.search-input-group')) {
                    resultsDiv.style.display = 'none';
                }
            });
        });

        // Search members via AJAX
        async function searchMembers(query) {
            try {
                const response = await fetch('../../api/search_members.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ query: query })
                });
                
                const data = await response.json();
                const resultsDiv = document.getElementById('memberResults');
                
                if (data.success && data.members.length > 0) {
                    resultsDiv.innerHTML = data.members.map(member => `
                        <div class="member-result-item" onclick="selectMember(${member.member_id}, '${member.first_name}', '${member.last_name}', '${member.member_code}', '${member.email}', '${member.membership_type}', '${member.membership_expiry}', '${member.status}')">
                            <div class="member-result-name">${member.first_name} ${member.last_name}</div>
                            <div class="member-result-details">
                                ${member.member_code} • ${member.email} • ${member.membership_type}
                                <span class="member-status ${member.status}">${member.status}</span>
                            </div>
                        </div>
                    `).join('');
                    resultsDiv.style.display = 'block';
                } else {
                    resultsDiv.innerHTML = '<div class="member-result-item">No members found</div>';
                    resultsDiv.style.display = 'block';
                }
            } catch (error) {
                console.error('Search error:', error);
            }
        }

        // Select a member
        function selectMember(id, firstName, lastName, memberCode, email, membershipType, membershipExpiry, status) {
            // Hide search results
            document.getElementById('memberResults').style.display = 'none';
            
            // Update search input
            document.getElementById('memberSearch').value = `${firstName} ${lastName} (${memberCode})`;
            
            // Show selected member info
            const memberInfo = document.getElementById('memberInfo');
            const expiryDate = new Date(membershipExpiry);
            const today = new Date();
            const isExpired = expiryDate < today;
            
            memberInfo.innerHTML = `
                <div class="member-info-item">
                    <div class="member-info-label">Full Name</div>
                    <div class="member-info-value">${firstName} ${lastName}</div>
                </div>
                <div class="member-info-item">
                    <div class="member-info-label">Member Code</div>
                    <div class="member-info-value">${memberCode}</div>
                </div>
                <div class="member-info-item">
                    <div class="member-info-label">Email</div>
                    <div class="member-info-value">${email}</div>
                </div>
                <div class="member-info-item">
                    <div class="member-info-label">Membership Type</div>
                    <div class="member-info-value">${membershipType}</div>
                </div>
                <div class="member-info-item">
                    <div class="member-info-label">Expiry Date</div>
                    <div class="member-info-value" style="color: ${isExpired ? 'var(--danger-color)' : 'var(--success-color)'}">
                        ${new Date(membershipExpiry).toLocaleDateString()}
                        ${isExpired ? '(Expired)' : ''}
                    </div>
                </div>
                <div class="member-info-item">
                    <div class="member-info-label">Status</div>
                    <div class="member-info-value">
                        <span class="member-status ${status}">${status}</span>
                    </div>
                </div>
            `;
            
            // Set hidden member ID
            document.getElementById('selectedMemberId').value = id;
            
            // Show member info and payment form
            document.getElementById('selectedMember').style.display = 'block';
            document.getElementById('paymentForm').style.display = 'block';
        }

        // Toggle renewal options
        function toggleRenewalOptions() {
            const paymentType = document.getElementById('paymentType').value;
            const renewalGroup = document.getElementById('renewalTypeGroup');
            const renewalShortcuts = document.getElementById('renewalShortcuts');
            
            if (paymentType === 'renewal') {
                renewalGroup.style.display = 'block';
                renewalShortcuts.style.display = 'block';
                document.getElementById('renewalType').required = true;
            } else {
                renewalGroup.style.display = 'none';
                renewalShortcuts.style.display = 'none';
                document.getElementById('renewalType').required = false;
                document.getElementById('renewalType').value = '';
            }
        }

        // Set renewal type and amount
        function setRenewal(type, amount) {
            document.getElementById('renewalType').value = type;
            document.getElementById('paymentAmount').value = amount;
        }

        // Reset payment form
        function resetPaymentForm() {
            document.getElementById('processPaymentForm').reset();
            document.getElementById('selectedMember').style.display = 'none';
            document.getElementById('paymentForm').style.display = 'none';
            document.getElementById('memberSearch').value = '';
            toggleRenewalOptions();
        }

        // Filter payments
        function filterPayments(type) {
            const url = new URL(window.location);
            if (type) {
                url.searchParams.set('filter_type', type);
            } else {
                url.searchParams.delete('filter_type');
            }
            window.location.href = url.toString();
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

        // Export payment to PDF (Print popup)
        function exportPaymentPDF(paymentId) {
            // Open payment receipt in popup window for printing
            const printWindow = window.open(
                '../../api/print_payment_receipt.php?payment_id=' + paymentId, 
                'PaymentReceipt', 
                'width=800,height=900,scrollbars=yes,resizable=yes'
            );
            
            // Focus on the popup window
            if (printWindow) {
                printWindow.focus();
            }
        }

        // Initialize page
        console.log('Membership page initialized successfully');
    </script>
</body>
</html>