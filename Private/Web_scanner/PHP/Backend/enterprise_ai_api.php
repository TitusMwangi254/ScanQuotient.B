<?php
/**
 * Enterprise AI: overview (executive summary, top risks, compliance) and ask (Q&A about scan).
 * Requires enterprise package and scan ownership.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

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

$user_id = $_SESSION['user_uid'] ?? $_SESSION['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?: [];
$action = isset($input['action']) ? $input['action'] : '';
$scan_id = isset($input['scan_id']) ? (int) $input['scan_id'] : 0;
$question = isset($input['question']) ? trim($input['question']) : '';

if ($scan_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid scan']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

    $stmt = $pdo->prepare("SELECT scan_json, target_url FROM scan_results WHERE id = ? AND user_id = ?");
    $stmt->execute([$scan_id, $user_id]);
    $row = $stmt->fetch();
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Scan not found']);
        exit;
    }

    $userPackage = 'freemium';
    $stmtEmail = $pdo->prepare("SELECT email FROM users WHERE user_id = ? LIMIT 1");
    $stmtEmail->execute([$user_id]);
    $userRow = $stmtEmail->fetch();
    $userEmail = $userRow['email'] ?? null;
    if ($userEmail) {
        $stmtPay = $pdo->prepare("SELECT package FROM payments WHERE email = ? AND (account_status = 'active' OR account_status IS NULL) AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY created_at DESC LIMIT 1");
        $stmtPay->execute([$userEmail]);
        $payRow = $stmtPay->fetch();
        if ($payRow && strtolower(trim($payRow['package'] ?? '')) === 'enterprise') {
            $userPackage = 'enterprise';
        }
    }
    if ($userPackage !== 'enterprise') {
        echo json_encode(['ok' => false, 'error' => 'Enterprise feature']);
        exit;
    }

    $scan_data = json_decode($row['scan_json'], true) ?: [];
    $target = $row['target_url'] ?? $scan_data['target'] ?? '';

    if ($action === 'ask') {
        if ($question === '') {
            echo json_encode(['ok' => false, 'error' => 'Question required']);
            exit;
        }
        $wordCount = count(preg_split('/\s+/', $question, -1, PREG_SPLIT_NO_EMPTY));
        if ($wordCount > 80) {
            echo json_encode(['ok' => false, 'error' => 'Question must be 80 words or fewer. Please shorten your question.']);
            exit;
        }
        $answer = ask_gpt($scan_data, $target, $question);
        echo json_encode(['ok' => true, 'answer' => $answer]);
        exit;
    }

    if ($action === 'overview') {
        $overview = build_enterprise_overview($scan_data, $target);
        echo json_encode(['ok' => true, 'overview' => $overview]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Invalid action']);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}

function build_enterprise_overview(array $scan, string $target): array {
    $summary = $scan['summary'] ?? [];
    $vulns = $scan['vulnerabilities'] ?? [];
    $risk_level = $summary['risk_level'] ?? 'Unknown';
    $risk_score = $summary['risk_score'] ?? 0;
    $breakdown = $summary['severity_breakdown'] ?? [];
    $headers = $scan['headers'] ?? [];
    $headersScore = $headers['score'] ?? 0;
    $findingsText = '';
    foreach (array_slice($vulns, 0, 20) as $v) {
        $findingsText .= '- ' . ($v['name'] ?? '') . ' (' . ($v['severity'] ?? '') . '): ' . ($v['remediation'] ?? '') . "\n";
    }
    if ($findingsText === '') $findingsText = 'No issues found.';

    $prompt = "For this security scan (target: {$target}, risk: {$risk_level}, score: {$risk_score}, breakdown: " . json_encode($breakdown) . ", headers score: {$headersScore}%), provide a JSON object with exactly these keys (plain text only, no markdown):\n";
    $prompt .= "1. executive_summary: 2-3 sentences for C-level.\n";
    $prompt .= "2. top_3_risks: array of exactly 3 strings, each one short sentence (most critical first).\n";
    $prompt .= "3. compliance_snapshot: one short paragraph mentioning OWASP Top 10, PCI-DSS, or other frameworks if relevant to these findings.\n\n";
    $prompt .= "Findings:\n{$findingsText}\n\nRespond with only valid JSON, no other text.";

    $response = gpt_request($prompt);
    $jsonStr = $response;
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $response, $m)) {
        $jsonStr = trim($m[1]);
    }
    $decoded = json_decode($jsonStr, true);
    if (is_array($decoded) && (isset($decoded['executive_summary']) || isset($decoded['top_3_risks']))) {
        $risks = $decoded['top_3_risks'] ?? [];
        if (!is_array($risks)) $risks = [];
        return [
            'executive_summary' => $decoded['executive_summary'] ?? 'Summary unavailable.',
            'top_3_risks' => array_slice($risks, 0, 3),
            'compliance_snapshot' => $decoded['compliance_snapshot'] ?? '',
        ];
    }
    return [
        'executive_summary' => "Scan of {$target} shows {$risk_level} risk (score {$risk_score}). " . (count($vulns) ? count($vulns) . " finding(s) require attention." : "No critical issues detected."),
        'top_3_risks' => array_slice(array_map(function ($v) { return ($v['name'] ?? '') . ' (' . ($v['severity'] ?? '') . ')'; }, $vulns), 0, 3),
        'compliance_snapshot' => 'Review findings against OWASP Top 10 and your compliance requirements. Address critical and high severity items first.',
    ];
}

function ask_gpt(array $scan, string $target, string $question): string {
    $summary = $scan['summary'] ?? [];
    $vulns = $scan['vulnerabilities'] ?? [];
    $summaryText = "Target: {$target}. Risk: " . ($summary['risk_level'] ?? '') . ", score: " . ($summary['risk_score'] ?? 0) . ". Total issues: " . ($summary['total_vulnerabilities'] ?? 0);
    $findingsText = '';
    foreach (array_slice($vulns, 0, 25) as $v) {
        $findingsText .= '- ' . ($v['name'] ?? '') . ' (' . ($v['severity'] ?? '') . '): ' . ($v['description'] ?? '') . ' Mitigation: ' . ($v['remediation'] ?? '') . "\n";
    }
    $system = "You are a friendly web security consultant for ScanQuotient Enterprise. Accept greetings (e.g. hello, hi) and respond briefly and warmly. If the user greets you or asks a very general question (e.g. \"hello\"), include 3 short suggested questions they can ask next about the scan or web vulnerabilities (start the section with: \"Suggested questions:\"). Answer questions about web security and vulnerabilities in general and about this scan when relevant. Use plain text only (no markdown). Keep answers to 2-4 short paragraphs. Only if the question is clearly unrelated (e.g. politics, recipes, other non-security topics), reply briefly: \"Please ask about web security or this scan.\"";
    $prompt = "Scan context:\n{$summaryText}\n\nFindings:\n{$findingsText}\n\nQuestion: {$question}";
    return gpt_request_with_system($prompt, $system);
}

function gpt_request(string $prompt): string {
    return gpt_request_with_system($prompt, null);
}

function gpt_request_with_system(string $prompt, ?string $systemMessage): string {
    global $sqOpenAiKey;
    if (empty($sqOpenAiKey)) {
        return 'AI is not configured on this server.';
    }
    $messages = [];
    if ($systemMessage !== null && $systemMessage !== '') {
        $messages[] = ['role' => 'system', 'content' => $systemMessage];
    }
    $messages[] = ['role' => 'user', 'content' => $prompt];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $sqOpenAiKey],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'gpt-4o-mini',
            'messages' => $messages,
            'max_tokens' => 800,
        ]),
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$response) return 'Unable to get AI response.';
    $data = json_decode($response, true);
    $text = $data['choices'][0]['message']['content'] ?? '';
    return trim($text) ?: 'No response.';
}
