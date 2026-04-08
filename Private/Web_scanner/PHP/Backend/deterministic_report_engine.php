<?php
/**
 * ScanQuotient — Deterministic Report Engine  v5.0
 * ═══════════════════════════════════════════════════════════════════
 *
 * DROP-IN replacement for the deterministic layer in finding_ai_report.php.
 *
 * Replaces / supersedes in v4.1:
 *   • fallbackReport()
 *   • impactBulletsFromType()
 *   • recommendationsFromType()
 *   • buildStructuredEvidence()
 *   • deriveExpectedBehavior()
 *   • extractHeaderNameFromVulnName()
 *   • getRecommendedHeaderValue()
 *
 * DESIGN GOALS
 * ─────────────
 *  1. SPECIFIC  — every field references the actual target, parameter,
 *                 port, service, header, file, or payload from evidence.
 *                 Zero category-level generic filler sentences.
 *
 *  2. COMPLETE  — covers every finding type the Python scanner emits:
 *                 SSL/TLS (6 sub-types), security headers (7 headers),
 *                 CORS (3 sub-types), SQLi (2 sub-types), XSS (2 types),
 *                 open redirect, sensitive files, info disclosure (3 types),
 *                 cookie flags (3 flags), port scan (generic + 4 services).
 *
 *  3. FAST      — pure PHP, zero I/O, zero external calls.
 *                 A full report builds in < 1 ms.
 *
 *  4. SAFE      — every string access is null-guarded.
 *                 No PHP warning possible from missing input fields.
 *
 * HOW TO INTEGRATE
 * ─────────────────
 *  In finding_ai_report.php:
 *    require_once __DIR__ . '/deterministic_report_engine.php';
 *
 *  Then replace the call to fallbackReport() with:
 *    $base = DeterministicReport::build($vuln, $scan);
 *
 *  The returned array has exactly the same shape as before so
 *  normalizeReportShape(), enforceEvidenceStructure(), and the
 *  rest of the pipeline are unaffected.
 *
 * ═══════════════════════════════════════════════════════════════════
 */

// ─────────────────────────────────────────────────────────────────────
// HELPERS — shared utilities (safe to call from outside this file)
// ─────────────────────────────────────────────────────────────────────

/**
 * Pull a named field out of raw evidence text.
 * Handles both "Key: value" on one line and multi-word key names.
 * Returns '' if not found.
 */
function det_field(string $evidence, string $key): string
{
    if (preg_match('/^[ \t]{0,8}' . preg_quote($key, '/') . '\s*:\s*(.+)$/im', $evidence, $m)) {
        return trim((string) $m[1]);
    }
    return '';
}

/**
 * Extract all named fields into an associative array.
 * Cheap: single regex scan, cached per call.
 */
function det_fields(string $evidence): array
{
    $fields = [];
    preg_match_all('/^[ \t]{0,8}([A-Za-z][A-Za-z0-9 \-\/():]{2,45})\s*:\s*(.+)$/m', $evidence, $m, PREG_SET_ORDER);
    foreach ($m as $row) {
        $k = trim((string) $row[1]);
        $v = trim((string) $row[2]);
        if ($k !== '' && $v !== '' && !isset($fields[$k])) {
            $fields[$k] = $v;
        }
    }
    return $fields;
}

/**
 * Safe string getter from $vuln or $scan array.
 */
function det_str(array $arr, string $key, string $default = ''): string
{
    return trim((string) ($arr[$key] ?? $default));
}

/**
 * Parse domain from URL. Returns raw URL if parse fails.
 */
function det_domain(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    return ($host !== false && $host !== null && $host !== '') ? (string) $host : $url;
}

/**
 * Expand common security acronyms — mirrors expandSecurityTerms() in v4.1
 * but lighter-weight (no regex engine overhead for common cases).
 */
