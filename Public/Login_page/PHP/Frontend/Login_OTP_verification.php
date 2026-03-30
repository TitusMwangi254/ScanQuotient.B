<?php
// two_factor_verification.php - ScanQuotient 2FA Verification
session_start();
require_once __DIR__ . '/../../../security_headers.php';

// Verify 2FA flow
if (!isset($_SESSION['auth_mode']) || $_SESSION['auth_mode'] !== '2fa_verification') {
    header('Location: ../../../Login_page/PHP/Frontend/Login_page_site.php');
    exit();
}

$email = $_SESSION['2fa_email'] ?? '';
$maskedEmail = maskEmail($email);

function maskEmail($email)
{
    if (empty($email))
        return '';
    $parts = explode('@', $email);
    $name = $parts[0];
    $domain = $parts[1] ?? '';

    $maskedName = substr($name, 0, 2) . str_repeat('*', max(0, strlen($name) - 2));
    return $maskedName . '@' . $domain;
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScanQuotient | Two-Factor Authentication</title>
     <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

 <link rel="stylesheet" href="../../CSS/login_OTP_verification.css" />
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
    
    <a href="../../../Help_center/PHP/Frontend/Help_center.php"
        class="icon-btn" title="Help">
        <i class="fas fa-question-circle"></i>
    </a>
    <a href="../../../Homepage/PHP/Frontend/Homepage.php" class="icon-btn"
        title="Home">
        <i class="fas fa-home"></i>
    </a>
</div>
        </div>
    </header>

    <!-- MAIN CONTENT -->
    <main class="main-content">
        <div class="otp-container">
            <div class="security-icon">
                <i class="fas fa-shield-alt"></i>
            </div>

            <h2>Two-Factor Authentication</h2>

            <p class="email-display">
                Enter the 6-digit code sent to:
                <strong>
                    <?php echo htmlspecialchars($maskedEmail); ?>
                </strong>
            </p>

            <form id="verify2FAForm">
                <div class="otp-inputs">
                    <input type="text" maxlength="1" class="otp-input" required pattern="[0-9]">
                    <input type="text" maxlength="1" class="otp-input" required pattern="[0-9]">
                    <input type="text" maxlength="1" class="otp-input" required pattern="[0-9]">
                    <input type="text" maxlength="1" class="otp-input" required pattern="[0-9]">
                    <input type="text" maxlength="1" class="otp-input" required pattern="[0-9]">
                    <input type="text" maxlength="1" class="otp-input" required pattern="[0-9]">
                    <input type="hidden" name="code" id="otp_combined">
                </div>

                <div id="timer">
                    <i class="fas fa-clock"></i>
                    <span>Code expires in: 10:00</span>
                </div>

                <button type="submit" id="verifyBtn" class="btn btn-primary">
                    <i class="fas fa-check-circle"></i>
                    Verify & Continue
                </button>

                <div class="resend-section">
                    <p class="resend-text">Didn't receive the code?</p>
                    <button id="resendBtn" type="button" class="btn btn-secondary" disabled>
                        <i class="fas fa-redo"></i>
                        Resend Code
                    </button>
                </div>
            </form>

            <a href="../../../Login_page/PHP/Frontend/Login_page_site.php"
                class="btn btn-link">
                <i class="fas fa-times-circle"></i>
                Cancel & Return to Login
            </a>
        </div>
    </main>

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

    <div id="verifyOverlay" class="verify-overlay" hidden aria-hidden="true" role="alertdialog" aria-busy="true" aria-labelledby="verifyOverlayText">
        <div class="verify-overlay__backdrop"></div>
        <div class="verify-overlay__panel">
            <div class="verify-overlay__icon-wrap" aria-hidden="true">
                <div class="verify-overlay__spinner"></div>
                <span class="verify-overlay__tick"><i class="fas fa-check" aria-hidden="true"></i></span>
            </div>
            <p id="verifyOverlayText" class="verify-overlay__text">Verifying your code…</p>
        </div>
    </div>

<script src="../../Javascript/login_OTP_verification.js" defer></script>
    
</body>

</html>