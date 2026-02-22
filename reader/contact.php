<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';

$page_type = '';
if (isset($_GET['forgot'])) {
    $page_type = 'forgot';
    $page_title = 'Forgot Password';
    $page_subtitle = 'Don\'t worry! We\'ll help you recover your account';
    $page_icon = 'fas fa-key';
} elseif (isset($_GET['signup'])) {
    $page_type = 'signup';
    $page_title = 'Join ESSSL Library';
    $page_subtitle = 'Start your journey to knowledge today';
    $page_icon = 'fas fa-user-plus';
} else {
    $page_type = 'help';
    $page_title = 'Help & Support';
    $page_subtitle = 'We\'re here to assist you 24/7';
    $page_icon = 'fas fa-headset';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo SITE_NAME; ?></title>
    
    <!-- SEO Meta Tags -->
    <meta name="description" content="<?php echo $page_subtitle; ?> - ESSSL Library Support">
    <meta name="keywords" content="ESSSL Library, support, help, password reset, signup">
    
    <!-- Stylesheets -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #4A90E2;
            --primary-dark: #357ABD;
            --secondary-color: #f8f9fa;
            --text-primary: #333333;
            --text-muted: #666666;
            --border-color: #e0e6ed;
            --card-bg: #ffffff;
            --shadow-light: 0 2px 8px rgba(0, 0, 0, 0.1);
            --shadow-medium: 0 4px 16px rgba(0, 0, 0, 0.15);
        }

        [data-theme="dark"] {
            --secondary-color: #2d3748;
            --text-primary: #f7fafc;
            --text-muted: #a0aec0;
            --card-bg: #2d3748;
            --border-color: #4a5568;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #ffffff;
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
        }

        [data-theme="dark"] body {
            background-color: #1a202c;
        }

        /* Back Button */
        .back-button-container {
            position: fixed;
            top: 2rem;
            left: 2rem;
            z-index: 1000;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: white;
            color: var(--primary-color);
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            box-shadow: var(--shadow-light);
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
        }

        .back-button:hover {
            color: var(--primary-dark);
            box-shadow: var(--shadow-medium);
            border-color: var(--primary-color);
        }

        [data-theme="dark"] .back-button {
            background: var(--card-bg);
            color: #63b3ed;
            border-color: #4a5568;
        }

        /* Theme Toggle */
        .theme-toggle {
            position: fixed;
            top: 2rem;
            right: 2rem;
            z-index: 1000;
            background: white;
            border: 1px solid var(--border-color);
            width: 50px;
            height: 50px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-light);
        }

        .theme-toggle:hover {
            box-shadow: var(--shadow-medium);
            border-color: var(--primary-color);
        }

        .theme-toggle i {
            color: var(--primary-color);
            font-size: 1.2rem;
        }

        [data-theme="dark"] .theme-toggle {
            background: var(--card-bg);
            border-color: #4a5568;
        }

        [data-theme="dark"] .theme-toggle i {
            color: #fbbf24;
        }

        /* Main Container */
        .contact-main {
            padding: 6rem 1rem 4rem;
        }

        /* Contact Card */
        .contact-card {
            background: var(--card-bg);
            border-radius: 8px;
            padding: 3rem;
            box-shadow: var(--shadow-medium);
            border: 1px solid var(--border-color);
            max-width: 800px;
            margin: 0 auto;
        }

        /* Header Section */
        .contact-header {
            text-align: center;
            margin-bottom: 3rem;
            padding-bottom: 2rem;
            border-bottom: 2px solid var(--border-color);
        }

        .contact-logo {
            width: 80px;
            height: 80px;
            object-fit: contain;
            margin-bottom: 1.5rem;
        }

        .page-icon {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
            display: block;
        }

        .contact-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.75rem;
            font-family: 'Poppins', sans-serif;
        }

        .contact-subtitle {
            color: var(--text-muted);
            font-size: 1.1rem;
            font-weight: 400;
        }

        /* Alert Cards */
        .modern-alert {
            border: 1px solid;
            border-radius: 8px;
            padding: 2rem;
            margin-bottom: 2rem;
            background: white;
        }

        .modern-alert h5 {
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            font-size: 1.2rem;
        }

        .modern-alert h5 i {
            font-size: 1.3rem;
            margin-right: 0.75rem;
        }

        .alert-info {
            border-color: #3b82f6;
            background-color: #eff6ff;
            color: #1e40af;
        }

        .alert-success {
            border-color: #10b981;
            background-color: #f0fdf4;
            color: #065f46;
        }

        .alert-primary {
            border-color: var(--primary-color);
            background-color: #f8fafc;
            color: var(--primary-dark);
        }

        [data-theme="dark"] .modern-alert {
            background: rgba(45, 55, 72, 0.8);
            color: #e2e8f0;
            border-color: #4a5568;
        }

        .modern-alert hr {
            border: none;
            border-top: 1px solid;
            margin: 1rem 0;
            opacity: 0.3;
        }

        /* Contact Grid */
        .contact-info {
            margin-top: 2.5rem;
        }

        .contact-info h5 {
            color: var(--text-primary);
            font-weight: 600;
            margin-bottom: 2rem;
            text-align: center;
            font-size: 1.4rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }

        .contact-item {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            display: flex;
            align-items: flex-start;
            gap: 1.5rem;
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
        }

        .contact-item:hover {
            box-shadow: var(--shadow-medium);
            border-color: var(--primary-color);
        }

        [data-theme="dark"] .contact-item {
            background: rgba(45, 55, 72, 0.5);
            border-color: #4a5568;
        }

        .contact-item i {
            font-size: 1.5rem;
            color: var(--primary-color);
            background: #f8fafc;
            width: 50px;
            height: 50px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border: 1px solid var(--border-color);
        }

        [data-theme="dark"] .contact-item i {
            background: rgba(45, 55, 72, 0.8);
            border-color: #4a5568;
        }

        .contact-item-content {
            flex: 1;
        }

        .contact-item-content strong {
            color: var(--text-primary);
            font-weight: 600;
            font-size: 1.1rem;
            display: block;
            margin-bottom: 0.5rem;
        }

        .contact-item-content p {
            color: var(--text-muted);
            margin: 0;
            line-height: 1.6;
        }

        /* Quick Actions */
        .quick-actions {
            margin-top: 2.5rem;
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            padding-top: 2rem;
            border-top: 1px solid var(--border-color);
        }

        .quick-action {
            background: var(--primary-color);
            color: white;
            padding: 0.875rem 2rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: 1px solid var(--primary-color);
        }

        .quick-action:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            color: white;
        }

        .quick-action.secondary {
            background: white;
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
        }

        .quick-action.secondary:hover {
            background: var(--primary-color);
            color: white;
        }

        [data-theme="dark"] .quick-action.secondary {
            background: var(--card-bg);
            border-color: #63b3ed;
            color: #63b3ed;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .contact-card {
                padding: 2rem;
                border-radius: 8px;
            }

            .contact-title {
                font-size: 2rem;
            }

            .back-button-container,
            .theme-toggle {
                top: 1rem;
            }

            .back-button-container {
                left: 1rem;
            }

            .theme-toggle {
                right: 1rem;
            }

            .contact-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }

            .quick-actions {
                flex-direction: column;
                align-items: center;
            }

            .quick-action {
                width: 100%;
                justify-content: center;
                max-width: 300px;
            }

            .contact-main {
                padding: 4rem 1rem 2rem;
            }
        }

        @media (max-width: 480px) {
            .contact-card {
                padding: 1.5rem;
            }

            .contact-title {
                font-size: 1.75rem;
            }

            .contact-item {
                padding: 1.5rem;
            }
        }

        /* Clean animations */
        .fade-in {
            opacity: 1;
            transform: translateY(0);
        }

        /* Accessibility */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        @media (prefers-contrast: high) {
            .contact-card {
                border: 2px solid var(--text-primary);
            }
            
            .contact-item {
                border: 2px solid var(--text-primary);
            }

            .modern-alert {
                border-width: 2px;
            }
        }
    </style>
