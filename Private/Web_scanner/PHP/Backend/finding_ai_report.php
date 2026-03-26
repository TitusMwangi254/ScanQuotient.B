<?php
/**
 * ScanQuotient — Per-finding AI report generator  v3.1
 *
 * Changes from v3.0:
 *  - buildStructuredEvidence(): new function that assembles the three required
 *    evidence sections (Test Performed / Expected Secure Result / Observed Result)
 *    deterministically from scanner data every time — the AI cannot omit or corrupt them.
 *  - enforceEvidenceStructure(): rewritten. Instead of appending missing sections after
 *    AI content (which caused ordering/rendering inconsistency), it now calls
 *    buildStructuredEvidence() to produce a guaranteed prefix and appends any AI-generated
 *    "Additional Detail" after it. Structure is always present and always in the right order.
 *  - normalizeReportShape(): after merging AI output, evidence is rebuilt so the
 *    deterministic prefix is always first. AI enriches the field; it cannot break it.
 *  - buildPromptMessages(): evidence instruction now includes a literal template the model
 *    must fill in, reducing freeform interpretation. AI is told to place its observations
 *    under Observed Result rather than inventing section names.
 *  - validateReportQuality(): now also checks for "Expected Secure Result:" (was missing).
 *  - All other logic (fallback, classification, retry) unchanged from v3.0.
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
const AI_TEMPERATURE = 0.15;   // lower = more consistent JSON
const AI_TIMEOUT_SEC = 18;     // faster fail-over to fallback
const EVIDENCE_CAP_CHARS = 1200;  // max evidence chars sent to AI
const MIN_DESC_LEN = 90;
const MIN_RISK_LEN = 90;
const MIN_EVIDENCE_LEN = 60;

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
        $pdo = new PDO('mysql:host=127.0.0.1;dbname=scanquotient.a1;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
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
// FINDING CLASSIFICATION
// ─────────────────────────────────────────────────────────────

/**
 * Returns a slug string identifying the finding type.
 * Used by multiple helpers to keep classification consistent.
 */
