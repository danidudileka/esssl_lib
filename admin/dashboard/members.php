<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../includes/auth_check.php';

// Handle member actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $database = new Database();
    $db = $database->getConnection();
    
    try {
        if ($_POST['action'] === 'add_member' && hasPermission('manage_members')) {
            $first_name = trim($_POST['first_name']);
            $last_name = trim($_POST['last_name']);
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            $address = trim($_POST['address']);
            $membership_type = $_POST['membership_type'];
            $membership_duration = $_POST['membership_duration'];
            
            // Generate unique member code
            $stmt = $db->query("SELECT COUNT(*) as count FROM members");
            $count = $stmt->fetch()['count'];
            $member_code = 'MEM' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
            
            // Check for duplicate email
            $check_stmt = $db->prepare("SELECT member_id FROM members WHERE email = ?");
            $check_stmt->execute([$email]);
            if ($check_stmt->fetch()) {
                throw new Exception("Email already exists in the system");
            }
            
            // Generate random password
            $password = generateRandomPassword();
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Calculate membership expiry
            $expiry_date = calculateExpiryDate($membership_duration);
            
            // Insert member
            $stmt = $db->prepare("
                INSERT INTO members (member_code, first_name, last_name, email, phone, address, 
                                   membership_type, membership_expiry, password_hash, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([
                $member_code, $first_name, $last_name, $email, $phone, 
                $address, $membership_type, $expiry_date, $password_hash
            ]);
            
            $member_id = $db->lastInsertId();
            
            // Send welcome notification
            $welcome_message = "Welcome to ESSSL Library!\n\n";
            $welcome_message .= "Your account has been created successfully.\n\n";
            $welcome_message .= "Login Details:\n";
            $welcome_message .= "Email: {$email}\n";
            $welcome_message .= "Password: {$password}\n";
            $welcome_message .= "Member Code: {$member_code}\n\n";
            $welcome_message .= "Please change your password after first login.\n";
            $welcome_message .= "Your membership expires on: " . date('M j, Y', strtotime($expiry_date));
            
            $notif_stmt = $db->prepare("
                INSERT INTO member_notifications (member_id, title, message, type, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $notif_stmt->execute([
                $member_id,
                'Welcome to ESSSL Library',
                $welcome_message,
                'success'
            ]);
            
            $success = "Member added successfully! Login details sent to member.";
            $login_details = "Email: {$email}\nPassword: {$password}\nMember Code: {$member_code}";
            
        } elseif ($_POST['action'] === 'update_member' && hasPermission('manage_members')) {
            $member_id = (int)$_POST['member_id'];
            $first_name = trim($_POST['first_name']);
            $last_name = trim($_POST['last_name']);
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            $address = trim($_POST['address']);
            $membership_type = $_POST['membership_type'];
            $status = $_POST['status'];
            
            // Check for duplicate email (excluding current member)
            $check_stmt = $db->prepare("SELECT member_id FROM members WHERE email = ? AND member_id != ?");
            $check_stmt->execute([$email, $member_id]);
            if ($check_stmt->fetch()) {
                throw new Exception("Email already exists in the system");
            }
            
            $stmt = $db->prepare("
                UPDATE members SET first_name = ?, last_name = ?, email = ?, phone = ?, 
                                 address = ?, membership_type = ?, status = ?, updated_at = NOW()
                WHERE member_id = ?
            ");
            $stmt->execute([
                $first_name, $last_name, $email, $phone, 
                $address, $membership_type, $status, $member_id
            ]);
            
            $success = "Member updated successfully!";
            
        } elseif ($_POST['action'] === 'extend_membership' && hasPermission('manage_members')) {
            $member_id = (int)$_POST['member_id'];
            $extension_type = $_POST['extension_type'];
            
            // Get current expiry date
            $stmt = $db->prepare("SELECT membership_expiry, first_name, last_name FROM members WHERE member_id = ?");
            $stmt->execute([$member_id]);
            $member = $stmt->fetch();
            
            if ($member) {
                $current_expiry = new DateTime($member['membership_expiry']);
                $new_expiry = clone $current_expiry;
                
                switch ($extension_type) {
                    case 'one_week':
                        $new_expiry->add(new DateInterval('P7D'));
                        break;
                    case 'one_month':
                        $new_expiry->add(new DateInterval('P1M'));
                        break;
                    case 'one_year':
                        $new_expiry->add(new DateInterval('P1Y'));
                        break;
                }
                
                $update_stmt = $db->prepare("UPDATE members SET membership_expiry = ?, status = 'active' WHERE member_id = ?");
                $update_stmt->execute([$new_expiry->format('Y-m-d'), $member_id]);
                
                // Send notification
                $message = "Your membership has been extended!\n\n";
                $message .= "New expiry date: " . $new_expiry->format('M j, Y') . "\n";
                $message .= "Extension: " . str_replace('_', ' ', $extension_type);
                
                $notif_stmt = $db->prepare("
                    INSERT INTO member_notifications (member_id, title, message, type, created_at) 
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $notif_stmt->execute([
                    $member_id,
                    'Membership Extended',
                    $message,
                    'success'
                ]);
                
                $success = "Membership extended successfully!";
            }
            
        } elseif ($_POST['action'] === 'reset_password' && hasPermission('manage_members')) {
            $member_id = (int)$_POST['member_id'];
            $new_password = generateRandomPassword();
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("UPDATE members SET password_hash = ? WHERE member_id = ?");
            $stmt->execute([$password_hash, $member_id]);
            
            // Get member details
            $member_stmt = $db->prepare("SELECT first_name, last_name, email FROM members WHERE member_id = ?");
            $member_stmt->execute([$member_id]);
            $member = $member_stmt->fetch();
            
            // Send notification
            $message = "Your password has been reset by library administrator.\n\n";
            $message .= "New Password: {$new_password}\n\n";
            $message .= "Please login and change your password immediately.";
            
            $notif_stmt = $db->prepare("
                INSERT INTO member_notifications (member_id, title, message, type, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $notif_stmt->execute([
                $member_id,
                'Password Reset',
                $message,
                'warning'
            ]);
            
            $success = "Password reset successfully! New password sent to member.";
            $new_password_info = "New Password: {$new_password}";
            
        } elseif ($_POST['action'] === 'delete_member' && hasPermission('manage_members')) {
            $member_id = (int)$_POST['member_id'];
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
                    throw new Exception("Super admin credentials required for deletion.");
                }
                
                $stmt = $db->prepare("SELECT admin_id, password_hash FROM admin WHERE username = ? AND role = 'super_admin' AND status = 'active'");
                $stmt->execute([$sa_username]);
                $super_admin = $stmt->fetch();
                
                if (!$super_admin || !password_verify($sa_password, $super_admin['password_hash'])) {
                    throw new Exception("Invalid super admin credentials.");
                }
            }
            
            // Check for active loans
            $check_stmt = $db->prepare("SELECT COUNT(*) FROM book_loans WHERE member_id = ? AND status IN ('active', 'overdue')");
            $check_stmt->execute([$member_id]);
            $active_loans = $check_stmt->fetchColumn();
            
            if ($active_loans > 0) {
                throw new Exception("Cannot delete member with active loans. Please return all books first.");
            }
            
            // Soft delete - mark as inactive
            $stmt = $db->prepare("UPDATE members SET status = 'suspended', updated_at = NOW() WHERE member_id = ?");
            $stmt->execute([$member_id]);
            
            $success = "Member deleted successfully!";
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Helper functions
function generateRandomPassword($length = 8) {
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $password;
}

function calculateExpiryDate($duration) {
    $date = new DateTime();
    switch ($duration) {
        case 'one_week':
            $date->add(new DateInterval('P7D'));
            break;
        case 'one_month':
            $date->add(new DateInterval('P1M'));
            break;
        case 'three_months':
            $date->add(new DateInterval('P3M'));
            break;
        case 'six_months':
            $date->add(new DateInterval('P6M'));
            break;
        case 'one_year':
            $date->add(new DateInterval('P1Y'));
            break;
        default:
            $date->add(new DateInterval('P1Y'));
    }
    return $date->format('Y-m-d');
}

// Get members data
try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get search and filter parameters
    $search = $_GET['search'] ?? '';
    $filter = $_GET['filter'] ?? 'all';
    $membership_filter = $_GET['membership'] ?? 'all';
    $status_filter = $_GET['status'] ?? 'all';
    
    $where_clauses = [];
    $params = [];
    
    if ($search) {
        $where_clauses[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR member_code LIKE ? OR phone LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
    }
    
    if ($membership_filter !== 'all') {
        $where_clauses[] = "membership_type = ?";
        $params[] = $membership_filter;
    }
    
    if ($status_filter !== 'all') {
        $where_clauses[] = "status = ?";
        $params[] = $status_filter;
    } else {
        // By default, exclude suspended (deleted) members
        $where_clauses[] = "status != 'suspended'";
    }
    
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    
    switch($filter) {
        case 'recent':
            $order_by = 'registration_date DESC';
            break;
        case 'oldest':
            $order_by = 'registration_date ASC';
            break;
        case 'az_asc':
            $order_by = 'first_name ASC, last_name ASC';
            break;
        case 'az_desc':
            $order_by = 'first_name DESC, last_name DESC';
            break;
        case 'expiring':
            $order_by = 'membership_expiry ASC';
            break;
        default:
            $order_by = 'registration_date DESC';
    }
    
    $sql = "
        SELECT m.*, 
               (SELECT COUNT(*) FROM book_loans bl WHERE bl.member_id = m.member_id AND bl.status IN ('active', 'overdue')) as active_loans,
               (SELECT COUNT(*) FROM book_loans bl WHERE bl.member_id = m.member_id) as total_loans,
               CASE 
                   WHEN m.membership_expiry < CURDATE() THEN 'expired'
                   WHEN m.membership_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'expiring_soon'
                   ELSE 'valid'
               END as membership_status
        FROM members m
        $where_sql
        ORDER BY $order_by
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $members = $stmt->fetchAll();
    
    // Get statistics
    $stats_stmt = $db->query("
        SELECT 
            COUNT(*) as total_members,
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active_members,
            COUNT(CASE WHEN status = 'suspended' THEN 1 END) as suspended_members,
            COUNT(CASE WHEN membership_expiry < CURDATE() THEN 1 END) as expired_memberships,
            COUNT(CASE WHEN membership_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND membership_expiry >= CURDATE() THEN 1 END) as expiring_soon
        FROM members
    ");
    $statistics = $stats_stmt->fetch();
    
} catch (Exception $e) {
    $members = [];
    $statistics = [
        'total_members' => 0,
        'active_members' => 0,
        'suspended_members' => 0,
        'expired_memberships' => 0,
        'expiring_soon' => 0
    ];
    $error = "Error loading members: " . $e->getMessage();
}

// Check if current user is super admin
$is_current_super_admin = ($_SESSION['admin_role'] ?? '') === 'super_admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Members Management - Admin Panel</title>
    
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

        .add-member-btn {
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

        .add-member-btn:hover {
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        .stat-card.suspended {
            border-left-color: var(--danger-color);
        }

        .stat-card.expired {
            border-left-color: var(--warning-color);
        }

        .stat-card.expiring {
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

        /* Members Grid */
        .members-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 1.5rem;
            align-items: start;
        }

        .members-grid.list-view {
            grid-template-columns: 1fr;
        }

        .member-card {
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

        .member-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .member-header {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .member-avatar {
            flex-shrink: 0;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .member-info {
            flex: 1;
            min-width: 0;
        }

        .member-info h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.25rem;
            line-height: 1.3;
        }

        .member-info .email {
            color: var(--gray-color);
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }

        .member-info .member-code {
            background: #f3f4f6;
            color: var(--primary-color);
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .member-status {
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

        .status-badge.suspended {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }

        .status-badge.expired {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-color);
        }

        .membership-status {
            font-size: 0.75rem;
            color: var(--gray-color);
        }

        .membership-status.expired {
            color: var(--danger-color);
            font-weight: 600;
        }

        .membership-status.expiring_soon {
            color: var(--warning-color);
            font-weight: 600;
        }

        .member-details {
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

        .loan-stats {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 10px;
            margin: 1rem 0;
        }

        .loan-stats h6 {
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

        .btn-renew {
            background: var(--success-color);
            color: white;
        }

        .btn-renew:hover {
            background: #059669;
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

        .btn-delete {
            background: var(--danger-color);
            color: white;
        }

        .btn-delete:hover {
            background: #dc2626;
            color: white;
            transform: translateY(-2px);
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

        /* Modal Styles */
        .modal-dialog {
            max-width: 600px;
        }

        .modal-dialog.modal-lg {
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

        .extension-shortcuts {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .extension-btn {
            padding: 0.5rem 1rem;
            border: 1px solid #e5e7eb;
            background: #f8fafc;
            border-radius: 6px;
            color: var(--dark-color);
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .extension-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .login-details {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            font-family: 'Courier New', monospace;
            white-space: pre-line;
        }

        .copy-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.875rem;
            cursor: pointer;
            margin-left: 1rem;
        }

        .copy-btn:hover {
            background: var(--primary-dark);
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

        .alert-info {
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary-color);
        }

        .alert-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
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

        .confirmation-icon.success {
            color: var(--success-color);
        }

        .confirmation-icon.danger {
            color: var(--danger-color);
        }

        .confirmation-icon.warning {
            color: var(--warning-color);
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

        .btn-confirm.danger {
            background: var(--danger-color);
        }

        .btn-cancel {
            background: #f3f4f6;
            color: var(--gray-color);
        }

        .btn-cancel:hover {
            background: #e5e7eb;
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

            .members-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .member-card {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .extension-shortcuts {
                justify-content: center;
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
                    <a href="javascript:void(0)" onclick="navigateTo('members')" class="nav-link active">
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
                        <h1>Members Management</h1>
                        <p>Manage library members and their accounts</p>
                    </div>

                    <div class="header-actions">
                        <div class="header-search">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchInput" placeholder="Search members..." 
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
                                        <label>A-Z Name</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="sort" value="az_desc" <?php echo $filter === 'az_desc' ? 'checked' : ''; ?>>
                                        <label>Z-A Name</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="sort" value="expiring" <?php echo $filter === 'expiring' ? 'checked' : ''; ?>>
                                        <label>Expiring Soon</label>
                                    </div>
                                </div>
                                <div class="filter-section">
                                    <h6>Membership Type</h6>
                                    <div class="filter-option">
                                        <input type="radio" name="membership" value="all" <?php echo $membership_filter === 'all' ? 'checked' : ''; ?>>
                                        <label>All Types</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="membership" value="student" <?php echo $membership_filter === 'student' ? 'checked' : ''; ?>>
                                        <label>Student</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="membership" value="faculty" <?php echo $membership_filter === 'faculty' ? 'checked' : ''; ?>>
                                        <label>Faculty</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="membership" value="staff" <?php echo $membership_filter === 'staff' ? 'checked' : ''; ?>>
                                        <label>Staff</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="membership" value="public" <?php echo $membership_filter === 'public' ? 'checked' : ''; ?>>
                                        <label>Public</label>
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
                                        <input type="radio" name="status" value="suspended" <?php echo $status_filter === 'suspended' ? 'checked' : ''; ?>>
                                        <label>Suspended</label>
                                    </div>
                                    <div class="filter-option">
                                        <input type="radio" name="status" value="expired" <?php echo $status_filter === 'expired' ? 'checked' : ''; ?>>
                                        <label>Expired</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button class="add-member-btn" onclick="showAddMemberModal()">
                            <i class="fas fa-plus"></i>Add Member
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
                        <?php if (isset($login_details)): ?>
                            <div class="login-details mt-2">
                                <?php echo htmlspecialchars($login_details); ?>
                                <button class="copy-btn" onclick="copyLoginDetails('<?php echo htmlspecialchars($login_details); ?>')">
                                    <i class="fas fa-copy"></i> Copy
                                </button>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($new_password_info)): ?>
                            <div class="login-details mt-2">
                                <?php echo htmlspecialchars($new_password_info); ?>
                                <button class="copy-btn" onclick="copyLoginDetails('<?php echo htmlspecialchars($new_password_info); ?>')">
                                    <i class="fas fa-copy"></i> Copy
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
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
                            <div class="stat-value"><?php echo number_format($statistics['total_members']); ?></div>
                            <div class="stat-label">Total Members</div>
                            <i class="fas fa-users stat-icon"></i>
                        </div>
                        <div class="stat-card active">
                            <div class="stat-value"><?php echo number_format($statistics['active_members']); ?></div>
                            <div class="stat-label">Active Members</div>
                            <i class="fas fa-user-check stat-icon"></i>
                        </div>
                        <div class="stat-card suspended">
                            <div class="stat-value"><?php echo number_format($statistics['suspended_members']); ?></div>
                            <div class="stat-label">Suspended</div>
                            <i class="fas fa-user-times stat-icon"></i>
                        </div>
                        <div class="stat-card expired">
                            <div class="stat-value"><?php echo number_format($statistics['expired_memberships']); ?></div>
                            <div class="stat-label">Expired</div>
                            <i class="fas fa-user-clock stat-icon"></i>
                        </div>
                        <div class="stat-card expiring">
                            <div class="stat-value"><?php echo number_format($statistics['expiring_soon']); ?></div>
                            <div class="stat-label">Expiring Soon</div>
                            <i class="fas fa-exclamation-triangle stat-icon"></i>
                        </div>
                    </div>
                </div>

                <!-- Members Grid -->
                <div class="members-grid" id="membersGrid">
                    <?php if (empty($members)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-users" style="font-size: 4rem; color: var(--gray-color); opacity: 0.5;"></i>
                            <h3 class="mt-3 text-muted">No members found</h3>
                            <p class="text-muted">Try adjusting your search or filter criteria</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($members as $member): ?>
                            <div class="member-card">
                                <div class="member-header">
                                    <div class="member-avatar">
                                        <?php echo strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1)); ?>
                                    </div>
                                    <div class="member-info">
                                        <h3><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></h3>
                                        <div class="email"><?php echo htmlspecialchars($member['email']); ?></div>
                                        <div class="member-code"><?php echo htmlspecialchars($member['member_code']); ?></div>
                                    </div>
                                    <div class="member-status">
                                        <div class="status-badge <?php echo $member['status']; ?>">
                                            <?php echo ucfirst($member['status']); ?>
                                        </div>
                                        <div class="membership-status <?php echo $member['membership_status']; ?>">
                                            <?php 
                                            switch($member['membership_status']) {
                                                case 'expired':
                                                    echo 'Expired';
                                                    break;
                                                case 'expiring_soon':
                                                    echo 'Expiring Soon';
                                                    break;
                                                default:
                                                    echo 'Valid';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="member-details">
                                    <div class="detail-row">
                                        <span class="detail-label">Member ID:</span>
                                        <span class="detail-value"><?php echo $member['member_id']; ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Membership Type:</span>
                                        <span class="detail-value"><?php echo ucfirst($member['membership_type']); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Registration:</span>
                                        <span class="detail-value"><?php echo date('M j, Y', strtotime($member['registration_date'])); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Expiry Date:</span>
                                        <span class="detail-value"><?php echo date('M j, Y', strtotime($member['membership_expiry'])); ?></span>
                                    </div>
                                    <?php if ($member['phone']): ?>
                                        <div class="detail-row">
                                            <span class="detail-label">Phone:</span>
                                            <span class="detail-value"><?php echo htmlspecialchars($member['phone']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="loan-stats">
                                    <h6>Loan Statistics</h6>
                                    <div class="detail-row">
                                        <span class="detail-label">Active Loans:</span>
                                        <span class="detail-value"><?php echo $member['active_loans']; ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Total Loans:</span>
                                        <span class="detail-value"><?php echo $member['total_loans']; ?></span>
                                    </div>
                                </div>

                                <div class="action-buttons">
                                    <button class="btn-action btn-view" onclick="showMemberDetails(<?php echo $member['member_id']; ?>)" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    
                                    <div class="dropdown" style="flex: 1;">
                                        <button class="btn-action btn-renew dropdown-toggle" data-bs-toggle="dropdown" title="Extend Membership">
                                            <i class="fas fa-calendar-plus"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="extendMembership(<?php echo $member['member_id']; ?>, 'one_week')">1 Week</a></li>
                                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="extendMembership(<?php echo $member['member_id']; ?>, 'one_month')">1 Month</a></li>
                                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="extendMembership(<?php echo $member['member_id']; ?>, 'one_year')">1 Year</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="showExtendModal(<?php echo $member['member_id']; ?>)">Custom Period</a></li>
                                        </ul>
                                    </div>
                                    
                                    <?php if (hasPermission('manage_members')): ?>
                                        <button class="btn-action btn-edit" onclick="showEditMemberModal(<?php echo $member['member_id']; ?>)" title="Edit Member">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <div class="dropdown" style="flex: 1;">
                                            <button class="btn-action btn-delete dropdown-toggle" data-bs-toggle="dropdown" title="More Actions">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li><a class="dropdown-item" href="javascript:void(0)" onclick="resetPassword(<?php echo $member['member_id']; ?>)">Reset Password</a></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteMember(<?php echo $member['member_id']; ?>, '<?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>')">Delete Member</a></li>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Member Modal -->
    <div class="modal fade" id="addMemberModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus me-2"></i>Add New Member
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addMemberForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_member">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">First Name *</label>
                                    <input type="text" name="first_name" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Last Name *</label>
                                    <input type="text" name="last_name" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Email Address *</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="3"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Membership Type *</label>
                                    <select name="membership_type" class="form-control" required>
                                        <option value="student">Student</option>
                                        <option value="faculty">Faculty</option>
                                        <option value="staff">Staff</option>
                                        <option value="public">Public</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Membership Duration *</label>
                                    <select name="membership_duration" class="form-control" required>
                                        <option value="one_week">1 Week</option>
                                        <option value="one_month">1 Month</option>
                                        <option value="three_months">3 Months</option>
                                        <option value="six_months">6 Months</option>
                                        <option value="one_year" selected>1 Year</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            A random password will be generated and sent to the member via notification.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Member</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Member Modal -->
    <div class="modal fade" id="editMemberModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-edit me-2"></i>Edit Member
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editMemberForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_member">
                        <input type="hidden" name="member_id" id="editMemberId">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">First Name *</label>
                                    <input type="text" name="first_name" id="editFirstName" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Last Name *</label>
                                    <input type="text" name="last_name" id="editLastName" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Email Address *</label>
                            <input type="email" name="email" id="editEmail" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" id="editPhone" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Address</label>
                            <textarea name="address" id="editAddress" class="form-control" rows="3"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Membership Type *</label>
                                    <select name="membership_type" id="editMembershipType" class="form-control" required>
                                        <option value="student">Student</option>
                                        <option value="faculty">Faculty</option>
                                        <option value="staff">Staff</option>
                                        <option value="public">Public</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Status *</label>
                                    <select name="status" id="editStatus" class="form-control" required>
                                        <option value="active">Active</option>
                                        <option value="suspended">Suspended</option>
                                        <option value="expired">Expired</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Member</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Member Details Modal -->
    <div class="modal fade" id="memberDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user me-2"></i>Member Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="memberDetailsContent">
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

    <!-- Extend Membership Modal -->
    <div class="modal fade" id="extendMembershipModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-plus me-2"></i>Extend Membership
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="extendForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="extend_membership">
                        <input type="hidden" name="member_id" id="extendMemberId">
                        <input type="hidden" name="extension_type" id="extendType">
                        
                        <div class="form-group">
                            <label class="form-label">Select Extension Period</label>
                            <select name="extension_type" class="form-control" required onchange="updateExtendType(this.value)">
                                <option value="one_week">1 Week</option>
                                <option value="one_month" selected>1 Month</option>
                                <option value="one_year">1 Year</option>
                            </select>
                        </div>
                        
                        <div class="extension-shortcuts">
                            <button type="button" class="extension-btn" onclick="setExtension('one_week')">1 Week</button>
                            <button type="button" class="extension-btn" onclick="setExtension('one_month')">1 Month</button>
                            <button type="button" class="extension-btn" onclick="setExtension('one_year')">1 Year</button>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle me-2"></i>
                            The new expiry date will be calculated from the current expiry date.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Extend Membership</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Member Modal -->
    <div class="modal fade" id="deleteMemberModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>Delete Member
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="deleteMemberForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete_member">
                        <input type="hidden" name="member_id" id="deleteMemberId">
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> This action will suspend the member's account and cannot be easily undone.
                        </div>
                        
                        <p id="deleteMemberText">Are you sure you want to delete this member?</p>
                        
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
                            <i class="fas fa-user-times me-1"></i>Delete Member
                        </button>
                    </div>
                </form>
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
            <div class="confirmation-buttons">
                <button type="button" class="btn-cancel" onclick="hideConfirmation()">Cancel</button>
                <button type="button" class="btn-confirm" id="confirmButton" onclick="executeAction()">Confirm</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        let currentAction = null;
        let currentMemberId = null;
        let currentData = null;
        
        // Store super admin access temporarily
        let tempSuperAdminAccess = false;
        let tempAccessExpiry = 0;
        const TEMP_ACCESS_DURATION = 5 * 60 * 1000; // 5 minutes
        
        // Navigation function
        function navigateTo(page) {
            if (page === 'members') return;
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
                    url.searchParams.set('filter', this.value);
                    window.location.href = url.toString();
                });
            });

            document.querySelectorAll('input[name="membership"]').forEach(input => {
                input.addEventListener('change', function() {
                    const url = new URL(window.location);
                    if (this.value === 'all') {
                        url.searchParams.delete('membership');
                    } else {
                        url.searchParams.set('membership', this.value);
                    }
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
                    const grid = document.getElementById('membersGrid');
                    
                    if (view === 'list') {
                        grid.classList.add('list-view');
                    } else {
                        grid.classList.remove('list-view');
                    }
                });
            });
        });

        // Show add member modal
        function showAddMemberModal() {
            const modal = new bootstrap.Modal(document.getElementById('addMemberModal'));
            modal.show();
        }

        // Show edit member modal
        function showEditMemberModal(memberId) {
            try {
                const members = <?php echo json_encode($members); ?>;
                const member = members.find(m => m.member_id == memberId);
                
                if (member) {
                    document.getElementById('editMemberId').value = member.member_id;
                    document.getElementById('editFirstName').value = member.first_name;
                    document.getElementById('editLastName').value = member.last_name;
                    document.getElementById('editEmail').value = member.email;
                    document.getElementById('editPhone').value = member.phone || '';
                    document.getElementById('editAddress').value = member.address || '';
                    document.getElementById('editMembershipType').value = member.membership_type;
                    document.getElementById('editStatus').value = member.status;
                    
                    const modal = new bootstrap.Modal(document.getElementById('editMemberModal'));
                    modal.show();
                } else {
                    alert('Member not found!');
                }
            } catch (error) {
                console.error('Error showing edit modal:', error);
                alert('Error loading edit form. Please try again.');
            }
        }

        // Show member details modal
        function showMemberDetails(memberId) {
            try {
                const modal = new bootstrap.Modal(document.getElementById('memberDetailsModal'));
                const content = document.getElementById('memberDetailsContent');
                
                content.innerHTML = '<div class="text-center py-4"><div class="spinner-border" role="status"></div><p class="mt-2">Loading...</p></div>';
                modal.show();
                
                const members = <?php echo json_encode($members); ?>;
                const member = members.find(m => m.member_id == memberId);
                
                setTimeout(() => {
                    if (member) {
                        content.innerHTML = generateMemberDetailsHTML(member);
                    } else {
                        content.innerHTML = '<div class="text-center py-4"><p class="text-danger">Member not found!</p></div>';
                    }
                }, 500);
            } catch (error) {
                console.error('Error showing member details:', error);
                alert('Error loading member details. Please try again.');
            }
        }

        // Generate member details HTML
        function generateMemberDetailsHTML(member) {
            try {
                const membershipStatus = member.membership_status === 'expired' ? 'Expired' : 
                                       member.membership_status === 'expiring_soon' ? 'Expiring Soon' : 'Valid';
                
                return `
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6><i class="fas fa-user me-2"></i>Personal Information</h6>
                                </div>
                                <div class="card-body">
                                    <p><strong>Name:</strong> ${member.first_name} ${member.last_name}</p>
                                    <p><strong>Member Code:</strong> ${member.member_code}</p>
                                    <p><strong>Email:</strong> ${member.email}</p>
                                    <p><strong>Phone:</strong> ${member.phone || 'N/A'}</p>
                                    <p><strong>Address:</strong> ${member.address || 'N/A'}</p>
                                    <p><strong>Status:</strong> <span class="badge bg-${member.status === 'active' ? 'success' : 'danger'}">${member.status.charAt(0).toUpperCase() + member.status.slice(1)}</span></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header">
                                    <h6><i class="fas fa-id-card me-2"></i>Membership Information</h6>
                                </div>
                                <div class="card-body">
                                    <p><strong>Membership Type:</strong> ${member.membership_type.charAt(0).toUpperCase() + member.membership_type.slice(1)}</p>
                                    <p><strong>Registration Date:</strong> ${new Date(member.registration_date).toLocaleDateString()}</p>
                                    <p><strong>Expiry Date:</strong> ${new Date(member.membership_expiry).toLocaleDateString()}</p>
                                    <p><strong>Membership Status:</strong> <span class="badge bg-${member.membership_status === 'valid' ? 'success' : member.membership_status === 'expiring_soon' ? 'warning' : 'danger'}">${membershipStatus}</span></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h6><i class="fas fa-chart-bar me-2"></i>Loan Statistics</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <p><strong>Active Loans:</strong><br>${member.active_loans}</p>
                                        </div>
                                        <div class="col-md-4">
                                            <p><strong>Total Loans:</strong><br>${member.total_loans}</p>
                                        </div>
                                        <div class="col-md-4">
                                            <p><strong>Member Since:</strong><br>${Math.floor((new Date() - new Date(member.registration_date)) / (1000 * 60 * 60 * 24))} days</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } catch (error) {
                console.error('Error generating member details HTML:', error);
                return '<div class="text-center py-4"><p class="text-danger">Error loading member details!</p></div>';
            }
        }

        // Extend membership functions
        function extendMembership(memberId, extensionType) {
            currentAction = 'extend_membership';
            currentMemberId = memberId;
            currentData = { extension_type: extensionType };
            
            const extensionText = extensionType.replace('_', ' ');
            document.getElementById('confirmationIcon').innerHTML = '<i class="fas fa-calendar-plus"></i>';
            document.getElementById('confirmationIcon').className = 'confirmation-icon success';
            document.getElementById('confirmationTitle').textContent = 'Extend Membership';
            document.getElementById('confirmationMessage').textContent = `Are you sure you want to extend this member's subscription by ${extensionText}?`;
            document.getElementById('confirmButton').textContent = 'Yes, Extend';
            document.getElementById('confirmButton').className = 'btn-confirm';
            
            document.getElementById('confirmationModal').style.display = 'flex';
        }

        function showExtendModal(memberId) {
            document.getElementById('extendMemberId').value = memberId;
            const modal = new bootstrap.Modal(document.getElementById('extendMembershipModal'));
            modal.show();
        }

        function setExtension(type) {
            document.querySelector('select[name="extension_type"]').value = type;
            updateExtendType(type);
        }

        function updateExtendType(type) {
            document.getElementById('extendType').value = type;
        }

        // Reset password
        function resetPassword(memberId) {
            currentAction = 'reset_password';
            currentMemberId = memberId;
            currentData = null;
            
            document.getElementById('confirmationIcon').innerHTML = '<i class="fas fa-key"></i>';
            document.getElementById('confirmationIcon').className = 'confirmation-icon warning';
            document.getElementById('confirmationTitle').textContent = 'Reset Password';
            document.getElementById('confirmationMessage').textContent = 'Are you sure you want to reset this member\'s password? A new password will be generated and sent to them.';
            document.getElementById('confirmButton').textContent = 'Yes, Reset';
            document.getElementById('confirmButton').className = 'btn-confirm';
            
            document.getElementById('confirmationModal').style.display = 'flex';
        }

        // Delete member - Updated with super admin verification
        function deleteMember(memberId, memberName) {
            try {
                document.getElementById('deleteMemberId').value = memberId;
                document.getElementById('deleteMemberText').innerHTML = `Are you sure you want to delete "<strong>${memberName}</strong>"? This action will suspend their account.`;
                
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
                
                const modal = new bootstrap.Modal(document.getElementById('deleteMemberModal'));
                modal.show();
            } catch (error) {
                console.error('Error showing delete modal:', error);
                alert('Error loading delete form. Please try again.');
            }
        }

        // Hide confirmation modal
        function hideConfirmation() {
            document.getElementById('confirmationModal').style.display = 'none';
            currentAction = null;
            currentMemberId = null;
            currentData = null;
        }

        // Execute the confirmed action
        function executeAction() {
            try {
                if (!currentAction || !currentMemberId) return;
                
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.name = 'action';
                actionInput.value = currentAction;
                form.appendChild(actionInput);
                
                const memberIdInput = document.createElement('input');
                memberIdInput.name = 'member_id';
                memberIdInput.value = currentMemberId;
                form.appendChild(memberIdInput);
                
                if (currentData) {
                    Object.keys(currentData).forEach(key => {
                        const input = document.createElement('input');
                        input.name = key;
                        input.value = currentData[key];
                        form.appendChild(input);
                    });
                }
                
                document.body.appendChild(form);
                form.submit();
            } catch (error) {
                console.error('Error executing action:', error);
                alert('Error processing request. Please try again.');
            }
        }

        // Copy login details to clipboard
        function copyLoginDetails(text) {
            navigator.clipboard.writeText(text).then(function() {
                // Show temporary success message
                const button = event.target;
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-check"></i> Copied!';
                button.style.background = '#10b981';
                
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.style.background = '';
                }, 2000);
            }).catch(function(err) {
                console.error('Could not copy text: ', err);
                alert('Failed to copy to clipboard');
            });
        }

        // Print modal content
        function printModalContent() {
            try {
                const content = document.getElementById('memberDetailsContent').innerHTML;
                const printWindow = window.open('', '_blank', 'width=800,height=600');
                printWindow.document.write(`
                    <html>
                    <head>
                        <title>Member Details - Print</title>
                        <style>
                            body { font-family: Arial, sans-serif; margin: 20px; }
                            .header { text-align: center; border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 20px; }
                            .card { border: 1px solid #ddd; margin-bottom: 20px; }
                            .card-header { background: #f8f9fa; padding: 10px; font-weight: bold; }
                            .card-body { padding: 15px; }
                            .row { display: flex; flex-wrap: wrap; }
                            .col-lg-6, .col-12 { flex: 1; padding: 0 10px; }
                            .col-md-4 { flex: 0 0 33.33%; padding: 0 10px; }
                            @media print { body { margin: 0; } }
                        </style>
                    </head>
                    <body>
                        <div class="header">
                            <h1>ESSSL Library</h1>
                            <h2>Member Details</h2>
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

        // Form validation and submission handlers
        document.addEventListener('DOMContentLoaded', function() {
            const addForm = document.getElementById('addMemberForm');
            const editForm = document.getElementById('editMemberForm');
            const extendForm = document.getElementById('extendForm');
            const deleteMemberForm = document.getElementById('deleteMemberForm');
            
            if (addForm) {
                addForm.addEventListener('submit', function(e) {
                    const email = this.querySelector('input[name="email"]').value;
                    const firstName = this.querySelector('input[name="first_name"]').value;
                    const lastName = this.querySelector('input[name="last_name"]').value;
                    
                    if (!email || !firstName || !lastName) {
                        e.preventDefault();
                        alert('Please fill in all required fields!');
                        return false;
                    }
                    
                    if (!isValidEmail(email)) {
                        e.preventDefault();
                        alert('Please enter a valid email address!');
                        return false;
                    }
                });
            }
            
            if (editForm) {
                editForm.addEventListener('submit', function(e) {
                    const email = this.querySelector('input[name="email"]').value;
                    const firstName = this.querySelector('input[name="first_name"]').value;
                    const lastName = this.querySelector('input[name="last_name"]').value;
                    
                    if (!email || !firstName || !lastName) {
                        e.preventDefault();
                        alert('Please fill in all required fields!');
                        return false;
                    }
                    
                    if (!isValidEmail(email)) {
                        e.preventDefault();
                        alert('Please enter a valid email address!');
                        return false;
                    }
                });
            }

            // Delete member form submission with super admin verification
            if (deleteMemberForm) {
                deleteMemberForm.addEventListener('submit', function(e) {
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

        // Email validation function
        function isValidEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }

        // Auto-refresh notification for membership extensions
        function showNotification(message, type = 'success') {
            const notification = document.createElement('div');
            notification.className = `alert alert-${type} position-fixed`;
            notification.style.top = '20px';
            notification.style.right = '20px';
            notification.style.zIndex = '9999';
            notification.style.maxWidth = '400px';
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close ms-2" onclick="this.parentElement.remove()"></button>
            `;
            
            document.body.appendChild(notification);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 5000);
        }

        // Initialize page
        console.log('Admin Members Page initialized successfully');
        console.log('Total members loaded:', <?php echo count($members); ?>);
        
        // Auto-show success messages if any
        <?php if (isset($success)): ?>
            setTimeout(() => {
                showNotification('<?php echo addslashes($success); ?>', 'success');
            }, 500);
        <?php endif; ?>
    </script>
</body>
</html>