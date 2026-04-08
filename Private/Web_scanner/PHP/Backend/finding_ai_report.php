<?php
/**
 * ScanQuotient — Per-finding AI report generator  v4.1
 *
 * ═══════════════════════════════════════════════════════════════════
 * WHAT CHANGED FROM v4.0
 * ═══════════════════════════════════════════════════════════════════
 *
 * All four concurrency/caching fixes from v4.0 are UNCHANGED.
 * This release improves report quality only — zero speed regression.
 *
 * QUALITY IMPROVEMENTS (v4.1):
 *
 *  1. impactBulletsFromType() — now accepts $vuln and $scan arrays.
 *     Bullets reference the actual target domain, parameter name, port,
 *     service, missing attribute, and protocol extracted from evidence.
 *     No more category-level generic sentences.
 *
 *  2. recommendationsFromType() — evidence parsing added at the top.
 *     Steps reference the specific parameter, port/service, file path,
 *     missing cookie attribute, or header name from the actual finding.
 *     Includes concrete config snippets (Nginx/Apache) where relevant.
 *
 *  3. Two new helpers:
 *     - extractHeaderNameFromVulnName() — strips "Missing " prefix for
 *       readable impact bullet phrasing.
 *     - getRecommendedHeaderValue() — returns the recommended value
 *       for each known security header for use in recommendations.
 *
 *  4. AI prompt enriched — specific parsed evidence fields (parameter,
 *     payload, port, service, error string, etc.) are injected into the
 *     user prompt as a named block so the AI observed-result narrative
 *     references real scan values rather than category generics.
 *
 *  5. deriveExpectedBehavior() default case reworded to be less abstract.
 *
 *  6. fallbackReport() and normalizeReportShape() updated to pass $vuln
 *     and $scan through to impactBulletsFromType().
 *
 * ═══════════════════════════════════════════════════════════════════
 */

session_start();
require_once __DIR__ . '/../Include/sq_auth_guard.php';
require_once __DIR__ . '/deterministic_report_engine.php';
sq_require_web_scanner_auth(true);
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

// ─────────────────────────────────────────────────────────────
// CONFIG
// ─────────────────────────────────────────────────────────────

const AI_MODEL = 'gpt-4o-mini';
const AI_MAX_TOKENS = 820;
const AI_TEMPERATURE = 0.15;
const AI_TIMEOUT_SEC = 18;
const FAST_TIMEOUT_SEC = 10;
const EVIDENCE_CAP_CHARS = 1200;
const MIN_DESC_LEN = 90;
const MIN_RISK_LEN = 90;
const MIN_EVIDENCE_LEN = 60;

const CACHE_TTL_SECONDS = 86400;
const MAX_CONCURRENT_AI = 6;
const THROTTLE_SLEEP_MS = 300;
const THROTTLE_MAX_WAIT_SEC = 25;
const MAX_API_RETRIES = 2;
const RETRY_BASE_MS = 800;

// ─────────────────────────────────────────────────────────────
// BOOTSTRAP
// ─────────────────────────────────────────────────────────────

function loadOpenAiKey(): string
{
    $key = (string) (getenv('OPENAI_API_KEY') ?: '');
    if ($key !== '')
        return $key;
    $secretsPath = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'secrets.php';
    if (is_file($secretsPath)) {
        $secrets = include $secretsPath;
        if (is_array($secrets) && !empty($secrets['OPENAI_API_KEY'])) {
            return (string) $secrets['OPENAI_API_KEY'];
        }
    }
    return '';
}

