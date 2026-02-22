<?php
session_start();

// Clear remember me cookie if exists
if (isset($_COOKIE['admin_remember_token'])) {
    // Clear from database
    if (isset($_SESSION['admin_id'])) {
        try {
            require_once '../config/database.php';
            $database = new Database();
            $db = $database->getConnection();
            
            $stmt = $db->prepare("UPDATE admin SET remember_token = NULL, token_expires = NULL WHERE admin_id = ?");
            $stmt->execute([$_SESSION['admin_id']]);
        } catch (PDOException $e) {
            error_log("Admin logout error: " . $e->getMessage());
        }
    }
    
    // Clear cookie
    setcookie('admin_remember_token', '', time() - 3600, '/', '', false, true);
}

// Destroy session
session_destroy();

// Redirect to login page
header('Location: ./?logged_out=1');
exit();
?>