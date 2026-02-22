<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

session_start();
require_once '../config/database.php';
require_once '../config/constants.php';

// Check if user is logged in
if (!isset($_SESSION['reader_logged_in']) || $_SESSION['reader_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$reader_id = $_SESSION['reader_id'];
$action = $_GET['action'] ?? 'library';
$filter = $_GET['filter'] ?? 'all';
$alphabet = $_GET['alphabet'] ?? 'all';
$search = $_GET['search'] ?? '';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    switch ($action) {
        case 'library':
            $books = getLibraryBooks($db, $reader_id, $filter, $alphabet, $search);
            echo json_encode(['success' => true, 'books' => $books]);
            break;
            
        case 'my_books':
            $loans = getMyBooks($db, $reader_id, $search);
            echo json_encode(['success' => true, 'loans' => $loans]);
            break;
            
        case 'history':
            $history = getReadingHistory($db, $reader_id, $search);
            echo json_encode(['success' => true, 'history' => $history]);
            break;
            
        case 'favorites':
            $favorites = getFavoriteBooks($db, $reader_id, $search);
            echo json_encode(['success' => true, 'favorites' => $favorites]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}

function getLibraryBooks($db, $reader_id, $filter, $alphabet, $search) {
    $sql = "
        SELECT b.*, 
               CASE WHEN f.book_id IS NOT NULL THEN 1 ELSE 0 END as is_favorite,
               CASE WHEN b.available_copies > 0 THEN 'available' ELSE 'borrowed' END as availability_status
        FROM books b
        LEFT JOIN member_favorites f ON b.book_id = f.book_id AND f.member_id = ?
        WHERE b.status = 'active'
    ";
    
    $params = [$reader_id];
    
    // Apply filters
    if ($filter !== 'all') {
        switch ($filter) {
            case 'available':
                $sql .= " AND b.available_copies > 0";
                break;
            case 'borrowed':
                $sql .= " AND b.available_copies = 0";
                break;
            default:
                $sql .= " AND LOWER(b.genre) LIKE ?";
                $params[] = '%' . strtolower($filter) . '%';
        }
    }
    
    // Apply alphabet filter
    if ($alphabet !== 'all') {
        $sql .= " AND UPPER(LEFT(b.title, 1)) = ?";
        $params[] = strtoupper($alphabet);
    }
    
    // Apply search
    if (!empty($search)) {
        $sql .= " AND (LOWER(b.title) LIKE ? OR LOWER(b.author) LIKE ?)";
        $searchTerm = '%' . strtolower($search) . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $sql .= " ORDER BY b.title ASC LIMIT 50";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    $books = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $books[] = [
            'book_id' => (int)$row['book_id'],
            'isbn' => $row['isbn'],
            'title' => $row['title'],
            'author' => $row['author'],
            'publisher' => $row['publisher'],
            'publication_year' => $row['publication_year'],
            'pages' => (int)$row['pages'],
            'genre' => $row['genre'],
            'description' => $row['description'],
            'cover_image' => $row['cover_image'] ?: 'assets/images/default-book.jpg',
            'total_copies' => (int)$row['total_copies'],
            'available_copies' => (int)$row['available_copies'],
            'rating' => (float)$row['rating'],
            'is_favorite' => (bool)$row['is_favorite'],
            'availability_status' => $row['availability_status'],
            'rack_number' => $row['rack_number'],
            'dewey_decimal_number' => $row['dewey_decimal_number'],
            'shelf_position' => $row['shelf_position'],
            'floor_level' => $row['floor_level']
        ];
    }
    
    return $books;
}

function getMyBooks($db, $reader_id, $search) {
    $sql = "
        SELECT bl.*, b.title, b.author, b.cover_image, b.isbn,
               CASE 
                   WHEN bl.status = 'active' AND bl.due_date < CURDATE() THEN 'overdue'
                   WHEN bl.approval_status = 'pending' THEN 'pending'
                   ELSE bl.status
               END as display_status
        FROM book_loans bl
        JOIN books b ON bl.book_id = b.book_id
        WHERE bl.member_id = ? 
        AND (bl.status IN ('active', 'overdue') OR bl.approval_status = 'pending')
    ";
    
    $params = [$reader_id];
    
    if (!empty($search)) {
        $sql .= " AND (LOWER(b.title) LIKE ? OR LOWER(b.author) LIKE ?)";
        $searchTerm = '%' . strtolower($search) . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $sql .= " ORDER BY bl.loan_date DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    $loans = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $loans[] = [
            'loan_id' => (int)$row['loan_id'],
            'book_id' => (int)$row['book_id'],
            'title' => $row['title'],
            'author' => $row['author'],
            'cover_image' => $row['cover_image'] ?: 'assets/images/default-book.jpg',
            'loan_date' => $row['loan_date'],
            'due_date' => $row['due_date'],
            'return_date' => $row['return_date'],
            'status' => $row['status'],
            'approval_status' => $row['approval_status'],
            'display_status' => $row['display_status'],
            'fine_amount' => (float)$row['fine_amount']
        ];
    }
    
    return $loans;
}

function getReadingHistory($db, $reader_id, $search) {
    $sql = "
        SELECT bl.*, b.title, b.author, b.cover_image, b.isbn
        FROM book_loans bl
        JOIN books b ON bl.book_id = b.book_id
        WHERE bl.member_id = ? AND bl.status = 'returned'
    ";
    
    $params = [$reader_id];
    
    if (!empty($search)) {
        $sql .= " AND (LOWER(b.title) LIKE ? OR LOWER(b.author) LIKE ?)";
        $searchTerm = '%' . strtolower($search) . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $sql .= " ORDER BY bl.return_date DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getFavoriteBooks($db, $reader_id, $search) {
    $sql = "
        SELECT b.*, f.added_date,
               CASE WHEN b.available_copies > 0 THEN 'available' ELSE 'borrowed' END as availability_status
        FROM member_favorites f
        JOIN books b ON f.book_id = b.book_id
        WHERE f.member_id = ? AND b.status = 'active'
    ";
    
    $params = [$reader_id];
    
    if (!empty($search)) {
        $sql .= " AND (LOWER(b.title) LIKE ? OR LOWER(b.author) LIKE ?)";
        $searchTerm = '%' . strtolower($search) . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $sql .= " ORDER BY f.added_date DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    $favorites = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $favorites[] = [
            'book_id' => (int)$row['book_id'],
            'title' => $row['title'],
            'author' => $row['author'],
            'cover_image' => $row['cover_image'] ?: 'assets/images/default-book.jpg',
            'genre' => $row['genre'],
            'rating' => (float)$row['rating'],
            'available_copies' => (int)$row['available_copies'],
            'availability_status' => $row['availability_status'],
            'added_date' => $row['added_date']
        ];
    }
    
    return $favorites;
}
?>