<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';

// Check if reader is already logged in
if (isset($_SESSION['reader_logged_in']) && $_SESSION['reader_logged_in'] === true) {
    header('Location: dashboard/');
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
            
            // Check if input is email or username/member_code
            $field = filter_var($username_email, FILTER_VALIDATE_EMAIL) ? 'email' : 'member_code';
            
            // FIXED: Use password_hash instead of password
            $stmt = $db->prepare("
                SELECT member_id, member_code, first_name, last_name, email, password_hash, 
                       membership_type, membership_expiry, status, profile_image 
                FROM members 
                WHERE {$field} = ? AND status = 'active'
            ");
            $stmt->execute([$username_email]);
            $member = $stmt->fetch();
            
            if (!$member) {
                $login_error = 'No account found with this ' . ($field === 'email' ? 'email' : 'member code') . '.';
            } elseif ($member['status'] !== 'active') {
                $login_error = 'Your account is not active. Please contact the library.';
            } else {
                // FIXED: Check membership expiry
                $membership_expired = false;
                if ($member['membership_expiry']) {
                    $expiry_date = new DateTime($member['membership_expiry']);
                    $current_date = new DateTime();
                    if ($expiry_date < $current_date) {
                        $membership_expired = true;
                    }
                }
                
                // FIXED: Use password_hash field
                if (password_verify($password, $member['password_hash'])) {
                    // Set session variables
                    $_SESSION['reader_logged_in'] = true;
                    $_SESSION['reader_id'] = $member['member_id'];
                    $_SESSION['reader_code'] = $member['member_code'];
                    $_SESSION['reader_name'] = $member['first_name'] . ' ' . $member['last_name'];
                    $_SESSION['reader_email'] = $member['email'];
                    $_SESSION['reader_type'] = $member['membership_type'];
                    $_SESSION['reader_avatar'] = $member['profile_image'];
                    $_SESSION['reader_login_time'] = time();
                    $_SESSION['membership_expired'] = $membership_expired;
                    $_SESSION['membership_expiry'] = $member['membership_expiry'];
                    
                    // Handle remember me
                    if ($remember_me) {
                        $token = bin2hex(random_bytes(32));
                        $expires = time() + (30 * 24 * 60 * 60); // 30 days
                        
                        setcookie('remember_token', $token, $expires, '/', '', false, true);
                        
                        // Store token in database (add these fields if they don't exist)
                        try {
                            $stmt = $db->prepare("UPDATE members SET remember_token = ?, token_expires = FROM_UNIXTIME(?) WHERE member_id = ?");
                            $stmt->execute([$token, $expires, $member['member_id']]);
                        } catch (Exception $e) {
                            // If remember token fields don't exist, just continue without remember me
                            error_log("Remember token error: " . $e->getMessage());
                        }
                    }
                    
                    // Update last login (add this field if it doesn't exist)
                    try {
                        $update_stmt = $db->prepare("UPDATE members SET last_login = NOW() WHERE member_id = ?");
                        $update_stmt->execute([$member['member_id']]);
                    } catch (Exception $e) {
                        // If last_login field doesn't exist, just continue
                        error_log("Last login update error: " . $e->getMessage());
                    }
                    
                    header('Location: dashboard/');
                    exit();
                } else {
                    $login_error = 'Invalid username or password. Please check and try again.';
                }
            }
        } catch (PDOException $e) {
            $login_error = 'Database error. Please try again later.';
            error_log("Reader login error: " . $e->getMessage());
        } catch (Exception $e) {
            $login_error = 'System error. Please try again later.';
            error_log("Reader login error: " . $e->getMessage());
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

// Check for registration success
if (isset($_GET['registered'])) {
    $success_message = 'Registration successful! You can now login with your credentials.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reader Login - <?php echo SITE_NAME; ?></title>
    
    <!-- Stylesheets -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo ASSETS_URL; ?>css/reader.css" rel="stylesheet">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo ASSETS_URL; ?>images/favicon.ico">
</head>
<body>
    <div class="login-container">
        <!-- Left Side - Login Form -->
        <div class="login-side">
            <!-- Back Button -->
            <a href="../" class="back-btn">
                <i class="fas fa-arrow-left"></i>
            </a>

            <!-- Logo -->
            <div class="logo-section">
                <img src="<?php echo ASSETS_URL; ?>images/logo.png" alt="ABC Library" class="logo">
            </div>

            <!-- Login Form -->
            <div class="login-form-container">
                <div class="login-header">
                    <h1>Welcome back</h1>
                    <p>Please enter your details</p>
                </div>

                <?php if (!empty($login_error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo htmlspecialchars($login_error); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" id="loginForm">
                    <div class="form-group">
                        <label for="username_email">Email or Member Code</label>
                        <input type="text" 
                               id="username_email" 
                               name="username_email" 
                               placeholder="john.doe@email.com or MEM001"
                               required
                               value="<?php echo htmlspecialchars($_POST['username_email'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-input">
                            <input type="password" 
                                   id="password" 
                                   name="password" 
                                   placeholder="••••••••"
                                   required>
                            <button type="button" class="password-toggle" onclick="togglePassword()">
                                <i class="fas fa-eye" id="toggleIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-options">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" id="remember_me" name="remember_me">
                            <label for="remember_me">Remember for 30 days</label>
                        </div>
                        <a href="contact.php?forgot=1" class="forgot-link">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn-signin" id="signinBtn">
                        <span class="btn-text">Sign in</span>
                        <span class="btn-loading d-none">
                            <i class="fas fa-spinner fa-spin me-2"></i>Signing in...
                        </span>
                    </button>

                    <button type="button" class="btn-google" onclick="showGoogleNotice()">
                        <i class="fab fa-google me-2"></i>
                        Sign in with Google
                    </button>
                </form>

                <div class="signup-section">
                    <p>Don't have an account? <a href="contact.php?signup=1">Sign up</a></p>
                </div>
            </div>
        </div>

        <div class="login-side">
            <img src="../assets/images/studentlib.png" alt="book" width="80%vh" height="auto">
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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

        // Google sign in notice
        function showGoogleNotice() {
            alert('Google Sign-In is not implemented yet. Please use your email and password to login.');
        }

        // Form submission with loading state
        document.getElementById('loginForm').addEventListener('submit', function() {
            const signinBtn = document.getElementById('signinBtn');
            const btnText = signinBtn.querySelector('.btn-text');
            const btnLoading = signinBtn.querySelector('.btn-loading');
            
            btnText.classList.add('d-none');
            btnLoading.classList.remove('d-none');
            signinBtn.disabled = true;
        });

        // Auto-fill test credentials for development
        <?php if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE): ?>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('username_email').value = 'john.doe@email.com';
            document.getElementById('password').value = 'password';
        });
        <?php endif; ?>
    </script>
    
    <script src="<?php echo ASSETS_URL; ?>js/reader.js"></script>
</body>
</html>
