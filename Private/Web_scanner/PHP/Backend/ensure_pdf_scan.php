<?php
/**
 * Ensure a scan has a PDF: if pdf_path is missing but html_path exists, generate PDF from HTML
 * then redirect to download_scan.php. If PDF already exists, redirect immediately.
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

$scan_id = isset($_GET['scan_id']) ? (int) $_GET['scan_id'] : 0;
if ($scan_id <= 0) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid scan id');
}

// Legacy: scans may have been stored with numeric users.id
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
        $userPk = null;
    }
}

$baseDir = dirname(__DIR__, 4);

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $sql = "SELECT id, pdf_path, html_path FROM scan_results WHERE id = ? AND (user_id = ? OR user_id = ?)";
    $params = [$scan_id, $user_id];
    if ($userPk !== null) {
        $params[] = (string) $userPk;
    } else {
        $params[] = $user_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!$row) {
        header('HTTP/1.1 404 Not Found');
        exit('Scan not found');
    }

    $pdf_path = $row['pdf_path'] ?? null;
    $html_path = $row['html_path'] ?? null;

    $downloadUrl = 'download_scan.php?id=' . $scan_id . '&type=pdf';

    // PDF already exists and file on disk
    if (!empty($pdf_path)) {
        $fullPdf = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pdf_path);
        if (is_file($fullPdf) && filesize($fullPdf) >= 1200) {
            header('Location: ' . $downloadUrl);
            exit;
        }
    }

    // Need to generate from HTML
    if (empty($html_path)) {
        header('HTTP/1.1 404 Not Found');
        exit('PDF not available (no HTML report)');
    }

    $fullHtml = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $html_path);
    if (!is_file($fullHtml)) {
        header('HTTP/1.1 404 Not Found');
        exit('HTML report file not found');
    }

    $pdfRelative = preg_replace('/\.html$/i', '.pdf', $html_path);
    $fullPdf = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pdfRelative);

    if (!save_pdf_from_html($fullHtml, $fullPdf)) {
        header('HTTP/1.1 503 Service Unavailable');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'PDF generation is not available (Dompdf required).', 'fallback' => 'client']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE scan_results SET pdf_path = ? WHERE id = ?");
    $stmt->execute([$pdfRelative, $scan_id]);

    header('Location: ' . $downloadUrl);
    exit;
} catch (Exception $e) {
    error_log('ensure_pdf_scan: ' . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    exit('Error');
}

function save_pdf_from_html(string $htmlPath, string $pdfPath): bool {
    $htmlContent = file_get_contents($htmlPath);
    if (!class_exists('Dompdf\Dompdf')) {
        $autoloadPaths = [
            'C:/Users/1/vendor/autoload.php',
            dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
        ];
        foreach ($autoloadPaths as $path) {
            if (file_exists($path)) {
                require_once $path;
                break;
            }
        }
    }
    if (!class_exists('Dompdf\Dompdf')) {
        return false;
    }
    try {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($htmlContent);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        file_put_contents($pdfPath, $dompdf->output());
        return true;
    } catch (Throwable $e) {
        error_log('PDF generation: ' . $e->getMessage());
        return false;
    }
}
