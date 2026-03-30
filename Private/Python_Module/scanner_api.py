from flask import Flask, request, jsonify
from flask_cors import CORS
from flask_limiter import Limiter
from flask_limiter.util import get_remote_address
import requests
import urllib3
import ssl
import socket
import re
import json
import hashlib
import time
import logging
from datetime import datetime, timedelta, timezone
from urllib.parse import urlparse, parse_qs, urlencode, urlunparse, urljoin
from typing import Dict, List, Tuple, Optional, Set
import certifi
import concurrent.futures
from dataclasses import dataclass, asdict, field
from enum import Enum
import subprocess
import platform
from threading import Lock

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

logging.basicConfig(
    level=logging.DEBUG,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


# ─────────────────────────────────────────────
# EVIDENCE HELPERS
# ─────────────────────────────────────────────

def _format_request_headers(headers: dict) -> str:
    """Format outgoing request headers for evidence."""
    if not headers:
        return "  (none)"
    return "\n".join(f"  {k}: {v}" for k, v in headers.items())


def _format_response_headers(response: requests.Response) -> str:
    """Format all received response headers for evidence."""
    if response is None:
        return "  (no response)"
    return "\n".join(f"  {k}: {v}" for k, v in response.headers.items())


def _highlight_in_body(body: str, needle: str, context_chars: int = 200) -> str:
    """
    Find needle in body and return surrounding context with the needle
    wrapped in >>> ... <<< markers so it stands out in the evidence block.
    Returns up to context_chars characters either side.
    """
    if not body or not needle:
        return "(not found in body)"
    idx = body.find(needle)
    if idx == -1:
        idx = body.lower().find(needle.lower())
    if idx == -1:
        return f"(payload not found verbatim; body length={len(body)})"
    start  = max(0, idx - context_chars)
    end    = min(len(body), idx + len(needle) + context_chars)
    before = body[start:idx].replace('\n', ' ').replace('\r', '')
    match  = body[idx:idx + len(needle)]
    after  = body[idx + len(needle):end].replace('\n', ' ').replace('\r', '')
    prefix = "..." if start > 0 else ""
    suffix = "..." if end < len(body) else ""
    return f"{prefix}{before}>>>{match}<<<{after}{suffix}"


def _body_preview(body: str, max_chars: int = 400) -> str:
    if not body:
        return "(empty body)"
    cleaned = body[:max_chars].replace('\r', '').strip()
    return cleaned + ("..." if len(body) > max_chars else "")


def _classify_response_body(body: str, content_type: str) -> str:
    """
    Returns a short human-readable label for what the response body appears to be.
    Helps non-technical readers understand what was returned.
    """
    ct = content_type.lower()
    if 'json' in ct:
        return "JSON API response"
    if 'xml' in ct:
        return "XML document"
    if 'text/plain' in ct:
        return "Plain text"
    if 'html' in ct:
        # Distinguish error pages, login pages, app pages
        bl = body.lower()
        if any(k in bl for k in ['exception', 'stack trace', 'traceback', 'fatal error']):
            return "Error/exception page (contains debug output)"
        if any(k in bl for k in ['login', 'sign in', 'password', 'username']):
            return "Login or authentication page"
        if any(k in bl for k in ['404', 'not found', 'page not found']):
            return "404 / Not Found page"
        if any(k in bl for k in ['403', 'forbidden', 'access denied']):
            return "403 / Forbidden page"
        return "HTML web page"
    if not body.strip():
        return "Empty response body"
    return "Binary or unrecognised content"


def _extract_title(body: str) -> str:
    """Extract <title> from HTML for evidence context."""
    m = re.search(r'<title[^>]*>([^<]{1,120})</title>', body, re.I)
    return m.group(1).strip() if m else "(no title)"


def _collapse_nested_expansions(text: str) -> str:
    if not text:
        return text
    for _ in range(10):
        before = text
        text = re.sub(
            r"Hypertext Transfer Protocol \(Hypertext Transfer Protocol \(HTTP\)\)",
            "Hypertext Transfer Protocol (HTTP)", text)
        text = re.sub(
            r"Hypertext Transfer Protocol Secure \(Hypertext Transfer Protocol Secure \(HTTPS\)\)",
            "Hypertext Transfer Protocol Secure (HTTPS)", text)
        text = re.sub(
            r"Cross-Site Scripting \(Cross-Site Scripting \(Cross-Site Scripting \(XSS\)\)\)",
            "Cross-Site Scripting (XSS)", text)
        text = re.sub(
            r"Cross-Site Scripting \(Cross-Site Scripting \(XSS\)\)",
            "Cross-Site Scripting (XSS)", text)
        if text == before:
            break
    return text


def expand_security_terms(text: Optional[str]) -> str:
    if not text:
        return ""
    out = str(text)
    replacements = [
        (r"\bCORS\b",                    "Cross-Origin Resource Sharing (CORS)"),
        (r"\bSQLi\b",                    "Structured Query Language injection (SQL injection)"),
        (r"\bSQL\b",                     "Structured Query Language (SQL)"),
        (r"\bXSS\b",                     "Cross-Site Scripting (XSS)"),
        (r"\bTLS\b",                     "Transport Layer Security (TLS)"),
        (r"\bSSL\b",                     "Secure Sockets Layer / Transport Layer Security (SSL/TLS)"),
        (r"\bCSRF\b",                    "Cross-Site Request Forgery (CSRF)"),
        (r"(?<!\()\bHTTP\b(?!\))",       "Hypertext Transfer Protocol (HTTP)"),
        (r"(?<!\()\bHTTPS\b(?!\))",      "Hypertext Transfer Protocol Secure (HTTPS)"),
        (r"\bCVE\b",                     "Common Vulnerabilities and Exposures (CVE)"),
        (r"\bRCE\b",                     "Remote Code Execution (RCE)"),
        (r"\bVPN\b",                     "Virtual Private Network (VPN)"),
        (r"\bAPI\b",                     "Application Programming Interface (API)"),
    ]
    for pattern, replacement in replacements:
        out = re.sub(pattern, replacement, out)
    return _collapse_nested_expansions(out)


EAT_TZ = timezone(timedelta(hours=3))

def now_eat_iso() -> str:
    return datetime.now(EAT_TZ).isoformat()

def now_eat_naive() -> datetime:
    return datetime.now(EAT_TZ).replace(tzinfo=None)


app = Flask(__name__)

CORS(app, resources={
    r"/*": {
        "origins": "*",
        "methods": ["GET", "POST", "OPTIONS"],
        "allow_headers": ["Content-Type", "Authorization", "Accept"],
        "supports_credentials": False
    }
})

limiter = Limiter(
    app=app,
    key_func=get_remote_address,
    default_limits=["200 per day", "50 per hour"]
)

SCAN_PROGRESS: Dict[str, Dict] = {}
SCAN_PROGRESS_LOCK = Lock()


def set_scan_progress(scan_token: Optional[str], stage: str, progress: int,
                      status: str = "running", detail: str = "") -> None:
    if not scan_token:
        return
    with SCAN_PROGRESS_LOCK:
        SCAN_PROGRESS[scan_token] = {
            "scan_token":  scan_token,
            "stage":       stage,
            "progress":    max(0, min(100, int(progress))),
            "status":      status,
            "detail":      detail,
            "updated_at":  now_eat_iso(),
        }


# ─────────────────────────────────────────────
# DATA MODELS
# ─────────────────────────────────────────────

class Severity(Enum):
    CRITICAL = "critical"
    HIGH     = "high"
    MEDIUM   = "medium"
    LOW      = "low"
    INFO     = "info"
    SECURE   = "secure"

class PortStatus(Enum):
    OPEN       = "open"
    CLOSED     = "closed"
    FILTERED   = "filtered"
    UNFILTERED = "unfiltered"

@dataclass
class Vulnerability:
    name:          str
    severity:      Severity
    description:   str
    evidence:      str
    remediation:   str
    cvss_score:    Optional[float] = None
    what_we_tested: Optional[str] = None
    indicates:     Optional[str]  = None
    how_exploited: Optional[str]  = None

    def dedup_key(self) -> str:
        return hashlib.md5(
            f"{self.name}|{self.severity.value}|{self.description[:60]}".encode()
        ).hexdigest()

@dataclass
class PortInfo:
    port:    int
    status:  PortStatus
    service: str
    banner:  Optional[str] = None
    version: Optional[str] = None
    risk:    str = "info"

@dataclass
class ScanResult:
    target:         str
    timestamp:      str
    scan_duration:  float
    ssl_info:       Dict
    headers:        Dict
    vulnerabilities: List[Vulnerability]
    port_scan:      Dict
    summary:        Dict
    error:          Optional[str] = None
    server_info:    Optional[Dict] = None
    crawler:        Optional[Dict] = None


# ─────────────────────────────────────────────
# PORT SCANNER
# ─────────────────────────────────────────────

class PortScanner:
    COMMON_PORTS = {
        21:    ('FTP',           'high'),
        22:    ('SSH',           'medium'),
        23:    ('Telnet',        'critical'),
        25:    ('SMTP',          'medium'),
        53:    ('DNS',           'low'),
        80:    ('HTTP',          'info'),
        110:   ('POP3',          'medium'),
        143:   ('IMAP',          'medium'),
        443:   ('HTTPS',         'info'),
        445:   ('SMB',           'high'),
        3306:  ('MySQL',         'high'),
        3389:  ('RDP',           'high'),
        5432:  ('PostgreSQL',    'high'),
        6379:  ('Redis',         'critical'),
        8080:  ('HTTP-Proxy',    'medium'),
        8443:  ('HTTPS-Alt',     'info'),
        9200:  ('Elasticsearch', 'high'),
        27017: ('MongoDB',       'critical'),
    }

    RISKY_SERVICES = {
        'telnet':        'critical',
        'ftp':           'high',
        'ms-wbt-server': 'high',
        'netbios-ssn':   'high',
        'mysql':         'medium',
        'postgresql':    'medium',
        'redis':         'critical',
        'mongodb':       'critical',
        'elasticsearch': 'high',
        'docker':        'critical',
        'kubernetes':    'critical',
    }

    ADMIN_PANEL_PATHS = ['/', '/admin', '/manager', '/console',
                         '/dashboard', '/phpmyadmin', '/wp-admin']

    def __init__(self, timeout: float = 2.0, max_workers: int = 50):
        self.timeout      = timeout
        self.max_workers  = max_workers
        self.os_type      = platform.system().lower()

    def scan_host(self, hostname: str, ports: Optional[List[int]] = None,
                  scan_type: str = "connect") -> Dict:
        result = {
            'target': hostname, 'scan_type': scan_type,
            'ports_scanned': 0, 'open_ports': [], 'closed_ports': [],
            'filtered_ports': [], 'services_found': [], 'vulnerabilities': [],
            'start_time': now_eat_iso(), 'duration': 0.0
        }
        start_time = time.time()

        try:
            target_ip = socket.gethostbyname(hostname)
            result['ip_address'] = target_ip
        except socket.gaierror:
            result['error'] = f"Could not resolve hostname: {hostname}"
            return result

        if ports is None:
            ports = list(self.COMMON_PORTS.keys())
        elif isinstance(ports, str) and ports == "full":
            ports = list(range(1, 65536))
        elif isinstance(ports, str) and ports == "top100":
            ports = self._get_top100_ports()

        result['ports_scanned'] = len(ports)

        if scan_type == "syn" and self.os_type != "windows":
            open_ports = self._syn_scan(target_ip, ports)
        else:
            open_ports = self._connect_scan(target_ip, ports)

        def _port_evidence(title: str, info: PortInfo) -> str:
            """
            Forensic-quality port evidence.
            Covers: what was tested, what the scanner did, exactly what it found,
            what a layperson should understand, and what a developer can act on.
            """
            protocol = "TCP"
            state    = info.status.value.upper() if hasattr(info.status, "value") else "OPEN"
            risk_label = {
                'critical': 'CRITICAL — Do not expose to the internet',
                'high':     'HIGH — Should be restricted or hidden behind a firewall',
                'medium':   'MEDIUM — Review whether internet access is necessary',
                'low':      'LOW — Generally acceptable but worth reviewing',
                'info':     'INFO — Expected open port',
            }.get(info.risk.lower(), info.risk.upper())

            lines = [
                f"{'='*60}",
                f"PORT SCAN EVIDENCE — {title}",
                f"{'='*60}",
                "",
                "WHAT WE DID",
                f"{'-'*40}",
                f"  Scan Method      : TCP {scan_type.upper()} connect — we attempted a full",
                f"                     TCP handshake with port {info.port} on the target host.",
                f"  Target Hostname  : {hostname}",
                f"  Resolved IP      : {target_ip}",
                f"  Port Tested      : {info.port}/{protocol.lower()}",
                f"  Scan Timestamp   : {now_eat_iso()}",
                "",
                "WHAT WE FOUND",
                f"{'-'*40}",
                f"  Port             : {info.port}/{protocol.lower()}",
                f"  Observed State   : {state}",
                f"  Detected Service : {info.service}",
                f"  Risk Rating      : {risk_label}",
                "",
                "SERVICE FINGERPRINT",
                f"{'-'*40}",
            ]

            if info.banner:
                banner_clean = info.banner.replace('\r\n', '\n').replace('\r', '\n').strip()
                lines.append(f"  Banner Received  : Yes — the service sent the following greeting:")
                for banner_line in banner_clean.split('\n')[:8]:
                    lines.append(f"    | {banner_line}")
                lines.append(f"  Banner Length    : {len(info.banner)} characters")
            else:
                lines.append("  Banner Received  : No — port accepted connection but sent no greeting data.")
                lines.append("  Implication      : Service is listening but silent (common for databases, proxies).")

            if info.version:
                lines.append(f"  Version String   : {info.version}")
                lines.append(f"  Implication      : Exact version is visible — enables targeted CVE lookup.")

            lines += [
                "",
                "NETWORK REACHABILITY",
                f"{'-'*40}",
                f"  Connection Result  : TCP handshake completed successfully",
                f"  Firewall Status    : Not filtered — connection completed within {self.timeout:.1f}s timeout",
                f"  Internet Exposure  : This port is reachable from outside your network",
                "",
                "SUMMARY",
                f"{'-'*40}",
                f"  Port {info.port} on your server is open and responding to anyone on the internet.",
                f"  The service running here is identified as: {info.service}.",
            ]

            if info.risk in ('critical', 'high'):
                lines += [
                    f"  This type of service ({info.service}) is commonly targeted by automated",
                    f"  attack tools. It should not be directly reachable from the public internet.",
                    f"  If you need it, restrict access to specific IP addresses using a firewall rule.",
                ]
            elif info.risk == 'medium':
                lines += [
                    f"  This service does not need to be publicly accessible in most deployments.",
                    f"  Verify whether internet access is intentional and restrict if not needed.",
                ]
            else:
                lines.append(f"  This is an expected open port for serving web traffic.")

            return "\n".join(lines) + "\n"

        for port_info in open_ports:
            entry = {
                'port': port_info.port, 'service': port_info.service,
                'banner': port_info.banner, 'version': port_info.version,
                'risk': port_info.risk
            }
            result['open_ports'].append(entry)

            service_lower = port_info.service.lower()
            if any(risky in service_lower for risky in self.RISKY_SERVICES):
                risk_level = self.RISKY_SERVICES.get(
                    next((r for r in self.RISKY_SERVICES if r in service_lower), None), 'medium'
                )
                severity = (Severity.CRITICAL if risk_level == 'critical' else
                            Severity.HIGH     if risk_level == 'high'     else Severity.MEDIUM)
                result['vulnerabilities'].append(Vulnerability(
                    name=f"Exposed {port_info.service} Service",
                    severity=severity,
                    description=f"Port {port_info.port} ({port_info.service}) is open and accessible from the internet",
                    evidence=_port_evidence(f"Exposed {port_info.service} Service", port_info),
                    remediation=f"Restrict access to {port_info.service} using firewall rules. Consider VPN or IP whitelisting.",
                    cvss_score=7.5 if risk_level == 'critical' else 6.5,
                    what_we_tested=f"We probed port {port_info.port} and identified the service running on it.",
                    indicates="An exposed service widens your attack surface and may indicate a misconfigured firewall.",
                    how_exploited="Attackers scan for this port, then target known CVEs for the service, attempt brute-force logins, or use it for lateral movement."
                ))

            if port_info.port == 23:
                result['vulnerabilities'].append(Vulnerability(
                    name="Telnet Service Detected",
                    severity=Severity.CRITICAL,
                    description="Telnet transmits all data including credentials in plaintext",
                    evidence=_port_evidence("Telnet Plaintext Service", port_info),
                    remediation="Disable Telnet immediately and use SSH instead",
                    cvss_score=9.8,
                    what_we_tested="We detected an open Telnet service (port 23).",
                    indicates="Telnet means credentials and all traffic can be read by anyone on the network path.",
                    how_exploited="A network-level attacker can sniff usernames and passwords; or brute-force the login directly."
                ))
            elif port_info.port == 6379:
                result['vulnerabilities'].append(Vulnerability(
                    name="Redis Exposed to Internet",
                    severity=Severity.CRITICAL,
                    description="Redis is accessible without network-level restriction",
                    evidence=_port_evidence("Redis Unauthenticated Access", port_info),
                    remediation="Bind Redis to 127.0.0.1 in redis.conf. Enable requirepass. Block port 6379 at the firewall.",
                    cvss_score=10.0,
                    what_we_tested="We probed the Redis port for open access.",
                    indicates="Publicly accessible Redis frequently allows unauthenticated data access and remote code execution.",
                    how_exploited="Attackers read/write all data, plant SSH keys via config SET, or write a cron job for shell access."
                ))
            elif port_info.port == 27017:
                result['vulnerabilities'].append(Vulnerability(
                    name="MongoDB Exposed to Internet",
                    severity=Severity.CRITICAL,
                    description="MongoDB port is accessible without network-level restriction",
                    evidence=_port_evidence("MongoDB Unauthenticated Access", port_info),
                    remediation="Bind MongoDB to 127.0.0.1. Enable authentication. Block port 27017 at the firewall.",
                    cvss_score=10.0,
                    what_we_tested="We probed the MongoDB port for open access.",
                    indicates="Publicly accessible MongoDB has historically led to mass data theft.",
                    how_exploited="An attacker connects with any MongoDB client, dumps all databases, deletes them, and leaves a ransom note."
                ))
            elif port_info.port == 9200:
                result['vulnerabilities'].append(Vulnerability(
                    name="Elasticsearch Exposed to Internet",
                    severity=Severity.HIGH,
                    description="Elasticsearch REST API is publicly accessible",
                    evidence=_port_evidence("Elasticsearch REST API Exposure", port_info),
                    remediation="Restrict port 9200 to localhost or VPN. Enable Elasticsearch security features (X-Pack).",
                    cvss_score=8.5,
                    what_we_tested="We probed the Elasticsearch REST API port.",
                    indicates="Unauthenticated Elasticsearch allows reading and deleting all indexed data.",
                    how_exploited="Attacker calls /_cat/indices and /_search to dump all data, or deletes indices."
                ))

            if port_info.service in ('HTTP', 'HTTP-Proxy', 'HTTPS-Alt') or port_info.port in (8080, 8443, 8888, 9090):
                admin_found = self._probe_admin_panels(
                    hostname, port_info.port,
                    'https' if port_info.port in (8443,) else 'http'
                )
                if admin_found:
                    result['vulnerabilities'].append(Vulnerability(
                        name=f"Admin Panel Accessible on Port {port_info.port}",
                        severity=Severity.HIGH,
                        description=f"An admin or management interface was found at port {port_info.port}",
                        evidence=(
                            _port_evidence("Administrative Interface Exposure", port_info) +
                            f"\nADMIN PATH RESULTS\n{'-'*40}\n" +
                            "\n".join(f"  GET {p} -> returned HTTP 200/30x/401/403 (path exists)" for p in admin_found) +
                            f"\n\n  These paths responded — they exist and may be accessible.\n"
                            f"  A 401/403 response means the path is protected but reachable from the internet.\n"
                            f"  A 200 response means it is fully open with no authentication.\n"
                        ),
                        remediation="Restrict admin interfaces to internal IPs or VPN only. Add authentication if not present.",
                        cvss_score=7.0,
                        what_we_tested=f"We probed common admin paths on port {port_info.port}.",
                        indicates="Exposed admin panels are a prime target for credential brute-force and direct exploitation.",
                        how_exploited="Attacker accesses the panel directly and attempts default or brute-forced credentials."
                    ))

        result['duration']   = round(time.time() - start_time, 2)
        result['open_count'] = len(result['open_ports'])
        return result

    def _probe_admin_panels(self, hostname: str, port: int, scheme: str) -> List[str]:
        found   = []
        session = requests.Session()
        session.headers['User-Agent'] = 'ScanQuotient/2.2'
        for path in self.ADMIN_PANEL_PATHS:
            url = f"{scheme}://{hostname}:{port}{path}"
            try:
                r = session.get(url, timeout=3, verify=False, allow_redirects=False)
                if r.status_code in (200, 301, 302, 401, 403):
                    found.append(path)
                    if len(found) >= 3:
                        break
            except Exception:
                continue
        return found

    def _connect_scan(self, target_ip: str, ports: List[int]) -> List[PortInfo]:
        open_ports = []

        def scan_single_port(port: int) -> Optional[PortInfo]:
            sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            sock.settimeout(self.timeout)
            try:
                if sock.connect_ex((target_ip, port)) == 0:
                    service_name = self.COMMON_PORTS.get(port, ('Unknown', 'info'))[0]
                    banner = None
                    version = None
                    try:
                        sock.settimeout(1.0)
                        if port == 22:
                            banner  = sock.recv(256).decode('utf-8', errors='ignore').strip()
                            version = banner.split('\n')[0] if banner else None
                            service_name = 'SSH'
                        elif port == 21:
                            banner = sock.recv(256).decode('utf-8', errors='ignore').strip()
                            service_name = 'FTP'
                        elif port == 25:
                            banner = sock.recv(256).decode('utf-8', errors='ignore').strip()
                            service_name = 'SMTP'
                        elif port in (80, 8080, 8000, 8888):
                            sock.send(b'HEAD / HTTP/1.0\r\nHost: target\r\n\r\n')
                            banner = sock.recv(512).decode('utf-8', errors='ignore').strip()
                            service_name = 'HTTP'
                        elif port in (443, 8443):
                            service_name = 'HTTPS'
                        elif port == 6379:
                            sock.send(b'PING\r\n')
                            banner = sock.recv(64).decode('utf-8', errors='ignore').strip()
                            service_name = 'Redis'
                        elif port == 27017:
                            service_name = 'MongoDB'
                        else:
                            sock.send(b'\r\n')
                            banner = sock.recv(256).decode('utf-8', errors='ignore').strip()
                    except Exception:
                        pass

                    risk = self.COMMON_PORTS.get(port, ('Unknown', 'info'))[1]
                    return PortInfo(
                        port=port, status=PortStatus.OPEN, service=service_name,
                        banner=banner[:400] if banner else None,
                        version=version, risk=risk
                    )
            except socket.timeout:
                return PortInfo(port=port, status=PortStatus.FILTERED, service='unknown', risk='info')
            except Exception as e:
                logger.debug(f"Port {port} error: {e}")
                return None
            finally:
                sock.close()
            return None

        with concurrent.futures.ThreadPoolExecutor(max_workers=self.max_workers) as executor:
            futures = {executor.submit(scan_single_port, p): p for p in ports}
            for future in concurrent.futures.as_completed(futures):
                result = future.result()
                if result and result.status == PortStatus.OPEN:
                    open_ports.append(result)

        return sorted(open_ports, key=lambda x: x.port)

    def _syn_scan(self, target_ip: str, ports: List[int]) -> List[PortInfo]:
        try:
            return self._nmap_syn_scan(target_ip, ports)
        except Exception as e:
            logger.warning(f"SYN scan failed ({e}), falling back to connect scan")
            return self._connect_scan(target_ip, ports)

    def _nmap_syn_scan(self, target_ip: str, ports: List[int]) -> List[PortInfo]:
        open_ports = []
        try:
            subprocess.run(['nmap', '--version'], capture_output=True, check=True)
        except (subprocess.CalledProcessError, FileNotFoundError):
            raise RuntimeError("nmap not available")

        port_str = ','.join(map(str, ports))
        cmd      = ['nmap', '-sS', '-p', port_str, '-T4', '--open', '-oX', '-', target_ip]
        try:
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=300)
            for line in result.stdout.split('\n'):
                if 'portid=' in line and 'state="open"' in line:
                    match = re.search(r'portid="(\d+)"', line)
                    if match:
                        port = int(match.group(1))
                        service_match = re.search(r'name="([^"]+)"', line)
                        service = service_match.group(1) if service_match else 'unknown'
                        open_ports.append(PortInfo(
                            port=port, status=PortStatus.OPEN,
                            service=service.upper(),
                            risk=self.RISKY_SERVICES.get(service.lower(), 'low')
                        ))
        except Exception as e:
            logger.error(f"nmap scan failed: {e}")
            raise
        return open_ports

    def _get_top100_ports(self) -> List[int]:
        return [
            21,22,23,25,53,80,110,111,135,139,143,443,445,993,995,
            1723,3306,3389,5900,8080,8443,8888,9200,27017,6379,5432,
            2222,2082,2083,2086,2087,2095,2096,7080,7443,8000,8001,
            8008,8009,8010,8081,8082,8083,8084,8085,8086,8087,8088,
            8089,8090,8181,8222,8333,8400,8500,8600,8800,8888,8899,
            9000,9001,9002,9003,9009,9090,9091,9100,9200,9300,9418,
            9999,10000,10001,10080,10443,11211,15672,25565,27017,
            28017,49152,49153,49154,49155,49156,49157,50000,51413,
            55555,65000,65535
        ][:100]


