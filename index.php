<?php
session_start();
require_once 'config/database.php';
require_once 'config/constants.php';

// Get latest 8 books for display
try {
    $database = new Database();
    $db = $database->getConnection();
    
    $stmt = $db->prepare("
        SELECT book_id, title, author, cover_image, description, rating, publication_year 
        FROM books 
        WHERE status = 'active' AND available_copies > 0 
        ORDER BY added_date DESC 
        LIMIT 8
    ");
    $stmt->execute();
    $latest_books = $stmt->fetchAll();
    
    // Get total books count
    $count_stmt = $db->query("SELECT COUNT(*) as total FROM books WHERE status = 'active'");
    $total_books = $count_stmt->fetch()['total'];
    
} catch(Exception $e) {
    $latest_books = [];
    $total_books = 0;
    error_log("Landing page error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo SITE_TAGLINE; ?></title>
    
    <!-- SEO Meta Tags -->
    <meta name="description" content="ESSSL Library - Your digital gateway to knowledge with thousands of books, modern facilities, and AI-powered assistance.">
    <meta name="keywords" content="library, books, digital library, ESSSL Library, online catalog">
    <meta name="author" content="ESSSL Library">
    
    <!-- Open Graph -->
    <meta property="og:title" content="<?php echo SITE_NAME; ?> - <?php echo SITE_TAGLINE; ?>">
    <meta property="og:description" content="Discover thousands of books in our modern digital library">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo SITE_URL; ?>">
    
    <!-- Preload Critical Resources -->
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800&display=swap" as="style">
    
    <!-- Stylesheets -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.css" rel="stylesheet">
    <link href="<?php echo ASSETS_URL; ?>css/style.css" rel="stylesheet">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo ASSETS_URL; ?>images/favicon.ico">
</head>
<body>
    <!-- Loading Screen -->
    <div id="loading-screen" class="loading-screen">
        <div class="loading-content">
            <div class="loading-logo">
                <img src="<?php echo ASSETS_URL; ?>images/logo.png" alt="ESSSL Library" style="width: 200px; height: 200px; object-fit: contain;">
                <br><br>
                <div class="book-loader">
                    <div class="book-page"></div>
                    <div class="book-page"></div>
                    <div class="book-page"></div>
                </div>
            </div>
            
            <div class="loading-progress">
                <div class="progress-fill"></div>
            </div>
        </div>
    </div>

    <!-- Navigation Header -->
    <nav class="navbar navbar-expand-lg navbar-light fixed-top" id="mainNavbar">
        <div class="container">
            <a class="navbar-brand" href="<?php echo SITE_URL; ?>">
                <div class="brand-logo d-flex align-items-center">
                    <img src="<?php echo ASSETS_URL; ?>images/logo.png" alt="ESSSL Library" style="width: 40px; height: 40px; margin-right: 10px; object-fit: contain;">
                    <span class="brand-text">ESSSL <span class="text-primary">Library</span></span>
                </div>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item">
                        <a class="nav-link" href="#home"></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#about"></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#contact"></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link nav-login" href="admin/">Admin</a>
                    </li>
                    <li class="nav-item">
                        <button class="theme-toggle" id="themeToggle" title="Toggle Dark Mode">
                            <i class="fas fa-moon" id="themeIcon"></i>
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="hero-section">
        <div class="container">
            <div class="row align-items-center min-vh-100">
                <div class="col-lg-6" data-aos="fade-right" data-aos-duration="1000">
                    <div class="hero-content">

                        <h1 class="hero-title">
                            ESSSL<br>
                            <span class="text-primary">Library</span>
                        </h1>
                        <p class="hero-subtitle">
                            Your digital gateway to knowledge with thousands of books, modern facilities, and AI-powered assistance.
                        </p>
                        
                        <div class="hero-actions">
                            <a href="reader/" class="btn btn-primary btn-premium me-3">
                                JOIN NOW
                            </a>
                            <a href="reader/" class="btn btn-outline-primary btn-premium">
                                Reader Login
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6" data-aos="fade-right" data-aos-duration="1000">
                    <img src="./assets/images/bookstack.png" width="100%" alt="book-img">
                </div>
                

            </div>
        </div>
    </section>

    <!-- Latest Books Section -->
    <section id="books" class="books-section py-5">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <h2 class="section-title">Latest Books</h2>
                <p class="section-subtitle">Discover our newest additions to the collection</p>
            </div>
            
            <div class="row g-4" id="booksContainer">
                <?php foreach($latest_books as $index => $book): ?>
                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="<?php echo $index * 100; ?>">
                    <div class="book-card">
                        <div class="book-cover">
                            <?php if (!empty($book['cover_image'])): ?>
                                <img src="<?php echo htmlspecialchars($book['cover_image']); ?>" 
                                    alt="<?php echo htmlspecialchars($book['title']); ?>" 
                                    style="width:200px; height:280px; object-fit:cover;">
                            <?php endif;

                            // Generate random rating for display 
                            $display_rating = $book['rating'] > 0 ? $book['rating'] : (3.5 + (rand(0, 15) / 10)); // Random between 3.5-5.0
                            ?>
                            <img src="<?php echo $image_path; ?>" 
                                 alt="<?php echo htmlspecialchars($book['title']); ?>">
                            <div class="book-overlay">
                                <div class="book-actions">
                                    <button class="btn btn-sm btn-light" onclick="viewBookDetails(<?php echo $book['book_id']; ?>, '<?php echo htmlspecialchars($book['title'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($book['author'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($book['description'] ?? 'No description available', ENT_QUOTES); ?>', <?php echo $book['publication_year']; ?>, '<?php echo $book['rack_number'] ?? 'Not specified'; ?>', <?php echo $display_rating; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-primary" onclick="addToWishlist(<?php echo $book['book_id']; ?>)">
                                        <i class="fas fa-heart"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="book-info">
                            <h5 class="book-title"><?php echo htmlspecialchars(substr($book['title'], 0, 30)) . (strlen($book['title']) > 30 ? '...' : ''); ?></h5>
                            <p class="book-author">by <?php echo htmlspecialchars($book['author']); ?></p>
                            <div class="book-rating">
                                <?php for($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star <?php echo $i <= floor($display_rating) ? 'text-warning' : 'text-muted'; ?>"></i>
                                <?php endfor; ?>
                                <span class="rating-text">(<?php echo number_format($display_rating, 1); ?>)</span>
                            </div>
                            <p class="book-year"><?php echo $book['publication_year']; ?></p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="text-center mt-5" data-aos="fade-up">
                <span class="btn btn-primary btn-premium btn-lg" onclick="window.location.href = './reader';">
                    <i class="fas fa-compass me-2"></i>
                    Explore More in Dashboard
                </span>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section py-5">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <h2 class="section-title">Why Choose ESSSL Library?</h2>
                <p class="section-subtitle">Modern features for the digital age</p>
            </div>
            
            <div class="row g-4">
                <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="100">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <h4>Fast & Easy Borrowing</h4>
                        <p>Borrow, reserve, and renew books instantly anytime with a seamless and user-friendly experience.</p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="200">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-search"></i>
                        </div>
                        <h4>Smart Search</h4>
                        <p>Advanced search algorithms with auto-suggestions, filters, and semantic understanding for better book discovery.</p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="300">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-mobile-alt"></i>
                        </div>
                        <h4>Mobile Responsive</h4>
                        <p>Access your library account from any device with our fully responsive design and progressive web app capabilities.</p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="400">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <h4>24/7 Access</h4>
                        <p>Browse our digital catalog, manage your account, and access online resources anytime, anywhere.</p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="500">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h4>Secure & Safe</h4>
                        <p>Your data is protected with enterprise-level security, encryption, and privacy controls.</p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="600">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <h4>Community</h4>
                        <p>Join a community of readers, share reviews, participate in book clubs, and discover new favorites.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer py-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="footer-brand">
                        <div class="brand-logo mb-3 d-flex align-items-center">
                            <img src="<?php echo ASSETS_URL; ?>images/logo.png" alt="ESSL Library" style="width: 40px; height: 40px; margin-right: 10px; object-fit: contain;">
                            <span class="brand-text text-white">ESSSL <span class="text-primary">Library</span></span>
                        </div>
                        <p class="footer-text">Your gateway to knowledge with modern digital library services, AI assistance, and a vast collection of books and resources.</p>
                        <div class="social-links">
                            <a href="#" class="social-link"><i class="fab fa-facebook"></i></a>
                            <a href="#" class="social-link"><i class="fab fa-twitter"></i></a>
                            <a href="#" class="social-link"><i class="fab fa-instagram"></i></a>
                            <a href="#" class="social-link"><i class="fab fa-linkedin"></i></a>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-2 col-md-6 mb-4">
                    <h5 class="footer-title">Quick Links</h5>
                    <ul class="footer-links">
                        <li><a href="#home">Home</a></li>
                        <li><a href="#books">Books</a></li>
                        <li><a href="dashboard">Catalog</a></li>
                        <li><a href="reader/">My Account</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-2 col-md-6 mb-4">
                    <h5 class="footer-title"></h5>
                    <ul class="footer-links">
                        <li><a href="#"></a></li>
                        <li><a href="#"></a></li>
                        <li><a href="#"></a></li>
                        <li><a href="#"></a></li>
                    </ul>
                </div>
                
                <div class="col-lg-4 col-md-6 mb-4">
                    <h5 class="footer-title">Contact Info</h5>
                    <div class="contact-info">
                        <div class="contact-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <span>123 Library Street, Knowledge City, KC 12345</span>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-phone"></i>
                            <span>+1 (555) 123-4567</span>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-envelope"></i>
                            <span>info@esssllibrary.com</span>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-clock"></i>
                            <span>Mon-Fri: 8AM-10PM | Sat-Sun: 9AM-8PM</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <hr class="footer-divider">
            
            <div class="row align-items-center">
                <div class="col-md-6">
                    <p class="copyright-text mb-0">
                        &copy; <?php echo date('Y'); ?> ESSSL Library. All rights reserved.
                    </p>
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="footer-links-inline">

                    </div>
                </div>
            </div>
        </div>
    </footer>


    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>
    <script src="<?php echo ASSETS_URL; ?>js/main.js"></script>
    <script>
// Override the viewBookDetails function
function viewBookDetails(bookId, title, author, description, year, rack, rating) {
    // Remove any existing modals
    document.querySelectorAll('.book-details-modal').forEach(modal => modal.remove());
    
    // Create new modal with actual data
    const modal = document.createElement('div');
    modal.className = 'book-details-modal';
    modal.innerHTML = `
        <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); display: flex; align-items: center; justify-content: center; z-index: 10000;">
            <div style="background: white; padding: 2rem; border-radius: 16px; max-width: 500px; width: 90%;">
                <h4 style="text-align: center; color: #333; margin-bottom: 1rem;">📚 ${title || 'Book Details'}</h4>
                <p style="color: #666; margin-bottom: 0.5rem;"><strong>Author:</strong> ${author || 'Unknown Author'}</p>
                <p style="color: #666; margin-bottom: 0.5rem;"><strong>Year:</strong> ${year || 'N/A'}</p>
                <p style="color: #666; margin-bottom: 0.5rem;"><strong>Location:</strong> ${rack || 'Available'}</p>
                <p style="color: #666; margin-bottom: 0.5rem;"><strong>Rating:</strong> ⭐⭐⭐⭐⭐ (${rating || '4.2'})</p>
                <p style="color: #666; margin-bottom: 1rem;">${description || 'A wonderful book from our library collection.'}</p>
                <div style="text-align: center;">
                    <button onclick="this.closest('.book-details-modal').remove()" style="background: #4A90E2; color: white; border: none; padding: 0.75rem 2rem; border-radius: 8px; cursor: pointer;">Close</button>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}
</script>
</body>
</html>