<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../includes/auth_check.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_book' && hasPermission('manage_books')) {
        $database = new Database();
        $db = $database->getConnection();
        
        try {
            // Get form data
            $title = trim($_POST['title']);
            $author = trim($_POST['author']);
            $isbn = trim($_POST['isbn']) ?: null;
            $publisher = trim($_POST['publisher']) ?: null;
            $publication_year = (int)$_POST['publication_year'] ?: null;
            $genre = trim($_POST['genre']) ?: null;
            $dewey_decimal_number = trim($_POST['dewey_decimal_number']) ?: null;
            $dewey_classification = trim($_POST['dewey_classification']) ?: null;
            $rack_number = trim($_POST['rack_number']) ?: null;
            $shelf_position = $_POST['shelf_position'] ?: 'Middle';
            $floor_level = (int)$_POST['floor_level'] ?: 1;
            $total_copies = (int)$_POST['total_copies'] ?: 1;
            $available_copies = (int)$_POST['available_copies'] ?: $total_copies;
            $description = trim($_POST['description']) ?: null;
            $pages = (int)$_POST['pages'] ?: null;
            $language = trim($_POST['language']) ?: 'English';
            
            // Handle cover image upload
            $cover_image = null;
            if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../../assets/images/books/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION);
                $cover_image = uniqid('book_') . '.' . $file_extension;
                $upload_path = $upload_dir . $cover_image;
                
                if (!move_uploaded_file($_FILES['cover_image']['tmp_name'], $upload_path)) {
                    throw new Exception("Failed to upload cover image");
                }
            }
            
            // Validate required fields
            if (empty($title) || empty($author) || empty($rack_number)) {
                throw new Exception("Title, Author, and Rack Number are required fields");
            }
            
            if ($available_copies > $total_copies) {
                throw new Exception("Available copies cannot be more than total copies");
            }
            
            // Check if ISBN already exists
            if ($isbn) {
                $check_stmt = $db->prepare("SELECT book_id FROM books WHERE isbn = ?");
                $check_stmt->execute([$isbn]);
                if ($check_stmt->fetch()) {
                    throw new Exception("A book with this ISBN already exists");
                }
            }
            
            // Insert book into database
            $stmt = $db->prepare("
                INSERT INTO books (
                    title, author, isbn, publisher, publication_year, genre,
                    dewey_decimal_number, dewey_classification, rack_number, shelf_position,
                    floor_level, total_copies, available_copies, description, cover_image,
                    pages, language, status, added_date
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW()
                )
            ");
            
            $result = $stmt->execute([
                $title, $author, $isbn, $publisher, $publication_year, $genre,
                $dewey_decimal_number, $dewey_classification, $rack_number, $shelf_position,
                $floor_level, $total_copies, $available_copies, $description, $cover_image,
                $pages, $language
            ]);
            
            if ($result) {
                $book_id = $db->lastInsertId();
                $success = "Book '{$title}' has been successfully added to the library! (Book ID: {$book_id})";
                
                // Clear form data on success
                $_POST = [];
            } else {
                throw new Exception("Failed to add book to database");
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
            
            // Clean up uploaded file if database insertion failed
            if (isset($cover_image) && $cover_image && file_exists($upload_path)) {
                unlink($upload_path);
            }
        }
    }
}