# ─────────────────────────────────────────────
# MAIN SECURITY SCANNER
# ─────────────────────────────────────────────

class SecurityScanner:
    def __init__(self):
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'ScanQuotient Security Scanner/2.2 (Security Assessment Tool)'
        })
        self.timeout       = 10
        self.max_redirects = 5
        self.port_scanner  = PortScanner(timeout=2.0, max_workers=30)

        self.sql_payloads = [
            "'", "''", "' OR '1'='1", "' OR 1=1--", "' UNION SELECT NULL--",
            "' AND 1=1--", "' AND 1=2--", "'; DROP TABLE users--",
            "1' AND 1=1--", "1' AND 1=2--", "' OR 'x'='x",
            "') OR ('1'='1", "1; SELECT * FROM users", "' OR 1=1#", "' OR 1=1/*"
        ]

        self.blind_sql_payloads = [
            ("' AND SLEEP(4)--",                          "MySQL"),
            ("'; WAITFOR DELAY '0:0:4'--",                "MSSQL"),
            ("' AND pg_sleep(4)--",                       "PostgreSQL"),
            ("' AND 1=BENCHMARK(5000000,MD5(1))--",       "MySQL-benchmark"),
        ]

        self.xss_payloads = [
            "<script>alert(1)</script>",
            "<img src=x onerror=alert(1)>",
            "javascript:alert(1)",
            "\"><script>alert(1)</script>",
            "'><script>alert(1)</script>",
            "<svg onload=alert(1)>",
            "<iframe src=javascript:alert(1)>",
            "<body onload=alert(1)>",
            "<input onfocus=alert(1) autofocus>",
            "<select onfocus=alert(1) autofocus>",
            "<textarea onfocus=alert(1) autofocus>",
            "<video><source onerror=\"javascript:alert(1)\">",
            "<audio src=x onerror=alert(1)>",
        ]

        self.redirect_payloads = [
            "https://evil.com", "//evil.com", "/\\evil.com",
            "https:evil.com", "%2F%2Fevil.com",
        ]

        self.sensitive_paths = [
            ('/.env',               'Environment File',         Severity.CRITICAL),
            ('/.env.local',         'Environment File',         Severity.CRITICAL),
            ('/.env.production',    'Environment File',         Severity.CRITICAL),
            ('/.git/HEAD',          'Git Repository',           Severity.HIGH),
            ('/.git/config',        'Git Config',               Severity.HIGH),
            ('/config.php',         'PHP Config File',          Severity.HIGH),
            ('/wp-config.php',      'WordPress Config',         Severity.CRITICAL),
            ('/wp-config.php.bak',  'WordPress Config Backup',  Severity.CRITICAL),
            ('/phpinfo.php',        'PHP Info Page',            Severity.MEDIUM),
            ('/info.php',           'PHP Info Page',            Severity.MEDIUM),
            ('/test.php',           'Test PHP File',            Severity.LOW),
            ('/admin',              'Admin Panel',              Severity.MEDIUM),
            ('/admin/',             'Admin Panel',              Severity.MEDIUM),
            ('/administrator',      'Admin Panel',              Severity.MEDIUM),
            ('/phpmyadmin',         'phpMyAdmin',               Severity.HIGH),
            ('/phpmyadmin/',        'phpMyAdmin',               Severity.HIGH),
            ('/pma',                'phpMyAdmin (pma)',          Severity.HIGH),
            ('/wp-admin',           'WordPress Admin',          Severity.MEDIUM),
            ('/wp-login.php',       'WordPress Login',          Severity.LOW),
            ('/server-status',      'Apache Server Status',     Severity.MEDIUM),
            ('/server-info',        'Apache Server Info',       Severity.MEDIUM),
            ('/nginx_status',       'Nginx Status Page',        Severity.MEDIUM),
            ('/actuator',           'Spring Boot Actuator',     Severity.HIGH),
            ('/actuator/env',       'Spring Actuator Env',      Severity.CRITICAL),
            ('/actuator/health',    'Spring Actuator Health',   Severity.LOW),
            ('/api/swagger-ui',     'Swagger UI',               Severity.LOW),
            ('/swagger-ui.html',    'Swagger UI',               Severity.LOW),
            ('/swagger.json',       'Swagger API Spec',         Severity.LOW),
            ('/api-docs',           'API Docs',                 Severity.LOW),
            ('/graphql',            'GraphQL Endpoint',         Severity.MEDIUM),
            ('/console',            'Web Console',              Severity.HIGH),
            ('/solr',               'Solr Admin UI',            Severity.HIGH),
            ('/jmx-console',        'JBoss JMX Console',        Severity.CRITICAL),
            ('/web-console',        'JBoss Web Console',        Severity.HIGH),
            ('/debug',              'Debug Endpoint',           Severity.MEDIUM),
            ('/_debug_toolbar',     'Django Debug Toolbar',     Severity.MEDIUM),
            ('/trace',              'Trace Endpoint',           Severity.LOW),
            ('/backup',             'Backup Directory',         Severity.HIGH),
            ('/backup.zip',         'Backup Archive',           Severity.CRITICAL),
            ('/backup.tar.gz',      'Backup Archive',           Severity.CRITICAL),
            ('/dump.sql',           'Database Dump',            Severity.CRITICAL),
            ('/db.sql',             'Database Dump',            Severity.CRITICAL),
            ('/database.sql',       'Database Dump',            Severity.CRITICAL),
            ('/robots.txt',         'Robots.txt (info only)',    Severity.INFO),
            ('/sitemap.xml',        'Sitemap (info only)',       Severity.INFO),
            ('/crossdomain.xml',    'Flash Crossdomain Policy', Severity.LOW),
            ('/.htaccess',          '.htaccess File',           Severity.MEDIUM),
            ('/web.config',         'IIS Web Config',           Severity.HIGH),
            ('/package.json',       'Node Package Manifest',    Severity.LOW),
            ('/composer.json',      'PHP Composer Manifest',    Severity.LOW),
            ('/Dockerfile',         'Dockerfile',               Severity.LOW),
            ('/docker-compose.yml', 'Docker Compose File',      Severity.MEDIUM),
        ]

        self.security_headers = {
            'Strict-Transport-Security': {
                'required': True, 'description': 'HSTS - Forces HTTPS connections',
                'severity': Severity.HIGH
            },
            'Content-Security-Policy': {
                'required': True, 'description': 'CSP - Prevents XSS and data injection',
                'severity': Severity.CRITICAL
            },
            'X-Frame-Options': {
                'required': True, 'description': 'Clickjacking protection',
                'severity': Severity.MEDIUM
            },
            'X-Content-Type-Options': {
                'required': True, 'description': 'MIME sniffing protection',
                'severity': Severity.MEDIUM
            },
            'Referrer-Policy': {
                'required': False, 'description': 'Controls referrer information',
                'severity': Severity.LOW
            },
            'Permissions-Policy': {
                'required': False, 'description': 'Browser feature restrictions',
                'severity': Severity.LOW
            },
            'X-XSS-Protection': {
                'required': False, 'description': 'Legacy XSS filter (deprecated but useful)',
                'severity': Severity.LOW
            }
        }

        self.header_recommended = {
            'Strict-Transport-Security': 'max-age=31536000; includeSubDomains; preload',
            'Content-Security-Policy':   "default-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'",
            'X-Frame-Options':           'DENY',
            'X-Content-Type-Options':    'nosniff',
            'Referrer-Policy':           'strict-origin-when-cross-origin',
            'Permissions-Policy':        'camera=(), microphone=(), geolocation=()',
            'X-XSS-Protection':          '1; mode=block',
        }

    # ── Helpers ───────────────────────────────────────────────────────────

    def _safe_get(self, url: str, **kwargs) -> Optional[requests.Response]:
        kwargs.setdefault('timeout', self.timeout)
        kwargs.setdefault('verify', False)
        kwargs.setdefault('allow_redirects', True)
        try:
            return self.session.get(url, **kwargs)
        except requests.RequestException as e:
            logger.debug(f"GET {url} failed: {e}")
            return None

    def _build_evidence(self, title: str, request_url: str, method: str,
                        request_headers: Optional[dict], response: Optional[requests.Response],
                        extra_fields: Optional[List[Tuple[str, str]]] = None,
                        body_highlight: Optional[str] = None,
                        body_preview_chars: int = 400,
                        plain_english_summary: Optional[str] = None) -> str:
        """
        Central evidence builder — produces a structured, forensic-quality block.

        Sections:
          WHAT WE DID         — scanner action in plain language
          REQUEST             — full HTTP request sent (method, URL, all headers)
          RESPONSE            — full status line + all response headers received
          RESPONSE BODY       — excerpt with finding highlighted (>>> ... <<<)
          FINDING DETAILS     — finding-specific key/value fields
          PLAIN-LANGUAGE      — one-paragraph summary for non-technical readers

        Both technical and non-technical readers can extract value from this layout.
        """
        sep  = "=" * 60
        sep2 = "-" * 40

        # Derive body metadata if response exists
        body_type    = ""
        page_title   = ""
        content_type = ""
        if response is not None:
            content_type = response.headers.get('Content-Type', '')
            body_type    = _classify_response_body(response.text, content_type)
            if 'html' in content_type.lower():
                page_title = _extract_title(response.text)

        lines = [sep, f"EVIDENCE: {title}", sep, ""]

        # ── WHAT WE DID ──────────────────────────────────────────────────
        lines += [
            "WHAT WE DID",
            sep2,
            f"  We sent a {method} request to the URL shown below and inspected the",
            f"  response for signs of the vulnerability described in this finding.",
            f"  URL Tested  : {request_url}",
            f"  Method      : {method}",
            f"  Timestamp   : {now_eat_iso()}",
            "",
        ]

        # ── REQUEST ──────────────────────────────────────────────────────
        lines += ["REQUEST", sep2,
                  f"  Method  : {method}",
                  f"  URL     : {request_url}"]
        if request_headers:
            lines.append("  Headers Sent:")
            for k, v in request_headers.items():
                lines.append(f"    {k}: {v}")
        lines.append("")

        # ── RESPONSE ─────────────────────────────────────────────────────
        lines += ["RESPONSE", sep2]
        if response is not None:
            lines.append(f"  Status  : {response.status_code} {response.reason}")
            if content_type:
                lines.append(f"  Content-Type   : {content_type}")
            if page_title:
                lines.append(f"  Page Title     : {page_title}")
            lines.append(f"  Body Type      : {body_type}")
            lines.append(f"  Body Size      : {len(response.content):,} bytes")
            lines.append("  All Response Headers:")
            for k, v in response.headers.items():
                lines.append(f"    {k}: {v}")
            lines.append("")

            # ── RESPONSE BODY ─────────────────────────────────────────
            lines += ["RESPONSE BODY", sep2]
            if body_highlight:
                excerpt = _highlight_in_body(response.text, body_highlight)
                lines.append("  The scanner looked for the payload in the response body.")
                lines.append("  The finding is marked with >>> ... <<< below:")
                lines.append(f"  {excerpt}")
            else:
                lines.append(f"  (First {body_preview_chars} characters of response body)")
                lines.append(f"  {_body_preview(response.text, body_preview_chars)}")
        else:
            lines.append("  (No HTTP response received — connection may have failed or timed out)")
        lines.append("")

        # ── FINDING DETAILS ───────────────────────────────────────────
        if extra_fields:
            lines += ["FINDING DETAILS", sep2]
            for k, v in extra_fields:
                lines.append(f"  {k:<35}: {v if v is not None and v != '' else 'Not observed'}")
            lines.append("")

        # ── PLAIN-LANGUAGE SUMMARY ────────────────────────────────────
        if plain_english_summary:
            lines += ["PLAIN-LANGUAGE SUMMARY", sep2,
                      f"  {plain_english_summary}", ""]

        return "\n".join(lines)

    def get_server_info(self, response: requests.Response) -> Dict:
        info = {}
        headers_lower = {k.lower(): (k, v) for k, v in response.headers.items()}
        for key, label in [
            ('server', 'Server'), ('x-powered-by', 'X-Powered-By'),
            ('x-aspnet-version', 'X-AspNet-Version'),
            ('x-aspnetmvc-version', 'X-AspNetMvc-Version'),
            ('x-runtime', 'X-Runtime'), ('x-version', 'X-Version'),
            ('x-generator', 'X-Generator'), ('via', 'Via'),
        ]:
            if key in headers_lower:
                _, value = headers_lower[key]
                info[label] = value[:200] if value else ''
        return info

    def crawl_domain(self, start_url: str, max_pages: int = 12) -> List[str]:
        parsed   = urlparse(start_url)
        scheme   = parsed.scheme
        netloc   = parsed.netloc
        base     = f"{scheme}://{netloc}"
        seen: Set[str] = set()
        to_visit = [start_url]
        discovered = []

        while to_visit and len(discovered) < max_pages:
            url = to_visit.pop(0)
            if url in seen:
                continue
            seen.add(url)
            if url not in discovered:
                discovered.append(url)
            try:
                r    = self._safe_get(url)
                if r is None:
                    continue
                text = r.text or ''
                for m in re.finditer(r'<a\s+[^>]*href\s*=\s*["\']([^"\']+)["\']', text, re.I):
                    href = m.group(1).strip().split('#')[0].strip()
                    if not href or href.startswith('javascript:') or href.startswith('mailto:'):
                        continue
                    if href.startswith('//'):
                        href = scheme + ':' + href
                    elif href.startswith('/'):
                        href = base + href
                    elif not href.startswith('http'):
                        try:
                            href = urljoin(url, href)
                        except Exception:
                            continue
                    try:
                        p    = urlparse(href)
                        if p.netloc and p.netloc.lower() != netloc.lower():
                            continue
                        if not p.scheme or p.scheme not in ('http', 'https'):
                            continue
                        norm = urlunparse((p.scheme, p.netloc or netloc, p.path or '/', '', '', ''))
                        if norm not in seen and norm not in to_visit:
                            to_visit.append(norm)
                    except Exception:
                        continue
            except Exception as e:
                logger.debug(f"Crawl error for {url}: {e}")
        return discovered[:max_pages]

    def validate_url(self, url: str) -> Tuple[bool, str]:
        if not url:
            return False, "URL is required"
        parsed = urlparse(url)
        if parsed.scheme not in ['http', 'https']:
            return False, "URL must use http:// or https:// scheme"
        if not parsed.netloc:
            return False, "Invalid URL format"
        hostname = parsed.hostname
        if hostname:
            blocked = [
                r'^127\.', r'^10\.', r'^172\.(1[6-9]|2[0-9]|3[01])\.',
                r'^192\.168\.', r'^0\.0\.0\.0$', r'^localhost$',
                r'^::1$', r'^fc00:', r'^fe80:'
            ]
            for pattern in blocked:
                if re.match(pattern, hostname, re.IGNORECASE):
                    return False, "Scanning internal/private addresses is not allowed"
        clean_url = urlunparse((
            parsed.scheme, parsed.netloc, parsed.path or '/',
            parsed.params, parsed.query, ''
        ))
        return True, clean_url

    def _ssl_evidence(self, title: str, hostname: str, port: int,
                      cert: Optional[dict], cipher: Optional[tuple],
                      protocol: Optional[str],
                      extra_fields: Optional[List[Tuple[str, str]]] = None,
                      error: Optional[str] = None,
                      plain_english_summary: Optional[str] = None) -> str:
        """
        Forensic-quality SSL/TLS evidence block.
        Includes full socket handshake details, complete certificate fields,
        SAN list, cipher suite breakdown, finding-specific extras,
        and a plain-language summary.
        """
        sep  = "=" * 60
        sep2 = "-" * 40
        lines = [sep, f"EVIDENCE: {title}", sep, ""]

        # ── WHAT WE DID ──────────────────────────────────────────────────
        lines += [
            "WHAT WE DID",
            sep2,
            f"  We opened a raw TLS socket connection to {hostname}:{port} and",
            f"  inspected the cryptographic handshake, server certificate, and",
            f"  negotiated cipher suite for security weaknesses.",
            f"  Scan Timestamp  : {now_eat_iso()}",
            "",
        ]

        # ── CONNECTION ───────────────────────────────────────────────────
        lines += [
            "CONNECTION",
            sep2,
            f"  Test Method     : Raw TLS socket handshake (ssl.SSLContext)",
            f"  Target Hostname : {hostname}",
            f"  Target Port     : {port}",
            "",
        ]

        # ── TLS HANDSHAKE ────────────────────────────────────────────────
        lines += ["TLS HANDSHAKE", sep2]
        if error:
            lines.append(f"  Handshake Result : FAILED")
            lines.append(f"  Error            : {error}")
            lines.append(f"  Meaning          : The browser would show a security warning for this site.")
        else:
            lines.append(f"  Handshake Result : SUCCESS — TLS connection established")
        if protocol:
            proto_note = {
                'TLSv1.3': 'Current best standard — excellent',
                'TLSv1.2': 'Acceptable — widely supported',
                'TLSv1.1': 'DEPRECATED — should be disabled',
                'TLSv1':   'DEPRECATED — vulnerable to BEAST attack',
                'SSLv3':   'BROKEN — vulnerable to POODLE attack',
                'SSLv2':   'BROKEN — critically insecure',
            }.get(protocol, 'Unknown protocol version')
            lines.append(f"  Negotiated Protocol : {protocol} ({proto_note})")
        if cipher:
            cipher_note = "strong" if any(x in cipher[0].upper() for x in ['GCM', 'CHACHA', 'POLY']) else "potentially weak — review"
            lines.append(f"  Cipher Suite        : {cipher[0]} ({cipher_note})")
            lines.append(f"  Cipher Protocol     : {cipher[1] if len(cipher) > 1 else 'unknown'}")
            lines.append(f"  Key Bits            : {cipher[2] if len(cipher) > 2 else 'unknown'}")
        lines.append("")

        # ── CERTIFICATE ──────────────────────────────────────────────────
        if cert:
            lines += ["CERTIFICATE", sep2]
            subject = cert.get('subject', ())
            subject_str = ", ".join(
                f"{k}={v}" for fields in subject for k, v in (fields if isinstance(fields[0], tuple) else [fields])
            ) if subject else "unknown"
            lines.append(f"  Subject          : {subject_str}")

            issuer = cert.get('issuer', ())
            issuer_str = ", ".join(
                f"{k}={v}" for fields in issuer for k, v in (fields if isinstance(fields[0], tuple) else [fields])
            ) if issuer else "unknown"
            lines.append(f"  Issuer           : {issuer_str}")

            not_before = cert.get('notBefore', 'unknown')
            not_after  = cert.get('notAfter',  'unknown')
            lines.append(f"  Serial Number    : {cert.get('serialNumber', 'unknown')}")
            lines.append(f"  Valid From       : {not_before}")
            lines.append(f"  Valid Until      : {not_after}")

            # Validity window in days
            try:
                expiry = datetime.strptime(not_after, '%b %d %H:%M:%S %Y %Z')
                days_left = (expiry - now_eat_naive()).days
                if days_left < 0:
                    lines.append(f"  Validity Status  : EXPIRED {abs(days_left)} days ago")
                elif days_left < 30:
                    lines.append(f"  Validity Status  : Expiring in {days_left} days — renew now")
                else:
                    lines.append(f"  Validity Status  : Valid for {days_left} more days")
            except Exception:
                pass

            san_list = cert.get('subjectAltName', [])
            if san_list:
                lines.append(f"  Subject Alt Names ({len(san_list)} domains covered):")
                for san_type, san_val in san_list[:10]:
                    lines.append(f"    {san_type}: {san_val}")
                if len(san_list) > 10:
                    lines.append(f"    ... and {len(san_list) - 10} more")
            else:
                lines.append("  Subject Alt Names: None")

            ocsp = cert.get('OCSP', [])
            if ocsp:
                lines.append(f"  OCSP URLs        : {', '.join(ocsp)}")
            ca_issuers = cert.get('caIssuers', [])
            if ca_issuers:
                lines.append(f"  CA Issuers       : {', '.join(ca_issuers)}")
            lines.append("")

        # ── FINDING DETAILS ───────────────────────────────────────────
        if extra_fields:
            lines += ["FINDING DETAILS", sep2]
            for k, v in extra_fields:
                lines.append(f"  {k:<35}: {v if v is not None and v != '' else 'Not observed'}")
            lines.append("")

        # ── PLAIN-LANGUAGE SUMMARY ────────────────────────────────────
        if plain_english_summary:
            lines += ["PLAIN-LANGUAGE SUMMARY", sep2,
                      f"  {plain_english_summary}", ""]

        return "\n".join(lines)

    # ── SSL/TLS ───────────────────────────────────────────────────────────

    def check_ssl_tls(self, url: str) -> Dict:
        result = {
            'status': 'unknown', 'https': False,
            'certificate': {}, 'vulnerabilities': [],
            'protocols': [], 'grade': 'F'
        }

        parsed = urlparse(url)
        if parsed.scheme != 'https':
            result['status'] = 'insecure'
            result['vulnerabilities'].append(Vulnerability(
                name="Insecure HTTP",
                severity=Severity.HIGH,
                description="Site does not use HTTPS encryption",
                evidence=self._ssl_evidence(
                    title="Insecure HTTP — No Transport Encryption",
                    hostname=parsed.hostname or url,
                    port=parsed.port or 80,
                    cert=None, cipher=None, protocol=None,
                    extra_fields=[
                        ("Request URL",       url),
                        ("Observed Scheme",   parsed.scheme.upper()),
                        ("Expected Scheme",   "HTTPS"),
                        ("Observed Result",   "Site served over HTTP — all traffic is unencrypted plaintext"),
                        ("HSTS Present",      "No — no HTTPS to enforce"),
                        ("Upgrade Path",      "Obtain a TLS cert (Let's Encrypt is free) and redirect HTTP to HTTPS"),
                    ],
                    plain_english_summary=(
                        "Your website is using plain HTTP, which means everything sent between "
                        "a visitor and your server — including passwords and session cookies — "
                        "can be read by anyone on the same network (e.g. coffee shop Wi-Fi). "
                        "Switching to HTTPS with a free Let's Encrypt certificate fixes this completely."
                    )
                ),
                remediation="Enable HTTPS and redirect all HTTP traffic to HTTPS. Most hosting providers offer free Let's Encrypt certificates.",
                what_we_tested="We checked whether the site is served over HTTPS.",
                indicates="Use of HTTP means all data between the visitor and your server travels in plaintext.",
                how_exploited="Anyone on the same Wi-Fi network (café, airport) can read and modify traffic, steal passwords and session cookies."
            ))
            return result

        result['https'] = True
        hostname = parsed.hostname
        port     = parsed.port or 443

        try:
            context = ssl.create_default_context(cafile=certifi.where())
            context.check_hostname = True
            context.verify_mode    = ssl.CERT_REQUIRED

            with socket.create_connection((hostname, port), timeout=self.timeout) as sock:
                with context.wrap_socket(sock, server_hostname=hostname) as ssock:
                    cert     = ssock.getpeercert()
                    cipher   = ssock.cipher()
                    protocol = ssock.version()

                    result['protocols'].append(protocol)
                    result['certificate'] = {
                        'subject':          cert.get('subject'),
                        'issuer':           cert.get('issuer'),
                        'not_after':        cert.get('notAfter'),
                        'not_before':       cert.get('notBefore'),
                        'serial_number':    cert.get('serialNumber'),
                        'subject_alt_name': cert.get('subjectAltName', []),
                        'cipher':           cipher[0] if cipher else 'unknown'
                    }

                    not_after = cert.get('notAfter')
                    if not_after:
                        expiry_date       = datetime.strptime(not_after, '%b %d %H:%M:%S %Y %Z')
                        days_until_expiry = (expiry_date - now_eat_naive()).days
                        if days_until_expiry < 0:
                            result['vulnerabilities'].append(Vulnerability(
                                name="Expired SSL Certificate",
                                severity=Severity.CRITICAL,
                                description=f"Certificate expired {abs(days_until_expiry)} days ago",
                                evidence=self._ssl_evidence(
                                    title="Expired SSL/TLS Certificate",
                                    hostname=hostname or '', port=port,
                                    cert=cert, cipher=cipher, protocol=protocol,
                                    extra_fields=[
                                        ("Days Since Expiry",  str(abs(days_until_expiry))),
                                        ("Expiry Date",        not_after),
                                        ("Current Date",       now_eat_iso()[:10]),
                                        ("Observed Result",    f"Certificate EXPIRED {abs(days_until_expiry)} days ago — browsers block this site"),
                                        ("Browser Behaviour",  "NET::ERR_CERT_DATE_INVALID shown to all visitors"),
                                    ],
                                    plain_english_summary=(
                                        f"Your security certificate expired {abs(days_until_expiry)} days ago. "
                                        "Visitors now see a browser warning saying 'Your connection is not private' "
                                        "and most will leave immediately. Renew the certificate today — "
                                        "certbot (Let's Encrypt) does this for free and can auto-renew."
                                    )
                                ),
                                remediation="Renew your SSL certificate immediately. Use certbot (Let's Encrypt) for a free certificate.",
                                what_we_tested="We checked the SSL/TLS certificate expiry date.",
                                indicates="An expired certificate breaks HTTPS and shows security warnings to all visitors.",
                                how_exploited="Visitors see browser security errors; some ignore them, leaving data exposed."
                            ))
                        elif days_until_expiry < 30:
                            result['vulnerabilities'].append(Vulnerability(
                                name="SSL Certificate Expiring Soon",
                                severity=Severity.MEDIUM,
                                description=f"Certificate expires in {days_until_expiry} days",
                                evidence=self._ssl_evidence(
                                    title="SSL/TLS Certificate Expiring Soon",
                                    hostname=hostname or '', port=port,
                                    cert=cert, cipher=cipher, protocol=protocol,
                                    extra_fields=[
                                        ("Days Remaining",   str(days_until_expiry)),
                                        ("Expiry Date",      not_after),
                                        ("Current Date",     now_eat_iso()[:10]),
                                        ("Observed Result",  f"Certificate expires in {days_until_expiry} days"),
                                        ("Auto-Renewal Cmd", "certbot renew --deploy-hook 'systemctl reload nginx'"),
                                    ],
                                    plain_english_summary=(
                                        f"Your certificate has {days_until_expiry} days left before it expires. "
                                        "Once it expires your site will show security warnings to all visitors. "
                                        "Renew now and set up automatic renewal so this never happens again."
                                    )
                                ),
                                remediation="Renew the certificate now. Set up auto-renewal with certbot to avoid this in future.",
                                what_we_tested="We checked the SSL/TLS certificate expiry date.",
                                indicates="Certificate will soon expire, losing HTTPS protection.",
                                how_exploited="Expired certs cause browser warnings; users may abandon the site or be vulnerable to MITM."
                            ))

                    if protocol in ['SSLv2', 'SSLv3', 'TLSv1', 'TLSv1.1']:
                        result['vulnerabilities'].append(Vulnerability(
                            name=f"Weak TLS Protocol ({protocol})",
                            severity=Severity.HIGH,
                            description=f"Server uses outdated protocol {protocol}",
                            evidence=self._ssl_evidence(
                                title=f"Weak TLS Protocol Accepted — {protocol}",
                                hostname=hostname or '', port=port,
                                cert=cert, cipher=cipher, protocol=protocol,
                                extra_fields=[
                                    ("Negotiated Protocol", protocol),
                                    ("Expected Protocols",  "TLSv1.2 or TLSv1.3 only"),
                                    ("Known Attacks",       "BEAST (TLS 1.0), POODLE (SSL 3.0), DROWN (SSL 2.0)"),
                                    ("CVEs",                "CVE-2014-3566 (POODLE), CVE-2011-3389 (BEAST)"),
                                    ("Observed Result",     f"Server completed a full {protocol} handshake — deprecated protocol accepted"),
                                    ("Fix (Nginx)",         "ssl_protocols TLSv1.2 TLSv1.3;"),
                                    ("Fix (Apache)",        "SSLProtocol -all +TLSv1.2 +TLSv1.3"),
                                ],
                                plain_english_summary=(
                                    f"Your server still accepts {protocol}, an old and insecure encryption standard. "
                                    "This is like using a lock that's known to be broken. Attackers with network access "
                                    "can potentially decrypt traffic. Disabling old protocols in your server config "
                                    "takes less than 5 minutes and fixes this completely."
                                )
                            ),
                            remediation="Disable TLS 1.0 and 1.1 in your server config. Allow only TLS 1.2 and 1.3.",
                            what_we_tested="We checked which TLS protocol version the server negotiates.",
                            indicates=f"{protocol} is considered broken and vulnerable to known attacks.",
                            how_exploited="BEAST and POODLE attacks can decrypt traffic encrypted with older protocols."
                        ))

                    if cipher:
                        cipher_name  = cipher[0].upper()
                        weak_ciphers = ['RC4', 'DES', '3DES', 'NULL', 'EXPORT', 'MD5', 'ANON']
                        if any(w in cipher_name for w in weak_ciphers):
                            matched_weak = next((w for w in weak_ciphers if w in cipher_name), 'unknown')
                            result['vulnerabilities'].append(Vulnerability(
                                name=f"Weak Cipher Suite ({cipher[0]})",
                                severity=Severity.HIGH,
                                description=f"Server is using a weak or broken cipher: {cipher[0]}",
                                evidence=self._ssl_evidence(
                                    title=f"Weak Cipher Suite Negotiated — {cipher[0]}",
                                    hostname=hostname or '', port=port,
                                    cert=cert, cipher=cipher, protocol=protocol,
                                    extra_fields=[
                                        ("Negotiated Cipher",    cipher[0]),
                                        ("Key Bits",             str(cipher[2]) if len(cipher) > 2 else 'unknown'),
                                        ("Weak Pattern Matched", matched_weak),
                                        ("Why It's Weak",        f"{matched_weak} is considered cryptographically broken"),
                                        ("Recommended Ciphers",  "TLS_AES_256_GCM_SHA384, TLS_CHACHA20_POLY1305_SHA256, ECDHE-RSA-AES256-GCM-SHA384"),
                                        ("Observed Result",      f"Server negotiated {cipher[0]} — considered weak"),
                                        ("Fix (Nginx)",          "ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:...;"),
                                        ("Fix (Apache)",         "SSLCipherSuite HIGH:!aNULL:!MD5:!3DES:!RC4"),
                                    ],
                                    plain_english_summary=(
                                        f"The encryption algorithm your server uses ({cipher[0]}) has known weaknesses. "
                                        "An attacker who records your encrypted traffic could potentially decrypt it later. "
                                        "Updating your server's cipher list to modern standards (AES-GCM or ChaCha20) "
                                        "is a configuration change that takes minutes to apply."
                                    )
                                ),
                                remediation="Update your TLS configuration to use only strong cipher suites (AES-GCM, ChaCha20).",
                                what_we_tested="We inspected the cipher suite negotiated by the server.",
                                indicates="Weak ciphers can be cracked, exposing encrypted traffic.",
                                how_exploited="An attacker who records encrypted traffic can decrypt it offline using known attacks against the weak cipher."
                            ))

                    result['grade']  = 'A+' if protocol == 'TLSv1.3' else 'A' if protocol == 'TLSv1.2' else 'C'
                    result['status'] = 'secure'

        except ssl.SSLCertVerificationError as e:
            result['status'] = 'untrusted'
            result['vulnerabilities'].append(Vulnerability(
                name="Untrusted or Self-Signed SSL Certificate",
                severity=Severity.HIGH,
                description="Certificate cannot be verified by a trusted authority",
                evidence=self._ssl_evidence(
                    title="Untrusted / Self-Signed SSL Certificate",
                    hostname=hostname or '', port=port,
                    cert=None, cipher=None, protocol=None,
                    error=str(e),
                    extra_fields=[
                        ("Validation Error",   str(e)),
                        ("Trust Chain",        "Could not be chained to any trusted certificate authority"),
                        ("Browser Behaviour",  "NET::ERR_CERT_AUTHORITY_INVALID / 'Your connection is not private'"),
                        ("Observed Result",    "Certificate rejected by system trust store — cannot be verified"),
                        ("Fix",                "Replace with a certificate from Let's Encrypt (free) or a trusted CA"),
                    ],
                    plain_english_summary=(
                        "Your site's security certificate was not issued by a recognised authority, "
                        "so browsers refuse to trust it. Every visitor sees a 'Your connection is not private' "
                        "warning and must click through a scary warning page to continue. "
                        "Replace it with a free Let's Encrypt certificate to fix this immediately."
                    )
                ),
                remediation="Replace the self-signed certificate with one from a trusted CA (e.g. Let's Encrypt — it's free).",
                what_we_tested="We tried to verify the server certificate against trusted certificate authorities.",
                indicates="Visitors see 'Your connection is not private' warnings.",
                how_exploited="Users who click through the warning are vulnerable to MITM attacks."
            ))
        except ssl.SSLError as e:
            result['status'] = 'error'
            result['vulnerabilities'].append(Vulnerability(
                name="SSL Certificate Error",
                severity=Severity.HIGH,
                description="SSL certificate validation failed",
                evidence=self._ssl_evidence(
                    title="SSL/TLS Handshake Failed",
                    hostname=hostname or '', port=port,
                    cert=None, cipher=None, protocol=None,
                    error=str(e),
                    extra_fields=[
                        ("SSL Error",         str(e)),
                        ("Observed Result",   "TLS handshake did not complete — HTTPS is broken"),
                        ("Common Causes",     "Mismatched hostname, broken cert chain, unsupported protocol"),
                        ("Fix",               "Verify certificate CN/SAN matches hostname; ensure full chain installed"),
                    ],
                    plain_english_summary=(
                        "The HTTPS connection could not be established due to a certificate error. "
                        "This typically means the certificate is for a different domain name, "
                        "the certificate chain is incomplete, or the certificate itself is malformed."
                    )
                ),
                remediation="Fix SSL certificate configuration (valid cert, correct chain, matching hostname).",
                what_we_tested="We attempted to establish an HTTPS connection and validate the server certificate.",
                indicates="Broken HTTPS; visitors cannot connect securely.",
                how_exploited="Browsers may block the site entirely or show warnings that users dismiss."
            ))
        except Exception as e:
            result['status'] = 'error'
            logger.error(f"SSL check error: {e}")

        return result

    # ── Security Headers ──────────────────────────────────────────────────

    def check_security_headers(self, response: requests.Response) -> Dict:
        result = {
            'present': [], 'missing': [], 'misconfigured': [],
            'score': 0, 'max_score': 0
        }
        headers = {k.lower(): v for k, v in response.headers.items()}

        for header, config in self.security_headers.items():
            header_lower = header.lower()
            result['max_score'] += (3 if config['required'] else 1)

            if header_lower in headers:
                value = headers[header_lower]
                result['present'].append({
                    'name':  header,
                    'value': value[:100] + '...' if len(value) > 100 else value
                })

                if header == 'X-Frame-Options':
                    if value.upper() not in ['DENY', 'SAMEORIGIN']:
                        result['misconfigured'].append({
                            'header':  header,
                            'issue':   'Invalid value — should be DENY or SAMEORIGIN',
                            'current': value
                        })
                    else:
                        result['score'] += 3

                elif header == 'Content-Security-Policy':
                    issues = []
                    if "unsafe-inline" in value:
                        issues.append("'unsafe-inline' negates XSS protection")
                    if "unsafe-eval" in value:
                        issues.append("'unsafe-eval' allows arbitrary script execution")
                    if re.search(r"script-src\s+['\"]?\*", value):
                        issues.append("wildcard script-src allows any script source")
                    if issues:
                        result['misconfigured'].append({
                            'header':  header,
                            'issue':   '; '.join(issues),
                            'current': value[:80] + '...' if len(value) > 80 else value
                        })
                        result['score'] += 1
                    else:
                        result['score'] += 3

                elif header == 'Strict-Transport-Security':
                    max_age_match = re.search(r'max-age\s*=\s*(\d+)', value, re.I)
                    if max_age_match:
                        max_age = int(max_age_match.group(1))
                        if max_age < 31536000:
                            result['misconfigured'].append({
                                'header':  header,
                                'issue':   f'max-age={max_age} is less than 1 year (31536000).',
                                'current': value
                            })
                            result['score'] += 2
                        else:
                            result['score'] += 3
                    else:
                        result['misconfigured'].append({
                            'header': header, 'issue': 'Missing max-age directive',
                            'current': value
                        })
                else:
                    result['score'] += 3 if config['required'] else 1
            else:
                if config['required']:
                    result['missing'].append({
                        'name':        header,
                        'description': config['description'],
                        'severity':    config['severity'].value
                    })

        result['percentage'] = round(
            (result['score'] / result['max_score']) * 100, 1
        ) if result['max_score'] > 0 else 0
        return result

    # ── CORS Misconfiguration ─────────────────────────────────────────────

    def check_cors(self, url: str) -> List[Vulnerability]:
        vulnerabilities = []
        evil_origin  = "https://evil-attacker.com"
        sent_headers = {'Origin': evil_origin, 'User-Agent': self.session.headers['User-Agent']}

        # Also test preflight OPTIONS to capture full CORS policy
        preflight_result = "Not tested"
        try:
            preflight = self.session.options(
                url, timeout=self.timeout, verify=False,
                headers={
                    'Origin': evil_origin,
                    'Access-Control-Request-Method': 'GET',
                    'Access-Control-Request-Headers': 'Authorization',
                }
            )
            pf_acao = preflight.headers.get('Access-Control-Allow-Origin', '(not set)')
            pf_acam = preflight.headers.get('Access-Control-Allow-Methods', '(not set)')
            pf_acah = preflight.headers.get('Access-Control-Allow-Headers', '(not set)')
            pf_acac = preflight.headers.get('Access-Control-Allow-Credentials', '(not set)')
            pf_acma = preflight.headers.get('Access-Control-Max-Age', '(not set)')
            preflight_result = (
                f"HTTP {preflight.status_code} — "
                f"Allow-Origin: {pf_acao} | "
                f"Allow-Methods: {pf_acam} | "
                f"Allow-Credentials: {pf_acac}"
            )
            preflight_detail = (
                f"    Status              : {preflight.status_code} {preflight.reason}\n"
                f"    Allow-Origin        : {pf_acao}\n"
                f"    Allow-Methods       : {pf_acam}\n"
                f"    Allow-Headers       : {pf_acah}\n"
                f"    Allow-Credentials   : {pf_acac}\n"
                f"    Max-Age             : {pf_acma}"
            )
        except Exception:
            preflight_detail = "    (OPTIONS preflight request failed or not supported)"

        try:
            resp = self.session.get(url, timeout=self.timeout, verify=False,
                                    headers={'Origin': evil_origin})
            acao = resp.headers.get('Access-Control-Allow-Origin', '')
            acac = resp.headers.get('Access-Control-Allow-Credentials', '')
            acam = resp.headers.get('Access-Control-Allow-Methods', '')
            acah = resp.headers.get('Access-Control-Allow-Headers', '')

            if acao == '*':
                vulnerabilities.append(Vulnerability(
                    name="CORS Wildcard Origin",
                    severity=Severity.MEDIUM,
                    description="Server allows any website to make cross-origin requests (Access-Control-Allow-Origin: *)",
                    evidence=self._build_evidence(
                        title="CORS Wildcard Policy",
                        request_url=url, method="GET",
                        request_headers=sent_headers,
                        response=resp,
                        extra_fields=[
                            ("Test: Injected Origin Header",           evil_origin),
                            ("Result: Access-Control-Allow-Origin",    acao),
                            ("Result: Access-Control-Allow-Credentials", acac or "Not present"),
                            ("Result: Access-Control-Allow-Methods",   acam or "Not present"),
                            ("Result: Access-Control-Allow-Headers",   acah or "Not present"),
                            ("Preflight OPTIONS Result",               preflight_result),
                            ("Preflight Detail",                       "\n" + preflight_detail),
                            ("Observed Result",
                             "Wildcard ACAO returned — any website can read this server's responses"),
                            ("What This Means",
                             "A malicious website at evil.com CAN read responses from this server on behalf of logged-in users"),
                        ],
                        plain_english_summary=(
                            "We pretended to be a malicious website and asked your server for data. "
                            "Your server said 'yes, anyone can access me' (Access-Control-Allow-Origin: *). "
                            "This means any website a visitor opens could silently fetch data from your "
                            "server using that visitor's login session. Fix: replace '*' with your "
                            "exact trusted domain name."
                        )
                    ),
                    remediation="Restrict CORS to specific trusted origins. Replace '*' with your exact frontend domain.",
                    cvss_score=5.3,
                    what_we_tested="We sent a cross-origin request with a fake Origin header and inspected the CORS response.",
                    indicates="Any website can make API requests on behalf of your users.",
                    how_exploited="A malicious site reads your API responses, leaking private user data from authenticated endpoints."
                ))

            elif acao == evil_origin:
                if acac.lower() == 'true':
                    vulnerabilities.append(Vulnerability(
                        name="CORS Misconfiguration — Origin Reflection with Credentials",
                        severity=Severity.CRITICAL,
                        description="Server reflects any Origin and allows credentials, enabling cross-origin authenticated attacks",
                        evidence=self._build_evidence(
                            title="CORS Origin Reflection + Credentials",
                            request_url=url, method="GET",
                            request_headers=sent_headers,
                            response=resp,
                            extra_fields=[
                                ("Test: Injected Origin Header",           evil_origin),
                                ("Result: Access-Control-Allow-Origin",    acao),
                                ("Result: Access-Control-Allow-Credentials", acac),
                                ("Result: Access-Control-Allow-Methods",   acam or "Not present"),
                                ("Preflight OPTIONS Result",               preflight_result),
                                ("Preflight Detail",                       "\n" + preflight_detail),
                                ("Observed Result",
                                 "CRITICAL: Server reflected untrusted origin AND allowed credentials"),
                                ("What This Means",
                                 "Attacker site can make authenticated API calls AS the victim, reading private data or performing actions"),
                            ],
                            plain_english_summary=(
                                "This is a critical finding. We sent a request pretending to be from evil-attacker.com "
                                "and your server responded saying 'yes, evil-attacker.com can make requests — "
                                "and it can send cookies too.' This means a malicious website can silently perform "
                                "actions on your site as any logged-in user — reading their private data, changing "
                                "their settings, or making purchases on their behalf."
                            )
                        ),
                        remediation="Never reflect the Origin header blindly. Maintain a strict whitelist of allowed origins.",
                        cvss_score=9.3,
                        what_we_tested="We sent a request with a fake Origin header and checked if the server reflected it alongside Allow-Credentials: true.",
                        indicates="Any site can make authenticated requests to your API as the logged-in user.",
                        how_exploited="A malicious site makes API calls that are sent with the victim's cookies, reading private data or performing actions on their behalf."
                    ))
                else:
                    vulnerabilities.append(Vulnerability(
                        name="CORS Misconfiguration — Origin Reflection",
                        severity=Severity.MEDIUM,
                        description="Server reflects arbitrary Origin headers in CORS responses",
                        evidence=self._build_evidence(
                            title="CORS Origin Reflection",
                            request_url=url, method="GET",
                            request_headers=sent_headers,
                            response=resp,
                            extra_fields=[
                                ("Test: Injected Origin Header",           evil_origin),
                                ("Result: Access-Control-Allow-Origin",    acao),
                                ("Result: Access-Control-Allow-Credentials", acac or "Not present — unauthenticated cross-origin only"),
                                ("Result: Access-Control-Allow-Methods",   acam or "Not present"),
                                ("Preflight OPTIONS Result",               preflight_result),
                                ("Preflight Detail",                       "\n" + preflight_detail),
                                ("Observed Result",
                                 "Server reflected untrusted origin — unauthenticated cross-origin access possible"),
                                ("What This Means",
                                 "Any website can read unauthenticated responses from this server. Credentials (cookies) are not sent."),
                            ],
                            plain_english_summary=(
                                "Your server is echoing back whatever 'Origin' header it receives. "
                                "We sent a fake origin (evil-attacker.com) and the server confirmed access for it. "
                                "While cookies are not sent in this case, unauthenticated API data "
                                "can still be read by any website. Fix: use a strict allowlist of trusted domains."
                            )
                        ),
                        remediation="Maintain a strict whitelist of allowed origins instead of reflecting the request Origin.",
                        cvss_score=6.1,
                        what_we_tested="We sent a request with a fake Origin header and checked if the server reflected it.",
                        indicates="Any website can make cross-origin requests to your server.",
                        how_exploited="Malicious sites can read responses from your unauthenticated API endpoints."
                    ))
        except Exception as e:
            logger.debug(f"CORS check error: {e}")
        return vulnerabilities

    # ── Open Redirect ─────────────────────────────────────────────────────

    def check_open_redirect(self, url: str) -> List[Vulnerability]:
        vulnerabilities = []
        parsed          = urlparse(url)
        redirect_params = ['redirect', 'redirect_to', 'url', 'next', 'return',
                           'returnUrl', 'return_url', 'goto', 'destination', 'redir']

        for param in redirect_params:
            for payload in self.redirect_payloads[:2]:
                test_query = urlencode({param: payload})
                test_url   = urlunparse((
                    parsed.scheme, parsed.netloc, parsed.path, '', test_query, ''
                ))
                sent_headers = dict(self.session.headers)
                try:
                    resp = self.session.get(test_url, timeout=self.timeout,
                                            verify=False, allow_redirects=False)
                    if resp.status_code in (301, 302, 303, 307, 308):
                        location = resp.headers.get('Location', '')
                        if 'evil' in location or location.startswith('//') or 'evil-attacker' in location:
                            # Try to follow the redirect chain for additional context
                            redirect_chain = [f"Step 1: {resp.status_code} -> {location}"]
                            try:
                                chain_resp = self.session.get(
                                    test_url, timeout=self.timeout, verify=False,
                                    allow_redirects=True, max_redirects=3
                                )
                                if chain_resp.history and len(chain_resp.history) > 1:
                                    for i, hr in enumerate(chain_resp.history[1:], 2):
                                        redirect_chain.append(
                                            f"Step {i}: {hr.status_code} -> {hr.headers.get('Location','?')}"
                                        )
                                redirect_chain.append(f"Final URL: {chain_resp.url}")
                            except Exception:
                                pass

                            chain_str = "\n    ".join(redirect_chain)

                            vulnerabilities.append(Vulnerability(
                                name="Open Redirect",
                                severity=Severity.MEDIUM,
                                description=f"Parameter '{param}' can redirect users to external malicious sites",
                                evidence=self._build_evidence(
                                    title="Open Redirect Validation",
                                    request_url=test_url, method="GET",
                                    request_headers=sent_headers,
                                    response=resp,
                                    extra_fields=[
                                        ("Parameter Tested",        param),
                                        ("Injected Payload",        payload),
                                        ("Response Status",         f"{resp.status_code} {resp.reason}"),
                                        ("Location Header",         location or "Not present"),
                                        ("Destination Controlled",  "YES — destination is an attacker-controlled external URL"),
                                        ("Redirect Chain",          "\n    " + chain_str),
                                        ("All Redirect Headers",    resp.headers.get('Location', 'none')),
                                        ("Observed Result",
                                         f"Application issued HTTP {resp.status_code} redirect to external URL we supplied"),
                                        ("What This Means",
                                         f"An attacker crafts: {parsed.scheme}://{parsed.netloc}{parsed.path}?{param}=https://phishing.com — victim trusts your domain and gets sent to attacker"),
                                    ],
                                    plain_english_summary=(
                                        f"Your site redirects users to whatever URL is placed in the '{param}' parameter "
                                        "without checking whether it's safe. An attacker can create a link that looks like "
                                        f"it goes to your site ({parsed.netloc}) but immediately sends the visitor to a "
                                        "fake login page. Because the link starts with your trusted domain, users are "
                                        "much more likely to click it and enter their credentials."
                                    )
                                ),
                                remediation="Validate redirect destinations against a whitelist of allowed URLs. Never redirect to user-supplied URLs directly.",
                                cvss_score=6.1,
                                what_we_tested=f"We tested the '{param}' parameter with an external URL as the redirect target.",
                                indicates="Open redirect allows an attacker to use your domain as a launchpad for phishing.",
                                how_exploited="Attacker crafts a link like yoursite.com/login?redirect=evil.com — victim trusts your domain, lands on attacker's site."
                            ))
                            return vulnerabilities
                except Exception:
                    continue
        return vulnerabilities

    # ── Sensitive File Exposure ───────────────────────────────────────────

    def check_sensitive_files(self, base_url: str) -> List[Vulnerability]:
        vulnerabilities = []
        parsed          = urlparse(base_url)
        base            = f"{parsed.scheme}://{parsed.netloc}"
        seen_paths: Set[str] = set()

        def probe(path_info: Tuple) -> Optional[Vulnerability]:
            path, label, severity = path_info
            if path in seen_paths:
                return None
            seen_paths.add(path)
            url = base + path
            try:
                resp         = self.session.get(url, timeout=5, verify=False, allow_redirects=False)
                content_type = resp.headers.get('Content-Type', '')
                content_len  = len(resp.content)

                if resp.status_code == 200:
                    if 'text/html' in content_type and content_len > 50000:
                        return None
                    if severity == Severity.INFO:
                        return None

                    body_type    = _classify_response_body(resp.text, content_type)
                    body_excerpt = _body_preview(resp.text, 600)

                    # Credential pattern detection
                    secret_findings = []
                    secret_patterns = [
                        (r'(?i)(password|passwd|db_pass)\s*[=:]\s*\S+',   'Database Password'),
                        (r'(?i)(secret|secret_key)\s*[=:]\s*["\']?\S+',  'Secret Key'),
                        (r'(?i)(api[_-]?key)\s*[=:]\s*["\']?\S+',        'API Key'),
                        (r'(?i)(token)\s*[=:]\s*["\']?\S+',              'Auth Token'),
                        (r'AKIA[0-9A-Z]{16}',                             'AWS Access Key ID'),
                        (r'-----BEGIN.*PRIVATE KEY-----',                 'Private Key'),
                        (r'ghp_[A-Za-z0-9]{36}',                         'GitHub Token'),
                        (r'(?i)DB_HOST\s*[=:]\s*\S+',                    'Database Host'),
                        (r'(?i)DATABASE_URL\s*[=:]\s*\S+',               'Database URL'),
                    ]
                    for sp_pattern, sp_label in secret_patterns:
                        m = re.search(sp_pattern, resp.text)
                        if m:
                            secret_findings.append(f"{sp_label}: {m.group(0)[:80]}")

                    # Build a rich, structured evidence block
                    sep  = "=" * 60
                    sep2 = "-" * 40
                    ev_lines = [
                        sep,
                        f"EVIDENCE: Exposed Sensitive File — {label}",
                        sep,
                        "",
                        "WHAT WE DID",
                        sep2,
                        f"  We requested the path '{path}' directly from your server.",
                        f"  This file should not be publicly accessible — we confirmed it is.",
                        f"  URL Tested  : {url}",
                        f"  Timestamp   : {now_eat_iso()}",
                        "",
                        "REQUEST",
                        sep2,
                        f"  Method  : GET",
                        f"  URL     : {url}",
                        f"  Headers : Standard browser-like headers (User-Agent, Accept)",
                        "",
                        "RESPONSE",
                        sep2,
                        f"  Status         : {resp.status_code} {resp.reason}",
                        f"  Content-Type   : {content_type or '(not set)'}",
                        f"  Content-Length : {content_len:,} bytes",
                        f"  Body Type      : {body_type}",
                        f"  Server         : {resp.headers.get('Server', '(not disclosed)')}",
                        "",
                        "ALL RESPONSE HEADERS",
                        sep2,
                    ]
                    for k, v in resp.headers.items():
                        ev_lines.append(f"  {k}: {v}")

                    ev_lines += [
                        "",
                        "RESPONSE BODY (first 600 characters)",
                        sep2,
                        f"  {body_excerpt}",
                        "",
                    ]

                    if secret_findings:
                        ev_lines += [
                            "CREDENTIALS / SECRETS DETECTED IN BODY",
                            sep2,
                            "  *** WARNING: Live credentials appear to be present in this file ***",
                        ]
                        for sf in secret_findings:
                            ev_lines.append(f"  >>> {sf}")
                        ev_lines += [
                            "",
                            "  ACTION REQUIRED: Rotate all credentials listed above immediately.",
                            "  Treat them as fully compromised — they have been publicly accessible.",
                            "",
                        ]

                    ev_lines += [
                        "FINDING DETAILS",
                        sep2,
                        f"  File / Path          : {path}",
                        f"  File Type            : {label}",
                        f"  Credentials Found    : {'YES — see above' if secret_findings else 'None detected by pattern matching (manual review recommended)'}",
                        f"  Access Control       : None — file is fully public with no authentication",
                        "",
                        "PLAIN-LANGUAGE SUMMARY",
                        sep2,
                    ]

                    if secret_findings:
                        ev_lines.append(
                            f"  Your file '{path}' is publicly accessible and appears to contain "
                            f"live credentials (passwords, API keys, or secrets). Anyone who visits "
                            f"that URL — including automated scanners — can read these credentials "
                            f"and use them to access your database or third-party services. "
                            f"Delete or block the file immediately, then rotate every credential it contains."
                        )
                    else:
                        ev_lines.append(
                            f"  Your file '{path}' is publicly accessible on the internet. "
                            f"Files like this ({label}) are not meant to be served publicly — "
                            f"they can reveal your server technology, configuration, or source code "
                            f"to attackers who scan for them. Block access via your web server config."
                        )
                    ev_lines.append("")

                    return Vulnerability(
                        name=f"Exposed {label}",
                        severity=severity,
                        description=f"The file or path '{path}' is publicly accessible and should not be",
                        evidence="\n".join(ev_lines),
                        remediation=f"Block access to '{path}' via your server/firewall config, or delete the file if it shouldn't exist.",
                        what_we_tested=f"We requested the path '{path}' to check if it is publicly accessible.",
                        indicates=f"Exposed {label} can leak credentials, source code, configuration, or sensitive data.",
                        how_exploited=f"An attacker downloads the file and extracts database passwords, API keys, or source code."
                    )

                elif resp.status_code in (401, 403) and severity in (Severity.CRITICAL, Severity.HIGH):
                    return Vulnerability(
                        name=f"{label} Exists (Access Restricted)",
                        severity=Severity.LOW,
                        description=f"'{path}' exists on the server but requires authentication",
                        evidence=(
                            f"{'='*60}\n"
                            f"EVIDENCE: Protected Path Exists — {label}\n"
                            f"{'='*60}\n\n"
                            f"WHAT WE DID\n{'-'*40}\n"
                            f"  We requested '{path}' and received an authentication challenge.\n"
                            f"  The path exists, but access is currently protected.\n\n"
                            f"REQUEST\n{'-'*40}\n"
                            f"  Method : GET\n"
                            f"  URL    : {url}\n\n"
                            f"RESPONSE\n{'-'*40}\n"
                            f"  Status           : {resp.status_code} {resp.reason}\n"
                            f"  WWW-Authenticate : {resp.headers.get('WWW-Authenticate', 'not present')}\n\n"
                            f"FINDING DETAILS\n{'-'*40}\n"
                            f"  Observed Result  : Path exists but access is restricted\n"
                            f"  Risk             : Low — currently protected, but confirms technology presence\n\n"
                            f"PLAIN-LANGUAGE SUMMARY\n{'-'*40}\n"
                            f"  The path '{path}' exists on your server and is locked, which is better than\n"
                            f"  being open. However, it confirms this technology is present, and if the lock\n"
                            f"  is ever bypassed or brute-forced, the contents would be exposed.\n"
                        ),
                        remediation=f"Verify this path is intentional. Ensure authentication is robust and consider moving it.",
                        what_we_tested=f"We requested '{path}' to check for its existence.",
                        indicates="The path exists, confirming the technology in use.",
                        how_exploited="If authentication is bypassed or brute-forced, the contents are exposed."
                    )

            except requests.RequestException:
                pass
            except Exception as e:
                logger.debug(f"Probe error for {path}: {e}")
            return None

        with concurrent.futures.ThreadPoolExecutor(max_workers=10) as executor:
            futures = {executor.submit(probe, p): p for p in self.sensitive_paths}
            for future in concurrent.futures.as_completed(futures):
                result = future.result()
                if result:
                    vulnerabilities.append(result)

        return vulnerabilities

    # ── SQL Injection ─────────────────────────────────────────────────────

    def test_sql_injection(self, url: str) -> List[Vulnerability]:
        vulnerabilities = []
        parsed      = urlparse(url)
        base_params = parse_qs(parsed.query)
        test_params = base_params.copy() if base_params else {'id': ['1']}
        found       = False

        # First establish a baseline response for comparison
        baseline_url = url
        baseline_body = ""
        baseline_status = None
        try:
            baseline_resp = self.session.get(baseline_url, timeout=self.timeout, verify=False)
            baseline_body   = baseline_resp.text
            baseline_status = baseline_resp.status_code
        except Exception:
            pass

        with concurrent.futures.ThreadPoolExecutor(max_workers=3) as executor:
            futures = {}
            for param_name in test_params:
                for payload in self.sql_payloads[:5]:
                    p_copy = test_params.copy()
                    p_copy[param_name] = [payload]
                    test_url_built = urlunparse((
                        parsed.scheme, parsed.netloc, parsed.path,
                        parsed.params, urlencode(p_copy, doseq=True), parsed.fragment
                    ))
                    f = executor.submit(self._test_sql_payload_full, test_url_built, payload, param_name)
                    futures[f] = (payload, param_name, test_url_built)

            for future in concurrent.futures.as_completed(futures):
                if found:
                    break
                payload, param_name, tested_url = futures[future]
                try:
                    is_vulnerable, matched_pattern, response = future.result()
                    if is_vulnerable and response is not None:
                        found = True
                        sent_headers = dict(self.session.headers)

                        # Check if baseline and payload response differ meaningfully
                        body_diff_note = "Baseline comparison not available"
                        if baseline_body:
                            if matched_pattern and re.search(matched_pattern, response.text, re.I):
                                body_diff_note = "Error string NOT present in baseline — confirms injection caused the error"
                            else:
                                body_diff_note = "See body excerpt above for the matched error string"

                        # Extract just the matching error snippet for clarity
                        error_snippet = ""
                        if matched_pattern:
                            m = re.search(matched_pattern, response.text, re.I)
                            if m:
                                start = max(0, m.start() - 80)
                                end   = min(len(response.text), m.end() + 80)
                                error_snippet = f"...{response.text[start:end].strip()}..."

                        vulnerabilities.append(Vulnerability(
                            name="SQL Injection (Error-Based)",
                            severity=Severity.CRITICAL,
                            description=f"Parameter '{param_name}' is vulnerable to SQL injection — database errors returned",
                            evidence=self._build_evidence(
                                title="SQL Injection — Error-Based",
                                request_url=tested_url, method="GET",
                                request_headers=sent_headers,
                                response=response,
                                extra_fields=[
                                    ("Parameter Tested",              param_name),
                                    ("Injected Payload",              payload),
                                    ("Error Pattern Matched",         matched_pattern),
                                    ("Error Snippet from Body",       error_snippet or "see body above"),
                                    ("Response Status",               f"{response.status_code} {response.reason}"),
                                    ("Baseline URL",                  baseline_url),
                                    ("Baseline Status",               str(baseline_status) if baseline_status else "not captured"),
                                    ("Baseline vs Payload Comparison", body_diff_note),
                                    ("Database Type Indicated",       _infer_db_from_pattern(matched_pattern)),
                                    ("Observed Result",
                                     "Database error string found in response body — SQL injection confirmed"),
                                    ("What This Means",
                                     f"The '{param_name}' parameter is not sanitised — the injected quote character broke the SQL query and the database error was returned to the browser"),
                                ],
                                body_highlight=error_snippet[3:23] if error_snippet and len(error_snippet) > 6 else None,
                                plain_english_summary=(
                                    f"We sent a deliberately broken SQL character (') to the '{param_name}' "
                                    "parameter and the server responded with a database error message. "
                                    "This confirms the application is passing user input directly into "
                                    "database queries without sanitising it first. An attacker can use "
                                    "this to read every record in your database — usernames, passwords, "
                                    "emails, payment data — with freely available tools."
                                )
                            ),
                            remediation="Use parameterized queries or prepared statements. Never concatenate user input into SQL strings.",
                            cvss_score=9.8,
                            what_we_tested=f"We injected SQL syntax into '{param_name}' and looked for database error messages.",
                            indicates="The application executes user input as part of a SQL query — attacker controls the database.",
                            how_exploited="Attacker extracts all data (usernames, passwords, emails), or in some DBs gains OS-level command execution."
                        ))
                except Exception as e:
                    logger.error(f"SQL test error: {e}")

        # Time-based blind
        if not found:
            for param_name in list(test_params.keys())[:2]:
                for payload, db_type in self.blind_sql_payloads:
                    p_copy = test_params.copy()
                    p_copy[param_name] = [payload]
                    test_url_blind = urlunparse((
                        parsed.scheme, parsed.netloc, parsed.path,
                        parsed.params, urlencode(p_copy, doseq=True), parsed.fragment
                    ))
                    safe_params = test_params.copy()
                    safe_params[param_name] = ['1']
                    baseline_url_blind = urlunparse((
                        parsed.scheme, parsed.netloc, parsed.path,
                        parsed.params, urlencode(safe_params, doseq=True), parsed.fragment
                    ))
                    is_blind, delay, baseline_delay = self._test_blind_sql_full(test_url_blind, baseline_url_blind)
                    if is_blind:
                        found = True
                        vulnerabilities.append(Vulnerability(
                            name=f"SQL Injection (Time-Based Blind — {db_type})",
                            severity=Severity.CRITICAL,
                            description=f"Parameter '{param_name}' causes a {delay:.1f}s server delay with a sleep payload, indicating blind SQL injection",
                            evidence=(
                                f"{'='*60}\n"
                                f"EVIDENCE: SQL Injection — Time-Based Blind ({db_type})\n"
                                f"{'='*60}\n\n"
                                f"WHAT WE DID\n{'-'*40}\n"
                                f"  We sent two requests to the same parameter: one with a safe value,\n"
                                f"  and one with a payload that tells the database to pause for 4 seconds.\n"
                                f"  If the server pauses only when we send the sleep payload, the database\n"
                                f"  is executing our injected command — confirming SQL injection.\n\n"
                                f"REQUEST A — Baseline (safe value)\n{'-'*40}\n"
                                f"  Method          : GET\n"
                                f"  URL             : {baseline_url_blind}\n"
                                f"  Parameter Value : {param_name} = 1 (safe, normal input)\n\n"
                                f"REQUEST B — Payload (sleep injection)\n{'-'*40}\n"
                                f"  Method          : GET\n"
                                f"  URL             : {test_url_blind}\n"
                                f"  Parameter Value : {param_name} = {payload}\n\n"
                                f"TIMING COMPARISON\n{'-'*40}\n"
                                f"  Baseline Response Time  : {baseline_delay:.2f}s  (normal speed)\n"
                                f"  Payload Response Time   : {delay:.2f}s  (delayed by sleep command)\n"
                                f"  Observed Delay Delta    : {delay - baseline_delay:.2f}s above baseline\n"
                                f"  Detection Threshold     : 3.5s delay above baseline\n"
                                f"  Conclusion              : THRESHOLD EXCEEDED — sleep function executed in the database\n\n"
                                f"FINDING DETAILS\n{'-'*40}\n"
                                f"  Database Type Indicated : {db_type}\n"
                                f"  Sleep Function Used     : {payload}\n"
                                f"  Parameter Tested        : {param_name}\n"
                                f"  Error Visible           : No — this is 'blind' injection (no error shown, but still exploitable)\n"
                                f"  Observed Result         : Response delayed by {delay:.1f}s — injected sleep executed in DB\n\n"
                                f"PLAIN-LANGUAGE SUMMARY\n{'-'*40}\n"
                                f"  This is SQL injection without visible error messages — called 'blind' injection.\n"
                                f"  When we told the database to sleep for 4 seconds, the server waited exactly that long.\n"
                                f"  A normal request took {baseline_delay:.2f}s. The attack request took {delay:.2f}s.\n"
                                f"  This proves the database is running our commands. Even without seeing any error,\n"
                                f"  automated tools (like sqlmap) can extract your entire database one bit at a time\n"
                                f"  by asking yes/no questions through timing differences.\n"
                            ),
                            remediation="Use parameterized queries. This is a blind injection — no visible error, but fully exploitable.",
                            cvss_score=9.8,
                            what_we_tested=f"We injected time-delay SQL payloads into '{param_name}' and measured response time vs baseline.",
                            indicates="The application executes user input as SQL even with no visible error output.",
                            how_exploited="Tools like sqlmap can extract the entire database character by character using timing differences."
                        ))
                        break
                if found:
                    break

        return vulnerabilities

    def _test_sql_payload_full(self, url: str, payload: str, param: str
                                ) -> Tuple[bool, str, Optional[requests.Response]]:
        """Returns (is_vulnerable, matched_pattern, response_object)."""
        sql_errors = [
            r"SQL syntax.*MySQL", r"Warning.*mysql_.*", r"MySqlClient\.",
            r"PostgreSQL.*ERROR", r"Warning.*pg_.*", r"Npgsql\.",
            r"Driver.*SQL.*Server", r"OLE DB.*SQL.*Server",
            r"Warning.*mssql_.*", r"Exception.*Oracle", r"Oracle error",
            r"Oracle.*Driver", r"Warning.*oci_.*", r"SQLite\.Exception",
            r"System\.Data\.SQLite\.SQLiteException", r"Warning.*sqlite_.*",
            r"\[SQLite_ERROR\]", r"Microsoft Access.*Driver",
            r"JET Database Engine", r"ODBC.*Driver",
            r"Microsoft OLE DB Provider for ODBC Drivers"
        ]
        try:
            response = self.session.get(url, timeout=self.timeout,
                                         allow_redirects=False, verify=False)
            content = response.text
            for pattern in sql_errors:
                if re.search(pattern, content, re.IGNORECASE):
                    return True, pattern, response
            return False, "", response
        except requests.RequestException:
            return False, "", None

    def _test_sql_payload(self, url: str, payload: str, param: str) -> Tuple[bool, str]:
        is_vuln, pattern, _ = self._test_sql_payload_full(url, payload, param)
        return is_vuln, pattern

    def _test_blind_sql_full(self, url: str, baseline_url: str,
                              threshold: float = 3.5) -> Tuple[bool, float, float]:
        """Returns (is_blind, payload_delay, baseline_delay)."""
        try:
            t0       = time.time()
            self.session.get(baseline_url, timeout=8, verify=False)
            baseline = time.time() - t0

            t0    = time.time()
            self.session.get(url, timeout=12, verify=False)
            delay = time.time() - t0

            if delay > threshold and delay > baseline + 2.5:
                return True, delay, baseline
            return False, delay, baseline
        except requests.Timeout:
            return True, threshold + 1, 0.0
        except Exception:
            return False, 0.0, 0.0

    def _test_blind_sql(self, url: str, threshold: float = 3.5) -> Tuple[bool, float]:
        is_blind, delay, _ = self._test_blind_sql_full(url, url, threshold)
        return is_blind, delay

    # ── XSS ──────────────────────────────────────────────────────────────

    def test_xss(self, url: str) -> List[Vulnerability]:
        vulnerabilities = []
        parsed       = urlparse(url)
        test_params  = {'xss_test': ['test']}
        sent_headers = dict(self.session.headers)

        for payload in self.xss_payloads[:6]:
            test_params['xss_test'] = [payload]
            test_url = urlunparse((
                parsed.scheme, parsed.netloc, parsed.path,
                parsed.params, urlencode(test_params), parsed.fragment
            ))
            try:
                response = self.session.get(test_url, timeout=self.timeout, verify=False)
                content  = response.text

                if payload in content:
                    csp       = response.headers.get('Content-Security-Policy', '')
                    mitigated = bool(csp and 'unsafe-inline' not in csp)
                    context   = self._analyze_xss_context(content, payload)
                    severity  = Severity.MEDIUM if mitigated else Severity.HIGH

                    # Check encoding: was payload HTML-encoded in any form?
                    html_encoded_payload = payload.replace('<', '&lt;').replace('>', '&gt;').replace('"', '&quot;')
                    partial_encoded      = html_encoded_payload in content
                    raw_reflected        = payload in content  # already confirmed True
                    encoding_status      = (
                        "Payload reflected RAW — no HTML encoding applied. Script will execute."
                        if raw_reflected and not partial_encoded
                        else "Payload reflected raw AND encoded forms found — encoding is inconsistent."
                    )

                    # Get surrounding HTML context for the injection point
                    injection_context_snippet = ""
                    idx = content.find(payload)
                    if idx > -1:
                        ctx_start = max(0, idx - 120)
                        ctx_end   = min(len(content), idx + len(payload) + 120)
                        injection_context_snippet = content[ctx_start:ctx_end].replace('\n', ' ').strip()

                    vulnerabilities.append(Vulnerability(
                        name=f"Reflected XSS ({context['type']}){' — CSP may mitigate' if mitigated else ''}",
                        severity=severity,
                        description=f"XSS payload reflected in {context['location']}. {'A CSP header is present which may block execution.' if mitigated else 'No CSP is blocking execution.'}",
                        evidence=self._build_evidence(
                            title=f"Reflected Cross-Site Scripting (XSS) — {context['type']}",
                            request_url=test_url, method="GET",
                            request_headers=sent_headers,
                            response=response,
                            extra_fields=[
                                ("Parameter Tested",            "xss_test (synthetic test parameter)"),
                                ("Injected Payload",            payload),
                                ("Payload Found in Response",   "YES — verbatim, unencoded"),
                                ("Encoding Applied",            encoding_status),
                                ("Injection Context Type",      context['type']),
                                ("Context Location",            context['location']),
                                ("Context Detail",              context['details']),
                                ("Surrounding HTML at Injection", f"...{injection_context_snippet}..." if injection_context_snippet else "see body above"),
                                ("CSP Header Present",          f"YES — {csp[:80]}" if csp else "NO — no Content-Security-Policy header"),
                                ("CSP Blocks Execution",        "Possibly (no unsafe-inline)" if mitigated else "NO — payload will execute in browser"),
                                ("Observed Result",             "Payload reflected verbatim in response — XSS confirmed"),
                                ("What This Means",             "Script tags sent as input are echoed back into the page HTML without encoding"),
                            ],
                            body_highlight=payload,
                            plain_english_summary=(
                                f"We sent a script tag as input and your server returned it unchanged in the page. "
                                "When a real user visits a link containing this payload, their browser will execute "
                                "the script — giving an attacker the ability to steal their login session, log "
                                "their keystrokes, or redirect them to a malicious site. "
                                + ("A Content-Security-Policy header is present which may reduce the impact, "
                                   "but the vulnerability itself is still present and should be fixed."
                                   if mitigated else
                                   "There is no Content-Security-Policy header, so the script will execute without restriction.")
                            )
                        ),
                        remediation="HTML-encode all user input before rendering it. Add a strong Content-Security-Policy header.",
                        cvss_score=6.1 if mitigated else 8.8,
                        what_we_tested="We injected script payloads into query parameters and checked if they were reflected unencoded in the HTML.",
                        indicates="User-supplied input is rendered without encoding — a classic reflected XSS vulnerability.",
                        how_exploited="Attacker sends victim a link with the payload in the URL. When the victim loads it, the script runs in their browser, stealing cookies or performing actions as them."
                    ))
                    break
            except requests.RequestException:
                continue

        # DOM XSS sink detection — with actual source context snippets
        try:
            response  = self.session.get(url, timeout=self.timeout, verify=False)
            dom_sinks = {
                'document.write':  'Can write arbitrary HTML — commonly used to inject scripts',
                'innerHTML':       'Assigns HTML directly — a classic XSS injection point',
                'outerHTML':       'Replaces element HTML — same risk as innerHTML',
                'eval(':           'Executes string as code — extremely dangerous with user data',
                'setTimeout(':     'Evaluates string arguments as code',
                'setInterval(':    'Evaluates string arguments as code',
                'location.href':   'Setting from user input can redirect to javascript: URLs',
            }
            content     = response.text
            found_sinks = []
            for sink, desc in dom_sinks.items():
                if sink in content:
                    # Extract a snippet of the source around the sink
                    idx = content.find(sink)
                    start = max(0, idx - 60)
                    end   = min(len(content), idx + len(sink) + 100)
                    snippet = content[start:end].replace('\n', ' ').strip()
                    found_sinks.append((sink, desc, snippet))

            if found_sinks:
                sink_names    = ', '.join(s for s, _, _ in found_sinks[:3])
                sink_detail_lines = []
                for sink, desc, snippet in found_sinks[:5]:
                    sink_detail_lines.append(f"  Sink     : {sink}")
                    sink_detail_lines.append(f"  Risk     : {desc}")
                    sink_detail_lines.append(f"  In Source: ...{snippet}...")
                    sink_detail_lines.append("")
                sink_details = "\n".join(sink_detail_lines)

                vulnerabilities.append(Vulnerability(
                    name="Potential DOM XSS Sink Detected",
                    severity=Severity.MEDIUM,
                    description=f"Page uses JavaScript patterns that can lead to DOM XSS: {sink_names}",
                    evidence=(
                        f"{'='*60}\n"
                        f"EVIDENCE: DOM Cross-Site Scripting (XSS) Sink Detection\n"
                        f"{'='*60}\n\n"
                        f"WHAT WE DID\n{'-'*40}\n"
                        f"  We loaded the page and scanned its JavaScript source code for\n"
                        f"  patterns (called 'sinks') that are dangerous when they receive\n"
                        f"  user-controlled data. A sink is a JavaScript function that can\n"
                        f"  execute code or modify the page if given malicious input.\n\n"
                        f"REQUEST\n{'-'*40}\n"
                        f"  Method  : GET\n"
                        f"  URL     : {url}\n\n"
                        f"RESPONSE\n{'-'*40}\n"
                        f"  Status       : {response.status_code} {response.reason}\n"
                        f"  Content-Type : {response.headers.get('Content-Type', 'not set')}\n"
                        f"  Page Title   : {_extract_title(response.text)}\n\n"
                        f"SINKS DETECTED — WITH SOURCE CONTEXT\n{'-'*40}\n"
                        f"{sink_details}\n"
                        f"  Total dangerous sinks found: {len(found_sinks)}\n\n"
                        f"FINDING DETAILS\n{'-'*40}\n"
                        f"  Sinks Found        : {', '.join(s for s,_,_ in found_sinks)}\n"
                        f"  Observed Result    : Dangerous JavaScript patterns present in page source\n"
                        f"  Exploitability     : Depends on whether any sink receives user-controlled data\n"
                        f"                       (URL parameters, hash fragment, postMessage, localStorage)\n\n"
                        f"PLAIN-LANGUAGE SUMMARY\n{'-'*40}\n"
                        f"  We found {len(found_sinks)} JavaScript function(s) in your page that can be dangerous:\n"
                        f"  {sink_names}.\n"
                        f"  These are not necessarily exploitable by themselves — they only become\n"
                        f"  a vulnerability if they ever receive data that an attacker can control\n"
                        f"  (like a URL parameter or page hash). A developer should review each\n"
                        f"  occurrence and verify it does not use untrusted data.\n"
                    ),
                    remediation="Avoid using dangerous sinks with user-controlled data. Use textContent instead of innerHTML. Sanitize inputs with DOMPurify if HTML is required.",
                    what_we_tested="We scanned the page JavaScript for dangerous DOM manipulation patterns.",
                    indicates="If any of these sinks receive user-controlled data (URL params, hash, postMessage), XSS is possible.",
                    how_exploited="Attacker controls data that flows into a dangerous sink via the URL or other client-side input, causing script execution."
                ))
        except Exception:
            pass

        return vulnerabilities

    def _analyze_xss_context(self, content: str, payload: str) -> Dict:
        idx = content.find(payload)
        if idx == -1:
            return {'type': 'Unknown', 'location': 'Unknown', 'details': 'Not found'}
        before = content[max(0, idx - 50):idx]
        if '<script' in before.lower():
            return {'type': 'Script Context',    'location': 'JavaScript block',
                    'details': 'Inside <script> tag — direct code execution without needing to break out of HTML context'}
        elif '="' in before or "='" in before:
            return {'type': 'Attribute Context', 'location': 'HTML attribute value',
                    'details': 'Inside HTML attribute — event handler injection (e.g. onerror=, onload=) is possible'}
        else:
            return {'type': 'HTML Context',      'location': 'HTML body',
                    'details': 'Reflected directly in page body — script tag injection works without escaping'}

    # ── Information Disclosure ────────────────────────────────────────────

    def check_information_disclosure(self, response: requests.Response, url: str) -> List[Vulnerability]:
        vulnerabilities = []
        headers = response.headers
        content = response.text
        all_headers_str = "\n".join(f"    {k}: {v}" for k, v in response.headers.items())

        server_header = headers.get('Server', '')
        if server_header and any(char.isdigit() for char in server_header):
            vulnerabilities.append(Vulnerability(
                name="Server Version Disclosure",
                severity=Severity.LOW,
                description="Server header reveals exact version information",
                evidence=(
                    f"{'='*60}\n"
                    f"EVIDENCE: Server Version Disclosure\n"
                    f"{'='*60}\n\n"
                    f"WHAT WE DID\n{'-'*40}\n"
                    f"  We made a standard HTTP request and examined the Server header\n"
                    f"  in the response for software name and version number disclosure.\n\n"
                    f"REQUEST\n{'-'*40}\n"
                    f"  Method  : GET\n"
                    f"  URL     : {url}\n\n"
                    f"RESPONSE\n{'-'*40}\n"
                    f"  Status       : {response.status_code} {response.reason}\n"
                    f"  All Headers:\n{all_headers_str}\n\n"
                    f"FINDING DETAILS\n{'-'*40}\n"
                    f"  Server Header Value : {server_header}\n"
                    f"  Version Disclosed   : YES — contains version number\n"
                    f"  Observed Result     : Exact server software version visible in every HTTP response\n\n"
                    f"PLAIN-LANGUAGE SUMMARY\n{'-'*40}\n"
                    f"  Every page your server sends includes a 'Server' header that announces\n"
                    f"  exactly what software is running and its version number ({server_header}).\n"
                    f"  Attackers use this to look up known security vulnerabilities for that\n"
                    f"  exact version. Hiding the version takes one line of config.\n"
                ),
                remediation="Configure your server to omit version details. In Apache: ServerTokens Prod. In Nginx: server_tokens off.",
                what_we_tested="We inspected the Server response header for version numbers.",
                indicates="Exact version info helps attackers look up known CVEs for that specific version.",
                how_exploited="Attacker checks CVE databases for the disclosed version and runs matching exploits."
            ))

        powered_by = headers.get('X-Powered-By', '')
        if powered_by:
            vulnerabilities.append(Vulnerability(
                name="Technology Stack Disclosure",
                severity=Severity.LOW,
                description="X-Powered-By header reveals backend technology and version",
                evidence=(
                    f"{'='*60}\n"
                    f"EVIDENCE: Technology Stack Disclosure\n"
                    f"{'='*60}\n\n"
                    f"WHAT WE DID\n{'-'*40}\n"
                    f"  We examined the X-Powered-By response header, which many frameworks\n"
                    f"  set automatically to identify the backend technology.\n\n"
                    f"REQUEST\n{'-'*40}\n"
                    f"  Method  : GET\n"
                    f"  URL     : {url}\n\n"
                    f"RESPONSE\n{'-'*40}\n"
                    f"  Status       : {response.status_code} {response.reason}\n"
                    f"  All Headers:\n{all_headers_str}\n\n"
                    f"FINDING DETAILS\n{'-'*40}\n"
                    f"  X-Powered-By Value  : {powered_by}\n"
                    f"  Technology Revealed : {powered_by}\n"
                    f"  Observed Result     : Backend framework/version disclosed in every response\n\n"
                    f"PLAIN-LANGUAGE SUMMARY\n{'-'*40}\n"
                    f"  Your server is advertising that it runs '{powered_by}' in every\n"
                    f"  HTTP response. This helps attackers narrow down which exploits to try.\n"
                    f"  Removing this header is a one-line configuration change.\n"
                ),
                remediation="Remove X-Powered-By header. In Express.js: app.disable('x-powered-by'). In PHP: expose_php = Off.",
                what_we_tested="We inspected the X-Powered-By response header.",
                indicates="Technology disclosure helps attackers target framework-specific vulnerabilities.",
                how_exploited="Attacker targets known exploits for the disclosed framework version."
            ))

        sensitive_patterns = [
            (r'AKIA[0-9A-Z]{16}',                                         'AWS Access Key ID',      Severity.CRITICAL),
            (r'aws_secret_access_key\s*[=:]\s*["\']?[A-Za-z0-9/+=]{40}', 'AWS Secret Key',         Severity.CRITICAL),
            (r'sk_live_[0-9a-zA-Z]{24,}',                                 'Stripe Live Secret',     Severity.CRITICAL),
            (r'pk_live_[0-9a-zA-Z]{24,}',                                 'Stripe Publishable Key', Severity.MEDIUM),
            (r'-----BEGIN (RSA |EC |DSA )?PRIVATE KEY-----',              'Private Key',            Severity.CRITICAL),
            (r'password\s*[=:]\s*["\'][^"\']{6,}',                        'Hardcoded Password',     Severity.HIGH),
            (r'api[_-]?key\s*[=:]\s*["\'][^"\']{8,}',                    'API Key',                Severity.HIGH),
            (r'secret[_-]?key\s*[=:]\s*["\'][^"\']{8,}',                 'Secret Key',             Severity.HIGH),
            (r'DB_PASSWORD\s*=\s*\S+',                                    'Database Password',      Severity.CRITICAL),
            (r'DATABASE_URL\s*=\s*\S+',                                   'Database URL',           Severity.HIGH),
            (r'ghp_[A-Za-z0-9]{36}',                                      'GitHub Personal Token',  Severity.CRITICAL),
        ]

        for pattern, name, severity in sensitive_patterns:
            m = re.search(pattern, content, re.IGNORECASE)
            if m:
                matched_text  = m.group(0)[:80]
                body_context  = _highlight_in_body(content, m.group(0)[:20], context_chars=120)
                vulnerabilities.append(Vulnerability(
                    name=f"Exposed {name} in Response",
                    severity=severity,
                    description=f"The page response contains what appears to be a {name}",
                    evidence=(
                        f"{'='*60}\n"
                        f"EVIDENCE: Credential/Secret Exposure — {name}\n"
                        f"{'='*60}\n\n"
                        f"WHAT WE DID\n{'-'*40}\n"
                        f"  We scanned the HTTP response body for patterns matching known\n"
                        f"  credential formats ({name}). This pattern was found.\n\n"
                        f"REQUEST\n{'-'*40}\n"
                        f"  Method  : GET\n"
                        f"  URL     : {url}\n\n"
                        f"RESPONSE\n{'-'*40}\n"
                        f"  Status       : {response.status_code} {response.reason}\n"
                        f"  Content-Type : {response.headers.get('Content-Type', 'not set')}\n"
                        f"  Body Length  : {len(content):,} characters\n\n"
                        f"CREDENTIAL FOUND IN BODY\n{'-'*40}\n"
                        f"  Secret Type          : {name}\n"
                        f"  Matched Text         : {matched_text}\n"
                        f"  Position in Body     : character {m.start():,} of {len(content):,}\n"
                        f"  Pattern Used         : {pattern[:60]}\n"
                        f"  Context in Body:\n"
                        f"    {body_context}\n\n"
                        f"FINDING DETAILS\n{'-'*40}\n"
                        f"  Credential Active    : Assumed YES — rotate immediately to be safe\n"
                        f"  Exposure Duration    : Unknown — assume it has been indexed/scraped\n"
                        f"  Observed Result      : Live credential/secret visible in page response body\n\n"
                        f"PLAIN-LANGUAGE SUMMARY\n{'-'*40}\n"
                        f"  A {name} appears to be embedded in a page your server is serving publicly.\n"
                        f"  Anyone who visits this URL — including automated bots and search engine crawlers —\n"
                        f"  can read this credential and use it to access your {name.split()[0]} account,\n"
                        f"  database, or payment system. Revoke the credential immediately and remove\n"
                        f"  it from the source code or response. Never put secrets in client-facing files.\n"
                    ),
                    remediation="Remove all secrets from client-facing code and responses. Use server-side environment variables. Rotate the exposed credential immediately.",
                    what_we_tested=f"We scanned the response body for patterns matching {name} formats.",
                    indicates="A live secret is exposed in the page source — this is an active credential leak.",
                    how_exploited="Attacker reads the page source, copies the credential, and uses it to access your cloud account, database, or payment system."
                ))

        if urlparse(url).scheme == 'https':
            http_scripts  = re.findall(r'src=["\']http://[^"\']+\.js["\']',  content, re.I)
            http_styles   = re.findall(r'href=["\']http://[^"\']+\.css["\']', content, re.I)
            http_images   = re.findall(r'src=["\']http://[^"\']+\.(?:png|jpg|jpeg|gif|webp|svg)["\']', content, re.I)
            http_other    = re.findall(r'src=["\']http://[^"\']+["\']',       content, re.I)
            all_mixed     = list(set(http_scripts + http_styles + http_other))

            if all_mixed:
                resource_breakdown = []
                if http_scripts: resource_breakdown.append(f"JavaScript files: {len(http_scripts)} (highest risk — can execute code)")
                if http_styles:  resource_breakdown.append(f"CSS stylesheets : {len(http_styles)}")
                if http_images:  resource_breakdown.append(f"Images          : {len(http_images)}")
                other_count = len(all_mixed) - len(http_scripts) - len(http_styles) - len(http_images)
                if other_count > 0:
                    resource_breakdown.append(f"Other resources : {other_count}")

                vulnerabilities.append(Vulnerability(
                    name="Mixed Content (HTTP Resources on HTTPS Page)",
                    severity=Severity.MEDIUM,
                    description=f"HTTPS page loads {len(all_mixed)} resource(s) over plain HTTP",
                    evidence=(
                        f"{'='*60}\n"
                        f"EVIDENCE: Mixed Content — HTTP Resources on HTTPS Page\n"
                        f"{'='*60}\n\n"
                        f"WHAT WE DID\n{'-'*40}\n"
                        f"  We loaded the HTTPS page and scanned its HTML source for any resources\n"
                        f"  (scripts, stylesheets, images) loaded over unencrypted HTTP.\n\n"
                        f"REQUEST\n{'-'*40}\n"
                        f"  Method  : GET\n"
                        f"  URL     : {url}\n\n"
                        f"RESPONSE\n{'-'*40}\n"
                        f"  Status  : {response.status_code} {response.reason}\n"
                        f"  Page loads these HTTP resources:\n\n"
                        f"RESOURCE BREAKDOWN\n{'-'*40}\n" +
                        "\n".join(f"  {rb}" for rb in resource_breakdown) +
                        f"\n  Total mixed resources: {len(all_mixed)}\n\n"
                        f"MIXED RESOURCE EXAMPLES (first 5)\n{'-'*40}\n" +
                        "\n".join(f"  {i+1}. {r}" for i, r in enumerate(all_mixed[:5])) +
                        f"\n\n"
                        f"FINDING DETAILS\n{'-'*40}\n"
                        f"  Mixed JS Files  : {len(http_scripts)} — MOST CRITICAL (scripts can be hijacked to run code)\n"
                        f"  Mixed CSS Files : {len(http_styles)}\n"
                        f"  Mixed Images    : {len(http_images)}\n"
                        f"  Observed Result : HTTPS page fetches resources over unencrypted HTTP connections\n\n"
                        f"PLAIN-LANGUAGE SUMMARY\n{'-'*40}\n"
                        f"  Your site uses HTTPS (good), but it is loading {len(all_mixed)} resource(s) using\n"
                        f"  the unencrypted HTTP protocol. This breaks the security of your HTTPS connection\n"
                        f"  because an attacker on the same network can modify those HTTP resources in transit.\n"
                        f"  If any are JavaScript files, the attacker can inject arbitrary code into your page.\n"
                        f"  Fix: change all resource URLs to HTTPS or use protocol-relative URLs (//...).\n"
                    ),
                    remediation="Change all resource URLs to HTTPS or use protocol-relative URLs (//example.com/script.js).",
                    what_we_tested="We scanned the page HTML for HTTP resource references on an HTTPS page.",
                    indicates="Mixed content means parts of the page are unencrypted, undermining HTTPS protection.",
                    how_exploited="An attacker can modify the HTTP resource in transit to inject malicious JavaScript even though the main page is HTTPS."
                ))

        return vulnerabilities

    # ── Security Misconfigurations ────────────────────────────────────────

    def check_security_misconfigurations(self, response: requests.Response, url: str) -> List[Vulnerability]:
        vulnerabilities = []
        set_cookie = response.headers.get('Set-Cookie', '')

        if set_cookie:
            # Parse multiple Set-Cookie headers if present
            all_cookies = response.raw.headers.getlist('Set-Cookie') if hasattr(response.raw, 'headers') and hasattr(response.raw.headers, 'getlist') else [set_cookie]
            cookie_display = "\n".join(f"    {i+1}. {c}" for i, c in enumerate(all_cookies[:5]))
            cookie_count   = len(all_cookies)

            if 'Secure' not in set_cookie and urlparse(url).scheme == 'https':
                vulnerabilities.append(Vulnerability(
                    name="Cookie Missing Secure Flag",
                    severity=Severity.MEDIUM,
                    description="Session cookie can be transmitted over unencrypted HTTP connections",
                    evidence=self._build_evidence(
                        title="Cookie Missing Secure Flag",
                        request_url=url, method="GET",
                        request_headers=dict(self.session.headers),
                        response=response,
                        extra_fields=[
                            ("Cookies Set by Server",     f"{cookie_count} cookie(s)\n{cookie_display}"),
                            ("Missing Attribute",         "Secure"),
                            ("Current Cookie Header",     set_cookie[:200]),
                            ("Expected Correct Value",    "Set-Cookie: name=value; Secure; HttpOnly; SameSite=Strict"),
                            ("Why Secure Is Required",    "Without Secure, the browser can send the cookie over plain HTTP"),
                            ("Observed Result",           "Secure attribute absent — cookie transmittable over unencrypted HTTP"),
                        ],
                        plain_english_summary=(
                            "A session cookie is being set without the 'Secure' flag. This means if a user "
                            "visits your site over HTTP (even by accident, or if an attacker downgrades the "
                            "connection), their session cookie will be sent unencrypted. An attacker on the "
                            "same network can intercept it and log in as that user. Adding '; Secure' to the "
                            "cookie definition fixes this."
                        )
                    ),
                    remediation="Add 'Secure' to all cookie definitions: Set-Cookie: name=value; Secure; HttpOnly; SameSite=Strict",
                    what_we_tested="We checked Set-Cookie response headers for the Secure attribute.",
                    indicates="The cookie can leak over HTTP, exposing session tokens.",
                    how_exploited="On any HTTP connection the browser sends the cookie unencrypted — attacker reads it and hijacks the session."
                ))

            if 'HttpOnly' not in set_cookie:
                vulnerabilities.append(Vulnerability(
                    name="Cookie Missing HttpOnly Flag",
                    severity=Severity.MEDIUM,
                    description="Session cookie is readable by JavaScript — exploitable via XSS",
                    evidence=self._build_evidence(
                        title="Cookie Missing HttpOnly Flag",
                        request_url=url, method="GET",
                        request_headers=dict(self.session.headers),
                        response=response,
                        extra_fields=[
                            ("Cookies Set by Server",      f"{cookie_count} cookie(s)\n{cookie_display}"),
                            ("Missing Attribute",          "HttpOnly"),
                            ("Current Cookie Header",      set_cookie[:200]),
                            ("Expected Correct Value",     "Set-Cookie: name=value; Secure; HttpOnly"),
                            ("What HttpOnly Prevents",     "JavaScript on the page cannot read the cookie via document.cookie"),
                            ("XSS Theft Demo",             "document.cookie returns all non-HttpOnly cookies — attacker sends them to their server"),
                            ("Observed Result",            "HttpOnly absent — JavaScript can read this cookie via document.cookie"),
                        ],
                        plain_english_summary=(
                            "This cookie is missing the 'HttpOnly' flag, which means any JavaScript "
                            "running on your page can read it. If your site has any XSS vulnerability "
                            "(or if a third-party script is compromised), an attacker can steal this "
                            "cookie with a single line of JavaScript: document.cookie. This gives them "
                            "full control of the affected user's session."
                        )
                    ),
                    remediation="Add 'HttpOnly' to all sensitive cookies: Set-Cookie: name=value; Secure; HttpOnly",
                    what_we_tested="We checked Set-Cookie headers for the HttpOnly attribute.",
                    indicates="Any XSS vulnerability on the site can directly steal session cookies.",
                    how_exploited="XSS payload calls document.cookie and sends the value to the attacker's server — instant session hijack."
                ))

            if 'SameSite' not in set_cookie:
                vulnerabilities.append(Vulnerability(
                    name="Cookie Missing SameSite Attribute",
                    severity=Severity.LOW,
                    description="Cookie will be sent on cross-site requests, enabling CSRF attacks",
                    evidence=self._build_evidence(
                        title="Cookie Missing SameSite Attribute",
                        request_url=url, method="GET",
                        request_headers=dict(self.session.headers),
                        response=response,
                        extra_fields=[
                            ("Cookies Set by Server",     f"{cookie_count} cookie(s)\n{cookie_display}"),
                            ("Missing Attribute",         "SameSite"),
                            ("Current Cookie Header",     set_cookie[:200]),
                            ("Expected Correct Value",    "SameSite=Strict or SameSite=Lax"),
                            ("SameSite=Strict Means",     "Cookie only sent on requests originating from your own site"),
                            ("SameSite=Lax Means",        "Cookie sent on top-level navigations but not on background requests"),
                            ("Without SameSite",          "Cookie sent on all cross-site requests including form POSTs from other sites"),
                            ("Observed Result",           "SameSite absent — cookie sent on all cross-site requests"),
                        ],
                        plain_english_summary=(
                            "This cookie is missing the 'SameSite' attribute. Without it, if a user is "
                            "logged into your site and visits a malicious website, that malicious site can "
                            "make requests to your server that automatically include the user's session cookie. "
                            "This is the basis of Cross-Site Request Forgery (CSRF) attacks — the attacker "
                            "can submit forms or make API calls as the victim without them knowing."
                        )
                    ),
                    remediation="Add 'SameSite=Strict' or 'SameSite=Lax' to cookies.",
                    what_we_tested="We checked Set-Cookie headers for the SameSite attribute.",
                    indicates="Cross-Site Request Forgery (CSRF) is possible if no other CSRF protection exists.",
                    how_exploited="Attacker's site makes a POST request to your site — the browser sends the cookie automatically."
                ))

        return vulnerabilities

    # ── Deduplication ─────────────────────────────────────────────────────

    @staticmethod
    def deduplicate(vulnerabilities: List[Vulnerability]) -> List[Vulnerability]:
        seen: Dict[str, Vulnerability] = {}
        for vuln in vulnerabilities:
            key = vuln.dedup_key()
            if key not in seen:
                seen[key] = vuln
            else:
                existing  = seen[key]
                sev_order = [Severity.CRITICAL, Severity.HIGH, Severity.MEDIUM,
                             Severity.LOW, Severity.INFO, Severity.SECURE]
                if sev_order.index(vuln.severity) < sev_order.index(existing.severity):
                    seen[key] = vuln
        return list(seen.values())

    # ── Full Scan Orchestrator ────────────────────────────────────────────

    def perform_scan(self, url: str, enable_port_scan: bool = True,
                     port_scan_type: str = "connect",
                     custom_ports: Optional[List[int]] = None,
                     scan_token: Optional[str] = None) -> ScanResult:

        GLOBAL_TIMEOUT = 180
        start_time     = time.time()
        timestamp      = now_eat_iso()

        set_scan_progress(scan_token, "Preparing scan", 5, "running")
        is_valid, validation_result = self.validate_url(url)
        if not is_valid:
            set_scan_progress(scan_token, "Validation failed", 100, "failed", validation_result)
            return ScanResult(
                target=url, timestamp=timestamp, scan_duration=0,
                ssl_info={}, headers={}, vulnerabilities=[],
                port_scan={}, summary={}, error=validation_result
            )

        clean_url           = validation_result
        all_vulnerabilities: List[Vulnerability] = []
        port_scan_results   = {}

        module_progress = {
            "ssl": 20, "server": 26, "headers": 32, "cors": 38, "redirect": 44,
            "files": 50, "sqli": 58, "xss": 66, "info": 72, "config": 78,
            "crawl": 84, "ports": 92
        }
        module_label = {
            "ssl":      "Checking SSL/TLS",
            "server":   "Inspecting server headers",
            "headers":  "Validating security headers",
            "cors":     "Testing CORS policy",
            "redirect": "Testing open redirects",
            "files":    "Checking sensitive file exposure",
            "sqli":     "Testing SQL injection paths",
            "xss":      "Testing cross-site scripting paths",
            "info":     "Analyzing information disclosure",
            "config":   "Reviewing security misconfiguration",
            "crawl":    "Discovering domain pages",
            "ports":    "Scanning open ports and services",
        }

        def run_module(name: str, fn, *args, **kwargs):
            set_scan_progress(
                scan_token,
                module_label.get(name, f"Running {name} checks"),
                module_progress.get(name, 15), "running"
            )
            if time.time() - start_time > GLOBAL_TIMEOUT:
                logger.warning(f"Skipping {name} — global timeout reached")
                return None
            try:
                return fn(*args, **kwargs)
            except Exception as e:
                logger.error(f"Module '{name}' failed: {e}", exc_info=True)
                return None

        try:
            logger.info(f"Starting scan of {clean_url}")
            set_scan_progress(scan_token, "Connecting to target", 12, "running")
            try:
                response  = self.session.get(
                    clean_url, timeout=self.timeout, allow_redirects=True, verify=False
                )
                final_url = response.url
            except requests.RequestException as e:
                return ScanResult(
                    target=clean_url, timestamp=timestamp,
                    scan_duration=round(time.time() - start_time, 2),
                    ssl_info={}, headers={}, vulnerabilities=[],
                    port_scan={}, summary={},
                    error=f"Failed to connect to target: {str(e)}"
                )

            ssl_info       = run_module("ssl",      self.check_ssl_tls, clean_url)         or {'vulnerabilities': [], 'https': False, 'grade': 'F', 'protocols': [], 'status': 'error'}
            server_info    = run_module("server",   self.get_server_info, response)        or {}
            headers_info   = run_module("headers",  self.check_security_headers, response) or {'present': [], 'missing': [], 'misconfigured': [], 'score': 0, 'max_score': 1, 'percentage': 0}
            cors_vulns     = run_module("cors",     self.check_cors, final_url)            or []
            redirect_vulns = run_module("redirect", self.check_open_redirect, final_url)   or []
            file_vulns     = run_module("files",    self.check_sensitive_files, clean_url) or []
            sql_vulns      = run_module("sqli",     self.test_sql_injection, final_url)    or []
            xss_vulns      = run_module("xss",      self.test_xss, final_url)             or []
            info_vulns     = run_module("info",     self.check_information_disclosure, response, final_url) or []
            cfg_vulns      = run_module("config",   self.check_security_misconfigurations, response, final_url) or []

            all_vulnerabilities.extend(ssl_info.get('vulnerabilities', []))

            def _header_evidence_text(target_url: str, header_name: str,
                                       resp: requests.Response,
                                       headers_info_local: Dict) -> str:
                status_code  = getattr(resp, "status_code", "unknown")
                present      = [str(p.get('name', '')) for p in headers_info_local.get('present', []) if p.get('name')]
                present_str  = ", ".join(present[:8]) if present else "None found"
                missing_names = [str(m.get('name', '')) for m in headers_info_local.get('missing', []) if m.get('name')]
                missing_str   = ", ".join(missing_names[:8]) if missing_names else "None"
                expected      = self.header_recommended.get(header_name, "See OWASP Secure Headers Project")
                all_resp_headers = "\n".join(f"    {k}: {v}" for k, v in resp.headers.items())

                header_explanations = {
                    'Strict-Transport-Security': (
                        "Tells browsers to always use HTTPS for this domain — prevents protocol downgrade attacks.",
                        "A user visits http://yoursite.com — without HSTS the browser may use HTTP, "
                        "exposing the session cookie. With HSTS, the browser upgrades to HTTPS automatically."
                    ),
                    'Content-Security-Policy': (
                        "Tells the browser which scripts, styles, and resources are allowed to load — blocks injected scripts.",
                        "If an attacker injects a script via XSS, without CSP the browser will execute it. "
                        "With CSP, the browser refuses to run scripts not explicitly permitted."
                    ),
                    'X-Frame-Options': (
                        "Prevents your page from being embedded in an iframe on another site — blocks clickjacking.",
                        "An attacker puts your login page in an invisible iframe on their site. "
                        "Without X-Frame-Options, users can be tricked into clicking buttons they cannot see."
                    ),
                    'X-Content-Type-Options': (
                        "Prevents browsers from guessing the file type — stops MIME-sniffing attacks.",
                        "If a user uploads a JavaScript file named 'image.jpg', a browser without nosniff "
                        "may execute it as a script. With nosniff, it treats it as an image."
                    ),
                    'Referrer-Policy': (
                        "Controls how much URL information is sent to third parties in the Referer header.",
                        "Without this, query parameters (including session tokens) in your URLs may leak "
                        "to every third-party resource loaded on your pages."
                    ),
                    'Permissions-Policy': (
                        "Restricts which browser features (camera, mic, location) scripts can access.",
                        "An injected script could request camera or microphone access. "
                        "With Permissions-Policy set to deny these, the browser blocks the request."
                    ),
                    'X-XSS-Protection': (
                        "Enables the built-in XSS filter in older browsers (IE, older Chrome).",
                        "Low impact on modern browsers which use CSP instead, but adds a layer for legacy users."
                    ),
                }
                what_it_does, attack_scenario = header_explanations.get(
                    header_name,
                    (f"Security header that protects against specific browser-based attacks.", "Consult OWASP Secure Headers Project for details.")
                )

                return (
                    f"{'='*60}\n"
                    f"EVIDENCE: Missing Security Header — {header_name}\n"
                    f"{'='*60}\n\n"
                    f"WHAT WE DID\n{'-'*40}\n"
                    f"  We made a GET request and checked whether the response included\n"
                    f"  the '{header_name}' security header.\n\n"
                    f"REQUEST\n{'-'*40}\n"
                    f"  Method     : GET\n"
                    f"  URL        : {target_url}\n\n"
                    f"RESPONSE\n{'-'*40}\n"
                    f"  Status     : {status_code}\n"
                    f"  All Response Headers:\n{all_resp_headers}\n\n"
                    f"FINDING DETAILS\n{'-'*40}\n"
                    f"  Header Checked             : {header_name}\n"
                    f"  Header Present             : NO\n"
                    f"  Recommended Value          : {header_name}: {expected}\n"
                    f"  What This Header Does      : {what_it_does}\n"
                    f"  Attack It Prevents         : {attack_scenario}\n"
                    f"  Other Present Headers      : {present_str}\n"
                    f"  Other Missing Headers      : {missing_str}\n"
                    f"  Observed Result            : Header absent from server response\n\n"
                    f"PLAIN-LANGUAGE SUMMARY\n{'-'*40}\n"
                    f"  Your server is not sending the '{header_name}' header.\n"
                    f"  What this header does: {what_it_does}\n"
                    f"  Without it: {attack_scenario}\n"
                    f"  Fix: Add this line to your web server config:\n"
                    f"    {header_name}: {expected}\n"
                )

            header_report = {
                'Strict-Transport-Security': (
                    "We checked the HTTP response for the Strict-Transport-Security (HSTS) header.",
                    "Missing HSTS means the browser can be tricked into loading your site over HTTP.",
                    "An attacker strips HTTPS from requests, downgrading the connection to HTTP and intercepting all traffic including login credentials."
                ),
                'Content-Security-Policy': (
                    "We checked for the Content-Security-Policy (CSP) header.",
                    "Without CSP, any injected script (via XSS) will execute without restriction.",
                    "An injected script can steal session cookies, log keystrokes, or redirect users to phishing pages."
                ),
                'X-Frame-Options': (
                    "We checked for the X-Frame-Options header.",
                    "Without this header, your page can be embedded in an invisible iframe on another site.",
                    "An attacker overlays an invisible version of your page on a malicious site, tricking users into clicking buttons they can't see (clickjacking)."
                ),
                'X-Content-Type-Options': (
                    "We checked for the X-Content-Type-Options header.",
                    "Without nosniff, browsers may execute uploaded files as scripts even with the wrong content type.",
                    "If your site allows file uploads, an attacker uploads a JavaScript file with an image extension; the browser executes it."
                ),
                'Referrer-Policy': (
                    "We checked for the Referrer-Policy header.",
                    "Without this header, the full URL of your pages is leaked to every third-party resource.",
                    "Third-party services receive URLs that may contain session tokens or sensitive query parameters."
                ),
                'Permissions-Policy': (
                    "We checked for the Permissions-Policy header.",
                    "Without this, any script on the page can request camera, microphone, or geolocation access.",
                    "An injected script silently requests access to the device camera or microphone."
                ),
                'X-XSS-Protection': (
                    "We checked for the legacy X-XSS-Protection header.",
                    "Low impact on modern browsers which use CSP instead; relevant for older browser users.",
                    "Older browsers without CSP support lack an extra XSS filter layer."
                ),
            }

            for missing in headers_info.get('missing', []):
                severity = (Severity.CRITICAL if missing['severity'] == 'critical' else
                            Severity.HIGH     if missing['severity'] == 'high'     else
                            Severity.MEDIUM   if missing['severity'] == 'medium'   else Severity.LOW)
                tested, indicates, exploited = header_report.get(missing['name'], (
                    f"We checked for the {missing['name']} header.",
                    f"Missing {missing['name']} weakens browser security.",
                    "Consult OWASP Secure Headers Project for details."
                ))
                rec         = self.header_recommended.get(missing['name'], '')
                remediation = (f"Add this to your server config: {missing['name']}: {rec}" if rec
                               else f"Implement the {missing['name']} header.")
                all_vulnerabilities.append(Vulnerability(
                    name=f"Missing {missing['name']}",
                    severity=severity,
                    description=missing['description'],
                    evidence=_header_evidence_text(final_url, missing['name'], response, headers_info),
                    remediation=remediation,
                    what_we_tested=tested,
                    indicates=indicates,
                    how_exploited=exploited
                ))

            discovered_urls = run_module("crawl", self.crawl_domain, clean_url, 12) or [clean_url]
            pages_checked: List[str]          = [clean_url]
            per_url_issues: Dict[str, int]    = {}

            for page_url in discovered_urls:
                if time.time() - start_time > GLOBAL_TIMEOUT:
                    break
                if page_url in (clean_url, final_url):
                    continue
                try:
                    page_resp = self.session.get(page_url, timeout=self.timeout,
                                                  allow_redirects=True, verify=False)
                    page_resp.raise_for_status()
                    pages_checked.append(page_url)
                    page_headers = self.check_security_headers(page_resp)
                    page_path    = urlparse(page_url).path or '/'
                    issue_count  = 0

                    for missing in page_headers.get('missing', []):
                        severity = (Severity.CRITICAL if missing['severity'] == 'critical' else
                                    Severity.HIGH     if missing['severity'] == 'high'     else
                                    Severity.MEDIUM   if missing['severity'] == 'medium'   else Severity.LOW)
                        tested, indicates, exploited = header_report.get(missing['name'], (
                            f"We checked {page_path} for the {missing['name']} header.",
                            f"Missing {missing['name']}.", "Implement the header on all pages."
                        ))
                        rec         = self.header_recommended.get(missing['name'], '')
                        remediation = (f"Add to all pages including {page_path}: {missing['name']}: {rec}" if rec
                                       else f"Implement {missing['name']} on all pages including {page_path}.")
                        all_resp_headers_page = "\n".join(
                            f"    {k}: {v}" for k, v in page_resp.headers.items()
                        )
                        _, attack_scenario = {
                            'Strict-Transport-Security': ("HSTS", "Protocol downgrade attack possible on this page."),
                            'Content-Security-Policy':   ("CSP",  "XSS on this page will execute without browser restriction."),
                            'X-Frame-Options':           ("XFO",  "This page can be framed for clickjacking attacks."),
                            'X-Content-Type-Options':    ("XCTO", "MIME sniffing attacks possible on this page."),
                        }.get(missing['name'], ("", "Implement the header on all pages."))

                        page_evidence = (
                            f"{'='*60}\n"
                            f"EVIDENCE: Missing Security Header — {missing['name']} (page: {page_path})\n"
                            f"{'='*60}\n\n"
                            f"WHAT WE DID\n{'-'*40}\n"
                            f"  We crawled to this page ({page_path}) and checked for the\n"
                            f"  '{missing['name']}' security header. It was absent.\n\n"
                            f"REQUEST\n{'-'*40}\n"
                            f"  Method  : GET\n"
                            f"  URL     : {page_url}\n\n"
                            f"RESPONSE\n{'-'*40}\n"
                            f"  Status  : {page_resp.status_code} {page_resp.reason}\n"
                            f"  All Response Headers:\n{all_resp_headers_page}\n\n"
                            f"FINDING DETAILS\n{'-'*40}\n"
                            f"  Header Checked     : {missing['name']}\n"
                            f"  Page Path          : {page_path}\n"
                            f"  Expected Value     : {missing['name']}: {rec if rec else 'see OWASP'}\n"
                            f"  Observed Result    : Header absent from this page path\n"
                            f"  Security Impact    : {attack_scenario}\n\n"
                            f"PLAIN-LANGUAGE SUMMARY\n{'-'*40}\n"
                            f"  This specific page ({page_path}) is missing the '{missing['name']}' header.\n"
                            f"  Security headers should be applied at the web server level so every\n"
                            f"  page and endpoint is protected automatically, not just the homepage.\n"
                        )
                        all_vulnerabilities.append(Vulnerability(
                            name=f"Missing {missing['name']} (page: {page_path})",
                            severity=severity,
                            description=missing['description'] + f" [Page: {page_path}]",
                            evidence=page_evidence,
                            remediation=remediation,
                            what_we_tested=tested,
                            indicates=indicates,
                            how_exploited=exploited
                        ))
                        issue_count += 1
                    per_url_issues[page_url] = issue_count
                except Exception:
                    continue

            all_vulnerabilities.extend(cors_vulns)
            all_vulnerabilities.extend(redirect_vulns)
            all_vulnerabilities.extend(file_vulns)
            all_vulnerabilities.extend(sql_vulns)
            all_vulnerabilities.extend(xss_vulns)
            all_vulnerabilities.extend(info_vulns)
            all_vulnerabilities.extend(cfg_vulns)

            if enable_port_scan:
                parsed   = urlparse(clean_url)
                hostname = parsed.hostname
                port_scan_results = run_module(
                    "ports", self.port_scanner.scan_host,
                    hostname, custom_ports, port_scan_type
                ) or {}
                if 'vulnerabilities' in port_scan_results:
                    all_vulnerabilities.extend(port_scan_results['vulnerabilities'])

            all_vulnerabilities = self.deduplicate(all_vulnerabilities)
            set_scan_progress(scan_token, "Finalizing score and report summary", 97, "running")

            severity_counts = {s.value: 0 for s in Severity}
            for vuln in all_vulnerabilities:
                severity_counts[vuln.severity.value] += 1

            raw_score   = (
                severity_counts['critical'] * 10 +
                severity_counts['high']     * 5  +
                severity_counts['medium']   * 2  +
                severity_counts['low']      * 1
            )
            SCORE_CEILING = 106
            risk_score = min(round((raw_score / SCORE_CEILING) * 100), 100)
            risk_level = ('Critical' if risk_score >= 60 else
                          'High'     if risk_score >= 30 else
                          'Medium'   if risk_score >= 12 else
                          'Low'      if risk_score >  0  else 'Secure')

            contributions = {
                'critical': severity_counts['critical'] * 10,
                'high':     severity_counts['high'] * 5,
                'medium':   severity_counts['medium'] * 2,
                'low':      severity_counts['low'] * 1,
            }
            risk_score_detail = {
                'raw_points': int(raw_score),
                'raw_points_cap': SCORE_CEILING,
                'risk_score_0_100': int(risk_score),
                'formula_short': (
                    'Issue points = Critical×10 + High×5 + Medium×2 + Low×1; '
                    'score is that total scaled to 0–100 (capped).'
                ),
                'weights': {'critical': 10, 'high': 5, 'medium': 2, 'low': 1},
                'contributions': contributions,
                'excluded_from_score': ['info', 'secure'],
                'note': (
                    'Informational and "secure" findings are counted separately '
                    'and are not part of this numeric score.'
                ),
            }

            scan_duration = round(time.time() - start_time, 2)

            def _clip(s: str, max_len: int = 100) -> str:
                s = (s or '').strip()
                if len(s) <= max_len:
                    return s
                return s[: max_len - 1].rstrip() + '…'

            openers = {
                'Secure':   "This scan’s risk model did not surface severe-rated issues.",
                'Low':      "A few findings are worth fixing; the overall score is still on the lower side.",
                'Medium':   "Several findings add up — put remediation on your schedule.",
                'High':     "The score reflects serious exposure from what we reported.",
                'Critical': "The score sits in the top band until the most severe items are addressed.",
            }
            friendly_parts: List[str] = [openers[risk_level]]

            def _ordered_severe_titles(limit: int = 4) -> List[str]:
                out: List[str] = []
                seen: Set[str] = set()
                for sev in (Severity.CRITICAL, Severity.HIGH):
                    for v in all_vulnerabilities:
                        if v.severity != sev or v.name in seen:
                            continue
                        seen.add(v.name)
                        out.append(_clip(v.name, 120))
                        if len(out) >= limit:
                            return out
                return out

            severe_titles = _ordered_severe_titles(4)
            if severe_titles:
                friendly_parts.append(
                    "Reported in this run: " + "; ".join(severe_titles) + "."
                )
            elif severity_counts['critical'] or severity_counts['high']:
                friendly_parts.append(
                    f"Counts: {severity_counts['critical']} critical, {severity_counts['high']} high — open Detailed report for titles."
                )
            elif all_vulnerabilities:
                friendly_parts.append(
                    "See the findings list for each check we ran and what we observed."
                )

            missing_header_names = [
                str(m.get('name', '')).strip()
                for m in headers_info.get('missing', [])
                if isinstance(m, dict) and m.get('name')
            ]
            missing_header_names = list(dict.fromkeys(missing_header_names))[:6]

            priority_actions: List[str] = []

            crit_v = [v for v in all_vulnerabilities if v.severity == Severity.CRITICAL]
            high_v = [v for v in all_vulnerabilities if v.severity == Severity.HIGH]
            seen_n: Set[str] = set()
            for v in crit_v + high_v:
                if v.name in seen_n:
                    continue
                seen_n.add(v.name)
                priority_actions.append(f"Remediate: {_clip(v.name, 110)}")
                if len(priority_actions) >= 3:
                    break

            ssl_grade = str(ssl_info.get('grade') or '').upper()
            if ssl_grade in ('C', 'D', 'F'):
                priority_actions.append(
                    f"Improve TLS/HTTPS (current grade {ssl_grade}) using the SSL/TLS section and your host or CDN settings."
                )

            if headers_info.get('percentage', 0) < 70 and missing_header_names:
                hdr_line = ", ".join(missing_header_names)
                if len(missing_header_names) >= 6 and len(headers_info.get('missing', [])) > 6:
                    hdr_line += ", …"
                priority_actions.append(
                    "Send these security headers on HTML responses: " + hdr_line + "."
                )
            elif headers_info.get('percentage', 0) < 70:
                priority_actions.append(
                    "Raise header coverage — use the Security headers section for what is missing."
                )

            exposed_cfg = [
                v for v in all_vulnerabilities
                if 'exposed' in v.name.lower() and ('.env' in v.name.lower() or '.git' in v.name.lower() or 'env' in v.name.lower())
            ]
            if exposed_cfg:
                priority_actions.append(
                    f"Revoke public access to sensitive paths (e.g. {_clip(exposed_cfg[0].name, 90)})."
                )

            if not priority_actions:
                if all_vulnerabilities:
                    priority_actions.append("Work through the findings list top-down, then re-scan the same URL.")
                else:
                    priority_actions.append("Re-scan after you deploy changes to confirm nothing regressed.")

            friendly_summary = {'message': " ".join(friendly_parts), 'priority_actions': priority_actions}

            crawler_result = {
                'discovered_urls': discovered_urls,
                'pages_checked':   pages_checked,
                'per_url_issues':  per_url_issues,
            }

            final_result = ScanResult(
                target=clean_url, timestamp=timestamp, scan_duration=scan_duration,
                ssl_info={
                    'https':             ssl_info['https'],
                    'grade':             ssl_info.get('grade', 'F'),
                    'protocols':         ssl_info.get('protocols', []),
                    'certificate_valid': ssl_info.get('status') == 'secure'
                },
                headers={
                    'score':         headers_info.get('percentage', 0),
                    'present':       len(headers_info.get('present', [])),
                    'missing':       len(headers_info.get('missing', [])),
                    'present_names': [p['name'] for p in headers_info.get('present', []) if isinstance(p, dict)],
                    'missing_names': [m['name'] for m in headers_info.get('missing', []) if isinstance(m, dict)],
                    'misconfigured': headers_info.get('misconfigured', [])
                },
                vulnerabilities=all_vulnerabilities,
                port_scan={
                    'enabled':       enable_port_scan,
                    'target_ip':     port_scan_results.get('ip_address'),
                    'scan_type':     port_scan_results.get('scan_type', 'none'),
                    'ports_scanned': port_scan_results.get('ports_scanned', 0),
                    'open_ports':    port_scan_results.get('open_ports', []),
                    'open_count':    port_scan_results.get('open_count', 0),
                    'duration':      port_scan_results.get('duration', 0)
                },
                summary={
                    'total_vulnerabilities': len(all_vulnerabilities),
                    'severity_breakdown':    severity_counts,
                    'risk_score':            risk_score,
                    'risk_level':            risk_level,
                    'risk_score_detail':     risk_score_detail,
                    'scan_status':           'completed',
                    'user_friendly':         friendly_summary
                },
                server_info=server_info,
                crawler=crawler_result
            )
            set_scan_progress(scan_token, "Scan completed", 100, "completed")
            return final_result

        except Exception as e:
            logger.error(f"Scan error: {e}", exc_info=True)
            set_scan_progress(scan_token, "Scan failed", 100, "failed", str(e))
            return ScanResult(
                target=clean_url, timestamp=timestamp,
                scan_duration=round(time.time() - start_time, 2),
                ssl_info={}, headers={}, vulnerabilities=all_vulnerabilities,
                port_scan={}, summary={}, error=f"Scan failed: {str(e)}",
                server_info=None, crawler=None
            )


