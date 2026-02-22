<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'esssl_lib');
define('DB_USER', 'root');
define('DB_PASS', '');

// Site configuration
define('SITE_NAME', 'ESSSL Library Management System');
define('SITE_TAGLINE', 'Your Gateway to Knowledge');
define('SITE_URL', 'http://localhost/esssl_lib/');
define('ASSETS_URL', SITE_URL . 'assets/');



// Library settings
define('MAX_BOOKS_PER_MEMBER', 5);
define('LOAN_PERIOD_DAYS', 14);
define('FINE_PER_DAY', 1.00);
define('SESSION_TIMEOUT', 3600); // 1 hour

// File upload settings
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('UPLOAD_PATH', 'uploads/');

// Pagination
define('ITEMS_PER_PAGE', 20);

// Email settings (if needed)
define('SMTP_HOST', 'localhost');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', '');
define('SMTP_PASSWORD', '');

// Security
define('ENCRYPTION_KEY', 'your_secret_encryption_key_here_change_this');
define('PASSWORD_PEPPER', 'your_password_pepper_here_change_this');
?>