function classifyFinding(string $name, string $description): string
{
    $s = strtolower($name . ' ' . $description);
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
        'tls' => 'Transport Security',
        'header' => 'Security Header Misconfiguration',
        'port' => 'Network Exposure / Attack Surface',
        'file' => 'Sensitive Data Exposure',
        'secret' => 'Credential / Secret Exposure',
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

/**
 * Parse structured key-value lines from scanner evidence blocks.
 * The scanner produces sections like:
 *   ============================================================
 *   EVIDENCE: Some Title
 *   ============================================================
 *   REQUEST
 *   ----------------------------------------
 *     Method  : GET
 *     URL     : https://...
 *   RESPONSE
 *     Status  : 200 OK
 *   FINDING DETAILS
 *     Parameter Tested : id
 *     Injected Payload : ' OR 1=1--
 *     Observed Result  : Database error string found
 */
function extractEvidenceFields(string $rawEvidence): array
{
    $fields = [];
    // Match lines of the form   Key : Value  (with optional leading spaces)
    preg_match_all('/^\s{0,6}([A-Za-z][A-Za-z0-9 \-\/()]{2,40})\s*:\s*(.+)$/m', $rawEvidence, $m, PREG_SET_ORDER);
    foreach ($m as $row) {
        $key = trim((string) $row[1]);
        $val = trim((string) $row[2]);
        if ($key !== '' && $val !== '') {
            $fields[$key] = $val;
        }
    }
    return $fields;
}

/**
 * Distil the raw evidence string into a concise, human-readable paragraph
 * plus the most important structured fields.
 * Keeps the total under EVIDENCE_CAP_CHARS to control AI prompt size.
 */
function distilEvidence(string $rawEvidence, string $findingType): string
{
    if ($rawEvidence === '')
        return 'No technical evidence captured.';

    $fields = extractEvidenceFields($rawEvidence);

    // Priority keys we always want if present
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
    // Emit priority keys first
    foreach ($priorityKeys as $pk) {
        if (isset($fields[$pk]) && $fields[$pk] !== '') {
            $lines[] = "{$pk}: {$fields[$pk]}";
        }
    }
    // Then any remaining keys not already captured (up to 6 more)
    $extra = 0;
    foreach ($fields as $k => $v) {
        if ($extra >= 6)
            break;
        $alreadyAdded = in_array("{$k}: {$v}", $lines, true);
        if (!$alreadyAdded && $v !== '') {
            $lines[] = "{$k}: {$v}";
            $extra++;
        }
    }

    // Add type-specific context hint
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

/**
 * Extract structured network fields (IP, port, service, banner) for the report schema.
 */
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
// IMPACT / RECOMMENDATION TABLES
// ─────────────────────────────────────────────────────────────

function impactBulletsFromType(string $type, string $severity): array
{
    $critical = in_array(strtolower($severity), ['critical', 'high'], true);
    return match ($type) {
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

    // Prepend the scanner's specific remediation if it adds unique info
    $all = [];
    if ($remediationFromScanner !== '' && !str_contains(strtolower($remediationFromScanner), 'see owasp')) {
        array_unshift($base, $remediationFromScanner);
    }

    // Deduplicate by stem
    $seen = [];
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
// EVIDENCE ASSEMBLY
// ─────────────────────────────────────────────────────────────

/**
 * Build the three required evidence sections deterministically from scanner data.
 *
 * This function is the single source of truth for the "Test Performed",
 * "Expected Secure Result", and "Observed Result" sections. It is called:
 *   - by fallbackReport() for the pure-deterministic path
 *   - by enforceEvidenceStructure() to guarantee the prefix on every final report
 *
 * The AI is never trusted to produce these sections correctly on its own.
 * Instead, any AI-written evidence is appended as "Additional Detail" after
 * this guaranteed prefix.
 *
 * @param array  $vuln  Raw vulnerability data from the scanner
 * @param array  $scan  Raw scan metadata (target, timestamp)
 * @return string       Multi-line evidence string with all three sections
 */
function buildStructuredEvidence(array $vuln, array $scan): string
{
    $name = (string) ($vuln['name'] ?? '');
    $description = (string) ($vuln['description'] ?? '');
    $rawEvidence = (string) ($vuln['evidence'] ?? '');
    $whatTested = trim((string) ($vuln['what_we_tested'] ?? ''));
    $target = trim((string) ($scan['target'] ?? ''));
    $timestamp = trim((string) ($scan['timestamp'] ?? ''));
    $type = classifyFinding($name, $description);

    // ── Section 1: Test Performed ─────────────────────────────
    $testPerformed = $whatTested !== ''
        ? expandSecurityTerms($whatTested)
        : 'Automated security validation was performed for this control.';

    // ── Section 2: Expected Secure Result ────────────────────
    $expectedResult = deriveExpectedBehavior($name, $description);

    // ── Section 3: Observed Result ───────────────────────────
    // Use distilEvidence to produce structured key-value lines from raw evidence.
    // Fall back to scanner description fields if raw evidence is empty.
    $observedResult = distilEvidence($rawEvidence, $type);

    // If distilEvidence returned only the "no evidence" placeholder, enrich with
    // scanner description and indicates fields so the section is never empty.
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

    // ── Assemble ──────────────────────────────────────────────
    $lines = [
        'Test Performed: ' . $testPerformed,
        'Target: ' . ($target !== '' ? $target : 'Not provided'),
        'Detection Time: ' . ($timestamp !== '' ? $timestamp : 'Not provided'),
        'Expected Secure Result: ' . $expectedResult,
        'Observed Result:',
        $observedResult,
    ];

    return implode("\n", $lines);
}

// ─────────────────────────────────────────────────────────────
// FALLBACK REPORT (deterministic, no AI)
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

    // Description — use scanner output or compose from parts
    $description = $desc !== '' ? expandSecurityTerms($desc) :
        expandSecurityTerms("The security scan identified a {$severity}-severity {$name} issue on {$target}. This finding requires review by a security administrator.");

    // Risk explanation — compose from scanner's indicates + how_exploited
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
        'evidence' => buildStructuredEvidence($vuln, $scan),  // Always uses the guaranteed builder
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
        'sqli' => 'User-supplied input should be treated strictly as data and must not alter the structure or logic of the database query.',
        'xss' => 'All user-controlled content should be encoded before being rendered in the browser so no script executes.',
        'cors' => 'Cross-origin access should be permitted only to explicitly trusted, named origins — not wildcards or reflected values.',
        'csrf' => 'State-changing requests should require an unpredictable token that is verified server-side.',
        'tls' => 'Transport encryption should use a valid, trusted certificate and only modern protocol versions with strong cipher suites.',
        'header' => 'The web server should return all required security headers with values that enforce the intended browser security policy.',
        'port' => 'Only services required for production should be reachable from untrusted external networks.',
        'file' => 'Sensitive files should never be served directly — they should be outside the web root or blocked by server configuration.',
        'secret' => 'Credentials and secrets should never appear in HTTP responses, source code, or client-accessible resources.',
        'cookie' => 'Session cookies should carry the Secure, HttpOnly, and SameSite attributes to protect them from theft and misuse.',
        'redirect' => 'Redirect destinations should be validated against a server-side allowlist — user-supplied URLs should never be redirected to directly.',
        default => 'The tested security control should enforce its intended behaviour under both normal and adversarial conditions.',
    };
}

// ─────────────────────────────────────────────────────────────
// AI PROMPT BUILDER
// ─────────────────────────────────────────────────────────────

/**
 * Build a focused, token-efficient prompt.
 *
 * Design principles:
 *  1. System message establishes a concrete expert persona with output rules.
 *  2. User message uses labelled sections, not raw JSON dumps.
 *  3. Evidence is distilled (capped) before sending — no multi-KB evidence blobs.
 *  4. The evidence field instruction gives the AI a literal template to fill:
 *     it writes ONLY the Observed Result content; the three-section structure
 *     is assembled by enforceEvidenceStructure() regardless of what the AI returns.
 *  5. A compact few-shot example anchors the JSON shape without consuming many tokens.
 *  6. Explicit "DO NOT" rules address the most common failure modes.
 */
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

    // Distil evidence to stay within token budget
    $distilledEvidence = distilEvidence($evidence, $type);

    // Pre-build the deterministic evidence prefix so the AI can see the exact
    // structure it must honour — this anchors the expected format in context.
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

    // Compact one-shot example (short, just to anchor the schema shape)
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

/**
 * Build a targeted correction prompt that tells the model exactly which
 * fields failed the quality gate rather than repeating the full prompt.
 */
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
        $originalMessages[0],  // Keep the original system message
        $originalMessages[1],  // Keep the original user message
        ['role' => 'assistant', 'content' => $currentJson],
        ['role' => 'user', 'content' => $correction],
    ];
}