# ─────────────────────────────────────────────
# MODULE-LEVEL HELPERS (used inside scanner methods)
# ─────────────────────────────────────────────

def _infer_db_from_pattern(pattern: str) -> str:
    """Infer the database type from the matched SQL error pattern."""
    if not pattern:
        return "Unknown"
    pl = pattern.lower()
    if 'mysql' in pl:   return "MySQL / MariaDB"
    if 'pg_' in pl or 'postgresql' in pl or 'npgsql' in pl: return "PostgreSQL"
    if 'mssql' in pl or 'sql server' in pl or 'ole db' in pl or 'waitfor' in pl: return "Microsoft SQL Server"
    if 'oracle' in pl or 'oci_' in pl: return "Oracle Database"
    if 'sqlite' in pl:  return "SQLite"
    if 'access' in pl or 'jet' in pl: return "Microsoft Access / JET"
    if 'odbc' in pl:    return "Unknown (via ODBC)"
    return "Unknown"


# ─────────────────────────────────────────────
# FLASK ROUTES
# ─────────────────────────────────────────────

scanner = SecurityScanner()

@app.before_request
def log_request():
    logger.debug(f"Request: {request.method} {request.url} from {request.remote_addr}")

@app.after_request
def after_request(response):
    logger.debug(f"Response: {response.status}")
    response.headers.add('Access-Control-Allow-Origin',  '*')
    response.headers.add('Access-Control-Allow-Headers', 'Content-Type,Authorization')
    response.headers.add('Access-Control-Allow-Methods', 'GET,PUT,POST,DELETE,OPTIONS')
    return response

