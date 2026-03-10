<?php
// reset_password2.php - Process password reset with validation
session_start();
header('Content-Type: application/json');

// Database configuration
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Verify password reset flow
if (!isset($_SESSION['auth_mode']) || $_SESSION['auth_mode'] !== 'password_reset') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid session. Please start password reset again.'
    ]);
    exit();
}

// Verify request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method.'
    ]);
    exit();
}

// Get session user ID
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Session expired. Please log in again.'
    ]);
    exit();
}

// Get form data
$currentPassword = $_POST['current_password'] ?? '';
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

$errors = [];

// Validate current password presence
if (empty($currentPassword)) {
    $errors[] = 'Current password is required.';
}

// Validate new password
if (empty($newPassword)) {
    $errors[] = 'New password is required.';
} elseif (strlen($newPassword) < 12) {
    $errors[] = 'New password must be at least 12 characters.';
} elseif (!preg_match('/[A-Z]/', $newPassword)) {
    $errors[] = 'New password must contain at least one uppercase letter.';
} elseif (!preg_match('/[a-z]/', $newPassword)) {
    $errors[] = 'New password must contain at least one lowercase letter.';
} elseif (!preg_match('/[0-9]/', $newPassword)) {
    $errors[] = 'New password must contain at least one number.';
} elseif (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $newPassword)) {
    $errors[] = 'New password must contain at least one special character.';
} elseif ($newPassword === $currentPassword) {
    $errors[] = 'New password cannot be the same as current password.';
}

// Validate password confirmation
if ($newPassword !== $confirmPassword) {
    $errors[] = 'Passwords do not match.';
}

// Return errors if any
if (!empty($errors)) {
    echo json_encode([
        'status' => 'error',
        'message' => implode(' ', $errors)
    ]);
    exit();
}

try {
    // Database connection
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Fetch current password hash
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode([
            'status' => 'error',
            'message' => 'User not found.'
        ]);
        exit();
    }

    // Verify current password
    if (!password_verify($currentPassword, $user['password_hash'])) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Current password is incorrect.'
        ]);
        exit();
    }

    // Hash new password
    $newPasswordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

    // Update password and clear reset flags
    $updateStmt = $pdo->prepare("
        UPDATE users 
        SET 
            password_hash = :new_hash,
            password_reset_status = 'no',
            password_reset_expires = NULL,
            password_expiry = DATE_ADD(NOW(), INTERVAL 90 DAY),
            last_password_change = NOW(),
            updated_at = NOW()
        WHERE user_id = :user_id
    ");

    $updateStmt->execute([
        ':new_hash' => $newPasswordHash,
        ':user_id' => $userId
    ]);

    // Check if update was successful
    if ($updateStmt->rowCount() === 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to update password. Please try again.'
        ]);
        exit();
    }

    // Clear password reset session
    unset($_SESSION['auth_mode']);
    unset($_SESSION['force_reset_reason']);
    unset($_SESSION['user_id']);
    unset($_SESSION['user_email']);
    unset($_SESSION['user_name']);

    // Set success message for login page
    $_SESSION['reset_success'] = 'Password updated successfully! Please log in with your new password.';

    // Return success response
    echo json_encode([
        'status' => 'success',
        'message' => 'Password reset successfully! Redirecting to login...',
        'redirect' => '../../../Login_page/PHP/Frontend/Login_page_site.php'
    ]);

} catch (PDOException $e) {
    error_log("Database error in reset_password2: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'System error. Please try again later.'
    ]);
} catch (Exception $e) {
    error_log("General error in reset_password2: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'An unexpected error occurred.'
    ]);
}
?>