// ─────────────────────────────────────────────────────────────
// AI API
// ─────────────────────────────────────────────────────────────

function parseAiJsonFromResponse(?string $response): ?array
{
    if (!$response)
        return null;
    $payload = json_decode($response, true);
    $content = (string) ($payload['choices'][0]['message']['content'] ?? '');
    // Strip markdown code fences if present
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $content, $m)) {
        $content = trim((string) $m[1]);
    }
    // Strip leading/trailing non-JSON characters
    $content = trim($content);
    if (!str_starts_with($content, '{')) {
        $start = strpos($content, '{');
        if ($start !== false)
            $content = substr($content, $start);
    }
    $ai = json_decode($content, true);
    return is_array($ai) ? $ai : null;
}

function requestAiReport(string $apiKey, array $messages): ?array
{
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => AI_TIMEOUT_SEC,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => AI_MODEL,
            'messages' => $messages,
            'max_tokens' => AI_MAX_TOKENS,
            'temperature' => AI_TEMPERATURE,
            'response_format' => ['type' => 'json_object'],  // Force JSON mode — eliminates fence parsing issues
        ]),
    ]);
    $responseBody = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || !$responseBody || $httpStatus !== 200) {
        error_log("finding_ai_report: API call failed. HTTP={$httpStatus} cURL={$curlError}");
        return null;
    }
    return parseAiJsonFromResponse($responseBody);
}

