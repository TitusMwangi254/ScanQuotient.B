<?php
/**
 * Batch-generate missing PDFs from existing HTML reports for the signed-in user.
 * Helps make share/download actions instant on older scans.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) {
    $in = [];
}
$limit = isset($in['limit']) ? (int) $in['limit'] : 120;
$limit = max(1, min(300, $limit));
$groupId = isset($in['group_id']) ? (int) $in['group_id'] : 0;

function sq_load_dompdf_once_batch(): bool
{
    if (class_exists('\Dompdf\Dompdf')) {
        return true;
    }
    $autoloadPaths = [
        'C:/Users/1/vendor/autoload.php',
        dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
    ];
    foreach ($autoloadPaths as $path) {
        if (is_file($path)) {
            require_once $path;
            if (class_exists('\Dompdf\Dompdf')) {
                return true;
            }
        }
    }
    return class_exists('\Dompdf\Dompdf');
}

function sq_save_pdf_from_html_batch(string $htmlPath, string $pdfPath): bool
{
    if (!is_file($htmlPath) || !sq_load_dompdf_once_batch()) {
        return false;
    }
    try {
        $html = (string) file_get_contents($htmlPath);
        if (strlen($html) < 80) {
            return false;
        }
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $out = $dompdf->output();
        if (!$out || strlen($out) < 1200) {
            return false;
        }
        $written = file_put_contents($pdfPath, $out);
        return ($written !== false && $written >= 1200);
    } catch (Throwable $e) {
        error_log('prebuild_missing_pdfs pdf error: ' . $e->getMessage());
        return false;
    }
}

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
    $generated = 0;
    $already = 0;
    $missingHtml = 0;
    $failed = 0;

    foreach ($rows as $row) {
        $pdfRel = (string) ($row['pdf_path'] ?? '');
        if ($pdfRel !== '') {
            $fullPdf = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($pdfRel, '/'));
            if (is_file($fullPdf) && filesize($fullPdf) >= 1200) {
                $already++;
                continue;
            }
        }

        $htmlRel = (string) ($row['html_path'] ?? '');
        if ($htmlRel === '') {
            $missingHtml++;
            continue;
        }
        $fullHtml = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($htmlRel, '/'));
        if (!is_file($fullHtml)) {
            $missingHtml++;
            continue;
        }

        $targetPdfRel = preg_replace('/\.html$/i', '.pdf', $htmlRel);
        $fullPdfTarget = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim((string) $targetPdfRel, '/'));
        if (sq_save_pdf_from_html_batch($fullHtml, $fullPdfTarget)) {
            $up = $pdo->prepare("UPDATE scan_results SET pdf_path = ? WHERE id = ?");
            $up->execute([$targetPdfRel, (int) $row['id']]);
            $generated++;
        } else {
            $failed++;
        }
    }

    echo json_encode([
        'ok' => true,
        'processed' => count($rows),
        'generated' => $generated,
        'already_ready' => $already,
        'missing_html' => $missingHtml,
        'failed' => $failed,
        'message' => 'PDF prebuild completed',
    ]);
} catch (Throwable $e) {
    error_log('prebuild_missing_pdfs: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to prebuild PDFs']);
}

