<?php
/**
 * Return estimated count of scans that are missing a usable PDF
 * but still have HTML available (thus prebuild-capable).
 */
session_start();
require_once __DIR__ . '/../Include/sq_auth_guard.php';
sq_require_web_scanner_auth(true);
header('Content-Type: application/json; charset=utf-8');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

$userId = $_SESSION['user_uid'] ?? $_SESSION['user_id'] ?? null;
if (!$userId) {
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$groupId = isset($_GET['group_id']) ? (int) $_GET['group_id'] : 0;
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 400;
$limit = max(1, min(1200, $limit));

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $userPk = null;
    if (isset($_SESSION['user_uid']) && $_SESSION['user_uid']) {
        try {
            $stmtPk = $pdo->prepare("SELECT id FROM users WHERE user_id = ? LIMIT 1");
            $stmtPk->execute([$_SESSION['user_uid']]);
            $rowPk = $stmtPk->fetch();
            $userPk = $rowPk['id'] ?? null;
        } catch (Throwable $e) {
            $userPk = null;
        }
    }

    $whereSql = 'user_id = :uid';
    $params = [':uid' => $userId];
    if ($userPk !== null) {
        $whereSql = '(user_id = :uid OR user_id = :pk)';
        $params[':pk'] = (string) $userPk;
    }
    if ($groupId > 0) {
        $whereSql .= ' AND group_id = :gid';
        $params[':gid'] = $groupId;
    }

    $stmt = $pdo->prepare("
        SELECT id, html_path, pdf_path
        FROM scan_results
        WHERE {$whereSql}
        ORDER BY created_at DESC
        LIMIT {$limit}
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $baseDir = dirname(__DIR__, 4);
    $missingCount = 0;
    $readyCount = 0;
    $unbuildableCount = 0;
    foreach ($rows as $row) {
        $pdfRel = (string) ($row['pdf_path'] ?? '');
        $pdfReady = false;
        if ($pdfRel !== '') {
            $fullPdf = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($pdfRel, '/'));
            $pdfReady = is_file($fullPdf) && filesize($fullPdf) >= 1200;
        }
        if ($pdfReady) {
            $readyCount++;
            continue;
        }

        $htmlRel = (string) ($row['html_path'] ?? '');
        if ($htmlRel === '') {
            $unbuildableCount++;
            continue;
        }
        $fullHtml = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($htmlRel, '/'));
        if (!is_file($fullHtml)) {
            $unbuildableCount++;
            continue;
        }
        $missingCount++;
    }

    echo json_encode([
        'ok' => true,
        'missing_pdf_count' => $missingCount,
        'ready_pdf_count' => $readyCount,
        'unbuildable_count' => $unbuildableCount,
        'scanned' => count($rows),
    ]);
} catch (Throwable $e) {
    error_log('missing_pdfs_count: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Could not estimate missing PDFs']);
}

