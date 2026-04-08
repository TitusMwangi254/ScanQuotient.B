<?php
/**
 * Save scan result, generate human-readable report via OpenAI, store artefacts (CSV, HTML, PDF) in Storage/Scan_results.
 * Uses user_id from session (set by login_handler). Local use only - API key in code to be removed for production.
 */
session_start();
require_once __DIR__ . '/../Include/sq_auth_guard.php';
sq_require_web_scanner_auth(true);
header('Content-Type: application/json; charset=utf-8');

// Ensure consistent timestamps in East Africa time (EAT, UTC+03:00)
date_default_timezone_set('Africa/Nairobi');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Secrets: read from environment first, then optional local config (gitignored)
$sqOpenAiKey = (string) (getenv('OPENAI_API_KEY') ?: '');
if ($sqOpenAiKey === '') {
    $secretsPath = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'secrets.php';
    if (is_file($secretsPath)) {
        $secrets = include $secretsPath;
        if (is_array($secrets) && !empty($secrets['OPENAI_API_KEY'])) {
            $sqOpenAiKey = (string) $secrets['OPENAI_API_KEY'];
        }
    }
}

$storageDir = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'Storage' . DIRECTORY_SEPARATOR . 'Scan_results';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$user_id = $_SESSION['user_uid'] ?? $_SESSION['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(['error' => 'Not logged in', 'report_text' => '']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
$scan_data = $input['scan_data'] ?? null;
$requestDetailedAi = !empty($input['detailed_ai']);

if (!$scan_data || !isset($scan_data['target'])) {
    echo json_encode(['error' => 'Missing scan_data', 'report_text' => '']);
    exit;
}

// ── Load Dompdf once, at the top, so every code path benefits ────────────────
// Searches all likely locations and logs clearly if it still can't be found.
function load_dompdf(): bool
{
    if (class_exists('Dompdf\Dompdf')) {
        return true;
    }

    $candidates = [
        // Absolute path on your dev machine
        'C:/Users/1/vendor/autoload.php',
        // Relative to this file: go up 4 levels to project root, then vendor/
        dirname(__DIR__, 4) . '/vendor/autoload.php',
        // Common alternative: vendor/ sits next to the Backend/ folder
        dirname(__DIR__, 1) . '/vendor/autoload.php',
        dirname(__DIR__, 2) . '/vendor/autoload.php',
        dirname(__DIR__, 3) . '/vendor/autoload.php',
        // If Dompdf was installed standalone (not via Composer)
        dirname(__DIR__, 4) . '/dompdf/autoload.inc.php',
    ];

    foreach ($candidates as $path) {
        if (file_exists($path)) {
            require_once $path;
            if (class_exists('Dompdf\Dompdf')) {
                return true;
            }
        }
    }

    // Nothing worked — log every path tried so you can diagnose instantly
    error_log(
        'save_scan_report: Dompdf not found. Tried the following paths: ' .
        implode(' | ', $candidates) .
        ' — Install Dompdf with: composer require dompdf/dompdf'
    );
    return false;
}

$dompdfAvailable = load_dompdf();

try {
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }

    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Align MySQL session timezone with EAT so created_at/display stays consistent.
    $pdo->exec("SET time_zone = '+03:00'");

    // Get user package
    $userPackage = 'freemium';
    $stmtEmail = $pdo->prepare("SELECT email FROM users WHERE user_id = ? LIMIT 1");
    $stmtEmail->execute([$user_id]);
    $userRow = $stmtEmail->fetch();
    $userEmail = $userRow['email'] ?? null;
    if ($userEmail) {
        $stmtPay = $pdo->prepare("
            SELECT package FROM payments
            WHERE email = ?
              AND (account_status = 'active' OR account_status IS NULL)
              AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmtPay->execute([$userEmail]);
        $payRow = $stmtPay->fetch();
        if ($payRow && in_array(strtolower(trim($payRow['package'] ?? '')), ['freemium', 'pro', 'enterprise'], true)) {
            $userPackage = strtolower(trim($payRow['package']));
        }
    }

    if ($userPackage === 'freemium') {
        $stmtCount = $pdo->prepare("SELECT COUNT(*) AS c FROM scan_results WHERE user_id = ?");
        $stmtCount->execute([$user_id]);
        $count = (int) $stmtCount->fetch()['c'];
        if ($count >= 5) {
            $report_text = generate_report_via_openai($scan_data);
            echo json_encode([
                'ok' => true,
                'report_text' => $report_text,
                'saved' => false,
                'message' => 'Report generated. Save limit reached (5 scans). Upgrade to Pro for unlimited saves.',
                'upgrade' => true,
            ]);
            exit;
        }
    }

    if ($requestDetailedAi && $userPackage === 'enterprise') {
        $detailedReport = generate_detailed_report_via_openai($scan_data);
        echo json_encode(['ok' => true, 'report_text' => $detailedReport, 'detailed_ai' => true]);
        exit;
    }

    // Ensure table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scan_results (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    VARCHAR(64)    NOT NULL,
            target_url VARCHAR(2048)  NOT NULL,
            scan_json  LONGTEXT       NOT NULL,
            report_text LONGTEXT      NULL,
            pdf_path   VARCHAR(512)   NULL,
            doc_path   VARCHAR(512)   NULL,
            html_path  VARCHAR(512)   NULL,
            csv_path   VARCHAR(512)   NULL,
            created_at TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user    (user_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $pdo->exec("ALTER TABLE scan_results MODIFY COLUMN user_id VARCHAR(64) NOT NULL");
    } catch (Exception $e) { /* already correct shape */
    }
    try {
        $pdo->exec("ALTER TABLE scan_results ADD COLUMN doc_path VARCHAR(512) NULL AFTER pdf_path");
    } catch (Exception $e) { /* column already exists */
    }

    $report_text = generate_report_via_openai($scan_data);

    $ts = date('Y-m-d_His');
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', parse_url($scan_data['target'], PHP_URL_HOST) ?: 'scan');
    $baseName = $user_id . '_' . $ts . '_' . substr($safe, 0, 32);

    $csvPath = $storageDir . DIRECTORY_SEPARATOR . $baseName . '.csv';
    $htmlPath = $storageDir . DIRECTORY_SEPARATOR . $baseName . '.html';
    $pdfPath = $storageDir . DIRECTORY_SEPARATOR . $baseName . '.pdf';
    $docPath = $storageDir . DIRECTORY_SEPARATOR . $baseName . '.doc';

    // Always write CSV and HTML
    file_put_contents($csvPath, build_csv_report($scan_data));
    $htmlReport = build_html_report($scan_data, $report_text);
    file_put_contents($htmlPath, $htmlReport);
    // Word-compatible artifact (HTML payload with .doc extension)
    file_put_contents($docPath, $htmlReport);

    // ── PDF generation ───────────────────────────────────────────────────────
    // Attempt server-side PDF via Dompdf.
    // $dompdfAvailable was resolved at the top of the file — no guessing here.
    $pdfPathRelative = null;
    $pdfError = null;

    if (!$dompdfAvailable) {
        // Dompdf not installed — tell the frontend explicitly so it can fall
        // back to the client-side jsPDF path and upload via upload_pdf.php
        $pdfError = 'Dompdf not available on this server. Install with: composer require dompdf/dompdf';
        error_log('save_scan_report: ' . $pdfError);
    } else {
        $pdfSaved = save_pdf_from_html($htmlPath, $pdfPath);
        if ($pdfSaved) {
            $pdfPathRelative = 'Storage/Scan_results/' . $baseName . '.pdf';
        } else {
            $pdfError = 'PDF rendering failed — see PHP error log for details.';
        }
    }

    // Save to database — CSV and HTML always present; PDF only if generated
    $createdAtOverride = null;
    if (!empty($scan_data['timestamp'])) {
        // Convert the scanner timestamp into MySQL datetime string in EAT.
        $ts = strtotime((string) $scan_data['timestamp']);
        if ($ts !== false) {
            $createdAtOverride = date('Y-m-d H:i:s', $ts);
        }
    }

    if ($createdAtOverride) {
        $stmt = $pdo->prepare("
            INSERT INTO scan_results
                (user_id, target_url, scan_json, report_text, pdf_path, doc_path, html_path, csv_path, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $scan_data['target'],
            json_encode($scan_data),
            $report_text,
            $pdfPathRelative, // null if not generated
            'Storage/Scan_results/' . $baseName . '.doc',
            'Storage/Scan_results/' . $baseName . '.html',
            'Storage/Scan_results/' . $baseName . '.csv',
            $createdAtOverride,
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO scan_results (user_id, target_url, scan_json, report_text, pdf_path, doc_path, html_path, csv_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $scan_data['target'],
            json_encode($scan_data),
            $report_text,
            $pdfPathRelative, // null if not generated
            'Storage/Scan_results/' . $baseName . '.doc',
            'Storage/Scan_results/' . $baseName . '.html',
            'Storage/Scan_results/' . $baseName . '.csv',
        ]);
    }
    $scan_id = (int) $pdo->lastInsertId();

    // ── Response ─────────────────────────────────────────────────────────────
    // 'pdf' key in download is either a URL string or null.
    // When null, the frontend's existing jsPDF fallback will run and should
    // call upload_pdf.php with the correct scan_id (which is now in the response).
    echo json_encode([
        'ok' => true,
        'scan_id' => $scan_id,
        'report_text' => $report_text,
        'pdf_server' => $pdfPathRelative !== null,   // tells frontend whether server handled PDF
        'pdf_error' => $pdfError,                   // null if OK, message if failed
        'download' => [
            'csv' => 'download_scan.php?id=' . $scan_id . '&type=csv',
            'html' => 'download_scan.php?id=' . $scan_id . '&type=html',
            'doc' => 'download_scan.php?id=' . $scan_id . '&type=doc',
            'pdf' => $pdfPathRelative
                ? 'download_scan.php?id=' . $scan_id . '&type=pdf'
                : null,
        ],
    ]);

} catch (Exception $e) {
    error_log('save_scan_report: ' . $e->getMessage());
    echo json_encode([
        'error' => 'Failed to save report',
        'report_text' => '',
        'details' => $e->getMessage(),
    ]);
}


// ── OpenAI report generation ─────────────────────────────────────────────────

/**
 * Merge repeated findings that only differ by path (e.g. "Missing CSP (page: /a)" … "/z")
 * into one row per issue type so the narrative can cover all distinct problems without
 * hitting prompt limits. Distinct types (admin panels, cookies, server disclosure) stay separate.
 */
function compact_vulnerabilities_for_ai_report(array $vulns): array
{
    $groups = [];
    foreach ($vulns as $v) {
        if (!is_array($v)) {
            continue;
        }
        $rawName = (string) ($v['name'] ?? '');
        $key = strtolower(trim(preg_replace('/\s*\(page:\s*[^)]+\)\s*/i', '', $rawName)));
        if ($key === '') {
            $key = '_row_' . md5($rawName . '|' . ($v['severity'] ?? ''));
        }
        if (!isset($groups[$key])) {
            $groups[$key] = ['v' => $v, 'count' => 0, 'paths' => []];
        }
        $groups[$key]['count']++;
        if (preg_match('/\(page:\s*([^)]+)\)/i', $rawName, $m)) {
            $p = trim($m[1]);
            if ($p !== '' && !in_array($p, $groups[$key]['paths'], true)) {
                $groups[$key]['paths'][] = $p;
            }
        }
    }

    $out = [];
    foreach ($groups as $g) {
        $v = $g['v'];
        $baseName = preg_replace('/\s*\(page:\s*[^)]+\)\s*/i', '', (string) ($v['name'] ?? ''));
        $baseName = trim($baseName);
        if ($g['count'] > 1) {
            $pathsSample = array_slice($g['paths'], 0, 8);
            $suffix = ' — Observed on ' . $g['count'] . ' checked path(s)';
            if ($pathsSample !== []) {
                $suffix .= ' (examples: ' . implode(', ', $pathsSample);
                if (count($g['paths']) > count($pathsSample)) {
                    $suffix .= ', …';
                }
                $suffix .= ')';
            }
            $v['name'] = $baseName . $suffix;
        } else {
            $v['name'] = trim((string) ($v['name'] ?? ''));
        }
        $out[] = $v;
    }

    return $out;
}

function generate_report_via_openai(array $scan): string
{
    global $sqOpenAiKey;
    if (empty($sqOpenAiKey)) {
        $target = (string) ($scan['target'] ?? '');
        return $target !== '' ? "AI is not configured on this server for {$target}." : "AI is not configured on this server.";
    }
    $summary = $scan['summary'] ?? [];
    $risk_level = $summary['risk_level'] ?? 'Unknown';
    $risk_score = $summary['risk_score'] ?? 0;
    $total = $summary['total_vulnerabilities'] ?? 0;
    $breakdown = $summary['severity_breakdown'] ?? [];
    $vulns = $scan['vulnerabilities'] ?? [];
    $target = $scan['target'] ?? '';
    $headers = $scan['headers'] ?? [];
    $headerScore = $headers['score'] ?? 0;
    $headerPresent = $headers['present'] ?? 0;
    $headerMissing = $headers['missing'] ?? 0;
    $misconfigured = $headers['misconfigured'] ?? [];

    $compact = compact_vulnerabilities_for_ai_report($vulns);
    $findings = [];
    foreach (array_slice($compact, 0, 50) as $v) {
        $entry = 'Finding: ' . ($v['name'] ?? '') . ' (Severity: ' . ($v['severity'] ?? '') . '). ';
        $entry .= 'What we tested: ' . ($v['what_we_tested'] ?? $v['description'] ?? '') . ' ';
        $entry .= 'This indicates: ' . ($v['indicates'] ?? '') . ' ';
        $entry .= 'How exploited: ' . ($v['how_exploited'] ?? '') . ' ';
        $entry .= 'How to mitigate: ' . ($v['remediation'] ?? '');
        $findings[] = $entry;
    }
    $findingsText = implode("\n\n", $findings) ?: 'No issues found.';

    $misconfiguredText = '';
    if (!empty($misconfigured)) {
        $lines = [];
        foreach (array_slice($misconfigured, 0, 10) as $m) {
            $lines[] = ($m['header'] ?? 'header') . ' - ' . ($m['issue'] ?? '');
        }
        $misconfiguredText = implode('; ', $lines);
    }

    $prompt = "Write a clear, plain-language security report for a website owner who is not a security expert.\n";
    $prompt .= "STRICT RULES:\n";
    $prompt .= "- Do NOT use markdown characters like *, -, #, or bullet symbols.\n";
    $prompt .= "- Organise the report into short titled sections in plain text only.\n";
    $prompt .= "For each finding you mention, explain: what we tested, what was found or missing, which vulnerability this indicates, how it can be exploited, and how to mitigate it.\n";
    $prompt .= "- Use 3 main parts: 1) What we checked and overall risk. 2) Cover EVERY distinct finding type listed below (the list is already merged when the same issue appeared on many URLs). Include headers, HTTPS, server or version disclosure, cookies, admin or sensitive endpoints, and anything else in the list. 3) Summary of priorities and next steps.\n";
    $prompt .= "- Include a short paragraph explaining what Security Headers are and what the score means.\n";
    $prompt .= "- If a finding says it was observed on multiple paths, say once that it applies broadly across the site, not only the homepage.\n";
    $prompt .= "- Length: thorough but readable (roughly 10 to 18 short paragraphs or titled blocks, not a bare minimum summary).\n\n";
    $prompt .= "Target: {$target}\n";
    $prompt .= "Risk level: {$risk_level} (score: {$risk_score})\n";
    $prompt .= "Total issues: {$total}\n";
    $prompt .= "Severity breakdown: " . json_encode($breakdown) . "\n";
    $prompt .= "Security headers score: {$headerScore}% (present: {$headerPresent}, missing: {$headerMissing}).\n";
    if ($misconfiguredText !== '') {
        $prompt .= "Misconfigured headers: {$misconfiguredText}\n";
    }
    $prompt .= "\nFindings:\n{$findingsText}\n";

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 55,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $sqOpenAiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'You write clear security reports for website owners. Use only plain text (no markdown).'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => 1600,
        ]),
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$response) {
        return "We scanned {$target} and found a risk level of {$risk_level} ({$total} issues). Please review the detailed findings and fix critical and high severity items first.";
    }
    $data = json_decode($response, true);
    $text = $data['choices'][0]['message']['content'] ?? '';
    return trim($text) ?: "Security scan completed for {$target}. Risk: {$risk_level}. See detailed findings for next steps.";
}

