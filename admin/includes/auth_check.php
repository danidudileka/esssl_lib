<?php
// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

// Check session timeout
if (isset($_SESSION['admin_login_time'])) {
    if (time() - $_SESSION['admin_login_time'] > 3600) { // 1 hour timeout
        session_destroy();
        header('Location: ../index.php?timeout=1');
        exit();
    }
    $_SESSION['admin_login_time'] = time(); // Reset timeout
}

// Get admin role
$admin_role = $_SESSION['admin_role'] ?? 'assistant';
$is_super_admin = ($admin_role === 'super_admin');
$is_librarian = ($admin_role === 'librarian');
$is_assistant = ($admin_role === 'assistant');

// Function to check if user has permission for specific actions
function hasPermission($action) {
    global $admin_role;
    
    $permissions = [
        'super_admin' => ['all'],
        'librarian' => [
            'view_dashboard',
            'manage_books',
            'manage_loans',
            'approve_loans',
            'manage_members',
            'send_messages',
            'view_reports',
            'process_payments'
        ],
        'assistant' => [
            'view_dashboard',
            'view_books',
            'view_loans',
            'view_members',
            'view_reports'
        ]
    ];
    
    if ($admin_role === 'super_admin' || in_array('all', $permissions[$admin_role])) {
        return true;
    }
    
    return in_array($action, $permissions[$admin_role] ?? []);
}

// Function to require admin authentication for specific actions
function requireAdminAuth() {
    global $admin_role;
    
    if ($admin_role === 'assistant') {
        // Assistant needs to enter admin credentials
        return false;
    }
    
    return true;
}
?>