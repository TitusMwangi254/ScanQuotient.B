<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['fp_code'])) {
    echo json_encode(['status' => 'error', 'message' => 'No code']);
    exit();
}

$code = $_POST['code'] ?? '';

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=scanquotient.a1;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

    $get = $pdo->prepare("SELECT * FROM security_rate_limits WHERE scope = ? AND scope_key = ? LIMIT 1");
    $isLocked = function (?array $row) {
        return $row && !empty($row['locked_until']) && strtotime($row['locked_until']) > time();
    };
    $minsRemaining = function (?array $row) {
        if (!$row || empty($row['locked_until'])) return 0;
        $diff = strtotime($row['locked_until']) - time();
        return $diff > 0 ? (int)ceil($diff / 60) : 0;
    };

    $get->execute(['fp_code_user', $userKey]);
    $uRow = $get->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($isLocked($uRow)) {
        $mins = $minsRemaining($uRow);
        echo json_encode(['status' => 'error', 'message' => "Too many incorrect codes. Try again in {$mins} minute(s)."]);
        exit();
    }

    $get->execute(['fp_code_ip', $ip]);
    $iRow = $get->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($isLocked($iRow)) {
        $mins = $minsRemaining($iRow);
        echo json_encode(['status' => 'error', 'message' => "Too many attempts from your network. Try again in {$mins} minute(s)."]);
        exit();
    }

} catch (Exception $e) {
    error_log("Forgot password code rate limit init error: " . $e->getMessage());
    $pdo = null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userKey = (string)($_SESSION['fp_user_id'] ?? 'unknown');
}

if (time() > $_SESSION['fp_expires']) {
    echo json_encode(['status' => 'error', 'message' => 'Code expired']);
    exit();
}

if ($code !== $_SESSION['fp_code']) {
    if (isset($pdo) && $pdo instanceof PDO) {
        $now = time();
        $bump = function (string $scope, string $key, int $windowSeconds, int $threshold, int $lockMinutes, string $reason) use ($pdo, $now) {
            $st = $pdo->prepare("SELECT fail_count, last_fail_at, first_fail_at FROM security_rate_limits WHERE scope = ? AND scope_key = ? LIMIT 1");
            $st->execute([$scope, $key]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
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

        // 5 wrong codes => 15 min lock per user; 12 wrong => 15 min lock per ip
        $uLocked = $bump('fp_code_user', $userKey, 15 * 60, 5, 15, 'fp_code_lock');
        $iLocked = $bump('fp_code_ip', $ip, 15 * 60, 12, 15, 'fp_ip_lock');
        if ($uLocked || $iLocked) {
            echo json_encode(['status' => 'error', 'message' => 'Too many incorrect codes. Please wait 15 minutes and try again.']);
            exit();
        }
    }
    echo json_encode(['status' => 'error', 'message' => 'Invalid code']);
    exit();
}

$_SESSION['fp_verified'] = true;

// Clear rate limits on success
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $clr = $pdo->prepare("
            UPDATE security_rate_limits
            SET fail_count = 0, first_fail_at = NULL, last_fail_at = NULL, locked_until = NULL, lock_minutes = NULL, reason = NULL
            WHERE (scope = 'fp_code_user' AND scope_key = ?) OR (scope = 'fp_code_ip' AND scope_key = ?)
        ");
        $clr->execute([$userKey, $ip]);
    }
} catch (Exception $e) {
    error_log("Forgot password code rate limit reset error: " . $e->getMessage());
}

echo json_encode(['status' => 'success']);
?>