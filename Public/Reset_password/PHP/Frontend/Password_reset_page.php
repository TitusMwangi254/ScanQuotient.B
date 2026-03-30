<?php
// password_reset_page4.php - ScanQuotient Password Reset
session_start();
require_once __DIR__ . '/../../../security_headers.php';

// Verify user is in password reset flow
if (!isset($_SESSION['auth_mode']) || $_SESSION['auth_mode'] !== 'password_reset') {
    header('Location: ../../../Login_page/PHP/Frontend/Login_page_site.php');
    exit();
}

// Get user info from session
$userId = $_SESSION['user_id'] ?? '';
$resetReason = $_SESSION['force_reset_reason'] ?? 'required';

// Get username from database if not in session
$username = $_SESSION['user_name'] ?? '';
if (empty($username) && !empty($userId)) {
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;dbname=scanquotient.a1;charset=utf8mb4", "root", "");
        $stmt = $pdo->prepare("SELECT user_name FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $username = $user['user_name'] ?? '';
    } catch (Exception $e) {
        error_log("Error fetching username: " . $e->getMessage());
    }
}

// Status message based on reason
$statusMessages = [
    'expired' => 'Your password reset link has expired. Please set a new password.',
    'required' => 'A password reset is required for your account security.',
    'password_expired' => 'Your password has expired. Please create a new password to continue.',
    'user_requested' => 'You requested to change your password. Enter your current password to verify your identity.'
];
$statusMessage = $statusMessages[$resetReason] ?? $statusMessages['required'];
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScanQuotient | Reset Password</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../../CSS/reset_password.css" />


</head>

<body>

    <header class="header">
        <div class="nav-container">
            <div class="brand">
                <h1>ScanQuotient</h1>
                <span class="tagline">Quantifying risk. Strengthening security.</span>
            </div>
            <div class="header-actions">

                <div class="theme-switch-wrapper">
                    <label class="theme-switch" for="theme-toggle" title="Toggle theme">
                        <input type="checkbox" id="theme-toggle">
                    </label>
                </div>

                <a href="../../../Help_center/PHP/Frontend/Help_center.php" class="icon-btn" title="Help">
                    <i class="fas fa-question-circle"></i>
                </a>
                <a href="../../../Homepage/PHP/Frontend/Homepage.php" class="icon-btn" title="Home">
                    <i class="fas fa-home"></i>
                </a>
            </div>
        </div>
    </header>

    <!-- MAIN CONTENT -->
    <main class="main-content">
        <div class="reset-card">
            <div class="page-header">
                <h2><i class="fas fa-key"></i> Reset Your Password</h2>
                <p>Create a new secure password for your account</p>
            </div>

            <div class="status-banner">
                <i class="fas fa-exclamation-triangle"></i>
                <p>
                    <?php echo htmlspecialchars($statusMessage); ?>
                </p>
            </div>

            <form id="resetForm" action="../../PHP/Backend/reset_password.php" method="POST">
                <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($userId); ?>">
                <input type="hidden" name="username" value="<?php echo htmlspecialchars($username); ?>">
                <input type="hidden" name="reset_reason" value="<?php echo htmlspecialchars($resetReason); ?>">

                <div class="form-content">
                    <!-- Left: Validation Rules -->
                    <div class="validation-panel">
                        <h3><i class="fas fa-shield-alt"></i> Password Requirements</h3>
                        <ul class="validation-list" id="validationList">
                            <li id="lengthCriterion"><i class="fas fa-times-circle"></i> At least 12 characters</li>
                            <li id="uppercaseCriterion"><i class="fas fa-times-circle"></i> One uppercase letter (A-Z)
                            </li>
                            <li id="lowercaseCriterion"><i class="fas fa-times-circle"></i> One lowercase letter (a-z)
                            </li>
                            <li id="numberCriterion"><i class="fas fa-times-circle"></i> One number (0-9)</li>
                            <li id="specialCharCriterion"><i class="fas fa-times-circle"></i> One special character
                                (!@#$%^&*)</li>
                            <li id="matchCriterion"><i class="fas fa-times-circle"></i> Passwords match</li>
                        </ul>
                    </div>

                    <!-- Right: Input Fields -->
                    <div class="form-panel">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <div class="input-wrapper">
                                <input type="password" id="current_password" name="current_password"
                                    class="form-control" placeholder="Enter current password" required>
                                <i class="fas fa-eye-slash toggle-password" data-target="current_password"></i>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <div class="input-wrapper">
                                <input type="password" id="new_password" name="new_password" class="form-control"
                                    placeholder="Enter new password" required>
                                <i class="fas fa-eye-slash toggle-password" data-target="new_password"></i>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <div class="input-wrapper">
                                <input type="password" id="confirm_password" name="confirm_password"
                                    class="form-control" placeholder="Confirm new password" required>
                                <i class="fas fa-eye-slash toggle-password" data-target="confirm_password"></i>
                            </div>
                        </div>

                        <button type="submit" class="btn-reset" id="resetBtn" disabled>
                            <i class="fas fa-check"></i> Reset Password
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <!-- TOAST CONTAINER -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- FOOTER -->
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-brand">
                <h3>ScanQuotient</h3>
                <p>Quantifying risk. Strengthening security.</p>
            </div>

            <div class="footer-social">
                <a href="#" title="Twitter"><i class="fab fa-twitter"></i></a>
                <a href="#" title="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                <a href="#" title="GitHub"><i class="fab fa-github"></i></a>
                <a href="mailto:support@scanquotient.com" title="Email"><i class="fas fa-envelope"></i></a>
            </div>
        </div>

        <div class="footer-bottom">
            <p>&copy; 2026 ScanQuotient. All rights reserved. | Securing your digital assets.</p>
        </div>
    </footer>

    <script src="../../Javascript/reset_password.js" defer></script>
</body>

</html>