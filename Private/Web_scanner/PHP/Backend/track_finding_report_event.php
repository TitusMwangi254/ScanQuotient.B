<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$raw = file_get_contents('php://input');
$in = json_decode((string) $raw, true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

$eventKey = substr((string) ($in['event_key'] ?? 'finding_report_source_non_ai'), 0, 80);
$source = substr((string) ($in['source'] ?? ''), 0, 120);
if ($source === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing source']);
    exit;
}

try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=scanquotient.a1;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_server_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        event_key VARCHAR(80) NOT NULL,
        level ENUM('info','warning','error') NOT NULL DEFAULT 'info',
        source VARCHAR(120) NOT NULL,
        message VARCHAR(255) NOT NULL,
        detail_json LONGTEXT NULL,
        user_id VARCHAR(64) NULL,
        request_ip VARCHAR(64) NULL,
        request_uri VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created_at (created_at),
        INDEX idx_level (level),
        INDEX idx_source (source),
        INDEX idx_event_key (event_key),
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $msg = 'Frontend finding report source observed: ' . $source;
    $stmt = $pdo->prepare("INSERT INTO system_server_logs
        (event_key, level, source, message, detail_json, user_id, request_ip, request_uri)
        VALUES (:event_key, 'info', 'web_scanner.finding_report_telemetry', :message, :detail_json,
                :user_id, :request_ip, :request_uri)");
    $stmt->execute([
        ':event_key' => $eventKey,
        ':message' => substr($msg, 0, 255),
        ':detail_json' => json_encode($in, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':user_id' => (string) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? ''),
        ':request_ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ':request_uri' => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 255),
    ]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('track_finding_report_event failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Track failed']);
}
?>
