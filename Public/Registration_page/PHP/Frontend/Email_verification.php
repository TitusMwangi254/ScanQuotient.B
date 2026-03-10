<?php
// Only get email from URL (no sessions)
$email = $_GET['email'] ?? null;

if (!$email) {
    die("Invalid verification request.");
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScanQuotient | Verify Email</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="stylesheet" href="../../CSS/Email_verification.css">
</head>

<body>

    <!-- Top Bar -->
    <div class="top-bar">
        <div class="brand">
            <h1>ScanQuotient</h1>
            <span class="tagline">Quantifying Risk. Strengthening Security</span>
        </div>

        <div class="header-icons">
            <button class="icon-btn" id="theme-toggle" title="Toggle Theme">
                <i class="fas fa-moon"></i>
            </button>

            <a href="../../../Help_center/PHP/Frontend/Help_center.php" class="icon-btn" title="Help">
                <i class="fas fa-question-circle"></i>
            </a>

            <a href="../../../Homepage/PHP/Frontend/Homepage.php" class="icon-btn" title="Home">
                <i class="fas fa-home"></i>
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="otp-container">

            <h2>Email Verification</h2>

            <p class="email-display">
                Enter the 6-digit code sent to:
                <strong><?php echo htmlspecialchars($email); ?></strong>
            </p>

            <form id="verifyForm">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">

                <div class="otp-inputs">
                    <input type="text" maxlength="1" class="otp-input" required>
                    <input type="text" maxlength="1" class="otp-input" required>
                    <input type="text" maxlength="1" class="otp-input" required>
                    <input type="text" maxlength="1" class="otp-input" required>
                    <input type="text" maxlength="1" class="otp-input" required>
                    <input type="text" maxlength="1" class="otp-input" required>
                    <input type="hidden" name="code" id="otp_combined">
                </div>

                <div id="timer">Code expires in: 05:00</div>

                <button type="submit" id="verifyBtn" class="btn btn-primary">
                    <i class="fas fa-shield-alt"></i>
                    Verify Code
                </button>

                <div class="resend-section">
                    <p class="resend-text">Didn't receive the code?</p>
                    <button id="resendBtn" type="button" class="btn btn-secondary">
                        <i class="fas fa-redo"></i>
                        Resend Code
                    </button>
                </div>
            </form>

            <a href="../../../Registration_page/PHP/Frontend/Registration_page.php" class="btn btn-link">
                <i class="fas fa-arrow-left"></i>
                Back to Registration
            </a>

        </div>
    </div>
    <input type="hidden" id="userEmail" value="<?php echo htmlspecialchars($email); ?>">
    <!-- Footer -->
    <footer class="footer">
        <div class="footer-brand">ScanQuotient</div>
        <div class="footer-tagline">Quantifying Risk. Strengthening Security</div>
        <div class="footer-copyright">&copy;
            <?php echo date('Y'); ?> All rights reserved.
        </div>
    </footer>

    <script src="../../Javascript/email_verification.js"></script>

</body>

</html>