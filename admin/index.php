<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';

// Check if admin is already logged in
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: dashboard/index.php');
    exit();
}

// Handle login form submission
$login_error = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username_email = trim($_POST['username_email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']);
    
    if (!empty($username_email) && !empty($password)) {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            if (!$db) {
                throw new Exception("Database connection failed");
            }
            
            // Check if input is email or username
            $field = filter_var($username_email, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
            
            $stmt = $db->prepare("
                SELECT admin_id, username, email, password_hash, full_name, role, status 
                FROM admin 
                WHERE {$field} = ? AND status = 'active'
            ");
            $stmt->execute([$username_email]);
            $admin = $stmt->fetch();
            
            if (!$admin) {
                $login_error = 'No account found with this ' . ($field === 'email' ? 'email' : 'username') . '.';
            } elseif ($admin['status'] !== 'active') {
                $login_error = 'Your account is not active. Please contact the administrator.';
            } else {
                if (password_verify($password, $admin['password_hash'])) {
                    // Set session variables
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_id'] = $admin['admin_id'];
                    $_SESSION['admin_username'] = $admin['username'];
                    $_SESSION['admin_name'] = $admin['full_name'];
                    $_SESSION['admin_email'] = $admin['email'];
                    $_SESSION['admin_role'] = $admin['role'];
                    $_SESSION['admin_login_time'] = time();
                    
                    // Handle remember me
                    if ($remember_me) {
                        $token = bin2hex(random_bytes(32));
                        $expires = time() + (30 * 24 * 60 * 60); // 30 days
                        
                        setcookie('admin_remember_token', $token, $expires, '/', '', false, true);
                        
                        try {
                            $stmt = $db->prepare("UPDATE admin SET remember_token = ?, token_expires = FROM_UNIXTIME(?) WHERE admin_id = ?");
                            $stmt->execute([$token, $expires, $admin['admin_id']]);
                        } catch (Exception $e) {
                            error_log("Remember token error: " . $e->getMessage());
                        }
                    }
                    
                    // Update last login
                    try {
                        $update_stmt = $db->prepare("UPDATE admin SET last_login = NOW() WHERE admin_id = ?");
                        $update_stmt->execute([$admin['admin_id']]);
                    } catch (Exception $e) {
                        error_log("Last login update error: " . $e->getMessage());
                    }
                    
                    // Redirect to dashboard
                    header('Location: dashboard/index.php');
                    exit();
                } else {
                    $login_error = 'Invalid username or password. Please check your password and try again.';
                }
            }
        } catch (PDOException $e) {
            $login_error = 'Database error. Please try again later.';
            error_log("Admin login error: " . $e->getMessage());
        } catch (Exception $e) {
            $login_error = 'System error. Please try again later.';
            error_log("Admin login error: " . $e->getMessage());
        }
    } else {
        $login_error = 'Please fill in all required fields.';
    }
}

// Check for logout message
if (isset($_GET['logged_out'])) {
    $success_message = 'You have been successfully logged out.';
}