@app.route("/scan", methods=["POST", "OPTIONS"])
@limiter.limit("10 per minute")
def scan():
    if request.method == "OPTIONS":
        return jsonify({"status": "ok"}), 200
    try:
        data = request.get_json()
        if not data:
            return jsonify({'error': 'No JSON data provided'}), 400
        target = data.get('target')
        if not target:
            return jsonify({'error': 'Target URL is required'}), 400

        enable_port_scan = data.get('enable_port_scan', True)
        port_scan_type   = data.get('port_scan_type', 'connect')
        custom_ports     = data.get('custom_ports')
        scan_token       = data.get('scan_token')

        if port_scan_type not in ['connect', 'syn']:
            port_scan_type = 'connect'

        result = scanner.perform_scan(
            target,
            enable_port_scan=enable_port_scan,
            port_scan_type=port_scan_type,
            custom_ports=custom_ports,
            scan_token=scan_token
        )

        response_data = {
            'target':        result.target,
            'timestamp':     result.timestamp,
            'scan_duration': result.scan_duration,
            'ssl':           result.ssl_info,
            'headers':       result.headers,
            'port_scan':     result.port_scan,
            'server_info':   result.server_info or {},
            'crawler':       result.crawler or {},
            'vulnerabilities': [
                {
                    'name':           expand_security_terms(v.name),
                    'severity':       v.severity.value,
                    'description':    expand_security_terms(v.description),
                    'evidence':       expand_security_terms(v.evidence),
                    'remediation':    expand_security_terms(v.remediation),
                    'cvss_score':     v.cvss_score,
                    'what_we_tested': expand_security_terms(getattr(v, 'what_we_tested', None)),
                    'indicates':      expand_security_terms(getattr(v, 'indicates', None)),
                    'how_exploited':  expand_security_terms(getattr(v, 'how_exploited', None))
                } for v in result.vulnerabilities
            ],
            'summary': result.summary,
            'error':   result.error
        }
        return jsonify(response_data)

    except Exception as e:
        logger.error(f"Endpoint error: {e}", exc_info=True)
        return jsonify({'error': 'Internal server error', 'details': str(e)}), 500


