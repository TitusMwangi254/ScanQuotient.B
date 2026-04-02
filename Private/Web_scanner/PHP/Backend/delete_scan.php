<?php
session_start();
require_once __DIR__ . '/../Include/sq_auth_guard.php';
sq_require_web_scanner_auth(true);
header('Content-Type: application/json; charset=utf-8');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

$user_id = $_SESSION['user_uid'] ?? $_SESSION['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

// Legacy support: older scans may have been stored with numeric users.id in scan_results.user_id.
$userPk = null;
if (isset($_SESSION['user_uid']) && $_SESSION['user_uid']) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdoTmp = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $stmtPk = $pdoTmp->prepare("SELECT id FROM users WHERE user_id = ? LIMIT 1");
        $stmtPk->execute([$_SESSION['user_uid']]);
        $rowPk = $stmtPk->fetch();
        $userPk = $rowPk['id'] ?? null;
    } catch (Exception $e) {
        $userPk = null; // best-effort only
    }
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
$id = isset($input['id']) ? (int) $input['id'] : 0;
if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid scan id']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $stmtEmail = $pdo->prepare("SELECT email FROM users WHERE user_id = ? LIMIT 1");
    $stmtEmail->execute([$user_id]);
    $userRow = $stmtEmail->fetch();
    $userEmail = $userRow['email'] ?? null;
    $package = 'freemium';
    if ($userEmail) {
        $stmtPay = $pdo->prepare("
            SELECT package FROM payments
            WHERE email = ? AND (account_status = 'active' OR account_status IS NULL)
            AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmtPay->execute([$userEmail]);
        $payRow = $stmtPay->fetch();
        if ($payRow && in_array(strtolower(trim($payRow['package'] ?? '')), ['pro', 'enterprise'], true)) {
            $package = strtolower(trim($payRow['package']));
        }
    }
    if ($package === 'freemium') {
        echo json_encode(['ok' => false, 'error' => 'Upgrade to Pro or Enterprise to delete saved scans.']);
        exit;
    }
    if ($userPk !== null) {
        $stmt = $pdo->prepare("DELETE FROM scan_results WHERE id = ? AND (user_id = ? OR user_id = ?)");
        $stmt->execute([$id, $user_id, $userPk]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM scan_results WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
    }
    if ($stmt->rowCount() === 0) {
        echo json_encode(['ok' => false, 'error' => 'Scan not found or access denied']);
        exit;
    }
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Failed to delete']);
}
