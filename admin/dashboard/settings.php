<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../includes/auth_check.php';

// Handle settings updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $database = new Database();
    $db = $database->getConnection();
    
    try {
        if ($_POST['action'] === 'add_new_admin' && $_SESSION['admin_role'] === 'super_admin') {
            $username = trim($_POST['username']);
            $full_name = trim($_POST['full_name']);
            $email = trim($_POST['email']);
            $role = $_POST['role'];
            $password = trim($_POST['password']);
            $confirm_password = trim($_POST['confirm_password']);
            
            // Validate required fields
            if (empty($username) || empty($full_name) || empty($email) || empty($password)) {
                throw new Exception("All fields are required");
            }
            
            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format");
            }
            
            // Validate password length
            if (strlen($password) < 6) {
                throw new Exception("Password must be at least 6 characters long");
            }
            
            // Check password confirmation
            if ($password !== $confirm_password) {
                throw new Exception("Passwords do not match");
            }
            
            // Validate role
            if (!in_array($role, ['super_admin', 'librarian', 'assistant'])) {
                throw new Exception("Invalid role selected");
            }
            
            $db->beginTransaction();
            
            // Check if username already exists
            $username_check = $db->prepare("SELECT admin_id FROM admin WHERE username = ?");
            $username_check->execute([$username]);
            if ($username_check->fetch()) {
                throw new Exception("Username already exists");
            }
            
            // Check if email already exists
            $email_check = $db->prepare("SELECT admin_id FROM admin WHERE email = ?");
            $email_check->execute([$email]);
            if ($email_check->fetch()) {
                throw new Exception("Email already exists");
            }
            
            // Hash password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert new admin
            $insert_stmt = $db->prepare("
                INSERT INTO admin (username, email, password_hash, full_name, role, status, created_at) 
                VALUES (?, ?, ?, ?, ?, 'active', NOW())
            ");
            $insert_stmt->execute([$username, $email, $password_hash, $full_name, $role]);
            
            $db->commit();
            $success = "New admin account created successfully! Username: {$username}";
            
        } elseif ($_POST['action'] === 'update_admin_profile' && hasPermission('manage_settings')) {
            $admin_id = $_SESSION['admin_id'];
            $full_name = trim($_POST['full_name']);
            $email = trim($_POST['email']);
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
            
            // Validate required fields
            if (empty($full_name) || empty($email)) {
                throw new Exception("Name and email are required");
            }
            
            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format");
            }
            
            $db->beginTransaction();
            
            // Check if email is already used by another admin
            $email_check = $db->prepare("SELECT admin_id FROM admin WHERE email = ? AND admin_id != ?");
            $email_check->execute([$email, $admin_id]);
            if ($email_check->fetch()) {
                throw new Exception("Email is already in use by another admin");
            }
            
            // Get current admin data
            $admin_stmt = $db->prepare("SELECT password_hash FROM admin WHERE admin_id = ?");
            $admin_stmt->execute([$admin_id]);
            $admin_data = $admin_stmt->fetch();
            
            // If password change is requested
            if (!empty($new_password)) {
                if (empty($current_password)) {
                    throw new Exception("Current password is required to change password");
                }
                
                if (!password_verify($current_password, $admin_data['password_hash'])) {
                    throw new Exception("Current password is incorrect");
                }
                
                if ($new_password !== $confirm_password) {
                    throw new Exception("New passwords do not match");
                }
                
                if (strlen($new_password) < 6) {
                    throw new Exception("New password must be at least 6 characters long");
                }
                
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $db->prepare("UPDATE admin SET full_name = ?, email = ?, password_hash = ?, updated_at = NOW() WHERE admin_id = ?");
                $update_stmt->execute([$full_name, $email, $password_hash, $admin_id]);
            } else {
                // Update only name and email
                $update_stmt = $db->prepare("UPDATE admin SET full_name = ?, email = ?, updated_at = NOW() WHERE admin_id = ?");
                $update_stmt->execute([$full_name, $email, $admin_id]);
            }
            
            // Update session data
            $_SESSION['admin_name'] = $full_name;
            $_SESSION['admin_email'] = $email;
            
            $db->commit();
            $success = "Admin profile updated successfully!";
            
        } elseif ($_POST['action'] === 'update_site_settings' && hasPermission('manage_settings')) {
            $site_name = trim($_POST['site_name']);
            $site_description = trim($_POST['site_description']);
            $currency = trim($_POST['currency']);
            $currency_symbol = trim($_POST['currency_symbol']);
            $items_per_page = (int)$_POST['items_per_page'];
            $session_timeout = (int)$_POST['session_timeout'];
            
            if (empty($site_name) || empty($site_description)) {
                throw new Exception("Site name and description are required");
            }
            
            $db->beginTransaction();
            
            // Update or insert site settings
            $settings = [
                'site_name' => $site_name,
                'site_description' => $site_description,
                'currency' => $currency,
                'currency_symbol' => $currency_symbol,
                'items_per_page' => $items_per_page,
                'session_timeout' => $session_timeout
            ];
            
            foreach ($settings as $key => $value) {
                $stmt = $db->prepare("
                    INSERT INTO site_settings (setting_key, setting_value, updated_at) 
                    VALUES (?, ?, NOW()) 
                    ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
                ");
                $stmt->execute([$key, $value, $value]);
            }
            
            $db->commit();
            $success = "Site settings updated successfully!";
            
        } elseif ($_POST['action'] === 'upload_logo' && hasPermission('manage_settings')) {
            if (!isset($_FILES['logo_file']) || $_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Please select a valid logo file");
            }
            
            $upload_dir = '../../assets/images/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_info = pathinfo($_FILES['logo_file']['name']);
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'svg'];
            
            if (!in_array(strtolower($file_info['extension']), $allowed_extensions)) {
                throw new Exception("Only JPG, PNG, GIF, and SVG files are allowed");
            }
            
            if ($_FILES['logo_file']['size'] > 2 * 1024 * 1024) { // 2MB limit
                throw new Exception("File size must be less than 2MB");
            }
            
            $new_filename = 'logo.' . strtolower($file_info['extension']);
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $upload_path)) {
                // Update database
                $stmt = $db->prepare("
                    INSERT INTO site_settings (setting_key, setting_value, updated_at) 
                    VALUES ('site_logo', ?, NOW()) 
                    ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
                ");
                $stmt->execute([$new_filename, $new_filename]);
                
                $success = "Logo uploaded successfully!";
            } else {
                throw new Exception("Failed to upload logo file");
            }
            
        } elseif ($_POST['action'] === 'upload_favicon' && hasPermission('manage_settings')) {
            if (!isset($_FILES['favicon_file']) || $_FILES['favicon_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Please select a valid favicon file");
            }
            
            $upload_dir = '../../assets/images/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_info = pathinfo($_FILES['favicon_file']['name']);
            $allowed_extensions = ['ico', 'png'];
            
            if (!in_array(strtolower($file_info['extension']), $allowed_extensions)) {
                throw new Exception("Only ICO and PNG files are allowed for favicon");
            }
            
            if ($_FILES['favicon_file']['size'] > 1024 * 1024) { // 1MB limit
                throw new Exception("Favicon file size must be less than 1MB");
            }
            
            $new_filename = 'favicon.' . strtolower($file_info['extension']);
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['favicon_file']['tmp_name'], $upload_path)) {
                // Update database
                $stmt = $db->prepare("
                    INSERT INTO site_settings (setting_key, setting_value, updated_at) 
                    VALUES ('site_favicon', ?, NOW()) 
                    ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
                ");
                $stmt->execute([$new_filename, $new_filename]);
                
                $success = "Favicon uploaded successfully!";
            } else {
                throw new Exception("Failed to upload favicon file");
            }
        }
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
    }
}