// Check for timeout message
if (isset($_GET['timeout'])) {
    $login_error = 'Your session has expired. Please login again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - <?php echo SITE_NAME; ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            height: 100vh;
            overflow: hidden;
        }

        .login-container {
            display: flex;
            height: 100vh;
        }

        /* Left Side - Welcome Section */
        .welcome-side {
            flex: 1;
            background: #26667F;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
            padding: 60px;
            overflow: hidden;
        }

        .logo-section {
            position: absolute;
            top: 40px;
            left: 60px;
            display: flex;
            align-items: center;
        }

        .logo {
            width: 40px;
            height: 40px;
            object-fit: contain;
            margin-right: 12px;
        }

        .logo-text {
            color: white;
            font-size: 16px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .welcome-content {
            margin:60px;
            max-width: 700px;
        }

        .welcome-title {
            font-size: 48px;
            font-weight: 800;
            color: white;
            line-height: 1.2;
            margin-bottom: 20px;
        }

        .welcome-subtitle {
            font-size: 16px;
            color: rgba(255, 255, 255, 0.8);
            line-height: 1.6;
        }

        /* Decorative elements */
        .welcome-side::before {
            content: '';
            position: absolute;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            top: -150px;
            right: -150px;
        }

        .welcome-side::after {
            content: '';
            position: absolute;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            bottom: -100px;
            left: -100px;
        }

        /* Right Side - Login Form */
        .form-side {
            flex: 1;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
        }

        .form-container {
            width: 100%;
            max-width: 400px;
        }

        .form-header {
            margin-bottom: 40px;
        }

        .form-title {
            font-size: 24px;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 8px;
        }

        .form-subtitle {
            font-size: 14px;
            color: #6b7280;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
            display: flex;
            align-items: center;
        }

        .alert-danger {
            background-color: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background-color: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .alert i {
            margin-right: 8px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #374151;
            font-size: 14px;
            font-weight: 500;
        }

        .input-wrapper {
            position: relative;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 16px;
            color: #1f2937;
            background: white;
            transition: all 0.2s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #26667F;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-input::placeholder {
            color: #9ca3af;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6b7280;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            transition: color 0.2s ease;
        }

        .password-toggle:hover {
            color: #26667F;
        }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
        }

        .checkbox-group input[type="checkbox"] {
            width: 16px;
            height: 16px;
            margin-right: 8px;
            accent-color: #26667F;
        }

        .checkbox-group label {
            font-size: 14px;
            color: #6b7280;
            margin: 0;
        }

        .forgot-link {
            color: #26667F;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }

        .forgot-link:hover {
            text-decoration: underline;
        }

        .button-group {
            display: flex;
            gap: 12px;
            margin-bottom: 32px;
        }

        .btn {
            flex: 1;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            position: relative;
            overflow: hidden;
            text-decoration: none;
            text-align: center;
        }

        .btn-primary {
            background: #26667F;
            color: white;
        }

        .btn-primary:hover {
            background: #16485b;
            transform: translateY(-1px);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-secondary {
            background: transparent;
            color: #26667F;
            border: 1px solid #26667F;
        }

        .btn-secondary:hover {
            background: #26667F;
            color: white;
        }

        .btn-loading {
            display: none;
            align-items: center;
            justify-content: center;
        }

        .btn-loading.active {
            display: flex;
        }

        .btn-text.hidden {
            display: none;
        }

        .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s linear infinite;
            margin-right: 8px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .social-section {
            text-align: center;
            padding-top: 24px;
            border-top: 1px solid #f3f4f6;
        }

        .social-text {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 16px;
        }

        .social-links {
            display: flex;
            justify-content: center;
            gap: 16px;
        }

        .social-link {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6b7280;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .social-link.facebook {
            background: #f3f4f6;
        }

        .social-link.facebook:hover {
            background: #1877f2;
            color: white;
        }

        .social-link.twitter {
            background: #f3f4f6;
        }

        .social-link.twitter:hover {
            background: #1da1f2;
            color: white;
        }

        .social-link.linkedin {
            background: #f3f4f6;
        }

        .social-link.linkedin:hover {
            background: #0077b5;
            color: white;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .login-container {
                flex-direction: column;
            }

            .welcome-side {
                flex: 0 0 200px;
                padding: 40px 30px;
                justify-content: center;
            }

            .logo-section {
                position: relative;
                top: 0;
                left: 0;
                margin-bottom: 30px;
            }

            .welcome-title {
                font-size: 32px;
            }

            .welcome-content {
                max-width: none;
                text-align: center;
            }

            .form-side {
                flex: 1;
                padding: 30px 20px;
            }

            .button-group {
                flex-direction: column;
            }

            .btn {
                flex: none;
            }
        }

        @media (max-width: 480px) {
            .welcome-side {
                flex: 0 0 160px;
                padding: 30px 20px;
            }

            .logo-section {
                margin-bottom: 20px;
            }

            .welcome-title {
                font-size: 28px;
            }

            .welcome-subtitle {
                font-size: 14px;
            }

            .form-container {
                max-width: none;
            }
        }

        /* Animations */
        .login-container {
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .form-container {
            animation: slideInRight 0.6s ease-out;
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .welcome-content {
            animation: slideInLeft 0.6s ease-out;
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Left Side - Welcome Section -->
        <div class="welcome-side">
            <div class="logo-section">
                <img src="<?php echo ASSETS_URL; ?>images/logo.png" alt="Logo" class="logo" style="filter: brightness(0) invert(1);" onclick="window.location.href = '../';">
                <span class="logo-text">ESSSL LIBRARY MANAGEMENT SYSTEM<br>ADMINISTRATIVE LOGIN</span>
            </div>
            
            <div class="welcome-content">
                <h1 class="welcome-title">Hello,<br>welcome!</h1>
                <p class="welcome-subtitle">Your comprehensive platform for managing library operations, from book cataloging to member services.</p>
            </div>
        </div>

        <!-- Right Side - Login Form -->
        <div class="form-side">
            <div class="form-container">
                <div class="form-header">
                    <h2 class="form-title">Sign In</h2>
                    <p class="form-subtitle">Sign in to your admin account</p>
                </div>

                <!-- Alerts -->
                <?php if (!empty($login_error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($login_error); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Login Form -->
                <form method="POST" id="loginForm">
                    <div class="form-group">
                        <label for="username_email">Email address</label>
                        <input type="text" 
                               id="username_email" 
                               name="username_email" 
                               class="form-input"
                               placeholder="Enter your email or username"
                               required
                               value="<?php echo htmlspecialchars($_POST['username_email'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-wrapper">
                            <input type="password" 
                                   id="password" 
                                   name="password" 
                                   class="form-input"
                                   placeholder="Enter your password"
                                   required>
                            <button type="button" class="password-toggle" onclick="togglePassword()">
                                <i class="fas fa-eye" id="toggleIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-options">
                        <div class="checkbox-group">
                            <input type="checkbox" id="remember_me" name="remember_me">
                            <label for="remember_me">Remember me</label>
                        </div>
                        <a href="#" class="forgot-link">Forgot password?</a>
                    </div>

                    <div class="button-group">
                        <button type="submit" class="btn btn-primary" id="loginBtn">
                            <span class="btn-text">Login</span>
                            <span class="btn-loading" id="loadingText">
                                <div class="spinner"></div>
                                Signing in...
                            </span>
                        </button>
                    </div>
                </form>

                <!-- Social Links -->
                <div class="social-section">
                    <p class="social-text">Follow us</p>
                    <div class="social-links">
                        <a href="#" class="social-link facebook">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="#" class="social-link twitter">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="#" class="social-link linkedin">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Password toggle function
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // Form submission with loading state
        document.getElementById('loginForm').addEventListener('submit', function() {
            const loginBtn = document.getElementById('loginBtn');
            const btnText = loginBtn.querySelector('.btn-text');
            const btnLoading = loginBtn.querySelector('.btn-loading');
            
            btnText.classList.add('hidden');
            btnLoading.classList.add('active');
            loginBtn.disabled = true;
        });

        // Auto-focus on first input
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('username_email').focus();
        });
    </script>
</body>
</html>