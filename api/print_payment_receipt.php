<?php
// File: api/print_payment_receipt.php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo "<script>alert('Access denied. Please login.'); window.close();</script>";
    exit();
}

$payment_id = isset($_GET['payment_id']) ? (int)$_GET['payment_id'] : 0;

if ($payment_id <= 0) {
    echo "<script>alert('Invalid payment ID.'); window.close();</script>";
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get payment details with member and admin info
    $stmt = $db->prepare("
        SELECT p.*, 
               CONCAT(m.first_name, ' ', m.last_name) as member_name,
               m.member_code, m.email, m.phone, m.address, m.membership_type, m.membership_expiry,
               CONCAT(a.full_name) as processed_by_name,
               a.email as admin_email
        FROM payments p
        JOIN members m ON p.member_id = m.member_id
        LEFT JOIN admin a ON p.processed_by = a.admin_id
        WHERE p.payment_id = ?
    ");
    
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch();
    
    if (!$payment) {
        echo "<script>alert('Payment not found.'); window.close();</script>";
        exit();
    }
    
    $payment_date = date('F j, Y g:i A', strtotime($payment['payment_date']));
    $expiry_date = date('F j, Y', strtotime($payment['membership_expiry']));
    
} catch (Exception $e) {
    echo "<script>alert('Error loading payment: " . addslashes($e->getMessage()) . "'); window.close();</script>";
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt - <?php echo str_pad($payment['payment_id'], 6, '0', STR_PAD_LEFT); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            font-family: 'Arial', sans-serif; 
            line-height: 1.6;
            color: #333;
            background: #f8fafc;
            padding: 20px;
        }
        
        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border-radius: 10px;
            overflow: hidden;
        }
        
        .header { 
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
            text-align: center; 
            padding: 30px 20px;
        }
        
        .header h1 { 
            font-size: 2.5em;
            margin-bottom: 10px;
            font-weight: 700;
        }
        
        .header .tagline {
            font-size: 1.1em;
            opacity: 0.9;
            margin-bottom: 20px;
        }
        
        .receipt-number {
            background: rgba(255,255,255,0.2);
            display: inline-block;
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: bold;
            font-size: 1.1em;
        }
        
        .receipt-body {
            padding: 40px;
        }
        
        .payment-summary { 
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border: 2px solid #10b981; 
            border-radius: 15px; 
            padding: 30px; 
            text-align: center; 
            margin-bottom: 40px;
            position: relative;
        }
        
        .payment-summary::before {
            content: '✅';
            position: absolute;
            top: -15px;
            left: 50%;
            transform: translateX(-50%);
            background: #10b981;
            padding: 10px;
            border-radius: 50%;
            font-size: 1.2em;
        }
        
        .amount { 
            font-size: 3em; 
            font-weight: bold; 
            color: #059669;
            margin: 20px 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .payment-type { 
            background: #2563eb; 
            color: white; 
            padding: 12px 24px; 
            border-radius: 25px; 
            display: inline-block; 
            font-weight: bold; 
            text-transform: uppercase; 
            font-size: 0.9em;
            letter-spacing: 1px;
        }
        
        .status-paid { 
            background: #dcfce7; 
            color: #166534; 
            padding: 8px 20px; 
            border-radius: 20px; 
            font-weight: bold; 
            display: inline-block; 
            margin-top: 15px;
            border: 2px solid #10b981;
        }
        
        .info-grid { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 30px; 
            margin-bottom: 30px; 
        }
        
        .info-section {
            background: #f8fafc;
            padding: 25px;
            border-radius: 10px;
            border-left: 4px solid #2563eb;
        }
        
        .info-section h3 { 
            color: #2563eb; 
            margin-bottom: 20px;
            font-size: 1.2em;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-row { 
            display: flex; 
            justify-content: space-between; 
            margin-bottom: 12px; 
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label { 
            font-weight: bold; 
            color: #4b5563; 
            flex: 1;
        }
        
        .info-value { 
            color: #1f2937;
            flex: 1;
            text-align: right;
            font-weight: 500;
        }
        
        .footer { 
            background: #f8fafc;
            text-align: center; 
            padding: 30px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280; 
        }
        
        .footer h4 {
            color: #2563eb;
            margin-bottom: 10px;
        }
        
        .print-actions {
            text-align: center;
            margin: 20px 0;
            padding: 20px;
            background: #f8fafc;
            border-radius: 10px;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 30px;
            margin: 0 10px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            text-decoration: none;
            font-size: 1em;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #2563eb;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1d4ed8;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 6em;
            color: rgba(37, 99, 235, 0.05);
            font-weight: bold;
            pointer-events: none;
            z-index: 1;
        }
        
        @media print {
            body { 
                background: white;
                padding: 0;
            }
            .receipt-container {
                box-shadow: none;
                border-radius: 0;
            }
            .print-actions {
                display: none !important;
            }
            .no-print {
                display: none !important;
            }
        }
        
        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .amount {
                font-size: 2.5em;
            }
            .receipt-body {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="watermark">ESSL LIBRARY</div>
        
        <div class="header">
            <h1>ESSL Library</h1>
            <div class="tagline">Your Gateway to Knowledge</div>
            <div class="receipt-number">
                Receipt #<?php echo str_pad($payment['payment_id'], 6, '0', STR_PAD_LEFT); ?>
            </div>
        </div>
        
        <div class="receipt-body">
            <div class="payment-summary">
                <div class="payment-type"><?php echo ucfirst(str_replace('_', ' ', $payment['payment_type'])); ?></div>
                <div class="amount">LKR <?php echo number_format($payment['amount'], 2); ?></div>
                <div class="status-paid">PAYMENT COMPLETED</div>
            </div>
            
            <div class="info-grid">
                <div class="info-section">
                    <h3>👤 Member Information</h3>
                    <div class="info-row">
                        <span class="info-label">Full Name:</span>
                        <span class="info-value"><?php echo htmlspecialchars($payment['member_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Member Code:</span>
                        <span class="info-value"><?php echo htmlspecialchars($payment['member_code']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Email:</span>
                        <span class="info-value"><?php echo htmlspecialchars($payment['email']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Membership Type:</span>
                        <span class="info-value"><?php echo ucfirst($payment['membership_type']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Expiry Date:</span>
                        <span class="info-value"><?php echo $expiry_date; ?></span>
                    </div>
                </div>
                
                <div class="info-section">
                    <h3>💳 Payment Details</h3>
                    <div class="info-row">
                        <span class="info-label">Payment Date:</span>
                        <span class="info-value"><?php echo $payment_date; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Payment Method:</span>
                        <span class="info-value"><?php echo ucfirst($payment['payment_method']); ?></span>
                    </div>
                    <?php if ($payment['renewal_type']): ?>
                    <div class="info-row">
                        <span class="info-label">Renewal Period:</span>
                        <span class="info-value"><?php echo ucfirst($payment['renewal_type']); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-label">Processed By:</span>
                        <span class="info-value"><?php echo htmlspecialchars($payment['processed_by_name']); ?></span>
                    </div>
                    <?php if ($payment['notes']): ?>
                    <div class="info-row">
                        <span class="info-label">Notes:</span>
                        <span class="info-value"><?php echo htmlspecialchars($payment['notes']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="print-actions no-print">
            <button class="btn btn-primary" onclick="window.print()">
                🖨️ Print Receipt
            </button>
            <button class="btn btn-secondary" onclick="window.close()">
                ❌ Close Window
            </button>
        </div>
        
        <div class="footer">
            <h4>ESSL Library Management System</h4>
            <p>Generated on: <?php echo date('F j, Y g:i A'); ?></p>
            <p>This is a computer-generated receipt and serves as proof of payment.</p>
            <p>For any queries, please contact library administration.</p>
            <p><strong>Thank you for your payment!</strong></p>
        </div>
    </div>

    <script>
        // Auto-focus and show print dialog option
        window.onload = function() {
            // Optional: Auto-open print dialog (uncomment if desired)
            // setTimeout(() => window.print(), 500);
        };
        
        // Handle keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
            if (e.key === 'Escape') {
                window.close();
            }
        });
    </script>
</body>
</html>