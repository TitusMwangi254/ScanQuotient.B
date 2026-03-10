<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['fp_verified']) || !$_SESSION['fp_verified']) {
    echo json_encode(['status' => 'error', 'message' => 'Not verified']);
    exit();
}

$password = $_POST['password'] ?? '';

if (strlen($password) < 12) {
    echo json_encode(['status' => 'error', 'message' => 'Password too short']);
    exit();
}

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=scanquotient.a1;charset=utf8mb4", "root", "");

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    $stmt = $pdo->prepare("
        UPDATE users 
        SET password_hash = ?,
            password_expiry = DATE_ADD(NOW(), INTERVAL 90 DAY),
            password_reset_status = 'no',
            password_reset_expires = NULL,
            last_password_change = NOW(),
            two_factor_enabled = 'yes',
            updated_at = NOW()
        WHERE user_id = ?
    ");
    $stmt->execute([$hash, $_SESSION['fp_user_id']]);

    session_destroy();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'System error']);
}
?>