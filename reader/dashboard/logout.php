<?php
session_start();

// Clear remember me cookie if exists
if (isset($_COOKIE['remember_token'])) {
    // Clear from database
    if (isset($_SESSION['reader_id'])) {
        try {
            require_once '../config/database.php';
            $database = new Database();
            $db = $database->getConnection();
            
            $stmt = $db->prepare("UPDATE members SET remember_token = NULL, token_expires = NULL WHERE member_id = ?");
            $stmt->execute([$_SESSION['reader_id']]);
        } catch (PDOException $e) {
            error_log("Logout error: " . $e->getMessage());
        }
    }
    
    // Clear cookie
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
}

// Destroy session
session_destroy();

// Redirect to login page
header('Location: ./?logged_out=1');
exit();
?>
