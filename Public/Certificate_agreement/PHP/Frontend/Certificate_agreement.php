<?php
// Certificate_agreement.php - User must accept admin-issued certificate before proceeding
session_start();
require_once __DIR__ . '/../../../security_headers.php';
date_default_timezone_set('Africa/Nairobi');

if (!isset($_SESSION['auth_mode']) || $_SESSION['auth_mode'] !== 'certificate_agreement') {
    header('Location: ../../../Login_page/PHP/Frontend/Login_page_site.php');
    exit();
}

$certId = (int)($_SESSION['cert_id'] ?? 0);
$userId = (string)($_SESSION['cert_user_id'] ?? '');
if ($certId <= 0 || $userId === '') {
    header('Location: ../../../Login_page/PHP/Frontend/Login_page_site.php');
    exit();
}

$certificate = null;
$error = '';

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=scanquotient.a1;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $stmt = $pdo->prepare("SELECT * FROM security_certificates WHERE id = ? AND is_active = 'yes' LIMIT 1");
    $stmt->execute([$certId]);
    $certificate = $stmt->fetch();

    if (!$certificate) {
        $error = 'This certificate is no longer available.';
    }
} catch (Exception $e) {
    error_log("Certificate agreement load error: " . $e->getMessage());
    $error = 'System error. Please try again.';
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScanQuotient | Certificate Agreement</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../../CSS/certificate_agreement.css" />
</head>

<body>
    <header class="header" id="sqHeader">
        <div class="nav-container">
            <div class="brand">
                <h1>ScanQuotient</h1>
                <span class="tagline">Quantifying risk. Strengthening security.</span>
            </div>
            <div class="header-actions">
                <button type="button" class="icon-btn" id="sqThemeBtn" title="Toggle theme" aria-label="Toggle theme">
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
    </header>

    <main class="main-content">
        <div class="agreement-card">
            <div class="page-header">
                <h2><i class="fas fa-file-signature"></i> Certificate Agreement</h2>
                <p>You must review and accept this certificate to continue.</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="status-banner status-banner--error">
                    <i class="fas fa-exclamation-circle"></i>
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
                <div style="display:flex; justify-content:flex-end; margin-top: 14px;">
                    <a class="btn-secondary" href="../../../Login_page/PHP/Frontend/Login_page_site.php">
                        <i class="fas fa-arrow-left"></i> Back to login
                    </a>
                </div>
            <?php else: ?>
                <div class="certificate-meta">
                    <div class="meta-pill">
                        <i class="fas fa-id-badge"></i>
                        Certificate #<?php echo (int)$certificate['id']; ?>
                    </div>
                    <div class="meta-pill">
                        <i class="fas fa-user"></i>
                        <?php echo htmlspecialchars($_SESSION['cert_username'] ?? ''); ?>
                    </div>
                    <div class="meta-pill">
                        <i class="fas fa-shield-alt"></i>
                        <?php echo htmlspecialchars($_SESSION['cert_role'] ?? ''); ?>
                    </div>
                </div>

                <div class="certificate-box">
                    <h3 class="certificate-title"><?php echo htmlspecialchars($certificate['title']); ?></h3>
                    <div class="certificate-body">
                        <?php echo nl2br(htmlspecialchars($certificate['body'])); ?>
                    </div>
                </div>

                <form action="../Backend/accept_certificate.php" method="POST" class="agreement-form">
                    <input type="hidden" name="certificate_id" value="<?php echo (int)$certificate['id']; ?>">
                    <div class="checkbox-group">
                        <input type="checkbox" id="agreeCert" name="agree" value="yes" required>
                        <label for="agreeCert">
                            I have read and agree to the certificate above.
                        </label>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-check"></i> Agree & Continue
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <footer class="footer">
        <div class="footer-grid">
            <div class="footer-left">
                <div class="footer-brand">
                    <h3>ScanQuotient</h3>
                    <p>Quantifying risk. Strengthening security.</p>
                </div>
            </div>
            <div class="footer-middle">
                <div class="footer-center-text">
                    &copy; <?php echo date('Y'); ?> ScanQuotient. All rights reserved.
                </div>
            </div>
            <div class="footer-right">
                <a class="footer-email" href="mailto:support@scanquotient.com">
                    <i class="fas fa-envelope"></i>
                    support@scanquotient.com
                </a>
            </div>
        </div>
    </footer>

    <script src="../../Javascript/certificate_agreement.js" defer></script>
</body>

</html>

