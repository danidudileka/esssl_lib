<?php
// Set headers first
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

session_start();
require_once '../config/database.php';

if (!isset($_SESSION['reader_logged_in']) || $_SESSION['reader_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit();
}

// Debug: Log what we're receiving
error_log("POST data received: " . print_r($_POST, true));
error_log("Request method: " . $_SERVER['REQUEST_METHOD']);
error_log("Content type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));

// Get data from POST (FormData sends as $_POST, not JSON)
$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');

// Debug output
error_log("Parsed values:");
error_log("First name: '$first_name'");
error_log("Last name: '$last_name'");
error_log("Email: '$email'");

// Validate required fields
if (empty($first_name) || empty($last_name) || empty($email)) {
    echo json_encode([
        'success' => false, 
        'message' => 'First name, last name, and email are required',
        'debug' => [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'first_name_empty' => empty($first_name),
            'last_name_empty' => empty($last_name),
            'email_empty' => empty($email),
            'received_post' => $_POST
        ]
    ]);
    exit();
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $member_id = $_SESSION['reader_id'];
    
    // Check if email is already taken by another user
    $email_check_stmt = $db->prepare("SELECT member_id FROM members WHERE email = ? AND member_id != ?");
    $email_check_stmt->execute([$email, $member_id]);
    
    if ($email_check_stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Email address is already in use by another member']);
        exit();
    }
    
    // Update member information
    $stmt = $db->prepare("
        UPDATE members 
        SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ?, updated_at = NOW() 
        WHERE member_id = ?
    ");
    $result = $stmt->execute([
        $first_name,
        $last_name, 
        $email,
        $phone,
        $address,
        $member_id
    ]);
    
    if ($result) {
        // Update session variables
        $_SESSION['reader_name'] = $first_name . ' ' . $last_name;
        $_SESSION['reader_email'] = $email;
        
        echo json_encode([
            'success' => true, 
            'message' => 'Profile updated successfully',
            'updated_name' => $_SESSION['reader_name'],
            'updated_email' => $_SESSION['reader_email']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
    }
    
} catch (PDOException $e) {
    error_log("Update profile error: " . $e->getMessage());
    
    // Handle duplicate email error
    if ($e->getCode() == 23000) {
        echo json_encode(['success' => false, 'message' => 'Email address is already in use']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    }
} catch (Exception $e) {
    error_log("Update profile error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error occurred']);
}
?>