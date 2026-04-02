<?php
session_start();
require_once __DIR__ . '/../Include/sq_auth_guard.php';
sq_require_web_scanner_auth(true);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '' || strlen($token) > 120) {
    http_response_code(400);
    echo json_encode(['error' => 'token is required']);
    exit;
}

$url = 'http://127.0.0.1:5000/scan-progress?token=' . rawurlencode($token);
$ch = curl_init($url);
if ($ch === false) {
    http_response_code(503);
    echo json_encode(['error' => 'Scanner service unavailable']);
    exit;
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode >= 500) {
    http_response_code(503);
    echo json_encode(['error' => 'Scanner service unavailable']);
    exit;
}

$decoded = json_decode((string) $response, true);
if (!is_array($decoded)) {
    http_response_code(502);
    echo json_encode(['error' => 'Invalid progress payload']);
    exit;
}

echo json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

