<?php
session_start();

if (!isset($_SESSION['authenticated']) || !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'], true)) {
    header('HTTP/1.1 403 Forbidden');
    exit('Admin access required');
}

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$type = isset($_GET['type']) ? strtolower((string) $_GET['type']) : '';
if ($id <= 0 || !in_array($type, ['pdf', 'doc', 'html', 'csv'], true)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid request');
}

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $stmt = $pdo->prepare("SELECT pdf_path, doc_path, html_path, csv_path FROM scan_results WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        header('HTTP/1.1 404 Not Found');
        exit('Scan not found');
    }

    $pathKey = $type . '_path';
    $relativePath = (string) ($row[$pathKey] ?? '');
    if ($relativePath === '') {
        header('HTTP/1.1 404 Not Found');
        exit('File not available');
    }

    $baseDir = dirname(__DIR__, 4);
    $fullPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($fullPath)) {
        header('HTTP/1.1 404 Not Found');
        exit('File not found');
    }

    if ($type === 'pdf' && filesize($fullPath) < 1200) {
        header('HTTP/1.1 404 Not Found');
        exit('PDF not available');
    }

    $mimes = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'html' => 'text/html; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8',
    ];
    header('Content-Type: ' . $mimes[$type]);
    header('Content-Disposition: attachment; filename="admin-scan-' . $id . '.' . $type . '"');
    header('Content-Length: ' . filesize($fullPath));
    readfile($fullPath);
    exit;
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Download failed');
}