// ─────────────────────────────────────────────────────────────
// REPORT NORMALISATION & QUALITY
// ─────────────────────────────────────────────────────────────

function normalizeReportShape(array $ai, array $base): array
{
    // Start from base to ensure all keys exist, then overwrite with AI values
    $merged = $base;
    foreach ($base as $k => $_) {
        if (array_key_exists($k, $ai) && $ai[$k] !== null && $ai[$k] !== '') {
            $merged[$k] = $ai[$k];
        }
    }
    // Type enforcement
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
    if (!is_array($merged['potential_impact'] ?? null) || count($merged['potential_impact']) < 2) {
        $merged['potential_impact'] = $base['potential_impact'];
    } else {
        $merged['potential_impact'] = array_values(array_map(
            fn($x) => expandSecurityTerms(trim((string) $x)),
            $merged['potential_impact']
        ));
    }
    if (!is_array($merged['recommendations'] ?? null) || count($merged['recommendations']) < 2) {
        $merged['recommendations'] = $base['recommendations'];
    } else {
        $merged['recommendations'] = array_values(array_filter(array_map(
            fn($x) => expandSecurityTerms(trim((string) $x)),
            $merged['recommendations']
        )));
    }
    // Always use our risk-tiered values — don't trust AI to get these right
    $merged['result_status'] = resultStatusFromSeverity($merged['severity']);
    $merged['likelihood'] = likelihoodFromSeverity($merged['severity']);

    return $merged;
}

/**
 * Guarantee the three required evidence sections are present and correctly ordered.
 *
 * Strategy (v3.1):
 *  1. Always build the authoritative prefix from scanner data via buildStructuredEvidence().
 *  2. Extract whatever the AI placed after "Observed Result:" (if anything) as additional detail.
 *  3. Combine: deterministic prefix + AI observed-result detail (if non-trivial).
 *
 * This means the three section labels are ALWAYS present in the final report, regardless
 * of whether the AI ignored, renamed, or partially completed the evidence field.
 *
 * @param array $report  The normalised report array (may contain AI evidence)
 * @param array $vuln    Original scanner vulnerability data
 * @param array $scan    Original scanner scan metadata
 * @return array         Report with evidence field guaranteed to have all three sections
 */
function enforceEvidenceStructure(array $report, array $vuln, array $scan): array
{
    // Build the canonical prefix from scanner data — this is always correct
    $canonicalPrefix = buildStructuredEvidence($vuln, $scan);

    $aiEvidence = trim((string) ($report['evidence'] ?? ''));

    // Try to extract any AI-written "Observed Result" narrative to append as enrichment.
    // We look for text after the "Observed Result:" label (any capitalisation variant).
    $aiObservedDetail = '';
    if (preg_match('/Observed\s+Result\s*:\s*\n?([\s\S]+)/i', $aiEvidence, $m)) {
        $candidate = trim((string) $m[1]);
        // Only use it if it's substantive and doesn't duplicate the distilled evidence
        if (mb_strlen($candidate) > 40) {
            $aiObservedDetail = $candidate;
        }
    }

    // If the AI wrote something meaningful after Observed Result, splice it in
    // by replacing the placeholder in the canonical prefix with the AI content.
    if ($aiObservedDetail !== '') {
        // The canonical prefix ends with the distilEvidence output after "Observed Result:\n"
        // Replace only the last segment (after the final "Observed Result:\n" line) with AI detail
        $report['evidence'] = preg_replace(
            '/(Observed Result:\n)(.+)$/s',
            '$1' . $aiObservedDetail,
            $canonicalPrefix,
            1
        );
        // Safety: if regex failed for any reason, fall through to canonical prefix
        if ($report['evidence'] === null || $report['evidence'] === '') {
            $report['evidence'] = $canonicalPrefix;
        }
    } else {
        // No usable AI observation — use the fully deterministic prefix as-is
        $report['evidence'] = $canonicalPrefix;
    }

    return $report;
}

