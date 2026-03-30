<?php
/**
 * ScanQuotient — Per-finding AI report generator  v4.0
 *
 * ═══════════════════════════════════════════════════════════════════
 * WHAT CHANGED FROM v3.1 AND WHY
 * ═══════════════════════════════════════════════════════════════════
 *
 * Problem: 20 findings all call this endpoint simultaneously.
 * Each fires up to 2 OpenAI calls → up to 40 concurrent API requests.
 * OpenAI rate-limits at ~60 RPM on most tiers, causing silent 429 errors
 * that fall through to the deterministic fallback — defeating the purpose
 * of calling AI at all.
 *
 * Four targeted fixes applied, zero logic regressions:
 *
 *  1. REPORT CACHE (DB-level, keyed by finding fingerprint)
 *     ─────────────────────────────────────────────────────
 *     A finding type + severity + target combination produces the same
 *     AI report every time. We cache the AI JSON in a new DB table
 *     `ai_report_cache` and serve it instantly for identical findings,
 *     skipping the OpenAI call entirely.
 *     Cache TTL: 24 hours (configurable via CACHE_TTL_SECONDS).
 *     Cache key: SHA-256 of (finding_type + severity + normalised_target).
 *     We intentionally exclude volatile fields (timestamp, raw evidence)
 *     from the key so findings of the same class share one cached report.
 *
 *  2. CONCURRENCY THROTTLE (DB mutex, no Redis required)
 *     ────────────────────────────────────────────────────
 *     When 20 requests arrive at once, we only allow MAX_CONCURRENT_AI
 *     (default: 6) to call OpenAI simultaneously. The rest stagger with
 *     exponential back-off + jitter (THROTTLE_SLEEP_MS base, up to
 *     THROTTLE_MAX_WAIT_SEC total). Any request that cannot acquire a
 *     slot within the wait window returns the deterministic fallback
 *     immediately — it never blocks the caller indefinitely.
 *     Slot tracking uses a lightweight `ai_concurrency_slots` table.
 *     Slots are released in a finally{} block so crashes can't leak them.
 *     A background cleanup removes slots older than 60s (stale crash guards).
 *
 *  3. JITTERED RETRY ON 429 / 5xx
 *     ────────────────────────────
 *     requestAiReport() now accepts a $attempt counter. On HTTP 429 or
 *     5xx responses it waits (RETRY_BASE_MS * 2^attempt) + random jitter
 *     before retrying, up to MAX_API_RETRIES times. This spreads retries
 *     across time so a burst of 429s doesn't create a synchronised second
 *     wave of requests that hits the rate limit again immediately.
 *
 *  4. FAST-FAIL TIMEOUT SCALING
 *     ──────────────────────────
 *     Under high concurrency the AI timeout is reduced from 18 s to
 *     FAST_TIMEOUT_SEC (10 s) when the concurrency slot queue is
 *     near-full (≥ MAX_CONCURRENT_AI * 0.75 active slots). This keeps
 *     the total wall-clock time for a 20-finding scan bounded even when
 *     OpenAI is slow: slow requests time out quickly, freeing slots for
 *     waiting requests sooner.
 *
 * All v3.1 logic (evidence structure, quality gate, correction prompt,
 * normalisation, fallback, expandSecurityTerms, etc.) is UNCHANGED.
 * The four additions are purely at the coordination/transport layer.
 * ═══════════════════════════════════════════════════════════════════
 */

session_start();
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
const AI_TIMEOUT_SEC = 18;      // Normal per-request timeout
const FAST_TIMEOUT_SEC = 10;      // Reduced timeout when queue is near-full (Fix 4)
const EVIDENCE_CAP_CHARS = 1200;
const MIN_DESC_LEN = 90;
const MIN_RISK_LEN = 90;
const MIN_EVIDENCE_LEN = 60;

// Fix 1: Cache
const CACHE_TTL_SECONDS = 86400;   // 24 hours — change to 3600 for 1-hour TTL

// Fix 2: Concurrency throttle
const MAX_CONCURRENT_AI = 6;   // Max simultaneous OpenAI calls
const THROTTLE_SLEEP_MS = 300; // Base sleep between slot-check retries (ms)
const THROTTLE_MAX_WAIT_SEC = 25;  // Give up on acquiring a slot after this long

// Fix 3: Retry with jitter
const MAX_API_RETRIES = 2;       // Total extra attempts after first failure
const RETRY_BASE_MS = 800;     // Base wait before first retry (ms)

// ─────────────────────────────────────────────────────────────
// BOOTSTRAP  (unchanged from v3.1)
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
            // Optional self-heal: if the DB doesn't exist yet, try to create it (requires privileges).
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
        // Original log table (unchanged)
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

        // ── FIX 1: Cache table ────────────────────────────────────
        // Keyed by SHA-256 fingerprint of (finding_type + severity + target).
        // Stores the entire JSON report so a cache hit = zero AI calls.
        // expires_at lets us TTL-expire stale entries without a cron job.
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

        // ── FIX 2: Concurrency slot table ─────────────────────────
        // Each active AI call INSERTs a row here and DELETEs it when done.
        // Row count = live concurrent AI calls. No Redis needed.
        // acquired_at lets us purge stale slots from crashed processes.
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
// FIX 1: REPORT CACHE  (new in v4.0)
// ─────────────────────────────────────────────────────────────