// Get genres for dropdown
try {
    $database = new Database();
    $db = $database->getConnection();
    
    $genre_stmt = $db->query("SELECT DISTINCT genre FROM books WHERE genre IS NOT NULL AND genre != '' ORDER BY genre");
    $genres = $genre_stmt->fetchAll();
    
} catch (Exception $e) {
    $genres = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Items - Admin Panel</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
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

        
        /* Main Content */
        .admin-main {
            flex: 1;
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        /* Header */
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
            flex-wrap: wrap;
            gap: 1rem;
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
            flex-wrap: wrap;
        }

        .quick-stats {
            background: #f8fafc;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            font-size: 0.875rem;
            color: var(--gray-color);
            border: 1px solid #e5e7eb;
        }

        .quick-stats .stat-value {
            font-weight: 600;
            color: var(--dark-color);
        }

        /* Content Area */
        .content-area {
            padding: 2rem;
        }

        /* Form Container */
        .form-container {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }

        .form-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .form-header h2 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark-color);
            margin: 0;
        }

        .form-icon {
            width: 50px;
            height: 50px;
            background: var(--primary-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }

        .form-section {
            margin-bottom: 2rem;
        }

        .form-section h4 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 1rem;
            padding-left: 0.5rem;
            border-left: 4px solid var(--primary-color);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            font-weight: 500;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
            display: block;
        }

        .required {
            color: var(--danger-color);
        }

        .form-control {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 0.75rem;
            transition: all 0.3s;
            font-size: 0.9rem;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            outline: none;
        }

        .form-control.is-invalid {
            border-color: var(--danger-color);
        }

        .invalid-feedback {
            color: var(--danger-color);
            font-size: 0.8rem;
            margin-top: 0.25rem;
        }

        .input-group {
            display: flex;
            align-items: center;
        }

        .input-group-text {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-right: none;
            padding: 0.75rem;
            border-radius: 8px 0 0 8px;
            color: var(--gray-color);
        }

        .input-group .form-control {
            border-left: none;
            border-radius: 0 8px 8px 0;
        }

        .file-upload-area {
            border: 2px dashed #e5e7eb;
            border-radius: 10px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
        }

        .file-upload-area:hover {
            border-color: var(--primary-color);
            background: rgba(37, 99, 235, 0.05);
        }

        .file-upload-area.dragover {
            border-color: var(--primary-color);
            background: rgba(37, 99, 235, 0.1);
        }

        .upload-icon {
            font-size: 2rem;
            color: var(--gray-color);
            margin-bottom: 1rem;
        }

        .upload-text {
            color: var(--gray-color);
            margin-bottom: 0.5rem;
        }

        .upload-note {
            font-size: 0.8rem;
            color: var(--gray-color);
            opacity: 0.8;
        }

        .file-input {
            display: none;
        }

        .preview-image {
            max-width: 150px;
            max-height: 200px;
            border-radius: 8px;
            margin-top: 1rem;
            border: 1px solid #e5e7eb;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            padding-top: 2rem;
            border-top: 1px solid #e5e7eb;
            margin-top: 2rem;
        }

        .btn-primary {
            background: var(--primary-color);
            border-color: var(--primary-color);
            padding: 0.75rem 2rem;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.3s;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #f3f4f6;
            border-color: #e5e7eb;
            color: var(--gray-color);
            padding: 0.75rem 2rem;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.3s;
        }

        .btn-secondary:hover {
            background: #e5e7eb;
            color: var(--dark-color);
        }

        .auto-generate-btn {
            background: var(--accent-color);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.3s;
        }

        .auto-generate-btn:hover {
            background: #d97706;
        }

        .help-text {
            font-size: 0.8rem;
            color: var(--gray-color);
            margin-top: 0.25rem;
        }

        .alert {
            border-radius: 10px;
            border: none;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }

        /* Quick Add Templates */
        .quick-templates {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .template-btn {
            padding: 0.75rem 1rem;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            color: var(--dark-color);
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
        }

        .template-btn:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            transform: translateY(-2px);
        }

        /* Mobile Responsive */
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

            .header-actions {
                width: 100%;
                justify-content: space-between;
            }

            .form-container {
                padding: 1.5rem;
            }

            .form-actions {
                flex-direction: column;
            }

            .quick-templates {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<?php include '../includes/sidebar.php'; ?>

    <div class="admin-layout">
        <!-- Sidebar -->
        <aside class="admin-sidebar" id="adminSidebar">
            <div class="sidebar-header">
                <a href="javascript:void(0)" class="sidebar-logo">
                    <img src="../../assets/images/logo.png" alt="Logo">
                    <div>
                        <h3>ESSSL Library</h3>
                        <div class="admin-badge">ADMIN PANEL</div>
                    </div>
                </a>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('')" class="nav-link">
                        <i class="fas fa-tachometer-alt nav-icon"></i>
                        <span>Dashboard</span>
                    </a>
                </div>

                <div class="nav-item">
            <a href="javascript:void(0)" onclick="navigateTo('reservations')" class="nav-link <?php echo $current_page === 'reservations' ? 'active' : ''; ?>">
                <i class="fas fa-book-open nav-icon"></i>
                <span>Reservations</span>
                <?php if ($sidebar_counts['reservations'] > 0): ?>
                    <span class="nav-badge"><?php echo $sidebar_counts['reservations']; ?></span>
                <?php endif; ?>
            </a>
        </div>

        <div class="nav-item">
            <a href="javascript:void(0)" onclick="navigateTo('pending')" class="nav-link <?php echo $current_page === 'pending' ? 'active' : ''; ?>">
                <i class="fas fa-clock nav-icon"></i>
                <span>Pending</span>
                <?php if ($sidebar_counts['pending'] > 0): ?>
                    <span class="nav-badge"><?php echo $sidebar_counts['pending']; ?></span>
                <?php endif; ?>
            </a>
        </div>

                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('explore')" class="nav-link">
                        <i class="fas fa-search nav-icon"></i>
                        <span>Explore</span>
                    </a>
                </div>

                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('add-items')" class="nav-link active">
                        <i class="fas fa-plus-circle nav-icon"></i>
                        <span>Add Items</span>
                    </a>
                </div>

                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('loan-history')" class="nav-link">
                        <i class="fas fa-history nav-icon"></i>
                        <span>Loan History</span>
                    </a>
                </div>

                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('members')" class="nav-link">
                        <i class="fas fa-users nav-icon"></i>
                        <span>Members</span>
                    </a>
                </div>

                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('messages')" class="nav-link">
                        <i class="fas fa-envelope nav-icon"></i>
                        <span>Messages</span>
                    </a>
                </div>

                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('membership')" class="nav-link">
                        <i class="fas fa-id-card nav-icon"></i>
                        <span>Membership</span>
                    </a>
                </div>

                <div class="nav-item">
                    <a href="javascript:void(0)" onclick="navigateTo('settings')" class="nav-link">
                        <i class="fas fa-cog nav-icon"></i>
                        <span>Settings</span>
                    </a>
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
                    <a href="../logout.php" class="ms-auto text-white" style="text-decoration: none;">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="admin-main">
            <!-- Header -->
            <header class="admin-header">
                <div class="header-content">
                    <button class="mobile-menu-toggle" onclick="toggleSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>

                    <div class="header-title">
                        <h1>Add New Book</h1>
                        <p>Add books to the library collection</p>
                    </div>

                    <div class="header-actions">
                        <div class="quick-stats">
                            <span>Total Books: </span>
                            <span class="stat-value"><?php 
                                try {
                                    $count_stmt = $db->query("SELECT COUNT(*) as total FROM books WHERE status = 'active'");
                                    echo $count_stmt->fetch()['total'] ?? 0;
                                } catch (Exception $e) {
                                    echo '0';
                                }
                            ?></span>
                        </div>
                        
                        <a href="javascript:void(0)" onclick="navigateTo('explore')" class="btn btn-outline-primary">
                            <i class="fas fa-list me-1"></i>View All Books
                        </a>
                    </div>
                </div>
            </header>

            <!-- Content Area -->
            <div class="content-area">
                <?php if (isset($success)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Quick Templates -->
                <div class="quick-templates">
                    <button type="button" class="template-btn" onclick="fillTemplate('fiction')">
                        <i class="fas fa-book"></i>Fiction Template
                    </button>
                    <button type="button" class="template-btn" onclick="fillTemplate('textbook')">
                        <i class="fas fa-graduation-cap"></i>Textbook Template
                    </button>
                    <button type="button" class="template-btn" onclick="fillTemplate('science')">
                        <i class="fas fa-flask"></i>Science Template
                    </button>
                    <button type="button" class="template-btn" onclick="clearForm()">
                        <i class="fas fa-eraser"></i>Clear Form
                    </button>
                </div>

                <!-- Add Book Form -->
                <div class="form-container">
                    <div class="form-header">
                        <div class="form-icon">
                            <i class="fas fa-plus"></i>
                        </div>
                        <h2>Book Information</h2>
                    </div>

                    <form method="POST" enctype="multipart/form-data" id="addBookForm" novalidate>
                        <input type="hidden" name="action" value="add_book">
                        
                        <!-- Basic Information -->
                        <div class="form-section">
                            <h4>Basic Information</h4>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">Title <span class="required">*</span></label>
                                        <input type="text" name="title" id="title" class="form-control" required 
                                               value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
                                        <div class="invalid-feedback"></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">Author <span class="required">*</span></label>
                                        <input type="text" name="author" id="author" class="form-control" required 
                                               value="<?php echo htmlspecialchars($_POST['author'] ?? ''); ?>">
                                        <div class="invalid-feedback"></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">ISBN</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-barcode"></i></span>
                                            <input type="text" name="isbn" id="isbn" class="form-control" 
                                                   placeholder="978-0123456789" pattern="[0-9\-]{10,17}"
                                                   value="<?php echo htmlspecialchars($_POST['isbn'] ?? ''); ?>">
                                        </div>
                                        <div class="help-text">Format: 978-0123456789 (optional)</div>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="form-label">Publisher</label>
                                        <input type="text" name="publisher" id="publisher" class="form-control" 
                                               placeholder="Publisher name"
                                               value="<?php echo htmlspecialchars($_POST['publisher'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="form-label">Publication Year</label>
                                        <input type="number" name="publication_year" id="publication_year" class="form-control" 
                                               min="1000" max="2030" placeholder="2023"
                                               value="<?php echo htmlspecialchars($_POST['publication_year'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="form-label">Genre</label>
                                        <input type="text" name="genre" id="genre" class="form-control" 
                                               list="genreList" placeholder="Fiction, Science, etc."
                                               value="<?php echo htmlspecialchars($_POST['genre'] ?? ''); ?>">
                                        <datalist id="genreList">
                                            <?php foreach ($genres as $genre): ?>
                                                <option value="<?php echo htmlspecialchars($genre['genre']); ?>">
                                            <?php endforeach; ?>
                                            <option value="Fiction">
                                            <option value="Non-Fiction">
                                            <option value="Science">
                                            <option value="History">
                                            <option value="Biography">
                                            <option value="Technology">
                                            <option value="Art">
                                            <option value="Philosophy">
                                            <option value="Psychology">
                                            <option value="Business">
                                        </datalist>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="form-label">Language</label>
                                        <select name="language" id="language" class="form-control">
                                            <option value="English" <?php echo ($_POST['language'] ?? '') === 'English' ? 'selected' : ''; ?>>English</option>
                                            <option value="Spanish" <?php echo ($_POST['language'] ?? '') === 'Spanish' ? 'selected' : ''; ?>>Spanish</option>
                                            <option value="French" <?php echo ($_POST['language'] ?? '') === 'French' ? 'selected' : ''; ?>>French</option>
                                            <option value="German" <?php echo ($_POST['language'] ?? '') === 'German' ? 'selected' : ''; ?>>German</option>
                                            <option value="Chinese" <?php echo ($_POST['language'] ?? '') === 'Chinese' ? 'selected' : ''; ?>>Chinese</option>
                                            <option value="Japanese" <?php echo ($_POST['language'] ?? '') === 'Japanese' ? 'selected' : ''; ?>>Japanese</option>
                                            <option value="Other" <?php echo ($_POST['language'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Description</label>
                                <textarea name="description" id="description" class="form-control" rows="3" 
                                          placeholder="Brief description of the book..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <!-- Classification -->
                        <div class="form-section">
                            <h4>Classification & Location</h4>
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label class="form-label">Dewey Decimal Number</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-sort-numeric-up"></i></span>
                                            <input type="text" name="dewey_decimal_number" id="dewey_decimal" class="form-control" 
                                                   placeholder="000.000" pattern="[0-9.]{3,}"
                                                   value="<?php echo htmlspecialchars($_POST['dewey_decimal_number'] ?? ''); ?>">
                                            <button type="button" class="auto-generate-btn" onclick="generateDewey()">
                                                <i class="fas fa-magic"></i> Auto
                                            </button>
                                        </div>
                                        <div class="help-text">Dewey Decimal Classification system (e.g., 004.21 for Programming)</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label class="form-label">Classification Description</label>
                                        <input type="text" name="dewey_classification" id="dewey_classification" class="form-control" 
                                               placeholder="e.g., Computer Science - Programming Languages"
                                               value="<?php echo htmlspecialchars($_POST['dewey_classification'] ?? ''); ?>">
                                        <div class="help-text">Subject classification description</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="form-label">Rack Number <span class="required">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-map-marker-alt"></i></span>
                                            <input type="text" name="rack_number" id="rack_number" class="form-control" 
                                                   placeholder="CS-A-01" required
                                                   value="<?php echo htmlspecialchars($_POST['rack_number'] ?? ''); ?>">
                                            <button type="button" class="auto-generate-btn" onclick="generateRackNumber()">
                                                <i class="fas fa-magic"></i> Auto
                                            </button>
                                        </div>
                                        <div class="help-text">Physical location identifier (required)</div>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="form-label">Shelf Position</label>
                                        <select name="shelf_position" id="shelf_position" class="form-control">
                                            <option value="Top" <?php echo ($_POST['shelf_position'] ?? '') === 'Top' ? 'selected' : ''; ?>>Top</option>
                                            <option value="Middle" <?php echo ($_POST['shelf_position'] ?? 'Middle') === 'Middle' ? 'selected' : ''; ?>>Middle</option>
                                            <option value="Bottom" <?php echo ($_POST['shelf_position'] ?? '') === 'Bottom' ? 'selected' : ''; ?>>Bottom</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="form-label">Floor Level</label>
                                        <select name="floor_level" id="floor_level" class="form-control">
                                            <option value="1" <?php echo ($_POST['floor_level'] ?? '1') === '1' ? 'selected' : ''; ?>>Floor 1</option>
                                            <option value="2" <?php echo ($_POST['floor_level'] ?? '') === '2' ? 'selected' : ''; ?>>Floor 2</option>
                                            <option value="3" <?php echo ($_POST['floor_level'] ?? '') === '3' ? 'selected' : ''; ?>>Floor 3</option>
                                            <option value="4" <?php echo ($_POST['floor_level'] ?? '') === '4' ? 'selected' : ''; ?>>Floor 4</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Copies & Details -->
                        <div class="form-section">
                            <h4>Inventory Details</h4>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="form-label">Total Copies <span class="required">*</span></label>
                                        <input type="number" name="total_copies" id="total_copies" class="form-control" 
                                               min="1" max="100" value="<?php echo htmlspecialchars($_POST['total_copies'] ?? '1'); ?>" required>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="form-label">Available Copies</label>
                                        <input type="number" name="available_copies" id="available_copies" class="form-control" 
                                               min="0" value="<?php echo htmlspecialchars($_POST['available_copies'] ?? '1'); ?>">
                                        <div class="help-text">Auto-synced with total copies</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label class="form-label">Pages</label>
                                        <input type="number" name="pages" id="pages" class="form-control" 
                                               min="1" max="5000" placeholder="320"
                                               value="<?php echo htmlspecialchars($_POST['pages'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Cover Image -->
                        <div class="form-section">
                            <h4>Cover Image</h4>
                            <div class="form-group">
                                <label class="form-label">Book Cover</label>
                                <div class="file-upload-area" onclick="triggerFileInput()" id="uploadArea">
                                    <div class="upload-icon">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                    </div>
                                    <div class="upload-text">Click to upload or drag and drop</div>
                                    <div class="upload-note">JPG, PNG, GIF up to 5MB</div>
                                    <input type="file" name="cover_image" id="cover_image" class="file-input" 
                                           accept="image/*" onchange="previewImage(this)">
                                    <div id="imagePreview"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="clearForm()">
                                <i class="fas fa-eraser me-1"></i>Clear Form
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-plus me-1"></i>Add Book to Library
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Navigation function
        function navigateTo(page) {
            if (page === 'add-items') return;
            if (page === '') {
                window.location.href = './';
                return;
            }
            window.location.href = './' + page + '.php';
        }

        // Mobile sidebar toggle
        function toggleSidebar() {
            document.getElementById('adminSidebar').classList.toggle('show');
        }

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

        // File upload functionality
        function triggerFileInput() {
            document.getElementById('cover_image').click();
        }

        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.innerHTML = `
                        <img src="${e.target.result}" class="preview-image" alt="Book Cover Preview">
                        <div class="mt-2">
                            <small class="text-success">
                                <i class="fas fa-check"></i> Image selected: ${input.files[0].name}
                            </small>
                        </div>
                    `;
                }
                
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Drag and drop functionality
        const uploadArea = document.getElementById('uploadArea');

        uploadArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });

        uploadArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
        });

        uploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                const fileInput = document.getElementById('cover_image');
                fileInput.files = files;
                previewImage(fileInput);
            }
        });

        // Auto-sync available copies with total copies
        document.getElementById('total_copies').addEventListener('input', function() {
            const totalCopies = parseInt(this.value) || 0;
            const availableCopies = document.getElementById('available_copies');
            
            if (parseInt(availableCopies.value) > totalCopies || availableCopies.value === '') {
                availableCopies.value = totalCopies;
            }
            
            availableCopies.max = totalCopies;
        });

        // Form templates
        function fillTemplate(type) {
            const templates = {
                fiction: {
                    genre: 'Fiction',
                    dewey_decimal: '813',
                    dewey_classification: 'American Literature - Fiction',
                    rack_number: 'LIT-A-01',
                    floor_level: '1',
                    shelf_position: 'Middle'
                },
                textbook: {
                    genre: 'Education',
                    dewey_decimal: '371',
                    dewey_classification: 'Education - Textbooks',
                    rack_number: 'EDU-A-01',
                    floor_level: '2',
                    shelf_position: 'Top'
                },
                science: {
                    genre: 'Science',
                    dewey_decimal: '500',
                    dewey_classification: 'Natural Sciences',
                    rack_number: 'SCI-A-01',
                    floor_level: '2',
                    shelf_position: 'Middle'
                }
            };
            
            const template = templates[type];
            if (template) {
                Object.keys(template).forEach(key => {
                    const field = document.getElementById(key);
                    if (field) {
                        field.value = template[key];
                    }
                });
            }
        }

        // Auto-generate Dewey Decimal based on genre
        function generateDewey() {
            const genre = document.getElementById('genre').value.toLowerCase();
            const deweyField = document.getElementById('dewey_decimal');
            const classificationField = document.getElementById('dewey_classification');
            
            const deweyMap = {
                'computer science': { dewey: '004', classification: 'Computer Science' },
                'technology': { dewey: '600', classification: 'Technology & Applied Sciences' },
                'fiction': { dewey: '813', classification: 'American Literature - Fiction' },
                'literature': { dewey: '800', classification: 'Literature' },
                'history': { dewey: '900', classification: 'History & Geography' },
                'science': { dewey: '500', classification: 'Natural Sciences' },
                'mathematics': { dewey: '510', classification: 'Mathematics' },
                'physics': { dewey: '530', classification: 'Physics' },
                'chemistry': { dewey: '540', classification: 'Chemistry' },
                'biology': { dewey: '570', classification: 'Biology' },
                'psychology': { dewey: '150', classification: 'Psychology' },
                'philosophy': { dewey: '100', classification: 'Philosophy' },
                'religion': { dewey: '200', classification: 'Religion' },
                'art': { dewey: '700', classification: 'Fine Arts' },
                'music': { dewey: '780', classification: 'Music' },
                'business': { dewey: '650', classification: 'Business & Management' },
                'economics': { dewey: '330', classification: 'Economics' },
                'education': { dewey: '370', classification: 'Education' },
                'biography': { dewey: '920', classification: 'Biography' }
            };
            
            const match = Object.keys(deweyMap).find(key => genre.includes(key));
            if (match) {
                deweyField.value = deweyMap[match].dewey;
                classificationField.value = deweyMap[match].classification;
            }
        }

        // Auto-generate rack number based on genre and dewey
        function generateRackNumber() {
            const genre = document.getElementById('genre').value.toLowerCase();
            const rackField = document.getElementById('rack_number');
            
            const rackMap = {
                'computer science': 'CS-A-',
                'technology': 'TEC-A-',
                'fiction': 'LIT-A-',
                'literature': 'LIT-A-',
                'history': 'HIST-A-',
                'science': 'SCI-A-',
                'mathematics': 'MAT-A-',
                'physics': 'PHY-A-',
                'chemistry': 'CHE-A-',
                'biology': 'BIO-A-',
                'psychology': 'PSY-A-',
                'philosophy': 'PHI-A-',
                'religion': 'REL-A-',
                'art': 'ART-A-',
                'music': 'MUS-A-',
                'business': 'BUS-A-',
                'economics': 'ECO-A-',
                'education': 'EDU-A-',
                'biography': 'BIO-A-'
            };
            
            const prefix = Object.keys(rackMap).find(key => genre.includes(key));
            if (prefix) {
                const randomNum = String(Math.floor(Math.random() * 99) + 1).padStart(2, '0');
                rackField.value = rackMap[prefix] + randomNum;
            }
        }

        // Clear form
        function clearForm() {
            if (confirm('Are you sure you want to clear all form data?')) {
                document.getElementById('addBookForm').reset();
                document.getElementById('imagePreview').innerHTML = '';
            }
        }

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('addBookForm');
            
            form.addEventListener('submit', function(e) {
                let isValid = true;
                
                // Clear previous validation states
                document.querySelectorAll('.is-invalid').forEach(el => {
                    el.classList.remove('is-invalid');
                });
                
                // Title validation
                const title = document.getElementById('title');
                if (!title.value.trim()) {
                    title.classList.add('is-invalid');
                    title.nextElementSibling.textContent = 'Title is required';
                    isValid = false;
                }
                
                // Author validation
                const author = document.getElementById('author');
                if (!author.value.trim()) {
                    author.classList.add('is-invalid');
                    author.nextElementSibling.textContent = 'Author is required';
                    isValid = false;
                }
                
                // ISBN validation (if provided)
                const isbn = document.getElementById('isbn');
                if (isbn.value.trim()) {
                    const isbnPattern = /^[\d\-]{10,17}$/;
                    if (!isbnPattern.test(isbn.value.trim())) {
                        isbn.classList.add('is-invalid');
                        isbn.parentElement.nextElementSibling.textContent = 'Invalid ISBN format';
                        isValid = false;
                    }
                }
                
                // Rack Number validation
                const rackNumber = document.getElementById('rack_number');
                if (!rackNumber.value.trim()) {
                    rackNumber.classList.add('is-invalid');
                    rackNumber.nextElementSibling.nextElementSibling.textContent = 'Rack number is required';
                    isValid = false;
                }
                
                // Total copies validation
                const totalCopies = document.getElementById('total_copies');
                if (parseInt(totalCopies.value) < 1) {
                    totalCopies.classList.add('is-invalid');
                    totalCopies.nextElementSibling.textContent = 'Total copies must be at least 1';
                    isValid = false;
                }
                
                // Available copies validation
                const availableCopies = document.getElementById('available_copies');
                if (parseInt(availableCopies.value) > parseInt(totalCopies.value)) {
                    availableCopies.classList.add('is-invalid');
                    availableCopies.nextElementSibling.textContent = 'Available copies cannot exceed total copies';
                    isValid = false;
                }
                
                if (!isValid) {
                    e.preventDefault();
                }
            });
            
            // Real-time validation
            document.getElementById('title').addEventListener('blur', function() {
                if (!this.value.trim()) {
                    this.classList.add('is-invalid');
                    this.nextElementSibling.textContent = 'Title is required';
                } else {
                    this.classList.remove('is-invalid');
                    this.nextElementSibling.textContent = '';
                }
            });
            
            document.getElementById('author').addEventListener('blur', function() {
                if (!this.value.trim()) {
                    this.classList.add('is-invalid');
                    this.nextElementSibling.textContent = 'Author is required';
                } else {
                    this.classList.remove('is-invalid');
                    this.nextElementSibling.textContent = '';
                }
            });
            
            document.getElementById('rack_number').addEventListener('blur', function() {
                if (!this.value.trim()) {
                    this.classList.add('is-invalid');
                    this.nextElementSibling.nextElementSibling.textContent = 'Rack number is required';
                } else {
                    this.classList.remove('is-invalid');
                    this.nextElementSibling.nextElementSibling.textContent = '';
                }
            });
            
            // Auto-generate classification when genre changes
            document.getElementById('genre').addEventListener('blur', function() {
                if (this.value && !document.getElementById('dewey_decimal').value) {
                    generateDewey();
                    generateRackNumber();
                }
            });
        });

        // Initialize page
        console.log('Admin Add Items Page initialized successfully');
    </script>
</body>
</html>