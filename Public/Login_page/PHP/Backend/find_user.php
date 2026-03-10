<?php
session_start();
header('Content-Type: application/json');

$id = trim($_POST['identifier'] ?? '');

if (empty($id)) {
    echo json_encode(['status' => 'error', 'message' => 'Required']);
    exit();
}

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=scanquotient.a1;charset=utf8mb4", "root", "");

    $stmt = $pdo->prepare("
        SELECT user_id, email, recovery_email, security_question, 
               account_active, deleted_at
        FROM users 
        WHERE user_name = ? OR email = ? OR recovery_email = ?
        LIMIT 1
    ");
    $stmt->execute([$id, $id, $id]);
    $user = $stmt->fetch();

    if (!$user || $user['deleted_at'] || $user['account_active'] !== 'yes') {
        echo json_encode(['status' => 'error', 'message' => 'Account not found']);
        exit();
    }

    // Store in session
    $_SESSION['fp_user_id'] = $user['user_id'];
    $_SESSION['fp_email'] = $user['email'];
    $_SESSION['fp_recovery'] = $user['recovery_email'];
    $_SESSION['fp_question'] = $user['security_question'];
    $_SESSION['fp_answer'] = ''; // Will verify separately
    $_SESSION['fp_step'] = 'verify';

    // Mask emails
    function mask($e)
    {
        if (empty($e))
            return '';
        $p = explode('@', $e);
        return substr($p[0], 0, 2) . '***@' . $p[1];
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'masked_email' => mask($user['email']),
            'masked_recovery' => mask($user['recovery_email']),
            'has_email' => !empty($user['email']),
            'has_recovery' => !empty($user['recovery_email']) && $user['recovery_email'] !== $user['email'],
            'has_question' => !empty($user['security_question']),
            'question' => $user['security_question']
        ]
    ]);

} catch (Exception $e) {
    error_log("Find user error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'System error']);
}
?>