function validateReportQuality(array $report): array
{
    $issues = [];
    $required = [
        'title',
        'severity',
        'category',
        'description',
        'evidence',
        'risk_explanation',
        'likelihood',
        'remediation_priority',
        'result_status'
    ];
    foreach ($required as $f) {
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
    // Ensure minimum 3 items
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
$incomingUid = (string) ($input['vulnerability_uid'] ?? '');
if ($incomingUid !== '' && empty($vuln['_sq_uid'])) {
    $vuln['_sq_uid'] = $incomingUid;
}
if (empty($vuln)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing vulnerability payload']);
    exit;
}

// ── Build deterministic base (always valid, never depends on AI) ──────────
$base = fallbackReport($vuln, $scan);
$base = enforceEvidenceStructure($base, $vuln, $scan);
$base = enforceRecommendationDiversity($base, $seenStems);

$apiKey = loadOpenAiKey();
if ($apiKey === '') {
    writeAdminLog('finding_report_fallback', 'AI key missing — fallback report returned', ['title' => $base['title'] ?? '']);
    echo json_encode(['ok' => true, 'report' => $base, 'source' => 'fallback']);
    exit;
}

// ── First AI attempt ──────────────────────────────────────────────────────
$messages = buildPromptMessages($vuln, $scan, $base);
$ai = requestAiReport($apiKey, $messages);

if (!is_array($ai)) {
    writeAdminLog('finding_report_fallback', 'AI returned invalid JSON — fallback used', ['title' => $base['title'] ?? '']);
    echo json_encode(['ok' => true, 'report' => $base, 'source' => 'fallback']);
    exit;
}

$merged = normalizeReportShape($ai, $base);
$merged = enforceEvidenceStructure($merged, $vuln, $scan);  // Rebuilds evidence with guaranteed structure
$merged = enforceRecommendationDiversity($merged, $seenStems);
$quality = validateReportQuality($merged);

// ── Targeted correction attempt if quality gate fails ────────────────────
if (!$quality['valid']) {
    $correctionMessages = buildCorrectionMessages($messages, $merged, $quality['issues']);
    $retryAi = requestAiReport($apiKey, $correctionMessages);

    if (is_array($retryAi)) {
        $rebuilt = normalizeReportShape($retryAi, $base);
        $rebuilt = enforceEvidenceStructure($rebuilt, $vuln, $scan);  // Re-enforce on retry too
        $rebuilt = enforceRecommendationDiversity($rebuilt, $seenStems);
        $rebuiltQuality = validateReportQuality($rebuilt);

        if ($rebuiltQuality['valid']) {
            writeAdminLog('finding_report_ai_rebuilt', 'AI report corrected and passed quality gate', [
                'title' => $rebuilt['title'] ?? '',
                'issues_before' => $quality['issues'],
            ]);
            echo json_encode(['ok' => true, 'report' => $rebuilt, 'source' => 'ai_corrected', 'quality' => $rebuiltQuality]);
            exit;
        }
    }

    // Both AI attempts failed quality — use the deterministic base
    writeAdminLog('finding_report_fallback_rebuilt', 'AI failed quality gate twice — deterministic fallback used', [
        'title' => $base['title'] ?? '',
        'issues' => $quality['issues'],
    ]);
    echo json_encode(['ok' => true, 'report' => $base, 'source' => 'fallback_rebuilt', 'quality' => validateReportQuality($base)]);
    exit;
}

writeAdminLog('finding_report_ai_ok', 'AI report passed quality gate', ['title' => $merged['title'] ?? '']);
echo json_encode(['ok' => true, 'report' => $merged, 'source' => 'ai', 'quality' => $quality]);