@app.route("/scan-progress", methods=["GET", "OPTIONS"])
@limiter.limit("120 per minute")
def scan_progress():
    if request.method == "OPTIONS":
        return jsonify({"status": "ok"}), 200
    token = (request.args.get("token") or "").strip()
    if not token:
        return jsonify({"error": "token is required"}), 400
    with SCAN_PROGRESS_LOCK:
        progress = SCAN_PROGRESS.get(token)
    if not progress:
        return jsonify({
            "scan_token": token, "stage": "Waiting for scan",
            "progress": 0, "status": "unknown", "detail": "",
            "updated_at": now_eat_iso(),
        }), 200
    return jsonify(progress), 200


@app.route("/port-scan", methods=["POST", "OPTIONS"])
@limiter.limit("5 per minute")
def port_scan_only():
    if request.method == "OPTIONS":
        return jsonify({"status": "ok"}), 200
    try:
        data = request.get_json()
        if not data:
            return jsonify({'error': 'No JSON data provided'}), 400
        target = data.get('target')
        if not target:
            return jsonify({'error': 'Target host is required'}), 400
        if '://' in target:
            target = urlparse(target).hostname

        ports        = data.get('ports')
        scan_type    = data.get('scan_type', 'connect')
        port_scanner = PortScanner(
            timeout=data.get('timeout', 2.0),
            max_workers=data.get('max_workers', 50)
        )
        result = port_scanner.scan_host(target, ports=ports, scan_type=scan_type)
        return jsonify({
            'target':        result['target'],
            'ip_address':    result.get('ip_address'),
            'scan_type':     result['scan_type'],
            'duration':      result['duration'],
            'ports_scanned': result['ports_scanned'],
            'open_ports':    result['open_ports'],
            'vulnerabilities': [
                {'name': v.name, 'severity': v.severity.value, 'description': v.description}
                for v in result.get('vulnerabilities', [])
            ]
        })
    except Exception as e:
        logger.error(f"Port scan error: {e}", exc_info=True)
        return jsonify({'error': 'Port scan failed', 'details': str(e)}), 500