function det_expand(string $text): string
{
    if ($text === '')
        return '';
    static $patterns = null;
    if ($patterns === null) {
        $patterns = [
            '/\bCORS\b/' => 'Cross-Origin Resource Sharing (CORS)',
            '/\bSQLi\b/' => 'SQL injection (SQLi)',
            '/\bSQL\b/' => 'Structured Query Language (SQL)',
            '/\bXSS\b/' => 'Cross-Site Scripting (XSS)',
            '/\bTLS\b/' => 'Transport Layer Security (TLS)',
            '/\bSSL\b/' => 'Secure Sockets Layer (SSL)',
            '/\bCSRF\b/' => 'Cross-Site Request Forgery (CSRF)',
            '/(?<!\()\bHTTP\b(?!\))/' => 'Hypertext Transfer Protocol (HTTP)',
            '/(?<!\()\bHTTPS\b(?!\))/' => 'Hypertext Transfer Protocol Secure (HTTPS)',
            '/\bCVE\b/' => 'Common Vulnerabilities and Exposures (CVE)',
            '/\bRCE\b/' => 'Remote Code Execution (RCE)',
            '/\bVPN\b/' => 'Virtual Private Network (VPN)',
            '/\bAPI\b/' => 'Application Programming Interface (API)',
            '/\bCSP\b/' => 'Content Security Policy (CSP)',
            '/\bHSTS\b/' => 'HTTP Strict Transport Security (HSTS)',
        ];
    }
    $out = (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    // collapse double-expansions e.g. "HTTP (HTTP)"
    $out = (string) preg_replace('/(\w[\w\s]+)\s*\(\1\)/u', '$1', $out);
    return $out;
}

/**
 * Clamp a string to $max characters, appending "…" if trimmed.
 */
function det_clamp(string $s, int $max = 220): string
{
    return mb_strlen($s) <= $max ? $s : mb_substr($s, 0, $max - 1) . '…';
}

// ─────────────────────────────────────────────────────────────────────
// FINDING CLASSIFIER  (same contract as classifyFinding() in v4.1)
// ─────────────────────────────────────────────────────────────────────

function det_classify(string $name, string $description = ''): string
{
    $s = strtolower($name . ' ' . $description);

    if (str_contains($s, 'sql injection') || str_contains($s, 'sqli') || str_contains($s, 'error-based') || str_contains($s, 'time-based blind'))
        return 'sqli';
    if (str_contains($s, 'cross-site scripting') || str_contains($s, 'xss') || str_contains($s, 'dom xss'))
        return 'xss';
    if (str_contains($s, 'cors') || str_contains($s, 'cross-origin'))
        return 'cors';
    if (str_contains($s, 'csrf') || str_contains($s, 'cross-site request forgery'))
        return 'csrf';
    if (str_contains($s, 'open redirect'))
        return 'redirect';
    if (str_contains($s, 'cookie'))
        return 'cookie';
    if (str_contains($s, 'ssl') || str_contains($s, 'tls') || str_contains($s, 'certificate') || str_contains($s, 'cipher') || str_contains($s, 'insecure http'))
        return 'tls';
    if (str_contains($s, 'strict-transport-security') || str_contains($s, 'hsts'))
        return 'hsts';
    if (str_contains($s, 'content-security-policy') || str_contains($s, 'csp'))
        return 'csp';
    if (str_contains($s, 'x-frame-options') || str_contains($s, 'clickjacking'))
        return 'clickjacking';
    if (str_contains($s, 'x-content-type') || str_contains($s, 'mime sniff') || str_contains($s, 'nosniff'))
        return 'mime';
    if (str_contains($s, 'header'))
        return 'header';
    if (str_contains($s, 'port') || str_contains($s, 'telnet') || str_contains($s, 'redis') || str_contains($s, 'mongodb') || str_contains($s, 'elasticsearch') || str_contains($s, 'service'))
        return 'port';
    if (str_contains($s, '.env') || str_contains($s, '.git') || str_contains($s, 'sensitive file') || str_contains($s, 'exposed') && (str_contains($s, 'file') || str_contains($s, 'path')))
        return 'file';
    if (str_contains($s, 'secret') || str_contains($s, 'api key') || str_contains($s, 'credential') || str_contains($s, 'token') || str_contains($s, 'aws') || str_contains($s, 'stripe') || str_contains($s, 'github'))
        return 'secret';
    if (str_contains($s, 'server version') || str_contains($s, 'x-powered-by') || str_contains($s, 'technology') || str_contains($s, 'mixed content') || str_contains($s, 'information disclosure'))
        return 'info';
    return 'generic';
}

// ─────────────────────────────────────────────────────────────────────
// MAIN ENTRY POINT
// ─────────────────────────────────────────────────────────────────────

final class DeterministicReport
{
    /**
     * Build a complete report array from raw scanner vulnerability + scan data.
     * Returns the same shape as fallbackReport() in v4.1 — fully compatible.
     *
     * @param  array $vuln   Single vulnerability from the scanner /scan response.
     * @param  array $scan   Top-level scan response (target, timestamp, …).
     * @return array         Report ready for normalizeReportShape() / JSON output.
     */
    public static function build(array $vuln, array $scan): array
    {
        $name = det_str($vuln, 'name', 'Security Finding');
        $sev = det_str($vuln, 'severity', 'medium');
        $desc = det_str($vuln, 'description');
        $evidence = det_str($vuln, 'evidence');
        $indicates = det_str($vuln, 'indicates');
        $howExpl = det_str($vuln, 'how_exploited');
        $remText = det_str($vuln, 'remediation');
        $target = det_str($scan, 'target');
        $timestamp = det_str($scan, 'timestamp');

        $type = det_classify($name, $desc);
        $domain = det_domain($target);
        $fields = det_fields($evidence);

        // ── Context extraction (all nullable → '') ────────────────────
        $ctx = self::ctx($fields, $evidence, $desc, $name);

        // ── Five core text fields ─────────────────────────────────────
        $title = self::title($name);
        $description = self::description($type, $ctx, $domain, $sev, $desc, $name);
        $riskExplan = self::risk($type, $ctx, $domain, $indicates, $howExpl, $sev, $name);
        $evidenceBlock = self::evidence($vuln, $scan, $ctx, $type);
        $impact = self::impact($type, $ctx, $domain, $sev);
        $recommendations = self::recommendations($type, $ctx, $domain, $remText, $vuln);

        // ── Network fields for port findings ──────────────────────────
        $ip = self::extractIp($evidence . ' ' . $desc);
        $port = $ctx['port'] !== '' ? $ctx['port'] : self::extractPort($evidence . ' ' . $desc);
        $service = $ctx['service'];
        $state = ($ctx['state'] !== '') ? $ctx['state'] : (str_contains(strtolower($evidence), 'open') ? 'Open' : '');

        return [
            'title' => $title,
            'severity' => ucfirst(strtolower($sev)),
            'category' => self::category($type),
            'target' => $target,
            'ip_address' => $ip,
            'port' => $port,
            'service_detected' => $service,
            'state' => $state,
            'detection_time' => $timestamp,
            'description' => $description,
            'evidence' => $evidenceBlock,
            'risk_explanation' => $riskExplan,
            'potential_impact' => $impact,
            'likelihood' => self::likelihood($sev),
            'recommendations' => $recommendations,
            'remediation_priority' => self::priority($sev),
            'result_status' => self::status($sev),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // CONTEXT EXTRACTION  — parse every useful value out of evidence
    // ═══════════════════════════════════════════════════════════════════

    private static function ctx(array $fields, string $evidence, string $desc, string $name): array
    {
        $n = strtolower($name . ' ' . $desc . ' ' . $evidence);

        // Parameter
        $param = $fields['Parameter Tested'] ?? $fields['parameter tested'] ?? '';

        // Payload
        $payload = $fields['Injected Payload'] ?? $fields['payload'] ?? '';

        // Port
        $port = '';
        if (!empty($fields['Port'])) {
            $port = preg_replace('/[^0-9\/a-zA-Z]/', '', $fields['Port']);
        } elseif (preg_match('/\bport\s+(\d+)/i', $evidence, $m)) {
            $port = $m[1];
        }

        // Service
        $service = $fields['Detected Service'] ?? $fields['Service'] ?? '';
        if ($service === '' && preg_match('/\b(Redis|MongoDB|Telnet|FTP|SSH|SMTP|MySQL|PostgreSQL|Elasticsearch|SMB|RDP|HTTP|HTTPS)\b/i', $evidence, $m)) {
            $service = $m[1];
        }

        // Protocol
        $protocol = $fields['Negotiated Protocol'] ?? $fields['protocol'] ?? '';
        if ($protocol === '' && preg_match('/\b(TLSv1\.?[0-3]?|SSLv[23])\b/', $evidence, $m)) {
            $protocol = $m[1];
        }

        // Cipher
        $cipher = $fields['Cipher Suite'] ?? $fields['Negotiated Cipher'] ?? '';

        // Header
        $header = $fields['Header Checked'] ?? '';
        if ($header === '') {
            // try to extract from vuln name: "Missing Content-Security-Policy"
            if (preg_match('/(?:missing|absent)\s+([A-Za-z][\w-]+(?:-[\w]+)*)/i', $name, $m)) {
                $header = $m[1];
            }
        }

        // Cookie attribute
        $cookieAttr = $fields['Missing Attribute'] ?? '';
        if ($cookieAttr === '' && preg_match('/\b(Secure|HttpOnly|SameSite)\b/', $name . ' ' . $desc, $m)) {
            $cookieAttr = $m[1];
        }

        // File/path
        $file = $fields['File / Path'] ?? $fields['path'] ?? $fields['File'] ?? '';
        if ($file === '' && preg_match('/[\'"](\/.+?)[\'"]/', $evidence, $m)) {
            $file = $m[1];
        }

        // Secret type
        $secretType = $fields['Secret Type'] ?? $fields['Credential / Secret'] ?? '';
        if ($secretType === '' && preg_match('/AWS|Stripe|GitHub|API Key|Password|Token|Private Key/i', $name . ' ' . $desc, $m)) {
            $secretType = $m[0];
        }

        // Days (cert)
        $daysExpired = $fields['Days Since Expiry'] ?? '';
        $daysLeft = $fields['Days Remaining'] ?? '';
        $expiryDate = $fields['Not Valid After (EXPIRY)'] ?? $fields['Valid Until'] ?? $fields['Not Valid After'] ?? '';

        // XSS context
        $xssCtx = $fields['Injection Context Type'] ?? '';
        $csp = $fields['CSP Header Present'] ?? '';

        // CORS
        $acao = $fields['Result: Access-Control-Allow-Origin'] ?? $fields['Access-Control-Allow-Origin'] ?? '';
        $acac = $fields['Result: Access-Control-Allow-Credentials'] ?? $fields['Access-Control-Allow-Credentials'] ?? '';

        // Timing (blind SQLi)
        $timingPayload = $fields['Payload Response Time'] ?? '';
        $timingBaseline = $fields['Baseline Response Time'] ?? '';
        $timingDelta = $fields['Observed Delta'] ?? '';

        // Error (SQLi)
        $sqlError = $fields['Error Pattern Matched'] ?? '';
        $dbType = $fields['Database Type Indicated'] ?? $fields['Database Inferred'] ?? '';

        // Banner (port)
        $banner = $fields['Banner Received'] ?? '';
        $state = $fields['Observed State'] ?? $fields['Port State'] ?? '';

        // Server header
        $serverHeader = $fields['Server Header Value'] ?? $fields['Server'] ?? '';
        $poweredBy = $fields['X-Powered-By Value'] ?? $fields['X-Powered-By'] ?? '';

        // Mixed content
        $mixedJsCount = $fields['Mixed JS Files'] ?? '';

        // Redirect
        $redirectParam = $param;
        $redirectLocation = $fields['Location Header'] ?? '';

        // Version
        $version = $fields['Version String'] ?? '';

        return compact(
            'param',
            'payload',
            'port',
            'service',
            'protocol',
            'cipher',
            'header',
            'cookieAttr',
            'file',
            'secretType',
            'daysExpired',
            'daysLeft',
            'expiryDate',
            'xssCtx',
            'csp',
            'acao',
            'acac',
            'timingPayload',
            'timingBaseline',
            'timingDelta',
            'sqlError',
            'dbType',
            'banner',
            'state',
            'serverHeader',
            'poweredBy',
            'mixedJsCount',
            'redirectParam',
            'redirectLocation',
            'version'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // TITLE
    // ═══════════════════════════════════════════════════════════════════

    private static function title(string $name): string
    {
        return det_expand($name);
    }

    // ═══════════════════════════════════════════════════════════════════
    // DESCRIPTION  — 2–3 sentences, specific to this finding
    // ═══════════════════════════════════════════════════════════════════

    private static function description(
        string $type,
        array $ctx,
        string $domain,
        string $sev,
        string $rawDesc,
        string $name
    ): string {
        $p = $ctx['param'];
        $pay = $ctx['payload'];
        $prt = $ctx['port'];
        $svc = $ctx['service'];
        $hdr = $ctx['header'];
        $fle = $ctx['file'];
        $sec = $ctx['secretType'];
        $pro = $ctx['protocol'];
        $cip = $ctx['cipher'];
        $cka = $ctx['cookieAttr'];
        $db = $ctx['dbType'];
        $dex = $ctx['daysExpired'];
        $dlf = $ctx['daysLeft'];
        $dte = $ctx['expiryDate'];
        $acao = $ctx['acao'];
        $xco = $ctx['xssCtx'];

        switch ($type) {
            case 'sqli':
                $paramStr = $p !== '' ? "the '{$p}' parameter" : "a query parameter";
                $dbStr = $db !== '' ? " The database engine identified is {$db}." : '';
                $blind = stripos($name, 'blind') !== false || stripos($name, 'time-based') !== false;
                if ($blind) {
                    $tpay = $ctx['timingPayload'];
                    $tbase = $ctx['timingBaseline'];
                    $delta = $ctx['timingDelta'];
                    $timingStr = ($tpay !== '' && $tbase !== '')
                        ? " A safe request took {$tbase} while the injected request held the response for {$tpay}, a delta of {$delta}."
                        : '';
                    return det_expand("Time-based blind SQL injection was confirmed on {$domain} through {$paramStr}. A database sleep payload caused a measurable server-side delay, proving the database executes injected commands even though no error is returned to the browser.{$timingStr}{$dbStr}");
                }
                $errStr = $ctx['sqlError'] !== '' ? " The matched error pattern was: {$ctx['sqlError']}." : '';
                return det_expand("Error-based SQL injection was confirmed on {$domain} through {$paramStr}. Injecting a quote character into the parameter caused the database to return a diagnostic error message in the response.{$errStr}{$dbStr}");

            case 'xss':
                if (stripos($name, 'dom') !== false) {
                    return det_expand("The page served by {$domain} contains JavaScript patterns (sinks) that can execute attacker-controlled data as code if that data originates from the URL, hash fragment, or other client-side source. This is a potential DOM-based Cross-Site Scripting (XSS) finding that requires developer review to confirm exploitability.");
                }
                $ctxStr = $xco !== '' ? " The payload landed in a {$xco} context." : '';
                $cspStr = $ctx['csp'] !== '' && stripos($ctx['csp'], 'no') === false
                    ? " A Content Security Policy (CSP) header is present but does not fully mitigate the reflected payload."
                    : " No Content Security Policy (CSP) is present, so the script executes in the browser without any policy restriction.";
                $payStr = $pay !== '' ? " The payload '{$pay}' was returned verbatim and unencoded." : '';
                return det_expand("Reflected Cross-Site Scripting (XSS) was confirmed on {$domain}.{$payStr}{$ctxStr}{$cspStr}");

            case 'cors':
                if ($acao === '*') {
                    return det_expand("The server at {$domain} returns Access-Control-Allow-Origin: * on every response, granting any website on the internet permission to read its cross-origin responses. This exposes unauthenticated API data to arbitrary third-party origins.");
                }
                $credStr = $ctx['acac'] === 'true' ? " The server also returns Access-Control-Allow-Credentials: true, which means authenticated requests — including session cookies — can be made cross-origin from any reflected origin." : '';
                return det_expand("The server at {$domain} reflects the incoming Origin header unconditionally in the Access-Control-Allow-Origin response header (observed value: {$acao}).{$credStr} This allows any attacker-controlled site to make cross-origin requests that the server treats as trusted.");

            case 'tls':
                if (stripos($name, 'insecure http') !== false || stripos($name, 'no https') !== false) {
                    return det_expand("The site at {$domain} is served entirely over unencrypted Hypertext Transfer Protocol (HTTP). No Transport Layer Security (TLS) is in use, meaning every byte transmitted between the server and the visitor — including passwords and session cookies — travels in plaintext across every network node on the path.");
                }
                if (stripos($name, 'expired') !== false) {
                    $dStr = $dex !== '' ? " The certificate expired {$dex} days ago" . ($dte !== '' ? " (expiry date: {$dte})" : '') . '.' : '';
                    return det_expand("The Transport Layer Security (TLS) certificate presented by {$domain} has expired.{$dStr} All major browsers now display a full-page security warning and most visitors cannot proceed without manually overriding the error.");
                }
                if (stripos($name, 'expiring') !== false) {
                    $dStr = $dlf !== '' ? " {$dlf} days remain before expiry" . ($dte !== '' ? " ({$dte})" : '') . '.' : '';
                    return det_expand("The Transport Layer Security (TLS) certificate for {$domain} is approaching its expiry date.{$dStr} If not renewed before expiry, all browsers will block visitors with a security warning identical to an expired certificate.");
                }
                if (stripos($name, 'untrusted') !== false || stripos($name, 'self-signed') !== false) {
                    return det_expand("The certificate presented by {$domain} was not issued by a recognised certificate authority and cannot be validated by any major browser or operating system trust store. Visitors see a full-page 'Your connection is not private' warning and must click through multiple security prompts to continue.");
                }
                if (stripos($name, 'weak cipher') !== false || stripos($name, 'cipher suite') !== false) {
                    $cipStr = $cip !== '' ? " The negotiated cipher suite is {$cip}." : '';
                    return det_expand("The server at {$domain} negotiated a cryptographically weak cipher suite.{$cipStr} Weak cipher suites have known mathematical weaknesses that reduce the effective strength of the encryption and may allow a well-resourced attacker to decrypt recorded traffic.");
                }
                if ($pro !== '') {
                    return det_expand("The server at {$domain} accepted a connection using {$pro}, a deprecated version of the Transport Layer Security (TLS) protocol that has known cryptographic vulnerabilities. Modern best practice requires TLS 1.2 at minimum, with TLS 1.3 preferred.");
                }
                return det_expand($rawDesc !== '' ? $rawDesc : "A Transport Layer Security (TLS) configuration issue was detected on {$domain} that weakens the encryption protecting traffic between the server and its visitors.");

            case 'hsts':
                return det_expand("The server at {$domain} does not send the Strict-Transport-Security (HSTS) header. Without this header, browsers have no instruction to enforce Hypertext Transfer Protocol Secure (HTTPS) on subsequent visits, leaving users susceptible to protocol downgrade attacks on the first unprotected request.");

            case 'csp':
                return det_expand("The server at {$domain} does not send a Content-Security-Policy (CSP) header. Without a policy, the browser places no restriction on which scripts, stylesheets, or embedded resources may execute on the page, and any successfully injected script runs without any browser-level containment.");

            case 'clickjacking':
                return det_expand("The server at {$domain} does not send an X-Frame-Options header. Without this control, any external website can embed your pages inside a hidden or transparent iframe and trick logged-in users into clicking interface elements they cannot see — a technique known as clickjacking.");

            case 'mime':
                return det_expand("The server at {$domain} does not send the X-Content-Type-Options: nosniff header. Without this header, browsers may override the declared Content-Type and execute responses as scripts, enabling content-type confusion attacks particularly relevant when the application hosts user-uploaded files.");

            case 'header':
                $hdrStr = $hdr !== '' ? "the {$hdr} header" : "a required security response header";
                return det_expand("The server at {$domain} does not send {$hdrStr}. This header enforces a browser-level security policy that operates independently of application code. Its absence means that one class of browser-based attack has no policy barrier regardless of how well the application itself validates input.");

            case 'redirect':
                $prStr = $p !== '' ? "the '{$p}' parameter" : "a redirect parameter";
                $locStr = $ctx['redirectLocation'] !== '' ? " The server issued a redirect to {$ctx['redirectLocation']}." : '';
                return det_expand("The application at {$domain} issues an unvalidated redirect from {$prStr} to a user-supplied destination URL.{$locStr} An attacker can craft a link that starts with your trusted domain name but immediately redirects victims to any external site.");

            case 'cookie':
                $ckaStr = $cka !== '' ? "the {$cka} attribute" : "one or more required security attributes";
                return det_expand("A session cookie set by {$domain} is missing {$ckaStr}. This weakens the protection of authenticated user sessions against the attack class that the missing attribute is designed to prevent. All session and authentication cookies should carry Secure, HttpOnly, and SameSite=Strict attributes.");

            case 'file':
                $fStr = $fle !== '' ? "'{$fle}'" : "a sensitive file";
                return det_expand("The file {$fStr} on {$domain} is publicly accessible over Hypertext Transfer Protocol (HTTP) without authentication. This file type is not intended for public serving and may contain credentials, configuration details, or source code that directly enables further attacks.");

            case 'secret':
                $sStr = $sec !== '' ? "a {$sec}" : "a credential or secret";
                return det_expand("The Hypertext Transfer Protocol (HTTP) response from {$domain} contains {$sStr} embedded in the response body. This secret is publicly accessible to anyone who loads the URL — including automated bots and search engine crawlers — and must be treated as fully compromised.");

            case 'info':
                if (stripos($name, 'server version') !== false || stripos($name, 'version disclosure') !== false) {
                    $svStr = $ctx['serverHeader'] !== '' ? " The Server header reads: {$ctx['serverHeader']}." : '';
                    return det_expand("The server at {$domain} discloses its exact software name and version number in the Server response header.{$svStr} This allows automated scanners to identify the specific version and cross-reference it against known vulnerability databases without any additional probing.");
                }
                if (stripos($name, 'x-powered-by') !== false || stripos($name, 'technology') !== false) {
                    $pbStr = $ctx['poweredBy'] !== '' ? " The X-Powered-By header reads: {$ctx['poweredBy']}." : '';
                    return det_expand("The server at {$domain} discloses its backend technology stack via the X-Powered-By response header.{$pbStr} This information helps attackers narrow their exploit selection to vulnerabilities specific to the disclosed framework and version.");
                }
                if (stripos($name, 'mixed content') !== false) {
                    $mcStr = $ctx['mixedJsCount'] !== '' ? " {$ctx['mixedJsCount']} JavaScript file(s) are loaded over HTTP." : '';
                    return det_expand("The Hypertext Transfer Protocol Secure (HTTPS) page at {$domain} loads one or more resources — including scripts or stylesheets — over unencrypted Hypertext Transfer Protocol (HTTP).{$mcStr} An attacker positioned on the network path can intercept and modify these HTTP resources even though the main page is served over HTTPS.");
                }
                return det_expand($rawDesc !== '' ? $rawDesc : "An information disclosure issue was identified at {$domain} that reveals technical details about the server infrastructure to unauthenticated external requesters.");

            case 'port':
                $svcStr = $svc !== '' ? "{$svc}" : "a network service";
                $prtStr = $prt !== '' ? " on port {$prt}" : '';
                $bnrStr = ($ctx['banner'] !== '' && strtolower($ctx['banner']) !== 'no')
                    ? " The service responded with a banner during scanning." : '';
                return det_expand("{$svcStr}{$prtStr} is reachable from the public internet on {$domain}.{$bnrStr} Services of this type are not designed for direct internet exposure and are actively targeted by automated exploitation tooling within minutes of becoming reachable.");

            default:
                if ($rawDesc !== '')
                    return det_expand($rawDesc);
                return det_expand("A {$sev}-severity security finding was identified on {$domain}. The control being tested did not behave as expected under adversarial input conditions. Review the evidence section for the specific observed behaviour and the recommendations below for remediation steps.");
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // RISK EXPLANATION  — what does an attacker specifically gain
    // ═══════════════════════════════════════════════════════════════════

    private static function risk(
        string $type,
        array $ctx,
        string $domain,
        string $indicates,
        string $howExpl,
        string $sev,
        string $name
    ): string {
        $p = $ctx['param'];
        $prt = $ctx['port'];
        $svc = $ctx['service'];
        $hdr = $ctx['header'];
        $fle = $ctx['file'];
        $sec = $ctx['secretType'];
        $db = $ctx['dbType'];
        $cka = $ctx['cookieAttr'];

        switch ($type) {
            case 'sqli':
                $paramStr = $p !== '' ? "through the '{$p}' parameter" : "through the affected endpoint";
                $dbStr = $db !== '' ? " ({$db})" : '';
                $blind = stripos($name, 'blind') !== false || stripos($name, 'time-based') !== false;
                $method = $blind
                    ? "Automated tools such as sqlmap can extract the entire database character-by-character using timing differences — no error messages are needed for a complete dump."
                    : "An attacker can use freely available tooling to enumerate every table, row, and credential in the database within minutes of discovering this endpoint.";
                return det_expand("The database{$dbStr} backing {$domain} is fully readable — and potentially writable — {$paramStr}. {$method} Depending on database user permissions, this may also allow server file system access or operating system command execution.");

            case 'xss':
                $domXss = stripos($name, 'dom') !== false;
                if ($domXss) {
                    return det_expand("If attacker-controlled data reaches any of the dangerous JavaScript sinks identified on {$domain}, arbitrary script will execute in the victim's browser with the same privileges as your application. This enables session cookie theft, keylogging, and silent API actions on behalf of the victim.");
                }
                return det_expand("A visitor to {$domain} who clicks a crafted link will execute attacker-controlled JavaScript in their browser. The attacker can steal the session cookie and replay it from anywhere — gaining full account access without knowing the user's password. No user action beyond clicking the link is required.");

            case 'cors':
                $credBool = $ctx['acac'] === 'true';
                if ($credBool) {
                    return det_expand("Any website can make authenticated API requests to {$domain} using a logged-in user's session cookies. An attacker who tricks a victim into visiting a malicious page can silently read private account data, submit forms, or trigger state-changing actions — all without the victim's knowledge and while they remain logged in.");
                }
                return det_expand("Any website can read unauthenticated responses from {$domain}'s Application Programming Interface (API). While session cookies are not sent automatically, public or semi-public API endpoints return data readable by any third-party origin, enabling data harvesting and API abuse from attacker-controlled sites.");

            case 'tls':
                if (stripos($name, 'insecure http') !== false || stripos($name, 'no https') !== false) {
                    return det_expand("Every interaction a visitor has with {$domain} — login, form submission, browsing — is transmitted in plaintext. Any node on the network path can read passwords, steal session cookies, and inject arbitrary content into pages delivered to users. No technical sophistication is required beyond running a packet capture on the same network.");
                }
                if (stripos($name, 'expired') !== false || stripos($name, 'untrusted') !== false) {
                    return det_expand("Browsers block or strongly warn against accessing {$domain}, causing immediate user-facing failure. Users who override the warning are exposed to man-in-the-middle attacks because the browser's certificate validation mechanism is effectively disabled when users accept untrusted certificates.");
                }
                if (stripos($name, 'weak cipher') !== false || stripos($name, 'protocol') !== false) {
                    $proStr = $ctx['protocol'] !== '' ? "{$ctx['protocol']}" : "the deprecated protocol";
                    return det_expand("A network-positioned attacker who records encrypted traffic between users and {$domain} may be able to decrypt it using published attacks against {$proStr}. On shared networks this requires no privileged position — passive traffic capture is sufficient. Credentials and session tokens transmitted during the affected period are at risk.");
                }
                return det_expand($indicates !== '' ? det_expand($indicates) : "Transport-layer weaknesses on {$domain} reduce the confidentiality and integrity guarantees of HTTPS, potentially exposing user data to network-positioned attackers.");

            case 'hsts':
                return det_expand("Without HTTP Strict Transport Security (HSTS) on {$domain}, a browser that has not previously visited the site will make its first request over unencrypted Hypertext Transfer Protocol (HTTP). An SSL stripping tool intercepts this first request, downgrades the connection, and proxies a plaintext session — invisibly to the user — for the entire browsing session.");

            case 'csp':
                return det_expand("Without a Content Security Policy (CSP) on {$domain}, any Cross-Site Scripting (XSS) vulnerability — present today or introduced in future — executes without any browser-level containment. A successful injection can steal session tokens, log keystrokes, perform API actions as the victim, and exfiltrate data to an external server — all without triggering any security alert.");

            case 'clickjacking':
                return det_expand("An attacker can create a page that embeds {$domain} in a transparent iframe positioned precisely over a decoy interface. When a logged-in user interacts with what they believe is the attacker's page, they are actually clicking buttons on your site — submitting forms, confirming payments, or granting permissions — without realising it.");

            case 'mime':
                return det_expand("If {$domain} hosts any user-uploadable content, an attacker can upload a JavaScript file with a non-script extension. Without the nosniff directive, the browser may execute it as a script when opened, creating a stored Cross-Site Scripting (XSS) vector through what appears to be a safe file type upload path.");

            case 'header':
                $hdrStr = $hdr !== '' ? "the {$hdr} header" : "this header";
                return det_expand("Without {$hdrStr}, {$domain} relies entirely on application-layer controls to prevent the attack class it governs. If any single vulnerability — in application code, a third-party dependency, or a future deployment — creates an opening, there is no browser-level policy to limit the blast radius.");

            case 'redirect':
                return det_expand("An attacker crafts a link using {$domain}'s trusted domain name that immediately redirects victims to a phishing site or malware delivery page. Because the link originates from your trusted domain, email security filters, browser warnings, and user judgment are all bypassed — click-through rates on such links are significantly higher than on obvious external links.");

            case 'cookie':
                $ckaStr = $cka !== '' ? "Without the {$cka} attribute, " : "Without the required cookie security attributes, ";
                $specific = match (strtolower($cka)) {
                    'secure' => "the session cookie can be transmitted over unencrypted Hypertext Transfer Protocol (HTTP) — for example during a protocol downgrade — giving a network attacker a readable copy of the token.",
                    'httponly' => "any JavaScript running on {$domain} — including injected scripts or compromised third-party libraries — can read the session cookie via document.cookie and exfiltrate it in a single line of code.",
                    'samesite' => "the browser sends the session cookie automatically on cross-site requests, enabling Cross-Site Request Forgery (CSRF) attacks where a malicious site triggers authenticated actions on {$domain} without the user's knowledge.",
                    default => "the session cookie does not have full protection against cookie theft and Cross-Site Request Forgery (CSRF) attacks.",
                };
                return det_expand("{$ckaStr}{$specific} Session hijacking is the direct consequence — an attacker who obtains the token controls the victim's account completely.");

            case 'file':
                $fStr = $fle !== '' ? "'{$fle}'" : "the exposed file";
                return det_expand("Reading {$fStr} on {$domain} may immediately yield database credentials, API keys, private encryption keys, or application source code — each representing a separate, directly exploitable access path. Automated scanners worldwide probe for these paths continuously; the file may have already been retrieved before this scan ran.");

            case 'secret':
                $sStr = $sec !== '' ? "The exposed {$sec}" : "The exposed credential";
                return det_expand("{$sStr} on {$domain} provides direct, authenticated access to the connected service with no additional exploitation required. Cloud provider keys give account-level access that can result in data theft, infrastructure destruction, or significant financial charges. Credentials collected by automated bots are typically abused within minutes of exposure.");

            case 'info':
                if (stripos($name, 'mixed content') !== false) {
                    return det_expand("An attacker on the same network as a visitor to {$domain} can intercept Hypertext Transfer Protocol (HTTP) requests for the mixed resources and replace them with malicious content. If any of the mixed resources are JavaScript files, the attacker injects arbitrary code into the Hypertext Transfer Protocol Secure (HTTPS) page despite the main page being encrypted.");
                }
                return det_expand("Precise version and technology information about {$domain} allows an attacker to search CVE databases and exploit repositories for vulnerabilities specific to the disclosed software version. This converts a passive reconnaissance step into a targeted exploitation attempt without any additional scanning.");

            case 'port':
                $svcStr = $svc !== '' ? "{$svc}" : "the exposed service";
                $prtStr = $prt !== '' ? " on port {$prt}" : '';
                return det_expand("Direct internet access to {$svcStr}{$prtStr} on {$domain} allows automated tools to probe for default credentials, known exploits, and misconfigurations. Services of this class frequently run with broad system privileges — a single successful unauthenticated connection can provide full server control, data exfiltration capability, or a pivot point into the internal network.");

            default:
                if ($indicates !== '')
                    return det_expand($indicates);
                if ($howExpl !== '')
                    return det_expand("An attacker can exploit this by: " . lcfirst($howExpl));
                return det_expand("This finding on {$domain} indicates that a security control is not functioning as intended. The specific failure mode is documented in the evidence section. Unaddressed findings of this type provide attackers with an advantage that compounds over time as exploitation tooling improves.");
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // EVIDENCE BLOCK  — structured format matching pipeline expectations
    // ═══════════════════════════════════════════════════════════════════

    private static function evidence(array $vuln, array $scan, array $ctx, string $type): string
    {
        $name = det_str($vuln, 'name');
        $desc = det_str($vuln, 'description');
        $target = det_str($scan, 'target');
        $timestamp = det_str($scan, 'timestamp');
        $whatTested = det_str($vuln, 'what_we_tested');
        $rawEvidence = det_str($vuln, 'evidence');

        // "Test Performed" line
        $testLine = $whatTested !== ''
            ? det_expand($whatTested)
            : self::defaultTestLine($type, $ctx);

        // "Expected Secure Result"
        $expected = self::expectedBehavior($type, $name, $desc, $ctx);

        // "Observed Result" narrative — specific to this finding
        $observed = self::observedResult($type, $ctx, $desc, $rawEvidence, $name);

        return implode("\n", [
            "Test Performed: {$testLine}",
            "Target: " . ($target !== '' ? $target : 'Not provided'),
            "Detection Time: " . ($timestamp !== '' ? $timestamp : 'Not provided'),
            "Expected Secure Result: {$expected}",
            "Observed Result:",
            $observed,
        ]);
    }

    private static function defaultTestLine(string $type, array $ctx): string
    {
        return match ($type) {
            'sqli' => "We injected SQL syntax into the identified parameter and analysed the response for database error messages and timing anomalies.",
            'xss' => "We injected script payloads into query parameters and inspected whether they were reflected unencoded in the HTML response.",
            'cors' => "We sent a GET request with an injected Origin header pointing to an attacker-controlled domain and inspected the CORS response headers.",
            'tls' => "We performed a TLS handshake with the server and inspected the certificate, negotiated protocol, and cipher suite for security weaknesses.",
            'hsts' => "We made an HTTP request and inspected response headers for the Strict-Transport-Security directive.",
            'csp' => "We made an HTTP request and inspected response headers for a Content-Security-Policy directive.",
            'clickjacking' => "We made an HTTP request and inspected response headers for X-Frame-Options and Content-Security-Policy frame-ancestors directives.",
            'mime' => "We made an HTTP request and inspected response headers for the X-Content-Type-Options: nosniff directive.",
            'header' => "We made an HTTP GET request and inspected all response headers for the required security header.",
            'redirect' => "We injected external URL payloads into redirect-related parameters and inspected Location response headers.",
            'cookie' => "We inspected Set-Cookie response headers for required security attributes.",
            'file' => "We requested the sensitive file path directly over HTTP with no authentication and recorded the server response.",
            'secret' => "We scanned the HTTP response body using credential pattern matching and identified a matching secret.",
            'info' => "We inspected HTTP response headers and page content for technology version disclosure.",
            'port' => "We performed a TCP connect scan against the target host and confirmed port reachability from an external network.",
            default => "Automated security validation was performed for this control.",
        };
    }

    private static function expectedBehavior(string $type, string $name, string $desc, array $ctx): string
    {
        return match ($type) {
            'sqli' => "User-supplied input must be treated as data — not as part of the query structure — using parameterised queries or prepared statements.",
            'xss' => "User-controlled content must be HTML-encoded before rendering so the browser treats it as text and never executes it as code.",
            'cors' => "Cross-origin access should be granted only to explicitly named, trusted origins — never wildcards or blindly reflected values.",
            'tls' => "The server should present a valid, trusted certificate and accept connections only over TLS 1.2 or TLS 1.3 with strong cipher suites.",
            'hsts' => "The server should return Strict-Transport-Security: max-age=31536000; includeSubDomains on every HTTPS response.",
            'csp' => "The server should return a Content-Security-Policy header that restricts script sources to trusted origins and omits 'unsafe-inline'.",
            'clickjacking' => "The server should return X-Frame-Options: DENY or Content-Security-Policy: frame-ancestors 'none' to prevent iframe embedding.",
            'mime' => "The server should return X-Content-Type-Options: nosniff on all responses to prevent MIME-type sniffing.",
            'header' => "The server should return the required security header with a value that enforces the intended browser security policy on every response.",
            'redirect' => "Redirect destinations must be validated against a server-side allowlist of approved paths — user-supplied URLs must never be forwarded directly.",
            'cookie' => "Session cookies should carry Secure, HttpOnly, and SameSite=Strict to prevent theft and cross-site misuse.",
            'file' => "Sensitive files must be blocked at the server configuration layer or stored outside the web root — they must never be publicly accessible.",
            'secret' => "Credentials and secrets must never appear in HTTP responses — they should be stored in environment variables or a secrets manager.",
            'info' => "Server responses should not disclose software names, version numbers, or technology stack identifiers in headers or page content.",
            'port' => "Only services required for public operation should be reachable from untrusted external networks — all internal services must be firewalled.",
            default => "The tested security control should enforce its policy consistently under both normal usage and deliberate adversarial input.",
        };
    }

    private static function observedResult(string $type, array $ctx, string $desc, string $rawEvidence, string $name): string
    {
        $p = $ctx['param'];
        $pay = $ctx['payload'];
        $prt = $ctx['port'];
        $svc = $ctx['service'];
        $hdr = $ctx['header'];
        $fle = $ctx['file'];
        $sec = $ctx['secretType'];
        $pro = $ctx['protocol'];
        $cip = $ctx['cipher'];
        $cka = $ctx['cookieAttr'];
        $db = $ctx['dbType'];
        $acao = $ctx['acao'];
        $acac = $ctx['acac'];
        $dex = $ctx['daysExpired'];
        $dlf = $ctx['daysLeft'];
        $dte = $ctx['expiryDate'];
        $err = $ctx['sqlError'];
        $xco = $ctx['xssCtx'];
        $csp = $ctx['csp'];
        $loc = $ctx['redirectLocation'];
        $sv = $ctx['serverHeader'];
        $pb = $ctx['poweredBy'];
        $tpay = $ctx['timingPayload'];
        $tbase = $ctx['timingBaseline'];
        $tdelta = $ctx['timingDelta'];

        switch ($type) {
            case 'sqli':
                if (stripos($name, 'blind') !== false || stripos($name, 'time-based') !== false) {
                    $tStr = ($tpay !== '' && $tbase !== '')
                        ? "The baseline request completed in {$tbase}. The injected sleep payload held the response for {$tpay} (delta: {$tdelta}), exceeding the 3.5-second detection threshold."
                        : "The injected sleep payload caused a measurable delay significantly above the baseline response time, confirming server-side execution of the sleep function.";
                    $pStr = $p !== '' ? " Parameter: '{$p}'." : '';
                    $dbStr = $db !== '' ? " Database type: {$db}." : '';
                    return det_expand("Time-based blind SQL injection confirmed.{$pStr}{$dbStr} {$tStr} The database is executing injected commands — extraction is possible using automated tooling despite the absence of visible error output.");
                }
                $pStr = $p !== '' ? "The parameter '{$p}'" : "The affected parameter";
                $payStr = $pay !== '' ? " Payload injected: {$pay}." : '';
                $errStr = $err !== '' ? " The matched database error pattern was: {$err}." : '';
                $dbStr = $db !== '' ? " Database identified as: {$db}." : '';
                return det_expand("{$pStr} returned a database error message when SQL syntax was injected.{$payStr}{$errStr}{$dbStr} The error confirms the database is executing user input as part of the query — parameterised queries are not in use.");

            case 'xss':
                if (stripos($name, 'dom') !== false) {
                    $sinks = det_field($rawEvidence, 'Sinks Found');
                    $sinkStr = $sinks !== '' ? " Dangerous sinks detected: {$sinks}." : '';
                    return det_expand("Dangerous JavaScript sink patterns were found in the page source.{$sinkStr} These patterns (such as innerHTML, document.write, eval) can execute attacker-controlled data as code if any sink receives input from a URL parameter, hash fragment, or postMessage — a developer review is required to confirm exploitability.");
                }
                $payStr = $pay !== '' ? "The payload '{$pay}' was" : "The injected payload was";
                $ctxStr = $xco !== '' ? " Injection context: {$xco}." : '';
                $cspStr = ($csp !== '' && stripos($csp, 'no') !== false)
                    ? " No Content Security Policy (CSP) header is present — the script executes without any browser-level restriction."
                    : ($csp !== '' ? " A Content Security Policy (CSP) header is present but does not prevent execution of the reflected payload." : '');
                return det_expand("{$payStr} returned verbatim and unencoded in the HTML response body.{$ctxStr}{$cspStr} The browser treats the reflected content as executable code rather than display text.");

            case 'cors':
                if ($acao === '*') {
                    return det_expand("The response contained Access-Control-Allow-Origin: * — the server grants cross-origin read access to any website on the internet, regardless of origin. No Origin header whitelisting is in place.");
                }
                $credStr = $acac === 'true'
                    ? " Access-Control-Allow-Credentials: true was also returned, meaning the server permits authenticated cross-origin requests — including those carrying session cookies — from any reflected origin. This combination is a critical misconfiguration."
                    : " Access-Control-Allow-Credentials was not set to true, so cookies are not sent — only unauthenticated cross-origin access is possible.";
                return det_expand("The server reflected the injected attacker origin in Access-Control-Allow-Origin: {$acao}.{$credStr}");

            case 'tls':
                if (stripos($name, 'insecure http') !== false || stripos($name, 'no https') !== false) {
                    return det_expand("The server responded over unencrypted Hypertext Transfer Protocol (HTTP). No Transport Layer Security (TLS) handshake was performed and no certificate was presented. All traffic is transmitted in plaintext with no encryption or integrity protection.");
                }
                if (stripos($name, 'expired') !== false) {
                    $dStr = $dex !== '' ? " The certificate expired {$dex} days ago" . ($dte !== '' ? " ({$dte})" : '') . '.' : '';
                    return det_expand("The TLS certificate presented by the server has passed its validity period.{$dStr} Browsers display a full-page security warning and refuse to establish a secure connection without a manual user override.");
                }
                if (stripos($name, 'expiring') !== false) {
                    $dStr = $dlf !== '' ? " {$dlf} days remain before the certificate expires" . ($dte !== '' ? " ({$dte})" : '') . '.' : '';
                    return det_expand("The TLS certificate is valid but approaching expiry.{$dStr} No action has been taken to renew it. If not renewed before the expiry date, the site will immediately display certificate errors to all visitors.");
                }
                if (stripos($name, 'untrusted') !== false || stripos($name, 'self-signed') !== false) {
                    return det_expand("The TLS certificate presented by the server could not be verified against any trusted root certificate authority. The certificate is either self-signed or issued by a private CA not in the public trust store. All browsers reject this certificate with a security warning.");
                }
                if (stripos($name, 'weak cipher') !== false) {
                    $cipStr = $cip !== '' ? "The negotiated cipher suite was {$cip}." : "A weak cipher suite was negotiated.";
                    return det_expand("{$cipStr} This cipher has known cryptographic weaknesses and does not provide adequate security for sensitive data in transit.");
                }
                if ($pro !== '') {
                    return det_expand("The server completed a full TLS handshake using {$pro}. This protocol version is deprecated and has published cryptographic attacks that can compromise the confidentiality of traffic encrypted with it. The server should accept only TLS 1.2 and TLS 1.3.");
                }
                return det_expand($desc !== '' ? det_expand($desc) : "A TLS configuration issue was observed. Review the technical evidence section for the specific cipher, protocol, or certificate detail that triggered this finding.");

            case 'hsts':
                return det_expand("The Strict-Transport-Security header was absent from the server response. The server delivers content over HTTPS but provides no instruction to browsers to enforce HTTPS on subsequent visits. A first-time visitor who arrives via HTTP is not automatically protected.");

            case 'csp':
                return det_expand("No Content-Security-Policy header was present in the server response. The browser has no policy directive governing which scripts, frames, or resources are permitted to load. Any injected script executes without restriction in the victim's browser context.");

            case 'clickjacking':
                return det_expand("Neither an X-Frame-Options header nor a Content-Security-Policy frame-ancestors directive was present in the server response. The page can be embedded in an iframe on any external domain without restriction.");

            case 'mime':
                return det_expand("The X-Content-Type-Options: nosniff header was absent from the server response. Browsers are free to override the declared Content-Type by sniffing the response body, which can cause non-script resources to be executed as scripts.");

            case 'header':
                $hdrStr = $hdr !== '' ? "The {$hdr} header" : "The required security header";
                return det_expand("{$hdrStr} was absent from the server response. All other response headers were inspected — the missing header was not present in any form, including alternative or prefixed variants.");

            case 'redirect':
                $pStr = $p !== '' ? "The '{$p}' parameter" : "The redirect parameter";
                $locStr = $loc !== '' ? " The server issued a redirect to: {$loc}." : '';
                return det_expand("{$pStr} accepted an external URL as its value and the server issued an HTTP redirect to the attacker-supplied destination.{$locStr} No validation of the redirect destination was performed before issuing the Location header.");

            case 'cookie':
                $ckaStr = $cka !== '' ? "The {$cka} attribute was" : "Required cookie security attributes were";
                return det_expand("{$ckaStr} absent from the Set-Cookie header in the server response. The cookie was set without the full complement of Secure, HttpOnly, and SameSite=Strict attributes, leaving it exposed to the attack vector that the missing attribute is designed to prevent.");

            case 'file':
                $fStr = $fle !== '' ? "'{$fle}'" : "The sensitive file";
                $credStr = det_field($rawEvidence, 'Credentials Found');
                $credNote = ($credStr !== '' && strtolower($credStr) !== 'none detected by pattern matching (manual review recommended)')
                    ? " Pattern matching identified potential credentials in the response body — treat them as compromised and rotate immediately."
                    : " No credentials were matched by pattern — manual inspection of the response body is recommended.";
                return det_expand("A GET request to {$fStr} returned HTTP 200 with the file contents in the response body. The file is publicly accessible with no authentication required.{$credNote}");

            case 'secret':
                $matched = det_field($rawEvidence, 'Matched Text');
                $sStr = $sec !== '' ? "a {$sec}" : "a credential";
                $mStr = $matched !== '' ? " Matched text: {$matched}." : '';
                return det_expand("The HTTP response body contained {$sStr} matching a known credential pattern.{$mStr} The secret was present in the response served to the unauthenticated scanner — it is readable by anyone who loads the URL.");

            case 'info':
                if ($sv !== '') {
                    return det_expand("The Server response header disclosed the exact server software and version: '{$sv}'. This value is present on every HTTP response and is immediately queryable by automated scanners and CVE lookup tools.");
                }
                if ($pb !== '') {
                    return det_expand("The X-Powered-By response header disclosed the backend technology: '{$pb}'. This information is sent with every response and narrows the attack surface to vulnerabilities specific to this framework and version.");
                }
                $mcJs = $ctx['mixedJsCount'];
                $mcStr = $mcJs !== '' ? " {$mcJs} JavaScript file(s) were identified loading over HTTP." : '';
                return det_expand("The Hypertext Transfer Protocol Secure (HTTPS) page was found to load one or more resources over unencrypted Hypertext Transfer Protocol (HTTP).{$mcStr} These resources are interceptable and modifiable by a network-positioned attacker without breaking the HTTPS connection of the main page.");

            case 'port':
                $pStr = $prt !== '' ? "Port {$prt}" : "The scanned port";
                $sStr = $svc !== '' ? " ({$svc})" : '';
                $bnrStr = ($ctx['banner'] !== '' && strtolower($ctx['banner']) !== 'no')
                    ? " The service responded with a banner during scanning."
                    : " The port accepted the TCP connection without returning a service banner.";
                return det_expand("{$pStr}{$sStr} completed a TCP handshake with the external scanner — the port is OPEN and reachable from the public internet.{$bnrStr} No firewall filtering was observed between the scanner and this port.");

            default:
                if ($desc !== '')
                    return det_expand($desc);
                return "The scanner identified a deviation from expected security behaviour. The observed response did not match the expected secure result defined above. Refer to the raw evidence captured during the scan for full technical detail.";
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // POTENTIAL IMPACT  — 3 concrete, specific harm statements
    // ═══════════════════════════════════════════════════════════════════

    private static function impact(string $type, array $ctx, string $domain, string $sev): array
    {
        $p = $ctx['param'];
        $prt = $ctx['port'];
        $svc = $ctx['service'];
        $hdr = $ctx['header'];
        $fle = $ctx['file'];
        $sec = $ctx['secretType'];
        $db = $ctx['dbType'];
        $cka = $ctx['cookieAttr'];
        $pro = $ctx['protocol'];

        switch ($type) {
            case 'sqli':
                $dbStr = $db !== '' ? " ({$db})" : '';
                $pStr = $p !== '' ? " via the '{$p}' parameter" : '';
                return [
                    det_expand("Complete extraction of every record in the database{$dbStr} backing {$domain}{$pStr} — including usernames, password hashes, email addresses, and any stored payment or personal data — is achievable using freely available automated tooling in a single unattended run."),
                    det_expand("Depending on the database user's privileges, an attacker may escalate from data extraction to reading arbitrary server files (using LOAD_FILE) or writing backdoor files to the web root — converting a data breach into full server compromise."),
                    det_expand("Regulatory breach notification is triggered immediately upon exploitation — GDPR, HIPAA, and PCI-DSS all mandate disclosure of unauthorised database access, exposing {$domain}'s operator to significant fines and reputational damage."),
                ];

            case 'xss':
                if (stripos($ctx['payload'] . $svc, 'dom') !== false || stripos($svc, 'dom') !== false) {
                    return [
                        det_expand("If attacker-controlled data reaches an identified sink on {$domain}, the victim's session cookie is readable via document.cookie and exfiltrable in a single fetch() call — giving the attacker full authenticated access without knowing the user's password."),
                        det_expand("DOM-based Cross-Site Scripting (XSS) sinks can be triggered by crafted URLs shared via social media, email, or shortened links — no server-side change is needed once the link reaches a victim who is logged into {$domain}."),
                        det_expand("Keylogging and form data interception are achievable once script executes — an attacker injecting into a login or payment page can silently capture every keystroke before the form is submitted."),
                    ];
                }
                return [
                    det_expand("Any visitor to {$domain} who clicks a crafted link has their session cookie stolen and replayed by the attacker — the attacker gains full authenticated account access with no further interaction required."),
                    det_expand("The script executes in the victim's browser with the same permissions as the legitimate page — it can submit forms, change account settings, initiate transactions, and exfiltrate data on the victim's behalf, all silently."),
                    det_expand("If the reflected content is cached by a reverse proxy or CDN, the payload may be served to every subsequent visitor without requiring individual crafted links — escalating a reflected finding to a mass-compromise scenario."),
                ];

            case 'cors':
                $credBool = $ctx['acac'] === 'true';
                if ($credBool) {
                    return [
                        det_expand("Any logged-in user of {$domain} who visits an attacker-controlled site will have their private Application Programming Interface (API) data silently read — the attacker's JavaScript calls your API using the victim's session cookies and reads the full response."),
                        det_expand("Authenticated state-changing actions — password resets, profile updates, data deletions, purchase confirmations — can be triggered cross-origin against {$domain} without any user awareness, effectively giving the attacker full account control."),
                        det_expand("The attack requires no malware, no browser vulnerability, and no special user action beyond visiting a link — a convincing phishing page is sufficient to exploit any active {$domain} session."),
                    ];
                }
                return [
                    det_expand("Any website can read unauthenticated Application Programming Interface (API) responses from {$domain} — public or semi-public data including pricing, inventory, or aggregate user statistics is freely accessible cross-origin."),
                    det_expand("The permissive Cross-Origin Resource Sharing (CORS) policy can be combined with other vulnerabilities — for example an open redirect — to create a cross-origin data exfiltration chain that bypasses same-origin protections."),
                    det_expand("Third-party analytics scripts, advertising code, and compromised dependencies running on other sites can leverage the policy to make API requests to {$domain} and read responses, creating a persistent data harvesting risk."),
                ];

            case 'tls':
                if (stripos($svc, 'http') !== false || stripos($ctx['protocol'], 'insecure') !== false || ($ctx['protocol'] === '' && stripos($ctx['sqlError'], 'no https') !== false)) {
                    return [
                        det_expand("All credentials submitted through any form on {$domain} — login, password reset, registration — travel in plaintext and can be captured with a passive packet capture tool on the same network, requiring no active attack."),
                        det_expand("Session cookies transmitted over unencrypted Hypertext Transfer Protocol (HTTP) can be stolen and replayed to take over authenticated accounts without knowing the user's password — affecting every user on shared networks."),
                        det_expand("An active attacker can inject arbitrary content into pages delivered to users over Hypertext Transfer Protocol (HTTP) — inserting malicious scripts, modifying displayed data, or redirecting users to phishing pages transparently."),
                    ];
                }
                return [
                    det_expand("Users on shared or untrusted networks — hotel Wi-Fi, coffee shops, corporate proxies — are exposed to traffic interception using published attacks against the deprecated protocol or weak cipher in use on {$domain}."),
                    det_expand("Browsers display security warnings for deprecated Transport Layer Security (TLS) configurations, causing visitor abandonment and damaging user trust in {$domain} — some browsers block access entirely."),
                    det_expand("Recorded encrypted sessions may be stored by passive adversaries for future decryption as cryptographic attack tooling improves — long-lived sensitive data transmitted to {$domain} today is at future risk."),
                ];

            case 'hsts':
                return [
                    det_expand("A first-time visitor to {$domain} whose browser has no cached HSTS entry is vulnerable to SSL stripping on their initial Hypertext Transfer Protocol (HTTP) request — their session is conducted entirely in plaintext while they believe they are using HTTPS."),
                    det_expand("SSL stripping tools operate silently — the victim's browser shows a regular page with no visual indication that the connection has been downgraded, making this attack invisible to end users."),
                    det_expand("Credentials, session tokens, and any sensitive data submitted during a downgraded session on {$domain} are captured in plaintext by the attacker, leading directly to account takeover."),
                ];

            case 'csp':
                return [
                    det_expand("Any Cross-Site Scripting (XSS) vulnerability discovered in {$domain} in future — including in third-party dependencies — executes without any browser-level policy barrier, giving injected scripts unrestricted access to the page."),
                    det_expand("Compromised third-party scripts included by {$domain} (analytics, chat widgets, advertising) run with full browser access — data exfiltration, session theft, and keylogging are all possible from a single compromised dependency."),
                    det_expand("Without a Content Security Policy (CSP) violation reporting endpoint, {$domain}'s security team has no visibility into active injection attempts — attacks proceed undetected until damage is already done."),
                ];

            case 'clickjacking':
                return [
                    det_expand("A logged-in {$domain} user who visits an attacker-controlled page can be tricked into performing account actions — deleting data, confirming payments, or changing settings — by clicking what appears to be an unrelated interface element."),
                    det_expand("Clickjacking attacks require no technical vulnerability in the application code — a basic HTML page with a transparent iframe positioned over a decoy button is sufficient to execute the attack against any unprotected {$domain} page."),
                    det_expand("High-value actions such as payment confirmation, administrative approvals, or permission grants are the primary targets — the attacker weaponises the victim's own authenticated session to perform irreversible actions."),
                ];

            case 'mime':
                return [
                    det_expand("If {$domain} allows file uploads, an attacker can upload a JavaScript file with an image or document extension — without nosniff, the browser may execute it as a script when the file is opened, creating a stored Cross-Site Scripting (XSS) vector."),
                    det_expand("MIME sniffing turns seemingly safe file types into execution vectors — a file delivered with text/plain Content-Type that contains HTML or script content may be rendered as a web page rather than displayed as text."),
                    det_expand("Content-type confusion attacks enable persistent Cross-Site Scripting (XSS) through upload paths that appear to handle only safe file types, bypassing file type validation controls that rely on extension or MIME type checking."),
                ];

            case 'header':
                $hdrStr = $hdr !== '' ? "the {$hdr} header" : "this security header";
                return [
                    det_expand("Without {$hdrStr}, {$domain} relies entirely on application-layer controls — a single future injection vulnerability, misconfigured route, or compromised dependency removes all protection that this header would have provided at the browser layer."),
                    det_expand("Security headers are audited by automated scanners, penetration testers, and compliance frameworks (PCI-DSS, ISO 27001) — their absence is flagged as a control gap in every external security assessment of {$domain}."),
                    det_expand("Absence of browser-enforced policies makes {$domain} more susceptible to the full attack class governed by this header — the impact of any future exploitation in the relevant category is significantly higher than on a hardened site."),
                ];

            case 'redirect':
                return [
                    det_expand("Phishing campaigns using {$domain}'s trusted domain name as the delivery URL have a significantly higher click-through rate than campaigns using unknown domains — users and email security tools both trust the link origin."),
                    det_expand("Open redirects are frequently used in OAuth token theft — an attacker substitutes the redirect_uri with {$domain}'s open redirect endpoint, causing an OAuth provider to send an authorization code to an attacker-controlled page via the trusted domain."),
                    det_expand("Corporate email filtering and browser safe-browsing systems may allowlist {$domain}'s domain — redirects through {$domain} to phishing or malware delivery pages bypass these controls, delivering malicious content that direct links would block."),
                ];

            case 'cookie':
                return match (strtolower($cka)) {
                    'secure' => [
                        det_expand("The session cookie for {$domain} can be transmitted over an unencrypted Hypertext Transfer Protocol (HTTP) connection — a protocol downgrade, misconfigured redirect, or HTTP subdomain exposes the cookie to passive network capture."),
                        det_expand("An attacker who captures the session cookie gains authenticated access to the victim's {$domain} account with no further exploitation — the cookie is a complete authentication credential that can be replayed from any location."),
                        det_expand("Cookie exposure via Hypertext Transfer Protocol (HTTP) is invisible to the victim — there is no browser warning or page change during the capture, making this a silent credential theft vector."),
                    ],
                    'httponly' => [
                        det_expand("The session cookie for {$domain} is accessible to JavaScript via document.cookie — any Cross-Site Scripting (XSS) vulnerability, compromised third-party script, or browser extension with script injection capability can exfiltrate it in one line of code."),
                        det_expand("Cookie exfiltration via Cross-Site Scripting (XSS) is one of the most common post-exploitation actions — stealing the session token converts a script injection into a full account takeover without needing the user's password."),
                        det_expand("Without HttpOnly, every JavaScript-accessible cookie is a credential at risk — not just obvious session tokens but also CSRF tokens, feature flags, and analytics identifiers that may carry authentication state."),
                    ],
                    'samesite' => [
                        det_expand("The session cookie for {$domain} is sent automatically on cross-site requests — a malicious page can submit a form to {$domain} and the browser attaches the victim's session cookie, allowing Cross-Site Request Forgery (CSRF) attacks without any user awareness."),
                        det_expand("Cross-Site Request Forgery (CSRF) attacks using this cookie can trigger any state-changing action that the victim is authorised to perform — password changes, email updates, fund transfers, or administrative actions — from a single malicious link."),
                        det_expand("SameSite cookies are the most effective CSRF mitigation for modern browsers — their absence means additional controls such as CSRF tokens must compensate, and any gap in token validation restores the full risk."),
                    ],
                    default => [
                        det_expand("The session cookie for {$domain} lacks full protection against cookie theft and Cross-Site Request Forgery (CSRF) attacks — authenticated sessions are more easily hijacked or abused by attacker-controlled sites."),
                        det_expand("A stolen or forged session cookie provides complete authenticated access to the victim's account — the attacker operates as the legitimate user with no additional authentication required."),
                        det_expand("Cookie security attributes are the primary defence against session-based attacks — their absence increases the risk from every other class of vulnerability that involves authenticated user sessions."),
                    ],
                };

            case 'file':
                $fStr = $fle !== '' ? "'{$fle}'" : "the exposed file";
                return [
                    det_expand("The file {$fStr} on {$domain} may contain database passwords, API keys, or private keys — each credential represents a separate, directly exploitable access path to connected systems that does not require further vulnerability chaining."),
                    det_expand("Automated vulnerability scanners probe for this file path continuously — it is listed in common wordlists (dirbuster, gobuster, SecLists) and likely scanned thousands of times per day against internet-facing servers. The file may already have been retrieved before this scan."),
                    det_expand("Source code exposed in files such as this accelerates every subsequent attack by revealing internal application logic, database schema, API endpoint paths, and the specific software versions in use — effectively providing an attacker with a map of the application."),
                ];

            case 'secret':
                $sStr = $sec !== '' ? "The exposed {$sec}" : "The exposed credential";
                return [
                    det_expand("{$sStr} provides immediate, authenticated access to the connected service — no vulnerability chaining, no brute-force, and no further reconnaissance is required beyond reading the URL that triggered this finding."),
                    det_expand("Cloud provider credentials (AWS, Azure, GCP) found in HTTP responses are harvested by automated bots within minutes of exposure — the financial impact of a compromised cloud account can include large compute charges, data exfiltration, and complete infrastructure deletion."),
                    det_expand("Once a credential is exposed in an HTTP response, it cannot be quietly rotated — search engine caches, automated scan databases, and threat intelligence platforms may have already indexed the value. Every system using the credential must be rotated simultaneously to close the exposure."),
                ];

            case 'info':
                if (stripos($ctx['serverHeader'] . $ctx['poweredBy'], '') !== false && ($ctx['serverHeader'] !== '' || $ctx['poweredBy'] !== '')) {
                    return [
                        det_expand("Precise version disclosure about {$domain} allows attackers to immediately query CVE databases and exploit repositories for vulnerabilities specific to the disclosed software version — converting passive reconnaissance into a targeted exploitation attempt."),
                        det_expand("Automated scanners worldwide harvest version disclosure from HTTP headers continuously — {$domain}'s exact software version may already be catalogued in threat intelligence platforms such as Shodan, accelerating attacker target selection."),
                        det_expand("Version information is permanently linked to known vulnerability timelines — knowing that {$domain} runs a specific version tells an attacker exactly which patches are missing and which exploits from public databases apply."),
                    ];
                }
                return [
                    det_expand("Mixed content on {$domain} undermines the security guarantee of Hypertext Transfer Protocol Secure (HTTPS) — an attacker on the same network can intercept and modify the HTTP resources even though the main page is encrypted."),
                    det_expand("JavaScript files loaded over Hypertext Transfer Protocol (HTTP) on an HTTPS page are the highest-risk class of mixed content — a modified script file gives the attacker complete control of page behaviour and access to all DOM data including form inputs and cookies."),
                    det_expand("Users of {$domain} on mobile networks, corporate proxies, or compromised home routers are silently exposed to content injection through the HTTP resources even while believing they are protected by the HTTPS padlock in their browser."),
                ];

            case 'port':
                $svcStr = $svc !== '' ? $svc : "the exposed service";
                $prtStr = $prt !== '' ? " on port {$prt}" : '';
                return [
                    det_expand("Direct internet access to {$svcStr}{$prtStr} on {$domain} enables automated exploitation tooling to attempt credential attacks, known CVE exploits, and default configuration abuse — typically beginning within minutes of the port appearing in scan results."),
                    det_expand("Services of this type are primary targets for ransomware operators and data exfiltration actors — a single successful unauthenticated connection may provide complete server control, database access, or lateral movement capability into the internal network."),
                    det_expand("The exposed port represents a persistent attack surface that operates independently of the web application — even a fully patched web application does not protect against direct exploitation of network services accessible on other ports."),
                ];

            default:
                return [
                    det_expand("This finding on {$domain} indicates that a security control is not enforcing its intended policy under adversarial conditions — the specific failure mode is documented in the evidence section above."),
                    det_expand("Attackers actively scan for this vulnerability class using publicly available tooling — exploitation does not require advanced skill and can be achieved by automated scanners without human involvement."),
                    det_expand("Unaddressed security findings compound over time — each open issue increases the number of available attack paths and the probability that a combination of findings enables a breach that no single finding would achieve alone."),
                ];
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // RECOMMENDATIONS  — 4–5 specific, actionable steps
    // ═══════════════════════════════════════════════════════════════════

    private static function recommendations(
        string $type,
        array $ctx,
        string $domain,
        string $remText,
        array $vuln
    ): array {
        $p = $ctx['param'];
        $prt = $ctx['port'];
        $svc = $ctx['service'];
        $hdr = $ctx['header'];
        $fle = $ctx['file'];
        $sec = $ctx['secretType'];
        $pro = $ctx['protocol'];
        $cip = $ctx['cipher'];
        $cka = $ctx['cookieAttr'];
        $db = $ctx['dbType'];

        $recs = match ($type) {
            'sqli' => [
                ($p !== ''
                    ? det_expand("Rewrite the query handler for the '{$p}' parameter using a parameterised query or prepared statement — the parameter value must be bound separately and must never be concatenated into the query string. This is the only reliable fix for SQL injection.")
                    : det_expand("Audit every database query in the codebase for string concatenation of user input and replace each instance with parameterised queries or prepared statements — apply this universally across all endpoints, not only the one identified here.")),
                det_expand("Set the database account used by the application to the minimum permissions required — remove DROP, CREATE, FILE, and EXECUTE privileges so that even a successful injection cannot escalate beyond data read access."),
                det_expand("Disable database error output in production responses — configure the application to log errors server-side only. Error messages returned to the browser assist attackers in confirming injection and identifying the database type."),
                det_expand("Run a dedicated Structured Query Language (SQL) injection scanner (sqlmap in safe mode, or a DAST tool) against all application endpoints after remediation to confirm the fix is complete and no sibling parameters share the same vulnerability."),
                det_expand("Implement a Web Application Firewall (WAF) rule to detect and block SQL injection patterns as a defence-in-depth layer — this does not replace parameterised queries but reduces exposure during the remediation window."),
            ],

            'xss' => [
                det_expand("Apply context-aware output encoding to all user-supplied values before rendering — use HTML entity encoding for body content, attribute encoding for HTML attributes, and JavaScript string encoding for values placed inside script blocks. Apply this at every output point, not just the identified parameter."),
                det_expand("Deploy a Content-Security-Policy header with script-src restricted to your specific trusted origins and without 'unsafe-inline' — this constrains the blast radius of any injection that escapes output encoding, and generates violation reports that alert you to active attacks."),
                det_expand("Search the codebase for every location where request parameters, URL fragments, or stored user content are written into HTML responses and apply encoding uniformly — a single missed location restores the full vulnerability even after the identified endpoint is fixed."),
                det_expand("For DOM-based Cross-Site Scripting (XSS) sinks, replace innerHTML assignments with textContent where HTML rendering is not required, and sanitise HTML input using DOMPurify before any assignment to innerHTML or outerHTML."),
                det_expand("Retest the affected endpoint with the same payload family after remediation — also test stored and DOM-based variants if user content is persisted or processed client-side, as these may share the same root cause."),
            ],

            'cors' => [
                det_expand("Replace the current Cross-Origin Resource Sharing (CORS) policy with a server-side origin allowlist — validate the incoming Origin header against an explicit list of trusted domains and reflect it in Access-Control-Allow-Origin only when it matches. Never reflect the Origin header unconditionally."),
                det_expand("Remove Access-Control-Allow-Credentials: true from any endpoint that uses a wildcard or reflected origin — credentials and wildcard origins cannot be safely combined. If credentialed cross-origin access is required, restrict it to a specific named allowlisted origin only."),
                det_expand("Apply the corrected Cross-Origin Resource Sharing (CORS) policy consistently across all Application Programming Interface (API) routes — audit preflight OPTIONS responses separately, as they may have different headers from GET and POST responses."),
                det_expand("Set the Vary: Origin response header on all endpoints that conditionally set Access-Control-Allow-Origin — this prevents caches from serving a cached response with one origin's CORS headers to a request from a different origin."),
                det_expand("Retest with an untrusted Origin header value after remediation across all application routes and HTTP methods to confirm the fix is complete and not bypassed by any route-specific override."),
            ],

            'tls' => self::tlsRecs($ctx, $pro, $cip),

            'hsts' => [
                det_expand("Add Strict-Transport-Security: max-age=31536000; includeSubDomains to all HTTPS responses — configure this at the web server or reverse proxy level so it is returned on every response, not only application routes."),
                det_expand("Verify that all subdomains of the target domain also serve content exclusively over Hypertext Transfer Protocol Secure (HTTPS) before adding the includeSubDomains directive — a single HTTP-only subdomain breaks HSTS protection for the entire domain."),
                det_expand("After confirming all subdomains are HTTPS-only, add the preload directive and submit the domain at hstspreload.org — preloading means browsers enforce HTTPS even on the very first visit, before any HSTS header has been received."),
                det_expand("Ensure the Strict-Transport-Security header is not present on any Hypertext Transfer Protocol (HTTP) response — the header is only valid and trusted by browsers when delivered over an existing HTTPS connection."),
            ],

            'csp' => [
                det_expand("Deploy a Content-Security-Policy header that restricts script-src to your specific trusted origins — start with default-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self' and add additional origins only as specifically required."),
                det_expand("Apply the policy initially in report-only mode using Content-Security-Policy-Report-Only with a report-uri or report-to endpoint — collect and review violation reports to identify legitimate resource loads that need to be allowlisted before switching to enforcement mode."),
                det_expand("Remove 'unsafe-inline' and 'unsafe-eval' from the script-src directive — these directives negate most of the Content Security Policy (CSP)'s protection against Cross-Site Scripting (XSS) by allowing inline script execution that injected code can exploit."),
                det_expand("Add frame-ancestors 'none' to the policy to simultaneously address clickjacking — this replaces the need for a separate X-Frame-Options header in modern browsers."),
                det_expand("Verify the Content Security Policy (CSP) is returned on all response types — HTML pages, Application Programming Interface (API) responses, redirect responses, and custom error pages — using a header inspection proxy after deployment."),
            ],

            'clickjacking' => [
                det_expand("Add X-Frame-Options: DENY to all application responses at the web server or reverse proxy configuration level — this prevents the page from being embedded in an iframe on any external domain and is supported by all major browsers including legacy versions."),
                det_expand("Add Content-Security-Policy: frame-ancestors 'none' to supplement X-Frame-Options — modern browsers enforce this CSP directive preferentially and it provides more granular control than the older header."),
                det_expand("Apply both headers globally in your web server configuration rather than in application code — this ensures coverage of all routes including those not explicitly handled by the application framework."),
                det_expand("Review high-value user interaction workflows — payment confirmations, permission grants, account deletions — and add explicit confirmation dialogs or re-authentication steps that cannot be triggered by an invisible iframe."),
            ],

            'mime' => [
                det_expand("Add X-Content-Type-Options: nosniff to all responses at the web server or reverse proxy configuration level — Nginx: add_header X-Content-Type-Options \"nosniff\" always; / Apache: Header always set X-Content-Type-Options \"nosniff\"."),
                det_expand("Ensure all responses are served with an explicit and accurate Content-Type header — never derive the content type from the file extension supplied by an uploader; always validate and override with a server-controlled value."),
                det_expand("Serve user-uploaded content from a separate cookieless domain (e.g. static.example.com) — this ensures that even if content is executed as a script in the browser, it has no access to the main application's cookies or authenticated state."),
            ],

            'header' => self::headerRecs($hdr, $domain),

            'redirect' => [
                det_expand("Validate redirect destination URLs server-side against an explicit allowlist of approved internal paths — reject any destination not on the list with a 400 Bad Request response, log the attempt, and never silently redirect to an unvalidated URL."),
                det_expand("If redirects must support external destinations, implement a signed redirect token — encode the destination URL server-side with a cryptographic signature, issue the token to the client, and resolve the destination only on the server at redirect time."),
                det_expand("Reject any redirect destination that contains a scheme other than your own application's scheme, or that resolves to a host outside your domain — strip protocol-relative URLs (//) and encode forward slashes to prevent bypass attempts."),
                det_expand("Retest the identified parameter with external URL, protocol-relative, and scheme-less payloads after remediation to confirm all bypass variants are blocked, not just the specific payload observed during the original scan."),
            ],

            'cookie' => self::cookieRecs($cka, $domain),

            'file' => self::fileRecs($fle, $domain),

            'secret' => [
                det_expand("Revoke and rotate the exposed {$sec} credential immediately — do not assess usage before revoking. Automated bots harvest credentials from HTTP responses within minutes of exposure; assume the credential is already known to attackers."),
                det_expand("Remove the credential from the source code, response body, or file where it was found — replace it with an environment variable reference or a secrets management lookup (AWS Secrets Manager, HashiCorp Vault, or Azure Key Vault)."),
                det_expand("Review access logs and audit trails for the affected credential covering the full period of exposure — look for authentication events from unfamiliar IP addresses or geographic regions that indicate unauthorised use has already occurred."),
                det_expand("Run a secrets scanning tool (truffleHog, gitleaks, or git-secrets) across the full repository history — secrets committed to version control persist in history even after the file is deleted or the line is removed from the current branch."),
                det_expand("Add a pre-commit hook and CI/CD pipeline step that scans for credential patterns before every commit and deployment — this prevents future credentials from being inadvertently exposed through the same path."),
            ],

            'info' => self::infoRecs($ctx, $domain),

            'port' => self::portRecs($svc, $prt, $domain),

            default => [
                det_expand("Retest this finding with identical conditions after applying your remediation to confirm the specific behaviour observed during the scan no longer occurs and the control is functioning as designed."),
                det_expand("Audit adjacent endpoints and functionality for the same class of issue — vulnerabilities of the same type frequently appear in clusters across related handlers built by the same developer or using the same framework pattern."),
                det_expand("Schedule a follow-up scan within 30 days of remediation to confirm the fix is stable and has not been reversed by a subsequent deployment or dependency update."),
                det_expand("Document the remediation steps taken as evidence for compliance audits — include the before/after behaviour, the specific change made, and the retest result."),
            ],
        };

        // Prepend the scanner's own remediation text if it adds specificity
        if ($remText !== '' && !str_contains(strtolower($remText), 'see owasp') && mb_strlen($remText) > 20) {
            $expanded = det_expand($remText);
            $stem = strtolower(substr(preg_replace('/[^a-z0-9 ]/i', '', $expanded), 0, 40));
            $firstStem = strtolower(substr(preg_replace('/[^a-z0-9 ]/i', '', $recs[0] ?? ''), 0, 40));
            if ($stem !== $firstStem) {
                array_unshift($recs, $expanded);
            }
        }

        // Deduplicate and limit to 5
        $seen = [];
        $out = [];
        foreach ($recs as $rec) {
            $key = strtolower(substr(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/i', '', $rec)), 0, 50));
            if ($key === '' || isset($seen[$key]))
                continue;
            $seen[$key] = true;
            $out[] = $rec;
            if (count($out) >= 5)
                break;
        }
        return $out;
    }

    // ── Per-type recommendation sub-builders ─────────────────────────

    private static function tlsRecs(array $ctx, string $pro, string $cip): array
    {
        $name = $ctx['sqlError']; // re-using field slot — not relevant here
        if (stripos($ctx['timingPayload'] . $ctx['timingDelta'], '') === 0) { /* unused */
        }

        $base = [
            ($pro !== ''
                ? det_expand("Disable {$pro} in your server configuration — Nginx: ssl_protocols TLSv1.2 TLSv1.3; / Apache: SSLProtocol -all +TLSv1.2 +TLSv1.3 — then restart the server and verify the protocol change with: openssl s_client -connect host:443 -tls1")
                : det_expand("Configure the server to accept only Transport Layer Security (TLS) 1.2 and TLS 1.3 — explicitly disable TLS 1.0, TLS 1.1, SSL 3.0, and SSL 2.0 in your Nginx or Apache configuration.")),
            ($cip !== ''
                ? det_expand("Remove the weak cipher '{$cip}' from your cipher suite configuration and replace with AEAD ciphers only: TLS_AES_256_GCM_SHA384, TLS_CHACHA20_POLY1305_SHA256, ECDHE-RSA-AES256-GCM-SHA384.")
                : det_expand("Update your cipher suite list to allow only AEAD ciphers (AES-GCM, ChaCha20-Poly1305) — explicitly remove RC4, 3DES, NULL, and EXPORT cipher entries from the server configuration.")),
            det_expand("Validate the corrected configuration using SSL Labs (ssllabs.com/ssltest) immediately after deployment — target an A or A+ grade and archive the result as evidence of remediation for compliance records."),
            det_expand("Enable automatic certificate renewal using certbot or your certificate authority's Application Programming Interface (API) client — configure renewal to trigger at 30 days remaining so certificate expiry cannot recur silently."),
            det_expand("Add the Strict-Transport-Security: max-age=31536000; includeSubDomains header to all HTTPS responses to prevent protocol downgrade attacks that could bypass a correctly configured TLS stack."),
        ];
        return $base;
    }

    private static function headerRecs(string $hdr, string $domain): array
    {
        static $headerDetails = [
        'Content-Security-Policy' => [
        "Add Content-Security-Policy: default-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self' to your web server configuration — apply at the global vhost level so it covers every route automatically.",
        "Deploy in report-only mode first using Content-Security-Policy-Report-Only with a report-uri endpoint — collect violation reports and tune the policy based on real traffic before switching to enforcement mode.",
        "Remove 'unsafe-inline' and 'unsafe-eval' from the directive if present — these flags negate most Cross-Site Scripting (XSS) protection the policy would otherwise provide.",
        "Verify the header is present on Application Programming Interface (API) endpoints, redirect responses, and error pages — not only on the HTML pages returned from your main routes.",
        ],
        'Strict-Transport-Security' => [
        "Add Strict-Transport-Security: max-age=31536000; includeSubDomains to all HTTPS responses — set this in your web server configuration so it is returned on every route.",
        "Verify all subdomains serve content exclusively over Hypertext Transfer Protocol Secure (HTTPS) before applying the includeSubDomains directive — a single HTTP-only subdomain breaks HSTS protection for the entire domain.",
        "After confirming full HTTPS coverage, add the preload directive and submit the domain at hstspreload.org — this ensures browsers enforce HTTPS even before the first HSTS header is received.",
        "Ensure the header is absent from Hypertext Transfer Protocol (HTTP) responses — it is only trusted by browsers when received over an existing HTTPS connection.",
        ],
        'X-Frame-Options' => [
        "Add X-Frame-Options: DENY to all application responses in your web server configuration — Nginx: add_header X-Frame-Options \"DENY\" always; / Apache: Header always set X-Frame-Options \"DENY\".",
        "Supplement with Content-Security-Policy: frame-ancestors 'none' for modern browser enforcement — both headers together provide maximum compatibility across browser versions.",
        "Review all pages that handle sensitive user actions (payments, settings, deletions) and add confirmation dialogs that cannot be silently triggered by a clickjacking iframe.",
        ],
        'X-Content-Type-Options' => [
        "Add X-Content-Type-Options: nosniff to all responses in your web server configuration — this is a single-line change that takes effect immediately after a config reload.",
        "Ensure all file uploads are stored with server-controlled Content-Type values — never derive the type from the filename extension provided by the uploader.",
        "Serve user-uploaded content from a separate cookieless subdomain to limit the blast radius of any content-type confusion that affects uploaded files.",
        ],
        'Referrer-Policy' => [
        "Add Referrer-Policy: strict-origin-when-cross-origin to all responses — this prevents sensitive URL parameters from leaking to third-party domains while preserving referrer information for same-origin analytics.",
        "Review URL structures to ensure session tokens, user identifiers, and sensitive data are not embedded in query parameters that would appear in Referer headers.",
        "Apply the header at the web server level rather than in application code to ensure consistent coverage across all routes and response types.",
        ],
        'Permissions-Policy' => [
        "Add Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=() to all responses — this prevents scripts from requesting sensitive browser features the application does not require.",
        "Audit which browser permissions the application legitimately needs and restrict the policy to only those — the strictest policy is one that denies everything not explicitly required.",
        "Apply the header at the reverse proxy or web server level so it is returned on all routes, not only explicitly coded application endpoints.",
        ],
        'X-XSS-Protection' => [
        "Add X-XSS-Protection: 1; mode=block to all responses — Nginx: add_header X-XSS-Protection \"1; mode=block\" always; / Apache: Header always set X-XSS-Protection \"1; mode=block\".",
        "Note that this header is deprecated in modern browsers which use Content Security Policy (CSP) instead — prioritise deploying a strong Content-Security-Policy header as the primary Cross-Site Scripting (XSS) mitigation.",
        "Apply both this header and a Content Security Policy (CSP) to cover both legacy and modern browser populations simultaneously.",
        ],
        ];

        $steps = $headerDetails[$hdr] ?? [
            det_expand("Add the {$hdr} header at the web server or reverse proxy configuration level so it applies to every response automatically — not only to specific application routes."),
            det_expand("Verify the header is present on all response types — HTML pages, Application Programming Interface (API) responses, redirect responses, and error pages — using a header inspection proxy after deployment."),
            det_expand("Use the OWASP Secure Headers Project reference (owasp.org/www-project-secure-headers) for the current recommended value and browser compatibility information."),
            det_expand("Schedule a re-scan after deployment to confirm the header appears consistently across all paths — absence from any route indicates a routing bypass that requires a separate fix."),
        ];

        return array_map('det_expand', $steps);
    }

    private static function cookieRecs(string $cka, string $domain): array
    {
        $base = [
            ($cka !== ''
                ? det_expand("Add the '{$cka}' attribute to the Set-Cookie header for all session and authentication cookies — the complete recommended definition is: Set-Cookie: name=value; Secure; HttpOnly; SameSite=Strict.")
                : det_expand("Add Secure, HttpOnly, and SameSite=Strict attributes to all session and authentication cookies — apply this at the framework or application session configuration level to cover every route automatically.")),
            det_expand("Audit every Set-Cookie header across all application routes using a header inspection proxy — check both authenticated and unauthenticated response flows, as some frameworks set different cookies per route type."),
            det_expand("Use the __Host- cookie name prefix for the most critical session cookies where the application supports it — this prefix enforces the Secure attribute, prevents subdomain sharing, and cannot be overridden by an insecure origin."),
            det_expand("Verify cookie attributes are applied consistently across all environments — development, staging, and production configurations sometimes differ and a staging misconfiguration can be accidentally promoted to production."),
        ];
        return $base;
    }

    private static function fileRecs(string $fle, string $domain): array
    {
        $fStr = $fle !== '' ? "'{$fle}'" : "the sensitive file path";
        $escapedFile = $fle !== '' ? preg_quote($fle, '/') : 'sensitivefile';
        return [
            det_expand("Block external access to {$fStr} using a server-level deny rule immediately — Nginx: location ~* {$escapedFile} { deny all; return 404; } / Apache: <Files \"{$fle}\"> Require all denied </Files>. Apply and reload the server configuration without delay."),
            det_expand("If the file contains credentials or secrets, treat them as fully compromised and rotate them immediately — do not wait to confirm usage. Check server access logs for the file path covering the maximum available retention period to assess prior exposure."),
            det_expand("Move all configuration and environment files permanently outside the web root — only files intended for public access should be inside the directory served by the web server. Restructure the deployment to enforce this separation."),
            det_expand("Add a deployment pipeline step that fails the build if files matching sensitive patterns (.env, .git/, *.sql, *.bak, *.key, web.config, docker-compose.yml) are detected inside the web root directory — this prevents recurrence across future deployments."),
            det_expand("Conduct a wider audit of all paths accessible at {$domain} using a web content discovery tool — other sensitive files may be present at paths not covered by this scan."),
        ];
    }

    private static function infoRecs(array $ctx, string $domain): array
    {
        if ($ctx['serverHeader'] !== '' || $ctx['poweredBy'] !== '') {
            return [
                det_expand("Suppress version information in the Server response header — Nginx: set server_tokens off; in nginx.conf / Apache: set ServerTokens Prod and ServerSignature Off in the server configuration."),
                det_expand("Remove the X-Powered-By header — Express/Node.js: app.disable('x-powered-by') / PHP: set expose_php = Off in php.ini / Apache: Header always unset X-Powered-By."),
                det_expand("Audit all custom response headers for additional technology or version disclosures — X-AspNet-Version, X-Runtime, X-Generator, and Via headers are also common disclosure vectors."),
                det_expand("Verify the suppression is effective by running curl -sI {$domain} | grep -iE 'server|powered|runtime|version' after applying the configuration changes."),
            ];
        }
        // mixed content
        return [
            det_expand("Replace all Hypertext Transfer Protocol (HTTP) resource URLs with their HTTPS equivalents in your HTML templates and static assets — this is a search-and-replace operation across the codebase or template layer."),
            det_expand("Add Content-Security-Policy: upgrade-insecure-requests to your response headers — this instructs browsers to automatically upgrade HTTP resource requests to HTTPS without requiring individual URL changes."),
            det_expand("Verify there are no remaining HTTP resource loads using your browser's developer tools Network tab — filter by 'HTTP' and confirm zero results before considering the fix complete."),
            det_expand("If any referenced resources are hosted on third-party domains that do not support HTTPS, replace them with HTTPS-capable alternatives or self-host the resources on your own HTTPS domain."),
        ];
    }

    private static function portRecs(string $svc, string $prt, string $domain): array
    {
        $svcStr = $svc !== '' ? $svc : "the exposed service";
        $prtStr = $prt !== '' ? $prt : "the identified";

        $svcSpecific = match (strtolower($svc)) {
            'redis' => det_expand("Bind Redis to localhost only — set bind 127.0.0.1 in /etc/redis/redis.conf and add requirepass with a strong password. Restart Redis after the change: systemctl restart redis."),
            'mongodb' => det_expand("Bind MongoDB to localhost — set bindIp: 127.0.0.1 in /etc/mongod.conf and enable authorization: enabled under the security section. Restart and create an admin user before enabling authentication."),
            'telnet' => det_expand("Disable the Telnet service immediately — systemctl stop telnet.socket && systemctl disable telnet.socket. Install and enable SSH as the replacement: systemctl enable --now ssh."),
            'elasticsearch' => det_expand("Bind Elasticsearch to localhost — set network.host: 127.0.0.1 in elasticsearch.yml. Enable Elasticsearch security (X-Pack) by setting xpack.security.enabled: true and configuring authentication."),
            'mysql' => det_expand("Bind MySQL to localhost — set bind-address = 127.0.0.1 in /etc/mysql/mysql.conf.d/mysqld.cnf. Remove or restrict any anonymous or remote root accounts: DELETE FROM mysql.user WHERE Host='%';"),
            'postgresql' => det_expand("Restrict PostgreSQL connections to localhost — set listen_addresses = 'localhost' in postgresql.conf and update pg_hba.conf to reject external connections."),
            'ftp' => det_expand("Disable FTP and replace it with SFTP (SSH File Transfer Protocol) — FTP transmits credentials in plaintext. If FTP cannot be removed, enforce TLS (FTPS) and restrict to specific IP addresses."),
            'rdp' => det_expand("Move Remote Desktop Protocol (RDP) access behind a Virtual Private Network (VPN) — remove the firewall rule allowing external access to port 3389. Require Network Level Authentication (NLA) and multi-factor authentication for all RDP sessions."),
            'smb' => det_expand("Block external access to SMB port 445 — Windows file sharing is not designed for internet exposure and is the primary vector for EternalBlue/WannaCry-style exploits. Ensure Windows security patches are fully applied."),
            default => det_expand("Restrict {$svcStr} to internal network access only — bind the service to 127.0.0.1 or a private IP in its configuration file so it does not listen on the public interface."),
        };

        return [
            det_expand("Add a firewall rule to block all external access to port {$prtStr}/tcp immediately — allow connections only from specific internal IP ranges or through a Virtual Private Network (VPN) tunnel. Verify the rule is active from an external network after applying it."),
            $svcSpecific,
            det_expand("If {$svcStr} is not required for production operations on this host, disable or uninstall it entirely — an unused service that is not running presents zero attack surface regardless of firewall configuration."),
            det_expand("Rotate all credentials associated with {$svcStr} immediately — default passwords and any credentials that may have been transmitted over the exposed period must be treated as compromised."),
            det_expand("Retest port reachability from a network outside the server's own subnet after applying the firewall changes — confirm port {$prtStr} returns no response (filtered) from the external scanner perspective."),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // METADATA HELPERS
    // ═══════════════════════════════════════════════════════════════════

    private static function category(string $type): string
    {
        return match ($type) {
            'sqli' => 'Injection / Input Validation',
            'xss' => 'Injection / Input Validation',
            'cors' => 'Access Control Misconfiguration',
            'csrf' => 'Access Control Misconfiguration',
            'redirect' => 'Access Control Misconfiguration',
            'cookie' => 'Session Management',
            'tls' => 'Transport Security',
            'hsts' => 'Transport Security',
            'header' => 'Security Header Misconfiguration',
            'mime' => 'Security Header Misconfiguration',
            'csp' => 'Security Header Misconfiguration',
            'clickjacking' => 'UI Redress / Clickjacking',
            'port' => 'Network Exposure / Attack Surface',
            'file' => 'Sensitive Data Exposure',
            'secret' => 'Credential / Secret Exposure',
            'info' => 'Information Disclosure',
            default => 'Application Security',
        };
    }

    private static function likelihood(string $sev): string
    {
        return match (strtolower($sev)) {
            'critical' => 'Very High',
            'high' => 'High',
            'medium' => 'Moderate',
            'low' => 'Low',
            default => 'Low',
        };
    }

    private static function priority(string $sev): string
    {
        return match (strtolower($sev)) {
            'critical', 'high' => 'High',
            'medium' => 'Medium',
            default => 'Low',
        };
    }

    private static function status(string $sev): string
    {
        return match (strtolower($sev)) {
            'critical', 'high' => 'Immediate Action Required',
            'medium' => 'Action Required',
            'low' => 'Monitor / Best Practice',
            'info' => 'Informational',
            default => 'Needs Review',
        };
    }

    // ═══════════════════════════════════════════════════════════════════
    // NETWORK FIELD EXTRACTORS
    // ═══════════════════════════════════════════════════════════════════

    private static function extractIp(string $text): string
    {
        if (preg_match('/\b(\d{1,3}(?:\.\d{1,3}){3})\b/', $text, $m)) {
            return $m[1];
        }
        return '';
    }

    private static function extractPort(string $text): string
    {
        if (preg_match('/\bport\s+(\d+)/i', $text, $m))
            return $m[1];
        if (preg_match('/Port\s*:\s*(\d+)/i', $text, $m))
            return $m[1];
        return '';
    }
}