</head>
<body>
    <!-- Back Button -->
    <div class="back-button-container">
        <a href="./" class="back-button">
            <i class="fas fa-arrow-left"></i>
            <span>Back to Login</span>
        </a>
    </div>

    <!-- Theme Toggle -->
    <button class="theme-toggle" id="themeToggle" title="Toggle Dark Mode">
        <i class="fas fa-moon" id="themeIcon"></i>
    </button>

    <main class="contact-main">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8 col-md-10">
                    <div class="contact-card fade-in" data-aos="zoom-in" data-aos-duration="800">
                        <div class="contact-header">
                            <img src="<?php echo ASSETS_URL; ?>images/logo.png" alt="ESSSL Library" class="contact-logo">
                            <i class="<?php echo $page_icon; ?> page-icon"></i>
                            <h2 class="contact-title"><?php echo $page_title; ?></h2>
                            <p class="contact-subtitle"><?php echo $page_subtitle; ?></p>
                        </div>

                        <?php if ($page_type === 'forgot'): ?>
                            <div class="modern-alert alert-info" data-aos="fade-up" data-aos-delay="200">
                                <h5><i class="fas fa-shield-alt"></i>Password Recovery</h5>
                                <p class="mb-3">Don't worry! Password recovery is simple and secure. Our library staff will help you regain access to your account quickly.</p>
                                <hr style="border-color: rgba(59, 130, 246, 0.3); margin: 1rem 0;">
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <p class="mb-1"><i class="fas fa-phone me-2"></i><strong>Phone:</strong> +1 (555) 123-4567</p>
                                        <p class="mb-1"><i class="fas fa-envelope me-2"></i><strong>Email:</strong> support@ESSSLlibrary.com</p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-1"><i class="fas fa-clock me-2"></i><strong>Support Hours:</strong></p>
                                        <p class="mb-0">Monday-Friday: 9AM-6PM</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="quick-actions" data-aos="fade-up" data-aos-delay="400">
                                <a href="tel:+15551234567" class="quick-action">
                                    <i class="fas fa-phone"></i>
                                    Call Now
                                </a>
                                <a href="mailto:support@ESSSLlibrary.com" class="quick-action secondary">
                                    <i class="fas fa-envelope"></i>
                                    Send Email
                                </a>
                            </div>

                        <?php elseif ($page_type === 'signup'): ?>
                            <div class="modern-alert alert-success" data-aos="fade-up" data-aos-delay="200">
                                <h5><i class="fas fa-user-plus"></i>Welcome to ESSSL Library!</h5>
                                <p class="mb-3">Join thousands of book lovers in our community. Creating your account is easy - just visit us in person or get in touch!</p>
                                <hr style="border-color: rgba(16, 185, 129, 0.3); margin: 1rem 0;">
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <p class="mb-1"><i class="fas fa-map-marker-alt me-2"></i><strong>Visit Us:</strong></p>
                                        <p class="mb-1">No.70, 1st Cross Street</p>
                                        <p class="mb-1">Galle Road, Colombo 04 </p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-1"><i class="fas fa-id-card me-2"></i><strong>Bring:</strong></p>
                                        <p class="mb-1">• Valid Photo ID</p>
                                        <p class="mb-0">• Proof of Address</p>
                                    </div>
                                </div>
                            </div>

                            <div class="quick-actions" data-aos="fade-up" data-aos-delay="400">
                                <a href="https://maps.google.com" target="_blank" class="quick-action">
                                    <i class="fas fa-map-marker-alt"></i>
                                    Get Directions
                                </a>
                                <a href="tel:+94 123456789" class="quick-action secondary">
                                    <i class="fas fa-phone"></i>
                                    Call First
                                </a>
                            </div>

                        <?php else: ?>
                            <div class="modern-alert alert-primary" data-aos="fade-up" data-aos-delay="200">
                                <h5><i class="fas fa-headset"></i>We're Here to Help!</h5>
                                <p class="mb-0">Whether you need technical support, have questions about our services, or need assistance with your account, our friendly library staff is ready to help you every step of the way.</p>
                            </div>

                            <div class="quick-actions" data-aos="fade-up" data-aos-delay="400">
                                <a href="tel:+94 123456789" class="quick-action">
                                    <i class="fas fa-phone"></i>
                                    Quick Call
                                </a>
                                <a href="mailto:support@ESSSLlibrary.com" class="quick-action secondary">
                                    <i class="fas fa-envelope"></i>
                                    Email Support
                                </a>
                            </div>
                        <?php endif; ?>

                        <div class="contact-info" data-aos="fade-up" data-aos-delay="600">
                            <h5>Contact Information</h5>
                            <div class="contact-grid">
                                <div class="contact-item">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <div class="contact-item-content">
                                        <strong>Visit Our Library</strong>
                                        <p>No.70, 1st Cross Street<br>Galle Road, Colombo 04<br>Easy parking available</p>
                                    </div>
                                </div>
                                
                                <div class="contact-item">
                                    <i class="fas fa-phone"></i>
                                    <div class="contact-item-content">
                                        <strong>Call Us</strong>
                                        <p>+94 123456789<br>Toll-free support line<br>Quick response guaranteed</p>
                                    </div>
                                </div>
                                
                                <div class="contact-item">
                                    <i class="fas fa-envelope"></i>
                                    <div class="contact-item-content">
                                        <strong>Email Support</strong>
                                        <p>support@ESSSLlibrary.com<br>24/7 email support<br>Response within 2 hours</p>
                                    </div>
                                </div>
                                
                                <div class="contact-item">
                                    <i class="fas fa-clock"></i>
                                    <div class="contact-item-content">
                                        <strong>Operating Hours</strong>
                                        <p>Mon-Fri: 8AM-10PM<br>Sat-Sun: 9AM-8PM<br>Extended holiday hours</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>
    
    <script>
        // Initialize AOS
        AOS.init({
            duration: 800,
            once: true,
            offset: 100
        });

        // Theme Management
        function initTheme() {
            const savedTheme = localStorage.getItem('abcLibraryTheme') || 'light';
            setTheme(savedTheme);
        }

        function setTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('abcLibraryTheme', theme);
            
            const themeIcon = document.getElementById('themeIcon');
            if (themeIcon) {
                if (theme === 'dark') {
                    themeIcon.className = 'fas fa-sun';
                } else {
                    themeIcon.className = 'fas fa-moon';
                }
            }
        }

        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            setTheme(newTheme);
        }

        // Initialize theme toggle
        document.getElementById('themeToggle').addEventListener('click', toggleTheme);

        // Initialize theme on page load
        document.addEventListener('DOMContentLoaded', function() {
            initTheme();
            
            // Add click effects to contact items
            document.querySelectorAll('.contact-item').forEach(item => {
                item.addEventListener('click', function() {
                    const phone = this.querySelector('strong').textContent.includes('Call');
                    const email = this.querySelector('strong').textContent.includes('Email');
                    
                    if (phone) {
                        window.open('tel:+15551234567');
                    } else if (email) {
                        window.open('mailto:support@ESSSLlibrary.com');
                    }
                });
            });

            // Add loading animation
            document.body.classList.add('loaded');
        });

        // Add some interactive effects
        document.querySelectorAll('.quick-action').forEach(action => {
            action.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px) scale(1.05)';
            });
            
            action.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });

        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                // Could add modal close functionality here
            }
        });

        console.log('🚀 ESSSL Library Support Page loaded successfully!');
    </script>
</body>
</html>