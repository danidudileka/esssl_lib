<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';

// Simple admin check (you can enhance this later)
if (!isset($_SESSION['admin_logged_in'])) {
    // For now, allow access for testing
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_name'] = 'Admin';
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Handle approval/rejection
    if ($_POST['action'] ?? '' === 'update_status') {
        $reservation_id = $_POST['reservation_id'];
        $status = $_POST['status']; // 'approved' or 'rejected'
        $notes = $_POST['notes'] ?? '';
        
        // Update reservation status
        $stmt = $db->prepare("
            UPDATE book_reservations 
            SET status = ?, approved_by = 1, approved_date = NOW(), notes = ?
            WHERE reservation_id = ?
        ");
        $stmt->execute([$status, $notes, $reservation_id]);
        
        if ($status === 'approved') {
            // Get reservation details
            $stmt = $db->prepare("SELECT member_id, book_id FROM book_reservations WHERE reservation_id = ?");
            $stmt->execute([$reservation_id]);
            $reservation = $stmt->fetch();
            
            // Create book loan record
            $loan_date = date('Y-m-d');
            $due_date = date('Y-m-d', strtotime('+14 days'));
            
            $stmt = $db->prepare("
                INSERT INTO book_loans (reservation_id, member_id, book_id, loan_date, due_date, status, approval_status)
                VALUES (?, ?, ?, ?, ?, 'active', 'approved')
            ");
            $stmt->execute([$reservation_id, $reservation['member_id'], $reservation['book_id'], $loan_date, $due_date]);
            
            // Update book available copies
            $stmt = $db->prepare("UPDATE books SET available_copies = available_copies - 1 WHERE book_id = ?");
            $stmt->execute([$reservation['book_id']]);
        }
        
        // Create notification for member
        $member_stmt = $db->prepare("SELECT member_id FROM book_reservations WHERE reservation_id = ?");
        $member_stmt->execute([$reservation_id]);
        $member_id = $member_stmt->fetch()['member_id'];
        
        $notif_title = $status === 'approved' ? 'Book Reservation Approved' : 'Book Reservation Rejected';
        $notif_message = $status === 'approved' ? 
            'Your book reservation has been approved. Please collect it within 2 days.' :
            'Your book reservation has been rejected. ' . $notes;
        $notif_type = $status === 'approved' ? 'success' : 'danger';
        
        $stmt = $db->prepare("
            INSERT INTO member_notifications (member_id, title, message, type, related_type) 
            VALUES (?, ?, ?, ?, 'reservation')
        ");
        $stmt->execute([$member_id, $notif_title, $notif_message, $notif_type]);
        
        $success_message = "Reservation " . $status . " successfully!";
    }
    
    // Get pending reservations
    $stmt = $db->prepare("
        SELECT br.*, b.title, b.author, b.cover_image, 
               m.first_name, m.last_name, m.email, m.member_code
        FROM book_reservations br
        JOIN books b ON br.book_id = b.book_id
        JOIN members m ON br.member_id = m.member_id
        WHERE br.status = 'pending'
        ORDER BY br.reservation_date ASC
    ");
    $stmt->execute();
    $pending_reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
    $pending_reservations = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Book Loans - ESSSL Library Admin</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
        }
        .admin-header {
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 1rem 0;
            margin-bottom: 2rem;
        }
        .reservation-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .book-cover-small {
            width: 60px;
            height: 80px;
            object-fit: cover;
            border-radius: 6px;
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-pending {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="admin-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="mb-0">📚 ESSSL Library Admin</h3>
                    <small class="text-muted">Book Loan Approvals</small>
                </div>
                <div>
                    <a href="../reader/dashboard/" class="btn btn-outline-primary me-2">
                        <i class="fas fa-user me-1"></i>Reader Panel
                    </a>
                    <a href="../" class="btn btn-outline-secondary">
                        <i class="fas fa-home me-1"></i>Home
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Success/Error Messages -->
        <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h4>Pending Book Reservations</h4>
                        <p class="text-muted mb-0">Review and approve/reject book loan requests</p>
                    </div>
                    <div class="badge bg-warning fs-6">
                        <?php echo count($pending_reservations); ?> Pending
                    </div>
                </div>

                <?php if (empty($pending_reservations)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                    <h5 class="text-muted">No Pending Reservations</h5>
                    <p class="text-muted">All book reservations have been processed!</p>
                </div>
                <?php else: ?>
                    <?php foreach ($pending_reservations as $reservation): ?>
                    <div class="reservation-card">
                        <div class="row align-items-center">
                            <div class="col-md-1">
                                <img src="../assets/images/default-book.jpg" 
                                     alt="<?php echo htmlspecialchars($reservation['title']); ?>" 
                                     class="book-cover-small">
                            </div>
                            <div class="col-md-4">
                                <h6 class="mb-1"><?php echo htmlspecialchars($reservation['title']); ?></h6>
                                <p class="text-muted mb-1">by <?php echo htmlspecialchars($reservation['author']); ?></p>
                                <span class="status-badge status-pending">Pending Approval</span>
                            </div>
                            <div class="col-md-3">
                                <strong>Member:</strong> <?php echo htmlspecialchars($reservation['first_name'] . ' ' . $reservation['last_name']); ?><br>
                                <small class="text-muted">ID: <?php echo htmlspecialchars($reservation['member_code']); ?></small><br>
                                <small class="text-muted"><?php echo htmlspecialchars($reservation['email']); ?></small>
                            </div>
                            <div class="col-md-2">
                                <strong>Requested:</strong><br>
                                <small><?php echo date('M d, Y', strtotime($reservation['reservation_date'])); ?></small><br>
                                <small class="text-muted"><?php echo date('H:i', strtotime($reservation['reservation_date'])); ?></small>
                            </div>
                            <div class="col-md-2">
                                <div class="d-grid gap-2">
                                    <button class="btn btn-success btn-sm" 
                                            onclick="approveReservation(<?php echo $reservation['reservation_id']; ?>, '<?php echo htmlspecialchars($reservation['title']); ?>')">
                                        <i class="fas fa-check me-1"></i>Approve
                                    </button>
                                    <button class="btn btn-danger btn-sm" 
                                            onclick="rejectReservation(<?php echo $reservation['reservation_id']; ?>, '<?php echo htmlspecialchars($reservation['title']); ?>')">
                                        <i class="fas fa-times me-1"></i>Reject
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Approval Modal -->
    <div class="modal fade" id="approvalModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Approve Reservation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="reservation_id" id="reservationId">
                        <input type="hidden" name="status" id="reservationStatus">
                        
                        <div class="alert alert-info">
                            <strong>Book:</strong> <span id="bookTitle"></span>
                        </div>
                        
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" 
                                      placeholder="Add any notes about this decision..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn" id="confirmButton">Confirm</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function approveReservation(reservationId, bookTitle) {
            document.getElementById('modalTitle').textContent = 'Approve Reservation';
            document.getElementById('reservationId').value = reservationId;
            document.getElementById('reservationStatus').value = 'approved';
            document.getElementById('bookTitle').textContent = bookTitle;
            document.getElementById('confirmButton').className = 'btn btn-success';
            document.getElementById('confirmButton').innerHTML = '<i class="fas fa-check me-1"></i>Approve';
            document.getElementById('notes').placeholder = 'Add any approval notes...';
            
            new bootstrap.Modal(document.getElementById('approvalModal')).show();
        }

        function rejectReservation(reservationId, bookTitle) {
            document.getElementById('modalTitle').textContent = 'Reject Reservation';
            document.getElementById('reservationId').value = reservationId;
            document.getElementById('reservationStatus').value = 'rejected';
            document.getElementById('bookTitle').textContent = bookTitle;
            document.getElementById('confirmButton').className = 'btn btn-danger';
            document.getElementById('confirmButton').innerHTML = '<i class="fas fa-times me-1"></i>Reject';
            document.getElementById('notes').placeholder = 'Please provide a reason for rejection...';
            
            new bootstrap.Modal(document.getElementById('approvalModal')).show();
        }
    </script>
</body>
</html>
