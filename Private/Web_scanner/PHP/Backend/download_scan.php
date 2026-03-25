<?php
/**
 * Serve stored scan artefacts (PDF, HTML, CSV) for the logged-in user.
 */
session_start();

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

$user_id = $_SESSION['user_uid'] ?? $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header('HTTP/1.1 403 Forbidden');
    exit('Not logged in');
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

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$type = isset($_GET['type']) ? strtolower($_GET['type']) : '';
if (!in_array($type, ['pdf', 'html', 'csv'], true) || $id <= 0) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid id or type');
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $stmt = $pdo->prepare("SELECT pdf_path, html_path, csv_path FROM scan_results WHERE id = ? AND (user_id = ? OR user_id = ?)");
    $stmt->execute([$id, $user_id, $userPk !== null ? (string) $userPk : $user_id]);
    $row = $stmt->fetch();
    if (!$row) {
        header('HTTP/1.1 404 Not Found');
        exit('Report not found');
    }

    $pathKey = $type . '_path';
    $relativePath = $row[$pathKey] ?? null;
    if (!$relativePath) {
        header('HTTP/1.1 404 Not Found');
        exit('File not available');
    }

    $baseDir = dirname(__DIR__, 4);
    $fullPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($fullPath)) {
        header('HTTP/1.1 404 Not Found');
        exit('File not found on disk');
    }

    // Guard: if a PDF is essentially empty, return not available so UI can regenerate.
    if ($type === 'pdf' && filesize($fullPath) < 1200) {
        header('HTTP/1.1 404 Not Found');
        exit('PDF not available');
    }

    $mimes = [
        'pdf' => 'application/pdf',
        'html' => 'text/html; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8',
    ];
    $ext = [
        'pdf' => 'pdf',
        'html' => 'html',
        'csv' => 'csv',
    ];
    $filename = 'scan-report-' . $id . '.' . $ext[$type];

    header('Content-Type: ' . $mimes[$type]);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($fullPath));
    readfile($fullPath);
    exit;
} catch (Exception $e) {
    error_log('download_scan: ' . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    exit('Download failed');
}