// Get current settings
try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get current admin info
    $admin_stmt = $db->prepare("SELECT * FROM admin WHERE admin_id = ?");
    $admin_stmt->execute([$_SESSION['admin_id']]);
    $admin_info = $admin_stmt->fetch();
    
    // Get site settings
    $settings_stmt = $db->query("SELECT setting_key, setting_value FROM site_settings");
    $site_settings = [];
    while ($row = $settings_stmt->fetch()) {
        $site_settings[$row['setting_key']] = $row['setting_value'];
    }
    
    // Set default values if settings don't exist
    $default_settings = [
        'site_name' => 'ESSSL Library Management System',
        'site_description' => 'Your Gateway to Knowledge',
        'currency' => 'LKR',
        'currency_symbol' => 'LKR',
        'items_per_page' => '20',
        'session_timeout' => '3600',
        'site_logo' => 'logo.png',
        'site_favicon' => 'favicon.ico'
    ];
    
    foreach ($default_settings as $key => $default_value) {
        if (!isset($site_settings[$key])) {
            $site_settings[$key] = $default_value;
        }
    }
    
    // Check if current user is super admin
    $is_super_admin = ($_SESSION['admin_role'] ?? '') === 'super_admin';
    
} catch (Exception $e) {
    $admin_info = [];
    $site_settings = [];
    $is_super_admin = false;
    $error = "Error loading settings: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Admin Panel</title>
    
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

        .nav-badge {
            margin-left: auto;
            background: var(--danger-color);
            color: white;
            font-size: 0.75rem;
            padding: 0.3rem 0.6rem;
            border-radius: 15px;
            font-weight: 600;
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

        /* Content Area */
        .content-area {
            padding: 2rem;
        }

        /* Settings Tabs */
        .settings-nav {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid #e5e7eb;
        }

        .settings-tab {
            padding: 1rem 1.5rem;
            background: transparent;
            border: none;
            color: var(--gray-color);
            font-weight: 500;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            position: relative;
        }

        .settings-tab:hover {
            color: var(--primary-color);
            background: rgba(37, 99, 235, 0.05);
        }

        .settings-tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
            background: rgba(37, 99, 235, 0.05);
        }

        /* Settings Sections */
        .settings-section {
            display: none;
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .settings-section.active {
            display: block;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .section-icon {
            width: 50px;
            height: 50px;
            background: var(--primary-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark-color);
            margin: 0;
        }

        .section-description {
            color: var(--gray-color);
            margin: 0;
            font-size: 0.95rem;
        }

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
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

        .form-text {
            font-size: 0.8rem;
            color: var(--gray-color);
            margin-top: 0.25rem;
        }

        /* Super Admin Section */
        .super-admin-section {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(245, 158, 11, 0.1));
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 10px;
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .super-admin-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .super-admin-icon {
            width: 40px;
            height: 40px;
            background: var(--danger-color);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }

        .add-admin-btn {
            background: var(--danger-color);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .add-admin-btn:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }

        .restricted-message {
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 1rem;
            color: var(--gray-color);
            text-align: center;
        }

        /* File Upload */
        .file-upload-area {
            border: 2px dashed #e5e7eb;
            border-radius: 10px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
        }

        .file-upload-area:hover {
            border-color: var(--primary-color);
            background: rgba(37, 99, 235, 0.05);
        }

        .file-upload-area.dragover {
            border-color: var(--primary-color);
            background: rgba(37, 99, 235, 0.1);
        }

        .upload-icon {
            font-size: 2.5rem;
            color: var(--gray-color);
            margin-bottom: 1rem;
        }

        .upload-text {
            color: var(--dark-color);
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .upload-note {
            font-size: 0.8rem;
            color: var(--gray-color);
        }

        .file-input {
            display: none;
        }

        .current-file {
            margin-top: 1rem;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }

        .current-file img {
            max-width: 100px;
            max-height: 100px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }

        /* Password Section */
        .password-section {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 1.5rem;
            margin-top: 1rem;
        }

        .password-toggle {
            position: relative;
        }

        .password-toggle-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray-color);
            cursor: pointer;
        }

        /* Currency Section */
        .currency-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .currency-option {
            display: flex;
            align-items: center;
            padding: 1rem;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .currency-option:hover {
            border-color: var(--primary-color);
            background: rgba(37, 99, 235, 0.05);
        }

        .currency-option.selected {
            border-color: var(--primary-color);
            background: rgba(37, 99, 235, 0.1);
        }

        .currency-option input[type="radio"] {
            margin-right: 0.75rem;
        }

        .currency-info {
            flex: 1;
        }

        .currency-name {
            font-weight: 600;
            color: var(--dark-color);
        }

        .currency-code {
            font-size: 0.85rem;
            color: var(--gray-color);
        }

        /* Action Buttons */
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            padding-top: 2rem;
            border-top: 1px solid #e5e7eb;
            margin-top: 2rem;
        }

        .btn {
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #f3f4f6;
            color: var(--gray-color);
        }

        .btn-secondary:hover {
            background: #e5e7eb;
            color: var(--dark-color);
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
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

        /* Preview Section */
        .preview-section {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 1.5rem;
            margin-top: 1.5rem;
        }

        .preview-header {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 1rem;
        }

        .site-preview {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .site-preview img {
            width: 40px;
            height: 40px;
            object-fit: contain;
        }

        .site-preview-info h4 {
            margin: 0;
            font-size: 1.1rem;
            color: var(--dark-color);
        }

        .site-preview-info p {
            margin: 0;
            font-size: 0.9rem;
            color: var(--gray-color);
        }

        /* Modal Styles */
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

            .settings-nav {
                flex-wrap: wrap;
            }

            .settings-tab {
                flex: 1;
                min-width: 120px;
                text-align: center;
                padding: 0.75rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .currency-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
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
                    <img src="../../assets/images/<?php echo htmlspecialchars($site_settings['site_logo']); ?>" alt="Logo">
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
                    <a href="javascript:void(0)" onclick="navigateTo('membership')" class="nav-link">
                        <i class="fas fa-id-card nav-icon"></i>
                        <span>Membership</span>
                    </a>
                </div>

                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('settings')" class="nav-link active">
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
                        <h1>Settings</h1>
                        <p>Manage system settings and configurations</p>
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

                <!-- Settings Navigation Tabs -->
                <div class="settings-nav">
                    <button class="settings-tab active" onclick="switchTab('admin-profile')">
                        <i class="fas fa-user-cog me-2"></i>Admin Profile
                    </button>
                    <button class="settings-tab" onclick="switchTab('site-settings')">
                        <i class="fas fa-globe me-2"></i>Site Settings
                    </button>
                    <button class="settings-tab" onclick="switchTab('appearance')">
                        <i class="fas fa-palette me-2"></i>Appearance
                    </button>
                    <button class="settings-tab" onclick="switchTab('system')">
                        <i class="fas fa-server me-2"></i>System
                    </button>
                </div>

                <!-- Admin Profile Settings -->
                <div id="admin-profile" class="settings-section active">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-user-cog"></i>
                        </div>
                        <div>
                            <h2 class="section-title">Admin Profile</h2>
                            <p class="section-description">Update your admin account information and password</p>
                        </div>
                    </div>

                    <form method="POST" id="adminProfileForm">
                        <input type="hidden" name="action" value="update_admin_profile">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Full Name *</label>
                                <input type="text" name="full_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($admin_info['full_name'] ?? ''); ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Email Address *</label>
                                <input type="email" name="email" class="form-control" 
                                       value="<?php echo htmlspecialchars($admin_info['email'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="password-section">
                            <h4 style="margin-bottom: 1rem; color: var(--dark-color);">
                                <i class="fas fa-lock me-2"></i>Change Password (Optional)
                            </h4>
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Current Password</label>
                                    <div class="password-toggle">
                                        <input type="password" name="current_password" id="currentPassword" class="form-control">
                                        <button type="button" class="password-toggle-btn" onclick="togglePassword('currentPassword')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">Required only if changing password</div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">New Password</label>
                                    <div class="password-toggle">
                                        <input type="password" name="new_password" id="newPassword" class="form-control">
                                        <button type="button" class="password-toggle-btn" onclick="togglePassword('newPassword')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">Leave blank to keep current password</div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Confirm New Password</label>
                                    <div class="password-toggle">
                                        <input type="password" name="confirm_password" id="confirmPassword" class="form-control">
                                        <button type="button" class="password-toggle-btn" onclick="togglePassword('confirmPassword')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="resetForm('adminProfileForm')">
                                <i class="fas fa-undo me-1"></i>Reset
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Update Profile
                            </button>
                        </div>
                    </form>

                    <!-- Super Admin Section -->
                    <?php if ($is_super_admin): ?>
                    <div class="super-admin-section">
                        <div class="super-admin-header">
                            <div class="super-admin-icon">
                                <i class="fas fa-user-shield"></i>
                            </div>
                            <div>
                                <h4 style="margin: 0; color: var(--danger-color);">Super Admin Privileges</h4>
                                <p style="margin: 0; color: var(--gray-color); font-size: 0.9rem;">Add new administrators to the system</p>
                            </div>
                        </div>
                        
                        <button type="button" class="add-admin-btn" onclick="showAddAdminModal()">
                            <i class="fas fa-user-plus"></i>Add New Admin
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="super-admin-section">
                        <div class="restricted-message">
                            <i class="fas fa-lock me-2"></i>
                            Admin management is restricted to Super Admin users only.
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Site Settings -->
                <div id="site-settings" class="settings-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-globe"></i>
                        </div>
                        <div>
                            <h2 class="section-title">Site Settings</h2>
                            <p class="section-description">Configure your library's basic information and preferences</p>
                        </div>
                    </div>

                    <form method="POST" id="siteSettingsForm">
                        <input type="hidden" name="action" value="update_site_settings">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Library Name *</label>
                                <input type="text" name="site_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($site_settings['site_name']); ?>" required>
                                <div class="form-text">This appears in the header and documents</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Library Description *</label>
                                <input type="text" name="site_description" class="form-control" 
                                       value="<?php echo htmlspecialchars($site_settings['site_description']); ?>" required>
                                <div class="form-text">Short tagline or description</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Currency & Regional Settings</label>
                            <div class="currency-grid">
                                <div class="currency-option <?php echo $site_settings['currency'] === 'LKR' ? 'selected' : ''; ?>" onclick="selectCurrency('LKR', 'LKR')">
                                    <input type="radio" name="currency" value="LKR" <?php echo $site_settings['currency'] === 'LKR' ? 'checked' : ''; ?>>
                                    <div class="currency-info">
                                        <div class="currency-name">Sri Lankan Rupee</div>
                                        <div class="currency-code">LKR - Default</div>
                                    </div>
                                </div>

                                <div class="currency-option <?php echo $site_settings['currency'] === 'USD' ? 'selected' : ''; ?>" onclick="selectCurrency('USD', '$')">
                                    <input type="radio" name="currency" value="USD" <?php echo $site_settings['currency'] === 'USD' ? 'checked' : ''; ?>>
                                    <div class="currency-info">
                                        <div class="currency-name">US Dollar</div>
                                        <div class="currency-code">USD - $</div>
                                    </div>
                                </div>

                                <div class="currency-option <?php echo $site_settings['currency'] === 'EUR' ? 'selected' : ''; ?>" onclick="selectCurrency('EUR', '€')">
                                    <input type="radio" name="currency" value="EUR" <?php echo $site_settings['currency'] === 'EUR' ? 'checked' : ''; ?>>
                                    <div class="currency-info">
                                        <div class="currency-name">Euro</div>
                                        <div class="currency-code">EUR - €</div>
                                    </div>
                                </div>

                                <div class="currency-option <?php echo $site_settings['currency'] === 'GBP' ? 'selected' : ''; ?>" onclick="selectCurrency('GBP', '£')">
                                    <input type="radio" name="currency" value="GBP" <?php echo $site_settings['currency'] === 'GBP' ? 'checked' : ''; ?>>
                                    <div class="currency-info">
                                        <div class="currency-name">British Pound</div>
                                        <div class="currency-code">GBP - £</div>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="currency_symbol" id="currencySymbol" value="<?php echo htmlspecialchars($site_settings['currency_symbol']); ?>">
                        </div>

                        <div class="preview-section">
                            <div class="preview-header">Preview</div>
                            <div class="site-preview">
                                <img src="../../assets/images/<?php echo htmlspecialchars($site_settings['site_logo']); ?>" alt="Logo" id="previewLogo">
                                <div class="site-preview-info">
                                    <h4 id="previewName"><?php echo htmlspecialchars($site_settings['site_name']); ?></h4>
                                    <p id="previewDescription"><?php echo htmlspecialchars($site_settings['site_description']); ?></p>
                                    <small>Currency: <span id="previewCurrency"><?php echo $site_settings['currency']; ?></span></small>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="resetForm('siteSettingsForm')">
                                <i class="fas fa-undo me-1"></i>Reset
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Save Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Appearance Settings -->
                <div id="appearance" class="settings-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-palette"></i>
                        </div>
                        <div>
                            <h2 class="section-title">Appearance</h2>
                            <p class="section-description">Upload and manage your library's logo and favicon</p>
                        </div>
                    </div>

                    <!-- Logo Upload -->
                    <div class="form-group">
                        <label class="form-label">Library Logo</label>
                        <form method="POST" enctype="multipart/form-data" id="logoForm">
                            <input type="hidden" name="action" value="upload_logo">
                            <div class="file-upload-area" onclick="triggerFileInput('logoFile')">
                                <div class="upload-icon">
                                    <i class="fas fa-image"></i>
                                </div>
                                <div class="upload-text">Click to upload new logo</div>
                                <div class="upload-note">JPG, PNG, GIF, SVG - Max 2MB</div>
                                <input type="file" name="logo_file" id="logoFile" class="file-input" 
                                       accept=".jpg,.jpeg,.png,.gif,.svg" onchange="uploadFile('logoForm')">
                            </div>
                            <div class="current-file">
                                <strong>Current Logo:</strong><br>
                                <img src="../../assets/images/<?php echo htmlspecialchars($site_settings['site_logo']); ?>" alt="Current Logo" style="margin-top: 0.5rem;">
                            </div>
                        </form>
                    </div>

                    <!-- Favicon Upload -->
                    <div class="form-group">
                        <label class="form-label">Favicon</label>
                        <form method="POST" enctype="multipart/form-data" id="faviconForm">
                            <input type="hidden" name="action" value="upload_favicon">
                            <div class="file-upload-area" onclick="triggerFileInput('faviconFile')">
                                <div class="upload-icon">
                                    <i class="fas fa-star"></i>
                                </div>
                                <div class="upload-text">Click to upload new favicon</div>
                                <div class="upload-note">ICO, PNG - Max 1MB</div>
                                <input type="file" name="favicon_file" id="faviconFile" class="file-input" 
                                       accept=".ico,.png" onchange="uploadFile('faviconForm')">
                            </div>
                            <div class="current-file">
                                <strong>Current Favicon:</strong><br>
                                <img src="../../assets/images/<?php echo htmlspecialchars($site_settings['site_favicon']); ?>" alt="Current Favicon" style="margin-top: 0.5rem; width: 32px; height: 32px;">
                            </div>
                        </form>
                    </div>
                </div>

                <!-- System Settings -->
                <div id="system" class="settings-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-server"></i>
                        </div>
                        <div>
                            <h2 class="section-title">System Settings</h2>
                            <p class="section-description">Configure system preferences and performance settings</p>
                        </div>
                    </div>

                    <form method="POST" id="systemSettingsForm">
                        <input type="hidden" name="action" value="update_site_settings">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Items Per Page</label>
                                <select name="items_per_page" class="form-control">
                                    <option value="10" <?php echo $site_settings['items_per_page'] == '10' ? 'selected' : ''; ?>>10 items</option>
                                    <option value="20" <?php echo $site_settings['items_per_page'] == '20' ? 'selected' : ''; ?>>20 items</option>
                                    <option value="50" <?php echo $site_settings['items_per_page'] == '50' ? 'selected' : ''; ?>>50 items</option>
                                    <option value="100" <?php echo $site_settings['items_per_page'] == '100' ? 'selected' : ''; ?>>100 items</option>
                                </select>
                                <div class="form-text">Number of items to display per page in lists</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Session Timeout</label>
                                <select name="session_timeout" class="form-control">
                                    <option value="1800" <?php echo $site_settings['session_timeout'] == '1800' ? 'selected' : ''; ?>>30 minutes</option>
                                    <option value="3600" <?php echo $site_settings['session_timeout'] == '3600' ? 'selected' : ''; ?>>1 hour</option>
                                    <option value="7200" <?php echo $site_settings['session_timeout'] == '7200' ? 'selected' : ''; ?>>2 hours</option>
                                    <option value="14400" <?php echo $site_settings['session_timeout'] == '14400' ? 'selected' : ''; ?>>4 hours</option>
                                </select>
                                <div class="form-text">Auto-logout time for inactive sessions</div>
                            </div>
                        </div>

                        <!-- Hidden fields to maintain site settings -->
                        <input type="hidden" name="site_name" value="<?php echo htmlspecialchars($site_settings['site_name']); ?>">
                        <input type="hidden" name="site_description" value="<?php echo htmlspecialchars($site_settings['site_description']); ?>">
                        <input type="hidden" name="currency" value="<?php echo htmlspecialchars($site_settings['currency']); ?>">
                        <input type="hidden" name="currency_symbol" value="<?php echo htmlspecialchars($site_settings['currency_symbol']); ?>">

                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="resetForm('systemSettingsForm')">
                                <i class="fas fa-undo me-1"></i>Reset
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Save Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <!-- Add New Admin Modal -->
    <div class="modal fade" id="addAdminModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="fas fa-user-shield me-2"></i>Add New Administrator
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addAdminForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_new_admin">
                        
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> You are creating a new administrator account with elevated privileges.
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" class="form-control" required>
                            <div class="form-text">Must be unique across the system</div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="full_name" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Email Address *</label>
                            <input type="email" name="email" class="form-control" required>
                            <div class="form-text">Must be unique across the system</div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Role *</label>
                            <select name="role" class="form-control" required>
                                <option value="">Select Role</option>
                                <option value="super_admin">Super Admin (Full Access)</option>
                                <option value="librarian">Librarian (Standard Access)</option>
                                <option value="assistant">Assistant (Limited Access)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Password *</label>
                            <div class="password-toggle">
                                <input type="password" name="password" id="adminPassword" class="form-control" required>
                                <button type="button" class="password-toggle-btn" onclick="togglePassword('adminPassword')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="form-text">Minimum 6 characters</div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Confirm Password *</label>
                            <div class="password-toggle">
                                <input type="password" name="confirm_password" id="adminConfirmPassword" class="form-control" required>
                                <button type="button" class="password-toggle-btn" onclick="togglePassword('adminConfirmPassword')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-user-plus me-1"></i>Create Admin Account
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
            if (page === 'settings') return;
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

        // Switch between settings tabs
        function switchTab(tabId) {
            // Hide all sections
            document.querySelectorAll('.settings-section').forEach(section => {
                section.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.settings-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected section
            document.getElementById(tabId).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        // Show add admin modal
        function showAddAdminModal() {
            const modal = new bootstrap.Modal(document.getElementById('addAdminModal'));
            modal.show();
        }

        // Toggle password visibility
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const button = input.nextElementSibling;
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Select currency
        function selectCurrency(currency, symbol) {
            // Remove selected class from all options
            document.querySelectorAll('.currency-option').forEach(option => {
                option.classList.remove('selected');
            });
            
            // Add selected class to clicked option
            event.currentTarget.classList.add('selected');
            
            // Update hidden field
            document.getElementById('currencySymbol').value = symbol;
            
            // Update preview
            document.getElementById('previewCurrency').textContent = currency;
        }

        // Trigger file input
        function triggerFileInput(inputId) {
            document.getElementById(inputId).click();
        }

        // Upload file
        function uploadFile(formId) {
            const form = document.getElementById(formId);
            if (confirm('Upload this file?')) {
                form.submit();
            }
        }

        // Reset form
        function resetForm(formId) {
            if (confirm('Reset all changes in this form?')) {
                document.getElementById(formId).reset();
            }
        }

        // Real-time preview updates
        document.addEventListener('DOMContentLoaded', function() {
            // Site name preview
            const siteNameInput = document.querySelector('input[name="site_name"]');
            if (siteNameInput) {
                siteNameInput.addEventListener('input', function() {
                    document.getElementById('previewName').textContent = this.value;
                });
            }
            
            // Site description preview
            const siteDescInput = document.querySelector('input[name="site_description"]');
            if (siteDescInput) {
                siteDescInput.addEventListener('input', function() {
                    document.getElementById('previewDescription').textContent = this.value;
                });
            }
            
            // Password validation
            const newPasswordInput = document.getElementById('newPassword');
            const confirmPasswordInput = document.getElementById('confirmPassword');
            
            if (newPasswordInput && confirmPasswordInput) {
                function validatePasswords() {
                    const newPassword = newPasswordInput.value;
                    const confirmPassword = confirmPasswordInput.value;
                    
                    if (newPassword && confirmPassword && newPassword !== confirmPassword) {
                        confirmPasswordInput.setCustomValidity('Passwords do not match');
                    } else {
                        confirmPasswordInput.setCustomValidity('');
                    }
                }
                
                newPasswordInput.addEventListener('input', validatePasswords);
                confirmPasswordInput.addEventListener('input', validatePasswords);
            }

            // Add Admin form validation
            const addAdminForm = document.getElementById('addAdminForm');
            if (addAdminForm) {
                addAdminForm.addEventListener('submit', function(e) {
                    const password = document.getElementById('adminPassword').value;
                    const confirmPassword = document.getElementById('adminConfirmPassword').value;
                    const username = document.querySelector('input[name="username"]').value;
                    const email = document.querySelector('input[name="email"]').value;
                    const role = document.querySelector('select[name="role"]').value;
                    
                    // Validate required fields
                    if (!username || !email || !password || !role) {
                        e.preventDefault();
                        alert('Please fill in all required fields');
                        return;
                    }
                    
                    // Validate password match
                    if (password !== confirmPassword) {
                        e.preventDefault();
                        alert('Passwords do not match');
                        return;
                    }
                    
                    // Validate password length
                    if (password.length < 6) {
                        e.preventDefault();
                        alert('Password must be at least 6 characters long');
                        return;
                    }
                    
                    // Final confirmation
                    const roleText = role === 'super_admin' ? 'Super Admin' : 
                                   role === 'librarian' ? 'Librarian' : 'Assistant';
                    
                    if (!confirm(`Are you sure you want to create a new ${roleText} account for "${username}"?`)) {
                        e.preventDefault();
                        return;
                    }
                });
            }
        });

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

        // Initialize page
        console.log('Settings page initialized successfully');
    </script>
</body>
</html>