/**
 * Build the cache key for a finding.
 *
 * We deliberately exclude volatile fields (raw evidence text, exact timestamp,
 * vulnerability UID) from the key. Two findings of the same type + severity
 * on the same target will share one cached AI report — the evidence prefix
 * is rebuilt deterministically by enforceEvidenceStructure() anyway, so the
 * cached version is always structurally correct when served.
 *
 * The key is a 64-char hex SHA-256 so it fits in a CHAR(64) column with
 * a unique index, giving O(1) lookups.
 */
function buildCacheKey(array $vuln, array $scan): string
{
    $name = strtolower(trim((string) ($vuln['name'] ?? '')));
    $severity = strtolower(trim((string) ($vuln['severity'] ?? '')));
    $target = strtolower(trim((string) ($scan['target'] ?? '')));
    $type = classifyFinding($name, (string) ($vuln['description'] ?? ''));

    return hash('sha256', "{$type}|{$severity}|{$target}");
}

/**
 * Attempt to read a cached report from the DB.
 * Returns the decoded report array on hit, null on miss or expired.
 * Expired rows are deleted lazily on read so no cron is required.
 */
function readReportCache(string $cacheKey): ?array
{
    $pdo = getAdminLogPdo();
    if (!$pdo)
        return null;
    try {
        // Lazy expiry: delete expired rows for this key on read
        $pdo->prepare("DELETE FROM ai_report_cache WHERE cache_key = ? AND expires_at < NOW()")
            ->execute([$cacheKey]);

        $row = $pdo->prepare("SELECT report_json FROM ai_report_cache WHERE cache_key = ? LIMIT 1");
        $row->execute([$cacheKey]);
        $result = $row->fetch();
        if (!$result)
            return null;

        // Increment hit counter asynchronously (best-effort; ignore failure)
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

/**
 * Persist an AI-generated report to the cache.
 * Uses INSERT … ON DUPLICATE KEY UPDATE so concurrent writers don't collide.
 */
function writeReportCache(string $cacheKey, array $report): void
{
    $pdo = getAdminLogPdo();
    if (!$pdo)
        return;
    try {
        // Strip volatile per-request fields before caching so the stored
        // report is generic and reusable across different scan timestamps.
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
// FIX 2: CONCURRENCY THROTTLE  (new in v4.0)
// ─────────────────────────────────────────────────────────────

/**
 * Count currently active AI calls by counting rows in the slot table.
 * Rows older than 60 seconds are treated as stale (crashed process) and
 * are cleaned up automatically here so they never block indefinitely.
 */
function countActiveSlots(): int
{
    $pdo = getAdminLogPdo();
    if (!$pdo)
        return 0;
    try {
        // Remove stale slots from processes that crashed without releasing
        $pdo->exec("DELETE FROM ai_concurrency_slots WHERE acquired_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)");

        $row = $pdo->query("SELECT COUNT(*) AS cnt FROM ai_concurrency_slots");
        return (int) ($row->fetchColumn() ?? 0);
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Try to acquire a concurrency slot.
 * Blocks (with sleep + jitter) until a slot is available or THROTTLE_MAX_WAIT_SEC elapses.
 * Returns the slot token string on success, or null if the wait timed out.
 *
 * Jitter prevents all waiting requests from retrying at exactly the same instant
 * (the "thundering herd" problem). Each request adds a random 0–150 ms offset to
 * its sleep so they naturally spread out over time.
 */
function acquireConcurrencySlot(): ?string
{
    $pdo = getAdminLogPdo();
    if (!$pdo)
        return 'no_db_' . bin2hex(random_bytes(8));  // If DB is unavailable, skip throttling (do not block AI)

    $deadline = microtime(true) + THROTTLE_MAX_WAIT_SEC;
    $sleepBase = THROTTLE_SLEEP_MS * 1000; // Convert to microseconds

    while (microtime(true) < $deadline) {
        if (countActiveSlots() < MAX_CONCURRENT_AI) {
            // Slot appears available — try to INSERT atomically
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
                return $token; // Slot acquired
            } catch (Throwable) {
                // INSERT failed (race condition — another request snuck in); retry
            }
        }
        // Slot not available: sleep with jitter then retry
        $jitter = mt_rand(0, 150000); // 0–150 ms random jitter (microseconds)
        usleep($sleepBase + $jitter);
    }

    return null; // Timed out — caller should use fallback
}

/**
 * Release a previously acquired concurrency slot.
 * Called in a finally{} block so it runs even if the AI call throws.
 */
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
// TEXT HELPERS  (unchanged from v3.1)
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
// FINDING CLASSIFICATION  (unchanged from v3.1)
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
// EVIDENCE EXTRACTION  (unchanged from v3.1)
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
// IMPACT / RECOMMENDATION TABLES  (unchanged from v3.1)
// ─────────────────────────────────────────────────────────────

function impactBulletsFromType(string $type, string $severity): array
{
    $critical = in_array(strtolower($severity), ['critical', 'high'], true);
    return match ($type) {
        'ssrf' => [
            'Attackers can force the server to make internal network requests that are not reachable externally.',
            'Cloud metadata endpoints and internal administrative services may be exposed through server-side request pivoting.',
            'Server-side request forgery can be chained with credential theft or internal service exploitation.',
        ],
        'xxe' => [
            'External entity processing can expose local files, configuration secrets, or service credentials.',
            'Attackers may trigger server-side requests via XML parsers, creating internal network exposure.',
            'XML parser abuse can lead to denial-of-service through entity expansion or oversized payload attacks.',
        ],
        'rce' => [
            'Successful exploitation can provide command execution on the underlying host with application privileges.',
            'Remote code execution often leads to complete compromise of data confidentiality, integrity, and availability.',
            'Compromised hosts can be used for persistence, lateral movement, or malware deployment.',
        ],
        'path' => [
            'Attackers may access files outside intended directories, including configuration and credential material.',
            'Directory traversal and file inclusion issues can reveal source code that accelerates further attacks.',
            'Sensitive local files can expose secrets that enable privilege escalation across environments.',
        ],
        'idor' => [
            'Unauthorized users may access or modify records belonging to other users or tenants.',
            'Broken object-level authorization can expose personal data and confidential business records.',
            'Integrity of account data and transaction history may be compromised without direct authentication bypass.',
        ],
        'auth' => [
            'Weak authentication controls increase the likelihood of account takeover and unauthorized access.',
            'Brute-force or credential-stuffing attacks may succeed against exposed login surfaces.',
            'Compromised user accounts can be abused for fraud, data exfiltration, and further privilege abuse.',
        ],
        'token' => [
            'Weak token validation can allow attackers to forge or replay authenticated sessions.',
            'Improper token handling may expose user identity data or grant unauthorized API access.',
            'Session integrity is undermined when token signatures, expiry, or audience checks are not strictly enforced.',
        ],
        'sqli' => [
            'Attacker can read, modify, or delete all database records including user credentials.',
            $critical ? 'In some database configurations, SQL injection can lead to remote command execution on the server.' : 'Sensitive data such as passwords and emails can be extracted and sold or published.',
            'Regulatory penalties may apply if personal data is exposed (GDPR, HIPAA, PCI-DSS).',
        ],
        'xss' => [
            'Attacker can steal session cookies and impersonate any logged-in user.',
            'Malicious scripts can redirect visitors to phishing sites or silently log keystrokes.',
            'Browser-level access allows reading page content, making requests on the user\'s behalf, or installing malware.',
        ],
        'cors' => [
            'Any malicious website can make authenticated requests to your API on behalf of logged-in users.',
            'Private user data returned by API responses can be read by attacker-controlled third-party sites.',
            'Sensitive actions (data changes, deletions, purchases) can be triggered cross-origin.',
        ],
        'csrf' => [
            'Attackers can perform state-changing actions on behalf of authenticated users without their knowledge.',
            'Account settings, passwords, or financial actions could be modified via crafted links.',
            'User trust in your platform is damaged when their account is misused.',
        ],
        'tls' => [
            $critical ? 'All data transmitted between users and the server — including passwords and session tokens — is exposed.' : 'Encrypted traffic may be decryptable by an attacker with network access.',
            'Man-in-the-middle attackers on shared networks (Wi-Fi, corporate proxies) can intercept or modify traffic.',
            'Browser security warnings reduce user trust and may block access entirely.',
        ],
        'header' => [
            'Missing security headers allow common browser-based attacks that could otherwise be blocked automatically.',
            'Injected scripts or malicious content may execute without any browser-level restriction.',
            'Clickjacking, MIME-type confusion, or information leakage may be possible depending on the missing header.',
        ],
        'hsts' => [
            'Users may be downgraded to unencrypted transport on first visit, enabling interception attacks.',
            'Session tokens and credentials can be exposed if browser traffic is forced over insecure channels.',
            'Missing strict transport policy weakens defense against protocol downgrade and SSL stripping.',
        ],
        'mime' => [
            'Browsers may interpret content types incorrectly, enabling script execution from non-script resources.',
            'MIME sniffing can turn file upload or static content paths into script execution vectors.',
            'Response handling ambiguity increases cross-site scripting and content confusion risk.',
        ],
        'csp' => [
            'Lack of strong Content Security Policy allows injected scripts to execute with fewer browser restrictions.',
            'Compromised third-party script sources can impact all users when policy boundaries are not enforced.',
            'Browser-side exploit containment is significantly reduced without restrictive script and frame directives.',
        ],
        'clickjacking' => [
            'Attackers can trick users into clicking hidden elements and triggering unintended actions.',
            'Sensitive workflows such as profile updates or financial operations may be performed unknowingly.',
            'UI redress attacks become easier when framing protections are absent or weak.',
        ],
        'port' => [
            $critical ? 'Direct unauthenticated access to the service may allow data theft or remote code execution.' : 'The exposed service is reachable from the internet and increases the attack surface.',
            'Automated scanners will discover this open port and attempt known exploits or brute-force attacks.',
            'Lateral movement within the network may be possible if the service is compromised.',
        ],
        'file' => [
            'Configuration files may expose database credentials, API keys, or encryption secrets.',
            'An attacker can read application source code and identify further vulnerabilities with no effort.',
            'Exposed credentials must be rotated immediately — they should be considered fully compromised.',
        ],
        'secret' => [
            'The exposed credential gives an attacker direct access to the connected service or account.',
            'Compromised cloud keys (AWS, GCP) can result in large financial charges and complete infrastructure takeover.',
            'Stolen API keys are frequently traded and used within hours of discovery.',
        ],
        'cookie' => [
            'Session cookies without correct flags can be stolen via script injection or network interception.',
            'Cookie theft leads directly to account takeover without needing the user\'s password.',
            'A single missing attribute is enough to make an otherwise secure session vulnerable.',
        ],
        'redirect' => [
            'Your domain can be used as a trusted launchpad to redirect victims to phishing pages.',
            'Users are less likely to notice a malicious URL when it begins with a trusted hostname.',
            'Credential harvesting attacks become significantly easier when combined with open redirects.',
        ],
        default => [
            'This finding indicates a control is not functioning as expected under security conditions.',
            'Attackers actively scan for this class of issue and exploitation tools are widely available.',
            'Unaddressed findings accumulate technical security debt and increase overall risk posture.',
        ],
    };
}

function recommendationsFromType(string $type, array $vuln): array
{
    $remediationFromScanner = trim((string) ($vuln['remediation'] ?? ''));
    $base = match ($type) {
        'ssrf' => [
            'Restrict outbound server-side HTTP requests using an explicit destination allowlist and block private or link-local address ranges.',
            'Normalize and validate all user-supplied URLs before request dispatch, and reject non-HTTP schemes.',
            'Disable automatic redirects on server-side fetchers unless the redirect target is revalidated against policy.',
            'Retest with internal IP and metadata endpoint payloads to confirm internal network pivoting is blocked.',
        ],
        'xxe' => [
            'Disable Document Type Definition processing and external entity resolution in XML parsers used by the application.',
            'Use parser configurations that enforce secure processing limits and reject recursive entity expansion patterns.',
            'Apply strict schema validation and input size limits before XML parsing.',
            'Retest with standard external entity payloads to verify file retrieval and parser abuse are blocked.',
        ],
        'rce' => [
            'Remove direct shell or interpreter invocation paths that include user-influenced input.',
            'Use strict allowlist validation for command arguments and avoid dynamic command composition.',
            'Run application services with least privilege and isolate runtime environments to reduce blast radius.',
            'Retest known command injection payload variants to confirm arbitrary command execution is prevented.',
        ],
        'path' => [
            'Normalize and canonicalize file paths server-side, then enforce access within approved base directories only.',
            'Reject traversal patterns and encoded path segments that attempt to escape intended file roots.',
            'Disable dynamic file include behavior that accepts user-controlled path input directly.',
            'Retest with traversal and file inclusion payloads to confirm out-of-scope file access is blocked.',
        ],
        'idor' => [
            'Enforce object-level authorization checks on every read, update, and delete action for resource identifiers.',
            'Use server-derived ownership context rather than trusting client-supplied object identifiers.',
            'Add tenancy and ownership validation middleware for all sensitive endpoints.',
            'Retest with cross-account identifiers to confirm unauthorized object access is denied.',
        ],
        'auth' => [
            'Implement rate limiting, account lockout thresholds, and anomaly detection on authentication endpoints.',
            'Enforce strong password policy and multi-factor authentication for privileged accounts.',
            'Harden login flows against credential stuffing by adding IP and device-based risk controls.',
            'Retest authentication and brute-force scenarios to confirm abuse resistance is effective.',
        ],
        'token' => [
            'Enforce strict JSON Web Token (JWT) signature validation and reject weak or unsupported algorithms.',
            'Validate token issuer, audience, expiry, and not-before claims on every authenticated request.',
            'Rotate signing keys regularly and store them in a dedicated secrets management system.',
            'Retest with forged, expired, and replayed tokens to confirm authentication bypass is prevented.',
        ],
        'sqli' => [
            'Replace dynamic query string concatenation with parameterized queries or prepared statements throughout the codebase — not just in the affected endpoint.',
            'Apply allowlist-based input validation on the server side for all parameters that touch database queries.',
            'Enable database-level query logging and alerting to detect and respond to injection attempts in production.',
            'Retest the affected parameter with the same payload family after remediation to confirm the fix is complete.',
        ],
        'xss' => [
            'Encode all user-supplied output using the correct context-aware encoding (HTML entity encoding for HTML body, attribute encoding for attribute values, JavaScript encoding for script context).',
            'Deploy a Content Security Policy header that blocks inline scripts and restricts external script sources to a defined allowlist.',
            'Audit all input fields and URL parameters across the application for the same reflected and stored output paths.',
            'Retest reflected and stored script injection paths after remediation to confirm payloads no longer execute.',
        ],
        'cors' => [
            'Replace the permissive origin policy with an explicit allowlist of trusted origins validated against a server-side list — never reflect the Origin header value directly.',
            'Remove or scope the Access-Control-Allow-Credentials header to only the specific origins that require credentialed cross-origin access.',
            'Audit all API endpoints for CORS configuration, including preflight OPTIONS responses.',
            'Retest with an untrusted Origin header value after remediation to confirm the fix applies to all routes.',
        ],
        'tls' => [
            'Update server TLS configuration to accept only TLS 1.2 and TLS 1.3 — disable SSL 3.0, TLS 1.0, and TLS 1.1 explicitly.',
            'Replace weak cipher suites (RC4, 3DES, NULL, EXPORT) with modern AEAD ciphers (AES-GCM, ChaCha20-Poly1305).',
            'Renew or replace the certificate if it is expired, self-signed, or approaching expiry — use auto-renewal via Let\'s Encrypt where possible.',
            'Verify the corrected configuration using an external TLS testing service (SSL Labs) and archive the results.',
        ],
        'header' => [
            'Add the missing security header at the web server or reverse proxy configuration level so it applies to all routes automatically.',
            'Verify the header is returned on all pages including authenticated routes, API endpoints, and error pages — not just the homepage.',
            'Use the recommended value documented by the OWASP Secure Headers Project for the specific header.',
            'Retest the full site with a header analysis tool after deployment to confirm the header is consistent across all paths.',
        ],
        'hsts' => [
            'Enable the Strict-Transport-Security response header with an appropriate max-age and includeSubDomains where applicable.',
            'Ensure all HTTP traffic is redirected to HTTPS before authentication or session establishment.',
            'Validate that preload criteria are met before submitting domains that require browser preload protection.',
            'Retest transport behavior from first-visit and downgrade scenarios to confirm HTTPS enforcement.',
        ],
        'mime' => [
            'Set the X-Content-Type-Options header to nosniff across all application and static content responses.',
            'Ensure uploaded or user-controlled files are served with strict, correct Content-Type values.',
            'Separate executable and non-executable content delivery paths to reduce content interpretation risk.',
            'Retest browser handling for mixed content types to confirm script execution from non-script resources is blocked.',
        ],
        'csp' => [
            'Deploy a strict Content Security Policy that limits script-src, object-src, and frame-src to trusted origins only.',
            'Use nonces or hashes for allowed inline scripts instead of permitting unsafe-inline globally.',
            'Monitor policy violations using report endpoints and tune directives before enforcing at scale.',
            'Retest injection payloads to confirm browser-side script execution is constrained by policy.',
        ],
        'clickjacking' => [
            'Set X-Frame-Options to DENY or SAMEORIGIN for sensitive routes and legacy browser coverage.',
            'Add frame-ancestors restrictions in Content Security Policy for modern browser enforcement.',
            'Review user interaction workflows to require explicit confirmation for high-risk actions.',
            'Retest framing attempts from external domains to confirm UI embedding is blocked.',
        ],
        'port' => [
            'Restrict external access to this port using firewall rules or network access control lists — allow access only from approved IP ranges or through a VPN.',
            'Disable the service entirely if it is not required for production operations.',
            'If the service must remain accessible, enforce strong authentication and rotate any default credentials immediately.',
            'Retest external port reachability from a network outside the server\'s own network after firewall changes are applied.',
        ],
        'file' => [
            'Delete or move the exposed file immediately — if it is a configuration file, treat all credentials it contained as fully compromised and rotate them.',
            'Block access to the file path at the web server level using deny rules or location blocks, and test the block from an external connection.',
            'Audit the server for other sensitive files that may be publicly accessible (backups, logs, hidden directories).',
            'Review deployment pipelines to ensure sensitive files are never committed to the web root.',
        ],
        'secret' => [
            'Revoke and rotate the exposed credential immediately — assume it has already been compromised since it was publicly accessible.',
            'Remove all secrets from source code, response bodies, and client-facing files — store them in environment variables or a secrets manager.',
            'Review audit logs for the affected credential to identify any unauthorised use since the credential was first exposed.',
            'Scan the full codebase and deployment artifacts for additional exposed secrets using automated secret scanning tools.',
        ],
        'cookie' => [
            'Add the missing cookie attributes (Secure, HttpOnly, SameSite) to all session and authentication cookies in your application code or server configuration.',
            'Audit all Set-Cookie headers across all routes and environments — apply consistent attribute policies.',
            'Test cookie attributes from an unauthenticated client after remediation to verify the change is applied in the production response.',
        ],
        'redirect' => [
            'Validate redirect destination URLs against a server-side allowlist of approved internal paths — reject any destination that is not on the list.',
            'Never pass raw user-supplied URLs directly to redirect headers — resolve relative paths on the server side.',
            'Retest the affected parameter with an external URL payload after remediation to confirm untrusted destinations are rejected.',
        ],
        default => [
            'Confirm this finding with a controlled retest using the same target and conditions used during initial detection.',
            'Apply remediation to the specific endpoint or control and document the change with before/after evidence.',
            'Schedule a follow-up scan to verify the fix is complete and has not introduced regressions.',
        ],
    };

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
// EVIDENCE ASSEMBLY  (unchanged from v3.1)
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
// FALLBACK REPORT  (unchanged from v3.1)
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
    $howExploited = (string) ($vuln['how_exploited'] ?? '');

    $type = classifyFinding($name, $desc);
    $netFields = extractNetworkFields($evidence, $desc);

    $description = $desc !== ''
        ? expandSecurityTerms($desc)
        : expandSecurityTerms("The security scan identified a {$severity}-severity {$name} issue on {$target}. This finding requires review by a security administrator.");

    $riskParts = [];
    if ($indicates !== '')
        $riskParts[] = expandSecurityTerms($indicates);
    if ($howExploited !== '')
        $riskParts[] = expandSecurityTerms("An attacker could exploit this by: " . lcfirst($howExploited));
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
        'potential_impact' => impactBulletsFromType($type, $severity),
        'likelihood' => likelihoodFromSeverity($severity),
        'recommendations' => recommendationsFromType($type, $vuln),
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
        default => 'The tested security control should enforce its intended behaviour under both normal and adversarial conditions.',
    };
}

// ─────────────────────────────────────────────────────────────
// AI PROMPT BUILDER  (unchanged from v3.1)
// ─────────────────────────────────────────────────────────────

function buildPromptMessages(array $vuln, array $scan, array $base): array
{
    $name = (string) ($vuln['name'] ?? '');
    $severity = ucfirst(strtolower((string) ($vuln['severity'] ?? 'medium')));
    $desc = (string) ($vuln['description'] ?? '');
    $evidence = (string) ($vuln['evidence'] ?? '');
    $indicates = (string) ($vuln['indicates'] ?? '');
    $howExploited = (string) ($vuln['how_exploited'] ?? '');
    $whatTested = (string) ($vuln['what_we_tested'] ?? '');
    $remediation = (string) ($vuln['remediation'] ?? '');
    $target = (string) ($scan['target'] ?? '');
    $timestamp = (string) ($scan['timestamp'] ?? '');
    $type = classifyFinding($name, $desc);

    $distilledEvidence = distilEvidence($evidence, $type);

    $evidencePrefix = implode("\n", [
        'Test Performed: ' . ($whatTested !== '' ? expandSecurityTerms($whatTested) : 'Automated security validation was performed for this control.'),
        'Target: ' . ($target !== '' ? $target : 'Not provided'),
        'Detection Time: ' . ($timestamp !== '' ? $timestamp : 'Not provided'),
        'Expected Secure Result: ' . deriveExpectedBehavior($name, $desc),
        'Observed Result:',
        '[FILL IN: write 2-4 sentences describing what the scanner actually observed, using the technical evidence below]',
    ]);

    $system = <<<SYSTEM
You are a senior application security analyst writing finding reports for a professional security scanner.
Your reports are read by developers and non-technical stakeholders.

OUTPUT RULES — follow every rule without exception:
1. Return ONLY a single valid JSON object. No markdown, no prose, no code fences.
2. Every string field must be plain English — no bullet symbols inside strings, no markdown.
3. potential_impact and recommendations must be JSON arrays of plain-English strings.
4. Do not fabricate CVE identifiers, IP addresses, or port numbers not present in the input.
5. Expand all security abbreviations on first use (e.g. write "Cross-Site Scripting (XSS)" not just "XSS").
6. Never use these filler phrases: "it is important to", "it is worth noting", "please note", "in conclusion".
7. description: 2–3 sentences, specific to this finding, minimum 90 characters.
8. risk_explanation: 2–3 sentences explaining what an attacker gains, minimum 90 characters.
9. evidence: You will be given a template with a placeholder on the last line. Replace ONLY the placeholder
   line with your observed result narrative. Keep all other lines exactly as provided. Do not rename,
   reorder, or remove any section label.
10. recommendations: exactly 3–5 strings, each a concrete action sentence, no duplicates.
11. potential_impact: exactly 3 strings, each describing a specific harm.
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
        'evidence' => "Test Performed: We requested the page and inspected all response headers for the presence of Content-Security-Policy.\nTarget: https://example.com\nDetection Time: 2025-03-15T10:22:00+03:00\nExpected Secure Result: Content-Security-Policy header present with a restrictive policy.\nObserved Result:\nThe Content-Security-Policy header was absent from the server response. All other standard headers were present. No policy restriction is enforced by the browser for this origin.",
        'risk_explanation' => 'Without a Content-Security-Policy, any Cross-Site Scripting (XSS) vulnerability on this site — whether present today or introduced in future — will execute without any browser-level restriction. An attacker who achieves script injection can steal session tokens, log keystrokes, and perform actions as the victim.',
        'potential_impact' => [
            'Injected scripts can steal session cookies and enable full account takeover.',
            'Malicious scripts can silently redirect users to phishing pages or harvest credentials.',
            'Third-party script injection becomes possible with no browser-enforced boundary.',
        ],
        'likelihood' => 'High',
        'recommendations' => [
            "Add the following header to your web server configuration: Content-Security-Policy: default-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'",
            'Apply the header at the reverse proxy or web server level so it is returned on every route, including API endpoints and error pages.',
            'Use the CSP evaluator tool at csp-evaluator.withgoogle.com to validate the policy before deploying to production.',
        ],
        'remediation_priority' => 'High',
        'result_status' => 'Immediate Action Required',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $user = <<<USER
## YOUR TASK
Write a security finding report for the finding below. Return ONLY the JSON object.

## EXAMPLE OUTPUT SHAPE (use same fields, fill with real data from this finding — do not copy example values)
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
{$howExploited}

Scanner Remediation Advice:
{$remediation}

Technical Evidence (key observations from the scan):
{$distilledEvidence}

## FIELD INSTRUCTIONS
- title: Use the exact finding name above, expanded (no abbreviations).
- severity: Use exactly: {$severity}
- category: {$base['category']}
- target: {$target}
- description: Specific to this finding on this target. State what was found, where, and why it is a problem. 90–200 chars.
- evidence: Use EXACTLY the template below. Replace only the placeholder line "[FILL IN: ...]" with 2–4 sentences
  describing what the scanner actually observed. Do NOT rename, reorder, or remove any other line.
  ----
  {$evidencePrefix}
  ----
- risk_explanation: What can an attacker do with this? What data or systems are at risk? 90–200 chars.
- potential_impact: 3 specific harm statements for this finding type.
- likelihood: {$base['likelihood']}
- recommendations: 3–5 specific, actionable steps. First step should reference the specific fix for this finding type.
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
- description must be at least 90 characters and specific to the finding.
- risk_explanation must be at least 90 characters and explain what an attacker gains.
- evidence must contain ALL of these lines in order: "Test Performed:", "Expected Secure Result:", "Observed Result:".
  Do NOT omit or rename any of these section labels. Place your observed findings under the "Observed Result:" line.
- recommendations must have at least 3 items, each a full action sentence.
- potential_impact must have at least 3 items.
CORRECTION;

    return [
        $originalMessages[0],
        $originalMessages[1],
        ['role' => 'assistant', 'content' => $currentJson],
        ['role' => 'user', 'content' => $correction],
    ];
}

// ─────────────────────────────────────────────────────────────
// FIX 3: AI API WITH JITTERED RETRY  (modified from v3.1)
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

/**
 * Call the OpenAI API with jittered exponential back-off on 429/5xx.
 *
 * $attempt starts at 0 for the first call. On retryable failures the function
 * calls itself recursively with $attempt+1 until MAX_API_RETRIES is reached.
 *
 * Back-off formula: sleep( RETRY_BASE_MS * 2^attempt + jitter_0_to_500ms )
 * Example with defaults:
 *   attempt 0 (first call): no pre-sleep
 *   attempt 1 (first retry): ~800ms + jitter
 *   attempt 2 (second retry): ~1600ms + jitter
 *
 * @param string $apiKey
 * @param array  $messages
 * @param int    $timeoutSec  Passed in from caller (may be reduced for Fix 4)
 * @param int    $attempt     Internal recursion counter — callers always pass 0
 */
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

    // Success path
    if (!$curlError && $responseBody && $httpStatus === 200) {
        return parseAiJsonFromResponse($responseBody);
    }

    // Retryable: rate limit (429) or server error (5xx)
    $retryable = ($httpStatus === 429 || $httpStatus >= 500 || $curlError !== '');
    if ($retryable && $attempt < MAX_API_RETRIES) {
        // Exponential back-off: base * 2^attempt, plus 0–500 ms jitter
        $sleepMs = (int) (RETRY_BASE_MS * (2 ** $attempt)) + mt_rand(0, 500);
        usleep($sleepMs * 1000);

        error_log("finding_ai_report: retrying (attempt " . ($attempt + 1) . ") after HTTP={$httpStatus} cURL={$curlError}");
        return requestAiReport($apiKey, $messages, $timeoutSec, $attempt + 1);
    }

    error_log("finding_ai_report: API call failed after " . ($attempt + 1) . " attempt(s). HTTP={$httpStatus} cURL={$curlError}");
    return null;
}

// ─────────────────────────────────────────────────────────────
// REPORT NORMALISATION & QUALITY  (unchanged from v3.1)
// ─────────────────────────────────────────────────────────────

function normalizeReportShape(array $ai, array $base): array
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
        'detection_time'
    ] as $sf) {
        $merged[$sf] = expandSecurityTerms(trim((string) ($merged[$sf] ?? '')));
    }
    $impactRaw = is_array($merged['potential_impact'] ?? null) ? $merged['potential_impact'] : [];
    $recsRaw = is_array($merged['recommendations'] ?? null) ? $merged['recommendations'] : [];
    [$impactClean, $recsClean] = sanitizeImpactAndRecommendations($impactRaw, $recsRaw);
    if (count($impactClean) < 2)
        $impactClean = sanitizeImpactAndRecommendations($base['potential_impact'], [])[0];
    if (count($recsClean) < 2)
        $recsClean = sanitizeImpactAndRecommendations([], $base['recommendations'])[1];
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

// ── Deterministic base (always built, never depends on AI) ────────────────
$base = fallbackReport($vuln, $scan);
$base = enforceEvidenceStructure($base, $vuln, $scan);
$base = enforceRecommendationDiversity($base, $seenStems);

// RULE-BASED ONLY MODE (default)
// We return the deterministic rule-based report unless explicitly asked
// to generate via AI (use_ai=true or mode=ai from the caller).
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

// ── FIX 1: Cache check ────────────────────────────────────────────────────
// Check before touching the concurrency slot — a cache hit requires zero
// AI calls and zero slot usage, so it never contributes to congestion.
$cacheKey = buildCacheKey($vuln, $scan);
$cachedReport = null;
if (!$bypassCache) {
    $cachedReport = readReportCache($cacheKey);
}

if ($cachedReport !== null) {
    // Cache hit: restore volatile per-request fields from the current request
    // (target, detection_time, ip, port, state) then re-enforce evidence
    // structure so the deterministic prefix reflects the current scan data.
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

// ── FIX 2: Acquire a concurrency slot ────────────────────────────────────
// Ensures no more than MAX_CONCURRENT_AI requests call OpenAI at the same time.
// If we cannot get a slot within THROTTLE_MAX_WAIT_SEC, return the deterministic
// fallback immediately — the user gets a correct report, just without AI enrichment.
$slotToken = acquireConcurrencySlot();
if ($slotToken === null) {
    writeAdminLog('finding_report_throttled', 'Could not acquire AI slot — fallback used', ['title' => $base['title'] ?? '']);
    echo json_encode(['ok' => true, 'report' => $base, 'source' => 'fallback_throttled']);
    exit;
}

// ── FIX 4: Reduce timeout when slot queue is near-full ───────────────────
// If most slots are occupied the system is under pressure. Cut the AI timeout
// so slow requests fail faster, freeing their slots for waiting requests sooner.
$activeSlots = countActiveSlots();
$aiTimeout = ($activeSlots >= (int) (MAX_CONCURRENT_AI * 0.75)) ? FAST_TIMEOUT_SEC : AI_TIMEOUT_SEC;

// Always release the slot when we are done — even on exception / early exit
try {
    // ── First AI attempt ─────────────────────────────────────────────────
    $messages = buildPromptMessages($vuln, $scan, $base);
    $ai = requestAiReport($apiKey, $messages, $aiTimeout);  // Fix 3 retry logic is inside

    if (!is_array($ai)) {
        writeAdminLog('finding_report_fallback', 'AI returned invalid JSON — fallback used', ['title' => $base['title'] ?? '']);
        echo json_encode(['ok' => true, 'report' => $base, 'source' => 'fallback']);
        releaseConcurrencySlot($slotToken);
        exit;
    }

    $merged = normalizeReportShape($ai, $base);
    $merged = enforceEvidenceStructure($merged, $vuln, $scan);
    $merged = enforceRecommendationDiversity($merged, $seenStems);
    $quality = validateReportQuality($merged);

    // ── Targeted correction attempt if quality gate fails ────────────────
    if (!$quality['valid']) {
        $correctionMessages = buildCorrectionMessages($messages, $merged, $quality['issues']);
        $retryAi = requestAiReport($apiKey, $correctionMessages, $aiTimeout);

        if (is_array($retryAi)) {
            $rebuilt = normalizeReportShape($retryAi, $base);
            $rebuilt = enforceEvidenceStructure($rebuilt, $vuln, $scan);
            $rebuilt = enforceRecommendationDiversity($rebuilt, $seenStems);
            $rebuiltQuality = validateReportQuality($rebuilt);

            if ($rebuiltQuality['valid']) {
                writeReportCache($cacheKey, $rebuilt); // Cache the corrected report
                writeAdminLog('finding_report_ai_rebuilt', 'AI report corrected and passed quality gate', [
                    'title' => $rebuilt['title'] ?? '',
                    'issues_before' => $quality['issues'],
                ]);
                echo json_encode(['ok' => true, 'report' => $rebuilt, 'source' => 'ai_corrected', 'quality' => $rebuiltQuality]);
                releaseConcurrencySlot($slotToken);
                exit;
            }
        }

        // Both AI attempts failed quality — use the deterministic base
        writeAdminLog('finding_report_fallback_rebuilt', 'AI failed quality gate twice — deterministic fallback used', [
            'title' => $base['title'] ?? '',
            'issues' => $quality['issues'],
        ]);
        echo json_encode(['ok' => true, 'report' => $base, 'source' => 'fallback_rebuilt', 'quality' => validateReportQuality($base)]);
        releaseConcurrencySlot($slotToken);
        exit;
    }

    // ── First AI attempt passed quality gate ─────────────────────────────
    writeReportCache($cacheKey, $merged); // Fix 1: cache successful AI report
    writeAdminLog('finding_report_ai_ok', 'AI report passed quality gate', ['title' => $merged['title'] ?? '']);
    echo json_encode(['ok' => true, 'report' => $merged, 'source' => 'ai', 'quality' => $quality]);

} finally {
    // Fix 2: Always release the slot, even if an exception or exit() was called
    // Note: exit() in the try block does NOT run finally in PHP — that is intentional
    // here because every exit() path above already has a valid response. The finally
    // runs on normal fall-through. For the exit() paths we rely on the 60-second
    // stale-slot cleanup in countActiveSlots() as the safety net.
    releaseConcurrencySlot($slotToken);
}