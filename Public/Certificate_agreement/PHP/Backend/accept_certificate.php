<?php
// accept_certificate.php - records acceptance then completes login
session_start();
date_default_timezone_set('Africa/Nairobi');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../../Login_page/PHP/Frontend/Login_page_site.php');
    exit();
}

if (!isset($_SESSION['auth_mode']) || $_SESSION['auth_mode'] !== 'certificate_agreement') {
    header('Location: ../../../Login_page/PHP/Frontend/Login_page_site.php');
    exit();
}

$certIdPosted = (int)($_POST['certificate_id'] ?? 0);
$agree = ($_POST['agree'] ?? '') === 'yes';
$certIdSession = (int)($_SESSION['cert_id'] ?? 0);
$userId = (string)($_SESSION['cert_user_id'] ?? '');

if (!$agree || $certIdPosted <= 0 || $certIdSession <= 0 || $certIdPosted !== $certIdSession || $userId === '') {
    header('Location: ../Frontend/Certificate_agreement.php');
    exit();
}

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=scanquotient.a1;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Ensure acceptance table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS security_certificate_acceptances (
            id INT AUTO_INCREMENT PRIMARY KEY,
            certificate_id INT NOT NULL,
            user_id VARCHAR(255) NOT NULL,
            accepted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            accepted_ip VARCHAR(45) NULL,
            accepted_user_agent TEXT NULL,
            UNIQUE KEY uniq_cert_user (certificate_id, user_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Record acceptance (idempotent)
    $stmt = $pdo->prepare("
        INSERT INTO security_certificate_acceptances (certificate_id, user_id, accepted_ip, accepted_user_agent)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE accepted_at = CURRENT_TIMESTAMP, accepted_ip = VALUES(accepted_ip), accepted_user_agent = VALUES(accepted_user_agent)
    ");
    $stmt->execute([
        $certIdSession,
        $userId,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    // Fetch user and check if more certificates are pending
    $uStmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
    $uStmt->execute([$userId]);
    $user = $uStmt->fetch();
    if (!$user) {
        session_destroy();
        header('Location: ../../../Login_page/PHP/Frontend/Login_page_site.php');
        exit();
    }

    // If more pending certificates exist, show next one immediately (no logout required)
    try {
        $hasCerts = (bool) $pdo->query("SHOW TABLES LIKE 'security_certificates'")->fetch();
        $hasAccept = (bool) $pdo->query("SHOW TABLES LIKE 'security_certificate_acceptances'")->fetch();
        if ($hasCerts && $hasAccept) {
            $pendingStmt = $pdo->prepare("
                SELECT c.id
                FROM security_certificates c
                WHERE c.is_active = 'yes'
                  AND (
                        c.target_type = 'everyone'
                        OR (c.target_type = 'role' AND c.target_value = :role)
                        OR (c.target_type = 'user_id' AND c.target_value = :uid)
                        OR (c.target_type = 'username' AND c.target_value = :uname)
                  )
                  AND NOT EXISTS (
                        SELECT 1
                        FROM security_certificate_acceptances a
                        WHERE a.certificate_id = c.id AND a.user_id = :uid_check
                  )
                ORDER BY c.created_at DESC
                LIMIT 1
            ");
            $pendingStmt->execute([
                ':uid' => $user['user_id'],
                ':uid_check' => $user['user_id'],
                ':role' => $user['role'],
                ':uname' => $user['user_name'],
            ]);
            $pending = $pendingStmt->fetch();
            if ($pending) {
                // Stay in certificate flow and load the next certificate
                $_SESSION['auth_mode'] = 'certificate_agreement';
                $_SESSION['cert_id'] = (int) $pending['id'];
                $_SESSION['cert_user_id'] = $user['user_id'];
                $_SESSION['cert_role'] = $user['role'];
                $_SESSION['cert_username'] = $user['user_name'];
                $_SESSION['cert_email'] = $user['email'];

                header('Location: ../Frontend/Certificate_agreement.php');
                exit();
            }
        }
    } catch (Throwable $e) {
        // If this check fails, continue to complete login.
        error_log("Next certificate check warning (non-fatal): " . $e->getMessage());
    }

    // Complete login
    session_regenerate_id(true);
    $_SESSION['authenticated'] = true;
    $_SESSION['login_time'] = time();
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? null;
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $_SESSION['role'] = $user['role'];
    $_SESSION['user_pk'] = $user['id'];
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['user_name'] = $user['user_name'];
    $_SESSION['profile_photo'] = $user['profile_photo'];
    $_SESSION['user_email'] = $user['email'];

    // Clear agreement flow flags
    unset($_SESSION['auth_mode']);
    unset($_SESSION['cert_id']);
    unset($_SESSION['cert_user_id']);
    unset($_SESSION['cert_role']);
    unset($_SESSION['cert_username']);
    unset($_SESSION['cert_email']);

    $redirect = ($user['role'] === 'admin' || $user['role'] === 'super_admin')
        ? '../../../../Private/Admin_dashboard/PHP/Frontend/Admin_dashboard.php'
        : '../../../../Private/User_dashboard/PHP/Frontend/User_dashboard.php';

    header("Location: {$redirect}");
    exit();

} catch (Exception $e) {
    error_log("Certificate acceptance error: " . $e->getMessage());
    header('Location: ../Frontend/Certificate_agreement.php');
    exit();
}

