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

# Disable SSL warnings for scanning purposes
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

logging.basicConfig(
    level=logging.DEBUG,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

# East Africa (EAT, UTC+03:00) without requiring tzdata/zoneinfo.
# Using a fixed offset keeps timestamps consistent and avoids ModuleNotFoundError on Windows.
EAT_TZ = timezone(timedelta(hours=3))

def now_eat_iso() -> str:
    return datetime.now(EAT_TZ).isoformat()

def now_eat_naive() -> datetime:
    # Return naive datetime (no tzinfo) for safe arithmetic with naive datetimes.
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
    name: str
    severity: Severity
    description: str
    evidence: str
    remediation: str
    cvss_score: Optional[float] = None
    what_we_tested: Optional[str] = None
    indicates: Optional[str] = None
    how_exploited: Optional[str] = None

    def dedup_key(self) -> str:
        """Stable key used to deduplicate identical findings."""
        return hashlib.md5(f"{self.name}|{self.severity.value}|{self.description[:60]}".encode()).hexdigest()

@dataclass
class PortInfo:
    port: int
    status: PortStatus
    service: str
    banner: Optional[str] = None
    version: Optional[str] = None
    risk: str = "info"

@dataclass
class ScanResult:
    target: str
    timestamp: str
    scan_duration: float
    ssl_info: Dict
    headers: Dict
    vulnerabilities: List[Vulnerability]
    port_scan: Dict
    summary: Dict
    error: Optional[str] = None
    server_info: Optional[Dict] = None
    crawler: Optional[Dict] = None


# ─────────────────────────────────────────────
# PORT SCANNER
# ─────────────────────────────────────────────

class PortScanner:
    """TCP Connect and SYN port scanner with service detection."""

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

    # HTTP-based admin panels that often appear on alternative ports
    ADMIN_PANEL_PATHS = [
        '/',
        '/admin',
        '/manager',
        '/console',
        '/dashboard',
        '/phpmyadmin',
        '/wp-admin',
    ]

    def __init__(self, timeout: float = 2.0, max_workers: int = 50):
        self.timeout   = timeout
        self.max_workers = max_workers
        self.os_type   = platform.system().lower()

    def scan_host(self, hostname: str, ports: Optional[List[int]] = None,
                  scan_type: str = "connect") -> Dict:
        result = {
            'target':       hostname,
            'scan_type':    scan_type,
            'ports_scanned': 0,
            'open_ports':   [],
            'closed_ports': [],
            'filtered_ports': [],
            'services_found': [],
            'vulnerabilities': [],
            'start_time':   now_eat_iso(),
            'duration':     0.0
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

        for port_info in open_ports:
            entry = {
                'port':    port_info.port,
                'service': port_info.service,
                'banner':  port_info.banner,
                'version': port_info.version,
                'risk':    port_info.risk
            }
            result['open_ports'].append(entry)

            # ── Risky service check ───────────────────────────────────────
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
                    evidence=f"Service detected: {port_info.banner or port_info.service}",
                    remediation=f"Restrict access to {port_info.service} using firewall rules. Consider VPN or IP whitelisting.",
                    cvss_score=7.5 if risk_level == 'critical' else 6.5,
                    what_we_tested=f"We probed port {port_info.port} and identified the service running on it.",
                    indicates="An exposed service widens your attack surface and may indicate a misconfigured firewall.",
                    how_exploited="Attackers scan for this port, then target known CVEs for the service, attempt brute-force logins, or use it for lateral movement."
                ))

            # ── Specific per-service checks ───────────────────────────────
            if port_info.port == 23:
                result['vulnerabilities'].append(Vulnerability(
                    name="Telnet Service Detected",
                    severity=Severity.CRITICAL,
                    description="Telnet transmits all data including credentials in plaintext",
                    evidence="Port 23 is open",
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
                    evidence=f"Port 6379 open. Banner: {port_info.banner or 'none'}",
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
                    evidence="Port 27017 open",
                    remediation="Bind MongoDB to 127.0.0.1. Enable authentication. Block port 27017 at the firewall.",
                    cvss_score=10.0,
                    what_we_tested="We probed the MongoDB port for open access.",
                    indicates="Publicly accessible MongoDB has historically led to mass data theft (hundreds of thousands of databases ransomed).",
                    how_exploited="An attacker connects with any MongoDB client, dumps all databases, deletes them, and leaves a ransom note."
                ))

            elif port_info.port == 9200:
                result['vulnerabilities'].append(Vulnerability(
                    name="Elasticsearch Exposed to Internet",
                    severity=Severity.HIGH,
                    description="Elasticsearch REST API is publicly accessible",
                    evidence="Port 9200 open",
                    remediation="Restrict port 9200 to localhost or VPN. Enable Elasticsearch security features (X-Pack).",
                    cvss_score=8.5,
                    what_we_tested="We probed the Elasticsearch REST API port.",
                    indicates="Unauthenticated Elasticsearch allows reading and deleting all indexed data.",
                    how_exploited="Attacker calls /_cat/indices and /_search to dump all data, or deletes indices."
                ))

            # ── Admin panel detection on HTTP ports ───────────────────────
            if port_info.service in ('HTTP', 'HTTP-Proxy', 'HTTPS-Alt') or port_info.port in (8080, 8443, 8888, 9090):
                admin_found = self._probe_admin_panels(hostname, port_info.port,
                                                        'https' if port_info.port in (8443,) else 'http')
                if admin_found:
                    result['vulnerabilities'].append(Vulnerability(
                        name=f"Admin Panel Accessible on Port {port_info.port}",
                        severity=Severity.HIGH,
                        description=f"An admin or management interface was found at port {port_info.port}",
                        evidence=f"Accessible path(s): {', '.join(admin_found)}",
                        remediation="Restrict admin interfaces to internal IPs or VPN only. Add authentication if not present.",
                        cvss_score=7.0,
                        what_we_tested=f"We probed common admin paths on port {port_info.port}.",
                        indicates="Exposed admin panels are a prime target for credential brute-force and direct exploitation.",
                        how_exploited="Attacker accesses the panel directly and attempts default or brute-forced credentials."
                    ))

        result['duration']    = round(time.time() - start_time, 2)
        result['open_count']  = len(result['open_ports'])
        return result

    def _probe_admin_panels(self, hostname: str, port: int, scheme: str) -> List[str]:
        """Try common admin paths on an HTTP port. Returns accessible paths."""
        found = []
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
                        # Protocol-aware probes
                        if port == 22:
                            banner = sock.recv(256).decode('utf-8', errors='ignore').strip()
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
                        port=port,
                        status=PortStatus.OPEN,
                        service=service_name,
                        banner=banner[:200] if banner else None,
                        version=version,
                        risk=risk
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
        cmd = ['nmap', '-sS', '-p', port_str, '-T4', '--open', '-oX', '-', target_ip]
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
                            port=port,
                            status=PortStatus.OPEN,
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
        self.timeout      = 10
        self.max_redirects = 5
        self.port_scanner  = PortScanner(timeout=2.0, max_workers=30)

        # ── Payloads ──────────────────────────────────────────────────────
        self.sql_payloads = [
            "'",
            "''",
            "' OR '1'='1",
            "' OR 1=1--",
            "' UNION SELECT NULL--",
            "' AND 1=1--",
            "' AND 1=2--",
            "'; DROP TABLE users--",
            "1' AND 1=1--",
            "1' AND 1=2--",
            "' OR 'x'='x",
            "') OR ('1'='1",
            "1; SELECT * FROM users",
            "' OR 1=1#",
            "' OR 1=1/*"
        ]

        # Time-based blind SQLi payloads mapped to DB type
        self.blind_sql_payloads = [
            ("' AND SLEEP(4)--",          "MySQL"),
            ("'; WAITFOR DELAY '0:0:4'--", "MSSQL"),
            ("' AND pg_sleep(4)--",        "PostgreSQL"),
            ("' AND 1=BENCHMARK(5000000,MD5(1))--", "MySQL-benchmark"),
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

        # Open redirect payloads
        self.redirect_payloads = [
            "https://evil.com",
            "//evil.com",
            "/\\evil.com",
            "https:evil.com",
            "%2F%2Fevil.com",
        ]

        # Sensitive files that should never be publicly accessible
        self.sensitive_paths = [
            ('/.env',                  'Environment File',         Severity.CRITICAL),
            ('/.env.local',            'Environment File',         Severity.CRITICAL),
            ('/.env.production',       'Environment File',         Severity.CRITICAL),
            ('/.git/HEAD',             'Git Repository',           Severity.HIGH),
            ('/.git/config',           'Git Config',               Severity.HIGH),
            ('/config.php',            'PHP Config File',          Severity.HIGH),
            ('/wp-config.php',         'WordPress Config',         Severity.CRITICAL),
            ('/wp-config.php.bak',     'WordPress Config Backup',  Severity.CRITICAL),
            ('/phpinfo.php',           'PHP Info Page',            Severity.MEDIUM),
            ('/info.php',              'PHP Info Page',            Severity.MEDIUM),
            ('/test.php',              'Test PHP File',            Severity.LOW),
            ('/admin',                 'Admin Panel',              Severity.MEDIUM),
            ('/admin/',                'Admin Panel',              Severity.MEDIUM),
            ('/administrator',         'Admin Panel',              Severity.MEDIUM),
            ('/phpmyadmin',            'phpMyAdmin',               Severity.HIGH),
            ('/phpmyadmin/',           'phpMyAdmin',               Severity.HIGH),
            ('/pma',                   'phpMyAdmin (pma)',         Severity.HIGH),
            ('/wp-admin',              'WordPress Admin',          Severity.MEDIUM),
            ('/wp-login.php',          'WordPress Login',          Severity.LOW),
            ('/server-status',         'Apache Server Status',     Severity.MEDIUM),
            ('/server-info',           'Apache Server Info',       Severity.MEDIUM),
            ('/nginx_status',          'Nginx Status Page',        Severity.MEDIUM),
            ('/actuator',              'Spring Boot Actuator',     Severity.HIGH),
            ('/actuator/env',          'Spring Actuator Env',      Severity.CRITICAL),
            ('/actuator/health',       'Spring Actuator Health',   Severity.LOW),
            ('/api/swagger-ui',        'Swagger UI',               Severity.LOW),
            ('/swagger-ui.html',       'Swagger UI',               Severity.LOW),
            ('/swagger.json',          'Swagger API Spec',         Severity.LOW),
            ('/api-docs',              'API Docs',                 Severity.LOW),
            ('/graphql',               'GraphQL Endpoint',         Severity.MEDIUM),
            ('/console',               'Web Console',              Severity.HIGH),
            ('/solr',                  'Solr Admin UI',            Severity.HIGH),
            ('/jmx-console',           'JBoss JMX Console',        Severity.CRITICAL),
            ('/web-console',           'JBoss Web Console',        Severity.HIGH),
            ('/debug',                 'Debug Endpoint',           Severity.MEDIUM),
            ('/_debug_toolbar',        'Django Debug Toolbar',     Severity.MEDIUM),
            ('/trace',                 'Trace Endpoint',           Severity.LOW),
            ('/backup',                'Backup Directory',         Severity.HIGH),
            ('/backup.zip',            'Backup Archive',           Severity.CRITICAL),
            ('/backup.tar.gz',         'Backup Archive',           Severity.CRITICAL),
            ('/dump.sql',              'Database Dump',            Severity.CRITICAL),
            ('/db.sql',                'Database Dump',            Severity.CRITICAL),
            ('/database.sql',          'Database Dump',            Severity.CRITICAL),
            ('/robots.txt',            'Robots.txt (info only)',    Severity.INFO),
            ('/sitemap.xml',           'Sitemap (info only)',       Severity.INFO),
            ('/crossdomain.xml',       'Flash Crossdomain Policy', Severity.LOW),
            ('/.htaccess',             '.htaccess File',           Severity.MEDIUM),
            ('/web.config',            'IIS Web Config',           Severity.HIGH),
            ('/package.json',          'Node Package Manifest',    Severity.LOW),
            ('/composer.json',         'PHP Composer Manifest',    Severity.LOW),
            ('/Dockerfile',            'Dockerfile',               Severity.LOW),
            ('/docker-compose.yml',    'Docker Compose File',      Severity.MEDIUM),
        ]

        self.security_headers = {
            'Strict-Transport-Security': {
                'required':    True,
                'description': 'HSTS - Forces HTTPS connections',
                'severity':    Severity.HIGH
            },
            'Content-Security-Policy': {
                'required':    True,
                'description': 'CSP - Prevents XSS and data injection',
                'severity':    Severity.CRITICAL
            },
            'X-Frame-Options': {
                'required':    True,
                'description': 'Clickjacking protection',
                'severity':    Severity.MEDIUM
            },
            'X-Content-Type-Options': {
                'required':    True,
                'description': 'MIME sniffing protection',
                'severity':    Severity.MEDIUM
            },
            'Referrer-Policy': {
                'required':    False,
                'description': 'Controls referrer information',
                'severity':    Severity.LOW
            },
            'Permissions-Policy': {
                'required':    False,
                'description': 'Browser feature restrictions',
                'severity':    Severity.LOW
            },
            'X-XSS-Protection': {
                'required':    False,
                'description': 'Legacy XSS filter (deprecated but useful)',
                'severity':    Severity.LOW
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
        """GET with sensible defaults and swallowed exceptions."""
        kwargs.setdefault('timeout', self.timeout)
        kwargs.setdefault('verify', False)
        kwargs.setdefault('allow_redirects', True)
        try:
            return self.session.get(url, **kwargs)
        except requests.RequestException as e:
            logger.debug(f"GET {url} failed: {e}")
            return None

    def get_server_info(self, response: requests.Response) -> Dict:
        info = {}
        headers_lower = {k.lower(): (k, v) for k, v in response.headers.items()}
        for key, label in [
            ('server',             'Server'),
            ('x-powered-by',       'X-Powered-By'),
            ('x-aspnet-version',   'X-AspNet-Version'),
            ('x-aspnetmvc-version','X-AspNetMvc-Version'),
            ('x-runtime',          'X-Runtime'),
            ('x-version',          'X-Version'),
            ('x-generator',        'X-Generator'),
            ('via',                'Via'),
        ]:
            if key in headers_lower:
                _, value = headers_lower[key]
                info[label] = value[:200] if value else ''
        return info

    def crawl_domain(self, start_url: str, max_pages: int = 12) -> List[str]:
        parsed = urlparse(start_url)
        scheme = parsed.scheme
        netloc = parsed.netloc
        base   = f"{scheme}://{netloc}"
        seen: Set[str]  = set()
        to_visit        = [start_url]
        discovered      = []

        while to_visit and len(discovered) < max_pages:
            url = to_visit.pop(0)
            if url in seen:
                continue
            seen.add(url)
            if url not in discovered:
                discovered.append(url)
            try:
                r = self._safe_get(url)
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
                evidence="URL scheme is HTTP",
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

                    # Expiry check
                    not_after = cert.get('notAfter')
                    if not_after:
                        expiry_date       = datetime.strptime(not_after, '%b %d %H:%M:%S %Y %Z')
                        days_until_expiry = (expiry_date - now_eat_naive()).days
                        if days_until_expiry < 0:
                            result['vulnerabilities'].append(Vulnerability(
                                name="Expired SSL Certificate",
                                severity=Severity.CRITICAL,
                                description=f"Certificate expired {abs(days_until_expiry)} days ago",
                                evidence=f"Expiry date: {not_after}",
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
                                evidence=f"Expiry date: {not_after}",
                                remediation="Renew the certificate now. Set up auto-renewal with certbot to avoid this in future.",
                                what_we_tested="We checked the SSL/TLS certificate expiry date.",
                                indicates="Certificate will soon expire, losing HTTPS protection.",
                                how_exploited="Expired certs cause browser warnings; users may abandon the site or be vulnerable to MITM."
                            ))

                    # Weak protocol
                    if protocol in ['SSLv2', 'SSLv3', 'TLSv1', 'TLSv1.1']:
                        result['vulnerabilities'].append(Vulnerability(
                            name=f"Weak TLS Protocol ({protocol})",
                            severity=Severity.HIGH,
                            description=f"Server uses outdated protocol {protocol}",
                            evidence=f"Negotiated protocol: {protocol}",
                            remediation="Disable TLS 1.0 and 1.1 in your server config. Allow only TLS 1.2 and 1.3.",
                            what_we_tested="We checked which TLS protocol version the server negotiates.",
                            indicates=f"{protocol} is considered broken and vulnerable to known attacks.",
                            how_exploited="BEAST and POODLE attacks can decrypt traffic encrypted with older protocols."
                        ))

                    # Weak cipher check
                    if cipher:
                        cipher_name = cipher[0].upper()
                        weak_ciphers = ['RC4', 'DES', '3DES', 'NULL', 'EXPORT', 'MD5', 'ANON']
                        if any(w in cipher_name for w in weak_ciphers):
                            result['vulnerabilities'].append(Vulnerability(
                                name=f"Weak Cipher Suite ({cipher[0]})",
                                severity=Severity.HIGH,
                                description=f"Server is using a weak or broken cipher: {cipher[0]}",
                                evidence=f"Negotiated cipher: {cipher[0]}",
                                remediation="Update your TLS configuration to use only strong cipher suites (AES-GCM, ChaCha20).",
                                what_we_tested="We inspected the cipher suite negotiated by the server.",
                                indicates="Weak ciphers can be cracked, exposing encrypted traffic.",
                                how_exploited="An attacker who records encrypted traffic can decrypt it offline using known attacks against the weak cipher."
                            ))

                    # HSTS preload check (from the cert negotiation response)
                    # Actual HSTS check is done in header analysis; grade here
                    if protocol == 'TLSv1.3':
                        result['grade'] = 'A+'
                    elif protocol == 'TLSv1.2':
                        result['grade'] = 'A'
                    else:
                        result['grade'] = 'C'

                    result['status'] = 'secure'

        except ssl.SSLCertVerificationError as e:
            result['status'] = 'untrusted'
            result['vulnerabilities'].append(Vulnerability(
                name="Untrusted or Self-Signed SSL Certificate",
                severity=Severity.HIGH,
                description="Certificate cannot be verified by a trusted authority",
                evidence=str(e),
                remediation="Replace the self-signed certificate with one from a trusted CA (e.g. Let's Encrypt — it's free).",
                what_we_tested="We tried to verify the server certificate against trusted certificate authorities.",
                indicates="Visitors see 'Your connection is not private' warnings; browsers cannot confirm they're talking to the real server.",
                how_exploited="Users who click through the warning are vulnerable to MITM; attackers can present their own certificate."
            ))
        except ssl.SSLError as e:
            result['status'] = 'error'
            result['vulnerabilities'].append(Vulnerability(
                name="SSL Certificate Error",
                severity=Severity.HIGH,
                description="SSL certificate validation failed",
                evidence=str(e),
                remediation="Fix SSL certificate configuration (valid cert, correct chain, matching hostname).",
                what_we_tested="We attempted to establish an HTTPS connection and validate the server certificate.",
                indicates="Broken HTTPS; visitors cannot connect securely.",
                how_exploited="Browsers may block the site entirely or show warnings that users dismiss, exposing them to interception."
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

                # Misconfiguration checks
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
                        result['score'] += 1  # Partial credit
                    else:
                        result['score'] += 3

                elif header == 'Strict-Transport-Security':
                    # Check max-age is meaningful (≥ 1 year = 31536000)
                    max_age_match = re.search(r'max-age\s*=\s*(\d+)', value, re.I)
                    if max_age_match:
                        max_age = int(max_age_match.group(1))
                        if max_age < 31536000:
                            result['misconfigured'].append({
                                'header':  header,
                                'issue':   f'max-age={max_age} is less than 1 year (31536000). Recommend at least 1 year.',
                                'current': value
                            })
                            result['score'] += 2  # Partial credit
                        else:
                            result['score'] += 3
                    else:
                        result['misconfigured'].append({
                            'header':  header,
                            'issue':   'Missing max-age directive',
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

        result['percentage'] = round((result['score'] / result['max_score']) * 100, 1) if result['max_score'] > 0 else 0
        return result

    # ── CORS Misconfiguration ─────────────────────────────────────────────

    def check_cors(self, url: str) -> List[Vulnerability]:
        """Detect overly permissive CORS policies."""
        vulnerabilities = []
        try:
            # Test 1: Does the server reflect an arbitrary Origin?
            evil_origin = "https://evil-attacker.com"
            resp = self.session.get(url, timeout=self.timeout, verify=False, headers={
                'Origin': evil_origin
            })
            acao = resp.headers.get('Access-Control-Allow-Origin', '')
            acac = resp.headers.get('Access-Control-Allow-Credentials', '')

            if acao == '*':
                vulnerabilities.append(Vulnerability(
                    name="CORS Wildcard Origin",
                    severity=Severity.MEDIUM,
                    description="Server allows any website to make cross-origin requests (Access-Control-Allow-Origin: *)",
                    evidence="Access-Control-Allow-Origin: *",
                    remediation="Restrict CORS to specific trusted origins. Replace '*' with your exact frontend domain.",
                    cvss_score=5.3,
                    what_we_tested="We sent a cross-origin request with a fake Origin header and inspected the CORS response.",
                    indicates="Any website can make API requests on behalf of your users.",
                    how_exploited="A malicious site reads your API responses, leaking private user data from authenticated endpoints."
                ))

            elif acao == evil_origin:
                # Server is reflecting the attacker origin
                if acac.lower() == 'true':
                    vulnerabilities.append(Vulnerability(
                        name="CORS Misconfiguration — Origin Reflection with Credentials",
                        severity=Severity.CRITICAL,
                        description="Server reflects any Origin and allows credentials, enabling cross-origin authenticated attacks",
                        evidence=f"Access-Control-Allow-Origin: {evil_origin}, Access-Control-Allow-Credentials: true",
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
                        evidence=f"Access-Control-Allow-Origin: {evil_origin}",
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
        """Test common redirect parameters for open redirect vulnerabilities."""
        vulnerabilities = []
        parsed = urlparse(url)

        redirect_params = ['redirect', 'redirect_to', 'url', 'next', 'return',
                           'returnUrl', 'return_url', 'goto', 'destination', 'redir']

        for param in redirect_params:
            for payload in self.redirect_payloads[:2]:  # Limit to keep scan fast
                test_query = urlencode({param: payload})
                test_url   = urlunparse((
                    parsed.scheme, parsed.netloc, parsed.path,
                    '', test_query, ''
                ))
                try:
                    resp = self.session.get(
                        test_url, timeout=self.timeout, verify=False,
                        allow_redirects=False
                    )
                    if resp.status_code in (301, 302, 303, 307, 308):
                        location = resp.headers.get('Location', '')
                        if 'evil' in location or location.startswith('//') or 'evil-attacker' in location:
                            vulnerabilities.append(Vulnerability(
                                name="Open Redirect",
                                severity=Severity.MEDIUM,
                                description=f"Parameter '{param}' can redirect users to external malicious sites",
                                evidence=f"Request to ?{param}={payload} redirected to: {location}",
                                remediation="Validate redirect destinations against a whitelist of allowed URLs. Never redirect to user-supplied URLs directly.",
                                cvss_score=6.1,
                                what_we_tested=f"We tested the '{param}' parameter with an external URL as the redirect target.",
                                indicates="Open redirect allows an attacker to use your domain as a launchpad for phishing.",
                                how_exploited="Attacker crafts a link like yoursite.com/login?redirect=evil.com — victim trusts your domain, lands on attacker's site."
                            ))
                            return vulnerabilities  # One finding is enough
                except Exception:
                    continue
        return vulnerabilities

    # ── Sensitive File Exposure ───────────────────────────────────────────

    def check_sensitive_files(self, base_url: str) -> List[Vulnerability]:
        """
        Probe a curated list of sensitive paths.
        Uses a thread pool for speed. Returns deduplicated findings.
        """
        vulnerabilities = []
        parsed   = urlparse(base_url)
        base     = f"{parsed.scheme}://{parsed.netloc}"
        seen_paths: Set[str] = set()

        def probe(path_info: Tuple) -> Optional[Vulnerability]:
            path, label, severity = path_info
            if path in seen_paths:
                return None
            seen_paths.add(path)
            url = base + path
            try:
                resp = self.session.get(
                    url, timeout=5, verify=False,
                    allow_redirects=False
                )
                # 200 = definitely exposed; 401/403 = exists but protected (still worth reporting for some)
                if resp.status_code == 200:
                    # Extra confirmation: check content isn't just an HTML 404 page
                    content_type = resp.headers.get('Content-Type', '')
                    content_len  = len(resp.content)

                    # Skip if it looks like a generic HTML page (likely a CMS 404)
                    if 'text/html' in content_type and content_len > 50000:
                        return None
                    if severity == Severity.INFO:
                        return None  # robots.txt etc — just skip, too noisy

                    snippet = resp.text[:120].replace('\n', ' ').strip()
                    return Vulnerability(
                        name=f"Exposed {label}",
                        severity=severity,
                        description=f"The file or path '{path}' is publicly accessible and should not be",
                        evidence=f"HTTP 200 from {url} — preview: {snippet}...",
                        remediation=f"Block access to '{path}' via your server/firewall config, or delete the file if it shouldn't exist.",
                        what_we_tested=f"We requested the path '{path}' to check if it is publicly accessible.",
                        indicates=f"Exposed {label} can leak credentials, source code, configuration, or sensitive data.",
                        how_exploited=f"An attacker downloads the file and extracts database passwords, API keys, or source code."
                    )
                elif resp.status_code in (401, 403) and severity in (Severity.CRITICAL, Severity.HIGH):
                    # Protected but exists — worth noting for high-risk paths
                    return Vulnerability(
                        name=f"{label} Exists (Access Restricted)",
                        severity=Severity.LOW,
                        description=f"'{path}' exists on the server but requires authentication",
                        evidence=f"HTTP {resp.status_code} from {url}",
                        remediation=f"Verify this path is intentional. Ensure authentication is robust and consider moving it.",
                        what_we_tested=f"We requested '{path}' to check for its existence.",
                        indicates="The path exists, confirming the technology in use. Authentication prevents direct access.",
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
        """Error-based + time-based blind SQLi detection."""
        vulnerabilities = []
        parsed      = urlparse(url)
        base_params = parse_qs(parsed.query)
        test_params = base_params.copy() if base_params else {'id': ['1']}

        found = False

        # Error-based
        with concurrent.futures.ThreadPoolExecutor(max_workers=3) as executor:
            futures = {}
            for param_name in test_params:
                for payload in self.sql_payloads[:5]:
                    p_copy = test_params.copy()
                    p_copy[param_name] = [payload]
                    test_url = urlunparse((
                        parsed.scheme, parsed.netloc, parsed.path,
                        parsed.params, urlencode(p_copy, doseq=True), parsed.fragment
                    ))
                    f = executor.submit(self._test_sql_payload, test_url, payload, param_name)
                    futures[f] = (payload, param_name)

            for future in concurrent.futures.as_completed(futures):
                if found:
                    break
                payload, param_name = futures[future]
                try:
                    is_vulnerable, evidence = future.result()
                    if is_vulnerable:
                        found = True
                        vulnerabilities.append(Vulnerability(
                            name="SQL Injection (Error-Based)",
                            severity=Severity.CRITICAL,
                            description=f"Parameter '{param_name}' is vulnerable to SQL injection — database errors returned",
                            evidence=f"Payload: {payload[:30]}… | Indicator: {evidence[:60]}",
                            remediation="Use parameterized queries or prepared statements. Never concatenate user input into SQL strings.",
                            cvss_score=9.8,
                            what_we_tested=f"We injected SQL syntax into '{param_name}' and looked for database error messages.",
                            indicates="The application executes user input as part of a SQL query — attacker controls the database.",
                            how_exploited="Attacker extracts all data (usernames, passwords, emails), or in some DBs gains OS-level command execution."
                        ))
                except Exception as e:
                    logger.error(f"SQL test error: {e}")

        # Time-based blind (only if no error-based found — avoids double-reporting)
        if not found:
            for param_name in list(test_params.keys())[:2]:  # Limit to 2 params
                for payload, db_type in self.blind_sql_payloads:
                    p_copy = test_params.copy()
                    p_copy[param_name] = [payload]
                    test_url = urlunparse((
                        parsed.scheme, parsed.netloc, parsed.path,
                        parsed.params, urlencode(p_copy, doseq=True), parsed.fragment
                    ))
                    is_blind, delay = self._test_blind_sql(test_url)
                    if is_blind:
                        found = True
                        vulnerabilities.append(Vulnerability(
                            name=f"SQL Injection (Time-Based Blind — {db_type})",
                            severity=Severity.CRITICAL,
                            description=f"Parameter '{param_name}' causes a {delay:.1f}s server delay with a sleep payload, indicating blind SQL injection",
                            evidence=f"Payload: {payload} | Response delay: {delay:.1f}s (baseline <1s)",
                            remediation="Use parameterized queries. This is a blind injection — no visible error, but fully exploitable.",
                            cvss_score=9.8,
                            what_we_tested=f"We injected time-delay SQL payloads into '{param_name}' and measured response time.",
                            indicates="The application executes user input as SQL even with no visible error output.",
                            how_exploited="Tools like sqlmap can extract the entire database character by character using timing differences."
                        ))
                        break
                if found:
                    break

        return vulnerabilities

    def _test_sql_payload(self, url: str, payload: str, param: str) -> Tuple[bool, str]:
        try:
            response = self.session.get(url, timeout=self.timeout, allow_redirects=False, verify=False)
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
            content = response.text
            for pattern in sql_errors:
                if re.search(pattern, content, re.IGNORECASE):
                    return True, f"SQL error pattern: {pattern[:30]}"
            return False, ""
        except requests.RequestException:
            return False, ""

    def _test_blind_sql(self, url: str, threshold: float = 3.5) -> Tuple[bool, float]:
        """
        Returns (True, delay) if response time suggests a sleep payload executed.
        Tests the same URL twice to rule out slow baseline.
        """
        try:
            # Baseline
            t0    = time.time()
            self.session.get(url.replace(url.split('=')[-1], '1'), timeout=8, verify=False)
            baseline = time.time() - t0

            # Payload
            t0    = time.time()
            self.session.get(url, timeout=12, verify=False)
            delay = time.time() - t0

            if delay > threshold and delay > baseline + 2.5:
                return True, delay
        except requests.Timeout:
            # A timeout itself can indicate the sleep worked
            return True, threshold + 1
        except Exception:
            pass
        return False, 0.0

    # ── XSS ──────────────────────────────────────────────────────────────

    def test_xss(self, url: str) -> List[Vulnerability]:
        vulnerabilities = []
        parsed      = urlparse(url)
        test_params = {'xss_test': ['test']}

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
                    # Check if the page has a CSP that would block it anyway
                    csp = response.headers.get('Content-Security-Policy', '')
                    mitigated = bool(csp and 'unsafe-inline' not in csp)

                    context = self._analyze_xss_context(content, payload)
                    severity = Severity.MEDIUM if mitigated else Severity.HIGH

                    vulnerabilities.append(Vulnerability(
                        name=f"Reflected XSS ({context['type']}){' — CSP may mitigate' if mitigated else ''}",
                        severity=severity,
                        description=f"XSS payload reflected in {context['location']}. {'A CSP header is present which may block execution.' if mitigated else 'No CSP is blocking execution.'}",
                        evidence=f"Payload: {payload[:40]}… | Context: {context['details']}",
                        remediation="HTML-encode all user input before rendering it. Add a strong Content-Security-Policy header.",
                        cvss_score=6.1 if mitigated else 8.8,
                        what_we_tested=f"We injected script payloads into query parameters and checked if they were reflected unencoded in the HTML.",
                        indicates="User-supplied input is rendered without encoding — a classic reflected XSS vulnerability.",
                        how_exploited="Attacker sends victim a link with the payload in the URL. When the victim loads it, the script runs in their browser, stealing cookies or performing actions as them."
                    ))
                    break
            except requests.RequestException:
                continue

        # DOM XSS sink detection
        try:
            response = self.session.get(url, timeout=self.timeout, verify=False)
            dom_sinks = {
                'document.write':       'document.write() with user data can write arbitrary HTML',
                'innerHTML':            'innerHTML assignment can inject HTML and scripts',
                'outerHTML':            'outerHTML assignment can inject HTML and scripts',
                'eval(':                'eval() executing user input enables code injection',
                'setTimeout(':          'setTimeout with string argument evaluates as code',
                'setInterval(':         'setInterval with string argument evaluates as code',
                'location.href':        'Setting location.href from user input enables redirect injection',
            }
            content = response.text
            found_sinks = [
                (sink, desc) for sink, desc in dom_sinks.items()
                if sink in content
            ]
            if found_sinks:
                sink_names = ', '.join(s for s, _ in found_sinks[:3])
                vulnerabilities.append(Vulnerability(
                    name="Potential DOM XSS Sink Detected",
                    severity=Severity.MEDIUM,
                    description=f"Page uses JavaScript patterns that can lead to DOM XSS: {sink_names}",
                    evidence=f"Dangerous sinks found in page source: {sink_names}",
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
            return {'type': 'Script Context',    'location': 'JavaScript block',  'details': 'Inside <script> tag'}
        elif '="' in before or "='" in before:
            return {'type': 'Attribute Context', 'location': 'HTML attribute',    'details': 'Inside HTML attribute value'}
        else:
            return {'type': 'HTML Context',      'location': 'HTML body',         'details': 'Reflected in page body'}

    # ── Information Disclosure ────────────────────────────────────────────

    def check_information_disclosure(self, response: requests.Response, url: str) -> List[Vulnerability]:
        vulnerabilities = []
        headers = response.headers
        content = response.text

        server_header = headers.get('Server', '')
        if server_header and any(char.isdigit() for char in server_header):
            vulnerabilities.append(Vulnerability(
                name="Server Version Disclosure",
                severity=Severity.LOW,
                description="Server header reveals exact version information",
                evidence=f"Server: {server_header}",
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
                evidence=f"X-Powered-By: {powered_by}",
                remediation="Remove X-Powered-By header. In Express.js: app.disable('x-powered-by'). In PHP: expose_php = Off.",
                what_we_tested="We inspected the X-Powered-By response header.",
                indicates="Technology disclosure helps attackers target framework-specific vulnerabilities.",
                how_exploited="Attacker targets known exploits for the disclosed framework version."
            ))

        # Secrets in response body
        sensitive_patterns = [
            (r'AKIA[0-9A-Z]{16}',                                    'AWS Access Key ID',    Severity.CRITICAL),
            (r'aws_secret_access_key\s*[=:]\s*["\']?[A-Za-z0-9/+=]{40}', 'AWS Secret Key',  Severity.CRITICAL),
            (r'sk_live_[0-9a-zA-Z]{24,}',                            'Stripe Live Secret',   Severity.CRITICAL),
            (r'pk_live_[0-9a-zA-Z]{24,}',                            'Stripe Publishable Key', Severity.MEDIUM),
            (r'-----BEGIN (RSA |EC |DSA )?PRIVATE KEY-----',         'Private Key',          Severity.CRITICAL),
            (r'password\s*[=:]\s*["\'][^"\']{6,}',                   'Hardcoded Password',   Severity.HIGH),
            (r'api[_-]?key\s*[=:]\s*["\'][^"\']{8,}',               'API Key',              Severity.HIGH),
            (r'secret[_-]?key\s*[=:]\s*["\'][^"\']{8,}',            'Secret Key',           Severity.HIGH),
            (r'DB_PASSWORD\s*=\s*\S+',                               'Database Password',    Severity.CRITICAL),
            (r'DATABASE_URL\s*=\s*\S+',                              'Database URL',         Severity.HIGH),
            (r'ghp_[A-Za-z0-9]{36}',                                 'GitHub Personal Token', Severity.CRITICAL),
        ]

        for pattern, name, severity in sensitive_patterns:
            if re.search(pattern, content, re.IGNORECASE):
                vulnerabilities.append(Vulnerability(
                    name=f"Exposed {name} in Response",
                    severity=severity,
                    description=f"The page response contains what appears to be a {name}",
                    evidence=f"Pattern matched: {pattern[:40]}…",
                    remediation="Remove all secrets from client-facing code and responses. Use server-side environment variables. Rotate the exposed credential immediately.",
                    what_we_tested=f"We scanned the response body for patterns matching {name} formats.",
                    indicates="A live secret is exposed in the page source — this is an active credential leak.",
                    how_exploited="Attacker reads the page source, copies the credential, and uses it to access your cloud account, database, or payment system."
                ))

        # Mixed content (HTTPS page loading HTTP resources)
        if urlparse(url).scheme == 'https':
            http_resources = re.findall(r'src=["\']http://[^"\']+["\']', content, re.I)
            http_resources += re.findall(r'href=["\']http://[^"\']+\.(?:css|js)["\']', content, re.I)
            if http_resources:
                vulnerabilities.append(Vulnerability(
                    name="Mixed Content (HTTP Resources on HTTPS Page)",
                    severity=Severity.MEDIUM,
                    description=f"HTTPS page loads {len(http_resources)} resource(s) over plain HTTP",
                    evidence=f"Examples: {', '.join(http_resources[:2])}",
                    remediation="Change all resource URLs to HTTPS or use protocol-relative URLs (//example.com/script.js).",
                    what_we_tested="We scanned the page HTML for HTTP resource references on an HTTPS page.",
                    indicates="Mixed content means parts of the page are unencrypted, undermining HTTPS protection.",
                    how_exploited="An attacker can modify the HTTP resource in transit (e.g. inject malicious JavaScript) even though the main page is HTTPS."
                ))

        return vulnerabilities

    # ── Security Misconfigurations ────────────────────────────────────────

    def check_security_misconfigurations(self, response: requests.Response, url: str) -> List[Vulnerability]:
        vulnerabilities = []

        # Cookie analysis
        set_cookie = response.headers.get('Set-Cookie', '')
        if set_cookie:
            if 'Secure' not in set_cookie and urlparse(url).scheme == 'https':
                vulnerabilities.append(Vulnerability(
                    name="Cookie Missing Secure Flag",
                    severity=Severity.MEDIUM,
                    description="Session cookie can be transmitted over unencrypted HTTP connections",
                    evidence="Set-Cookie header lacks the Secure attribute",
                    remediation="Add 'Secure' to all cookie definitions: Set-Cookie: name=value; Secure; HttpOnly; SameSite=Strict",
                    what_we_tested="We checked Set-Cookie response headers for the Secure attribute.",
                    indicates="The cookie can leak over HTTP, exposing session tokens.",
                    how_exploited="On any HTTP connection (e.g. after a redirect), the browser sends the cookie unencrypted — attacker reads it and hijacks the session."
                ))
            if 'HttpOnly' not in set_cookie:
                vulnerabilities.append(Vulnerability(
                    name="Cookie Missing HttpOnly Flag",
                    severity=Severity.MEDIUM,
                    description="Session cookie is readable by JavaScript — exploitable via XSS",
                    evidence="Set-Cookie header lacks the HttpOnly attribute",
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
                    evidence="Set-Cookie header lacks the SameSite attribute",
                    remediation="Add 'SameSite=Strict' or 'SameSite=Lax' to cookies: Set-Cookie: name=value; SameSite=Strict",
                    what_we_tested="We checked Set-Cookie headers for the SameSite attribute.",
                    indicates="Cross-Site Request Forgery (CSRF) is possible if no other CSRF protection exists.",
                    how_exploited="Attacker's site makes a POST request to your site — the browser sends the cookie automatically, performing actions as the logged-in user."
                ))

        return vulnerabilities

    # ── Vulnerability Deduplication ───────────────────────────────────────

    @staticmethod
    def deduplicate(vulnerabilities: List[Vulnerability]) -> List[Vulnerability]:
        """
        Remove duplicate findings.
        Exact key matches are dropped; header findings that differ only by page
        path are grouped into one (keeping the most severe occurrence).
        """
        seen: Dict[str, Vulnerability] = {}
        for vuln in vulnerabilities:
            key = vuln.dedup_key()
            if key not in seen:
                seen[key] = vuln
            else:
                # Keep higher severity if we see it again
                existing = seen[key]
                sev_order = [Severity.CRITICAL, Severity.HIGH, Severity.MEDIUM,
                             Severity.LOW, Severity.INFO, Severity.SECURE]
                if sev_order.index(vuln.severity) < sev_order.index(existing.severity):
                    seen[key] = vuln
        return list(seen.values())

    # ── Full Scan Orchestrator ────────────────────────────────────────────

    def perform_scan(self, url: str, enable_port_scan: bool = True,
                     port_scan_type: str = "connect",
                     custom_ports: Optional[List[int]] = None) -> ScanResult:

        GLOBAL_TIMEOUT = 180  # seconds — full scan must complete within 3 minutes
        start_time     = time.time()
        timestamp      = now_eat_iso()

        is_valid, validation_result = self.validate_url(url)
        if not is_valid:
            return ScanResult(
                target=url, timestamp=timestamp, scan_duration=0,
                ssl_info={}, headers={}, vulnerabilities=[],
                port_scan={}, summary={}, error=validation_result
            )

        clean_url          = validation_result
        all_vulnerabilities: List[Vulnerability] = []
        port_scan_results  = {}

        def run_module(name: str, fn, *args, **kwargs):
            """Run a scan module with error isolation."""
            if time.time() - start_time > GLOBAL_TIMEOUT:
                logger.warning(f"Skipping {name} — global timeout reached")
                return None
            try:
                return fn(*args, **kwargs)
            except Exception as e:
                logger.error(f"Module '{name}' failed: {e}", exc_info=True)
                return None

        try:
            # Initial HTTP fetch
            logger.info(f"Starting scan of {clean_url}")
            try:
                response  = self.session.get(
                    clean_url, timeout=self.timeout,
                    allow_redirects=True, verify=False
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

            # ── Run all modules ───────────────────────────────────────────

            ssl_info       = run_module("ssl",     self.check_ssl_tls, clean_url)         or {'vulnerabilities': [], 'https': False, 'grade': 'F', 'protocols': [], 'status': 'error'}
            server_info    = run_module("server",  self.get_server_info, response)        or {}
            headers_info   = run_module("headers", self.check_security_headers, response) or {'present': [], 'missing': [], 'misconfigured': [], 'score': 0, 'max_score': 1, 'percentage': 0}
            cors_vulns     = run_module("cors",    self.check_cors, final_url)            or []
            redirect_vulns = run_module("redirect",self.check_open_redirect, final_url)   or []
            file_vulns     = run_module("files",   self.check_sensitive_files, clean_url) or []
            sql_vulns      = run_module("sqli",    self.test_sql_injection, final_url)    or []
            xss_vulns      = run_module("xss",     self.test_xss, final_url)             or []
            info_vulns     = run_module("info",    self.check_information_disclosure, response, final_url) or []
            cfg_vulns      = run_module("config",  self.check_security_misconfigurations, response, final_url) or []

            # SSL vulns
            all_vulnerabilities.extend(ssl_info.get('vulnerabilities', []))

            # Header report lookup
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
                    "Without this header, the full URL of your pages is leaked to every third-party resource (analytics, fonts, ads).",
                    "Third-party services receive URLs that may contain session tokens or sensitive query parameters."
                ),
                'Permissions-Policy': (
                    "We checked for the Permissions-Policy header.",
                    "Without this, any script on the page (including third-party) can request camera, microphone, or geolocation access.",
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
                rec = self.header_recommended.get(missing['name'], '')
                remediation = (f"Add this to your server config: {missing['name']}: {rec}" if rec
                               else f"Implement the {missing['name']} header.")
                all_vulnerabilities.append(Vulnerability(
                    name=f"Missing {missing['name']}",
                    severity=severity,
                    description=missing['description'],
                    evidence=f"{missing['name']} was not found in the HTTP response.",
                    remediation=remediation,
                    what_we_tested=tested,
                    indicates=indicates,
                    how_exploited=exploited
                ))

            # ── Crawler: per-page header check ────────────────────────────
            discovered_urls = run_module("crawl", self.crawl_domain, clean_url, 12) or [clean_url]
            pages_checked: List[str] = [clean_url]
            per_url_issues: Dict[str, int] = {}

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
                            f"Missing {missing['name']}.",
                            "Implement the header on all pages."
                        ))
                        rec = self.header_recommended.get(missing['name'], '')
                        remediation = (f"Add to all pages including {page_path}: {missing['name']}: {rec}" if rec
                                       else f"Implement {missing['name']} on all pages including {page_path}.")
                        all_vulnerabilities.append(Vulnerability(
                            name=f"Missing {missing['name']} (page: {page_path})",
                            severity=severity,
                            description=missing['description'] + f" [Page: {page_path}]",
                            evidence=f"{missing['name']} absent on {page_path}",
                            remediation=remediation,
                            what_we_tested=tested,
                            indicates=indicates,
                            how_exploited=exploited
                        ))
                        issue_count += 1
                    per_url_issues[page_url] = issue_count
                except Exception:
                    continue

            # Add all module results
            all_vulnerabilities.extend(cors_vulns)
            all_vulnerabilities.extend(redirect_vulns)
            all_vulnerabilities.extend(file_vulns)
            all_vulnerabilities.extend(sql_vulns)
            all_vulnerabilities.extend(xss_vulns)
            all_vulnerabilities.extend(info_vulns)
            all_vulnerabilities.extend(cfg_vulns)

            # ── Port scan ─────────────────────────────────────────────────
            if enable_port_scan:
                logger.info("Starting port scan…")
                parsed   = urlparse(clean_url)
                hostname = parsed.hostname
                port_scan_results = run_module(
                    "ports", self.port_scanner.scan_host,
                    hostname, custom_ports, port_scan_type
                ) or {}
                if 'vulnerabilities' in port_scan_results:
                    all_vulnerabilities.extend(port_scan_results['vulnerabilities'])

            # ── Deduplicate ───────────────────────────────────────────────
            all_vulnerabilities = self.deduplicate(all_vulnerabilities)
# ── Scoring ───────────────────────────────────────────────────
            severity_counts = {s.value: 0 for s in Severity}
            for vuln in all_vulnerabilities:
                severity_counts[vuln.severity.value] += 1

            # Raw weighted sum
            raw_score = (
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

            scan_duration = round(time.time() - start_time, 2)

            # ── Friendly summary ──────────────────────────────────────────
            messages = {
                'Secure':   "Your site looks secure based on the checks we ran. No obvious issues found.",
                'Low':      "We found a few low-risk issues. Worth fixing, but no immediate emergency.",
                'Medium':   "We found issues that could be used to attack your site. Plan to fix these soon.",
                'High':     "We found serious security problems. These should be fixed as a high priority.",
                'Critical': "We found critical security problems. Your site is at high risk — fix these immediately."
            }
            friendly_parts = [messages[risk_level]]

            top_types: Set[str] = set()
            for v in all_vulnerabilities:
                if v.severity in (Severity.CRITICAL, Severity.HIGH):
                    n = v.name.lower()
                    if 'sql' in n:
                        top_types.add("database attacks (SQL injection)")
                    elif 'xss' in n or 'cross-site scripting' in n:
                        top_types.add("malicious scripts in visitors' browsers (XSS)")
                    elif 'redirect' in n:
                        top_types.add("open redirect (phishing launchpad)")
                    elif 'cors' in n:
                        top_types.add("cross-origin data theft (CORS)")
                    elif 'exposed' in n and ('env' in n or 'config' in n or 'git' in n):
                        top_types.add("exposed credentials or config files")
                    elif 'exposed' in n and ('redis' in n or 'mongodb' in n or 'mysql' in n):
                        top_types.add("exposed database services")
                    elif 'cookie' in n:
                        top_types.add("weak cookie settings")
                    elif 'ssl' in n or 'tls' in n or 'certificate' in n:
                        top_types.add("HTTPS/certificate problems")
                    elif 'header' in n:
                        top_types.add("missing browser security headers")

            if top_types:
                friendly_parts.append(
                    "Priority issues: " + ", ".join(sorted(top_types)) + "."
                )

            priority_actions: List[str] = []
            if severity_counts['critical'] or severity_counts['high']:
                priority_actions.append("Fix all Critical and High items first — focus your developer there.")
            if ssl_info.get('grade') in ('C', 'D', 'F'):
                priority_actions.append("Upgrade your HTTPS/TLS configuration immediately.")
            if headers_info.get('percentage', 0) < 70:
                priority_actions.append("Add security headers (CSP, HSTS, X-Frame-Options) to your web server config.")
            if any('Exposed' in v.name and ('env' in v.name.lower() or 'git' in v.name.lower()) for v in all_vulnerabilities):
                priority_actions.append("Delete or block access to .env and .git files on your server right now.")
            if not priority_actions:
                priority_actions.append("Keep dependencies updated and re-scan regularly.")

            friendly_summary = {
                'message':          " ".join(friendly_parts),
                'priority_actions': priority_actions
            }

            crawler_result = {
                'discovered_urls': discovered_urls,
                'pages_checked':   pages_checked,
                'per_url_issues':  per_url_issues,
            }

            return ScanResult(
                target=clean_url,
                timestamp=timestamp,
                scan_duration=scan_duration,
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
                    'scan_status':           'completed',
                    'user_friendly':         friendly_summary
                },
                server_info=server_info,
                crawler=crawler_result
            )

        except Exception as e:
            logger.error(f"Scan error: {e}", exc_info=True)
            return ScanResult(
                target=clean_url, timestamp=timestamp,
                scan_duration=round(time.time() - start_time, 2),
                ssl_info={}, headers={}, vulnerabilities=all_vulnerabilities,
                port_scan={}, summary={}, error=f"Scan failed: {str(e)}",
                server_info=None, crawler=None
            )


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

        if port_scan_type not in ['connect', 'syn']:
            port_scan_type = 'connect'

        result = scanner.perform_scan(
            target,
            enable_port_scan=enable_port_scan,
            port_scan_type=port_scan_type,
            custom_ports=custom_ports
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
                    'name':          v.name,
                    'severity':      v.severity.value,
                    'description':   v.description,
                    'evidence':      v.evidence,
                    'remediation':   v.remediation,
                    'cvss_score':    v.cvss_score,
                    'what_we_tested': getattr(v, 'what_we_tested', None),
                    'indicates':     getattr(v, 'indicates', None),
                    'how_exploited': getattr(v, 'how_exploited', None)
                } for v in result.vulnerabilities
            ],
            'summary': result.summary,
            'error':   result.error
        }

        return jsonify(response_data)

    except Exception as e:
        logger.error(f"Endpoint error: {e}", exc_info=True)
        return jsonify({'error': 'Internal server error', 'details': str(e)}), 500


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

        ports       = data.get('ports')
        scan_type   = data.get('scan_type', 'connect')
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
                {
                    'name':     v.name,
                    'severity': v.severity.value,
                    'description': v.description
                } for v in result.get('vulnerabilities', [])
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
        'version':   '2.2.0',
        'features':  [
            'ssl_scan', 'header_analysis', 'sqli_test', 'xss_test',
            'port_scan', 'cors_check', 'open_redirect', 'sensitive_files',
            'blind_sqli', 'mixed_content', 'secret_detection', 'deduplication'
        ]
    })


if __name__ == "__main__":
    print("=" * 55)
    print("ScanQuotient Security Scanner Backend v2.2")
    print("=" * 55)
    print("NEW: CORS check, open redirect, 40+ sensitive file probes,")
    print("     blind SQLi, mixed content, secret detection, deduplication")
    print("Server: http://0.0.0.0:5000")
    print("=" * 55)
    app.run(host='0.0.0.0', port=5000, debug=True, threaded=True, use_reloader=False)