function getAdminLogPdo(): ?PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO)
        return $pdo;
    try {
        $dbHost = (string) (getenv('SQ_DB_HOST') ?: '127.0.0.1');
        $dbName = (string) (getenv('SQ_DB_NAME') ?: 'scanquotient.a1');
        $dbUser = (string) (getenv('SQ_DB_USER') ?: 'root');
        $dbPass = (string) (getenv('SQ_DB_PASS') ?: '');
        $makePdo = function (string $dsn) use ($dbUser, $dbPass): PDO {
            return new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        };
        try {
            $pdo = $makePdo("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4");
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Unknown database') !== false || stripos($msg, 'unknown database') !== false) {
                try {
                    $bootstrap = $makePdo("mysql:host={$dbHost};charset=utf8mb4");
                    $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbName);
                    if ($safeName !== '') {
                        $bootstrap->exec("CREATE DATABASE IF NOT EXISTS `{$safeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                        $pdo = $makePdo("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4");
                    } else {
                        throw $e;
                    }
                } catch (Throwable) {
                    throw $e;
                }
            } else {
                throw $e;
            }
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS system_server_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_key VARCHAR(80) NOT NULL,
            level ENUM('info','warning','error') NOT NULL DEFAULT 'info',
            source VARCHAR(120) NOT NULL,
            message VARCHAR(255) NOT NULL,
            detail_json LONGTEXT NULL,
            user_id VARCHAR(64) NULL,
            request_ip VARCHAR(64) NULL,
            request_uri VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created_at (created_at),
            INDEX idx_level (level),
            INDEX idx_source (source),
            INDEX idx_event_key (event_key),
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_report_cache (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            cache_key CHAR(64) NOT NULL,
            report_json LONGTEXT NOT NULL,
            hit_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL,
            UNIQUE INDEX idx_cache_key (cache_key),
            INDEX idx_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_concurrency_slots (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            slot_token CHAR(36) NOT NULL,
            acquired_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE INDEX idx_slot_token (slot_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        return $pdo;
    } catch (Throwable $e) {
        error_log('finding_ai_report log DB init failed: ' . $e->getMessage());
        return null;
    }
}

function writeAdminLog(string $eventKey, string $message, array $detail = []): void
{
    $pdo = getAdminLogPdo();
    if (!$pdo)
        return;
    try {
        $stmt = $pdo->prepare("INSERT INTO system_server_logs
            (event_key, level, source, message, detail_json, user_id, request_ip, request_uri)
            VALUES (:event_key, 'info', 'web_scanner.finding_ai_report', :message, :detail_json,
                    :user_id, :request_ip, :request_uri)");
        $stmt->execute([
            ':event_key' => substr($eventKey, 0, 80),
            ':message' => substr($message, 0, 255),
            ':detail_json' => empty($detail) ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':user_id' => (string) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? ''),
            ':request_ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ':request_uri' => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('finding_ai_report log write failed: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────
// CACHE (v4.0 — unchanged)
// ─────────────────────────────────────────────────────────────

function buildCacheKey(array $vuln, array $scan): string
{
    $name = strtolower(trim((string) ($vuln['name'] ?? '')));
    $severity = strtolower(trim((string) ($vuln['severity'] ?? '')));
    $target = strtolower(trim((string) ($scan['target'] ?? '')));
    $type = classifyFinding($name, (string) ($vuln['description'] ?? ''));
    return hash('sha256', "{$type}|{$severity}|{$target}");
}

function readReportCache(string $cacheKey): ?array
{
    $pdo = getAdminLogPdo();
    if (!$pdo)
        return null;
    try {
        $pdo->prepare("DELETE FROM ai_report_cache WHERE cache_key = ? AND expires_at < NOW()")
            ->execute([$cacheKey]);
        $row = $pdo->prepare("SELECT report_json FROM ai_report_cache WHERE cache_key = ? LIMIT 1");
        $row->execute([$cacheKey]);
        $result = $row->fetch();
        if (!$result)
            return null;
        try {
            $pdo->prepare("UPDATE ai_report_cache SET hit_count = hit_count + 1 WHERE cache_key = ?")
                ->execute([$cacheKey]);
        } catch (Throwable) {
        }
        $decoded = json_decode((string) $result['report_json'], true);
        return is_array($decoded) ? $decoded : null;
    } catch (Throwable $e) {
        error_log('finding_ai_report cache read failed: ' . $e->getMessage());
        return null;
    }
}

function writeReportCache(string $cacheKey, array $report): void
{
    $pdo = getAdminLogPdo();
    if (!$pdo)
        return;
    try {
        $cacheable = $report;
        unset(
            $cacheable['detection_time'],
            $cacheable['target'],
            $cacheable['ip_address'],
            $cacheable['port'],
            $cacheable['state'],
            $cacheable['recommendation_stems']
        );
        $json = json_encode($cacheable, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $expiresAt = date('Y-m-d H:i:s', time() + CACHE_TTL_SECONDS);
        $pdo->prepare("INSERT INTO ai_report_cache (cache_key, report_json, expires_at)
                       VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE
                           report_json = VALUES(report_json),
                           expires_at  = VALUES(expires_at),
                           hit_count   = 0")
            ->execute([$cacheKey, $json, $expiresAt]);
    } catch (Throwable $e) {
        error_log('finding_ai_report cache write failed: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────
// CONCURRENCY THROTTLE (v4.0 — unchanged)
// ─────────────────────────────────────────────────────────────

function countActiveSlots(): int
{
    $pdo = getAdminLogPdo();
    if (!$pdo)
        return 0;
    try {
        $pdo->exec("DELETE FROM ai_concurrency_slots WHERE acquired_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)");
        $row = $pdo->query("SELECT COUNT(*) AS cnt FROM ai_concurrency_slots");
        return (int) ($row->fetchColumn() ?? 0);
    } catch (Throwable) {
        return 0;
    }
}

function acquireConcurrencySlot(): ?string
{
    $pdo = getAdminLogPdo();
    if (!$pdo)
        return 'no_db_' . bin2hex(random_bytes(8));
    $deadline = microtime(true) + THROTTLE_MAX_WAIT_SEC;
    $sleepBase = THROTTLE_SLEEP_MS * 1000;
    while (microtime(true) < $deadline) {
        if (countActiveSlots() < MAX_CONCURRENT_AI) {
            $token = sprintf(
                '%04x%04x-%04x-%04x-%04x-%012x',
                mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffffffffffff)
            );
            try {
                $pdo->prepare("INSERT INTO ai_concurrency_slots (slot_token) VALUES (?)")
                    ->execute([$token]);
                return $token;
            } catch (Throwable) {
            }
        }
        $jitter = mt_rand(0, 150000);
        usleep($sleepBase + $jitter);
    }
    return null;
}

function releaseConcurrencySlot(string $token): void
{
    $pdo = getAdminLogPdo();
    if (!$pdo)
        return;
    try {
        $pdo->prepare("DELETE FROM ai_concurrency_slots WHERE slot_token = ?")
            ->execute([$token]);
    } catch (Throwable $e) {
        error_log('finding_ai_report slot release failed: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────
// TEXT HELPERS
// ─────────────────────────────────────────────────────────────

function collapseNestedExpansions(string $text): string
{
    if ($text === '')
        return '';
    for ($i = 0; $i < 10; $i++) {
        $before = $text;
        $text = preg_replace('/Hypertext Transfer Protocol \(Hypertext Transfer Protocol \(HTTP\)\)/', 'Hypertext Transfer Protocol (HTTP)', $text);
        $text = preg_replace('/Hypertext Transfer Protocol Secure \(Hypertext Transfer Protocol Secure \(HTTPS\)\)/', 'Hypertext Transfer Protocol Secure (HTTPS)', $text);
        $text = preg_replace('/Cross-Site Scripting \(Cross-Site Scripting \(Cross-Site Scripting \(XSS\)\)\)/', 'Cross-Site Scripting (XSS)', $text);
        $text = preg_replace('/Cross-Site Scripting \(Cross-Site Scripting \(XSS\)\)/', 'Cross-Site Scripting (XSS)', $text);
        if ($text === $before)
            break;
    }
    return (string) $text;
}

function expandSecurityTerms(string $text): string
{
    if ($text === '')
        return '';
    $replacements = [
        '/\bCORS\b/' => 'Cross-Origin Resource Sharing (CORS)',
        '/\bSQLi\b/' => 'Structured Query Language injection (SQL injection)',
        '/\bSQL\b/' => 'Structured Query Language (SQL)',
        '/\bXSS\b/' => 'Cross-Site Scripting (XSS)',
        '/\bTLS\b/' => 'Transport Layer Security (TLS)',
        '/\bSSL\b/' => 'Secure Sockets Layer / Transport Layer Security (SSL/TLS)',
        '/\bCSRF\b/' => 'Cross-Site Request Forgery (CSRF)',
        '/(?<!\()\bHTTP\b(?!\))/' => 'Hypertext Transfer Protocol (HTTP)',
        '/(?<!\()\bHTTPS\b(?!\))/' => 'Hypertext Transfer Protocol Secure (HTTPS)',
        '/\bCVE\b/' => 'Common Vulnerabilities and Exposures (CVE)',
        '/\bRCE\b/' => 'Remote Code Execution (RCE)',
        '/\bVPN\b/' => 'Virtual Private Network (VPN)',
        '/\bAPI\b/' => 'Application Programming Interface (API)',
    ];
    $out = (string) preg_replace(array_keys($replacements), array_values($replacements), $text);
    return collapseNestedExpansions($out);
}

// ─────────────────────────────────────────────────────────────
// NEW v4.1 HELPERS
// ─────────────────────────────────────────────────────────────

/**
 * Strips "Missing " prefix and trailing page annotations for readable
 * impact bullet phrasing: "Missing Content-Security-Policy" →
 * "a Content-Security-Policy header".
 */
function extractHeaderNameFromVulnName(string $name): string
{
    $name = preg_replace('/^missing\s+/i', '', $name);
    $name = preg_replace('/\s*\(page:.*\)$/i', '', $name);
    return 'a ' . trim($name) . ' header';
}

/**
 * Returns the recommended production value for each known security header.
 * Used in recommendation steps so the developer gets a concrete config line.
 */
function getRecommendedHeaderValue(string $header): string
{
    return match (trim($header)) {
        'Content-Security-Policy' => "default-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'",
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains; preload',
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'X-XSS-Protection' => '1; mode=block',
        default => 'see OWASP Secure Headers Project',
    };
}

// ─────────────────────────────────────────────────────────────
// FINDING CLASSIFICATION
// ─────────────────────────────────────────────────────────────

function classifyFinding(string $name, string $description): string
{
    $s = strtolower($name . ' ' . $description);
    if (str_contains($s, 'ssrf') || str_contains($s, 'server-side request forgery'))
        return 'ssrf';
    if (str_contains($s, 'xxe') || str_contains($s, 'xml external entity'))
        return 'xxe';
    if (str_contains($s, 'remote code execution') || str_contains($s, ' rce') || str_contains($s, 'command injection') || str_contains($s, 'cmd injection'))
        return 'rce';
    if (str_contains($s, 'path traversal') || str_contains($s, 'directory traversal') || str_contains($s, '../') || str_contains($s, 'lfi') || str_contains($s, 'rfi') || str_contains($s, 'file inclusion'))
        return 'path';
    if (str_contains($s, 'idor') || str_contains($s, 'insecure direct object reference') || str_contains($s, 'broken access control') || str_contains($s, 'privilege escalation'))
        return 'idor';
    if (str_contains($s, 'authentication bypass') || str_contains($s, 'brute force') || str_contains($s, 'weak password policy') || str_contains($s, 'account takeover'))
        return 'auth';
    if (str_contains($s, 'jwt') || str_contains($s, 'json web token') || str_contains($s, 'token'))
        return 'token';
    if (str_contains($s, 'sql injection') || str_contains($s, 'sqli') || str_contains($s, 'sql'))
        return 'sqli';
    if (str_contains($s, 'xss') || str_contains($s, 'cross-site scripting'))
        return 'xss';
    if (str_contains($s, 'cors'))
        return 'cors';
    if (str_contains($s, 'csrf'))
        return 'csrf';
    if (str_contains($s, 'open redirect'))
        return 'redirect';
    if (str_contains($s, 'cookie'))
        return 'cookie';
    if (str_contains($s, 'ssl') || str_contains($s, 'tls') || str_contains($s, 'certificate'))
        return 'tls';
    if (str_contains($s, 'header'))
        return 'header';
    if (str_contains($s, 'port') || str_contains($s, 'service') || str_contains($s, 'exposed'))
        return 'port';
    if (str_contains($s, 'sensitive file') || str_contains($s, '.env') || str_contains($s, 'git'))
        return 'file';
    if (str_contains($s, 'secret') || str_contains($s, 'api key') || str_contains($s, 'credential'))
        return 'secret';
    if (str_contains($s, 'clickjacking') || str_contains($s, 'x-frame-options'))
        return 'clickjacking';
    if (str_contains($s, 'hsts') || str_contains($s, 'strict-transport-security'))
        return 'hsts';
    if (str_contains($s, 'nosniff') || str_contains($s, 'x-content-type-options') || str_contains($s, 'mime sniff'))
        return 'mime';
    if (str_contains($s, 'csp') || str_contains($s, 'content-security-policy'))
        return 'csp';
    if (str_contains($s, 'redirect') || str_contains($s, 'mixed content'))
        return 'content';
    return 'generic';
}

function categoryFromType(string $type): string
{
    return match ($type) {
        'sqli' => 'Injection / Input Validation',
        'xss' => 'Injection / Input Validation',
        'cors' => 'Access Control Misconfiguration',
        'csrf' => 'Access Control Misconfiguration',
        'redirect' => 'Access Control Misconfiguration',
        'cookie' => 'Session Management',
        'token' => 'Session Management',
        'auth' => 'Authentication and Access Control',
        'idor' => 'Authorization / Access Control',
        'tls' => 'Transport Security',
        'hsts' => 'Transport Security',
        'header' => 'Security Header Misconfiguration',
        'mime' => 'Security Header Misconfiguration',
        'csp' => 'Security Header Misconfiguration',
        'clickjacking' => 'UI Redress / Clickjacking',
        'port' => 'Network Exposure / Attack Surface',
        'file' => 'Sensitive Data Exposure',
        'path' => 'File System Exposure / Input Validation',
        'secret' => 'Credential / Secret Exposure',
        'ssrf' => 'Server-Side Request Handling',
        'xxe' => 'Parser / Input Validation',
        'rce' => 'Code Execution',
        'content' => 'Content Integrity',
        default => 'Application Security',
    };
}

function resultStatusFromSeverity(string $severity): string
{
    return match (strtolower($severity)) {
        'critical' => 'Immediate Action Required',
        'high' => 'Immediate Action Required',
        'medium' => 'Action Required',
        'low' => 'Monitor / Best Practice',
        'info' => 'Informational',
        default => 'Needs Review',
    };
}

function likelihoodFromSeverity(string $severity): string
{
    return match (strtolower($severity)) {
        'critical' => 'Very High',
        'high' => 'High',
        'medium' => 'Moderate',
        'low' => 'Low',
        default => 'Low',
    };
}

// ─────────────────────────────────────────────────────────────
// EVIDENCE EXTRACTION
// ─────────────────────────────────────────────────────────────

function extractEvidenceFields(string $rawEvidence): array
{
    $fields = [];
    preg_match_all('/^\s{0,6}([A-Za-z][A-Za-z0-9 \-\/()]{2,40})\s*:\s*(.+)$/m', $rawEvidence, $m, PREG_SET_ORDER);
    foreach ($m as $row) {
        $key = trim((string) $row[1]);
        $val = trim((string) $row[2]);
        if ($key !== '' && $val !== '')
            $fields[$key] = $val;
    }
    return $fields;
}

function distilEvidence(string $rawEvidence, string $findingType): string
{
    if ($rawEvidence === '')
        return 'No technical evidence captured.';

    $fields = extractEvidenceFields($rawEvidence);
    $priorityKeys = [
        'Injected Payload',
        'Payload',
        'Parameter Tested',
        'Observed Result',
        'Response Status',
        'Status',
        'Location Header',
        'Access-Control-Allow-Origin',
        'Access-Control-Allow-Credentials',
        'Matched Text',
        'Error Pattern Matched',
        'Observed Delay',
        'Baseline Response Time',
        'Payload Response Time',
        'Negotiated Protocol',
        'Negotiated Cipher',
        'Days Since Expiry',
        'Days Remaining',
        'Set-Cookie Header',
        'Missing Attribute',
        'Server',
        'X-Powered-By',
        'Port',
        'Service',
        'Resolved IP',
        'Raw Banner',
        'Content-Type',
        'Content-Length',
    ];

    $lines = [];
    foreach ($priorityKeys as $pk) {
        if (isset($fields[$pk]) && $fields[$pk] !== '')
            $lines[] = "{$pk}: {$fields[$pk]}";
    }
    $extra = 0;
    foreach ($fields as $k => $v) {
        if ($extra >= 6)
            break;
        if (!in_array("{$k}: {$v}", $lines, true) && $v !== '') {
            $lines[] = "{$k}: {$v}";
            $extra++;
        }
    }

    $hint = match ($findingType) {
        'sqli' => 'Finding type: SQL injection — database behavior changed by input.',
        'xss' => 'Finding type: Reflected XSS — script payload echoed in HTML response.',
        'cors' => 'Finding type: CORS misconfiguration — cross-origin policy is too permissive.',
        'tls' => 'Finding type: Transport security issue — TLS handshake or certificate problem.',
        'header' => 'Finding type: Missing or misconfigured security response header.',
        'port' => 'Finding type: Network port or service unexpectedly reachable from the internet.',
        'file' => 'Finding type: Sensitive file publicly accessible via HTTP.',
        'secret' => 'Finding type: Credential or secret key found in HTTP response.',
        'cookie' => 'Finding type: Session cookie is missing a required security attribute.',
        'redirect' => 'Finding type: Open redirect — server redirected to attacker-controlled URL.',
        default => '',
    };
    if ($hint !== '')
        $lines[] = $hint;

    $result = implode("\n", $lines);
    if (mb_strlen($result) > EVIDENCE_CAP_CHARS) {
        $result = mb_substr($result, 0, EVIDENCE_CAP_CHARS) . ' [truncated]';
    }
    return $result;
}

function extractNetworkFields(string $evidence, string $description): array
{
    $text = $evidence . "\n" . $description;
    $out = ['ip_address' => '', 'port' => '', 'service_detected' => '', 'state' => '', 'protocol' => '', 'banner' => ''];

    if (preg_match('/\b(\d{1,3}(?:\.\d{1,3}){3})\b/', $text, $m))
        $out['ip_address'] = $m[1];
    if (preg_match('/Port\s*:\s*(\d+)(?:\/(tcp|udp))?/i', $text, $m)) {
        $out['port'] = $m[1] . (!empty($m[2]) ? '/' . $m[2] : '');
        $out['protocol'] = strtoupper($m[2] ?? '');
    } elseif (preg_match('/port\s+(\d+)(?:\/(tcp|udp))?/i', $text, $m)) {
        $out['port'] = $m[1] . (!empty($m[2]) ? '/' . $m[2] : '');
        $out['protocol'] = strtoupper($m[2] ?? '');
    }
    if (preg_match('/(?:Detected Service|Service)\s*:\s*([A-Za-z0-9._\- ]+)/i', $text, $m))
        $out['service_detected'] = trim($m[1]);
    if (preg_match('/(?:Observed )?State\s*:\s*(open|closed|filtered)/i', $text, $m))
        $out['state'] = ucfirst(strtolower($m[1]));
    if (preg_match('/Raw Banner[^\n]*\n\s*\|\s*([^\n]+)/i', $text, $m))
        $out['banner'] = trim($m[1]);

    return $out;
}

// ─────────────────────────────────────────────────────────────
// IMPACT BULLETS — v4.1: references actual scan data
// ─────────────────────────────────────────────────────────────

function impactBulletsFromType(string $type, string $severity, array $vuln = [], array $scan = []): array
{
    $target = (string) ($scan['target'] ?? '');
    $name = (string) ($vuln['name'] ?? '');
    $desc = (string) ($vuln['description'] ?? '');
    $evidence = (string) ($vuln['evidence'] ?? '');

    // Extract specific values from evidence
    preg_match('/Parameter Tested\s*:\s*([^\n]+)/i', $evidence, $paramMatch);
    preg_match('/Port\s*:\s*(\d+)/i', $evidence, $portMatch);
    preg_match('/Detected Service\s*:\s*([^\n]+)/i', $evidence, $serviceMatch);
    preg_match('/Missing Attribute\s*:\s*([^\n]+)/i', $evidence, $attrMatch);
    preg_match('/Negotiated Protocol\s*:\s*([^\n]+)/i', $evidence, $protoMatch);

    $param = trim((string) ($paramMatch[1] ?? ''));
    $port = trim((string) ($portMatch[1] ?? ''));
    $service = trim((string) ($serviceMatch[1] ?? ''));
    $attr = trim((string) ($attrMatch[1] ?? ''));
    $proto = trim((string) ($protoMatch[1] ?? ''));
    $domain = parse_url($target, PHP_URL_HOST) ?: $target;
    $critical = in_array(strtolower($severity), ['critical', 'high'], true);

    return match ($type) {
        'ssrf' => [
            'Attackers can force the server to make internal network requests that are not reachable externally, bypassing firewall rules that protect internal infrastructure.',
            'Cloud metadata endpoints and internal administrative services may be exposed through server-side request pivoting — on AWS this includes the EC2 metadata API at 169.254.169.254.',
            'Server-side request forgery can be chained with credential theft or internal service exploitation to achieve full internal network access from a single external request.',
        ],
        'xxe' => [
            'External entity processing can expose local files, configuration secrets, or service credentials by reading arbitrary paths from the server file system.',
            'Attackers may trigger server-side requests via XML parsers, creating internal network exposure equivalent to server-side request forgery.',
            'XML parser abuse can lead to denial-of-service through recursive entity expansion (billion laughs attack) or oversized payload processing that exhausts server memory.',
        ],
        'rce' => [
            'Successful exploitation provides command execution on the underlying host with application-level privileges — attacker actions are indistinguishable from legitimate application activity in logs.',
            'Remote code execution leads to complete compromise of data confidentiality, integrity, and availability — database contents, configuration files, and user data are all accessible.',
            'Compromised hosts are immediately valuable for persistence, lateral movement to adjacent internal services, or deployment of ransomware and data exfiltration tooling.',
        ],
        'path' => [
            'Attackers may access files outside intended directories, including server configuration files, application source code, and credential material stored outside the web root.',
            'Directory traversal and file inclusion issues reveal application source code that accelerates further attacks by exposing internal logic, hardcoded secrets, and dependency versions.',
            'Sensitive local files can expose secrets that enable privilege escalation across environments — a single traversal read of /etc/passwd or a .env file is often enough to pivot further.',
        ],
        'idor' => [
            'Unauthorized users may access or modify records belonging to other users or tenants by substituting their own object identifiers in requests.',
            'Broken object-level authorization exposes personal data and confidential business records — all records in the affected data model are potentially reachable, not just the tested one.',
            'Integrity of account data and transaction history may be compromised without direct authentication bypass — the attacker operates as a legitimate authenticated user exploiting logic flaws.',
        ],
        'auth' => [
            'Weak authentication controls increase the likelihood of account takeover via credential stuffing — tools test millions of combinations per hour against exposed login endpoints automatically.',
            'Compromised user accounts become a foothold for lateral movement, data exfiltration, fraudulent transactions, and further privilege escalation within the application.',
            'Account takeover frequently goes undetected for extended periods — the attacker operates within normal session behaviour and leaves no intrusion signature distinguishable from legitimate use.',
        ],
        'token' => [
            'Weak token validation allows attackers to forge or replay authenticated sessions, gaining access to any account without knowing user credentials.',
            'Improper token handling may expose user identity data or grant unauthorized API access — algorithm confusion attacks can allow forging tokens for any user ID.',
            'Session integrity is undermined when token signatures, expiry, or audience claims are not strictly enforced — a single forged token provides the same access as a valid login.',
        ],
        'sqli' => [
            ($param !== ''
                ? "The '{$param}' parameter on {$domain} passes input directly into database queries — an attacker can extract every table, row, and credential without authentication using freely available tooling."
                : "Unsanitised input on {$domain} reaches the database layer — full data extraction is possible without authentication using freely available tooling."),
            ($critical
                ? "Depending on database user permissions, this vulnerability may allow file system access or operating system command execution on the server hosting {$domain} — not just data theft."
                : "User records, password hashes, session tokens, and any stored payment references on {$domain} are within reach of a single automated tool run against this endpoint."),
            "Regulatory exposure is immediate — a successful extraction triggers mandatory breach notification under GDPR, HIPAA, or PCI-DSS depending on the data categories held by {$domain}.",
        ],
        'xss' => [
            "Any visitor who clicks a crafted link to {$domain} will execute attacker-controlled JavaScript in their browser — their session cookie can be stolen and replayed instantly without the user noticing.",
            "An attacker with script execution can silently submit forms, change account settings, or exfiltrate sensitive page data on behalf of the victim — no further interaction is required once the link is clicked.",
            "If the payload is stored rather than reflected, every subsequent visitor to the affected page on {$domain} is automatically attacked — no crafted link is needed and no user action is required.",
        ],
        'cors' => [
            "Any website can make credentialed requests to {$domain}'s Application Programming Interface (API) using a logged-in user's session — private data returned by those endpoints is fully readable by the attacker's site.",
            "Authenticated state-changing actions on {$domain} — profile updates, password changes, data deletions, purchases — can be triggered cross-origin without the user's knowledge or consent.",
            "Users of {$domain} have no indication their session is being abused — the attack runs silently in a background browser tab or iframe while they are browsing a different site.",
        ],
        'csrf' => [
            'Attackers can perform state-changing actions on behalf of authenticated users without their knowledge by embedding crafted requests in other websites or emails.',
            'Account settings, passwords, email addresses, or financial actions could be modified via a single crafted link that the victim clicks while logged into the application.',
            'User trust in the platform is damaged when their account is misused — and the application has no reliable mechanism to distinguish a forged request from a legitimate one.',
        ],
        'tls' => [
            ($proto !== ''
                ? "{$proto} is deprecated and vulnerable to known protocol attacks — an attacker with network access between the user and {$domain} can decrypt traffic in real time using published tooling."
                : "Weak transport security on {$domain} allows a network-positioned attacker to intercept or modify all data in transit including login credentials and session tokens."),
            "Users on shared networks — corporate Wi-Fi, coffee shops, airports — are directly exposed to interception; no special hardware is required to execute a man-in-the-middle attack against deprecated protocols.",
            "Modern browsers display security warnings for deprecated TLS configurations, reducing user trust and potentially blocking access to {$domain} entirely for users who follow browser guidance.",
        ],
        'hsts' => [
            "Users may be downgraded from Hypertext Transfer Protocol Secure (HTTPS) to unencrypted Hypertext Transfer Protocol (HTTP) on first visit, enabling complete traffic interception before the secure session is established.",
            "Session tokens and credentials can be exposed if browser traffic is forced over insecure channels — SSL stripping tools automate this attack and require no user interaction beyond visiting the site.",
            "Without strict transport policy, the protection provided by HTTPS is conditional on the user never being subjected to a downgrade — an assumption that cannot be maintained on shared networks.",
        ],
        'header' => [
            ($name !== ''
                ? "Without " . extractHeaderNameFromVulnName($name) . ", {$domain}'s browser security depends entirely on the application never having an injection vulnerability — one slip makes the missing header's absence critical."
                : "Missing browser security headers on {$domain} remove a layer of defence that operates independently of application code — every future vulnerability is harder to contain."),
            "Browser-enforced policies are the last line of defence when application-layer controls fail — their absence means a successful injection or content attack runs without any browser-level restriction.",
            "Automated scanners, penetration testers, and security-conscious clients flag missing headers immediately — their absence signals an immature security posture to external auditors and compliance reviewers.",
        ],
        'mime' => [
            'Browsers may interpret content types incorrectly, enabling script execution from non-script resources such as uploaded image or document files.',
            'MIME sniffing turns file upload or static content paths into script execution vectors — an attacker uploads a JavaScript file with an image extension and the browser runs it.',
            'Response handling ambiguity increases cross-site scripting and content confusion risk — removing the nosniff header forces browsers to guess content types from file contents, not server declarations.',
        ],
        'csp' => [
            "Lack of a strong Content Security Policy on {$domain} allows injected scripts to execute with full browser-level access — there is no policy boundary to contain a successful injection.",
            'Compromised third-party script sources can impact all users when policy boundaries are not enforced — a single compromised CDN script affects every visitor without any application code change.',
            'Browser-side exploit containment is significantly reduced — a Content Security Policy violation report would otherwise alert the security team to active injection attempts in production.',
        ],
        'clickjacking' => [
            'Attackers can trick users into clicking hidden elements overlaid on your page, triggering unintended actions such as account deletions, fund transfers, or permission grants.',
            "Sensitive workflows on {$domain} — profile updates, payment confirmations, permission changes — may be completed unknowingly when the page is embedded in a transparent iframe on an attacker's site.",
            'UI redress attacks are simple to execute and require no technical sophistication — a basic HTML page with a transparent iframe is sufficient to carry out the attack against any unprotected page.',
        ],
        'port' => [
            ($service !== '' && $port !== ''
                ? "{$service} on port {$port} is reachable from the public internet on {$domain} — automated scanners identify and attempt exploitation of this service class within minutes of a port becoming accessible."
                : "The exposed service on {$domain} is reachable from the public internet and will be identified by automated scanning infrastructure within hours."),
            ($critical
                ? "Services of this type are a primary target for ransomware operators and credential-harvesting botnets — a single successful unauthenticated connection may provide full server access."
                : "Lateral movement through internal infrastructure becomes possible if this service is compromised, even if the service itself holds no sensitive data."),
            "Default or weak credentials on exposed services are exploited by automated tools that attempt thousands of combinations per minute — no human attacker involvement is required for initial access.",
        ],
        'file' => [
            "The exposed file on {$domain} is publicly accessible and may already have been retrieved by search engine crawlers, automated vulnerability scanners, or threat intelligence collection platforms before this scan ran.",
            "Configuration and environment files typically contain database connection strings, API keys, and secret values — each one represents a separate account or service that must be treated as fully compromised.",
            "Source code and infrastructure details extracted from exposed files on {$domain} accelerate every subsequent attack by revealing internal architecture, dependency versions, and application logic.",
        ],
        'secret' => [
            "The exposed credential gives direct, authenticated access to the connected service — no vulnerability chaining or additional exploitation is required beyond reading the response.",
            "Cloud provider keys found in responses are routinely harvested by automated bots within minutes of exposure — the financial and data impact of a compromised cloud account can be immediate and severe.",
            "Credentials embedded in application responses cannot be rotated silently — every system, partner, or integration that uses the same secret must be updated simultaneously to close the exposure completely.",
        ],
        'redirect' => [
            "{$domain}'s trusted domain name becomes a delivery mechanism for phishing attacks — victims see your URL in the address bar or link preview and proceed with a false sense of security.",
            "Credential harvesting pages that begin with {$domain}'s hostname bypass many corporate URL filtering and email security tools that rely on domain reputation for allow/deny decisions.",
            "Social engineering campaigns using open redirects are significantly more effective — click-through rates on phishing links increase substantially when the link origin appears to be a trusted, known domain.",
        ],
        default => [
            "This finding on {$domain} indicates a security control is not performing as designed under adversarial conditions — the specific failure mode is documented in the evidence section.",
            "Attackers actively scan for this vulnerability class and exploitation is typically achievable with publicly available tooling requiring minimal skill or preparation.",
            "Unaddressed findings compound over time — each unresolved issue increases the number of available attack paths and the probability of a successful breach.",
        ],
    };
}

// ─────────────────────────────────────────────────────────────
// RECOMMENDATIONS — v4.1: references specific finding data
// ─────────────────────────────────────────────────────────────

function recommendationsFromType(string $type, array $vuln): array
{
    $remediationFromScanner = trim((string) ($vuln['remediation'] ?? ''));
    $evidence = (string) ($vuln['evidence'] ?? '');
    $desc = (string) ($vuln['description'] ?? '');

    // Extract specific values from evidence
    preg_match('/Parameter Tested\s*:\s*([^\n]+)/i', $evidence, $pm);
    preg_match('/Port\s*:\s*(\d+)/i', $evidence, $portM);
    preg_match('/Detected Service\s*:\s*([^\n]+)/i', $evidence, $svcM);
    preg_match('/Missing Attribute\s*:\s*([^\n]+)/i', $evidence, $attrM);
    preg_match('/(?:File|Path)\s*:\s*([^\n]+)/i', $evidence, $fileM);
    preg_match('/Negotiated Protocol\s*:\s*([^\n]+)/i', $evidence, $protoM);
    preg_match('/Cipher Suite\s*:\s*([^\n]+)/i', $evidence, $cipherM);
    preg_match('/Header Checked\s*:\s*([^\n]+)/i', $evidence, $headerM);

    $param = trim((string) ($pm[1] ?? ''));
    $port = trim((string) ($portM[1] ?? ''));
    $service = trim((string) ($svcM[1] ?? ''));
    $attr = trim((string) ($attrM[1] ?? ''));
    $file = trim((string) ($fileM[1] ?? ''));
    $proto = trim((string) ($protoM[1] ?? ''));
    $cipher = trim((string) ($cipherM[1] ?? ''));
    $header = trim((string) ($headerM[1] ?? ''));

    $base = match ($type) {
        'ssrf' => [
            'Restrict outbound server-side HTTP requests using an explicit destination allowlist and block private, loopback, and link-local address ranges (10.x, 172.16–31.x, 192.168.x, 169.254.x) at the application layer.',
            'Normalize and validate all user-supplied URLs before request dispatch — reject non-HTTP schemes (file://, gopher://, dict://) and resolve hostnames to IP addresses before checking against the allowlist.',
            'Disable automatic redirects on server-side fetchers unless the redirect target is revalidated against the allowlist — attackers use open redirects on allowlisted hosts to pivot to internal addresses.',
            'Retest with internal IP and cloud metadata endpoint payloads (http://169.254.169.254/latest/meta-data/) after remediation to confirm internal network pivoting is blocked.',
        ],
        'xxe' => [
            'Disable Document Type Definition processing and external entity resolution in every XML parser used by the application — in PHP: libxml_disable_entity_loader(true) / in Java: set FEATURE_SECURE_PROCESSING.',
            'Use parser configurations that enforce secure processing limits and reject recursive entity expansion patterns that enable billion laughs denial-of-service.',
            'Apply strict schema validation and input size limits before XML parsing — reject oversized payloads at the network edge before they reach the parser.',
            'Retest with standard external entity payloads targeting /etc/passwd and internal URLs to verify file retrieval and server-side request forgery via XML are blocked.',
        ],
        'rce' => [
            'Remove direct shell or interpreter invocation paths that include any user-influenced input — replace dynamic command composition with library calls that do not invoke a shell.',
            'Use strict allowlist validation for any command arguments that cannot be eliminated — reject anything not matching an exact expected format before it reaches execution.',
            'Run application services with least-privilege operating system accounts — the application process should not have write access to the web root or shell execution capabilities beyond its function.',
            'Retest known command injection payload variants (semicolons, pipe characters, backtick substitution) to confirm arbitrary command execution is prevented after remediation.',
        ],
        'path' => [
            'Normalize and canonicalize file paths server-side using realpath() or equivalent, then reject any resolved path that does not start with the approved base directory.',
            'Reject traversal patterns (../, ..\, %2e%2e%2f) and encoded path segments before they reach file access functions — apply this at the input validation layer, not just the file access layer.',
            'Disable dynamic file include behavior that accepts user-controlled path input directly — replace with a lookup table that maps identifiers to safe, hardcoded file paths.',
            'Retest with traversal and file inclusion payloads targeting /etc/passwd and application configuration files to confirm out-of-scope file access is blocked.',
        ],
        'idor' => [
            'Enforce object-level authorization checks on every read, update, and delete operation — verify that the authenticated user owns or has explicit permission to access the requested resource identifier.',
            'Use server-derived ownership context rather than trusting client-supplied object identifiers — fetch the resource and check its owner attribute against the session, do not compare IDs.',
            'Add tenancy and ownership validation middleware for all sensitive endpoints — this ensures new routes added in future automatically inherit the authorization check.',
            'Retest with cross-account object identifiers after remediation to confirm unauthorized access to other users\' records returns 403, not the record contents.',
        ],
        'auth' => [
            'Implement rate limiting and exponential back-off on the authentication endpoint — after 5 failed attempts per IP per account, require a CAPTCHA or introduce a time delay before the next attempt is accepted.',
            'Enforce strong password requirements and offer multi-factor authentication for all accounts — require it for administrative and privileged accounts without exception.',
            'Add IP reputation and device fingerprinting signals to authentication flows to detect and challenge credential stuffing patterns before they succeed.',
            'Retest authentication abuse scenarios (high-volume login attempts, credential stuffing) after remediation to confirm rate limiting and lockout are effective and cannot be bypassed.',
        ],
        'token' => [
            'Enforce strict JSON Web Token (JWT) signature validation and explicitly reject tokens that use the "none" algorithm or any algorithm not on your server\'s approved list.',
            'Validate token issuer, audience, expiry, and not-before claims on every authenticated request — do not rely on the client to pass only valid tokens.',
            'Rotate signing keys on a defined schedule and store them in a dedicated secrets management system — never hardcode signing keys in application source code.',
            'Retest with forged, expired, and replayed tokens after remediation to confirm authentication bypass via token manipulation is prevented.',
        ],
        'sqli' => [
            ($param !== ''
                ? "Replace the dynamic query in the handler for the '{$param}' parameter with a parameterized query or prepared statement — the parameter value must be bound separately and never concatenated into the query string."
                : "Audit every database query in the codebase for string concatenation of user input and replace each instance with parameterized queries or prepared statements — this must be applied universally, not just to the affected endpoint."),
            "Apply server-side input validation using a strict allowlist of expected types and formats for all parameters that interact with database queries — reject any value that does not match the expected pattern before it reaches the query layer.",
            "Set database user permissions to the minimum required for application operation — the application account should not have DROP, CREATE, FILE, or EXECUTE privileges that extend the blast radius of an injection attack.",
            "Run the affected endpoint through a dedicated Structured Query Language (SQL) injection scanner (sqlmap in safe mode) after remediation to confirm the fix is complete and no sibling parameters in the same handler are also injectable.",
        ],
        'xss' => [
            "Encode all user-supplied output using context-appropriate escaping before rendering — HTML entity encoding for body content, attribute encoding for HTML attribute values, and JavaScript string encoding for values placed inside script blocks.",
            "Deploy a Content Security Policy header that sets script-src to your specific trusted origins and omits 'unsafe-inline' — this limits the blast radius of any injection that escapes output encoding.",
            "Search the codebase for every location where request parameters, URL fragments, or stored user content are written into HTML responses and apply encoding uniformly — a single missed location restores the full vulnerability.",
            "Retest the affected endpoint with the same payload family after remediation and also test stored and DOM-based variants if user-controlled content is persisted or processed client-side.",
        ],
        'cors' => [
            "Replace the permissive origin policy with a server-side allowlist — validate the incoming Origin header against the list and echo it back in Access-Control-Allow-Origin only when it matches exactly, never reflect it unconditionally.",
            "Remove Access-Control-Allow-Credentials: true from any endpoint that uses a wildcard or reflected origin — credentials and wildcard origins cannot be combined and their co-presence is always a misconfiguration.",
            "Audit every Application Programming Interface (API) route for its own Cross-Origin Resource Sharing (CORS) policy including preflight OPTIONS responses — they may have different headers from GET and POST responses.",
            "Retest with an untrusted Origin header value after remediation to confirm the fix applies to all routes and HTTP methods, not just the specific path tested during the scan.",
        ],
        'csrf' => [
            'Implement synchronizer token pattern (CSRF tokens) on all state-changing requests — generate an unpredictable per-session token server-side and verify it on every form submission or API mutation.',
            'Set SameSite=Strict or SameSite=Lax on session cookies — this prevents the browser from sending the session cookie on cross-origin requests, providing a defence-in-depth layer.',
            'Validate the Origin and Referer headers on state-changing requests as a secondary check — reject requests where neither header matches your application\'s expected origin.',
            'Retest cross-origin form submission and API mutation scenarios after remediation to confirm forged cross-site requests are rejected.',
        ],
        'tls' => [
            ($proto !== ''
                ? "Disable {$proto} explicitly in your server configuration — Nginx: `ssl_protocols TLSv1.2 TLSv1.3;` / Apache: `SSLProtocol -all +TLSv1.2 +TLSv1.3` — then restart the server and verify the change is active."
                : "Configure your server to accept only Transport Layer Security (TLS) 1.2 and 1.3 — disable all earlier protocol versions explicitly in your Nginx or Apache configuration."),
            ($cipher !== ''
                ? "Remove the weak cipher '{$cipher}' from your cipher suite list — replace with AEAD ciphers: TLS_AES_256_GCM_SHA384, TLS_CHACHA20_POLY1305_SHA256, ECDHE-RSA-AES256-GCM-SHA384."
                : "Update your cipher suite list to allow only AEAD ciphers (AES-GCM, ChaCha20-Poly1305) and explicitly remove RC4, 3DES, NULL, and EXPORT cipher entries."),
            "Test the corrected configuration using SSL Labs (ssllabs.com/ssltest) immediately after deployment — target an A or A+ grade and archive the result as evidence of remediation for compliance records.",
            "Enable automatic certificate renewal using certbot (Let's Encrypt) or your certificate authority's ACME client — configure renewal to trigger at 30 days remaining so expiry can never recur silently.",
        ],
        'hsts' => [
            'Enable the Strict-Transport-Security response header with max-age=31536000 and includeSubDomains — add the preload directive only after confirming all subdomains are also served exclusively over HTTPS.',
            'Ensure all HTTP traffic is redirected to HTTPS with a 301 permanent redirect before authentication or session establishment — the redirect must happen before any cookies are set.',
            'Validate that preload criteria are fully met before submitting the domain to the HSTS preload list (hstspreload.org) — once submitted, removal is slow and requires a separate submission process.',
            'Retest transport behavior from a fresh browser session with no HSTS cache and confirm the header is returned on the very first response, before any redirect is followed.',
        ],
        'mime' => [
            'Set the X-Content-Type-Options: nosniff header across all application and static content responses — apply this at the web server level so it covers every route without relying on application code.',
            'Ensure uploaded or user-controlled files are served with strict, correct Content-Type values that match their actual format — never derive the content type from the file extension supplied by the uploader.',
            'Separate executable and non-executable content delivery paths — serve user uploads from a separate domain that has no cookies or authentication, eliminating same-origin script execution risk.',
            'Retest browser content type handling for mixed content types after remediation to confirm script execution from non-script resources is blocked across all major browsers.',
        ],
        'csp' => [
            "Deploy a Content Security Policy that sets script-src to your specific trusted origins and omits 'unsafe-inline' — use nonces or hashes for any inline scripts that cannot be externalised.",
            'Apply the policy in report-only mode first (Content-Security-Policy-Report-Only) with a report-uri endpoint to capture violations before enforcing — tune directives based on real violation data to avoid breaking functionality.',
            'Restrict frame-ancestors, object-src, and base-uri in the policy — these directives prevent clickjacking, plugin abuse, and base tag injection respectively.',
            'Retest injection payloads after enforcement is active to confirm browser-side script execution is constrained by policy and violation reports appear in your reporting endpoint.',
        ],
        'clickjacking' => [
            'Set X-Frame-Options: DENY for all application pages — this prevents framing by any origin and is supported by all major browsers including legacy versions.',
            'Add frame-ancestors \'none\' to your Content Security Policy for modern browser enforcement — use both X-Frame-Options and CSP frame-ancestors together for maximum compatibility.',
            'Review high-risk user interaction workflows and add explicit confirmation steps that require deliberate user input — a click on a confirmation dialog cannot be triggered by clickjacking alone.',
            'Retest framing attempts from external domains after remediation to confirm the page cannot be embedded in an iframe on an untrusted origin.',
        ],
        'port' => [
            ($service !== '' && $port !== ''
                ? "Add a firewall rule to block external access to port {$port} ({$service}) immediately — allow connections only from specific internal IP ranges or through a Virtual Private Network (VPN) tunnel, not from the public internet."
                : "Add a firewall rule to block external access to this port immediately — allow connections only from specific internal IP ranges or through a Virtual Private Network (VPN) tunnel."),
            "If this service is not required for production operations, disable or uninstall it on the host entirely — an unused service that is not running cannot be discovered or exploited.",
            "If the service must remain accessible, rotate all credentials immediately and enforce key-based authentication where the service supports it — disable password authentication entirely and enable login attempt alerting.",
            "Retest external port reachability from a network outside the server's own subnet after firewall changes are applied to confirm the rule is active, correctly scoped, and not bypassed by a secondary interface.",
        ],
        'file' => [
            ($file !== ''
                ? "Block external access to '{$file}' immediately using a server-level deny rule — Nginx: `location ~ " . preg_quote($file, '/') . " { deny all; return 404; }` / Apache: add a `<Files>` block with `Require all denied`."
                : "Block access to the exposed path using a server-level deny rule — apply it at the web server or reverse proxy so it cannot be bypassed by the application routing layer."),
            "If the file contains credentials or secrets, treat them as fully compromised and rotate them immediately — check server access logs for the file path going back as far as log retention allows to assess prior exposure.",
            "Move all configuration and environment files outside the web root permanently so they are structurally inaccessible regardless of server configuration — only files intended for public serving should be in the web root.",
            "Add a deployment pipeline check that fails the build if files matching sensitive patterns (.env, .git/, *.sql, *.bak, *.config) are found inside the web root directory — this prevents recurrence across future deployments.",
        ],
        'secret' => [
            "Revoke and rotate the exposed credential immediately — do not wait to assess usage first, assume it has already been harvested since secrets exposed in HTTP responses are collected by automated bots within minutes.",
            "Remove the secret from the source code, response body, or file where it was found and replace it with an environment variable reference or a secrets manager lookup (AWS Secrets Manager, HashiCorp Vault, Azure Key Vault).",
            "Review access logs and audit trails for the affected credential covering the entire period of exposure — look for authentication events from unfamiliar IP addresses or geographic regions that indicate unauthorized use.",
            "Run a secrets scanning tool (truffleHog, gitleaks, git-secrets) across the full repository history — secrets committed to version control remain in history even after the file is deleted or the line is removed.",
        ],
        'cookie' => [
            ($attr !== ''
                ? "Add the '{$attr}' attribute to the affected Set-Cookie header in your application or web server configuration — the complete recommended value is: Set-Cookie: name=value; Secure; HttpOnly; SameSite=Strict."
                : "Add Secure, HttpOnly, and SameSite=Strict attributes to all session and authentication cookies — apply this at the framework or reverse proxy level so it covers every response route automatically."),
            "Audit every Set-Cookie header across all application routes and environments using a header inspection proxy — check authenticated and unauthenticated responses separately since some frameworks set different cookies per route.",
            "Set session cookie names to use the __Host- prefix where the application supports it — this prefix enforces the Secure attribute, prevents subdomain sharing, and cannot be overridden by an insecure origin.",
        ],
        'header' => [
            ($header !== ''
                ? "Add `{$header}: " . getRecommendedHeaderValue($header) . "` to your web server or reverse proxy configuration at the global level so it applies to every route automatically — not just the homepage."
                : "Add the missing security header at the web server or reverse proxy level so it applies globally — never rely on application code to set security headers since individual routes can miss them."),
            "Verify the header is present on all response types — authenticated pages, Application Programming Interface (API) endpoints, redirect responses (301/302), and custom error pages — using a header inspection proxy after deployment.",
            "Use the OWASP Secure Headers Project reference (owasp.org/www-project-secure-headers) for the current recommended value and test your policy against the browser compatibility matrix for your user base.",
            "Schedule a re-scan of all application routes after deployment — headers applied globally should appear consistently across all paths; any missing routes indicate a routing layer bypass requiring a separate fix.",
        ],
        'redirect' => [
            "Validate redirect destination URLs server-side against an explicit allowlist of approved internal paths — reject any destination not on the list with a 400 response and log the attempt; never silently redirect to an unvalidated URL.",
            "Never pass raw user-supplied URL values directly to Location headers — resolve relative paths on the server and construct the destination from your own base URL rather than accepting a full externally-supplied URL as input.",
            "If external redirects are a genuine product requirement, implement a signed redirect token scheme — the destination is encoded and signed server-side; the client receives only the token, which the server resolves at redirect time.",
            "Retest the affected parameter with an external URL payload after remediation and verify the response is a 400 error or a redirect to a safe default, not to the attacker-controlled destination.",
        ],
        default => [
            "Confirm this finding with a controlled retest using identical conditions to the original scan — document the before and after response behaviour as evidence of remediation for audit and compliance records.",
            "Apply the fix to the specific endpoint identified and then audit adjacent routes and functionality for the same class of issue — vulnerabilities of the same type frequently appear in clusters across related handlers.",
            "Schedule a follow-up scan within 30 days of remediation to confirm the fix is stable and has not been reversed by a subsequent deployment or dependency update.",
        ],
    };

    // Prepend the scanner's own remediation if it adds something specific
    if ($remediationFromScanner !== '' && !str_contains(strtolower($remediationFromScanner), 'see owasp')) {
        array_unshift($base, $remediationFromScanner);
    }

    $seen = [];
    $all = [];
    foreach ($base as $item) {
        $stem = strtolower(preg_replace('/[^a-z0-9 ]/i', '', $item));
        $stem = implode(' ', array_slice(explode(' ', trim($stem)), 0, 5));
        if ($stem === '' || isset($seen[$stem]))
            continue;
        $seen[$stem] = true;
        $all[] = expandSecurityTerms($item);
    }
    return array_values(array_slice($all, 0, 5));
}

// ─────────────────────────────────────────────────────────────
// IMPACT / RECOMMENDATION TABLES (legacy types — unchanged)
// ─────────────────────────────────────────────────────────────
// Note: impactBulletsFromType and recommendationsFromType above
// now handle all types. These helpers remain for any direct calls.

// ─────────────────────────────────────────────────────────────
// EVIDENCE ASSEMBLY
// ─────────────────────────────────────────────────────────────

function buildStructuredEvidence(array $vuln, array $scan): string
{
    $name = (string) ($vuln['name'] ?? '');
    $description = (string) ($vuln['description'] ?? '');
    $rawEvidence = (string) ($vuln['evidence'] ?? '');
    $whatTested = trim((string) ($vuln['what_we_tested'] ?? ''));
    $target = trim((string) ($scan['target'] ?? ''));
    $timestamp = trim((string) ($scan['timestamp'] ?? ''));
    $type = classifyFinding($name, $description);

    $testPerformed = $whatTested !== ''
        ? expandSecurityTerms($whatTested)
        : 'Automated security validation was performed for this control.';
    $expectedResult = deriveExpectedBehavior($name, $description);
    $observedResult = distilEvidence($rawEvidence, $type);

    if ($observedResult === 'No technical evidence captured.') {
        $parts = [];
        if ($description !== '')
            $parts[] = expandSecurityTerms($description);
        $indicates = trim((string) ($vuln['indicates'] ?? ''));
        if ($indicates !== '')
            $parts[] = expandSecurityTerms($indicates);
        if (!empty($parts))
            $observedResult = implode(' ', $parts);
    }

    return implode("\n", [
        'Test Performed: ' . $testPerformed,
        'Target: ' . ($target !== '' ? $target : 'Not provided'),
        'Detection Time: ' . ($timestamp !== '' ? $timestamp : 'Not provided'),
        'Expected Secure Result: ' . $expectedResult,
        'Observed Result:',
        $observedResult,
    ]);
}

// ─────────────────────────────────────────────────────────────
// FALLBACK REPORT — v4.1: passes $vuln/$scan to impact bullets
// ─────────────────────────────────────────────────────────────

function fallbackReport(array $vuln, array $scan): array
{
    $name = (string) ($vuln['name'] ?? 'Security Finding');
    $severity = ucfirst(strtolower((string) ($vuln['severity'] ?? 'medium')));
    $target = (string) ($scan['target'] ?? '');
    $timestamp = (string) ($scan['timestamp'] ?? '');
    $evidence = (string) ($vuln['evidence'] ?? '');
    $desc = (string) ($vuln['description'] ?? '');
    $indicates = (string) ($vuln['indicates'] ?? '');
    $howExpl = (string) ($vuln['how_exploited'] ?? '');

    $type = classifyFinding($name, $desc);
    $netFields = extractNetworkFields($evidence, $desc);

    $description = $desc !== ''
        ? expandSecurityTerms($desc)
        : expandSecurityTerms("The security scan identified a {$severity}-severity {$name} issue on {$target}. This finding requires review by a security administrator.");

    $riskParts = [];
    if ($indicates !== '')
        $riskParts[] = expandSecurityTerms($indicates);
    if ($howExpl !== '')
        $riskParts[] = expandSecurityTerms("An attacker could exploit this by: " . lcfirst($howExpl));
    $riskExplanation = $riskParts !== []
        ? implode(' ', $riskParts)
        : expandSecurityTerms("This {$severity}-severity finding indicates a weakness in the current security configuration that could be leveraged by an attacker to compromise the confidentiality, integrity, or availability of the application.");

    return [
        'title' => expandSecurityTerms($name),
        'severity' => $severity,
        'category' => categoryFromType($type),
        'target' => $target,
        'ip_address' => $netFields['ip_address'],
        'port' => $netFields['port'],
        'service_detected' => $netFields['service_detected'],
        'state' => $netFields['state'],
        'detection_time' => $timestamp,
        'description' => $description,
        'evidence' => buildStructuredEvidence($vuln, $scan),
        'risk_explanation' => $riskExplanation,
        'potential_impact' => impactBulletsFromType($type, $severity, $vuln, $scan),  // v4.1
        'likelihood' => likelihoodFromSeverity($severity),
        'recommendations' => recommendationsFromType($type, $vuln),                  // v4.1
        'remediation_priority' => $severity === 'Critical' || $severity === 'High' ? 'High' : ($severity === 'Medium' ? 'Medium' : 'Low'),
        'result_status' => resultStatusFromSeverity($severity),
    ];
}

function deriveExpectedBehavior(string $name, string $description): string
{
    $type = classifyFinding($name, $description);
    return match ($type) {
        'ssrf' => 'Server-side request functionality should only access explicitly approved destinations and must block internal network targets.',
        'xxe' => 'XML processing should disable external entity resolution and reject untrusted document type declarations.',
        'rce' => 'User-controlled input must never be passed into operating system command execution paths.',
        'path' => 'File access must be constrained to approved directories after path normalization and canonical checks.',
        'idor' => 'Every object access should be authorized against the current authenticated user or tenant context.',
        'auth' => 'Authentication controls should resist brute-force abuse and require strong identity verification.',
        'token' => 'Token-based authentication should enforce strict signature, issuer, audience, and expiry validation.',
        'sqli' => 'User-supplied input should be treated strictly as data and must not alter the structure or logic of the database query.',
        'xss' => 'All user-controlled content should be encoded before being rendered in the browser so no script executes.',
        'cors' => 'Cross-origin access should be permitted only to explicitly trusted, named origins — not wildcards or reflected values.',
        'csrf' => 'State-changing requests should require an unpredictable token that is verified server-side.',
        'tls' => 'Transport encryption should use a valid, trusted certificate and only modern protocol versions with strong cipher suites.',
        'hsts' => 'Browsers should be instructed to use HTTPS exclusively to prevent protocol downgrade attacks.',
        'header' => 'The web server should return all required security headers with values that enforce the intended browser security policy.',
        'mime' => 'Browsers should be prevented from MIME sniffing and must honor explicit server-declared content types.',
        'csp' => 'Content Security Policy should restrict script execution and framing to approved sources only.',
        'clickjacking' => 'Sensitive pages should not be frameable by untrusted origins.',
        'port' => 'Only services required for production should be reachable from untrusted external networks.',
        'file' => 'Sensitive files should never be served directly — they should be outside the web root or blocked by server configuration.',
        'secret' => 'Credentials and secrets should never appear in HTTP responses, source code, or client-accessible resources.',
        'cookie' => 'Session cookies should carry the Secure, HttpOnly, and SameSite attributes to protect them from theft and misuse.',
        'redirect' => 'Redirect destinations should be validated against a server-side allowlist — user-supplied URLs should never be redirected to directly.',
        default => 'The tested security control should enforce its intended policy consistently under both normal usage and deliberate adversarial input — any deviation from expected behaviour under adversarial conditions constitutes a finding.',
    };
}

// ─────────────────────────────────────────────────────────────
// AI PROMPT BUILDER — v4.1: injects parsed specific fields
// ─────────────────────────────────────────────────────────────

function buildPromptMessages(array $vuln, array $scan, array $base): array
{
    $name = (string) ($vuln['name'] ?? '');
    $severity = ucfirst(strtolower((string) ($vuln['severity'] ?? 'medium')));
    $desc = (string) ($vuln['description'] ?? '');
    $evidence = (string) ($vuln['evidence'] ?? '');
    $indicates = (string) ($vuln['indicates'] ?? '');
    $howExpl = (string) ($vuln['how_exploited'] ?? '');
    $whatTested = (string) ($vuln['what_we_tested'] ?? '');
    $remediation = (string) ($vuln['remediation'] ?? '');
    $target = (string) ($scan['target'] ?? '');
    $timestamp = (string) ($scan['timestamp'] ?? '');
    $type = classifyFinding($name, $desc);

    $distilledEvidence = distilEvidence($evidence, $type);

    // v4.1: Extract and inject specific parsed evidence values so the AI
    // observed-result narrative references real scan data, not category generics.
    $evidenceFields = extractEvidenceFields($evidence);
    $specificContext = [];
    foreach ([
        'Parameter Tested',
        'Injected Payload',
        'Observed Result',
        'Error Pattern Matched',
        'Port',
        'Detected Service',
        'Missing Attribute',
        'Negotiated Protocol',
        'Cipher Suite',
        'Location Header',
        'Matched Text',
        'Days Since Expiry',
        'Days Remaining',
        'Set-Cookie Header',
        'Header Checked',
        'Response Status',
        'Access-Control-Allow-Origin',
        'Access-Control-Allow-Credentials',
    ] as $key) {
        if (!empty($evidenceFields[$key])) {
            $specificContext[] = "{$key}: {$evidenceFields[$key]}";
        }
    }
    $specificContextStr = !empty($specificContext)
        ? implode("\n", $specificContext)
        : '(No specific field values parsed from evidence — use the distilled evidence below)';

    $evidencePrefix = implode("\n", [
        'Test Performed: ' . ($whatTested !== '' ? expandSecurityTerms($whatTested) : 'Automated security validation was performed for this control.'),
        'Target: ' . ($target !== '' ? $target : 'Not provided'),
        'Detection Time: ' . ($timestamp !== '' ? $timestamp : 'Not provided'),
        'Expected Secure Result: ' . deriveExpectedBehavior($name, $desc),
        'Observed Result:',
        '[FILL IN: write 2-4 sentences referencing the specific values listed under "Specific Observed Values" above — name the exact parameter, payload, port, service, error string, or attribute observed]',
    ]);

    $system = <<<SYSTEM
You are a senior application security analyst writing finding reports for a professional security scanner.
Your reports are read by both developers (who fix issues) and non-technical business stakeholders (who approve budgets and priorities).

OUTPUT RULES — follow every rule without exception:
1. Return ONLY a single valid JSON object. No markdown, no prose, no code fences.
2. Every string field must be plain English — no bullet symbols inside strings, no markdown.
3. potential_impact and recommendations must be JSON arrays of plain-English strings.
4. Do not fabricate CVE identifiers, IP addresses, or port numbers not present in the input.
5. Expand all security abbreviations on first use (e.g. write "Cross-Site Scripting (XSS)" not just "XSS").
6. Never use filler phrases: "it is important to", "it is worth noting", "please note", "in conclusion", "it should be noted".
7. description: 2–3 sentences, specific to this finding and target, minimum 90 characters. Name the actual endpoint, parameter, port, or file where relevant.
8. risk_explanation: 2–3 sentences explaining exactly what an attacker gains from this specific finding, minimum 90 characters. Reference the target domain and finding specifics.
9. evidence: Use the template below exactly. Replace ONLY the placeholder line with your observed result narrative.
   The narrative must reference specific values from "Specific Observed Values" — name the parameter, payload, port, error, or attribute.
   Do not write generic statements like "the scanner detected an issue". Keep all other lines exactly as provided.
10. recommendations: exactly 3–5 strings, each a concrete action sentence with a specific fix. No duplicates. Reference the specific finding where possible.
11. potential_impact: exactly 3 strings. Each must describe a specific, concrete harm — not a general security principle.
SYSTEM;

    $exampleJson = json_encode([
        'title' => 'Missing Content-Security-Policy Header',
        'severity' => 'High',
        'category' => 'Security Header Misconfiguration',
        'target' => 'https://example.com',
        'ip_address' => '',
        'port' => '',
        'service_detected' => '',
        'state' => '',
        'detection_time' => '2025-03-15T10:22:00+03:00',
        'description' => 'The server response for https://example.com does not include a Content-Security-Policy header. Without this header, the browser applies no restriction on which scripts may execute, making the application fully vulnerable to Cross-Site Scripting (XSS) if any input is reflected without encoding.',
        'evidence' => "Test Performed: We requested the page and inspected all response headers for the presence of Content-Security-Policy.\nTarget: https://example.com\nDetection Time: 2025-03-15T10:22:00+03:00\nExpected Secure Result: Content-Security-Policy header present with a restrictive policy.\nObserved Result:\nThe Content-Security-Policy header was absent from the GET response to https://example.com. All other standard headers were present. No script execution policy is enforced by the browser for this origin — any injected script will execute without restriction.",
        'risk_explanation' => 'Without a Content-Security-Policy, any Cross-Site Scripting (XSS) vulnerability on https://example.com — present today or introduced in future — will execute without any browser-level restriction. An attacker who achieves script injection can steal session tokens, log keystrokes, and perform actions as the victim with no CSP violation report to alert the security team.',
        'potential_impact' => [
            'Injected scripts can steal session cookies from https://example.com users and enable full account takeover without needing the user\'s password.',
            'Malicious scripts can silently redirect visitors to credential harvesting pages that mimic the application login.',
            'Compromised third-party scripts loaded by the page run without any policy boundary, giving supply chain attacks full browser-level access.',
        ],
        'likelihood' => 'High',
        'recommendations' => [
            "Add Content-Security-Policy: default-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self' to your Nginx or Apache configuration at the global vhost level.",
            'Deploy in report-only mode first using Content-Security-Policy-Report-Only with a report-uri endpoint to capture violations before enforcement — tune based on violation data.',
            'Verify the header is returned on all routes including API endpoints, redirect responses, and error pages using a header inspection proxy after deployment.',
        ],
        'remediation_priority' => 'High',
        'result_status' => 'Immediate Action Required',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $user = <<<USER
## YOUR TASK
Write a security finding report for the finding below. Return ONLY the JSON object.

## EXAMPLE OUTPUT SHAPE (same fields — fill with real data from this finding, do not copy example values)
{$exampleJson}

## FINDING DATA

Finding Name: {$name}
Severity: {$severity}
Target: {$target}
Detected At: {$timestamp}
Finding Type: {$type}

Short Description from Scanner:
{$desc}

What Was Tested:
{$whatTested}

What This Indicates (from scanner):
{$indicates}

How It Can Be Exploited (from scanner):
{$howExpl}

Scanner Remediation Advice:
{$remediation}

## SPECIFIC OBSERVED VALUES (reference these in your observed result narrative and description)
{$specificContextStr}

## FULL DISTILLED EVIDENCE
{$distilledEvidence}

## FIELD INSTRUCTIONS
- title: Use the exact finding name above, expanded (no abbreviations).
- severity: Use exactly: {$severity}
- category: {$base['category']}
- target: {$target}
- description: Specific to this finding on this target. Name the actual parameter, port, file, or header where it applies. 90–200 chars.
- evidence: Use EXACTLY the template below. Replace only the placeholder line with 2–4 sentences referencing specific values from "Specific Observed Values" above. Do NOT rename, reorder, or remove any other line.
  ----
  {$evidencePrefix}
  ----
- risk_explanation: What can an attacker do with this specific finding? Reference the target domain and observed values. 90–200 chars.
- potential_impact: 3 concrete, specific harm statements — not generic security principles. Reference the target or finding type.
- likelihood: {$base['likelihood']}
- recommendations: 3–5 specific, actionable steps. First step must reference the specific fix for this finding (parameter, port, file, header). Include config snippets where applicable.
- remediation_priority: {$base['remediation_priority']}
- result_status: {$base['result_status']}
USER;

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ];
}

function buildCorrectionMessages(array $originalMessages, array $failedReport, array $issues): array
{
    $issueList = implode("\n", array_map(fn($i) => "- {$i}", $issues));
    $currentJson = json_encode($failedReport, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $correction = <<<CORRECTION
The JSON report you returned failed the following quality checks:
{$issueList}

Here is the report that failed:
{$currentJson}

Fix ONLY the fields that failed. Return the complete corrected JSON object with no other text.
- description must be at least 90 characters, specific to the finding, and name the actual parameter/port/file/header observed.
- risk_explanation must be at least 90 characters and explain what an attacker gains from this specific finding — reference the target domain.
- evidence must contain ALL of these lines in order: "Test Performed:", "Expected Secure Result:", "Observed Result:".
  The Observed Result narrative must reference specific values from the scan (parameter name, payload, port, service, error string).
  Do NOT omit or rename any section label.
- recommendations must have at least 3 items, each a full action sentence with a specific fix step.
- potential_impact must have at least 3 items, each describing a concrete specific harm — not a generic security principle.
CORRECTION;

    return [
        $originalMessages[0],
        $originalMessages[1],
        ['role' => 'assistant', 'content' => $currentJson],
        ['role' => 'user', 'content' => $correction],
    ];
}

// ─────────────────────────────────────────────────────────────
// AI API WITH JITTERED RETRY (v4.0 — unchanged)
// ─────────────────────────────────────────────────────────────

function parseAiJsonFromResponse(?string $response): ?array
{
    if (!$response)
        return null;
    $payload = json_decode($response, true);
    $content = (string) ($payload['choices'][0]['message']['content'] ?? '');
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $content, $m)) {
        $content = trim((string) $m[1]);
    }
    $content = trim($content);
    if (!str_starts_with($content, '{')) {
        $start = strpos($content, '{');
        if ($start !== false)
            $content = substr($content, $start);
    }
    $ai = json_decode($content, true);
    return is_array($ai) ? $ai : null;
}

function requestAiReport(string $apiKey, array $messages, int $timeoutSec = AI_TIMEOUT_SEC, int $attempt = 0): ?array
{
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => AI_MODEL,
            'messages' => $messages,
            'max_tokens' => AI_MAX_TOKENS,
            'temperature' => AI_TEMPERATURE,
            'response_format' => ['type' => 'json_object'],
        ]),
    ]);
    $responseBody = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if (!$curlError && $responseBody && $httpStatus === 200) {
        return parseAiJsonFromResponse($responseBody);
    }

    $retryable = ($httpStatus === 429 || $httpStatus >= 500 || $curlError !== '');
    if ($retryable && $attempt < MAX_API_RETRIES) {
        $sleepMs = (int) (RETRY_BASE_MS * (2 ** $attempt)) + mt_rand(0, 500);
        usleep($sleepMs * 1000);
        error_log("finding_ai_report: retrying (attempt " . ($attempt + 1) . ") after HTTP={$httpStatus} cURL={$curlError}");
        return requestAiReport($apiKey, $messages, $timeoutSec, $attempt + 1);
    }

    error_log("finding_ai_report: API call failed after " . ($attempt + 1) . " attempt(s). HTTP={$httpStatus} cURL={$curlError}");
    return null;
}

// ─────────────────────────────────────────────────────────────
// REPORT NORMALISATION & QUALITY
// ─────────────────────────────────────────────────────────────

function normalizeReportShape(array $ai, array $base, array $vuln = [], array $scan = []): array
{
    $merged = $base;
    foreach ($base as $k => $_) {
        if (array_key_exists($k, $ai) && $ai[$k] !== null && $ai[$k] !== '') {
            $merged[$k] = $ai[$k];
        }
    }
    foreach ([
        'title',
        'severity',
        'category',
        'target',
        'description',
        'evidence',
        'risk_explanation',
        'likelihood',
        'remediation_priority',
        'result_status',
        'ip_address',
        'port',
        'service_detected',
        'state',
        'detection_time',
    ] as $sf) {
        $merged[$sf] = expandSecurityTerms(trim((string) ($merged[$sf] ?? '')));
    }

    $type = classifyFinding((string) ($merged['title'] ?? ''), (string) ($merged['description'] ?? ''));
    $impactRaw = is_array($merged['potential_impact'] ?? null) ? $merged['potential_impact'] : [];
    $recsRaw = is_array($merged['recommendations'] ?? null) ? $merged['recommendations'] : [];
    [$impactClean, $recsClean] = sanitizeImpactAndRecommendations($impactRaw, $recsRaw);

    // v4.1: fallback to context-aware bullets when AI returns too few
    if (count($impactClean) < 2)
        $impactClean = impactBulletsFromType($type, (string) ($merged['severity'] ?? 'medium'), $vuln, $scan);
    if (count($recsClean) < 2)
        $recsClean = recommendationsFromType($type, $vuln);

    $merged['potential_impact'] = $impactClean;
    $merged['recommendations'] = $recsClean;
    $merged['result_status'] = resultStatusFromSeverity($merged['severity']);
    $merged['likelihood'] = likelihoodFromSeverity($merged['severity']);
    return $merged;
}

function splitBulletLikeLines(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        $txt = trim((string) $item);
        if ($txt === '')
            continue;
        $parts = preg_split('/\r?\n+|(?<=\])\s*,\s*•\s*|^\s*•\s*/m', $txt);
        foreach ((array) $parts as $p) {
            $line = trim((string) $p);
            $line = trim($line, " \t\n\r\0\x0B,.;[]");
            if ($line !== '')
                $out[] = $line;
        }
    }
    return $out;
}

function dedupeListItems(array $items): array
{
    $seen = [];
    $out = [];
    foreach ($items as $item) {
        $txt = expandSecurityTerms(trim((string) $item));
        if ($txt === '')
            continue;
        $key = strtolower(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/i', '', $txt)));
        if ($key === '' || isset($seen[$key]))
            continue;
        $seen[$key] = true;
        $out[] = $txt;
    }
    return $out;
}

function isLikelyRecommendation(string $line): bool
{
    return (bool) preg_match('/^(add|set|configure|apply|enable|disable|validate|sanitize|escape|encode|restrict|implement|enforce|confirm|schedule|review|test|rotate|update|patch|remove)\b/i', trim($line));
}

function isMetaLabelLine(string $line): bool
{
    return (bool) preg_match('/^(recommended actions?|recommendations?|likelihood|remediation priority|result status|potential impact)\b/i', trim($line));
}

function sanitizeImpactAndRecommendations(array $impactItems, array $recommendationItems): array
{
    $impactLines = splitBulletLikeLines($impactItems);
    $recLines = splitBulletLikeLines($recommendationItems);

    $impactClean = [];
    foreach ($impactLines as $line) {
        if (isMetaLabelLine($line) || isLikelyRecommendation($line)) {
            $recLines[] = $line;
            continue;
        }
        $impactClean[] = $line;
    }
    $recClean = [];
    foreach ($recLines as $line) {
        if (isMetaLabelLine($line))
            continue;
        $recClean[] = $line;
    }
    return [dedupeListItems($impactClean), dedupeListItems($recClean)];
}

function enforceEvidenceStructure(array $report, array $vuln, array $scan): array
{
    $canonicalPrefix = buildStructuredEvidence($vuln, $scan);
    $aiEvidence = trim((string) ($report['evidence'] ?? ''));
    $aiObservedDetail = '';

    if (preg_match('/Observed\s+Result\s*:\s*\n?([\s\S]+)/i', $aiEvidence, $m)) {
        $candidate = trim((string) $m[1]);
        if (mb_strlen($candidate) > 40)
            $aiObservedDetail = $candidate;
    }

    if ($aiObservedDetail !== '') {
        $report['evidence'] = preg_replace(
            '/(Observed Result:\n)(.+)$/s',
            '$1' . $aiObservedDetail,
            $canonicalPrefix,
            1
        );
        if ($report['evidence'] === null || $report['evidence'] === '') {
            $report['evidence'] = $canonicalPrefix;
        }
    } else {
        $report['evidence'] = $canonicalPrefix;
    }
    return $report;
}

function validateReportQuality(array $report): array
{
    $issues = [];
    foreach (['title', 'severity', 'category', 'description', 'evidence', 'risk_explanation', 'likelihood', 'remediation_priority', 'result_status'] as $f) {
        if (trim((string) ($report[$f] ?? '')) === '')
            $issues[] = "Missing required field: {$f}";
    }
    if (mb_strlen(trim((string) ($report['description'] ?? ''))) < MIN_DESC_LEN)
        $issues[] = 'description is too short (min ' . MIN_DESC_LEN . ' chars)';
    if (mb_strlen(trim((string) ($report['risk_explanation'] ?? ''))) < MIN_RISK_LEN)
        $issues[] = 'risk_explanation is too short (min ' . MIN_RISK_LEN . ' chars)';
    if (mb_strlen(trim((string) ($report['evidence'] ?? ''))) < MIN_EVIDENCE_LEN)
        $issues[] = 'evidence is too short (min ' . MIN_EVIDENCE_LEN . ' chars)';
    if (!str_contains((string) ($report['evidence'] ?? ''), 'Test Performed:'))
        $issues[] = 'evidence must contain "Test Performed:" line';
    if (!str_contains((string) ($report['evidence'] ?? ''), 'Expected Secure Result:'))
        $issues[] = 'evidence must contain "Expected Secure Result:" line';
    if (!str_contains((string) ($report['evidence'] ?? ''), 'Observed Result:'))
        $issues[] = 'evidence must contain "Observed Result:" line';
    if (!is_array($report['recommendations'] ?? null) || count($report['recommendations']) < 3)
        $issues[] = 'recommendations must have at least 3 items';
    if (!is_array($report['potential_impact'] ?? null) || count($report['potential_impact']) < 2)
        $issues[] = 'potential_impact must have at least 2 items';
    return ['valid' => empty($issues), 'issues' => $issues];
}

function recommendationStem(string $text): string
{
    $t = strtolower(trim(preg_replace('/[^a-z0-9 ]/i', '', $text)));
    $parts = array_filter(explode(' ', $t));
    return implode(' ', array_slice(array_values($parts), 0, 5));
}

function enforceRecommendationDiversity(array $report, array $seenStems): array
{
    $seen = [];
    foreach ($seenStems as $s) {
        $stem = recommendationStem((string) $s);
        if ($stem !== '')
            $seen[$stem] = true;
    }
    $out = [];
    foreach ((array) ($report['recommendations'] ?? []) as $rec) {
        $text = expandSecurityTerms(trim((string) $rec));
        $stem = recommendationStem($text);
        if ($stem === '' || isset($seen[$stem]))
            continue;
        $seen[$stem] = true;
        $out[] = $text;
    }
    $src = (array) ($report['recommendations'] ?? []);
    $idx = 0;
    while (count($out) < 3 && $idx < count($src)) {
        $candidate = expandSecurityTerms(trim((string) $src[$idx]));
        $stem = recommendationStem($candidate);
        if ($stem !== '' && !isset($seen[$stem])) {
            $seen[$stem] = true;
            $out[] = $candidate;
        }
        $idx++;
    }
    $report['recommendations'] = $out;
    $report['recommendation_stems'] = array_keys($seen);
    return $report;
}

// ─────────────────────────────────────────────────────────────
// MAIN
// ─────────────────────────────────────────────────────────────

$raw = file_get_contents('php://input');
$input = json_decode((string) $raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

$vuln = is_array($input['vulnerability'] ?? null) ? $input['vulnerability'] : [];
$scan = is_array($input['scan_data'] ?? null) ? $input['scan_data'] : [];
$seenStems = is_array($input['recommendation_stems_seen'] ?? null) ? $input['recommendation_stems_seen'] : [];
$useAiRaw = $input['use_ai'] ?? null;
$useAiMode = $input['mode'] ?? '';
$bypassCacheRaw = $input['bypass_cache'] ?? null;

$useAi = filter_var($useAiRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null
    ? (bool) filter_var($useAiRaw, FILTER_VALIDATE_BOOLEAN)
    : (is_string($useAiMode) && strtolower($useAiMode) === 'ai');

$bypassCache = filter_var($bypassCacheRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null
    ? (bool) filter_var($bypassCacheRaw, FILTER_VALIDATE_BOOLEAN)
    : false;

$incomingUid = (string) ($input['vulnerability_uid'] ?? '');
if ($incomingUid !== '' && empty($vuln['_sq_uid']))
    $vuln['_sq_uid'] = $incomingUid;

if (empty($vuln)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing vulnerability payload']);
    exit;
}

// ── Deterministic base ────────────────────────────────────────────────────
$base = DeterministicReport::build($vuln, $scan);
$base = enforceEvidenceStructure($base, $vuln, $scan);
$base = enforceRecommendationDiversity($base, $seenStems);

// RULE-BASED ONLY MODE
if (!$useAi) {
    $quality = validateReportQuality($base);
    writeAdminLog('finding_report_rule_based_only', 'Rule-based report returned (AI disabled)', [
        'title' => $base['title'] ?? '',
        'quality_valid' => (bool) ($quality['valid'] ?? false),
    ]);
    echo json_encode(['ok' => true, 'report' => $base, 'source' => 'rule_based', 'quality' => $quality]);
    exit;
}

$apiKey = loadOpenAiKey();
if ($apiKey === '') {
    writeAdminLog('finding_report_fallback', 'AI key missing — fallback report returned', ['title' => $base['title'] ?? '']);
    echo json_encode(['ok' => true, 'report' => $base, 'source' => 'fallback']);
    exit;
}

// ── Cache check ───────────────────────────────────────────────────────────
$cacheKey = buildCacheKey($vuln, $scan);
$cachedReport = null;
if (!$bypassCache) {
    $cachedReport = readReportCache($cacheKey);
}

if ($cachedReport !== null) {
    $cachedReport['target'] = (string) ($scan['target'] ?? '');
    $cachedReport['detection_time'] = (string) ($scan['timestamp'] ?? '');

    $netFields = extractNetworkFields((string) ($vuln['evidence'] ?? ''), (string) ($vuln['description'] ?? ''));
    $cachedReport['ip_address'] = $netFields['ip_address'];
    $cachedReport['port'] = $netFields['port'];
    $cachedReport['service_detected'] = $netFields['service_detected'];
    $cachedReport['state'] = $netFields['state'];

    $cachedReport = enforceEvidenceStructure($cachedReport, $vuln, $scan);
    $cachedReport = enforceRecommendationDiversity($cachedReport, $seenStems);

    writeAdminLog('finding_report_cache_hit', 'Report served from cache', ['cache_key' => $cacheKey, 'title' => $cachedReport['title'] ?? '']);
    echo json_encode(['ok' => true, 'report' => $cachedReport, 'source' => 'cache']);
    exit;
}

// ── Acquire concurrency slot ──────────────────────────────────────────────
$slotToken = acquireConcurrencySlot();
if ($slotToken === null) {
    writeAdminLog('finding_report_throttled', 'Could not acquire AI slot — fallback used', ['title' => $base['title'] ?? '']);
    echo json_encode(['ok' => true, 'report' => $base, 'source' => 'fallback_throttled']);
    exit;
}

// ── Reduce timeout under high concurrency ─────────────────────────────────
$activeSlots = countActiveSlots();
$aiTimeout = ($activeSlots >= (int) (MAX_CONCURRENT_AI * 0.75)) ? FAST_TIMEOUT_SEC : AI_TIMEOUT_SEC;

try {
    // ── First AI attempt ─────────────────────────────────────────────────
    $messages = buildPromptMessages($vuln, $scan, $base);
    $ai = requestAiReport($apiKey, $messages, $aiTimeout);

    if (!is_array($ai)) {
        writeAdminLog('finding_report_fallback', 'AI returned invalid JSON — fallback used', ['title' => $base['title'] ?? '']);
        echo json_encode(['ok' => true, 'report' => $base, 'source' => 'fallback']);
        releaseConcurrencySlot($slotToken);
        exit;
    }

    // v4.1: pass $vuln and $scan so fallback bullets are context-aware
    $merged = normalizeReportShape($ai, $base, $vuln, $scan);
    $merged = enforceEvidenceStructure($merged, $vuln, $scan);
    $merged = enforceRecommendationDiversity($merged, $seenStems);
    $quality = validateReportQuality($merged);

    // ── Correction attempt if quality gate fails ──────────────────────────
    if (!$quality['valid']) {
        $correctionMessages = buildCorrectionMessages($messages, $merged, $quality['issues']);
        $retryAi = requestAiReport($apiKey, $correctionMessages, $aiTimeout);

        if (is_array($retryAi)) {
            $rebuilt = normalizeReportShape($retryAi, $base, $vuln, $scan);
            $rebuilt = enforceEvidenceStructure($rebuilt, $vuln, $scan);
            $rebuilt = enforceRecommendationDiversity($rebuilt, $seenStems);
            $rebuiltQuality = validateReportQuality($rebuilt);

            if ($rebuiltQuality['valid']) {
                writeReportCache($cacheKey, $rebuilt);
                writeAdminLog('finding_report_ai_rebuilt', 'AI report corrected and passed quality gate', [
                    'title' => $rebuilt['title'] ?? '',
                    'issues_before' => $quality['issues'],
                ]);
                echo json_encode(['ok' => true, 'report' => $rebuilt, 'source' => 'ai_corrected', 'quality' => $rebuiltQuality]);
                releaseConcurrencySlot($slotToken);
                exit;
            }
        }

        writeAdminLog('finding_report_fallback_rebuilt', 'AI failed quality gate twice — deterministic fallback used', [
            'title' => $base['title'] ?? '',
            'issues' => $quality['issues'],
        ]);
        echo json_encode(['ok' => true, 'report' => $base, 'source' => 'fallback_rebuilt', 'quality' => validateReportQuality($base)]);
        releaseConcurrencySlot($slotToken);
        exit;
    }

    // ── First AI attempt passed quality gate ─────────────────────────────
    writeReportCache($cacheKey, $merged);
    writeAdminLog('finding_report_ai_ok', 'AI report passed quality gate', ['title' => $merged['title'] ?? '']);
    echo json_encode(['ok' => true, 'report' => $merged, 'source' => 'ai', 'quality' => $quality]);

} finally {
    releaseConcurrencySlot($slotToken);
}