function generate_detailed_report_via_openai(array $scan): string
{
    global $sqOpenAiKey;
    if (empty($sqOpenAiKey)) {
        $target = (string) ($scan['target'] ?? '');
        return $target !== '' ? "AI is not configured on this server for {$target}." : "AI is not configured on this server.";
    }
    $summary = $scan['summary'] ?? [];
    $risk_level = $summary['risk_level'] ?? 'Unknown';
    $risk_score = $summary['risk_score'] ?? 0;
    $total = $summary['total_vulnerabilities'] ?? 0;
    $breakdown = $summary['severity_breakdown'] ?? [];
    $vulns = $scan['vulnerabilities'] ?? [];
    $target = $scan['target'] ?? '';
    $headers = $scan['headers'] ?? [];
    $server_info = $scan['server_info'] ?? [];
    $crawler = $scan['crawler'] ?? [];

    $compact = compact_vulnerabilities_for_ai_report($vulns);
    $findings = [];
    foreach (array_slice($compact, 0, 60) as $v) {
        $findings[] = 'Finding: ' . ($v['name'] ?? '') . ' (Severity: ' . ($v['severity'] ?? '') . '). '
            . 'What we tested: ' . ($v['what_we_tested'] ?? $v['description'] ?? '') . ' '
            . 'This indicates: ' . ($v['indicates'] ?? '') . ' '
            . 'How exploited: ' . ($v['how_exploited'] ?? '') . ' '
            . 'How to mitigate: ' . ($v['remediation'] ?? '');
    }
    $findingsText = implode("\n\n", $findings) ?: 'No issues found.';
    $serverText = empty($server_info) ? 'Not disclosed.' : json_encode($server_info);
    $crawlerText = empty($crawler['discovered_urls'])
        ? 'Only target URL.'
        : count($crawler['discovered_urls']) . ' pages discovered, ' . count($crawler['pages_checked'] ?? []) . ' checked.';

    $prompt = "Write a detailed, executive-style security overview. Plain text only (no markdown, no bullets).\n\n";
    $prompt .= "Sections: 1) Executive Summary. 2) Risk by Category. 3) Detailed findings (cover EVERY distinct finding type in the list below; merged rows already represent multiple URLs). 4) Server and infrastructure. 5) Remediation roadmap.\n\n";
    $prompt .= "Target {$target}. Risk: {$risk_level} (score: {$risk_score}). Issues: {$total}. Breakdown: " . json_encode($breakdown) . ".\n";
    $prompt .= "Headers score: " . ($headers['score'] ?? 0) . "%. Server info: {$serverText}. Crawler: {$crawlerText}.\n\n";
    $prompt .= "Findings:\n{$findingsText}\n";

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 70,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $sqOpenAiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'You write detailed executive-style security reports. Plain text only.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => 2500,
        ]),
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$response) {
        return "Detailed overview could not be generated. Risk: {$risk_level}. Please review the standard report and detailed findings.";
    }
    $data = json_decode($response, true);
    $text = $data['choices'][0]['message']['content'] ?? '';
    return trim($text) ?: "Security scan completed for {$target}. Risk: {$risk_level}.";
}


