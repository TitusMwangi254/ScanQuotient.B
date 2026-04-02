<?php
/**
 * Upload a generated PDF for a saved scan and update scan_results.pdf_path.
 * This is used when server-side PDF generation isn't available (no Dompdf).
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

$user_id = $_SESSION['user_uid'] ?? $_SESSION['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

// Legacy: scans may have been stored with numeric users.id
$userPk = null;
if (isset($_SESSION['user_uid']) && $_SESSION['user_uid']) {
    try {
        $dsnTmp = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdoTmp = new PDO($dsnTmp, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $stmtPk = $pdoTmp->prepare("SELECT id FROM users WHERE user_id = ? LIMIT 1");
        $stmtPk->execute([$_SESSION['user_uid']]);
        $rowPk = $stmtPk->fetch();
        $userPk = $rowPk['id'] ?? null;
    } catch (Exception $e) {
        $userPk = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: [];
$scan_id = isset($input['scan_id']) ? (int) $input['scan_id'] : 0;
$pdf_b64 = isset($input['pdf_base64']) ? (string) $input['pdf_base64'] : '';

if ($scan_id <= 0 || $pdf_b64 === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing scan_id or pdf_base64']);
    exit;
}

// Allow "data:application/pdf;base64,..." or raw base64
if (str_starts_with($pdf_b64, 'data:')) {
    $parts = explode(',', $pdf_b64, 2);
    $pdf_b64 = $parts[1] ?? '';
}

$bin = base64_decode($pdf_b64, true);
// Accept small but valid PDFs; keep a minimal guard to reject corrupt payloads.
if ($bin === false || strlen($bin) < 1200) {
    echo json_encode(['ok' => false, 'error' => 'Invalid PDF data']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $stmt = $pdo->prepare("SELECT id, target_url, pdf_path FROM scan_results WHERE id = ? AND (user_id = ? OR user_id = ?) LIMIT 1");
    $stmt->execute([$scan_id, $user_id, $userPk !== null ? (string) $userPk : $user_id]);
    $row = $stmt->fetch();
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Scan not found']);
        exit;
    }
    if (!empty($row['pdf_path'])) {
        echo json_encode([
            'ok' => true,
            'message' => 'PDF already saved',
            'download' => 'download_scan.php?id=' . $scan_id . '&type=pdf',
        ]);
        exit;
    }

    $storageDir = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'Storage' . DIRECTORY_SEPARATOR . 'Scan_results';
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }

    $ts = date('Y-m-d_His');
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', parse_url((string)($row['target_url'] ?? ''), PHP_URL_HOST) ?: 'scan');
    $baseName = $user_id . '_pdf_' . $scan_id . '_' . $ts . '_' . substr($safe, 0, 32);
    $pdfPath = $storageDir . DIRECTORY_SEPARATOR . $baseName . '.pdf';
    $written = @file_put_contents($pdfPath, $bin);
    if ($written === false || !is_file($pdfPath) || filesize($pdfPath) < 1200) {
        echo json_encode(['ok' => false, 'error' => 'Failed to write PDF file to storage']);
        exit;
    }

    $rel = 'Storage/Scan_results/' . $baseName . '.pdf';
    $upd = $pdo->prepare("UPDATE scan_results SET pdf_path = ? WHERE id = ? AND (user_id = ? OR user_id = ?)");
    $upd->execute([$rel, $scan_id, $user_id, $userPk !== null ? (string) $userPk : $user_id]);

    echo json_encode(['ok' => true, 'pdf_path' => $rel, 'download' => 'download_scan.php?id=' . $scan_id . '&type=pdf']);
} catch (Throwable $e) {
    error_log('upload_pdf: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to save PDF: ' . $e->getMessage()]);
}

