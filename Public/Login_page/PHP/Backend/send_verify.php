<?php
session_start();
header('Content-Type: application/json');

require_once 'C:/Users/1/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['fp_step']) || $_SESSION['fp_step'] !== 'verify') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid session']);
    exit();
}

$method = $_POST['method'] ?? '';

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=scanquotient.a1;charset=utf8mb4", "root", "");

    // Rate limit table
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
    $userKey = (string)($_SESSION['fp_user_id'] ?? 'unknown');

    $getLock = function (string $scope, string $key) use ($pdo) {
        $st = $pdo->prepare("SELECT * FROM security_rate_limits WHERE scope = ? AND scope_key = ? LIMIT 1");
        $st->execute([$scope, $key]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $isLocked = function (?array $row) {
        return $row && !empty($row['locked_until']) && strtotime($row['locked_until']) > time();
    };
    $minsRemaining = function (?array $row) {
        if (!$row || empty($row['locked_until'])) return 0;
        $diff = strtotime($row['locked_until']) - time();
        return $diff > 0 ? (int)ceil($diff / 60) : 0;
    };
    $fail = function (string $scope, string $key, int $windowSeconds, int $threshold, int $lockMinutes, string $reason) use ($pdo, $getLock) {
        $now = time();
        $row = $getLock($scope, $key);
        $reset = true;
        if ($row && !empty($row['last_fail_at'])) {
            $reset = strtotime($row['last_fail_at']) < ($now - $windowSeconds);
        }
        $fails = $reset ? 0 : (int)($row['fail_count'] ?? 0);
        $fails++;
        $lockedUntil = null;
        $lm = null;
        $rsn = null;
        if ($fails >= $threshold) {
            $lm = $lockMinutes;
            $lockedUntil = date('Y-m-d H:i:s', $now + $lockMinutes * 60);
            $rsn = $reason;
        }
        $firstFailAt = ($reset || !$row || empty($row['first_fail_at'])) ? date('Y-m-d H:i:s', $now) : $row['first_fail_at'];
        $up = $pdo->prepare("
            INSERT INTO security_rate_limits (scope, scope_key, fail_count, first_fail_at, last_fail_at, locked_until, lock_minutes, reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                fail_count = VALUES(fail_count),
                first_fail_at = VALUES(first_fail_at),
                last_fail_at = VALUES(last_fail_at),
                locked_until = VALUES(locked_until),
                lock_minutes = VALUES(lock_minutes),
                reason = VALUES(reason)
        ");
        $up->execute([$scope, $key, $fails, $firstFailAt, date('Y-m-d H:i:s', $now), $lockedUntil, $lm, $rsn]);
        return $lockedUntil;
    };

    // Global lock checks (per IP and per user reset flow)
    $uLock = $getLock('fp_verify_user', $userKey);
    if ($isLocked($uLock)) {
        $mins = $minsRemaining($uLock);
        echo json_encode(['status' => 'error', 'message' => "Too many attempts. Try again in {$mins} minute(s)."]);
        exit();
    }
    $iLock = $getLock('fp_verify_ip', $ip);
    if ($isLocked($iLock)) {
        $mins = $minsRemaining($iLock);
        echo json_encode(['status' => 'error', 'message' => "Too many attempts from your network. Try again in {$mins} minute(s)."]);
        exit();
    }

    // Get fresh user data
    $stmt = $pdo->prepare("SELECT security_answer FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['fp_user_id']]);
    $user = $stmt->fetch();

    if ($method === 'question') {
        // Verify security answer
        $answer = strtolower(trim($_POST['answer'] ?? ''));
        if (!password_verify($answer, $user['security_answer'])) {
            // 5 wrong answers => lock 15 minutes (per user + per ip)
            $uLockedUntil = $fail('fp_verify_user', $userKey, 15 * 60, 5, 15, 'fp_question_lock');
            $iLockedUntil = $fail('fp_verify_ip', $ip, 15 * 60, 12, 15, 'fp_ip_lock');
            if ($uLockedUntil || $iLockedUntil) {
                echo json_encode(['status' => 'error', 'message' => 'Too many incorrect answers. Please wait 15 minutes and try again.']);
                exit();
            }
            echo json_encode(['status' => 'error', 'message' => 'Incorrect answer']);
            exit();
        }
        $_SESSION['fp_verified'] = true;
        echo json_encode(['status' => 'success', 'skip_code' => true]);

    } else {
        // Limit sending codes (prevents email spam): 3 sends per 15 minutes per user; 10 per 15 minutes per IP
        $sendUser = $getLock('fp_send_user', $userKey);
        if ($isLocked($sendUser)) {
            $mins = $minsRemaining($sendUser);
            echo json_encode(['status' => 'error', 'message' => "Too many code requests. Try again in {$mins} minute(s)."]);
            exit();
        }
        $sendIp = $getLock('fp_send_ip', $ip);
        if ($isLocked($sendIp)) {
            $mins = $minsRemaining($sendIp);
            echo json_encode(['status' => 'error', 'message' => "Too many code requests from your network. Try again in {$mins} minute(s)."]);
            exit();
        }

        // Count send attempt (locks if abused)
        $fail('fp_send_user', $userKey, 15 * 60, 4, 15, 'fp_send_lock'); // 4th request triggers lock
        $fail('fp_send_ip', $ip, 15 * 60, 11, 15, 'fp_send_ip_lock');

        // Send email code
        $code = sprintf('%06d', mt_rand(0, 999999));
        $_SESSION['fp_code'] = $code;
        $_SESSION['fp_expires'] = time() + 900; // 15 min

        $target = ($method === 'recovery') ? $_SESSION['fp_recovery'] : $_SESSION['fp_email'];

        // Send email
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'scanquotient@gmail.com';
        $mail->Password = 'vnht iefe anwl xynb';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->setFrom('scanquotient@gmail.com', 'ScanQuotient');
        $mail->addAddress($target);
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Code';
        $mail->Body = "<div style='text-align:center'><h2>Reset Code: {$code}</h2><p>Expires in 15 minutes</p></div>";
        $mail->send();

        echo json_encode(['status' => 'success']);
    }

} catch (Exception $e) {
    error_log("Send verify error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to send']);
}
?>