// ── Report builders ──────────────────────────────────────────────────────────

function build_key_signal(array $v): string
{
    $tested = trim((string)($v['what_we_tested'] ?? ''));
    $indicates = trim((string)($v['indicates'] ?? ''));

    $normalize = static function (string $text): string {
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        return trim($text, " \t\n\r\0\x0B.;,");
    };

    $tested = $normalize($tested);
    $indicates = $normalize($indicates);

    if ($tested !== '' && $indicates !== '') {
        $sentence = "Testing {$tested} indicates {$indicates}.";
    } elseif ($indicates !== '') {
        $sentence = "The observed signal indicates {$indicates}.";
    } elseif ($tested !== '') {
        $sentence = "Testing focused on {$tested}.";
    } else {
        $sentence = "Scanner identified a security-relevant signal requiring review.";
    }

    if (strlen($sentence) > 220) {
        $sentence = rtrim(substr($sentence, 0, 217)) . '...';
    }
    return $sentence;
}

function build_csv_report(array $d): string
{
    $lines = [];
    $lines[] = 'Target,' . $d['target'];
    $lines[] = 'Scanned At,' . ($d['timestamp'] ?? '');
    $lines[] = 'Scan Duration (s),' . ($d['scan_duration'] ?? 0);
    $lines[] = 'Risk Level,' . ($d['summary']['risk_level'] ?? '');
    $lines[] = 'Risk Score,' . ($d['summary']['risk_score'] ?? 0);
    $lines[] = '';
    $lines[] = 'Severity,Name,Description,What We Tested,This Indicates,Key Indicator,How Exploited,Remediation';
    foreach ($d['vulnerabilities'] ?? [] as $v) {
        $keySignal = build_key_signal($v);
        $row = [
            $v['severity'] ?? '',
            str_replace('"', '""', $v['name'] ?? ''),
            str_replace('"', '""', $v['description'] ?? ''),
            str_replace('"', '""', $v['what_we_tested'] ?? ''),
            str_replace('"', '""', $v['indicates'] ?? ''),
            str_replace('"', '""', $keySignal),
            str_replace('"', '""', $v['how_exploited'] ?? ''),
            str_replace('"', '""', $v['remediation'] ?? ''),
        ];
        $lines[] = '"' . implode('","', $row) . '"';
    }
    return implode("\r\n", $lines);
}