@app.route("/health", methods=["GET"])
def health_check():
    return jsonify({
        'status':    'healthy',
        'timestamp': now_eat_iso(),
        'version':   '2.4.0',
        'features':  [
            'ssl_scan', 'header_analysis', 'sqli_test', 'xss_test',
            'port_scan', 'cors_check', 'open_redirect', 'sensitive_files',
            'blind_sqli', 'mixed_content', 'secret_detection', 'deduplication',
            'full_request_evidence', 'full_response_headers', 'body_highlight',
            'plain_english_summaries', 'cors_preflight_test', 'dom_xss_source_context',
            'sql_baseline_comparison', 'mixed_content_breakdown', 'body_type_classification'
        ]
    })


if __name__ == "__main__":
    print("=" * 60)
    print("ScanQuotient Security Scanner Backend v2.4")
    print("=" * 60)
    print("Evidence upgrades in this version:")
    print("  + WHAT WE DID section on every finding (plain language)")
    print("  + PLAIN-LANGUAGE SUMMARY section on every finding")
    print("  + Body type classification (JSON / HTML / error page / etc.)")
    print("  + Page title extraction in HTTP evidence")
    print("  + CORS: preflight OPTIONS test included with results")
    print("  + SQL injection: baseline URL + DB type inference")
    print("  + XSS: encoding check + injection context snippet")
    print("  + DOM XSS: actual source snippet around each sink")
    print("  + Open redirect: full redirect chain shown")
    print("  + Sensitive files: credential pattern list + structured body")
    print("  + Security headers: what-it-does + attack scenario per header")
    print("  + Server/X-Powered-By: full headers shown, not just the value")
    print("  + Mixed content: JS/CSS/image breakdown by type")
    print("  + Cookies: all Set-Cookie headers listed, not just first")
    print("  + Port scan: plain-language reachability explanation")
    print("  + SSL: validity days remaining, protocol quality labels")
    print("Server: http://0.0.0.0:5000")
    print("=" * 60)
    app.run(host='0.0.0.0', port=5000, debug=True, threaded=True, use_reloader=False)