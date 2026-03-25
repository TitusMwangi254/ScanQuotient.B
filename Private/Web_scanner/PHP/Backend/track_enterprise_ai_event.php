<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) && !isset($_SESSION['user_uid'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = [];
}

$scanId = isset($input['scan_id']) ? (int) $input['scan_id'] : 0;
$eventType = strtolower(trim((string) ($input['event_type'] ?? '')));
$meta = $input['meta'] ?? null;

$allowedEvents = ['page_view', 'ask_submitted', 'ask_success', 'ask_error', 'clear_chat'];
if ($scanId <= 0 || !in_array($eventType, $allowedEvents, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

$currentUserId = $_SESSION['user_uid'] ?? $_SESSION['user_id'];
$metaJson = null;
if (is_array($meta) || is_object($meta)) {
    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
} elseif (is_string($meta) && $meta !== '') {
    $metaJson = json_encode(['note' => $meta], JSON_UNESCAPED_UNICODE);
}

$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS enterprise_ai_usage_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(64) NOT NULL,
        scan_id INT NOT NULL,
        event_type VARCHAR(50) NOT NULL,
        meta_json TEXT NULL,
        ip_address VARCHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_scan (scan_id),
        INDEX idx_user (user_id),
        INDEX idx_event (event_type),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $pdo->prepare("INSERT INTO enterprise_ai_usage_events
        (user_id, scan_id, event_type, meta_json, ip_address, user_agent)
        VALUES (:user_id, :scan_id, :event_type, :meta_json, :ip_address, :user_agent)");
    $stmt->execute([
        ':user_id' => (string) $currentUserId,
        ':scan_id' => $scanId,
        ':event_type' => $eventType,
        ':meta_json' => $metaJson,
        ':ip_address' => $ipAddress,
        ':user_agent' => $userAgent,
    ]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Track failed']);
}
?>