function build_html_report(array $d, string $reportText): string
{
    $summary = $d['summary'] ?? [];
    $ssl = $d['ssl'] ?? [];
    $headers = $d['headers'] ?? [];
    $target = htmlspecialchars($d['target'] ?? '');
    $ts = !empty($d['timestamp']) ? date('Y-m-d H:i:s', strtotime($d['timestamp'])) : '';
    $duration = $d['scan_duration'] ?? 0;
    $riskLevel = $summary['risk_level'] ?? 'Secure';
    $riskScore = $summary['risk_score'] ?? 0;
    $riskClass = strtolower(preg_replace('/[^a-z]/i', '', (string) $riskLevel));

    $rows = '';
    foreach ($d['vulnerabilities'] ?? [] as $v) {
        $keySignal = build_key_signal($v);
        $rows .= '<tr>'
            . '<td>' . htmlspecialchars($v['severity'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($v['name'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($v['description'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($v['what_we_tested'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($v['indicates'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($keySignal) . '</td>'
            . '<td>' . htmlspecialchars($v['how_exploited'] ?? '') . '</td>'
            . '<td>' . htmlspecialchars($v['remediation'] ?? '') . '</td>'
            . '</tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="8">No issues found.</td></tr>';
    }

    $reportBlock = $reportText
        ? '<div class="report-block">' . nl2br(htmlspecialchars($reportText)) . '</div>'
        : '';
    $httpsYesNo = !empty($ssl['https']) ? 'Yes' : 'No';
    $sslGrade = $ssl['grade'] ?? 'N/A';
    $headersScore = $headers['score'] ?? 0;

    $serverInfo = $d['server_info'] ?? [];
    $serverBlock = '';
    if (!empty($serverInfo)) {
        $lines = [];
        foreach ($serverInfo as $k => $v) {
            if ($v !== '' && $v !== null) {
                $lines[] = htmlspecialchars($k) . ': ' . htmlspecialchars($v);
            }
        }
        $serverBlock = '<h2>Server</h2><p>' . implode(' &nbsp; ', $lines) . '</p>';
    }

    $crawler = $d['crawler'] ?? [];
    $crawlerBlock = '';
    if (!empty($crawler['discovered_urls']) || !empty($crawler['pages_checked'])) {
        $total = count($crawler['discovered_urls'] ?? []);
        $checked = count($crawler['pages_checked'] ?? []);
        $crawlerBlock = '<h2>Domain Discovery</h2><p>Discovered ' . (int) $total . ' page(s); checked ' . (int) $checked . ' for security headers.</p>';
        if (!empty($crawler['discovered_urls'])) {
            $crawlerBlock .= '<ul style="margin:8px 0; padding-left:20px;">';
            foreach (array_slice($crawler['discovered_urls'], 0, 50) as $u) {
                $crawlerBlock .= '<li>' . htmlspecialchars(is_string($u) ? $u : json_encode($u)) . '</li>';
            }
            if ($total > 50) {
                $crawlerBlock .= '<li><em>... and ' . ($total - 50) . ' more</em></li>';
            }
            $crawlerBlock .= '</ul>';
        }
    }

    $headersPresent = $headers['present_names'] ?? [];
    $headersMissing = $headers['missing_names'] ?? [];
    $headersDetail = '';
    if (!empty($headersPresent) || !empty($headersMissing)) {
        $headersDetail = '<p><strong>Present:</strong> ' . (empty($headersPresent) ? 'None' : implode(', ', array_map('htmlspecialchars', $headersPresent))) . '</p>';
        $headersDetail .= '<p><strong>Missing:</strong> ' . (empty($headersMissing) ? 'None' : implode(', ', array_map('htmlspecialchars', $headersMissing))) . '</p>';
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<title>ScanQuotient Security Report</title>
<style>
*{box-sizing:border-box;}
body{font-family:Inter,"Segoe UI",Arial,sans-serif;color:#0f172a;margin:0;background:#f8fafc;line-height:1.45;}
.wrap{max-width:1100px;margin:22px auto;padding:0 16px 28px;}
.sheet{background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 16px 34px rgba(15,23,42,.08);overflow:hidden;}
.cover{padding:24px 26px;background:linear-gradient(140deg,#e0e7ff 0%,#eef2ff 45%,#ffffff 100%);border-bottom:1px solid #e2e8f0;}
.brand{display:inline-flex;gap:8px;align-items:center;font-size:12px;font-weight:700;color:#3730a3;background:rgba(79,70,229,.12);border:1px solid rgba(99,102,241,.25);padding:6px 10px;border-radius:999px;}
h1{margin:12px 0 8px;font-size:30px;line-height:1.15;}
h2{margin:0 0 12px;font-size:18px;}
.subtitle{margin:0;color:#334155;font-size:14px;}
.meta{margin-top:8px;color:#334155;font-size:13px;}
.section{padding:18px 26px;border-top:1px solid #e2e8f0;}
.kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;}
.kpi{border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:12px;}
.kpi .k{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;font-weight:700;}
.kpi .v{font-size:18px;font-weight:800;color:#0f172a;}
.report-block{margin:10px 0 0;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;color:#1e293b;}
.badge{display:inline-block;padding:8px 12px;border-radius:999px;font-size:12px;font-weight:700;}
.badge-critical,.badge-high{background:#fee2e2;color:#991b1b;}
.badge-medium{background:#fef3c7;color:#92400e;}
.badge-low{background:#dbeafe;color:#1d4ed8;}
.badge-secure,.badge-info{background:#dcfce7;color:#166534;}
.panel{border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#fff;}
.panel + .panel{margin-top:10px;}
.panel p{margin:0 0 8px;}
.finding-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:12px;}
table{border-collapse:collapse;width:100%;background:#fff;table-layout:fixed;}
th,td{border-bottom:1px solid #e5e7eb;padding:8px 6px;font-size:12px;vertical-align:top;word-break:break-word;overflow-wrap:anywhere;white-space:normal;}
th{background:#f8fafc;color:#334155;text-transform:uppercase;letter-spacing:.35px;font-size:11px;position:sticky;top:0;}
tbody tr:nth-child(even){background:#fcfdff;}
td:first-child{font-weight:700;white-space:nowrap;}
ul{margin:8px 0 0 18px;padding:0;}
li{margin:4px 0;}
@media (max-width:960px){.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
@media print{
  body{background:#fff;}
  .wrap{max-width:none;margin:0;padding:0;}
  .sheet{border:none;box-shadow:none;border-radius:0;}
  .cover,.section{break-inside:avoid;}
  th{position:static;}
  table{table-layout:fixed;}
  th,td{font-size:10px;padding:5px 4px;word-break:break-word;overflow-wrap:anywhere;}
  th:nth-child(1),td:nth-child(1){width:8%;}
  th:nth-child(2),td:nth-child(2){width:12%;}
  th:nth-child(3),td:nth-child(3){width:14%;}
  th:nth-child(4),td:nth-child(4){width:14%;}
  th:nth-child(5),td:nth-child(5){width:14%;}
  th:nth-child(6),td:nth-child(6){width:16%;}
  th:nth-child(7),td:nth-child(7){width:10%;}
  th:nth-child(8),td:nth-child(8){width:12%;}
}
</style>
</head>
<body>
<div class="wrap">
  <div class="sheet">
    <section class="cover">
      <div class="brand">ScanQuotient <span>Web Security Assessment</span></div>
      <h1>Security Assessment Report</h1>
      <p class="subtitle">Prepared for <strong>{$target}</strong></p>
      <div class="meta"><strong>Scanned:</strong> {$ts} &nbsp; <strong>Duration:</strong> {$duration}s</div>
      <p style="margin:12px 0 0;"><span class="badge badge-{$riskClass}">{$riskLevel} Risk • Score {$riskScore}</span></p>
    </section>

    <section class="section">
      <h2>Scan Overview</h2>
      <div class="kpi-grid">
        <div class="kpi"><div class="k">Target</div><div class="v">{$target}</div></div>
        <div class="kpi"><div class="k">HTTPS Enabled</div><div class="v">{$httpsYesNo}</div></div>
        <div class="kpi"><div class="k">TLS Grade</div><div class="v">{$sslGrade}</div></div>
        <div class="kpi"><div class="k">Header Score</div><div class="v">{$headersScore}%</div></div>
      </div>
      {$reportBlock}
    </section>

    <section class="section">
      <h2>Security Posture Details</h2>
      <div class="panel">
        {$headersDetail}
      </div>
      {$serverBlock}
      {$crawlerBlock}
    </section>

    <section class="section">
      <h2>Detailed Issues</h2>
      <div class="finding-wrap">
        <table>
          <thead><tr><th>Severity</th><th>Name</th><th>Description</th><th>What We Tested</th><th>This Indicates</th><th>Key Indicator</th><th>How Exploited</th><th>Remediation</th></tr></thead>
          <tbody>{$rows}</tbody>
        </table>
      </div>
    </section>
  </div>
</div>
</body>
</html>
HTML;
}


// ── PDF generation ───────────────────────────────────────────────────────────

function save_pdf_from_html(string $htmlPath, string $pdfPath): bool
{
    // Dompdf was already loaded (or attempted) at the top of the file.
    // If we reach this function, class_exists check is the final gate.
    if (!class_exists('Dompdf\Dompdf')) {
        error_log('save_pdf_from_html: Dompdf class still not available.');
        return false;
    }

    $htmlContent = file_get_contents($htmlPath);
    if ($htmlContent === false || strlen($htmlContent) < 50) {
        error_log('save_pdf_from_html: HTML file empty or unreadable: ' . $htmlPath);
        return false;
    }

    try {
        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isRemoteEnabled', false);   // don't fetch external resources
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($htmlContent, 'UTF-8');
        // Landscape ensures wide findings tables (incl. Key Indicator) fully fit.
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $output = $dompdf->output();
        if (!$output || strlen($output) < 1000) {
            error_log('save_pdf_from_html: Dompdf produced an empty or tiny PDF (' . strlen($output ?? '') . ' bytes).');
            return false;
        }

        $written = file_put_contents($pdfPath, $output);
        if ($written === false) {
            error_log('save_pdf_from_html: Could not write PDF to: ' . $pdfPath);
            return false;
        }

        return true;

    } catch (\Throwable $e) {
        error_log('save_pdf_from_html: Dompdf threw an exception: ' . $e->getMessage());
        return false;
    }
}