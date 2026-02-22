<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/constants.php';

// Check if reader is logged in
if (!isset($_SESSION['reader_logged_in']) || $_SESSION['reader_logged_in'] !== true) {
   header('Location: ../');
   exit();
}

// Check session timeout
if (isset($_SESSION['reader_login_time'])) {
   if (time() - $_SESSION['reader_login_time'] > 3600) { // 1 hour timeout
       session_destroy();
       header('Location: ../?timeout=1');
       exit();
   }
   $_SESSION['reader_login_time'] = time();
}

$reader_id = $_SESSION['reader_id'];
$reader_name = $_SESSION['reader_name'] ?? 'Reader';

try {
   $database = new Database();
   $db = $database->getConnection();
   
   // Get member details and check membership status
   $member_stmt = $db->prepare("SELECT * FROM members WHERE member_id = ?");
   $member_stmt->execute([$reader_id]);
   $member = $member_stmt->fetch();
   
   // FIXED MEMBERSHIP EXPIRY CHECK
   $membership_expired = false;
   $days_until_expiry = 0;
   $expiry_status = 'active';
   
   if ($member && $member['membership_expiry']) {
       $expiry_date = new DateTime($member['membership_expiry']);
       $current_date = new DateTime();
       $interval = $current_date->diff($expiry_date);
       
       if ($expiry_date < $current_date) {
           $membership_expired = true;
           $expiry_status = 'expired';
           $days_until_expiry = -$interval->days;
       } else {
           $days_until_expiry = $interval->days;
           if ($days_until_expiry <= 7) {
               $expiry_status = 'expiring_soon';
           }
       }
   }
   
   // Get unread notifications count
   $notif_stmt = $db->prepare("SELECT COUNT(*) as unread FROM member_notifications WHERE member_id = ? AND is_read = FALSE");
   $notif_stmt->execute([$reader_id]);
   $unread_notifications = $notif_stmt->fetch()['unread'];
   
   // Get overdue books count
   $overdue_stmt = $db->prepare("
       SELECT COUNT(*) as overdue 
       FROM book_loans 
       WHERE member_id = ? AND status IN ('active', 'overdue') AND due_date < CURDATE()
   ");
   $overdue_stmt->execute([$reader_id]);
   $overdue_count = $overdue_stmt->fetch()['overdue'];
   
   // Get books for library with favorites info
   $books_stmt = $db->prepare("
       SELECT b.*, 
              CASE WHEN f.book_id IS NOT NULL THEN 1 ELSE 0 END as is_favorite
       FROM books b
       LEFT JOIN member_favorites f ON b.book_id = f.book_id AND f.member_id = ?
       WHERE b.status = 'active'
       ORDER BY b.title ASC
       LIMIT 100
   ");
   $books_stmt->execute([$reader_id]);
   $books = $books_stmt->fetchAll(PDO::FETCH_ASSOC);
   
   // Get my books data
   $my_books_stmt = $db->prepare("
       SELECT 
           bl.*,
           b.title, 
           b.author, 
           b.cover_image,
           b.isbn,
           b.rack_number,
           b.dewey_decimal_number,
           b.dewey_classification,
           b.shelf_position,
           b.floor_level,
           CASE 
               WHEN bl.approval_status = 'pending' THEN 'pending'
               WHEN bl.status = 'active' AND bl.due_date < CURDATE() THEN 'overdue'
               WHEN bl.status = 'overdue' THEN 'overdue'
               WHEN bl.status = 'active' THEN 'active'
               ELSE bl.status
           END as display_status
       FROM book_loans bl
       JOIN books b ON bl.book_id = b.book_id
       WHERE bl.member_id = ? 
       AND (bl.status IN ('active', 'overdue') OR bl.approval_status = 'pending')
       ORDER BY 
           CASE WHEN bl.approval_status = 'pending' THEN 1 ELSE 2 END,
           bl.loan_date DESC
   ");
   $my_books_stmt->execute([$reader_id]);
   $my_books = $my_books_stmt->fetchAll(PDO::FETCH_ASSOC);
   
   // Get reading history
   $history_stmt = $db->prepare("
       SELECT bl.*, b.title, b.author, b.cover_image, b.isbn, b.dewey_decimal_number,
              CASE WHEN f.book_id IS NOT NULL THEN 1 ELSE 0 END as is_favorite
       FROM book_loans bl
       JOIN books b ON bl.book_id = b.book_id
       LEFT JOIN member_favorites f ON b.book_id = f.book_id AND f.member_id = ?
       WHERE bl.member_id = ? AND bl.status = 'returned'
       ORDER BY bl.return_date DESC
       LIMIT 50
   ");
   $history_stmt->execute([$reader_id, $reader_id]);
   $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
   
   // FIXED - Get favorites (removed currently borrowed check)
   $favorites_stmt = $db->prepare("
       SELECT b.*, f.added_date
       FROM member_favorites f
       JOIN books b ON f.book_id = b.book_id
       WHERE f.member_id = ? AND b.status = 'active'
       ORDER BY f.added_date DESC
   ");
   $favorites_stmt->execute([$reader_id]);
   $favorites = $favorites_stmt->fetchAll(PDO::FETCH_ASSOC);
   
   // Get notifications for display
   $notifications_stmt = $db->prepare("
       SELECT * FROM member_notifications 
       WHERE member_id = ? 
       ORDER BY created_at DESC 
       LIMIT 50
   ");
   $notifications_stmt->execute([$reader_id]);
   $notifications = $notifications_stmt->fetchAll(PDO::FETCH_ASSOC);
   
   // Get stats data for charts
   $stats_stmt = $db->prepare("
       SELECT 
           COUNT(CASE WHEN bl.status = 'returned' THEN 1 END) as books_read,
           COUNT(CASE WHEN bl.status IN ('active', 'overdue') THEN 1 END) as current_loans,
           COUNT(CASE WHEN bl.status = 'overdue' THEN 1 END) as overdue_books,
           COUNT(DISTINCT CASE WHEN bl.status = 'returned' THEN YEAR(bl.return_date) END) as active_years
       FROM book_loans bl
       WHERE bl.member_id = ?
   ");
   $stats_stmt->execute([$reader_id]);
   $user_stats = $stats_stmt->fetch();
   
   // Get favorite genres
   $genre_stmt = $db->prepare("
       SELECT b.genre, COUNT(*) as count
       FROM book_loans bl
       JOIN books b ON bl.book_id = b.book_id
       WHERE bl.member_id = ? AND bl.status = 'returned' AND b.genre IS NOT NULL AND b.genre != ''
       GROUP BY b.genre
       ORDER BY count DESC
       LIMIT 5
   ");
   $genre_stmt->execute([$reader_id]);
   $favorite_genres = $genre_stmt->fetchAll(PDO::FETCH_ASSOC);
   
   // Get monthly reading data for last 12 months
   $monthly_stmt = $db->prepare("
       SELECT 
           DATE_FORMAT(bl.return_date, '%Y-%m') as month,
           COUNT(*) as books_count
       FROM book_loans bl
       WHERE bl.member_id = ? AND bl.status = 'returned' 
       AND bl.return_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
       GROUP BY DATE_FORMAT(bl.return_date, '%Y-%m')
       ORDER BY month ASC
   ");
   $monthly_stmt->execute([$reader_id]);
   $monthly_reading = $monthly_stmt->fetchAll(PDO::FETCH_ASSOC);
   
} catch (PDOException $e) {
   error_log("Dashboard error: " . $e->getMessage());
   $membership_expired = false;
   $unread_notifications = 0;
   $overdue_count = 0;
   $books = [];
   $my_books = [];
   $history = [];
   $favorites = [];
   $notifications = [];
   $user_stats = ['books_read' => 0, 'current_loans' => 0, 'overdue_books' => 0, 'active_years' => 0];
   $favorite_genres = [];
   $monthly_reading = [];
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Dashboard - ESSSL Library</title>
   
   <!-- Stylesheets -->
   <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
   <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
   <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
   <link href="../../assets/css/dashboard.css" rel="stylesheet">

   <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
   
   <style>
       .dashboard-body {
           font-family: 'Inter', sans-serif;
           background: #f8fafc;
           margin: 0;
           padding: 0;
       }

       .membership-expired-bar {
           position: fixed;
           top: 0;
           left: 0;
           right: 0;
           background: linear-gradient(90deg, #dc3545 0%, #e74c3c 100%);
           color: white;
           padding: 0.75rem 1rem;
           text-align: center;
           font-weight: 600;
           z-index: 1050;
           box-shadow: 0 2px 10px rgba(220, 53, 69, 0.3);
           animation: slideDownExpired 0.5s ease-out;
       }

       .membership-expired-bar.show {
           display: block;
       }

       .membership-expired-bar .close-btn {
           position: absolute;
           right: 1rem;
           top: 50%;
           transform: translateY(-50%);
           background: none;
           border: none;
           color: white;
           font-size: 1.2rem;
           cursor: pointer;
           opacity: 0.8;
       }

       .membership-expired-bar .close-btn:hover {
           opacity: 1;
       }

       .main-container.with-expired-bar {
           margin-top: 60px;
       }

       @keyframes slideDownExpired {
           from { transform: translateY(-100%); }
           to { transform: translateY(0); }
       }

       .top-alert {
           position: fixed;
           top: 20px;
           right: 20px;
           z-index: 10000;
           min-width: 300px;
           max-width: 400px;
           padding: 1rem 1.5rem;
           border-radius: 8px;
           color: white;
           font-weight: 500;
           box-shadow: 0 4px 12px rgba(0,0,0,0.15);
           animation: slideInRight 0.3s ease-out;
           display: flex;
           align-items: center;
           gap: 0.75rem;
       }

       .top-alert.success {
           background: linear-gradient(135deg, #10b981 0%, #059669 100%);
       }

       .top-alert.error {
           background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
       }

       .top-alert.warning {
           background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
       }

       .top-alert.info {
           background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
       }

       .top-alert .close-alert {
           margin-left: auto;
           background: none;
           border: none;
           color: white;
           font-size: 1.2rem;
           cursor: pointer;
           opacity: 0.8;
       }

       .top-alert .close-alert:hover {
           opacity: 1;
       }

       /* Main Container */
       .main-container {
           display: flex;
           min-height: 100vh;
           transition: margin-top 0.3s ease;
       }

       /* Sidebar Styles */
       .sidebar {
           width: 280px;
           background: #78938A;
           color: white;
           position: fixed;
           height: 100vh;
           overflow-y: auto;
           z-index: 1000;
           box-shadow: 4px 0 10px rgba(0,0,0,0.1);
           transition: transform 0.3s ease;
       }

       .sidebar-header {
           padding: 1.5rem;
           border-bottom: 1px solid rgba(255,255,255,0.1);
       }

       .logo-section {
           display: flex;
           align-items: center;
           gap: 12px;
       }

       .sidebar-logo {
           width: 40px;
           height: 40px;
           background: rgba(255,255,255,0.2);
           border-radius: 8px;
           display: flex;
           align-items: center;
           justify-content: center;
           font-size: 1.2rem;
       }

       .sidebar-title {
           font-size: 1.25rem;
           font-weight: 700;
           margin: 0;
       }

       .sidebar-nav {
           padding: 1rem 0;
       }

       .nav-list {
           list-style: none;
           padding: 0;
           margin: 0;
       }

       .nav-item {
           margin: 0.25rem 0;
       }

       .nav-link {
           display: flex;
           align-items: center;
           padding: 0.875rem 1.5rem;
           color: rgba(255,255,255,0.8);
           text-decoration: none;
           transition: all 0.2s ease;
           border-radius: 8px;
           margin: 0 1rem;
           position: relative;
           cursor: pointer;
       }

       .nav-link:hover, .nav-link.active {
           background: rgba(255,255,255,0.15);
           box-shadow: 4px 4px 4px #00000030;
           color: white;
           backdrop-filter: blur(10px);
       }

       .nav-link i {
           width: 20px;
           margin-right: 12px;
           font-size: 1rem;
       }

       .badge {
           font-size: 0.75rem;
           padding: 0.25rem 0.5rem;
           border-radius: 12px;
           margin-left: auto;
           min-width: 20px;
           text-align: center;
           font-weight: 600;
       }

       .badge.my-books {
           background: rgba(255,255,255,0.2);
           color: white;
       }

       .badge.overdue {
           background: #ef4444;
           color: white;
       }

       .badge.notifications {
           background: #f59e0b;
           color: white;
       }

       .user-info {
           position: absolute;
           bottom: 0;
           left: 0;
           right: 0;
           padding: 1.5rem;
           border-top: 1px solid rgba(255,255,255,0.1);
           background: rgba(0,0,0,0.1);
           display: flex;
           align-items: center;
           gap: 12px;
       }

       .user-avatar {
           width: 40px;
           height: 40px;
           background: rgba(255,255,255,0.2);
           border-radius: 50%;
           display: flex;
           align-items: center;
           justify-content: center;
       }

       .user-details h6 {
           margin: 0;
           font-weight: 600;
           font-size: 0.9rem;
       }

       .user-details p {
           margin: 0;
           font-size: 0.75rem;
           opacity: 0.8;
       }

       .logout-btn {
           color: rgba(255,255,255,0.8);
           font-size: 1.1rem;
           margin-left: auto;
           cursor: pointer;
           text-decoration: none;
       }

       .logout-btn:hover {
           color: white;
       }

       /* Main Content */
       .main-content {
           flex: 1;
           margin-left: 280px;
           padding: 2rem;
           min-height: 100vh;
       }

       /* Section Styles */
       .content-section {
           display: none;
       }

       .content-section.active {
           display: block;
       }

       .section-header {
           display: flex;
           justify-content: space-between;
           align-items: flex-start;
           margin-bottom: 2rem;
           padding-bottom: 1rem;
           border-bottom: 1px solid #e5e7eb;
           flex-wrap: wrap;
           gap: 1rem;
       }

       .section-title {
           font-size: 2rem;
           font-weight: 700;
           color: #1f2937;
           margin: 0;
       }

       .section-subtitle {
           color: #6b7280;
           margin: 0.5rem 0 0 0;
           font-size: 0.95rem;
       }

       .header-right {
           display: flex;
           gap: 1rem;
           align-items: center;
           flex-wrap: wrap;
       }

       /* Search Styles */
       .search-container {
           position: relative;
       }

       .search-wrapper {
           position: relative;
           display: flex;
           align-items: center;
       }

       .search-input {
           padding: 0.75rem 1rem 0.75rem 2.5rem;
           border: 1px solid #d1d5db;
           border-radius: 8px;
           width: 280px;
           font-size: 0.875rem;
           transition: all 0.2s;
       }

       .search-input:focus {
           outline: none;
           border-color: #78938A;
           box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
       }

       .search-icon {
           position: absolute;
           left: 0.75rem;
           color: #9ca3af;
           font-size: 0.875rem;
           z-index: 10;
       }

       /* Button Styles */
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
           color: #6b7280;
           border-radius: 6px;
           cursor: pointer;
           transition: all 0.2s;
       }

       .view-btn.active,
       .view-btn:hover {
           background: white;
           color: #374151;
           box-shadow: 0 1px 2px rgba(0,0,0,0.05);
       }

       .filter-btn {
           padding: 0.75rem 1rem;
           border: 1px solid #d1d5db;
           background: white;
           border-radius: 8px;
           color: #374151;
           font-size: 0.875rem;
           cursor: pointer;
           transition: all 0.2s;
           position: relative;
       }

       .filter-btn:hover {
           border-color: #78938A;
           color: #78938A;
       }

       /* Filter Dropdown */
       .filter-dropdown {
           position: absolute;
           top: 100%;
           right: 0;
           margin-top: 0.5rem;
           background: white;
           border: 1px solid #e5e7eb;
           border-radius: 8px;
           box-shadow: 0 10px 25px rgba(0,0,0,0.1);
           min-width: 250px;
           z-index: 100;
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
           color: #374151;
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
           color: #4b5563;
           font-size: 0.875rem;
       }

       /* Alphabet Filter */
       .alphabet-filter {
           margin-bottom: 2rem;
       }

       .alphabet-container {
           display: flex;
           flex-wrap: wrap;
           gap: 0.5rem;
       }

       .alphabet-btn {
           width: 40px;
           height: 40px;
           border: 1px solid #d1d5db;
           background: white;
           border-radius: 8px;
           display: flex;
           align-items: center;
           justify-content: center;
           cursor: pointer;
           transition: all 0.2s;
           font-weight: 500;
           font-size: 0.875rem;
       }

       .alphabet-btn:hover,
       .alphabet-btn.active {
           background: #78938A;
           color: white;
           border-color: #78938A;
       }

       /* Books Grid - Equal Heights */
       .books-container {
           margin-top: 1rem;
       }

       .books-grid {
           display: grid;
           grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
           gap: 1.5rem;
       }

       /* Book Card - Equal*/
       .book-card {
           background: white;
           border-radius: 12px;
           overflow: hidden;
           box-shadow: 0 2px 8px rgba(0,0,0,0.08);
           transition: all 0.3s ease;
           cursor: pointer;
           border: 1px solid #f3f4f6;
           display: flex;
           flex-direction: column;
           height: 100%;
       }

       .book-card:hover {
           transform: translateY(-4px);
           box-shadow: 0 8px 25px rgba(0,0,0,0.15);
       }

       .book-cover {
           position: relative;
           height: 200px;
           background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
           display: flex;
           align-items: center;
           justify-content: center;
           overflow: hidden;
           flex-shrink: 0;
       }

       .book-cover img {
           width: 100%;
           height: 100%;
           object-fit: cover;
       }

       .book-info {
           padding: 1rem;
           display: flex;
           flex-direction: column;
           flex: 1;
       }

       .book-title {
           font-size: 1rem;
           font-weight: 600;
           color: #1f2937;
           margin-bottom: 0.5rem;
           line-height: 1.4;
           display: -webkit-box;
           -webkit-line-clamp: 2;
           -webkit-box-orient: vertical;
           overflow: hidden;
           min-height: 2.8rem;
       }

       .book-author {
           font-size: 0.875rem;
           color: #6b7280;
           margin-bottom: 0.75rem;
           overflow: hidden;
           text-overflow: ellipsis;
           white-space: nowrap;
       }

       .book-meta {
           display: flex;
           flex-wrap: wrap;
           gap: 0.5rem;
           margin-bottom: 1rem;
           flex: 1;
       }

       .meta-item {
           font-size: 0.75rem;
           padding: 0.25rem 0.5rem;
           background: #f3f4f6;
           border-radius: 4px;
           color: #6b7280;
       }

       .book-actions {
           display: flex;
           gap: 0.5rem;
           margin-top: auto;
       }

       /*  FAVORITE BUTTON - PERFECT GRAY TO RED CONCEPT */
       .btn-favorite {
           background: none;
           border: 2px solid #d1d5db;
           padding: 0.5rem;
           border-radius: 6px;
           cursor: pointer;
           transition: all 0.3s ease;
           color: #9ca3af !important; 
           display: flex;
           align-items: center;
           justify-content: center;
           position: relative;
       }

       .btn-favorite:hover {
           border-color: #ef4444;
           color: #ef4444 !important;
           background: rgba(239, 68, 68, 0.05);
           transform: scale(1.05);
       }

       .btn-favorite.is-favorite {
           border-color: #ef4444 !important;
           color: #ef4444 !important;
           background: rgba(239, 68, 68, 0.1);
           box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
       }

       .btn-favorite.is-favorite i {
           color: #ef4444 !important;
           animation: heartBeat 0.5s ease;
       }

       @keyframes heartBeat {
           0% { transform: scale(1); }
           25% { transform: scale(1.2); }
           50% { transform: scale(1); }
           75% { transform: scale(1.1); }
           100% { transform: scale(1); }
       }

       .btn-borrow {
           flex: 1;
           background: #78938A;
           color: white;
           border: none;
           padding: 0.5rem 1rem;
           border-radius: 6px;
           cursor: pointer;
           transition: all 0.2s;
           font-size: 0.875rem;
       }

       .btn-borrow:hover {
           background: #5b8e7d;
       }

       .btn-borrow:disabled {
           background: #9ca3af;
           cursor: not-allowed;
       }

       /* Status badges */
       .status-badge {
           display: inline-block;
           padding: 0.25rem 0.5rem;
           border-radius: 12px;
           font-size: 0.75rem;
           font-weight: 500;
       }

       .status-badge.pending {
           background: #fef3c7;
           color: #92400e;
       }

       .status-badge.active {
           background: #dcfce7;
           color: #166534;
       }

       .status-badge.overdue {
           background: #fee2e2;
           color: #dc2626;
       }

       /* Notifications Section */
       .notification-item {
           background: white;
           border-radius: 12px;
           margin-bottom: 1rem;
           box-shadow: 0 2px 8px rgba(0,0,0,0.08);
           border: 1px solid #f3f4f6;
           overflow: hidden;
           transition: all 0.3s ease;
       }

       .notification-item:hover {
           box-shadow: 0 4px 12px rgba(0,0,0,0.12);
       }

       .notification-item.unread {
           border-left: 4px solid #78938A;
           background: #fafbff;
       }

       .notification-header {
           padding: 1rem 1.5rem;
           cursor: pointer;
           display: flex;
           align-items: center;
           justify-content: space-between;
           border-bottom: 1px solid #f3f4f6;
           background: #fafbff;
       }

       .notification-header:hover {
           background: #f0f4ff;
       }

       .notification-header h6 {
           margin: 0;
           font-weight: 600;
           color: #1f2937;
           flex: 1;
       }

       .notification-icon {
           width: 40px;
           height: 40px;
           border-radius: 50%;
           display: flex;
           align-items: center;
           justify-content: center;
           margin-right: 1rem;
           font-size: 1.1rem;
       }

       .notification-icon.success {
           background: rgba(16, 185, 129, 0.1);
           color: #10b981;
       }

       .notification-icon.warning {
           background: rgba(245, 158, 11, 0.1);
           color: #f59e0b;
       }

       .notification-icon.danger {
           background: rgba(239, 68, 68, 0.1);
           color: #ef4444;
       }

       .notification-icon.info {
           background: rgba(59, 130, 246, 0.1);
           color: #3b82f6;
       }

       .notification-meta {
           display: flex;
           align-items: center;
           gap: 1rem;
       }

       .notification-time {
           font-size: 0.75rem;
           color: #6b7280;
       }

       .notification-actions {
           display: flex;
           gap: 0.5rem;
           align-items: center;
       }

       .notification-body {
           padding: 1.5rem;
           display: none;
           background: white;
           border-top: 1px solid #f3f4f6;
       }

       .notification-body.expanded {
           display: block;
           animation: slideDown 0.3s ease-out;
       }

       .notification-message {
           color: #4b5563;
           line-height: 1.6;
           white-space: pre-line;
           margin-bottom: 1rem;
       }

       .notification-body-actions {
           display: flex;
           gap: 0.5rem;
           padding-top: 1rem;
           border-top: 1px solid #f3f4f6;
       }

       .btn-notification {
           padding: 0.5rem 1rem;
           border: 1px solid #d1d5db;
           background: white;
           border-radius: 6px;
           cursor: pointer;
           font-size: 0.875rem;
           transition: all 0.2s;
       }

       .btn-notification.primary {
           background: #78938A;
           color: white;
           border-color: #78938A;
       }

       .btn-notification.primary:hover {
           background: #5a67d8;
       }

       .btn-notification.danger {
           background: transparent;
           color: #ef4444;
           border-color: #ef4444;
           padding: 0.4rem 0.8rem;
           font-size: 0.8rem;
       }

       .btn-notification.danger:hover {
           background: #ef4444;
           color: white;
       }

       .btn-notification:hover {
           border-color: #9ca3af;
           background: #f9fafb;
       }

       .delete-btn {
           background: none;
           border: none;
           color: #ef4444;
           cursor: pointer;
           padding: 0.25rem 0.5rem;
           border-radius: 4px;
           font-size: 0.875rem;
           transition: all 0.2s;
       }

       .delete-btn:hover {
           background: rgba(239, 68, 68, 0.1);
       }

       .modal-dialog {
           max-width: 1000px;
           margin: 1rem auto;
       }

       .modal-content {
           border: none;
           border-radius: 12px;
           box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
           max-height: 90vh;
       }

       .modal-header {
           border-bottom: 1px solid #f3f4f6;
           padding: 1.5rem;
           flex-shrink: 0;
       }

       .modal-body {
           padding: 1.5rem;
           overflow-y: auto;
           max-height: calc(90vh - 140px);
       }

       .book-detail-cover {
           position: relative;
       }

       .book-detail-cover img {
           width: 100%;
           max-width: 300px;
           height: auto;
           border-radius: 8px;
           box-shadow: 0 4px 12px rgba(0,0,0,0.15);
       }

       /* FIXED Availability Badge - With Background */
       .availability-badge {
           display: inline-block;
           padding: 0.5rem 1rem;
           border-radius: 20px;
           font-size: 0.875rem;
           font-weight: 600;
           text-align: center;
           white-space: nowrap;
           margin-top: 1rem;
           border: 2px solid;
       }

       .availability-badge.available {
           background: rgba(16, 185, 129, 0.15);
           color: #10b981;
           border-color: #10b981;
       }

       .availability-badge.unavailable {
           background: rgba(239, 68, 68, 0.15);
           color: #ef4444;
           border-color: #ef4444;
       }

       /* Location Information Styles */
       .location-section {
           background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
           border: 1px solid #dee2e6;
           border-radius: 12px;
           padding: 1.5rem;
           margin: 1rem 0;
       }

       .location-grid {
           display: grid;
           grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
           gap: 1rem;
           margin-bottom: 1rem;
       }

       .location-item {
           background: white;
           border-radius: 8px;
           padding: 0.75rem;
           border-left: 4px solid #007bff;
       }

       .location-label {
           font-size: 0.75rem;
           color: #6c757d;
           font-weight: 600;
           text-transform: uppercase;
           margin-bottom: 0.25rem;
       }

       .location-value {
           font-weight: 600;
           font-size: 0.9rem;
       }

       .location-value.dewey-decimal {
           color: #0d6efd;
           font-family: 'Courier New', monospace;
           background: #e3f2fd;
           padding: 0.25rem 0.5rem;
           border-radius: 4px;
           display: inline-block;
       }

       .location-value.rack {
           color: #fd7e14;
           background: #fff3cd;
           padding: 0.25rem 0.5rem;
           border-radius: 4px;
           display: inline-block;
       }

       /* No data state */
       .no-data {
           text-align: center;
           padding: 3rem 1rem;
           color: #6b7280;
       }

       .no-data i {
           font-size: 3rem;
           margin-bottom: 1rem;
           opacity: 0.5;
       }

       /* MEMBERSHIP SECTION */
       .membership-section {
           background: white;
           border-radius: 12px;
           padding: 1.5rem;
           box-shadow: 0 2px 8px rgba(0,0,0,0.08);
           margin-bottom: 2rem;
       }

       /* STATS SECTION */
       .stats-section {
           background: white;
           border-radius: 12px;
           padding: 1.5rem;
           box-shadow: 0 2px 8px rgba(0,0,0,0.08);
           margin-bottom: 2rem;
       }

       .stats-grid {
           display: grid;
           grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
           gap: 1.5rem;
           margin-bottom: 2rem;
       }

       .stat-card {
           background: linear-gradient(135deg, #92BA92 0%, #78938A 100%);
           color: white;
           padding: 1.5rem;
           border-radius: 12px;
           text-align: center;
       }

       .stat-number {
           font-size: 2.5rem;
           font-weight: 700;
           margin-bottom: 0.5rem;
       }

       .stat-label {
           font-size: 0.875rem;
           color: #fff;
           opacity: 0.9;
       }

       .chart-container {
           background: white;
           padding: 1.5rem;
           border-radius: 12px;
           box-shadow: 0 2px 8px rgba(0,0,0,0.08);
           margin-bottom: 1.5rem;
           height: 400px;
       }

       .chart-title {
           font-size: 1.125rem;
           font-weight: 600;
           margin-bottom: 1rem;
           color: #1f2937;
       }

       .chart-wrapper {
           position: relative;
           height: 300px;
       }

       /* Profile Section */
       .profile-container {
           max-width: 1200px;
       }

       .card {
           border: none;
           border-radius: 12px;
           box-shadow: 0 2px 8px rgba(0,0,0,0.08);
       }

       .card-header {
           background: #f8fafc;
           border-bottom: 1px solid #e5e7eb;
           border-radius: 12px 12px 0 0 !important;
           padding: 1.25rem 1.5rem;
       }

       .card-header h5 {
           margin: 0;
           font-weight: 600;
           color: #1f2937;
       }

       .card-body {
           padding: 1.5rem;
       }

       .form-label {
           font-weight: 500;
           color: #374151;
           margin-bottom: 0.5rem;
       }

       .form-control {
           border-radius: 8px;
           border: 1px solid #d1d5db;
           padding: 0.75rem;
           transition: all 0.2s;
       }

       .form-control:focus {
           border-color: #78938A;
           box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
       }

       .btn-primary {
           background: #78938A;
           border-color: #78938A;
           border-radius: 8px;
           padding: 0.75rem 1.5rem;
       }

       .btn-primary:hover {
           background: #5a67d8;
           border-color: #5a67d8;
       }

       .btn-secondary {
           border-radius: 8px;
           padding: 0.75rem 1.5rem;
       }

       /* Mobile Responsive Styles */
       @media (max-width: 768px) {
           .sidebar {
               transform: translateX(-100%);
               transition: transform 0.3s ease;
           }

           .sidebar.show {
               transform: translateX(0);
           }

           .main-content {
               margin-left: 0;
               padding: 1rem;
           }

           .books-grid {
               grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
               gap: 1rem;
           }

           .top-alert {
               right: 10px;
               left: 10px;
               min-width: auto;
           }
       }

       /* Animation keyframes */
       @keyframes slideDown {
           from {
               opacity: 0;
               transform: translateY(-10px);
           }
           to {
               opacity: 1;
               transform: translateY(0);
           }
       }

       @keyframes slideInRight {
           from { transform: translateX(100%); opacity: 0; }
           to { transform: translateX(0); opacity: 1; }
       }
       
       @keyframes slideOutRight {
           from { transform: translateX(0); opacity: 1; }
           to { transform: translateX(100%); opacity: 0; }
       }

       /* Mobile menu toggle */
       .mobile-menu-toggle {
           display: none;
           position: fixed;
           top: 1rem;
           left: 1rem;
           z-index: 1001;
           background: #78938A;
           color: white;
           border: none;
           border-radius: 8px;
           padding: 0.75rem;
           font-size: 1.1rem;
           cursor: pointer;
       }

       @media (max-width: 768px) {
           .mobile-menu-toggle {
               display: block;
           }
       }
   </style>
</head>
<body class="dashboard-body">
   <!-- MEMBERSHIP EXPIRED BAR -->
   <?php if ($membership_expired): ?>
   <div class="membership-expired-bar show" id="membershipExpiredBar">
       <i class="fas fa-exclamation-triangle me-2"></i>
       <strong>Membership Expired!</strong> Your library membership has expired. Please renew to continue borrowing books.
       <button class="close-btn" onclick="closeMembershipBar()">
           <i class="fas fa-times"></i>
       </button>
   </div>
   <?php endif; ?>

   <!-- Mobile Menu Toggle -->
   <button class="mobile-menu-toggle" onclick="toggleSidebar()">
       <i class="fas fa-bars"></i>
   </button>

   <div class="main-container <?php echo $membership_expired ? 'with-expired-bar' : ''; ?>" id="mainContainer">
       <!--  Sidebar -->
       <nav class="sidebar" id="sidebar">
           <div class="sidebar-header">
               <div class="logo-section">
                   <div class="sidebar-logo">
                       <i class="fas fa-book"></i>
                   </div>
                   <h4 class="sidebar-title">ESSSL Library</h4>
                   
               </div>
           </div>

           <div class="sidebar-nav">
               <ul class="nav-list">
                   <li class="nav-item">
                       <a class="nav-link active" data-section="library">
                           <i class="fas fa-books"></i>
                           Library
                       </a>
                   </li>
                   <li class="nav-item">
                       <a class="nav-link" data-section="my-books">
                           <i class="fas fa-book-reader"></i>
                           My Books
                           <?php if (count($my_books) > 0): ?>
                               <span class="badge <?php echo $overdue_count > 0 ? 'overdue' : 'my-books'; ?>"><?php echo count($my_books); ?></span>
                           <?php endif; ?>
                       </a>
                   </li>
                   <li class="nav-item">
                       <a class="nav-link" data-section="history">
                           <i class="fas fa-history"></i>
                           My History
                       </a>
                   </li>
                   <li class="nav-item">
                       <a class="nav-link" data-section="favorites">
                           <i class="fas fa-heart"></i>
                           Favorites
                       </a>
                   </li>
                   <li class="nav-item">
                       <a class="nav-link" data-section="notifications">
                           <i class="fas fa-bell"></i>
                           Notifications
                           <?php if ($unread_notifications > 0): ?>
                               <span class="badge notifications"><?php echo $unread_notifications; ?></span>
                           <?php endif; ?>
                       </a>
                   </li>
                   <li class="nav-item">
                       <a class="nav-link" data-section="membership">
                           <i class="fas fa-id-card"></i>
                           Membership
                       </a>
                   </li>
                   <li class="nav-item">
                       <a class="nav-link" data-section="stats">
                           <i class="fas fa-chart-bar"></i>
                           Stats
                       </a>
                   </li>
                   <li class="nav-item">
                       <a class="nav-link" data-section="profile">
                           <i class="fas fa-user"></i>
                           Profile
                       </a>
                   </li>
               </ul>
           </div>

           <div class="user-info">
               <div class="user-avatar">
                   <i class="fas fa-user"></i>
               </div>
               <div class="user-details">
                   <h6><?php echo htmlspecialchars($reader_name); ?></h6>
                   <p><?php echo htmlspecialchars($_SESSION['reader_email']); ?></p>
               </div>
               <a href="../logout.php" class="logout-btn" title="Logout">
                   <i class="fas fa-sign-out-alt"></i>
               </a>
           </div>
       </nav>

       <!-- Main Content -->
       <main class="main-content">
           <!-- Library Section -->
           <section id="library" class="content-section active">
               <div class="section-header">
                   <div>
                       <h1 class="section-title">Library Catalog</h1>
                       <p class="section-subtitle">Browse and discover books</p>
                   </div>
                   <div class="header-right">
                       <div class="search-container">
                           <div class="search-wrapper">
                               <i class="fas fa-search search-icon"></i>
                               <input type="text" class="search-input" placeholder="Search books..." id="searchBooks">
                           </div>
                       </div>
                       <div class="view-toggle">
                           <button class="view-btn active" data-view="grid" data-target="books">
                               <i class="fas fa-th"></i>
                           </button>
                           <button class="view-btn" data-view="list" data-target="books">
                               <i class="fas fa-list"></i>
                           </button>
                       </div>
                       <div style="position: relative;">
                           <button class="filter-btn" onclick="toggleFilterDropdown('library')">
                               <i class="fas fa-filter"></i> Filter
                           </button>
                           <div class="filter-dropdown" id="libraryFilterDropdown">
                               <div class="filter-section">
                                   <h6>Sort By</h6>
                                   <div class="filter-option">
                                       <input type="radio" name="librarySort" id="librarySortRecent" value="recent">
                                       <label for="librarySortRecent">Recently Added</label>
                                   </div>
                                   <div class="filter-option">
                                       <input type="radio" name="librarySort" id="librarySortOldest" value="oldest">
                                       <label for="librarySortOldest">Oldest First</label>
                                   </div>
                                   <div class="filter-option">
                                       <input type="radio" name="librarySort" id="librarySortAZ" value="az" checked>
                                       <label for="librarySortAZ">A to Z</label>
                                   </div>
                                   <div class="filter-option">
                                       <input type="radio" name="librarySort" id="librarySortZA" value="za">
                                       <label for="librarySortZA">Z to A</label>
                                   </div>
                                   <div class="filter-option">
                                       <input type="radio" name="librarySort" id="librarySortYear" value="year">
                                       <label for="librarySortYear">Release Year</label>
                                   </div>
                                   <div class="filter-option">
                                       <input type="radio" name="librarySort" id="librarySortRating" value="rating">
                                       <label for="librarySortRating">Highest Rated</label>
                                   </div>
                               </div>
                           </div>
                       </div>
                   </div>
               </div>

               <!-- Alphabet Filter -->
               <div class="alphabet-filter">
                   <div class="alphabet-container">
                       <button class="alphabet-btn active" data-letter="All">All</button>
                       <?php for ($i = ord('A'); $i <= ord('Z'); $i++): ?>
                           <button class="alphabet-btn" data-letter="<?php echo chr($i); ?>"><?php echo chr($i); ?></button>
                       <?php endfor; ?>
                   </div>
               </div>

               <!-- Books Container -->
               <div class="books-container">
                   <div class="books-grid" id="booksGrid">
                       <!-- Books will be populated by JavaScript -->
                   </div>
               </div>
           </section>

           <!-- My Books Section -->
           <section id="my-books" class="content-section">
               <div class="section-header">
                   <div>
                       <h1 class="section-title">My Books</h1>
                       <p class="section-subtitle">Your current loans and reservations</p>
                   </div>
                   <div class="header-right">
                       <div class="search-container">
                           <div class="search-wrapper">
                               <i class="fas fa-search search-icon"></i>
                               <input type="text" class="search-input" placeholder="Search my books..." id="searchMyBooks">
                           </div>
                       </div>
                       <div class="view-toggle">
                           <button class="view-btn active" data-view="grid" data-target="myBooks">
                               <i class="fas fa-th"></i>
                           </button>
                           <button class="view-btn" data-view="list" data-target="myBooks">
                               <i class="fas fa-list"></i>
                           </button>
                       </div>
                       <div style="position: relative;">
                           <button class="filter-btn" onclick="toggleFilterDropdown('myBooks')">
                               <i class="fas fa-filter"></i> Filter
                           </button>
                           <div class="filter-dropdown" id="myBooksFilterDropdown">
                               <div class="filter-section">
                                   <h6>Sort By</h6>
                                   <div class="filter-option">
                                       <input type="radio" name="myBooksSort" id="myBooksSortRecent" value="recent" checked>
                                       <label for="myBooksSortRecent">Recently Borrowed</label>
                                   </div>
                                   <div class="filter-option">
                                       <input type="radio" name="myBooksSort" id="myBooksSortDue" value="due">
                                       <label for="myBooksSortDue">Due Date</label>
                                   </div>
                                   <div class="filter-option">
                                       <input type="radio" name="myBooksSort" id="myBooksSortAZ" value="az">
                                       <label for="myBooksSortAZ">A to Z</label>
                                   </div>
                                   <div class="filter-option">
                                       <input type="radio" name="myBooksSort" id="myBooksSortStatus" value="status">
                                       <label for="myBooksSortStatus">Status</label>
                                   </div>
                               </div>
                           </div>
                       </div>
                   </div>
               </div>

               <div class="books-container">
                   <div class="books-grid" id="myBooksGrid">
                       <!-- My books will be populated by JavaScript -->
                   </div>
               </div>
           </section>

           <!-- History Section -->
           <section id="history" class="content-section">
               <div class="section-header">
                   <div>
                       <h1 class="section-title">Reading History</h1>
                       <p class="section-subtitle">Books you've read previously</p>
                   </div>
                   <div class="header-right">
                       <div class="search-container">
                           <div class="search-wrapper">
                               <i class="fas fa-search search-icon"></i>
                               <input type="text" class="search-input" placeholder="Search history..." id="searchHistory">
                           </div>
                       </div>
                       <div class="view-toggle">
                           <button class="view-btn active" data-view="grid" data-target="history">
                               <i class="fas fa-th"></i>
                           </button>
                           <button class="view-btn" data-view="list" data-target="history">
                               <i class="fas fa-list"></i>
                           </button>
                       </div>
                       <div style="position: relative;">
                           <button class="filter-btn" onclick="toggleFilterDropdown('history')">
                               <i class="fas fa-filter"></i> Filter
                           </button>
                           <div class="filter-dropdown" id="historyFilterDropdown">
                               <div class="filter-section">
                                   <h6>Sort By</h6>
                                   <div class="filter-option">
                                       <input type="radio" name="historySort" id="historySortRecent" value="recent" checked>
                                       <label for="historySortRecent">Recently Returned</label>
                                   </div>
                                   <div class="filter-option">
                                       <input type="radio" name="historySort" id="historySortOldest" value="oldest">
                                       <label for="historySortOldest">Oldest First</label>
                                   </div>
                                   <div class="filter-option">
                                       <input type="radio" name="historySort" id="historySortAZ" value="az">
                                       <label for="historySortAZ">A to Z</label>
                                   </div>
                               </div>
                           </div>
                       </div>
                   </div>
               </div>

               <div class="books-container">
                   <div class="books-grid" id="historyGrid">
                       <!-- History will be populated by JavaScript -->
                   </div>
               </div>
           </section>

           <!-- Favorites Section -->
           <section id="favorites" class="content-section">
               <div class="section-header">
                   <div>
                       <h1 class="section-title">My Favorites</h1>
                       <p class="section-subtitle">Books you've marked as favorites</p>
                   </div>
                   <div class="header-right">
                       <div class="search-container">
                           <div class="search-wrapper">
                               <i class="fas fa-search search-icon"></i>
                               <input type="text" class="search-input" placeholder="Search favorites..." id="searchFavorites">
                           </div>
                       </div>
                       <div class="view-toggle">
                           <button class="view-btn active" data-view="grid" data-target="favorites">
                               <i class="fas fa-th"></i>
                           </button>
                           <button class="view-btn" data-view="list" data-target="favorites">
                               <i class="fas fa-list"></i>
                           </button>
                       </div>
                       <div style="position: relative;">
                           <button class="filter-btn" onclick="toggleFilterDropdown('favorites')">
                               <i class="fas fa-filter"></i> Filter
                           </button>
                           <div class="filter-dropdown" id="favoritesFilterDropdown">
                               <div class="filter-section">
                                   <h6>Sort By</h6>
                                   <div class="filter-option">
                                       <input type="radio" name="favoritesSort" id="favoritesSortRecent" value="recent" checked>
                                       <label for="favoritesSortRecent">Recently Added</label>
                                   </div>
                                   <div class="filter-option">
                                       <input type="radio" name="favoritesSort" id="favoritesSortAZ" value="az">
                                       <label for="favoritesSortAZ">A to Z</label>
                                   </div>
                                   <div class="filter-option">
                                       <input type="radio" name="favoritesSort" id="favoritesSortYear" value="year">
                                       <label for="favoritesSortYear">Release Year</label>
                                   </div>
                                   <div class="filter-option">
                                       <input type="radio" name="favoritesSort" id="favoritesSortRating" value="rating">
                                       <label for="favoritesSortRating">Highest Rated</label>
                                   </div>
                               </div>
                           </div>
                       </div>
                   </div>
               </div>

               <div class="books-container">
                   <div class="books-grid" id="favoritesGrid">
                       <!-- Favorites will be populated by JavaScript -->
                   </div>
               </div>
           </section>

           <!-- Notifications Section -->
           <section id="notifications" class="content-section">
               <div class="section-header">
                   <div>
                       <h1 class="section-title">Notifications</h1>
                       <p class="section-subtitle">Important updates and messages</p>
                   </div>
                   <div class="header-right">
                       <button class="btn-notification primary" onclick="markAllAsRead()">
                           <i class="fas fa-check-double me-1"></i>Mark All Read
                       </button>
                       <button class="btn-notification danger" onclick="clearAllNotifications()">
                           <i class="fas fa-trash me-1"></i>Clear All
                       </button>
                   </div>
               </div>

               <div id="notificationsContainer">
                   <!-- notifications will be populated by JavaScript -->
               </div>
           </section>

           <!-- NEW MEMBERSHIP SECTION -->
           <section id="membership" class="content-section">
               <div class="section-header">
                   <div>
                       <h1 class="section-title">Membership Details</h1>
                       <p class="section-subtitle">Your library membership information</p>
                   </div>
               </div>

               <div class="membership-section">
                   <div class="row">
                       <div class="col-lg-8">
                           <div class="card">
                               <div class="card-header">
                                   <h5><i class="fas fa-id-card me-2"></i>Membership Information</h5>
                               </div>
                               <div class="card-body">
                                   <div class="row">
                                       <div class="col-md-6">
                                           <div class="mb-3">
                                               <strong>Member Code:</strong><br>
                                               <span class="text-primary fs-5"><?php echo htmlspecialchars($member['member_code'] ?? ''); ?></span>
                                           </div>
                                       </div>
                                       <div class="col-md-6">
                                           <div class="mb-3">
                                               <strong>Membership Type:</strong><br>
                                               <span class="badge bg-info fs-6"><?php echo htmlspecialchars(ucfirst($member['membership_type'] ?? '')); ?></span>
                                               </div>
                       </div>
                       <div class="col-md-6">
                           <div class="mb-3">
                               <strong>Registration Date:</strong><br>
                               <?php echo $member['registration_date'] ? date('M j, Y', strtotime($member['registration_date'])) : 'N/A'; ?>
                           </div>
                       </div>
                       <div class="col-md-6">
                           <div class="mb-3">
                               <strong>Expiry Date:</strong><br>
                               <?php echo $member['membership_expiry'] ? date('M j, Y', strtotime($member['membership_expiry'])) : 'N/A'; ?>
                           </div>
                       </div>
                   </div>
                   
                   <div class="row">
                       <div class="col-12">
                           <div class="mb-3">
                               <strong>Status:</strong><br>
                               <span class="badge bg-<?php echo $membership_expired ? 'danger' : ($expiry_status === 'expiring_soon' ? 'warning' : 'success'); ?> fs-6">
                                   <?php 
                                   if ($membership_expired) {
                                       echo 'Expired';
                                   } elseif ($expiry_status === 'expiring_soon') {
                                       echo "Expires in {$days_until_expiry} day(s)";
                                   } else {
                                       echo 'Active';
                                   }
                                   ?>
                               </span>
                           </div>
                       </div>
                   </div>

                   <?php if ($membership_expired): ?>
                       <div class="alert alert-danger">
                           <i class="fas fa-exclamation-triangle me-2"></i>
                           <strong>Action Required:</strong> Your membership has expired. Please renew to continue enjoying library services.
                       </div>
                       <button class="btn btn-danger" onclick="contactLibrary()">
                           <i class="fas fa-phone me-1"></i>Contact for Renewal
                       </button>
                   <?php elseif ($expiry_status === 'expiring_soon'): ?>
                       <div class="alert alert-warning">
                           <i class="fas fa-clock me-2"></i>
                           <strong>Reminder:</strong> Your membership will expire in <?php echo $days_until_expiry; ?> day(s). Consider renewing soon.
                       </div>
                       <button class="btn btn-warning" onclick="contactLibrary()">
                           <i class="fas fa-sync-alt me-1"></i>Renew Membership
                       </button>
                   <?php else: ?>
                       <div class="alert alert-success">
                           <i class="fas fa-check-circle me-2"></i>
                           Your membership is active and in good standing.
                       </div>
                   <?php endif; ?>
               </div>
           </div>
       </div>
       <div class="col-lg-4">
           <div class="card">
               <div class="card-header">
                   <h5><i class="fas fa-chart-line me-2"></i>Membership Benefits</h5>
               </div>
               <div class="card-body">
                   <ul class="list-unstyled">
                       <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Borrow up to 5 books</li>
                       <li class="mb-2"><i class="fas fa-check text-success me-2"></i>14-day loan period</li>
                       <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Online catalog access</li>
                       <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Digital resources</li>
                       <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Event notifications</li>
                       <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Reading recommendations</li>
                   </ul>
               </div>
           </div>
       </div>
   </div>
</div>
</section>

           <!-- NEW STATS SECTION -->
           <section id="stats" class="content-section">
               <div class="section-header">
                   <div>
                       <h1 class="section-title">Reading Statistics</h1>
                       <p class="section-subtitle">Your reading progress and insights</p>
                   </div>
               </div>

               <div class="stats-section">
                   <!-- Stats Overview Cards -->
                   <div class="stats-grid">
                       <div class="stat-card">
                           <div class="stat-number"><?php echo $user_stats['books_read']; ?></div>
                           <div class="stat-label">Books Read</div>
                       </div>
                       <div class="stat-card">
                           <div class="stat-number"><?php echo $user_stats['current_loans']; ?></div>
                           <div class="stat-label">Current Loans</div>
                       </div>
                       <div class="stat-card">
                           <div class="stat-number"><?php echo count($favorites); ?></div>
                           <div class="stat-label">Favorite Books</div>
                       </div>
                       <div class="stat-card">
                           <div class="stat-number"><?php echo $user_stats['overdue_books']; ?></div>
                           <div class="stat-label">Overdue Books</div>
                       </div>
                   </div>

                   <!-- Charts Row -->
                   <div class="row">
                       <div class="col-lg-8">
                           <div class="chart-container">
                               <h6 class="chart-title">Monthly Reading Activity</h6>
                               <div class="chart-wrapper">
                                   <canvas id="monthlyChart"></canvas>
                               </div>
                           </div>
                       </div>
                       <div class="col-lg-4">
                           <div class="chart-container">
                               <h6 class="chart-title">Favorite Genres</h6>
                               <div class="chart-wrapper">
                                   <canvas id="genreChart"></canvas>
                               </div>
                           </div>
                       </div>
                   </div>
               </div>
           </section>

           <!--  Profile Section -->
           <section id="profile" class="content-section">
               <div class="section-header">
                   <div>
                       <h1 class="section-title">My Profile</h1>
                       <p class="section-subtitle">Manage your account information</p>
                   </div>
               </div>

               <div class="profile-container">
                   <div class="row">
                       <div class="col-lg-12">
                           <div class="card">
                               <div class="card-header">
                                   <h5><i class="fas fa-user me-2"></i>Personal Information</h5>
                               </div>
                               <div class="card-body">
                                   <form id="profileForm">
                                       <div class="row">
                                           <div class="col-md-6">
                                               <div class="mb-3">
                                                   <label for="firstName" class="form-label">First Name <span class="text-danger">*</span></label>
                                                   <input type="text" class="form-control" id="firstName" value="<?php echo htmlspecialchars($member['first_name'] ?? ''); ?>" required>
                                               </div>
                                           </div>
                                           <div class="col-md-6">
                                               <div class="mb-3">
                                                   <label for="lastName" class="form-label">Last Name <span class="text-danger">*</span></label>
                                                   <input type="text" class="form-control" id="lastName" value="<?php echo htmlspecialchars($member['last_name'] ?? ''); ?>" required>
                                               </div>
                                           </div>
                                       </div>
                                       <div class="mb-3">
                                           <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                           <input type="email" class="form-control" id="email" value="<?php echo htmlspecialchars($member['email'] ?? ''); ?>" required>
                                       </div>
                                       <div class="mb-3">
                                           <label for="phone" class="form-label">Phone</label>
                                           <input type="text" class="form-control" id="phone" value="<?php echo htmlspecialchars($member['phone'] ?? ''); ?>">
                                       </div>
                                       <div class="mb-3">
                                           <label for="address" class="form-label">Address</label>
                                           <textarea class="form-control" id="address" rows="3"><?php echo htmlspecialchars($member['address'] ?? ''); ?></textarea>
                                       </div>
                                       <div class="d-flex gap-2">
                                           <button type="submit" class="btn btn-primary">
                                               <i class="fas fa-save me-1"></i>Update Profile
                                           </button>
                                           <button type="button" class="btn btn-secondary" onclick="resetProfileForm()">
                                               <i class="fas fa-undo me-1"></i>Reset
                                           </button>
                                       </div>
                                       <div class="mt-2">
                                           <small class="text-muted">* Required fields</small>
                                       </div>
                                   </form>
                               </div>
                           </div>
                       </div>
                   </div>
               </div>
           </section>
       </main>
   </div>

   <!--  Book Details Modal -->
   <div class="modal fade" id="bookDetailsModal" tabindex="-1">
       <div class="modal-dialog modal-xl">
           <div class="modal-content">
               <div class="modal-header">
                   <h5 class="modal-title"><i class="fas fa-book me-2"></i>Book Details</h5>
                   <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
               </div>
               <div class="modal-body" id="bookDetailsContent">
                   <!-- Book details will be loaded here -->
               </div>
           </div>
       </div>
   </div>

   <!-- Loading Overlay -->
   <div id="loadingOverlay" class="position-fixed top-0 start-0 w-100 h-100 d-none" style="background: rgba(0,0,0,0.5); z-index: 9999;">
       <div class="d-flex align-items-center justify-content-center h-100">
           <div class="spinner-border text-light" role="status">
               <span class="visually-hidden">Loading...</span>
           </div>
       </div>
   </div>



   <!-- Scripts -->
   <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
   <script>
       // BULLETPROOF LIVE UPDATE SYSTEM - NO MORE CTRL+R NEEDED!
       let liveUpdateInterval;
       let isUpdating = false;
       let updateCounter = 0;
       let pendingActions = new Set();

       // Current sort settings
       let sortSettings = {
           library: 'az',
           myBooks: 'recent',
           history: 'recent',
           favorites: 'recent'
       };

       // Pass PHP data to JavaScript
       window.dashboardData = {
           books: <?php echo json_encode($books); ?>,
           myBooks: <?php echo json_encode($my_books); ?>,
           history: <?php echo json_encode($history); ?>,
           favorites: <?php echo json_encode($favorites); ?>,
           notifications: <?php echo json_encode($notifications); ?>,
           userId: <?php echo $reader_id; ?>,
           userName: '<?php echo htmlspecialchars($reader_name); ?>',
           userStats: <?php echo json_encode($user_stats); ?>,
           favoriteGenres: <?php echo json_encode($favorite_genres); ?>,
           monthlyReading: <?php echo json_encode($monthly_reading); ?>
       };

       // Set membership configuration
       window.membershipConfig = {
           expired: <?php echo $membership_expired ? 'true' : 'false'; ?>,
           daysUntilExpiry: <?php echo $days_until_expiry; ?>,
           status: '<?php echo $expiry_status; ?>'
       };

       // Current display data
       let displayData = {
           books: [...window.dashboardData.books],
           myBooks: [...window.dashboardData.myBooks],
           history: [...window.dashboardData.history],
           favorites: [...window.dashboardData.favorites],
           notifications: [...window.dashboardData.notifications]
       };



       // SIMPLE TOP ALERT SYSTEM
       function showTopAlert(message, type = 'info') {
           // Remove any existing alerts
           document.querySelectorAll('.top-alert').forEach(alert => alert.remove());
           
           const alert = document.createElement('div');
           alert.className = `top-alert ${type}`;
           
           const iconMap = {
               'success': 'check-circle',
               'error': 'exclamation-triangle',
               'warning': 'exclamation-triangle',
               'info': 'info-circle'
           };
           
           alert.innerHTML = `
               <i class="fas fa-${iconMap[type]}"></i>
               <span>${message}</span>
               <button class="close-alert" onclick="this.parentElement.remove()">
                   <i class="fas fa-times"></i>
               </button>
           `;
           
           document.body.appendChild(alert);
           
           // Auto remove after 4 seconds
           setTimeout(() => {
               if (alert.parentNode) {
                   alert.style.animation = 'slideOutRight 0.3s ease-in';
                   setTimeout(() => alert.remove(), 300);
               }
           }, 4000);
       }

       // Filter dropdown toggle
       function toggleFilterDropdown(section) {
           const dropdown = document.getElementById(`${section}FilterDropdown`);
           const isShown = dropdown.classList.contains('show');
           
           // Close all dropdowns
           document.querySelectorAll('.filter-dropdown').forEach(d => d.classList.remove('show'));
           
           // Toggle current dropdown
           if (!isShown) {
               dropdown.classList.add('show');
           }
       }

       // Close dropdowns when clicking outside
       document.addEventListener('click', function(e) {
           if (!e.target.closest('.filter-btn') && !e.target.closest('.filter-dropdown')) {
               document.querySelectorAll('.filter-dropdown').forEach(d => d.classList.remove('show'));
           }
       });

       // Sort function
       function sortBooks(books, sortType) {
           const sortedBooks = [...books];
           
           switch(sortType) {
               case 'recent':
                   return sortedBooks.sort((a, b) => {
                       const dateA = new Date(a.added_date || a.loan_date || a.return_date || a.added_date);
                       const dateB = new Date(b.added_date || b.loan_date || b.return_date || b.added_date);
                       return dateB - dateA;
                   });
                   
               case 'oldest':
                   return sortedBooks.sort((a, b) => {
                       const dateA = new Date(a.added_date || a.loan_date || a.return_date || a.added_date);
                       const dateB = new Date(b.added_date || b.loan_date || b.return_date || b.added_date);
                       return dateA - dateB;
                   });
                   
               case 'az':
                   return sortedBooks.sort((a, b) => a.title.localeCompare(b.title));
                   
               case 'za':
                   return sortedBooks.sort((a, b) => b.title.localeCompare(a.title));
                   
               case 'year':
                   return sortedBooks.sort((a, b) => (b.publication_year || 0) - (a.publication_year || 0));
                   
               case 'rating':
                   return sortedBooks.sort((a, b) => (b.rating || 0) - (a.rating || 0));
                   
               case 'due':
                   return sortedBooks.sort((a, b) => new Date(a.due_date) - new Date(b.due_date));
                   
               case 'status':
                   const statusOrder = { 'pending': 1, 'overdue': 2, 'active': 3 };
                   return sortedBooks.sort((a, b) => {
                       return (statusOrder[a.display_status] || 99) - (statusOrder[b.display_status] || 99);
                   });
                   
               default:
                   return sortedBooks;
           }
       }

       // Handle sort radio changes
       document.querySelectorAll('input[name="librarySort"]').forEach(input => {
           input.addEventListener('change', function() {
               sortSettings.library = this.value;
               displayData.books = sortBooks(displayData.books, this.value);
               renderBooks(displayData.books);
           });
       });

       document.querySelectorAll('input[name="myBooksSort"]').forEach(input => {
           input.addEventListener('change', function() {
               sortSettings.myBooks = this.value;
               displayData.myBooks = sortBooks(displayData.myBooks, this.value);
               renderMyBooks(displayData.myBooks);
           });
       });

       document.querySelectorAll('input[name="historySort"]').forEach(input => {
           input.addEventListener('change', function() {
               sortSettings.history = this.value;
               displayData.history = sortBooks(displayData.history, this.value);
               renderHistory(displayData.history);
           });
       });

       document.querySelectorAll('input[name="favoritesSort"]').forEach(input => {
           input.addEventListener('change', function() {
               sortSettings.favorites = this.value;
               displayData.favorites = sortBooks(displayData.favorites, this.value);
               renderFavorites(displayData.favorites);
           });
       });

       // Close membership expired bar
       function closeMembershipBar() {
           document.getElementById('membershipExpiredBar').style.display = 'none';
           document.getElementById('mainContainer').classList.remove('with-expired-bar');
       }
// FIXED BADGE SYSTEM - Include notifications badge
function forceUpdateAllBadges() {
    
    // My Books badge with overdue check
    const myBooksBadge = document.querySelector('.nav-link[data-section="my-books"] .badge');
    const myBooksCount = window.dashboardData.myBooks.length;
    const overdueCount = window.dashboardData.myBooks.filter(book => book.display_status === 'overdue').length;
    
    // Remove existing badge first
    if (myBooksBadge) myBooksBadge.remove();
    
    if (myBooksCount > 0) {
        const myBooksLink = document.querySelector('.nav-link[data-section="my-books"]');
        myBooksLink.innerHTML += `<span class="badge ${overdueCount > 0 ? 'overdue' : 'my-books'}">${myBooksCount}</span>`;
    }

    // FIXED: Notifications badge - show unread count
    const notifBadge = document.querySelector('.nav-link[data-section="notifications"] .badge');
    const unreadCount = window.dashboardData.notifications.filter(n => !n.is_read).length;
    

    // Remove existing badge first
    if (notifBadge) notifBadge.remove();
    
    // Show badge if there are unread notifications
    if (unreadCount > 0) {
        const notifLink = document.querySelector('.nav-link[data-section="notifications"]');
        if (notifLink) {
            notifLink.innerHTML += `<span class="badge notifications">${unreadCount}</span>`;
        } else {
            console.error('❌ Notifications link not found');
        }
    } else {
    }
}

       // BULLETPROOF LIVE UPDATE SYSTEM
       function startBulletproofDataRefresh() {
           // Aggressive refresh - every 2 seconds
           liveUpdateInterval = setInterval(async () => {
               if (!isUpdating) {
                   await bulletproofRefreshAllData();
               }
           }, 2000);
       }

       async function bulletproofRefreshAllData() {
           if (isUpdating) return;
           isUpdating = true;
           updateCounter++;
           
           try {
               const response = await fetch('../../api/get_live_data.php', {
                   method: 'POST',
                   headers: { 'Content-Type': 'application/json' },
                   body: JSON.stringify({ 
                       member_id: window.dashboardData.userId,
                       counter: updateCounter,
                       timestamp: Date.now()
                   })
               });

               const data = await response.json();

               if (data.success) {
                   // Check if data actually changed
                   const hasChanges = 
                       JSON.stringify(data.books) !== JSON.stringify(window.dashboardData.books) ||
                       JSON.stringify(data.myBooks) !== JSON.stringify(window.dashboardData.myBooks) ||
                       JSON.stringify(data.favorites) !== JSON.stringify(window.dashboardData.favorites) ||
                       JSON.stringify(data.notifications) !== JSON.stringify(window.dashboardData.notifications);

                   if (hasChanges || pendingActions.size > 0) {
                       
                       // Update ALL data arrays
                       window.dashboardData.books = data.books || [];
                       window.dashboardData.myBooks = data.myBooks || [];
                       window.dashboardData.history = data.history || [];
                       window.dashboardData.favorites = data.favorites || [];
                       window.dashboardData.notifications = data.notifications || [];

                       // Update display data IMMEDIATELY with current sort
                       const activeSection = document.querySelector('.content-section.active').id;
                       displayData.books = sortBooks([...window.dashboardData.books], sortSettings.library);
                       displayData.myBooks = sortBooks([...window.dashboardData.myBooks], sortSettings.myBooks);
                       displayData.history = sortBooks([...window.dashboardData.history], sortSettings.history);
                       displayData.favorites = sortBooks([...window.dashboardData.favorites], sortSettings.favorites);
                       displayData.notifications = [...window.dashboardData.notifications];

                       // Clear pending actions
                       pendingActions.clear();

                       // Force immediate badge update
                       forceUpdateAllBadges();

                       // Re-render current section immediately
                       forceRenderSectionData(activeSection);
                       
                   }
               }
           } catch (error) {
               console.error('Bulletproof refresh error:', error);
           }
           
           isUpdating = false;
       }

       function renderCurrentSection(sectionId) {
           switch (sectionId) {
               case 'library':
                   renderBooks(displayData.books);
                   break;
               case 'my-books':
                   renderMyBooks(displayData.myBooks);
                   break;
               case 'history':
                   renderHistory(displayData.history);
                   break;
               case 'favorites':
                   renderFavorites(displayData.favorites);
                   break;
               case 'notifications':
                   renderNotifications(displayData.notifications);
                   break;
               case 'stats':
                   initializeCharts();
                   break;
           }
       }

       function forceRenderSectionData(section) {
           renderCurrentSection(section);
       }

       // FIXED BULLETPROOF FAVORITE TOGGLE - PERFECT GRAY TO RED HEART CONCEPT
       async function bulletproofToggleFavorite(bookId, buttonElement) {
    
    // Add to pending actions
    pendingActions.add(`favorite_${bookId}`);
    
    // Get current state
    const isCurrentlyFavorite = window.dashboardData.favorites.some(fav => fav.book_id == bookId);
    
    // INSTANT VISUAL FEEDBACK
    const allHeartButtons = document.querySelectorAll(`[data-book-id="${bookId}"]`);
    allHeartButtons.forEach(btn => {
        if (isCurrentlyFavorite) {
            btn.classList.remove('is-favorite');
            btn.style.color = '#9ca3af !important';
        } else {
            btn.classList.add('is-favorite');
            btn.style.color = '#ef4444 !important';
        }
    });
    
    // Update data arrays immediately
    window.dashboardData.books.forEach(book => {
        if (book.book_id == bookId) {
            book.is_favorite = isCurrentlyFavorite ? 0 : 1;
        }
    });
    
    window.dashboardData.history.forEach(book => {
        if (book.book_id == bookId) {
            book.is_favorite = isCurrentlyFavorite ? 0 : 1;
        }
    });
    
    // Handle favorites array
    if (isCurrentlyFavorite) {
        window.dashboardData.favorites = window.dashboardData.favorites.filter(book => book.book_id != bookId);
    } else {
        const bookToAdd = window.dashboardData.books.find(b => b.book_id == bookId);
        if (bookToAdd) {
            window.dashboardData.favorites.unshift({
                ...bookToAdd,
                added_date: new Date().toISOString()
            });
        }
    }
    
    // Update display data
    displayData.books = sortBooks([...window.dashboardData.books], sortSettings.library);
    displayData.history = sortBooks([...window.dashboardData.history], sortSettings.history);
    displayData.favorites = sortBooks([...window.dashboardData.favorites], sortSettings.favorites);
    
    // Force re-render if on favorites page
    const activeSection = document.querySelector('.content-section.active').id;
    if (activeSection === 'favorites') {
        renderFavorites(displayData.favorites);
    }
    
    try {
        // Create form data
        const formData = new FormData();
        formData.append('book_id', bookId.toString());
        
        console.log('Sending favorite toggle request with FormData:', {
            book_id: bookId.toString(),
            url: '../../api/toggle_favorite.php'
        });
        
        const response = await fetch('../../api/toggle_favorite.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin' // Include session cookies
        });

        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();

        if (data.success) {
            showTopAlert(data.message, 'success');
            pendingActions.delete(`favorite_${bookId}`);
        } else {
            // REVERT on failure
            window.dashboardData.books.forEach(book => {
                if (book.book_id == bookId) {
                    book.is_favorite = isCurrentlyFavorite ? 1 : 0;
                }
            });
            
            allHeartButtons.forEach(btn => {
                if (isCurrentlyFavorite) {
                    btn.classList.add('is-favorite');
                    btn.style.color = '#ef4444 !important';
                } else {
                    btn.classList.remove('is-favorite');
                    btn.style.color = '#9ca3af !important';
                }
            });
            
            pendingActions.delete(`favorite_${bookId}`);
            showTopAlert(data.message || 'Failed to update favorite', 'error');
        }
    } catch (error) {
        // REVERT on error
        window.dashboardData.books.forEach(book => {
            if (book.book_id == bookId) {
                book.is_favorite = isCurrentlyFavorite ? 1 : 0;
            }
        });
        
        allHeartButtons.forEach(btn => {
            if (isCurrentlyFavorite) {
                btn.classList.add('is-favorite');
                btn.style.color = '#ef4444 !important';
            } else {
                btn.classList.remove('is-favorite');
                btn.style.color = '#9ca3af !important';
            }
        });
        
        pendingActions.delete(`favorite_${bookId}`);
        console.error('Error toggling favorite:', error);
        showTopAlert('Error updating favorite: ' + error.message, 'error');
    }
}


       
       // FIXED BULLETPROOF BORROW BOOK - INSTANT UPDATES
    // FIXED BORROW BOOK FUNCTION
async function bulletproofBorrowBook(bookId) {
    if (window.membershipConfig && window.membershipConfig.expired) {
        showTopAlert('Your membership has expired. Please renew your membership to borrow books.', 'warning');
        return;
    }

    if (!confirm('Are you sure you want to borrow this book?')) {
        return;
    }

    
    // Add to pending actions
    pendingActions.add(`borrow_${bookId}`);
    
    // INSTANT VISUAL FEEDBACK
    const bookInLibrary = window.dashboardData.books.find(b => b.book_id == bookId);
    if (bookInLibrary && bookInLibrary.available_copies > 0) {
        bookInLibrary.available_copies--;
        
        const activeSection = document.querySelector('.content-section.active').id;
        if (activeSection === 'library') {
            renderBooks(displayData.books);
        }
    }
    
    showLoading();
    
    try {
        // Create form data
        const formData = new FormData();
        formData.append('book_id', bookId.toString());
        
        console.log('Sending borrow request with FormData:', {
            book_id: bookId.toString(),
            url: '../../api/borrow_book.php'
        });
        
        const response = await fetch('../../api/borrow_book.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin' // Include session cookies
        });

        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();

        if (data.success) {
            showTopAlert(data.message, 'success');
            
            // Add to My Books immediately
            if (bookInLibrary) {
                const newLoan = {
                    book_id: bookInLibrary.book_id,
                    title: bookInLibrary.title,
                    author: bookInLibrary.author,
                    cover_image: bookInLibrary.cover_image,
                    loan_date: new Date().toISOString().split('T')[0],
                    due_date: new Date(Date.now() + 14*24*60*60*1000).toISOString().split('T')[0],
                    approval_status: 'pending',
                    display_status: 'pending'
                };
                
                window.dashboardData.myBooks.unshift(newLoan);
                displayData.myBooks = sortBooks([...window.dashboardData.myBooks], sortSettings.myBooks);
                forceUpdateAllBadges();
            }
            
            pendingActions.delete(`borrow_${bookId}`);
            
            // Close modal if open
            const modal = bootstrap.Modal.getInstance(document.getElementById('bookDetailsModal'));
            if (modal) {
                modal.hide();
            }
            
        } else {
            // Revert the optimistic update
            if (bookInLibrary) {
                bookInLibrary.available_copies++;
                const activeSection = document.querySelector('.content-section.active').id;
                if (activeSection === 'library') {
                    renderBooks(displayData.books);
                }
            }
            pendingActions.delete(`borrow_${bookId}`);
            showTopAlert(data.message || 'Failed to borrow book', 'error');
        }
    } catch (error) {
        // Revert the optimistic update
        if (bookInLibrary) {
            bookInLibrary.available_copies++;
            const activeSection = document.querySelector('.content-section.active').id;
            if (activeSection === 'library') {
                renderBooks(displayData.books);
            }
        }
        pendingActions.delete(`borrow_${bookId}`);
        console.error('Error borrowing book:', error);
        showTopAlert('Error borrowing book: ' + error.message, 'error');
    }
    
    hideLoading();
}


       //  Navigation functionality
       document.addEventListener('DOMContentLoaded', function() {
           // Handle navigation
           document.querySelectorAll('.nav-link').forEach(link => {
               link.addEventListener('click', function(e) {
                   e.preventDefault();
                   
                   // Remove active class from all nav links
                   document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
                   
                   // Add active class to clicked link
                   this.classList.add('active');
                   
                   // Hide all sections
                   document.querySelectorAll('.content-section').forEach(section => {
                       section.classList.remove('active');
                   });
                   
                   // Show target section
                   const targetSection = this.getAttribute('data-section');
                   document.getElementById(targetSection).classList.add('active');
                   
                   // Close mobile sidebar
                   if (window.innerWidth <= 768) {
                       document.getElementById('sidebar').classList.remove('show');
                   }
                   
                   // Render data for the section
                   renderCurrentSection(targetSection);
               });
           });

           // Search functionality for all sections
           setupSearchFunctionality();

           // Alphabet filter
           document.querySelectorAll('.alphabet-btn').forEach(btn => {
               btn.addEventListener('click', function() {
                   document.querySelectorAll('.alphabet-btn').forEach(b => b.classList.remove('active'));
                   this.classList.add('active');
                   
                   const letter = this.getAttribute('data-letter');
                   if (letter === 'All') {
                       displayData.books = sortBooks([...window.dashboardData.books], sortSettings.library);
                   } else {
                       displayData.books = sortBooks(window.dashboardData.books.filter(book => 
                           book.title.charAt(0).toUpperCase() === letter
                       ), sortSettings.library);
                   }
                   renderBooks(displayData.books);
               });
           });

           // View toggle for all sections
           document.querySelectorAll('.view-btn').forEach(btn => {
               btn.addEventListener('click', function() {
                   const target = this.getAttribute('data-target') || 'books';
                   const view = this.getAttribute('data-view');
                   
                   // Update active state for buttons with same target
                   document.querySelectorAll(`.view-btn[data-target="${target}"]`).forEach(b => b.classList.remove('active'));
                   this.classList.add('active');
                   
                   // Get the appropriate grid
                   let gridId = 'booksGrid';
                   if (target === 'books') gridId = 'booksGrid';
                   else if (target === 'myBooks') gridId = 'myBooksGrid';
                   else if (target === 'history') gridId = 'historyGrid';
                   else if (target === 'favorites') gridId = 'favoritesGrid';
                   
                   const grid = document.getElementById(gridId);
                   
                   if (view === 'list') {
                       grid.classList.add('list-view');
                   } else {
                       grid.classList.remove('list-view');
                   }
               });
           });

           // Initialize BULLETPROOF data refresh system
           startBulletproofDataRefresh();

           // Set up profile form
           document.getElementById('profileForm').addEventListener('submit', updateProfile);

           // Initial render with sort
           displayData.books = sortBooks(displayData.books, sortSettings.library);
           displayData.myBooks = sortBooks(displayData.myBooks, sortSettings.myBooks);
           displayData.history = sortBooks(displayData.history, sortSettings.history);
           displayData.favorites = sortBooks(displayData.favorites, sortSettings.favorites);

           forceUpdateAllBadges();
           renderBooks(displayData.books);
           

       });

       // Setup search functionality for all sections
       function setupSearchFunctionality() {
           // Library search
           document.getElementById('searchBooks').addEventListener('input', function() {
               const query = this.value.toLowerCase();
               if (query.length === 0) {
                   displayData.books = sortBooks([...window.dashboardData.books], sortSettings.library);
               } else {
                   displayData.books = sortBooks(window.dashboardData.books.filter(book => 
                       book.title.toLowerCase().includes(query) ||
                       book.author.toLowerCase().includes(query) ||
                       (book.genre && book.genre.toLowerCase().includes(query)) ||
                       (book.dewey_decimal_number && book.dewey_decimal_number.includes(query))
                   ), sortSettings.library);
               }
               renderBooks(displayData.books);
           });

           // My Books search
           document.getElementById('searchMyBooks').addEventListener('input', function() {
               const query = this.value.toLowerCase();
               if (query.length === 0) {
                   displayData.myBooks = sortBooks([...window.dashboardData.myBooks], sortSettings.myBooks);
               } else {
                   displayData.myBooks = sortBooks(window.dashboardData.myBooks.filter(book => 
                       book.title.toLowerCase().includes(query) ||
                       book.author.toLowerCase().includes(query) ||
                       book.display_status.toLowerCase().includes(query)
                   ), sortSettings.myBooks);
               }
               renderMyBooks(displayData.myBooks);
           });

           // History search
           document.getElementById('searchHistory').addEventListener('input', function() {
               const query = this.value.toLowerCase();
               if (query.length === 0) {
                   displayData.history = sortBooks([...window.dashboardData.history], sortSettings.history);
               } else {
                   displayData.history = sortBooks(window.dashboardData.history.filter(book => 
                       book.title.toLowerCase().includes(query) ||
                       book.author.toLowerCase().includes(query)
                   ), sortSettings.history);
               }
               renderHistory(displayData.history);
           });

           // Favorites search
           document.getElementById('searchFavorites').addEventListener('input', function() {
               const query = this.value.toLowerCase();
               if (query.length === 0) {
                   displayData.favorites = sortBooks([...window.dashboardData.favorites], sortSettings.favorites);
               } else {
                   displayData.favorites = sortBooks(window.dashboardData.favorites.filter(book => 
                       book.title.toLowerCase().includes(query) ||
                       book.author.toLowerCase().includes(query) ||
                       (book.genre && book.genre.toLowerCase().includes(query))
                   ), sortSettings.favorites);
               }
               renderFavorites(displayData.favorites);
           });
       }

       //  Mobile sidebar toggle
       function toggleSidebar() {
           const sidebar = document.getElementById('sidebar');
           sidebar.classList.toggle('show');
       }

       // Close sidebar when clicking outside on mobile
       document.addEventListener('click', function(e) {
           if (window.innerWidth <= 768) {
               const sidebar = document.getElementById('sidebar');
               const toggle = document.querySelector('.mobile-menu-toggle');
               
               if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
                   sidebar.classList.remove('show');
               }
           }
       });
       
       function getBookCoverUrl(coverImage, defaultPath = '../../assets/images/default-book.jpg') {
    if (coverImage) {
        // Check if it's a full URL (starts with http:// or https://)
        if (coverImage.startsWith('http://') || coverImage.startsWith('https://')) {
            return coverImage;
        } else {
            // It's a local filename, prepend the local path
            return `../../assets/images/books/${coverImage}`;
        }
    }
    return defaultPath;
}

       // FIXED BOOKS RENDERING WITH PERFECT GRAY TO RED HEART CONCEPT
       function renderBooks(books) {
           const grid = document.getElementById('booksGrid');
           
           if (books.length === 0) {
               grid.innerHTML = `
                   <div class="no-data">
                       <i class="fas fa-book"></i>
                       <h3>No books found</h3>
                       <p>Try adjusting your search or filter criteria</p>
                   </div>
               `;
               return;
           }

           grid.innerHTML = books.map(book => {
               // Check if book is ACTUALLY in favorites array
               const isInFavorites = window.dashboardData.favorites.some(fav => fav.book_id == book.book_id);
               
               return `
<div class="book-card" onclick="showBookDetails(${book.book_id})">
    <div class="book-cover">
        ${book.cover_image ? 
            `<img src="${getBookCoverUrl(book.cover_image)}" alt="${book.title}" onerror="this.src='../../assets/images/default-book.jpg'">` :
            `<img src="../../assets/images/default-book.jpg" alt="${book.title}">`
        }
    </div>
                                               <div class="book-info">
                           <h3 class="book-title">${book.title}</h3>
                           <p class="book-author">by ${book.author}</p>
                           <div class="book-meta">
                               ${book.genre ? `<span class="meta-item">${book.genre}</span>` : ''}
                               ${book.publication_year ? `<span class="meta-item">${book.publication_year}</span>` : ''}
                               ${book.dewey_decimal_number ? `<span class="meta-item">${book.dewey_decimal_number}</span>` : ''}
                               ${book.rack_number ? `<span class="meta-item">📚 ${book.rack_number}</span>` : ''}
                           </div>
                           <div class="book-actions">
                               
                               <button class="btn-borrow" onclick="event.stopPropagation(); bulletproofBorrowBook(${book.book_id})" 
                                       ${book.available_copies <= 0 ? 'disabled' : ''}>
                                   ${book.available_copies > 0 ? 'Borrow' : 'Unavailable'}
                               </button>
                               <button class="btn-favorite ${isInFavorites ? 'is-favorite' : ''}" 
                                       onclick="event.stopPropagation(); bulletproofToggleFavorite(${book.book_id}, this)" 
                                       data-book-id="${book.book_id}"
                                       style="color: ${isInFavorites ? '#ef4444' : '#9ca3af'} !important;">
                                   <i class="fas fa-heart"></i>
                               </button>
                           </div>
                       </div>
                   </div>
               `;
           }).join('');
       }

       function renderMyBooks(books) {
           const grid = document.getElementById('myBooksGrid');
           
           if (books.length === 0) {
               grid.innerHTML = `
                   <div class="no-data">
                       <i class="fas fa-book-reader"></i>
                       <h3>No borrowed books</h3>
                       <p>You haven't borrowed any books yet</p>
                   </div>
               `;
               return;
           }

           grid.innerHTML = books.map(book => `
<div class="book-card" onclick="showBookDetails(${book.book_id})">
    <div class="book-cover">
        ${book.cover_image ? 
            `<img src="${getBookCoverUrl(book.cover_image)}" alt="${book.title}" onerror="this.src='../../assets/images/default-book.jpg'">` :
            `<img src="../../assets/images/default-book.jpg" alt="${book.title}">`
        }
    </div>
                   <div class="book-info">
                       <h3 class="book-title">${book.title}</h3>
                       <p class="book-author">by ${book.author}</p>
                       <div class="book-meta">
                           <span class="status-badge ${book.display_status}">
                               ${book.display_status.charAt(0).toUpperCase() + book.display_status.slice(1)}
                           </span>
                           ${book.due_date ? `<span class="meta-item">Due: ${new Date(book.due_date).toLocaleDateString()}</span>` : ''}
                           ${book.rack_number ? `<span class="meta-item">📚 ${book.rack_number}</span>` : ''}
                       </div>
                       ${book.fine_amount > 0 ? `<div class="alert alert-warning">Fine: ${book.fine_amount}</div>` : ''}
                   </div>
               </div>
           `).join('');
       }

       function renderHistory(books) {
           const grid = document.getElementById('historyGrid');
           
           if (books.length === 0) {
               grid.innerHTML = `
                   <div class="no-data">
                       <i class="fas fa-history"></i>
                       <h3>No reading history</h3>
                       <p>Your reading history will appear here</p>
                   </div>
               `;
               return;
           }

           grid.innerHTML = books.map(book => {
               // Check if book is ACTUALLY in favorites array
               const isInFavorites = window.dashboardData.favorites.some(fav => fav.book_id == book.book_id);
               
               return `
<div class="book-card" onclick="showBookDetails(${book.book_id})">
    <div class="book-cover">
        ${book.cover_image ? 
            `<img src="${getBookCoverUrl(book.cover_image)}" alt="${book.title}" onerror="this.src='../../assets/images/default-book.jpg'">` :
            `<img src="../../assets/images/default-book.jpg" alt="${book.title}">`
        }
    </div>
                       <div class="book-info">
                           <h3 class="book-title">${book.title}</h3>
                           <p class="book-author">by ${book.author}</p>
                           <div class="book-meta">
                               <span class="meta-item">Returned: ${book.return_date ? new Date(book.return_date).toLocaleDateString() : 'N/A'}</span>
                               ${book.dewey_decimal_number ? `<span class="meta-item">${book.dewey_decimal_number}</span>` : ''}
                           </div>
                           <div class="book-actions">
                               
                               <button class="btn-borrow" onclick="event.stopPropagation(); bulletproofBorrowBook(${book.book_id})">
                                   Borrow Again
                               </button>
                               <button class="btn-favorite ${isInFavorites ? 'is-favorite' : ''}" 
                                       onclick="event.stopPropagation(); bulletproofToggleFavorite(${book.book_id}, this)" 
                                       data-book-id="${book.book_id}"
                                       style="color: ${isInFavorites ? '#ef4444' : '#9ca3af'} !important;">
                                   <i class="fas fa-heart"></i>
                               </button>
                           </div>
                       </div>
                   </div>
               `;
           }).join('');
       }

       // FIXED - Favorites rendering without "currently borrowed" text
       function renderFavorites(books) {
           const grid = document.getElementById('favoritesGrid');
           
           if (books.length === 0) {
               grid.innerHTML = `
                   <div class="no-data">
                       <i class="fas fa-heart"></i>
                       <h3>No favorite books</h3>
                       <p>Books you mark as favorites will appear here</p>
                   </div>
               `;
               return;
           }

           grid.innerHTML = books.map(book => `
<div class="book-card" onclick="showBookDetails(${book.book_id})">
    <div class="book-cover">
        ${book.cover_image ? 
            `<img src="${getBookCoverUrl(book.cover_image)}" alt="${book.title}" onerror="this.src='../../assets/images/default-book.jpg'">` :
            `<img src="../../assets/images/default-book.jpg" alt="${book.title}">`
        }
    </div>
                   <div class="book-info">
                       <h3 class="book-title">${book.title}</h3>
                       <p class="book-author">by ${book.author}</p>
                       <div class="book-meta">
                           <span class="meta-item">Added: ${new Date(book.added_date).toLocaleDateString()}</span>
                           ${book.available_copies > 0 ? 
                               `<span class="meta-item">✅ Available</span>` : 
                               `<span class="meta-item">📝 Unavailable</span>`
                           }
                       </div>
                       <div class="book-actions">
                           
                           <button class="btn-borrow" onclick="event.stopPropagation(); bulletproofBorrowBook(${book.book_id})" 
                                   ${book.available_copies <= 0 ? 'disabled' : ''}>
                               ${book.available_copies > 0 ? 'Borrow' : 'Unavailable'}
                           </button>
                           <button class="btn-favorite is-favorite" 
                                   onclick="event.stopPropagation(); bulletproofToggleFavorite(${book.book_id}, this)" 
                                   data-book-id="${book.book_id}"
                                   style="color: #ef4444 !important;">
                               <i class="fas fa-heart"></i>
                           </button>
                       </div>
                   </div>
               </div>
           `).join('');
       }

       //  Notifications Rendering
       function renderNotifications(notifications) {
           const container = document.getElementById('notificationsContainer');
           
           if (notifications.length === 0) {
               container.innerHTML = `
                   <div class="no-data">
                       <i class="fas fa-bell"></i>
                       <h3>No notifications</h3>
                       <p>You're all caught up!</p>
                   </div>
               `;
               return;
           }

           container.innerHTML = notifications.map(notification => `
               <div class="notification-item ${!notification.is_read ? 'unread' : ''}" id="notification-${notification.notification_id}">
                   <div class="notification-header" onclick="markAsReadAndToggle(${notification.notification_id})">
                       <div class="notification-icon ${notification.type}">
                           <i class="fas fa-${getNotificationIcon(notification.type)}"></i>
                       </div>
                       <h6>${notification.title}</h6>
                       <div class="notification-meta">
                           <span class="notification-time">
                               <i class="fas fa-clock me-1"></i>
                               ${formatTime(notification.created_at)}
                           </span>
                           <div class="notification-actions">
                               <button class="delete-btn" onclick="event.stopPropagation(); deleteNotification(${notification.notification_id})" title="Delete">
                                   <i class="fas fa-trash"></i>
                               </button>
                               <i class="fas fa-chevron-down notification-toggle"></i>
                           </div>
                       </div>
                   </div>  
                   <div class="notification-body" id="notification-body-${notification.notification_id}">
                       <div class="notification-message">${notification.message}</div>
                       <div class="notification-body-actions">
                           <button class="btn-notification danger" onclick="deleteNotification(${notification.notification_id})">
                               <i class="fas fa-trash me-1"></i>Delete
                           </button>
                       </div>
                   </div>
               </div>
           `).join('');
       }

       function getNotificationIcon(type) {
           switch(type) {
               case 'success': return 'check-circle';
               case 'warning': return 'exclamation-triangle';
               case 'danger': return 'exclamation-triangle';
               default: return 'info-circle';
           }
       }

       function formatTime(timestamp) {
           const date = new Date(timestamp);
           const now = new Date();
           const diffInHours = Math.floor((now - date) / (1000 * 60 * 60));
           
           if (diffInHours < 1) {
               const diffInMinutes = Math.floor((now - date) / (1000 * 60));
               return diffInMinutes < 1 ? 'Just now' : `${diffInMinutes}m ago`;
           } else if (diffInHours < 24) {
               return `${diffInHours}h ago`;
           } else {
               return date.toLocaleDateString();
           }
       }

       // Notification Functions
       function markAsReadAndToggle(notificationId) {
           const notification = window.dashboardData.notifications.find(n => n.notification_id == notificationId);
           
           // Auto mark as read when clicking
           if (notification && !notification.is_read) {
               markAsRead(notificationId, false); // false means don't show alert
           }
           
           // Toggle the notification body
           toggleNotification(notificationId);
       }

       function toggleNotification(notificationId) {
           const body = document.getElementById(`notification-body-${notificationId}`);
           const toggle = document.querySelector(`#notification-${notificationId} .notification-toggle`);
           
           if (body.classList.contains('expanded')) {
               body.classList.remove('expanded');
               toggle.style.transform = 'rotate(0deg)';
           } else {
               // Close all other notifications
               document.querySelectorAll('.notification-body.expanded').forEach(nb => {
                   nb.classList.remove('expanded');
               });
               document.querySelectorAll('.notification-toggle').forEach(nt => {
                   nt.style.transform = 'rotate(0deg)';
               });
               
               // Open this notification
               body.classList.add('expanded');
               toggle.style.transform = 'rotate(180deg)';
           }
       }

       async function markAsRead(notificationId, showAlert = true) {
           try {
               const formData = new FormData();
               formData.append('notification_id', notificationId);
               
               const response = await fetch('../../api/mark_notification_read.php', {
                   method: 'POST',
                   body: formData
               });

               const data = await response.json();

               if (data.success) {
                   // Update notification in array IMMEDIATELY
                   const notification = window.dashboardData.notifications.find(n => n.notification_id == notificationId);
                   if (notification) {
                       notification.is_read = true;
                   }
                   
                   displayData.notifications = [...window.dashboardData.notifications];
                   
                   // Force immediate badge update
                   forceUpdateAllBadges();
                   
                   // Re-render notifications immediately
                   renderNotifications(displayData.notifications);
                   
                   if (showAlert) {
                       showTopAlert('Notification marked as read', 'success');
                   }
               } else {
                   if (showAlert) {
                       showTopAlert('Failed to mark notification as read', 'error');
                   }
               }
           } catch (error) {
               console.error('Error marking notification as read:', error);
               if (showAlert) {
                   showTopAlert('Error updating notification', 'error');
               }
           }
       }

       // FIXED - Delete notification function
       async function deleteNotification(notificationId) {
           if (!confirm('Are you sure you want to delete this notification?')) {
               return;
           }

           try {
               const formData = new FormData();
               formData.append('notification_id', notificationId);
               
               const response = await fetch('../../api/delete_notification.php', {
                   method: 'POST',
                   body: formData
               });

               const data = await response.json();

               if (data.success) {
                   // Remove notification from array IMMEDIATELY
                   window.dashboardData.notifications = window.dashboardData.notifications.filter(n => n.notification_id != notificationId);
                   displayData.notifications = [...window.dashboardData.notifications];
                   
                   // Force immediate badge update
                   forceUpdateAllBadges();
                   
                   // Re-render notifications immediately
                   renderNotifications(displayData.notifications);
                   
                   showTopAlert('Notification deleted successfully', 'success');
               } else {
                   showTopAlert(data.message || 'Failed to delete notification', 'error');
               }
           } catch (error) {
               console.error('Error deleting notification:', error);
               showTopAlert('Error deleting notification', 'error');
           }
       }

       async function markAllAsRead() {
           const unreadNotifications = window.dashboardData.notifications.filter(n => !n.is_read);
           
           if (unreadNotifications.length === 0) {
               showTopAlert('No unread notifications found', 'info');
               return;
           }

           if (!confirm(`Mark all ${unreadNotifications.length} notifications as read?`)) {
               return;
           }

           showLoading();

           try {
               const response = await fetch('../../api/mark_all_notifications_read.php', {
                   method: 'POST',
                   headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                   body: `member_id=${window.dashboardData.userId}`
               });

               const data = await response.json();

               if (data.success) {
                   // Update all notifications as read IMMEDIATELY
                   window.dashboardData.notifications.forEach(n => n.is_read = true);
                   displayData.notifications = [...window.dashboardData.notifications];
                   
                   // Force immediate updates
                   forceUpdateAllBadges();
                   renderNotifications(displayData.notifications);
                   
                   showTopAlert('All notifications marked as read', 'success');
               } else {
                   showTopAlert('Failed to mark all notifications as read', 'error');
               }
           } catch (error) {
               console.error('Error marking all notifications as read:', error);
               showTopAlert('Error updating notifications', 'error');
           }

           hideLoading();
       }

       async function clearAllNotifications() {
           if (window.dashboardData.notifications.length === 0) {
               showTopAlert('No notifications to clear', 'info');  
               return;
           }

           if (!confirm(`Delete all ${window.dashboardData.notifications.length} notifications? This action cannot be undone.`)) {
               return;
           }

           showLoading();

           try {
               const response = await fetch('../../api/clear_all_notifications.php', {
                   method: 'POST',
                   headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                   body: `member_id=${window.dashboardData.userId}`
               });

               const data = await response.json();

               if (data.success) {
                   // Clear all notifications IMMEDIATELY
                   window.dashboardData.notifications = [];
                   displayData.notifications = [];
                   
                   // Force immediate updates
                   forceUpdateAllBadges();
                   renderNotifications(displayData.notifications);
                   
                   showTopAlert('All notifications cleared successfully', 'success');
               } else {
                   showTopAlert('Failed to clear notifications', 'error');
               }
           } catch (error) {
               console.error('Error clearing notifications:', error);
               showTopAlert('Error clearing notifications', 'error');
           }

           hideLoading();
       }

       // Book Details Modal
       function showBookDetails(bookId, loanStatus = null) {
           const book = window.dashboardData.books.find(b => b.book_id == bookId) ||
                       window.dashboardData.myBooks.find(b => b.book_id == bookId) ||
                       window.dashboardData.history.find(b => b.book_id == bookId) ||
                       window.dashboardData.favorites.find(b => b.book_id == bookId);
           
           if (book) {
               const modal = new bootstrap.Modal(document.getElementById('bookDetailsModal'));
               const availableCopies = parseInt(book.available_copies) || 0;
               const isAvailable = availableCopies > 0;
               
               // Check if book is ACTUALLY in favorites array
               const isFavorite = window.dashboardData.favorites.some(fav => fav.book_id == book.book_id);
               
               // Build location information
               let locationInfo = '';
               if (book.dewey_decimal_number || book.rack_number || book.floor_level) {
                   locationInfo = `
                       <div class="location-section">
                           <h6 class="fw-bold text-primary mb-3">
                               <i class="fas fa-map-marker-alt me-2"></i>Location in Library
                           </h6>
                           <div class="location-grid">`;
                   
                   if (book.dewey_decimal_number) {
                       locationInfo += `
                           <div class="location-item">
                               <div class="location-label">Dewey Decimal</div>
                               <div class="location-value dewey-decimal">${book.dewey_decimal_number}</div>
                           </div>`;
                   }
                   
                   if (book.dewey_classification) {
                       locationInfo += `
                           <div class="location-item">
                               <div class="location-label">Classification</div>
                               <div class="location-value classification">${book.dewey_classification}</div>
                           </div>`;
                   }
                   
                   if (book.floor_level) {
                       locationInfo += `
                           <div class="location-item">
                               <div class="location-label">Floor</div>
                               <div class="location-value floor">Level ${book.floor_level}</div>
                           </div>`;
                   }
                   
                   if (book.rack_number) {
                       locationInfo += `
                           <div class="location-item">
                               <div class="location-label">Rack Number</div>
                               <div class="location-value rack">${book.rack_number}</div>
                           </div>`;
                   }
                   
                   if (book.shelf_position) {
                       locationInfo += `
                           <div class="location-item">
                               <div class="location-label">Shelf Position</div>
                               <div class="location-value shelf">${book.shelf_position}</div>
                           </div>`;
                   }
                   
                   locationInfo += `</div></div>`;
               }
               
               let actionButtons = '';
               
               if (loanStatus === 'pending') {
                   actionButtons = `
                       <div class="alert alert-warning">
                           <i class="fas fa-clock me-2"></i>This book reservation is pending approval.
                       </div>`;
               } else if (loanStatus === 'overdue') {
                   actionButtons = `
                       <div class="alert alert-danger">
                           <i class="fas fa-exclamation-triangle me-2"></i>This book is overdue. Please return it as soon as possible.
                       </div>`;
               } else if (loanStatus === 'active') {
                   actionButtons = `
                       <div class="alert alert-info">
                           <i class="fas fa-book-open me-2"></i>You are currently reading this book.
                       </div>`;
               } else if (window.membershipConfig && window.membershipConfig.expired) {
                   actionButtons = `
                       <div class="alert alert-danger">
                           <i class="fas fa-exclamation-triangle me-2"></i>Your membership has expired. Please renew to continue borrowing books.
                       </div>
                       <button class="btn btn-danger me-2" onclick="contactLibrary()">
                           <i class="fas fa-phone me-1"></i>Contact for Renewal
                       </button>`;
               } else {
                   actionButtons = `
                       <div class="d-flex gap-2">
                           <button class="btn btn-outline-danger ${isFavorite ? 'is-favorite' : ''}" 
                                   onclick="bulletproofToggleFavorite(${book.book_id}, this)" 
                                   data-book-id="${book.book_id}"
                                   style="color: ${isFavorite ? '#ef4444' : '#9ca3af'} !important;">
                               <i class="fas fa-heart me-1"></i>${isFavorite ? 'Remove from Favorites' : 'Add to Favorites'}
                           </button>
                           <button class="btn btn-primary" onclick="bulletproofBorrowBook(${book.book_id})" ${!isAvailable ? 'disabled' : ''}>
                               <i class="fas fa-book me-1"></i>${isAvailable ? 'Borrow Book' : 'Unavailable'}
                           </button>
                       </div>`;
               }
               
               document.getElementById('bookDetailsContent').innerHTML = `
                   <div class="row">
<div class="col-lg-4">
    <div class="book-detail-cover text-center">
        ${book.cover_image ? 
            `<img src="${book.cover_image.startsWith('http') ? book.cover_image : '../../assets/images/books/' + book.cover_image}" 
                  alt="${book.title}" 
                  class="img-fluid rounded" 
                  style="max-height: 350px; max-width: 100%;" 
                  onerror="this.src='../../assets/images/default-book.jpg'">` :
            `<img src="../../assets/images/default-book.jpg" 
                  alt="${book.title}" 
                  class="img-fluid rounded" 
                  style="max-height: 350px; max-width: 100%;">`
        }
        <div class="availability-badge ${isAvailable ? 'available' : 'unavailable'}">
            ${isAvailable ? `✓ Available (${availableCopies} copies)` : '✗ Not Available'}
        </div>
    </div>
</div>
                       <div class="col-lg-8">
                           <div class="book-detail-info">
                               <h3 class="book-detail-title mb-2">${book.title}</h3>
                               <h5 class="book-detail-author text-muted mb-3">by ${book.author}</h5>
                               
                               <div class="book-detail-rating mb-3">
                                   ${renderStars(book.rating || 0)}
                                   <span class="ms-2">${book.rating || 'No rating'} / 5</span>
                               </div>
                               
                               <div class="book-detail-meta mb-3">
                                   <div class="row g-2">
                                       <div class="col-md-6">
                                           <strong>Book ID:</strong> ${book.book_id}
                                       </div>
                                       <div class="col-md-6">
                                           <strong>Publisher:</strong> ${book.publisher || 'Unknown'}
                                       </div>
                                       <div class="col-md-6">
                                           <strong>Year:</strong> ${book.publication_year || 'Unknown'}
                                       </div>
                                       <div class="col-md-6">
                                           <strong>ISBN:</strong> ${book.isbn || 'Not available'}
                                       </div>
                                       <div class="col-md-6">
                                           <strong>Genre:</strong> ${book.genre || 'General'}
                                       </div>
                                       <div class="col-md-6">
                                           <strong>Pages:</strong> ${book.pages || 'Unknown'}
                                       </div>
                                   </div>
                               </div>
                               
                               ${locationInfo}
                               
                               <div class="book-detail-description mb-4">
                                   <h6>Description:</h6>
                                   <p class="text-muted">${book.description || 'No description available for this book.'}</p>
                               </div>
                               
                               <div class="book-detail-actions">
                                   ${actionButtons}
                               </div>
                           </div>
                       </div>
                   </div>`;
               
               modal.show();
           } else {
               showTopAlert('Book details not found', 'error');
           }
       }

       function renderStars(rating) {
           const fullStars = Math.floor(rating);
           const hasHalfStar = rating % 1 >= 0.5;
           const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);
           
           let stars = '';
           for (let i = 0; i < fullStars; i++) {
               stars += '<i class="fas fa-star text-warning"></i>';
           }
           if (hasHalfStar) {
               stars += '<i class="fas fa-star-half-alt text-warning"></i>';
           }
           for (let i = 0; i < emptyStars; i++) {
               stars += '<i class="far fa-star text-warning"></i>';
           }
           return stars;
       }

       // FIXED Profile form submission with proper validation
       async function updateProfile(event) {
    event.preventDefault();
    
    
    // Get form values directly from the form elements
    const form = event.target;
    const firstName = form.querySelector('#firstName').value.trim();
    const lastName = form.querySelector('#lastName').value.trim();
    const email = form.querySelector('#email').value.trim();
    const phone = form.querySelector('#phone').value.trim();
    const address = form.querySelector('#address').value.trim();
    
    
    // Client-side validation
    if (!firstName || !lastName || !email) {
        showTopAlert('First name, last name, and email are required', 'error');
        return;
    }
    
    // Email validation
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        showTopAlert('Please enter a valid email address', 'error');
        return;
    }
    
    showLoading();
    
    try {
        // Create form data
        const formData = new FormData();
        formData.append('first_name', firstName);
        formData.append('last_name', lastName);
        formData.append('email', email);
        formData.append('phone', phone);
        formData.append('address', address);
        
        console.log('Sending profile update with FormData:', {
            first_name: firstName,
            last_name: lastName,
            email: email,
            phone: phone,
            address: address,
            url: '../../api/update_profile.php'
        });
        
        const response = await fetch('../../api/update_profile.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin' // Include session cookies
        });

        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();

        if (data.success) {
            showTopAlert(data.message || 'Profile updated successfully!', 'success');
            
            // Update session display if returned
            if (data.updated_name) {
                const userNameElement = document.querySelector('.user-details h6');
                if (userNameElement) {
                    userNameElement.textContent = data.updated_name;
                }
            }
        } else {
            showTopAlert(data.message || 'Failed to update profile', 'error');
        }
    } catch (error) {
        console.error('Error updating profile:', error);
        showTopAlert('Error updating profile: ' + error.message, 'error');
    }
    
    hideLoading();
}



       function resetProfileForm() {
           document.getElementById('firstName').value = '<?php echo htmlspecialchars($member['first_name'] ?? ''); ?>';
           document.getElementById('lastName').value = '<?php echo htmlspecialchars($member['last_name'] ?? ''); ?>';
           document.getElementById('email').value = '<?php echo htmlspecialchars($member['email'] ?? ''); ?>';
           document.getElementById('phone').value = '<?php echo htmlspecialchars($member['phone'] ?? ''); ?>';
           document.getElementById('address').value = '<?php echo htmlspecialchars($member['address'] ?? ''); ?>';
       }

       function contactLibrary() {
        alert("Please contact the library at +1 (555) 123-4567 or info@esssllibrary.com for membership renewal.")
       }

       // FIXED Initialize Charts
 // FIXED Initialize Charts - Proper Error Handling
function initializeCharts() {
    // Safely destroy existing charts if they exist
    if (window.monthlyChart && typeof window.monthlyChart.destroy === 'function') {
        try {
            window.monthlyChart.destroy();
        } catch (error) {
        }
        window.monthlyChart = null;
    }
    
    if (window.genreChart && typeof window.genreChart.destroy === 'function') {
        try {
            window.genreChart.destroy();
        } catch (error) {
        }
        window.genreChart = null;
    }

    // Monthly Reading Chart
    const monthlyCtx = document.getElementById('monthlyChart');
    if (monthlyCtx) {
        try {
            // Prepare data for last 12 months
            const months = [];
            const counts = [];
            const now = new Date();
            
            for (let i = 11; i >= 0; i--) {
                const date = new Date(now.getFullYear(), now.getMonth() - i, 1);
                const monthKey = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');
                const monthName = date.toLocaleDateString('en-US', { month: 'short', year: '2-digit' });
                
                months.push(monthName);
                
                const monthData = window.dashboardData.monthlyReading.find(m => m.month === monthKey);
                counts.push(monthData ? parseInt(monthData.books_count) : 0);
            }

            window.monthlyChart = new Chart(monthlyCtx, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'Books Read',
                        data: counts,
                        borderColor: '#78938A',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#78938A',
                        pointBorderColor: '#78938A',
                        pointHoverBackgroundColor: '#5a67d8',
                        pointHoverBorderColor: '#5a67d8'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                color: '#6b7280'
                            },
                            grid: {
                                color: 'rgba(107, 114, 128, 0.1)'
                            }
                        },
                        x: {
                            ticks: {
                                color: '#6b7280'
                            },
                            grid: {
                                color: 'rgba(107, 114, 128, 0.1)'
                            }
                        }
                    }
                }
            });
        } catch (error) {
            console.error('Error creating monthly chart:', error);
            monthlyCtx.parentElement.innerHTML = '<p class="text-muted text-center">Unable to load monthly reading chart</p>';
        }
    }

    // Genre Pie Chart
    const genreCtx = document.getElementById('genreChart');
    if (genreCtx && window.dashboardData.favoriteGenres && window.dashboardData.favoriteGenres.length > 0) {
        try {
            const genres = window.dashboardData.favoriteGenres.map(g => g.genre);
            const counts = window.dashboardData.favoriteGenres.map(g => parseInt(g.count));
            
            const colors = [
                '#78938A', '#92BA92', '#f093fb', '#f5576c', '#4facfe',
                '#43e97b', '#38ef7d', '#ff9a9e', '#fecfef', '#ffecd2'
            ];

            window.genreChart = new Chart(genreCtx, {
                type: 'doughnut',
                data: {
                    labels: genres,
                    datasets: [{
                        data: counts,
                        backgroundColor: colors.slice(0, genres.length),
                        borderWidth: 2,
                        borderColor: '#fff',
                        hoverBorderWidth: 3,
                        hoverBorderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                color: '#6b7280'
                            }
                        }
                    }
                }
            });
        } catch (error) {
            console.error('Error creating genre chart:', error);
            genreCtx.parentElement.innerHTML = '<p class="text-muted text-center">Unable to load genre chart</p>';
        }
    } else if (genreCtx) {
        // Show message when no genre data
        genreCtx.parentElement.innerHTML = '<p class="text-muted text-center">No genre data available<br><small>Start reading books to see your favorite genres</small></p>';
    }
}

       // Utility functions
       function showLoading() {
           document.getElementById('loadingOverlay').classList.remove('d-none');
       }

       function hideLoading() {
           document.getElementById('loadingOverlay').classList.add('d-none');
       }

       // Handle page visibility for auto-refresh
       document.addEventListener('visibilitychange', function() {
           if (document.hidden) {
               clearInterval(liveUpdateInterval);
           } else {
               startBulletproofDataRefresh();
               bulletproofRefreshAllData(); // Immediate refresh when page becomes visible
           }
       });

       // Clean up intervals when page unloads
       window.addEventListener('beforeunload', function() {
           if (liveUpdateInterval) {
               clearInterval(liveUpdateInterval);
           }
       });



       // Add this to your dashboard JavaScript to check for overdue books every hour
setInterval(async () => {
    try {
        const response = await fetch('../../api/notification_system.php');
        const data = await response.json();
    } catch (error) {
        console.error('Notification system error:', error);
    }
}, 3600000); // Run every hour
   </script>


   
</body>
</html>