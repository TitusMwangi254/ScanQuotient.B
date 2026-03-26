<?php
/**
 * Proxy to the Python scanner API so the frontend can call same-origin (no CORS).
 * Forwards POST body to http://127.0.0.1:5000/scan and returns the response.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
// Never leak internal PHP warnings/fatal details to end users.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ob_start();

$dbDsn = 'mysql:host=127.0.0.1;dbname=scanquotient.a1;charset=utf8mb4';
$dbUser = 'root';
$dbPass = '';
$logPdo = null;

function getLogPdo(): ?PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    global $dbDsn, $dbUser, $dbPass;
    try {
        $pdo = new PDO($dbDsn, $dbUser, $dbPass, [
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
        return $pdo;
    } catch (Throwable $e) {
        error_log('scan_proxy log DB init failed: ' . $e->getMessage());
        return null;
    }
}

function writeServerLog(string $eventKey, string $level, string $message, array $details = []): void
{
    $pdo = getLogPdo();
    if (!$pdo) {
        return;
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO system_server_logs
            (event_key, level, source, message, detail_json, user_id, request_ip, request_uri)
            VALUES (:event_key, :level, :source, :message, :detail_json, :user_id, :request_ip, :request_uri)");
        $stmt->execute([
            ':event_key' => $eventKey,
            ':level' => in_array($level, ['info', 'warning', 'error'], true) ? $level : 'info',
            ':source' => 'web_scanner.scan_proxy',
            ':message' => substr($message, 0, 255),
            ':detail_json' => empty($details) ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':user_id' => (string) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? ''),
            ':request_ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ':request_uri' => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('scan_proxy write log failed: ' . $e->getMessage());
    }
}

function failWithUserSafeError(string $eventKey, string $safeMessage, int $statusCode = 500, array $detail = []): void
{
    writeServerLog($eventKey, 'error', $safeMessage, $detail);
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode(['error' => $safeMessage]);
    exit;
}

register_shutdown_function(function (): void {
    $lastError = error_get_last();
    if ($lastError === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($lastError['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    error_log('scan_proxy fatal: ' . ($lastError['message'] ?? 'unknown error'));
    failWithUserSafeError(
        'scanner_fatal',
        'Scan request timed out or failed unexpectedly. Please try again shortly.',
        500,
        ['fatal' => $lastError]
    );
});

$scanner_url = 'http://127.0.0.1:5000/scan';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    failWithUserSafeError('bad_method', 'Unable to process this scan request right now.', 405);
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    failWithUserSafeError('empty_body', 'Unable to process this scan request right now.', 400);
}

$requestData = json_decode($raw, true);
$targetUrl = is_array($requestData) ? (string) ($requestData['target'] ?? $requestData['url'] ?? '') : '';
if (!is_array($requestData)) {
    failWithUserSafeError('bad_json', 'Unable to process this scan request right now.', 400);
}
$targetUrl = trim($targetUrl);
if ($targetUrl === '' || strlen($targetUrl) > 2048) {
    failWithUserSafeError('bad_target', 'Please enter a valid target URL.', 400, ['target' => $targetUrl]);
}
$parts = parse_url($targetUrl);
if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
    failWithUserSafeError('bad_target_scheme', 'Please enter a valid http or https URL.', 400, ['target' => $targetUrl]);
}
writeServerLog('scan_request_received', 'info', 'Scan request received', [
    'target' => $targetUrl,
]);

$ch = curl_init($scanner_url);
if ($ch === false) {
    failWithUserSafeError('curl_init_failed', 'Scanner service is temporarily unavailable. Please try again shortly.', 503);
}

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $raw,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    // Keep below PHP max execution window so we can return a safe JSON error.
    // Increased to better align with scanner full-run timeout.
    CURLOPT_TIMEOUT => 170,
    CURLOPT_CONNECTTIMEOUT => 10,
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

if ($response === false) {
    failWithUserSafeError(
        'scanner_unreachable',
        'Scanner service is temporarily unavailable. Please try again shortly.',
        503,
        ['curl_error' => $curl_err, 'target' => $targetUrl]
    );
}

$decoded = json_decode((string) $response, true);
$looksJson = is_array($decoded);

if ($http_code >= 400) {
    failWithUserSafeError(
        'scanner_error_response',
        'Scan could not be completed at this time. Please try again shortly.',
        502,
        ['http_code' => $http_code, 'response_excerpt' => substr((string) $response, 0, 1000), 'target' => $targetUrl]
    );
}

if (!$looksJson) {
    failWithUserSafeError(
        'scanner_bad_payload',
        'Scan could not be completed at this time. Please try again shortly.',
        502,
        ['http_code' => $http_code, 'response_excerpt' => substr((string) $response, 0, 1000), 'target' => $targetUrl]
    );
}

if (isset($decoded['error']) || isset($decoded['detail']) || isset($decoded['exception'])) {
    failWithUserSafeError(
        'scanner_error_payload',
        'Scan could not be completed at this time. Please try again shortly.',
        502,
        ['http_code' => $http_code, 'payload' => $decoded, 'target' => $targetUrl]
    );
}

writeServerLog('scan_request_success', 'info', 'Scan request completed successfully', [
    'http_code' => $http_code,
    'target' => $targetUrl,
]);
http_response_code(200);
echo json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
