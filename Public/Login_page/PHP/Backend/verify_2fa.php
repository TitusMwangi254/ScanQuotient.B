<?php
// verify_2fa.php - Verify 2FA code and complete login
session_start();
header('Content-Type: application/json');

// Verify 2FA session
if (!isset($_SESSION['auth_mode']) || $_SESSION['auth_mode'] !== '2fa_verification') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid session'
    ]);
    exit();
}

// Get submitted code
$submittedCode = $_POST['code'] ?? '';

// Validate code format
if (!preg_match('/^\d{6}$/', $submittedCode)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid code format'
    ]);
    exit();
}

// Check session code exists
if (!isset($_SESSION['2fa_code']) || !isset($_SESSION['2fa_expires'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'No verification code found. Please request a new code.'
    ]);
    exit();
}

// Check if code expired
if (strtotime($_SESSION['2fa_expires']) < time()) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Verification code has expired. Please resend.'
    ]);
    exit();
}

// Verify code matches
if ($submittedCode !== $_SESSION['2fa_code']) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid verification code'
    ]);
    exit();
}

// Code verified - complete login
try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=scanquotient.a1;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Update last activity
    $stmt = $pdo->prepare("UPDATE users SET updated_at = NOW() WHERE user_id = ?");
    $stmt->execute([$_SESSION['2fa_user_id']]);

    // Regenerate session ID for security
    session_regenerate_id(true);

    // Set authenticated session
    $_SESSION['authenticated'] = true;
    $_SESSION['user_id'] = $_SESSION['2fa_user_id'];
    $_SESSION['username'] = $_SESSION['2fa_username'];
    $_SESSION['email'] = $_SESSION['2fa_email'];
    $_SESSION['role'] = $_SESSION['2fa_role'];
    $_SESSION['login_time'] = time();

    // Clear 2FA data
    unset($_SESSION['auth_mode']);
    unset($_SESSION['2fa_pending']);
    unset($_SESSION['2fa_code']);
    unset($_SESSION['2fa_expires']);
    unset($_SESSION['2fa_user_id']);
    unset($_SESSION['2fa_username']);
    unset($_SESSION['2fa_email']);
    unset($_SESSION['2fa_role']);

    // Determine redirect
    $redirect = ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super_admin')
        ? '../../../../Private/Admin_dashboard/PHP/Frontend/Admin_dashboard.php'
        : '../../../../Private/User_dashboard/PHP/Frontend/User_dashboard.php';

    echo json_encode([
        'status' => 'success',
        'message' => 'Two-factor authentication successful',
        'redirect' => $redirect
    ]);

} catch (Exception $e) {
    error_log("2FA verification error: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'System error. Please try again.'
    ]);
}
?>