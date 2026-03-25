<?php
/**
 * Returns the current user's subscription package: freemium, pro, or enterprise.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

$user_id = $_SESSION['user_uid'] ?? $_SESSION['user_id'] ?? null;
$package = 'freemium';

if ($user_id) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // Get user email (same as User_subscription.php)
        $stmtEmail = $pdo->prepare("SELECT email FROM users WHERE user_id = ? LIMIT 1");
        $stmtEmail->execute([$user_id]);
        $userRow = $stmtEmail->fetch();
        $userEmail = $userRow['email'] ?? null;
        if ($userEmail) {
            // Plan from payments table: latest active, non-expired payment (match User_subscription.php)
            $stmtPay = $pdo->prepare("
                SELECT package FROM payments
                WHERE email = ? AND (account_status = 'active' OR account_status IS NULL)
                AND (expires_at IS NULL OR expires_at > NOW())
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmtPay->execute([$userEmail]);
            $payRow = $stmtPay->fetch();
            if ($payRow && !empty($payRow['package'])) {
                $p = strtolower(trim($payRow['package']));
                if (in_array($p, ['freemium', 'pro', 'enterprise'], true)) {
                    $package = $p;
                }
            }
        }
        // Fallback: users.subscription_plan if payments has no row
        if ($package === 'freemium') {
            try {
                $stmt = $pdo->prepare("SELECT subscription_plan FROM users WHERE user_id = ? LIMIT 1");
                $stmt->execute([$user_id]);
                $row = $stmt->fetch();
                if ($row && in_array(strtolower(trim($row['subscription_plan'] ?? '')), ['freemium', 'pro', 'enterprise'], true)) {
                    $package = strtolower(trim($row['subscription_plan']));
                }
            } catch (Exception $e) {}
        }
    } catch (Exception $e) {
        // Keep default freemium
    }
}

echo json_encode(['package' => $package]);
