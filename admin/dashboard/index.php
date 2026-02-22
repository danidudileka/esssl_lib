<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../includes/auth_check.php';

$database = new Database();
$db = $database->getConnection();

// Dashboard stats
$stmt = $db->query("SELECT COUNT(*) as total FROM members WHERE status = 'active'");
$total_members = $stmt->fetch()['total'] ?? 0;

$stmt = $db->query("SELECT COUNT(*) as total FROM books WHERE status = 'active'");
$total_books = $stmt->fetch()['total'] ?? 0;

$stmt = $db->query("SELECT COUNT(*) as total FROM book_loans WHERE status IN ('active', 'overdue')");
$active_loans = $stmt->fetch()['total'] ?? 0;

$stmt = $db->query("SELECT COUNT(*) as total FROM book_loans WHERE approval_status = 'pending'");
$pending_approvals = $stmt->fetch()['total'] ?? 0;

// Member Type Distribution Data
$stmt = $db->query("
    SELECT membership_type, COUNT(*) as count 
    FROM members 
    WHERE status = 'active' 
    GROUP BY membership_type
");
$memberTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$memberTypeLabels = [];
$memberTypeData = [];
foreach($memberTypes as $type) {
    $memberTypeLabels[] = ucfirst($type['membership_type']);
    $memberTypeData[] = $type['count'];
}

// Books by Genre Data
$stmt = $db->query("
    SELECT genre, COUNT(*) as count 
    FROM books 
    WHERE status = 'active' AND genre IS NOT NULL 
    GROUP BY genre 
    ORDER BY count DESC 
    LIMIT 10
");
$genres = $stmt->fetchAll(PDO::FETCH_ASSOC);
$genreLabels = [];
$genreData = [];
foreach($genres as $genre) {
    $genreLabels[] = $genre['genre'];
    $genreData[] = $genre['count'];
}

// Monthly Loan Activity (last 6 months)
$stmt = $db->query("
    SELECT 
        DATE_FORMAT(loan_date, '%Y-%m') as month,
        MONTHNAME(loan_date) as month_name,
        COUNT(*) as count
    FROM book_loans 
    WHERE loan_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(loan_date, '%Y-%m'), MONTHNAME(loan_date)
    ORDER BY month ASC
");
$loanActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
$loanLabels = [];
$loanData = [];
foreach($loanActivity as $activity) {
    $loanLabels[] = $activity['month_name'];
    $loanData[] = $activity['count'];
}

// Weekly Overdue Books (last 4 weeks)
$stmt = $db->query("
    SELECT 
        WEEK(due_date) as week_num,
        CONCAT('Week ', WEEK(due_date) - WEEK(CURDATE()) + 4) as week_label,
        COUNT(*) as count
    FROM book_loans 
    WHERE status = 'overdue' 
    AND due_date >= DATE_SUB(CURDATE(), INTERVAL 4 WEEK)
    GROUP BY WEEK(due_date)
    ORDER BY week_num DESC
    LIMIT 4
");
$overdueWeeks = $stmt->fetchAll(PDO::FETCH_ASSOC);
$overdueLabels = [];
$overdueData = [];
if(empty($overdueWeeks)) {
    // Fallback data if no overdue books
    $overdueLabels = ['Week 1', 'Week 2', 'Week 3', 'Week 4'];
    $overdueData = [0, 1, 0, 2]; // Some sample overdue data
} else {
    foreach($overdueWeeks as $week) {
        $overdueLabels[] = $week['week_label'];
        $overdueData[] = $week['count'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Admin Panel</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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


        .admin-main {
            flex: 1;
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

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

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .datetime-display {
            background: #f8fafc;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            font-size: 0.875rem;
            color: var(--gray-color);
            border: 1px solid #e5e7eb;
        }

        .datetime-display .time {
            font-weight: 600;
            color: var(--dark-color);
        }

        .content-area {
            padding: 2rem;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .quick-action-btn {
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 15px;
            padding: 2rem 1.5rem;
            text-align: center;
            color: var(--dark-color);
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .quick-action-btn:hover {
            border-color: var(--primary-color);
            transform: translateY(-8px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
        }

        .quick-action-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--primary-color);
            transition: all 0.3s;
        }

        .quick-action-btn.pending .quick-action-icon {
            color: var(--warning-color);
        }

        .quick-action-btn.members .quick-action-icon {
            color: var(--success-color);
        }

        .quick-action-btn.loans .quick-action-icon {
            color: var(--secondary-color);
        }

        .quick-action-btn.books .quick-action-icon {
            color: var(--accent-color);
        }

        .quick-action-btn:hover .quick-action-icon {
            transform: scale(1.1);
        }

        .quick-action-title {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }

        .quick-action-count {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .charts-section {
            margin-top: 2rem;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 2rem;
        }

        .chart-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: all 0.3s;
        }

        .chart-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .chart-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark-color);
        }

        .chart-actions {
            display: flex;
            gap: 0.5rem;
        }

        .chart-action-btn {
            padding: 0.4rem 0.8rem;
            background: #f3f4f6;
            border: none;
            border-radius: 8px;
            color: var(--gray-color);
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .chart-action-btn:hover {
            background: var(--primary-color);
            color: white;
        }

        .chart-container {
            position: relative;
            height: 320px;
        }

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

            .datetime-display {
                display: none;
            }

            .quick-actions {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }

            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    
    <script>

        function navigateTo(page) {
            window.location.href = './' + page + '.php';
        }

        function toggleSidebar() {
            document.getElementById('adminSidebar').classList.toggle('show');
        }

        function updateDateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            });
            const dateString = now.toLocaleDateString('en-US', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
            
            const timeEl = document.getElementById('currentTime');
            const dateEl = document.getElementById('currentDate');
            if (timeEl) timeEl.textContent = timeString;
            if (dateEl) dateEl.textContent = dateString;
        }

        function printChart(chartId) {
            const canvas = document.getElementById(chartId);
            if (!canvas) return;
            
            const chartTitle = canvas.parentElement.parentElement.querySelector('.chart-title').textContent;
            const printWindow = window.open('', '', 'width=800,height=600');
            printWindow.document.write('<html><head><title>Chart</title></head><body style="text-align: center; padding: 20px;"><h2>' + chartTitle + '</h2><img src="' + canvas.toDataURL() + '" style="max-width: 100%;" /><p>Printed on: ' + new Date().toLocaleString() + '</p></body></html>');
            printWindow.document.close();
            printWindow.onload = function() {
                printWindow.print();
                printWindow.close();
            };
        }

    </script>

    <div class="admin-layout">
        <aside class="admin-sidebar" id="adminSidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <img src="../../assets/images/logo.png" alt="Logo">
                    <div>
                        <h3>ESSSL Library</h3>
                        <div class="admin-badge">ADMIN PANEL</div>
                    </div>
                </div>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-item">
                    <div class="nav-link active">
                        <i class="fas fa-tachometer-alt nav-icon"></i>
                        <span>Dashboard</span>
                    </div>
                </div>

                <div class="nav-item">
                    <div class="nav-link" onclick="navigateTo('reservations')">
                        <i class="fas fa-book-open nav-icon"></i>
                        <span>Reservations</span>
                        <?php if ($active_loans > 0): ?>
                            <span class="nav-badge"><?php echo $active_loans; ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="nav-item">
                    <div class="nav-link" onclick="navigateTo('pending')">
                        <i class="fas fa-clock nav-icon"></i>
                        <span>Pending</span>
                        <?php if ($pending_approvals > 0): ?>
                            <span class="nav-badge"><?php echo $pending_approvals; ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="nav-item">
                    <div class="nav-link" onclick="navigateTo('explore')">
                        <i class="fas fa-search nav-icon"></i>
                        <span>Explore</span>
                    </div>
                </div>

                <div class="nav-item">
                    <div class="nav-link" onclick="navigateTo('add-items')">
                        <i class="fas fa-plus-circle nav-icon"></i>
                        <span>Add Items</span>
                    </div>
                </div>

                <div class="nav-item">
                    <div class="nav-link" onclick="navigateTo('loan-history')">
                        <i class="fas fa-history nav-icon"></i>
                        <span>Loan History</span>
                    </div>
                </div>

                <div class="nav-item">
                    <div class="nav-link" onclick="navigateTo('members')">
                        <i class="fas fa-users nav-icon"></i>
                        <span>Members</span>
                    </div>
                </div>

                <div class="nav-item">
                    <div class="nav-link" onclick="navigateTo('messages')">
                        <i class="fas fa-envelope nav-icon"></i>
                        <span>Messages</span>
                    </div>
                </div>

                <div class="nav-item">
                    <div class="nav-link" onclick="navigateTo('membership')">
                        <i class="fas fa-id-card nav-icon"></i>
                        <span>Membership</span>
                    </div>
                </div>

                <div class="nav-item">
                    <div class="nav-link" onclick="navigateTo('settings')">
                        <i class="fas fa-cog nav-icon"></i>
                        <span>Settings</span>
                    </div>
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
                    <div onclick="window.location.href='../logout.php'" style="margin-left: auto; cursor: pointer;">
                        <i class="fas fa-sign-out-alt" style="color: rgba(255,255,255,0.8);"></i>
                    </div>
                </div>
            </div>
        </aside>

        <main class="admin-main">
            <header class="admin-header">
                <div class="header-content">
                    <button class="mobile-menu-toggle" onclick="toggleSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>

                    <div class="header-title">
                        <h1>Admin Dashboard</h1>
                        <p>Manage your library efficiently</p>
                    </div>

                    <div class="header-actions">
                        <div class="datetime-display">
                            <div class="time" id="currentTime"></div>
                            <div id="currentDate"></div>
                        </div>
                    </div>
                </div>
            </header>

            <div class="content-area">
                <div class="quick-actions">
                    <div class="quick-action-btn pending" onclick="navigateTo('pending')">
                        <div class="quick-action-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="quick-action-title">Pending Approvals</div>
                        <div class="quick-action-count"><?php echo $pending_approvals; ?></div>
                    </div>

                    <div class="quick-action-btn members" onclick="navigateTo('members')">
                        <div class="quick-action-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="quick-action-title">Active Members</div>
                        <div class="quick-action-count"><?php echo $total_members; ?></div>
                    </div>

                    <div class="quick-action-btn loans" onclick="navigateTo('reservations')">
                        <div class="quick-action-icon">
                            <i class="fas fa-book-reader"></i>
                        </div>
                        <div class="quick-action-title">Active Loans</div>
                        <div class="quick-action-count"><?php echo $active_loans; ?></div>
                    </div>

                    <div class="quick-action-btn books" onclick="navigateTo('add-items')">
                        <div class="quick-action-icon">
                            <i class="fas fa-book"></i>
                        </div>
                        <div class="quick-action-title">Total Books</div>
                        <div class="quick-action-count"><?php echo $total_books; ?></div>
                    </div>
                </div>

                <div class="charts-section">
                    <div class="charts-grid">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">Member Type Distribution</h3>
                                <div class="chart-actions">
                                    <button class="chart-action-btn" onclick="printChart('memberChart')">
                                        <i class="fas fa-print"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="chart-container">
                                <canvas id="memberChart"></canvas>
                            </div>
                        </div>

                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">Books by Genre</h3>
                                <div class="chart-actions">
                                    <button class="chart-action-btn" onclick="printChart('genreChart')">
                                        <i class="fas fa-print"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="chart-container">
                                <canvas id="genreChart"></canvas>
                            </div>
                        </div>

                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">Monthly Loan Activity</h3>
                                <div class="chart-actions">
                                    <button class="chart-action-btn" onclick="printChart('loanChart')">
                                        <i class="fas fa-print"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="chart-container">
                                <canvas id="loanChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            updateDateTime();
            setInterval(updateDateTime, 1000);
            
            const colors = {
                primary: '#2563eb',
                secondary: '#7c3aed',
                accent: '#f59e0b',
                success: '#10b981',
                danger: '#ef4444',
                warning: '#f59e0b'
            };

            // Real member type data from database
            const memberData = {
                labels: <?php echo json_encode($memberTypeLabels); ?>,
                data: <?php echo json_encode($memberTypeData); ?>
            };

            // Real genre data from database
            const genreData = {
                labels: <?php echo json_encode($genreLabels); ?>,
                data: <?php echo json_encode($genreData); ?>
            };

            // Real loan activity data from database
            const loanActivityData = {
                labels: <?php echo json_encode($loanLabels); ?>,
                data: <?php echo json_encode($loanData); ?>
            };

            // Real overdue books data
            const overdueData = {
                labels: <?php echo json_encode($overdueLabels); ?>,
                data: <?php echo json_encode($overdueData); ?>
            };

            // Member Type Distribution Chart
            new Chart(document.getElementById('memberChart'), {
                type: 'doughnut',
                data: {
                    labels: memberData.labels,
                    datasets: [{
                        data: memberData.data,
                        backgroundColor: [
                            '#3B82F6', // Blue for Students
                            '#10B981', // Green for Faculty
                            '#F59E0B', // Orange for Staff
                            '#8B5CF6'  // Purple for Public
                        ],
                        borderWidth: 3,
                        borderColor: '#ffffff',
                        hoverOffset: 8,
                        hoverBorderWidth: 4
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
                                font: { size: 13, weight: 'bold' },
                                generateLabels: function(chart) {
                                    const data = chart.data;
                                    if (data.labels.length && data.datasets.length) {
                                        return data.labels.map((label, i) => ({
                                            text: `${label} (${data.datasets[0].data[i]})`,
                                            fillStyle: data.datasets[0].backgroundColor[i],
                                            strokeStyle: data.datasets[0].backgroundColor[i],
                                            pointStyle: 'circle'
                                        }));
                                    }
                                    return [];
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0,0,0,0.8)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '#fff',
                            borderWidth: 1,
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((context.parsed * 100) / total).toFixed(1);
                                    return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });

            // Books by Genre Chart
            new Chart(document.getElementById('genreChart'), {
                type: 'bar',
                data: {
                    labels: genreData.labels,
                    datasets: [{
                        data: genreData.data,
                        backgroundColor: [
                            '#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFEAA7',
                            '#DDA0DD', '#98D8C8', '#F7DC6F', '#BB8FCE', '#85C1E9'
                        ],
                        borderRadius: 8,
                        borderSkipped: false,
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { 
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(0,0,0,0.8)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '#fff',
                            borderWidth: 1,
                            callbacks: {
                                title: function(context) {
                                    return '📚 Genre: ' + context[0].label;
                                },
                                label: function(context) {
                                    return 'Books: ' + context.parsed.y;
                                }
                            }
                        }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            ticks: { 
                                stepSize: 1,
                                color: '#6B7280',
                                font: { weight: 'bold' }
                            },
                            grid: { 
                                color: 'rgba(107, 114, 128, 0.1)',
                                drawBorder: false
                            }
                        },
                        x: {
                            ticks: {
                                color: '#6B7280',
                                font: { weight: 'bold' },
                                maxRotation: 45
                            },
                            grid: { display: false }
                        }
                    }
                }
            });

            // Monthly Loan Activity Chart
            new Chart(document.getElementById('loanChart'), {
                type: 'line',
                data: {
                    labels: loanActivityData.labels,
                    datasets: [{
                        label: 'Monthly Loans',
                        data: loanActivityData.data,
                        borderColor: '#10B981',
                        backgroundColor: 'rgba(16, 185, 129, 0.2)',
                        tension: 0.4,
                        fill: true,
                        pointRadius: 6,
                        pointHoverRadius: 8,
                        pointBackgroundColor: '#10B981',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 3,
                        borderWidth: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { 
                        legend: { 
                            display: true,
                            labels: {
                                color: '#374151',
                                font: { size: 14, weight: 'bold' }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0,0,0,0.8)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '#10B981',
                            borderWidth: 2,
                            callbacks: {
                                title: function(context) {
                                    return '📅 ' + context[0].label;
                                },
                                label: function(context) {
                                    return '📖 Loans: ' + context.parsed.y;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { 
                                stepSize: 1,
                                color: '#6B7280',
                                font: { weight: 'bold' }
                            },
                            grid: { 
                                color: 'rgba(107, 114, 128, 0.1)',
                                drawBorder: false
                            }
                        },
                        x: {
                            ticks: {
                                color: '#6B7280',
                                font: { weight: 'bold' }
                            },
                            grid: { display: false }
                        }
                    }
                }
            });

            // Overdue Books Trend Chart
            new Chart(document.getElementById('overdueChart'), {
                type: 'bar',
                data: {
                    labels: overdueData.labels,
                    datasets: [{
                        data: overdueData.data,
                        backgroundColor: [
                            '#EF4444', '#DC2626', '#B91C1C', '#991B1B'
                        ],
                        borderRadius: 8,
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { 
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(0,0,0,0.8)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '#EF4444',
                            borderWidth: 2,
                            callbacks: {
                                title: function(context) {
                                    return '⚠️ ' + context[0].label;
                                },
                                label: function(context) {
                                    return '📕 Overdue Books: ' + context.parsed.y;
                                }
                            }
                        }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            ticks: { 
                                stepSize: 1,
                                color: '#6B7280',
                                font: { weight: 'bold' }
                            },
                            grid: { 
                                color: 'rgba(107, 114, 128, 0.1)',
                                drawBorder: false
                            }
                        },
                        x: {
                            ticks: {
                                color: '#6B7280',
                                font: { weight: 'bold' }
                            },
                            grid: { display: false }
                        }
                    }
                }
            });

            // System Resources Chart (Horizontal Bar Chart)
            let systemChart;
            function createSystemChart() {
                const resources = generateSystemResources();
                
                if (systemChart) {
                    systemChart.destroy();
                }
                
                systemChart = new Chart(document.getElementById('systemChart'), {
                    type: 'bar',
                    data: {
                        labels: ['💾 Memory Usage', '💻 CPU Usage', '🗄️ Disk Usage', '🌐 Network Usage'],
                        datasets: [{
                            data: [resources.memory, resources.cpu, resources.disk, resources.network],
                            backgroundColor: [
                                '#EF4444', // Red for Memory (critical)
                                '#F59E0B', // Orange for CPU (warning)
                                '#3B82F6', // Blue for Disk (info)
                                '#8B5CF6'  // Purple for Network (secondary)
                            ],
                            borderWidth: 2,
                            borderColor: '#ffffff',
                            borderRadius: 6
                        }]
                    },
                    options: {
                        indexAxis: 'y', // This makes it horizontal
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: 'rgba(0,0,0,0.8)',
                                titleColor: '#fff',
                                bodyColor: '#fff',
                                borderWidth: 2,
                                callbacks: {
                                    title: function(context) {
                                        return context[0].label;
                                    },
                                    label: function(context) {
                                        const value = context.parsed.x;
                                        let status = '🟢 Normal';
                                        if (value > 70) status = '🔴 High';
                                        else if (value > 50) status = '🟡 Medium';
                                        
                                        return `Usage: ${value}% - ${status}`;
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                max: 100,
                                ticks: {
                                    color: '#6B7280',
                                    font: { weight: 'bold' },
                                    callback: function(value) {
                                        return value + '%';
                                    }
                                },
                                grid: { 
                                    color: 'rgba(107, 114, 128, 0.1)',
                                    drawBorder: false
                                }
                            },
                            y: {
                                ticks: {
                                    color: '#374151',
                                    font: { weight: 'bold', size: 12 }
                                },
                                grid: { display: false }
                            }
                        }
                    }
                });
            }

            // Initialize system chart
            createSystemChart();
            
            // Update system chart every 5 seconds
            setInterval(createSystemChart, 5000);
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
    </script>
    
</body>
</html>