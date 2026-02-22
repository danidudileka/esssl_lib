<?php
// File: api/search_members.php
session_start();
header('Content-Type: application/json');

require_once '../config/database.php';

try {
    // Check if admin is logged in
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $query = trim($input['query'] ?? '');

    if (empty($query) || strlen($query) < 2) {
        echo json_encode(['success' => false, 'message' => 'Query too short']);
        exit();
    }

    $database = new Database();
    $db = $database->getConnection();

    // Search members by name, member_code, or email
    $search_param = "%$query%";
    $stmt = $db->prepare("
        SELECT 
            member_id,
            first_name,
            last_name,
            member_code,
            email,
            membership_type,
            membership_expiry,
            status,
            phone,
            registration_date
        FROM members 
        WHERE 
            (CONCAT(first_name, ' ', last_name) LIKE ? OR 
             member_code LIKE ? OR 
             email LIKE ? OR
             first_name LIKE ? OR
             last_name LIKE ?)
        ORDER BY 
            CASE 
                WHEN member_code LIKE ? THEN 1
                WHEN CONCAT(first_name, ' ', last_name) LIKE ? THEN 2
                WHEN email LIKE ? THEN 3
                ELSE 4
            END,
            first_name, last_name
        LIMIT 20
    ");
    
    $stmt->execute([
        $search_param, $search_param, $search_param, $search_param, $search_param,
        $search_param, $search_param, $search_param
    ]);
    
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format the response
    $response = [
        'success' => true,
        'members' => $members,
        'count' => count($members)
    ];

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Search failed: ' . $e->getMessage()
    ]);
}
?>