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

    // Rate limit to prevent account enumeration (per IP)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS security_rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            scope VARCHAR(50) NOT NULL,
            scope_key VARCHAR(255) NOT NULL,
            fail_count INT NOT NULL DEFAULT 0,
            first_fail_at DATETIME NULL,
            last_fail_at DATETIME NULL,
            locked_until DATETIME NULL,
            lock_minutes INT NULL,
            reason VARCHAR(80) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_scope_key (scope, scope_key),
            INDEX idx_locked_until (locked_until),
            INDEX idx_last_fail (last_fail_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $lockStmt = $pdo->prepare("SELECT locked_until FROM security_rate_limits WHERE scope = 'fp_find_ip' AND scope_key = ? LIMIT 1");
    $lockStmt->execute([$ip]);
    $lockRow = $lockStmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($lockRow['locked_until']) && strtotime($lockRow['locked_until']) > time()) {
        $mins = (int)ceil((strtotime($lockRow['locked_until']) - time()) / 60);
        echo json_encode(['status' => 'error', 'message' => "Too many attempts. Please wait {$mins} minute(s) and try again."]);
        exit();
    }

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
        // Failed lookup -> count attempt. 10 fails within 15 minutes => lock 15 minutes
        $now = time();
        $rowStmt = $pdo->prepare("SELECT fail_count, last_fail_at, first_fail_at FROM security_rate_limits WHERE scope = 'fp_find_ip' AND scope_key = ? LIMIT 1");
        $rowStmt->execute([$ip]);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $reset = true;
        if ($row && !empty($row['last_fail_at'])) {
            $reset = strtotime($row['last_fail_at']) < ($now - 15 * 60);
        }
        $fails = $reset ? 0 : (int)($row['fail_count'] ?? 0);
        $fails++;
        $lockedUntil = null;
        $lockMinutes = null;
        $reason = null;
        if ($fails >= 10) {
            $lockMinutes = 15;
            $lockedUntil = date('Y-m-d H:i:s', $now + 15 * 60);
            $reason = 'fp_find_lock';
        }
        $firstFailAt = ($reset || !$row || empty($row['first_fail_at'])) ? date('Y-m-d H:i:s', $now) : $row['first_fail_at'];
        $up = $pdo->prepare("
            INSERT INTO security_rate_limits (scope, scope_key, fail_count, first_fail_at, last_fail_at, locked_until, lock_minutes, reason)
            VALUES ('fp_find_ip', ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                fail_count = VALUES(fail_count),
                first_fail_at = VALUES(first_fail_at),
                last_fail_at = VALUES(last_fail_at),
                locked_until = VALUES(locked_until),
                lock_minutes = VALUES(lock_minutes),
                reason = VALUES(reason)
        ");
        $up->execute([$ip, $fails, $firstFailAt, date('Y-m-d H:i:s', $now), $lockedUntil, $lockMinutes, $reason]);

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