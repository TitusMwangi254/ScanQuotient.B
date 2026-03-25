<?php
/**
 * Share scan results: send email to one or more recipients with selected artefacts (PDF, HTML, CSV) attached.
 */
session_start();
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

// Legacy support: older scans may have been stored with numeric users.id in scan_results.user_id.
$userPk = null;
if (isset($_SESSION['user_uid']) && $_SESSION['user_uid']) {
    try {
        $dsnPk = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdoTmp = new PDO($dsnPk, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $stmtPk = $pdoTmp->prepare("SELECT id FROM users WHERE user_id = ? LIMIT 1");
        $stmtPk->execute([$_SESSION['user_uid']]);
        $rowPk = $stmtPk->fetch();
        $userPk = $rowPk['id'] ?? null;
    } catch (Exception $e) {
        $userPk = null; // best-effort only
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
$scan_id = isset($input['scan_id']) ? (int) $input['scan_id'] : 0;
$artefacts = isset($input['artefacts']) && is_array($input['artefacts']) ? $input['artefacts'] : [];
$recipients = isset($input['recipients']) && is_array($input['recipients']) ? $input['recipients'] : [];

$artefacts = array_intersect($artefacts, ['pdf', 'html', 'csv']);
$recipients = array_filter(array_map('trim', $recipients), function ($e) {
    return filter_var($e, FILTER_VALIDATE_EMAIL);
});

if ($scan_id <= 0 || empty($artefacts) || empty($recipients)) {
    echo json_encode(['ok' => false, 'error' => 'Provide scan_id, at least one artefact (pdf, html, csv), and valid recipient email(s).']);
    exit;
}

try {
    require_once 'C:/Users/1/vendor/autoload.php';

    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    if ($userPk !== null) {
        $stmt = $pdo->prepare("SELECT target_url, pdf_path, html_path, csv_path FROM scan_results WHERE id = ? AND (user_id = ? OR user_id = ?)");
        $stmt->execute([$scan_id, $user_id, $userPk]);
    } else {
        $stmt = $pdo->prepare("SELECT target_url, pdf_path, html_path, csv_path FROM scan_results WHERE id = ? AND user_id = ?");
        $stmt->execute([$scan_id, $user_id]);
    }
    $scan = $stmt->fetch();
    if (!$scan) {
        echo json_encode(['ok' => false, 'error' => 'Scan not found']);
        exit;
    }

    $stmtU = $pdo->prepare("SELECT first_name, surname FROM users WHERE user_id = ? LIMIT 1");
    $stmtU->execute([$user_id]);
    $user = $stmtU->fetch();
    $firstName = $user['first_name'] ?? 'A';
    $surname = $user['surname'] ?? 'User';
    $senderName = trim($firstName . ' ' . $surname) ?: 'A ScanQuotient user';

    $baseDir = dirname(__DIR__, 4);
    $target = $scan['target_url'] ?? 'Unknown target';
    $attachments = [];
    foreach ($artefacts as $type) {
        $key = $type . '_path';
        $rel = $scan[$key] ?? null;
        if (!$rel) continue;

        // Match the same path-building logic used by `download_scan.php`
        // so attachments resolve identically.
        $relNorm = str_replace('\\', '/', trim((string)$rel));
        $full = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relNorm);

        // If the DB path starts with a leading slash, retry without it.
        if ((!$full || !is_file($full)) && isset($relNorm[0]) && $relNorm[0] === '/') {
            $full = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relNorm, '/'));
        }

        // If DB path already looks like an absolute Windows path, use it directly.
        if ((!$full || !is_file($full)) && preg_match('/^[A-Za-z]:[\\/]/', $relNorm)) {
            $full = str_replace('/', DIRECTORY_SEPARATOR, $relNorm);
        }

        if ($full && is_file($full)) {
            $attachments[] = ['path' => $full, 'type' => $type, 'name' => 'scan-report-' . $scan_id . '.' . $type];
        }
    }
    if (empty($attachments)) {
        echo json_encode(['ok' => false, 'error' => 'No selected artefacts are available for this scan.']);
        exit;
    }

    $artefactList = implode(', ', array_map('strtoupper', array_column($attachments, 'type')));
    $targetEsc = htmlspecialchars($target);
    $senderEsc = htmlspecialchars($senderName);
    $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head><body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f4f4f4;">
        <table role="presentation" style="width:100%;border-collapse:collapse;">
        <tr><td align="center" style="padding:20px 0;">
        <table role="presentation" style="width:600px;max-width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
        <tr><td style="padding:28px 32px;text-align:center;background:linear-gradient(135deg,#3b82f6 0%,#1d4ed8 100%);border-radius:8px 8px 0 0;">
        <h1 style="color:#fff;margin:0;font-size:22px;font-weight:bold;">ScanQuotient – Scan Results Shared With You</h1>
        </td></tr>
        <tr><td style="padding:28px 32px;">
        <p style="color:#333;font-size:16px;line-height:1.6;margin:0 0 16px 0;">Hello,</p>
        <p style="color:#555;font-size:15px;line-height:1.6;margin:0 0 20px 0;">You are receiving shared security scan results from ScanQuotient.</p>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px 20px;margin:20px 0;">
        <p style="margin:0 0 8px 0;font-size:13px;color:#64748b;">Scan target</p>
        <p style="margin:0;font-size:15px;font-weight:600;color:#1e293b;word-break:break-all;">' . $targetEsc . '</p>
        <p style="margin:16px 0 0 0;font-size:13px;color:#64748b;">Shared by</p>
        <p style="margin:4px 0 0 0;font-size:15px;color:#1e293b;">' . $senderEsc . '</p>
        </div>
        <p style="color:#555;font-size:14px;line-height:1.6;margin:20px 0 8px 0;">The following report files are attached to this email:</p>
        <p style="margin:0;font-size:14px;font-weight:600;color:#1e293b;">' . htmlspecialchars($artefactList) . '</p>
        <p style="color:#64748b;font-size:13px;margin:24px 0 0 0;">Open the attachments to view the full security report. If you have questions, contact the person who shared this scan with you.</p>
        </td></tr>
        <tr><td style="padding:16px 32px;text-align:center;background:#f8f9fa;border-radius:0 0 8px 8px;">
        <p style="color:#6c757d;font-size:12px;margin:0;">This is an automated message from ScanQuotient. Please do not reply to this email.</p>
        <p style="color:#adb5bd;font-size:11px;margin:8px 0 0 0;">© ' . date('Y') . ' ScanQuotient. Quantifying Risk. Strengthening Security.</p>
        </td></tr>
        </table></td></tr></table></body></html>';
    $plainBody = "Hello,\n\nYou are receiving shared scan results from ScanQuotient.\n\nScan target: " . $target . "\nShared by: " . $senderName . "\n\nAttached: " . $artefactList . ".\n\nThis message was sent via ScanQuotient. Do not reply to this email.";

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'scanquotient@gmail.com';
    $mail->Password = 'vnht iefe anwl xynb';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->setFrom('scanquotient@gmail.com', 'ScanQuotient');
    $mail->Subject = 'ScanQuotient: Scan results shared by ' . $senderName;
    $mail->isHTML(true);
    $mail->Body = $htmlBody;
    $mail->AltBody = $plainBody;
    $mail->CharSet = 'UTF-8';

    foreach ($attachments as $a) {
        $mail->addAttachment($a['path'], $a['name']);
    }

    $sent = 0;
    foreach ($recipients as $to) {
        $mail->clearAddresses();
        $mail->addAddress($to);
        try {
            $mail->send();
            $sent++;
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            error_log('share_scan send error: ' . $e->getMessage());
        }
    }

    if ($sent === 0) {
        echo json_encode(['ok' => false, 'error' => 'Could not send email. Check server mail configuration.']);
        exit;
    }
    echo json_encode(['ok' => true, 'sent' => $sent, 'message' => 'Scan results shared with ' . $sent . ' recipient(s).']);
} catch (Exception $e) {
    error_log('share_scan: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to share']);
}
