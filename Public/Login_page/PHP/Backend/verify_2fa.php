<?php
// verify_2fa.php - Verify 2FA code and complete login
session_start();
header('Content-Type: application/json');

// DB for rate limiting (creates table if missing)
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

function rlEnsureTable(PDO $pdo): void
{
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
}

function rlGet(PDO $pdo, string $scope, string $key): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM security_rate_limits WHERE scope = ? AND scope_key = ? LIMIT 1");
    $stmt->execute([$scope, $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function rlIsLocked(?array $row): bool
{
    if (!$row || empty($row['locked_until'])) return false;
    return strtotime($row['locked_until']) > time();
}

function rlMinsRemaining(?array $row): int
{
    if (!$row || empty($row['locked_until'])) return 0;
    $diff = strtotime($row['locked_until']) - time();
    return $diff > 0 ? (int)ceil($diff / 60) : 0;
}

function rlFail(PDO $pdo, string $scope, string $key, int $windowSeconds, int $threshold, int $lockMinutes, string $reason): array
{
    $now = time();
    $row = rlGet($pdo, $scope, $key);
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
        $lockedUntil = date('Y-m-d H:i:s', $now + ($lockMinutes * 60));
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

    return ['locked_until' => $lockedUntil, 'fail_count' => $fails];
}

function rlReset(PDO $pdo, string $scope, string $key): void
{
    $stmt = $pdo->prepare("
        UPDATE security_rate_limits
        SET fail_count = 0, first_fail_at = NULL, last_fail_at = NULL, locked_until = NULL, lock_minutes = NULL, reason = NULL
        WHERE scope = ? AND scope_key = ?
    ");
    $stmt->execute([$scope, $key]);
}

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

// Rate limit: prevent OTP guessing (per user + per IP)
try {
    $pdoRL = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    rlEnsureTable($pdoRL);

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userKey = (string)($_SESSION['2fa_user_id'] ?? $_SESSION['2fa_email'] ?? 'unknown');

    $userLock = rlGet($pdoRL, 'otp_user', $userKey);
    if (rlIsLocked($userLock)) {
        $mins = rlMinsRemaining($userLock);
        echo json_encode(['status' => 'error', 'message' => "Too many incorrect codes. Try again in {$mins} minute(s)."]);
        exit();
    }

    $ipLock = rlGet($pdoRL, 'otp_ip', $ip);
    if (rlIsLocked($ipLock)) {
        $mins = rlMinsRemaining($ipLock);
        echo json_encode(['status' => 'error', 'message' => "Too many OTP attempts from your network. Try again in {$mins} minute(s)."]);
        exit();
    }
} catch (Exception $e) {
    // Non-fatal: continue without rate limiting if DB fails
    error_log("OTP rate limit init error: " . $e->getMessage());
    $pdoRL = null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userKey = (string)($_SESSION['2fa_user_id'] ?? $_SESSION['2fa_email'] ?? 'unknown');
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
    if (isset($pdoRL) && $pdoRL instanceof PDO) {
        // 5 wrong tries => 10 min lock per user; 15 wrong tries => 15 min lock per IP
        rlFail($pdoRL, 'otp_user', $userKey, 10 * 60, 5, 10, 'otp_user_lock');
        rlFail($pdoRL, 'otp_ip', $ip, 15 * 60, 15, 15, 'otp_ip_lock');
        $uRow = rlGet($pdoRL, 'otp_user', $userKey);
        if (rlIsLocked($uRow)) {
            $mins = rlMinsRemaining($uRow);
            echo json_encode(['status' => 'error', 'message' => "Too many incorrect codes. Try again in {$mins} minute(s)."]);
            exit();
        }
        $iRow = rlGet($pdoRL, 'otp_ip', $ip);
        if (rlIsLocked($iRow)) {
            $mins = rlMinsRemaining($iRow);
            echo json_encode(['status' => 'error', 'message' => "Too many OTP attempts from your network. Try again in {$mins} minute(s)."]);
            exit();
        }
    }
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

    // Clear OTP rate limits on success
    try {
        if (isset($pdoRL) && $pdoRL instanceof PDO) {
            rlReset($pdoRL, 'otp_user', $userKey);
            rlReset($pdoRL, 'otp_ip', $ip);
        }
    } catch (Exception $e) {
        error_log("OTP rate limit reset error: " . $e->getMessage());
    }

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