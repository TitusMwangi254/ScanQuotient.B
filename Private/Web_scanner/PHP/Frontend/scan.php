<?php
session_start();
$currentUserName = $_SESSION['user_name'] ?? 'User';
$profile_photo = $_SESSION['profile_photo'] ?? null;
if (!empty($profile_photo)) {
    $photo_path = ltrim((string) $profile_photo, '/');
    $base_url = '/ScanQuotient.v2/ScanQuotient.B';
    $avatar_url = $base_url . '/' . $photo_path;
} else {
    $avatar_url = '/ScanQuotient.v2/ScanQuotient.B/Storage/Public_images/default-avatar.png';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title> ScanQuotient | Security Scan</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css  ">
    <link rel="stylesheet" href="../../CSS/scanner.css" />
    <!-- Local vendored libs (avoid CDN/network issues) -->
    <script src="../../Javascript/vendor/html2canvas.min.js"></script>
    <script src="../../Javascript/vendor/jspdf.umd.min.js"></script>
</head>

<body>

    <header class="page-header">
        <div class="header-left">
            <a href="#" class="header-brand">ScanQuotient</a>
            <span class="header-tagline">Quantifying Risk. Strengthening Security.</span>
        </div>
        <div class="header-right">
            <img src="<?php echo htmlspecialchars($avatar_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile" class="header-profile-photo">
            <span class="welcome-text">
                <?php echo htmlspecialchars($currentUserName, ENT_QUOTES, 'UTF-8'); ?> |
                <span id="current-date"></span> |
                <span id="current-time"></span>
            </span>
            <button id="helpBtn" class="icon-btn" title="Help"><i class="fas fa-question-circle"></i></button>
            <a href="/ScanQuotient.v2/ScanQuotient.B/Private/User_dashboard/PHP/Frontend/User_dashboard.php"
                class="icon-btn" title="Home">
                <i class="fas fa-home"></i>
            </a>
            <a href="../../../../Public/Login_page/PHP/Frontend/Login_page_site.php" class="icon-btn" title="Logout"><i
                    class="fas fa-sign-out-alt"></i></a>
        </div>
    </header>

    <div class="app">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="brand">Navigation</div>
            <nav>
                <a href="/ScanQuotient.v2/ScanQuotient.B/Private/User_dashboard/PHP/Frontend/User_dashboard.php"
                    class="nav-btn"><i class="fas fa-home"></i><span>Dashboard</span></a>
                <a href="#" class="nav-btn active"><i class="fas fa-shield-alt"></i><span>New Scan</span></a>
                <a href="/ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Frontend/historical_scans.php"
                    class="nav-btn"><i class="fas fa-history"></i><span>History</span></a>
                <div class="nav-divider"></div>
                <a href="/ScanQuotient.v2/ScanQuotient.B/Public/Help_center/PHP/Frontend/Help_center.php"
                    class="nav-btn"><i class="fas fa-headset"></i><span>Help Center</span></a>
                <a href="../../../../Private/User_account/PHP/Frontend/User_subscription.php" class="nav-btn"><i
                        class="fas fa-user-cog"></i><span>Account</span></a>
                <button id="themeToggle" class="nav-btn theme-toggle-btn">
                    <i class="fas fa-sun"></i><span>Toggle Theme</span>
                </button>
            </nav>
        </aside>

        <main class="main">
            <div class="scan-container">

                <!-- Scan Card -->
                <div class="scan-card">
                    <div class="scan-header">
                        <div class="scan-icon">
                            <i class="fas fa-search"></i>
                        </div>
                        <h1>Vulnerability Assessment</h1>
                        <p>Enter a target URL to perform comprehensive security checks including SQL injection, XSS,
                            headers, and SSL validation.</p>
                    </div>

                    <div class="scan-body">
                        <!-- Input Section -->
                        <div class="input-group">
                            <label class="input-label">
                                <i class="fas fa-globe"></i>
                                Target URL
                            </label>
                            <div class="url-input-wrapper">
                                <input type="url" id="targetURL" class="url-input" placeholder="https://example.com  "
                                    required>
                                <button type="button" id="scanBtn" class="scan-btn">
                                    <img src="../../../../Storage/Public_images/page_icon.png" alt="" class="scan-btn-icon" aria-hidden="true">
                                    Start Scan
                                </button>
                            </div>
                        </div>

                        <!-- Error Message -->
                        <div id="errorMessage" class="error-message">
                            <i class="fas fa-exclamation-circle"></i>
                            <span id="errorText">Please enter a valid URL starting with http or https</span>
                        </div>

                        <!-- Loader -->
                        <div id="loader" class="loader-container">
                            <div class="loader-spinner"></div>
                            <div class="loader-text">Scanning target application</div>
                        </div>

                        <!-- Results -->
                        <div id="results" class="results-container">
                            <!-- Risk Summary -->
                            <div class="risk-summary" id="riskSummary">
                                <div class="risk-score-wrapper" id="riskScoreWrapper" title="Hover for scale">
                                    <div class="risk-score-circle" id="riskScoreCircle">
                                        <div class="risk-score-inner">
                                            <div class="risk-score-value" id="riskScoreValue">0</div>
                                            <div class="risk-score-label">Risk Score</div>
                                        </div>
                                    </div>
                                    <div class="risk-score-tooltip" id="riskScoreTooltip" aria-hidden="true">
                                        <p class="risk-tooltip-title">Risk score <strong><span
                                                    id="riskTooltipScore">0</span>/100</strong> — <span
                                                id="riskTooltipLevel">Secure</span></p>
                                        <p class="risk-tooltip-desc" id="riskTooltipDesc">This score summarizes overall
                                            risk based on detected issues and security hardening signals.</p>
                                        <div class="risk-scale-bar">
                                            <span class="risk-scale-seg good" title="0–30: Low risk">Good</span>
                                            <span class="risk-scale-seg neutral"
                                                title="31–60: Medium risk">Neutral</span>
                                            <span class="risk-scale-seg bad"
                                                title="61–100: High/Critical risk">Bad</span>
                                        </div>
                                        <div class="risk-tooltip-breakdown" id="riskTooltipBreakdown"
                                            style="margin-top:10px; display:grid; grid-template-columns: 1fr 1fr; gap:6px;">
                                            <!-- Filled by JS -->
                                        </div>
                                    </div>
                                </div>
                                <div class="risk-details">
                                    <h3 id="riskLevel">Secure</h3>
                                    <div class="risk-meta">
                                        <span><i class="fas fa-clock"></i> <span id="scanDuration">0s</span></span>
                                        <span><i class="fas fa-calendar"></i> <span id="scanTimestamp">--</span></span>
                                        <span><i class="fas fa-bug"></i> <span id="totalVulns">0</span> issues
                                            found</span>
                                    </div>
                                </div>
                                <div class="severity-counts" id="severityCounts">
                                    <!-- Populated by JS -->
                                </div>
                            </div>

                            <!-- SSL, Headers, Server & Crawler Summary -->
                            <div class="summary-grid">
                                <div class="summary-card">
                                    <div class="summary-card-title">
                                        <i class="fas fa-lock"></i>
                                        SSL/TLS Configuration
                                    </div>
                                    <div class="ssl-grade">
                                        <div class="grade-badge a-plus" id="sslGrade">A+</div>
                                        <div class="ssl-info">
                                            <div class="ssl-protocol" id="sslProtocol">TLS 1.3</div>
                                            <div class="ssl-status" id="sslStatus">Secure HTTPS</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="summary-card">
                                    <div class="summary-card-title">
                                        <i class="fas fa-shield-alt"></i>
                                        Security Headers
                                    </div>
                                    <div class="headers-score">
                                        <div class="score-bar">
                                            <div class="score-fill high" id="headersScoreBar" style="width: 0%"></div>
                                        </div>
                                        <div class="score-text" id="headersScoreText">0%</div>
                                    </div>
                                    <div class="headers-details" id="headersDetails">
                                        0 of 7 headers present
                                    </div>
                                </div>

                                <div class="summary-card" id="serverInfoCard">
                                    <div class="summary-card-title">
                                        <i class="fas fa-server"></i>
                                        Server
                                    </div>
                                    <div id="serverInfoContent" class="server-info-content">—</div>
                                </div>

                                <div class="summary-card" id="crawlerCard">
                                    <div class="summary-card-title">
                                        <i class="fas fa-sitemap"></i>
                                        Domain discovery
                                    </div>
                                    <div id="crawlerContent" class="crawler-content">—</div>
                                </div>
                            </div>

                            <!-- Tabs: Detailed Findings | Human-readable report -->
                            <div class="results-tabs">
                                <button type="button" class="results-tab active" data-tab="findings" id="tabFindings">
                                    <i class="fas fa-clipboard-list"></i>
                                    <span>Detailed Findings</span>
                                </button>
                                <button type="button" class="results-tab" data-tab="report" id="tabReport">
                                    <i class="fas fa-file-alt"></i>
                                    <span>User report</span>
                                </button>
                                <span id="targetBadge" class="target-badge">example.com</span>
                                <div class="report-actions report-download-row">
                                    <div class="report-artefact-btns">
                                        <button type="button" id="downloadCsvBtn" class="artefact-btn"
                                            title="Download CSV">
                                            <i class="fas fa-file-csv"></i><span>CSV</span>
                                        </button>
                                        <button type="button" id="downloadHtmlBtn" class="artefact-btn"
                                            title="Download HTML">
                                            <i class="fas fa-file-code"></i><span>HTML</span>
                                        </button>
                                        <button type="button" id="downloadPdfBtn" class="artefact-btn"
                                            title="Download PDF">
                                            <i class="fas fa-file-pdf"></i><span>PDF</span>
                                        </button>
                                    </div>
                                    <div class="report-extra-actions">
                                        <button type="button" id="aiOverviewBtn" class="artefact-btn secondary"
                                            title="AI Overview (Enterprise)" style="display:none;">
                                            <i class="fas fa-brain"></i><span>AI Overview</span>
                                        </button>
                                        <button type="button" id="shareResultsBtn" class="artefact-btn share-btn"
                                            title="Share (coming soon)"
                                            style="display:inline-flex; opacity:0.55; filter: blur(0.2px); cursor:not-allowed;"
                                            disabled>
                                            <i class="fas fa-share-alt"></i><span>Share (Soon)</span>
                                        </button>
                                    </div>
                                </div>
                                <p id="upgradeMessage" class="upgrade-msg"
                                    style="display:none; margin-top:8px; font-size:12px; color: var(--text-light);"></p>
                            </div>

                            <div class="results-panel active" id="panelFindings">
                                <div class="vulnerabilities-list" id="vulnerabilitiesList">
                                    <!-- Populated by JS -->
                                </div>
                            </div>
                            <div class="results-panel" id="panelReport">
                                <div class="human-report-panel" id="humanReportPanel">
                                    <p class="human-report-placeholder">Run a scan, then click &quot;Human-readable
                                        report&quot; to read the AI-generated summary.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <footer class="page-footer">
        <div class="footer-left">
            <a href="#" class="footer-brand">ScanQuotient</a>
            <span class="footer-sub">Quantifying Risk. Strengthening Security.</span>
        </div>
        <div class="footer-center">
            &copy; 2026 Authorized Security Testing
        </div>
        <div class="footer-right">
            info.scanquotient@gmail.com
        </div>
    </footer>

    <button type="button" id="backToTopBtn" class="back-to-top" title="Back to top" aria-label="Back to top">
        <i class="fas fa-arrow-up"></i>
    </button>

    <!-- Risk Score Modal (click the score circle) -->
    <div id="riskModal" class="modal-overlay"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:10002; align-items:center; justify-content:center;">
        <div class="modal"
            style="background:var(--bg-card); padding:22px; border-radius:16px; max-width:720px; width:92%; border:1px solid var(--border-color);">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:12px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div
                        style="width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:rgba(59,130,246,0.12);color:var(--brand-color);">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div>
                        <div style="font-size:16px;font-weight:800;">Risk score analysis</div>
                        <div style="font-size:12px;color:var(--text-light);">Score, bands, and severity breakdown</div>
                    </div>
                </div>
                <button type="button" id="riskModalClose" class="icon-btn" title="Close"
                    style="width:38px;height:38px;"><i class="fas fa-times"></i></button>
            </div>

            <div style="display:grid; grid-template-columns: 260px 1fr; gap:18px; align-items:start;">
                <div class="risk-modal-left"
                    style="padding:14px; border-radius:14px; border:1px solid var(--border-color); background:var(--bg-main);">
                    <div style="display:flex; align-items:center; justify-content:center; margin-bottom:10px;">
                        <svg width="190" height="190" viewBox="0 0 120 120" aria-label="Risk donut">
                            <circle cx="60" cy="60" r="46" stroke="rgba(100,116,139,0.25)" stroke-width="12"
                                fill="none"></circle>
                            <circle id="riskDonut" cx="60" cy="60" r="46" stroke="var(--brand-color)" stroke-width="12"
                                fill="none" stroke-linecap="round" stroke-dasharray="289" stroke-dashoffset="289"
                                transform="rotate(-90 60 60)"></circle>
                            <text x="60" y="58" text-anchor="middle" font-size="22" font-weight="800"
                                fill="var(--text-main)" id="riskModalScore">0</text>
                            <text x="60" y="76" text-anchor="middle" font-size="10" fill="var(--text-light)">out of
                                100</text>
                        </svg>
                    </div>
                    <div style="text-align:center;">
                        <div id="riskModalLevel" style="font-weight:800; font-size:14px;">Secure Risk</div>
                        <div id="riskModalDesc"
                            style="margin-top:6px; font-size:12px; color:var(--text-light); line-height:1.5;">—</div>
                    </div>
                </div>

                <div class="risk-modal-right">
                    <div
                        style="padding:14px; border-radius:14px; border:1px solid var(--border-color); background:var(--bg-main); margin-bottom:12px;">
                        <div style="font-size:13px; font-weight:700; margin-bottom:10px;">Score bands</div>
                        <div class="risk-band">
                            <div class="risk-band-row"><span>Good</span><span>0–30</span></div>
                            <div class="risk-band-bar">
                                <div class="seg good"></div>
                            </div>
                        </div>
                        <div class="risk-band">
                            <div class="risk-band-row"><span>Neutral</span><span>31–60</span></div>
                            <div class="risk-band-bar">
                                <div class="seg neutral"></div>
                            </div>
                        </div>
                        <div class="risk-band">
                            <div class="risk-band-row"><span>Bad</span><span>61–100</span></div>
                            <div class="risk-band-bar">
                                <div class="seg bad"></div>
                            </div>
                        </div>
                    </div>

                    <div
                        style="padding:14px; border-radius:14px; border:1px solid var(--border-color); background:var(--bg-main);">
                        <div style="font-size:13px; font-weight:700; margin-bottom:10px;">Severity breakdown</div>
                        <div class="sev-bars" id="riskModalBars"></div>
                        <div style="margin-top:10px; font-size:12px; color:var(--text-light); line-height:1.5;">
                            Fix Critical and High first, then Medium, then Low (hardening).
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Theme Management
        const themeToggle = document.getElementById('themeToggle');
        function setTheme(theme) {
            document.body.classList.toggle('dark', theme === 'dark');
            if (themeToggle) {
                const icon = theme === 'dark' ? 'fa-moon' : 'fa-sun';
                const text = theme === 'dark' ? 'Dark Mode' : 'Light Mode';
                themeToggle.innerHTML = '<i class="fas ' + icon + '"></i><span>' + text + '</span>';
            }
        }
        if (themeToggle) {
            themeToggle.addEventListener('click', function () {
                const current = document.body.classList.contains('dark') ? 'light' : 'dark';
                setTheme(current);
                localStorage.setItem('theme', current);
            });
        }
        setTheme(localStorage.getItem('theme') || 'light');

        // Date/Time
        function updateDateTime() {
            var cd = document.getElementById('current-date');
            var ct = document.getElementById('current-time');
            if (cd && ct) {
                var now = new Date();
                cd.textContent = now.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
                ct.textContent = now.toLocaleTimeString();
            }
        }
        updateDateTime();
        setInterval(updateDateTime, 1000);

        // Severity configuration
        const severityConfig = {
            critical: { icon: 'fa-skull-crossbones', color: 'critical' },
            high: { icon: 'fa-exclamation-triangle', color: 'high' },
            medium: { icon: 'fa-exclamation-circle', color: 'medium' },
            low: { icon: 'fa-info-circle', color: 'low' },
            info: { icon: 'fa-info', color: 'low' },
            secure: { icon: 'fa-check-circle', color: 'secure' }
        };

        // Scan Functionality
        const scanBtn = document.getElementById('scanBtn');
        const targetInput = document.getElementById('targetURL');
        const urlParam = new URLSearchParams(window.location.search).get('url');
        if (urlParam && targetInput) {
            try {
                targetInput.value = decodeURIComponent(urlParam);
            } catch (e) {
                targetInput.value = urlParam;
            }
        }
        const loader = document.getElementById('loader');
        const results = document.getElementById('results');
        const errorMessage = document.getElementById('errorMessage');
        const errorText = document.getElementById('errorText');

        function formatTimestamp(isoString) {
            if (!isoString || isoString === '--') return '--';
            const date = new Date(isoString);
            if (Number.isNaN(date.getTime())) return '--';
            // Force display in East Africa Time (Africa/Nairobi) for consistency.
            return new Intl.DateTimeFormat(undefined, {
                timeZone: 'Africa/Nairobi',
                year: 'numeric',
                month: 'short',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            }).format(date);
        }

        function getIconForVulnerability(name) {
            const nameLower = name.toLowerCase();
            if (nameLower.includes('sql')) return 'fa-database';
            if (nameLower.includes('xss') || nameLower.includes('script')) return 'fa-code';
            if (nameLower.includes('ssl') || nameLower.includes('tls') || nameLower.includes('certificate')) return 'fa-lock';
            if (nameLower.includes('header')) return 'fa-shield-alt';
            if (nameLower.includes('cookie')) return 'fa-cookie';
            if (nameLower.includes('information') || nameLower.includes('disclosure')) return 'fa-eye';
            if (nameLower.includes('injection')) return 'fa-syringe';
            return 'fa-bug';
        }

        function toggleVuln(index) {
            const card = document.getElementById(`vuln-${index}`);
            card.classList.toggle('expanded');
        }

        function updateRiskDisplay(summary) {
            const score = summary.risk_score || 0;
            const level = summary.risk_level || 'Secure';
            lastSummaryForModal = summary || null;

            // Update circle
            const circle = document.getElementById('riskScoreCircle');
            circle.className = 'risk-score-circle';
            if (level === 'Critical') circle.classList.add('critical');
            else if (level === 'High') circle.classList.add('high');
            else if (level === 'Medium') circle.classList.add('medium');
            else if (level === 'Low') circle.classList.add('low');

            document.getElementById('riskScoreValue').textContent = score;
            document.getElementById('riskLevel').textContent = level + ' Risk';

            // Tooltip content
            const tipScore = document.getElementById('riskTooltipScore');
            const tipLevel = document.getElementById('riskTooltipLevel');
            const tipDesc = document.getElementById('riskTooltipDesc');
            const tipBreak = document.getElementById('riskTooltipBreakdown');
            if (tipScore) tipScore.textContent = score;
            if (tipLevel) tipLevel.textContent = level;
            if (tipDesc) {
                if (score <= 30) tipDesc.textContent = 'Good: low risk posture. Focus on any remaining medium/high issues and hardening.';
                else if (score <= 60) tipDesc.textContent = 'Neutral: moderate risk. Address missing headers, SSL issues, and medium/high findings.';
                else tipDesc.textContent = 'Bad: high risk. Prioritize critical/high findings and security headers immediately.';
            }
            if (tipBreak) {
                const b = summary.severity_breakdown || {};
                const items = [
                    { k: 'critical', label: 'Critical', cls: 'critical', v: b.critical || 0 },
                    { k: 'high', label: 'High', cls: 'high', v: b.high || 0 },
                    { k: 'medium', label: 'Medium', cls: 'medium', v: b.medium || 0 },
                    { k: 'low', label: 'Low', cls: 'low', v: b.low || 0 },
                ];
                tipBreak.innerHTML = items.map(it => `
                    <div class="risk-mini-chip ${it.cls}">
                        <span class="risk-mini-dot"></span>
                        <span>${it.v} ${it.label}</span>
                    </div>
                `).join('');
            }

            // Severity badges
            const container = document.getElementById('severityCounts');
            const breakdown = summary.severity_breakdown || {};
            const badges = [];

            if (breakdown.critical > 0) badges.push(`<span class="severity-badge critical"><i class="fas fa-skull"></i> ${breakdown.critical} Critical</span>`);
            if (breakdown.high > 0) badges.push(`<span class="severity-badge high"><i class="fas fa-exclamation-triangle"></i> ${breakdown.high} High</span>`);
            if (breakdown.medium > 0) badges.push(`<span class="severity-badge medium"><i class="fas fa-exclamation-circle"></i> ${breakdown.medium} Medium</span>`);
            if (breakdown.low > 0) badges.push(`<span class="severity-badge low"><i class="fas fa-info-circle"></i> ${breakdown.low} Low</span>`);

            if (badges.length === 0) {
                badges.push(`<span class="severity-badge" style="background: rgba(16,185,129,0.1); color: var(--success-color)"><i class="fas fa-check-circle"></i> Secure</span>`);
            }

            container.innerHTML = badges.join('');
            document.getElementById('totalVulns').textContent = summary.total_vulnerabilities || 0;
        }

        // Risk modal (click score circle)
        let lastSummaryForModal = null;
        let lastVulnerabilities = [];
        (function () {
            const wrap = document.getElementById('riskScoreWrapper');
            const modal = document.getElementById('riskModal');
            const closeBtn = document.getElementById('riskModalClose');
            function close() {
                if (!modal) return;
                modal.style.display = 'none';
                modal.classList.remove('active');
            }
            function open() {
                if (!modal || !lastSummaryForModal) return;
                const score = lastSummaryForModal.risk_score || 0;
                const level = lastSummaryForModal.risk_level || 'Secure';
                const breakdown = lastSummaryForModal.severity_breakdown || {};
                const total = (breakdown.critical || 0) + (breakdown.high || 0) + (breakdown.medium || 0) + (breakdown.low || 0);

                const scoreEl = document.getElementById('riskModalScore');
                const levelEl = document.getElementById('riskModalLevel');
                const descEl = document.getElementById('riskModalDesc');
                if (scoreEl) scoreEl.textContent = score;
                if (levelEl) levelEl.textContent = level + ' Risk';
                if (descEl) {
                    if (score <= 30) descEl.textContent = 'Good: low risk posture. Keep hardening and fix any remaining medium/high findings.';
                    else if (score <= 60) descEl.textContent = 'Neutral: moderate risk. Prioritize missing headers, SSL issues, and medium/high findings.';
                    else descEl.textContent = 'Bad: high risk. Prioritize critical/high findings and security headers immediately.';
                }

                const donut = document.getElementById('riskDonut');
                if (donut) {
                    const circumference = 2 * Math.PI * 46; // r=46
                    const pct = Math.max(0, Math.min(100, score)) / 100;
                    donut.setAttribute('stroke-dasharray', String(circumference));
                    donut.setAttribute('stroke-dashoffset', String(circumference * (1 - pct)));
                    let color = 'var(--success-color)';
                    if (score > 60) color = 'var(--danger-color)';
                    else if (score > 30) color = 'var(--warning-color)';
                    donut.setAttribute('stroke', color);
                }

                const bars = document.getElementById('riskModalBars');
                if (bars) {
                    const items = [
                        { label: 'Critical', cls: 'critical', v: breakdown.critical || 0 },
                        { label: 'High', cls: 'high', v: breakdown.high || 0 },
                        { label: 'Medium', cls: 'medium', v: breakdown.medium || 0 },
                        { label: 'Low', cls: 'low', v: breakdown.low || 0 },
                    ];
                    bars.innerHTML = items.map(it => {
                        const pct = total ? Math.round((it.v / total) * 100) : 0;
                        return `
                            <div class="sev-row">
                                <div class="sev-label"><span class="dot ${it.cls}"></span>${it.label}</div>
                                <div class="sev-bar"><div class="fill ${it.cls}" style="width:${pct}%;"></div></div>
                                <div class="sev-val">${it.v}</div>
                            </div>
                        `;
                    }).join('');
                }

                modal.style.display = 'flex';
                modal.classList.add('active');
            }
            wrap?.addEventListener('click', open);
            closeBtn?.addEventListener('click', close);
            modal?.addEventListener('click', function (e) { if (e.target === modal) close(); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        })();

        function updateUserFriendlySummary(summary) {
            const friendly = summary.user_friendly || {};
            const message = friendly.message || '';
            const actions = friendly.priority_actions || [];

            const container = document.getElementById('userSummary');
            if (!container) return;

            if (!message && (!actions || actions.length === 0)) {
                container.style.display = 'none';
                return;
            }

            container.style.display = 'block';
            let html = '';
            if (message) {
                html += `<p>${message}</p>`;
            }
            if (actions && actions.length > 0) {
                html += '<ul>';
                actions.forEach(action => {
                    html += `<li>${action}</li>`;
                });
                html += '</ul>';
            }
            container.innerHTML = html;
        }

        function createVulnerabilityCard(vuln, index) {
            const config = {
                critical: { icon: 'fa-skull', color: '#dc2626' },
                high: { icon: 'fa-exclamation-triangle', color: '#ef4444' },
                medium: { icon: 'fa-exclamation-circle', color: '#f59e0b' },
                low: { icon: 'fa-info-circle', color: '#3b82f6' },
                info: { icon: 'fa-info', color: '#6b7280' },
                secure: { icon: 'fa-check-circle', color: '#10b981' }
            }[vuln.severity] || { icon: 'fa-bug', color: '#6b7280' };

            let icon = config.icon;
            const name = vuln.name.toLowerCase();
            if (name.includes('sql')) icon = 'fa-database';
            else if (name.includes('xss')) icon = 'fa-code';
            else if (name.includes('ssl') || name.includes('tls') || name.includes('certificate')) icon = 'fa-lock';
            else if (name.includes('header')) icon = 'fa-shield-alt';
            else if (name.includes('cookie')) icon = 'fa-cookie-bite';
            else if (name.includes('information') || name.includes('disclosure')) icon = 'fa-eye';

            const hasStructured = vuln.what_we_tested || vuln.indicates || vuln.how_exploited;
            const esc = (s) => (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            const truncateEvidence = (s, maxLen = 170) => {
                const val = (s || '').trim();
                if (!val) return '';
                if (val.length <= maxLen) return val;
                return val.slice(0, maxLen) + '...';
            };
            const evidencePreview = truncateEvidence(vuln.evidence);
            const evidenceBlock = vuln.evidence ? `
                <div class="vuln-section vuln-evidence">
                    <div class="vuln-section-title"><i class="fas fa-search"></i> Evidence</div>
                    <div class="vuln-section-content code">${esc(evidencePreview)}</div>
                    <button type="button" class="view-evidence-btn" data-vuln-index="${index}" style="margin-top:8px;display:inline-flex;align-items:center;gap:8px;padding:8px 13px;border:1px solid var(--border-color);background:var(--bg-main);color:var(--text-main);border-radius:999px;cursor:pointer;font-size:12px;font-weight:700;transition:all .22s ease;">
                        <i class="fas fa-expand"></i> View full evidence
                    </button>
                </div>` : '';

            const bodySections = hasStructured
                ? `
                ${vuln.what_we_tested ? `
                <div class="vuln-section">
                    <div class="vuln-section-title"><i class="fas fa-vial"></i> What we tested</div>
                    <div class="vuln-section-content">${esc(vuln.what_we_tested)}</div>
                </div>` : ''}
                ${vuln.indicates ? `
                <div class="vuln-section">
                    <div class="vuln-section-title"><i class="fas fa-exclamation-triangle"></i> This indicates</div>
                    <div class="vuln-section-content">${esc(vuln.indicates)}</div>
                </div>` : ''}
                ${vuln.how_exploited ? `
                <div class="vuln-section">
                    <div class="vuln-section-title"><i class="fas fa-user-secret"></i> How it can be exploited</div>
                    <div class="vuln-section-content">${esc(vuln.how_exploited)}</div>
                </div>` : ''}
                ${vuln.remediation ? `
                <div class="vuln-section">
                    <div class="vuln-section-title"><i class="fas fa-shield-alt"></i> How to mitigate</div>
                    <div class="vuln-section-content">${esc(vuln.remediation)}</div>
                </div>` : ''}
                ${evidenceBlock}
                `
                : `
                <div class="vuln-section">
                    <div class="vuln-section-title"><i class="fas fa-align-left"></i> Description</div>
                    <div class="vuln-section-content">${esc(vuln.description)}</div>
                </div>
                ${evidenceBlock}
                ${vuln.remediation ? `<div class="vuln-section"><div class="vuln-section-title"><i class="fas fa-wrench"></i> Remediation</div><div class="vuln-section-content">${esc(vuln.remediation)}</div></div>` : ''}
                `;

            return `
        <div class="vuln-card ${vuln.severity}" id="vuln-${index}">
            <div class="vuln-header" onclick="toggleVuln(${index})">
                <div class="vuln-title-section">
                    <div class="vuln-icon" style="color: ${config.color}">
                        <i class="fas ${icon}"></i>
                    </div>
                    <div>
                        <div class="vuln-title">${esc(vuln.name)}</div>
                        
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span class="vuln-severity">${vuln.severity}</span>
                    <i class="fas fa-chevron-down vuln-toggle"></i>
                </div>
            </div>
            <div class="vuln-body">
                ${bodySections}
            </div>
        </div>
    `;
        }
        function deriveEvidenceSummary(vuln) {
            const evidence = (vuln?.evidence || '').trim();
            const name = (vuln?.name || '').toLowerCase();
            const description = (vuln?.description || '').toLowerCase();
            const expAct = {
                expected: '',
                actual: evidence || 'No evidence captured.'
            };
            const m = evidence.match(/expected\s*[:\-]?\s*(.+?)\s*(?:\||;|,)\s*(?:actual|found|got|but has)\s*[:\-]?\s*(.+)$/i);
            if (m) {
                expAct.expected = m[1].trim();
                expAct.actual = m[2].trim();
                return expAct;
            }

            if (/payload:\s*/i.test(evidence) && evidence.includes('|')) {
                const parts = evidence.split('|').map(s => s.trim()).filter(Boolean);
                if (parts.length > 1) expAct.actual = parts.slice(1).join(' | ');
            }

            // Header findings (main + per-page variants)
            if (name.startsWith('missing ')) {
                const headerName = (vuln?.name || '').replace(/^Missing\s+/i, '').replace(/\s*\(page:.*\)$/i, '').trim();
                expAct.expected = `Expected: HTTP response should include a correctly configured ${headerName} header on all relevant pages/endpoints.`;

                if (/\(page:\s*([^)]+)\)/i.test(vuln?.name || '')) {
                    const mPage = (vuln?.name || '').match(/\(page:\s*([^)]+)\)/i);
                    if (mPage && mPage[1]) {
                        expAct.actual = `${headerName} is missing on page path: ${mPage[1]}.`;
                    }
                }
            } else if (name.includes('exposed') && name.includes('service')) {
                expAct.expected = "Expected: internet-facing host should expose only strictly required services; unnecessary service ports should be closed or restricted by firewall.";
            } else if (name.includes('telnet service detected')) {
                expAct.expected = "Expected: Telnet (plaintext remote access) should be disabled; use SSH with strong authentication instead.";
            } else if (name.includes('redis exposed to internet')) {
                expAct.expected = "Expected: Redis should be bound to trusted internal interfaces and protected by network ACLs/authentication.";
            } else if (name.includes('mongodb exposed to internet')) {
                expAct.expected = "Expected: MongoDB should not be publicly reachable; enforce private network access and authentication.";
            } else if (name.includes('elasticsearch exposed to internet')) {
                expAct.expected = "Expected: Elasticsearch REST API should be private or strongly access-controlled and not open to public internet.";
            } else if (name.includes('admin panel accessible on port')) {
                expAct.expected = "Expected: administrative interfaces should require strict access controls and should not be publicly discoverable.";
            } else if (name.includes('insecure http')) {
                expAct.expected = "Expected: site should enforce HTTPS-only access with HTTP redirected to HTTPS.";
            } else if (name.includes('expired ssl certificate')) {
                expAct.expected = "Expected: TLS certificate should be valid (not expired) and trusted throughout its lifetime.";
            } else if (name.includes('ssl certificate expiring soon')) {
                expAct.expected = "Expected: certificate lifecycle should be managed so certs are renewed well before expiry.";
            } else if (name.includes('weak tls protocol')) {
                expAct.expected = "Expected: server should negotiate modern TLS versions only (TLS 1.2/1.3) and disable legacy protocols.";
            } else if (name.includes('weak cipher suite')) {
                expAct.expected = "Expected: server should prefer strong modern cipher suites and disable weak/broken ciphers.";
            } else if (name.includes('untrusted or self-signed ssl certificate')) {
                expAct.expected = "Expected: certificate chain should be issued by a trusted CA and validate correctly for clients.";
            } else if (name.includes('ssl certificate error')) {
                expAct.expected = "Expected: certificate validation should succeed without trust/hostname/chain errors.";
            } else if (name.includes('cors wildcard origin')) {
                expAct.expected = "Expected: CORS should allow only explicitly trusted origins, not wildcard access.";
            } else if (name.includes('cors misconfiguration') && name.includes('credentials')) {
                expAct.expected = "Expected: credentialed cross-origin requests should be restricted to trusted origins only; never reflect arbitrary Origin with credentials.";
            } else if (name.includes('cors misconfiguration')) {
                expAct.expected = "Expected: Origin should not be reflected dynamically from arbitrary request headers.";
            } else if (name.includes('open redirect')) {
                expAct.expected = "Expected: redirect parameters should be validated against an allowlist of safe internal destinations.";
            } else if (name.startsWith('exposed ') && name.includes(' in response')) {
                expAct.expected = "Expected: responses should never include secrets/keys/tokens/passwords; sensitive values must remain server-side.";
            } else if (name.includes('server version disclosure')) {
                expAct.expected = "Expected: response headers should avoid disclosing exact server version/build details.";
            } else if (name.includes('technology stack disclosure')) {
                expAct.expected = "Expected: headers should avoid revealing framework/runtime identifiers like X-Powered-By.";
            } else if (name.includes('mixed content')) {
                expAct.expected = "Expected: HTTPS pages should load all scripts/styles/resources over HTTPS only.";
            } else if (name.includes('cookie missing secure flag')) {
                expAct.expected = "Expected: sensitive cookies should include the Secure flag so they are sent only over HTTPS.";
            } else if (name.includes('cookie missing httponly flag')) {
                expAct.expected = "Expected: sensitive cookies should include HttpOnly to prevent client-side script access.";
            } else if (name.includes('cookie missing samesite')) {
                expAct.expected = "Expected: sensitive cookies should include SameSite policy to reduce CSRF risk.";
            } else if (name.startsWith('exposed ') && description.includes('publicly accessible')) {
                expAct.expected = "Expected: sensitive files/paths should not be publicly accessible without authentication.";
            } else if (name.includes('exists (access restricted)')) {
                expAct.expected = "Expected: sensitive paths should be restricted, and ideally undiscoverable unless required.";
            } else if (name.includes('sql injection') || description.includes('sql injection')) {
                expAct.expected = "Expected: input should be treated as plain data and must not trigger database parser errors or query behavior changes.";
            } else if (name.includes('xss') || description.includes('cross-site scripting')) {
                expAct.expected = "Expected: user input should be safely escaped/encoded so script payloads are rendered harmless and never executed.";
            } else if (name.includes('potential dom xss sink detected')) {
                expAct.expected = "Expected: dangerous DOM sinks should not be fed by user-controlled data; safer APIs/sanitization should be used.";
            } else if (name.includes('port') || description.includes('port')) {
                expAct.expected = "Expected: only required service ports should be reachable from untrusted networks; unnecessary ports should be closed/filtered.";
            } else if (description.includes('disclosure')) {
                expAct.expected = "Expected: application responses should minimize technical disclosure and avoid leaking sensitive internals.";
            } else {
                expAct.expected = 'Expected: secure behavior for this test should be observed.';
            }
            return expAct;
        }
        function openEvidenceModal(vulnIndex) {
            const modal = document.getElementById('evidenceModal');
            if (!modal) return;
            const vuln = (lastVulnerabilities || [])[vulnIndex];
            if (!vuln) return;
            const titleEl = document.getElementById('evidenceModalTitle');
            const expectedEl = document.getElementById('evidenceExpected');
            const actualEl = document.getElementById('evidenceActual');
            const fullEl = document.getElementById('evidenceFull');
            if (titleEl) titleEl.textContent = (vuln.name || 'Vulnerability Evidence');
            const parsed = deriveEvidenceSummary(vuln);
            if (expectedEl) expectedEl.textContent = parsed.expected || '-';
            if (actualEl) actualEl.textContent = parsed.actual || '-';
            if (fullEl) fullEl.textContent = (vuln.evidence || '-');
            modal.style.display = 'flex';
            modal.classList.add('active');
        }
        function closeEvidenceModal() {
            const modal = document.getElementById('evidenceModal');
            if (!modal) return;
            modal.classList.remove('active');
            modal.style.display = 'none';
        }
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.view-evidence-btn');
            if (!btn) return;
            const idx = parseInt(btn.getAttribute('data-vuln-index') || '-1', 10);
            if (!Number.isNaN(idx) && idx >= 0) openEvidenceModal(idx);
        });
        function updateSSLDisplay(sslInfo) {
            const grade = sslInfo.grade || 'F';
            const badge = document.getElementById('sslGrade');

            const gradeClass = grade.toLowerCase().replace('+', '-plus');
            badge.className = 'grade-badge ' + gradeClass;
            badge.textContent = grade;

            const protocols = sslInfo.protocols || [];
            const protocolStr = protocols.join(', ') || 'None detected';
            document.getElementById('sslProtocol').textContent = protocolStr;

            // Derive status from the fields the new backend actually returns:
            // ssl.https (bool) + ssl.certificate_valid (bool) + ssl.grade (string)
            let statusText = 'Unknown';
            if (!sslInfo.https) {
                statusText = 'Insecure HTTP';
            } else if (sslInfo.certificate_valid) {
                statusText = grade === 'F' ? 'Weak SSL/TLS' : 'Secure HTTPS';
            } else {
                statusText = 'Certificate Error';
            }
            document.getElementById('sslStatus').textContent = statusText;
        }

        function updateHeadersDisplay(headersInfo) {
            // headersInfo comes from backend as:
            // { score, present, missing, misconfigured: [...] }
            const percentage = headersInfo.score || 0;
            const bar = document.getElementById('headersScoreBar');
            const text = document.getElementById('headersScoreText');

            bar.style.width = percentage + '%';

            // Color based on score
            bar.className = 'score-fill';
            if (percentage >= 80) bar.classList.add('high');
            else if (percentage >= 50) bar.classList.add('medium');
            else bar.classList.add('low');

            text.textContent = percentage + '%';

            // Update details text: list which headers are present and which are missing
            const presentNames = headersInfo.present_names || [];
            const missingNames = headersInfo.missing_names || [];
            const details = document.getElementById('headersDetails');
            let detailsStr = 'Browser security headers help protect visitors from attacks like XSS and clickjacking. ';
            if (presentNames.length) {
                detailsStr += 'Present: ' + presentNames.join(', ') + '. ';
            } else {
                detailsStr += 'No security headers are set. ';
            }
            if (missingNames.length) {
                detailsStr += 'Missing: ' + missingNames.join(', ') + '.';
            } else {
                detailsStr += 'No recommended headers are missing.';
            }
            details.textContent = detailsStr;
        }

        function updateServerDisplay(serverInfo) {
            const el = document.getElementById('serverInfoContent');
            if (!el) return;
            if (!serverInfo || Object.keys(serverInfo).length === 0) {
                el.textContent = 'No server headers detected.';
                return;
            }
            const parts = [];
            if (serverInfo.Server) parts.push('Server: ' + serverInfo.Server);
            if (serverInfo['X-Powered-By']) parts.push('X-Powered-By: ' + serverInfo['X-Powered-By']);
            if (serverInfo['X-AspNet-Version']) parts.push('AspNet: ' + serverInfo['X-AspNet-Version']);
            if (serverInfo['X-Runtime']) parts.push('Runtime: ' + serverInfo['X-Runtime']);
            if (serverInfo['X-Generator']) parts.push('Generator: ' + serverInfo['X-Generator']);
            if (serverInfo.Via) parts.push('Via: ' + serverInfo.Via);
            el.innerHTML = parts.length ? parts.map(p => '<div class="server-info-line">' + escapeHtml(p) + '</div>').join('') : '—';
        }

        function updateCrawlerDisplay(crawler) {
            const el = document.getElementById('crawlerContent');
            if (!el) return;
            if (!crawler || !crawler.discovered_urls || crawler.discovered_urls.length === 0) {
                el.textContent = 'Only the target URL was checked.';
                return;
            }
            const urls = crawler.discovered_urls || [];
            const checked = (crawler.pages_checked || []).length;
            let html = '<div class="crawler-summary">Discovered <strong>' + urls.length + '</strong> page(s); checked <strong>' + checked + '</strong> for security headers.</div>';
            if (urls.length) {
                html += '<ul class="crawler-url-list" style="margin-top:8px; font-size:12px; max-height:120px; overflow-y:auto;">';
                urls.forEach(function (u) {
                    html += '<li style="word-break:break-all;">' + escapeHtml(u) + '</li>';
                });
                html += '</ul>';
            }
            el.innerHTML = html;
        }

        function escapeHtml(s) {
            if (!s) return '';
            const div = document.createElement('div');
            div.textContent = s;
            return div.innerHTML;
        }

        // Replace your scanBtn event listener with this:

        function runScan() {
            var inp = document.getElementById('targetURL');
            var btn = document.getElementById('scanBtn');
            var loaderEl = document.getElementById('loader');
            var resultsEl = document.getElementById('results');
            var errMsgEl = document.getElementById('errorMessage');
            var errTextEl = document.getElementById('errorText');
            var url = (inp && inp.value) ? inp.value.trim() : '';

            if (!url || (url.indexOf('http://') !== 0 && url.indexOf('https://') !== 0)) {
                if (errTextEl) errTextEl.textContent = 'Please enter a valid URL starting with http:// or https://';
                if (errMsgEl) errMsgEl.classList.add('active');
                return;
            }

            if (errMsgEl) errMsgEl.classList.remove('active');
            if (btn) btn.disabled = true;
            if (loaderEl) loaderEl.classList.add('active');
            if (resultsEl) resultsEl.classList.remove('active');

            var scanApiUrl = '../Backend/scan_proxy.php';
            console.log('runScan: sending POST to', scanApiUrl, 'target=', url);
            fetch(scanApiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ target: url })
            }).then(function (response) {
                return response.text().then(function (text) {
                    try {
                        return { ok: response.ok, data: JSON.parse(text), status: response.status };
                    } catch (e) {
                        return { ok: false, data: null, status: response.status, raw: text };
                    }
                });
            }).then(function (result) {
                if (!result.ok) {
                    let errMsg = result.data && result.data.error ? result.data.error : ('Server error ' + result.status);
                    if (result.raw) errMsg = result.raw.slice(0, 300);
                    throw new Error(errMsg);
                }
                const data = result.data;

                // Check for scan-level errors
                if (data.error) {
                    throw new Error(data.error);
                }

                // Update target badge
                try {
                    document.getElementById('targetBadge').textContent = new URL(data.target).hostname;
                } catch (e) {
                    document.getElementById('targetBadge').textContent = data.target;
                }

                // Update meta info
                document.getElementById('scanDuration').textContent = (data.scan_duration || 0) + 's';
                document.getElementById('scanTimestamp').textContent = formatTimestamp(data.timestamp);

                // Update risk summary
                updateRiskDisplay(data.summary);
                updateUserFriendlySummary(data.summary);

                // Update SSL, Headers, Server & Crawler
                updateSSLDisplay(data.ssl);
                updateHeadersDisplay(data.headers);
                updateServerDisplay(data.server_info);
                updateCrawlerDisplay(data.crawler);

                // Update vulnerabilities list
                const vulnsList = document.getElementById('vulnerabilitiesList');
                if (data.vulnerabilities && data.vulnerabilities.length > 0) {
                    lastVulnerabilities = data.vulnerabilities;
                    vulnsList.innerHTML = data.vulnerabilities.map((vuln, idx) =>
                        createVulnerabilityCard(vuln, idx)
                    ).join('');
                } else {
                    lastVulnerabilities = [];
                    vulnsList.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-shield-alt"></i>
                    <h3>No Vulnerabilities Found</h3>
                    <p>The target appears to be secure. No issues were detected during the scan.</p>
                </div>
            `;
                }

                lastScanData = data;
                if (loaderEl) loaderEl.classList.remove('active');
                if (resultsEl) resultsEl.classList.add('active');

                // Save scan and generate human-readable report (GPT); store artefacts for download
                const humanReportPanel = document.getElementById('humanReportPanel');
                let humanReportTimerInterval = null;
                let humanReportTimerStart = Date.now();
                function startHumanReportTimer() {
                    if (!humanReportPanel) return;
                    try {
                        humanReportTimerStart = Date.now();
                        humanReportPanel.innerHTML = '<p class="human-report-placeholder">Generating human-readable report... <span id="humanReportWaitTimer">0s</span> elapsed</p>';
                    } catch (e) { }
                    humanReportTimerInterval = setInterval(() => {
                        const el = document.getElementById('humanReportWaitTimer');
                        if (!el) return;
                        const seconds = Math.floor((Date.now() - humanReportTimerStart) / 1000);
                        el.textContent = seconds + 's';
                    }, 300);
                }
                function stopHumanReportTimer() {
                    if (humanReportTimerInterval) clearInterval(humanReportTimerInterval);
                    humanReportTimerInterval = null;
                }

                startHumanReportTimer();
                fetch('../Backend/save_scan_report.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ scan_data: data })
                }).then(r => r.json()).then(res => {
                    stopHumanReportTimer();
                    if (res.report_text) {
                        let html = '<div class="human-report-content">' + renderStyledHumanReport(res.report_text) + '</div>';
                        if (res.saved === false && res.message) {
                            html = '<p class="upgrade-notice" style="margin-bottom:12px;padding:10px;background:rgba(245,158,11,0.15);border-radius:8px;font-size:13px;">' + (res.message || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</p>' + html;
                        }
                        document.getElementById('humanReportPanel').innerHTML = html;
                        lastHumanReportText = res.report_text;
                    } else if (res.error && res.upgrade) {
                        document.getElementById('humanReportPanel').innerHTML = '<p class="human-report-placeholder" style="color: var(--warning-color);">' + (res.message || res.error || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</p>';
                        return;
                    }
                    if (res.scan_id) lastScanId = res.scan_id;
                    if (res.download) lastDownloadUrls = res.download;

                    // If server-side PDF generation isn't available, generate and upload PDF for history/share.
                    if (lastScanId && (!lastDownloadUrls || !lastDownloadUrls.pdf)) {
                        ensurePdfSavedForHistory(lastScanId, lastScanData, 0, function (savedUrl) {
                            if (savedUrl) {
                                showToast('Report saved', 'PDF report saved and available in History.', 'success');
                            }
                        });
                    } else if (lastScanId && lastDownloadUrls && lastDownloadUrls.pdf) {
                        showToast('Report saved', 'Report saved. PDF/HTML/CSV available for download and sharing.', 'success');
                    }
                }).catch(() => {
                    stopHumanReportTimer();
                    document.getElementById('humanReportPanel').innerHTML = '<p class="human-report-placeholder">Report could not be saved. You can still use the download buttons for CSV, HTML, or PDF.</p>';
                });
                if (loaderEl) loaderEl.classList.remove('active');
                if (btn) btn.disabled = false;
            }).catch(function (err) {
                console.error('Scan error:', err);
                var loaderEl2 = document.getElementById('loader');
                var btn2 = document.getElementById('scanBtn');
                var errTextEl2 = document.getElementById('errorText');
                var errMsgEl2 = document.getElementById('errorMessage');
                if (loaderEl2) loaderEl2.classList.remove('active');
                var errorMsg = err.message || 'Scan failed.';
                if (err.message && err.message.indexOf('Failed to fetch') !== -1) {
                    errorMsg = 'Scanner service is temporarily unavailable. Please try again later.';
                }
                if (errTextEl2) errTextEl2.innerHTML = errorMsg.replace(/\n/g, '<br>');
                if (errMsgEl2) errMsgEl2.classList.add('active');
                if (btn2) btn2.disabled = false;
            });
        }
        function initScanButton() {
            var btn = document.getElementById('scanBtn');
            var inp = document.getElementById('targetURL');
            if (btn && inp) {
                btn.addEventListener('click', runScan);
                inp.addEventListener('keypress', function (e) { if (e.key === 'Enter') { e.preventDefault(); runScan(); } });
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initScanButton);
        } else {
            initScanButton();
        }


        // Help button popup
        document.getElementById('helpBtn').addEventListener('click', () => {
            alert('Enter a target URL to scan for:\n\n• SQL Injection (error-based + blind time-based)\n• Cross-Site Scripting (reflected + DOM XSS sinks)\n• CORS misconfiguration\n• Open redirect vulnerabilities\n• Exposed sensitive files (.env, .git, backups, admin panels)\n• Missing security headers (CSP, HSTS, X-Frame-Options…)\n• SSL/TLS certificate and cipher issues\n• Mixed content (HTTP resources on HTTPS pages)\n• Exposed secrets (AWS keys, Stripe keys, passwords)\n• Cookie security flags (Secure, HttpOnly, SameSite)\n• Open ports and exposed services (Redis, MongoDB, Telnet…)\n\nEnsure you have permission to scan the target.');
        });

        // Back to top
        (function () {
            const btn = document.getElementById('backToTopBtn');
            if (!btn) return;
            function sync() {
                btn.style.display = (window.scrollY > 400) ? 'inline-flex' : 'none';
            }
            window.addEventListener('scroll', sync, { passive: true });
            sync();
            btn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
        })();

        // Report generation helpers
        function buildCsvReport(data) {
            const lines = [];
            lines.push(`Target,${data.target}`);
            lines.push(`Scanned At,${formatTimestamp(data.timestamp)}`);
            lines.push(`Scan Duration (s),${data.scan_duration}`);
            lines.push(`Risk Level,${data.summary.risk_level}`);
            lines.push(`Risk Score,${data.summary.risk_score}`);
            lines.push('');
            lines.push('Severity,Name,Description,Evidence,Remediation');

            (data.vulnerabilities || []).forEach(v => {
                const row = [
                    v.severity || '',
                    (v.name || '').replace(/"/g, '""'),
                    (v.description || '').replace(/"/g, '""'),
                    (v.evidence || '').replace(/"/g, '""'),
                    (v.remediation || '').replace(/"/g, '""'),
                ].map(value => `"${value}"`);
                lines.push(row.join(','));
            });

            return lines.join('\r\n');
        }

        function buildHtmlReport(data) {
            const summary = data.summary || {};
            const friendly = summary.user_friendly || {};
            const headers = data.headers || {};
            const ssl = data.ssl || {};
            const presentNames = headers.present_names || [];
            const missingNames = headers.missing_names || [];

            const issuesHtml = (data.vulnerabilities || []).map(v => `
                <tr>
                    <td>${escapeHtml(v.severity || '')}</td>
                    <td>${escapeHtml(v.name || '')}</td>
                    <td>${escapeHtml(v.description || '')}</td>
                    <td>${escapeHtml(v.what_we_tested || '')}</td>
                    <td>${escapeHtml(v.indicates || '')}</td>
                    <td>${escapeHtml(v.how_exploited || '')}</td>
                    <td>${escapeHtml(v.evidence || '')}</td>
                    <td>${escapeHtml(v.remediation || '')}</td>
                </tr>
            `).join('');

            const actionsHtml = (friendly.priority_actions || []).map(a => `<li>${a}</li>`).join('');

            return `
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>ScanQuotient Security Report</title>
    <style>
        body { font-family: Arial, sans-serif; color: #111827; margin: 20px; background: #f8fafc; }
        h1, h2, h3 { color: #111827; margin: 0 0 10px 0; }
        .header { padding: 20px; border-radius: 14px; background: linear-gradient(135deg,#eef2ff 0%,#f5f3ff 55%, #ffffff 100%); border: 1px solid #dbeafe; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08); }
        .brand-row { display:flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 10px; }
        .brand-badge { display:inline-flex; align-items:center; gap:8px; font-size: 12px; font-weight: 700; color: #4338ca; background: rgba(99,102,241,0.12); padding: 6px 10px; border-radius: 999px; }
        .risk-pill { display:inline-block; padding: 6px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .risk-pill.secure, .risk-pill.low { background: #dcfce7; color: #166534; }
        .risk-pill.medium { background: #fef3c7; color: #92400e; }
        .risk-pill.high, .risk-pill.critical { background: #fee2e2; color: #991b1b; }
        .meta { margin-top: 10px; font-size: 13px; color: #374151; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px 12px; }
        .meta div { background: rgba(255,255,255,0.7); padding: 8px 10px; border-radius: 8px; border: 1px solid #e5e7eb; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .badge-critical { background: #fee2e2; color: #b91c1c; }
        .badge-high { background: #ffedd5; color: #c2410c; }
        .badge-medium { background: #fef3c7; color: #92400e; }
        .badge-low { background: #e0f2fe; color: #0369a1; }
        .badge-secure { background: #dcfce7; color: #16a34a; }
        .section { margin-top: 14px; }
        .small { font-size: 12px; color: #6b7280; }
        .pill { display:inline-block; padding: 3px 10px; border-radius: 999px; border:1px solid #e5e7eb; background:#fff; font-size:12px; margin: 2px 6px 0 0; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; table-layout: fixed; }
        th, td { border: 1px solid #e5e7eb; padding: 8px; font-size: 11px; vertical-align: top; word-wrap: break-word; }
        th { background: #f9fafb; text-align: left; }
    </style>
</head>
<body>
    <div class="header">
      <div class="brand-row">
          <div class="brand-badge"><span>ScanQuotient</span><span>Security Report</span></div>
          <span class="risk-pill ${(summary.risk_level || 'Secure').toLowerCase()}">${(summary.risk_level || 'Secure')} Risk</span>
      </div>
      <h1>Vulnerability Assessment Report</h1>
      <div class="meta">
          <div><strong>Target:</strong> ${escapeHtml(data.target || '')}</div>
          <div><strong>Scanned at:</strong> ${escapeHtml(formatTimestamp(data.timestamp) || '')}</div>
          <div><strong>Scan duration:</strong> ${escapeHtml(String(data.scan_duration || 0))}s</div>
      </div>
    </div>

    <h2>Overall Risk</h2>
    <p>
        <span class="badge badge-${(summary.risk_level || 'Secure').toLowerCase()}">
            ${(summary.risk_level || 'Secure')} Risk (Score: ${summary.risk_score || 0})
        </span>
    </p>
    ${friendly.message ? `<p>${friendly.message}</p>` : ''}
    ${actionsHtml ? `<h3>Suggested Next Steps</h3><ul>${actionsHtml}</ul>` : ''}

    <div class="section">
    <h2>HTTPS and Security Headers</h2>
    <p><strong>HTTPS enabled:</strong> ${ssl.https ? 'Yes' : 'No'}</p>
    <p><strong>SSL/TLS grade:</strong> ${ssl.grade || 'N/A'}</p>
    <p><strong>Security headers score:</strong> ${headers.score || 0}%</p>
    <div class="small"><strong>Present:</strong> ${presentNames.length ? presentNames.map(h => `<span class="pill">${escapeHtml(h)}</span>`).join('') : 'None'}</div>
    <div class="small" style="margin-top:6px;"><strong>Missing:</strong> ${missingNames.length ? missingNames.map(h => `<span class="pill">${escapeHtml(h)}</span>`).join('') : 'None'}</div>
    </div>

    <div class="section">
    <h2>Detailed Issues</h2>
    <table>
        <thead>
            <tr>
                <th>Severity</th>
                <th>Name</th>
                <th>Description</th>
                <th>What We Tested</th>
                <th>This Indicates</th>
                <th>How Exploited</th>
                <th>Evidence</th>
                <th>Remediation</th>
            </tr>
        </thead>
        <tbody>
            ${issuesHtml || '<tr><td colspan="8">No issues found.</td></tr>'}
        </tbody>
    </table>
    </div>
</body>
</html>
`;
        }

        function openStyledPrintPreview(reportHtml) {
            const reportWindow = window.open('', '_blank');
            if (!reportWindow) return;
            const parsed = new DOMParser().parseFromString(reportHtml, 'text/html');
            const bodyHtml = parsed.body ? parsed.body.innerHTML : reportHtml;
            const headHtml = parsed.head ? parsed.head.innerHTML : '';
            reportWindow.document.open();
            reportWindow.document.write(`<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
${headHtml}
<style>
    .print-toolbar { position: sticky; top: 0; z-index: 1000; background: #ffffff; border-bottom: 1px solid #e5e7eb; padding: 12px 20px; display:flex; justify-content:flex-end; }
    .print-btn { border: none; background: linear-gradient(135deg, #4f46e5, #2563eb); color: #fff; padding: 10px 16px; border-radius: 10px; font-weight: 700; cursor: pointer; box-shadow: 0 6px 16px rgba(37,99,235,0.25); }
    .print-btn:hover { filter: brightness(1.06); transform: translateY(-1px); }
    @media print { .print-toolbar { display: none !important; } body { background: #fff !important; } }
</style>
</head>
<body>
    <div class="print-toolbar">
        <button type="button" class="print-btn" onclick="window.print()">Print Report</button>
    </div>
    ${bodyHtml}
</body>
</html>`);
            reportWindow.document.close();
        }

        function downloadTextFile(filename, mimeType, content) {
            const blob = new Blob([content], { type: mimeType });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        function renderStyledHumanReport(text) {
            // Preserve original layout; only style section titles and (optionally) Finding lines.
            const safe = (text || '').replace(/\r\n/g, '\n');
            const lines = safe.split('\n');
            let out = '';
            let para = [];
            const knownTitles = new Set([
                'What we checked and overall risk',
                'Summary of priorities and next steps',
                'Executive Summary',
                'Detailed findings',
                'Remediation roadmap',
                'Next steps'
            ]);

            const boldOpeningsSet = new Set([
                'What we tested:',
                'What is missing:',
                'What vulnerability it indicates:',
                'How to mitigate:'
            ]);

            function escapeHtmlWithBoldOpenings(line) {
                const rawLine = String(line ?? '');
                // Keep leading whitespace (even though HTML collapses it) to avoid changing meaning.
                const trimmedStart = rawLine.replace(/^\s+/, '');
                const leading = rawLine.slice(0, rawLine.length - trimmedStart.length);

                // Exact bold prefixes with colon
                for (const prefix of boldOpeningsSet) {
                    if (trimmedStart.startsWith(prefix)) {
                        const rest = trimmedStart.slice(prefix.length);
                        return escapeHtml(leading) + '<strong>' + escapeHtml(prefix) + '</strong>' + escapeHtml(rest);
                    }
                }

                // How it can be exploited (may have ":" or ",")
                {
                    const m = trimmedStart.match(/^(How it can be exploited)(\s*:)?(.*)$/);
                    if (m) {
                        const headingBase = m[1];
                        const hasColon = !!m[2];
                        let rest = m[3] || '';
                        rest = rest.replace(/^[\s,]+/, '');
                        const headingDisplay = headingBase + (hasColon ? ':' : '');
                        return escapeHtml(leading) + '<strong>' + escapeHtml(headingDisplay) + '</strong>' + escapeHtml(rest);
                    }
                }

                // How to mitigate (allow missing colon)
                {
                    const m = trimmedStart.match(/^(How to mitigate)(\s*:)?(.*)$/);
                    if (m) {
                        const headingBase = m[1];
                        const hasColon = !!m[2];
                        let rest = m[3] || '';
                        rest = rest.replace(/^[\s]+/, '');
                        const headingDisplay = headingBase + (hasColon ? ':' : '');
                        return escapeHtml(leading) + '<strong>' + escapeHtml(headingDisplay) + '</strong>' + escapeHtml(rest);
                    }
                }

                // Default: normal escaped line
                return escapeHtml(rawLine);
            }

            function flushPara() {
                if (!para.length) return;
                const html = para.map(l => escapeHtmlWithBoldOpenings(l)).join('<br>');
                out += '<p class="hr-paragraph">' + html + '</p>';
                para = [];
            }
            for (let i = 0; i < lines.length; i++) {
                const raw = lines[i] ?? '';
                const line = raw.trim();
                if (!line) { flushPara(); continue; }
                const hadColon = line.endsWith(':');
                const titleCandidate = hadColon ? line.slice(0, -1).trim() : line;
                const looksLikeTitle = knownTitles.has(titleCandidate) || ((titleCandidate.length <= 60) && /^[A-Z][A-Za-z0-9 /&-]+$/.test(titleCandidate));
                if (looksLikeTitle && para.length === 0) {
                    flushPara();
                    const boldSectionTitles = new Set([
                        'What we tested',
                        'What is missing',
                        'What vulnerability it indicates',
                        'How to mitigate'
                    ]);
                    if (hadColon && boldSectionTitles.has(titleCandidate)) {
                        out += '<div class="hr-section-title"><strong>' + escapeHtml(titleCandidate + ':') + '</strong></div>';
                    } else {
                        out += '<div class="hr-section-title">' + escapeHtml(titleCandidate) + '</div>';
                    }
                    continue;
                }
                if (line.startsWith('Finding:')) {
                    flushPara();
                    out += '<div class="hr-callout hr-finding"><strong>' + escapeHtml('Finding:') + '</strong> ' + escapeHtml(line.slice(8).trim()) + '</div>';
                    continue;
                }
                para.push(raw);
            }
            flushPara();
            return out || '<p class="hr-paragraph">No report text.</p>';
        }

        function ensurePdfSavedForHistory(scanId, scanData, attempt, onSaved) {
            attempt = attempt || 0;
            if (lastDownloadUrls && lastDownloadUrls.pdf) return;
            // Wait for jsPDF to be available (CDNs may load slightly later)
            if (!window.jspdf || !window.jspdf.jsPDF) {
                if (attempt < 6) {
                    setTimeout(function () { ensurePdfSavedForHistory(scanId, scanData, attempt + 1); }, 800);
                }
                return;
            }
            generateAndUploadPdf(scanId, scanData).then(function (dl) {
                if (dl) {
                    if (!lastDownloadUrls) lastDownloadUrls = {};
                    lastDownloadUrls.pdf = dl;
                    if (typeof onSaved === 'function') onSaved(dl);
                    return;
                }
                if (attempt < 3) {
                    setTimeout(function () { ensurePdfSavedForHistory(scanId, scanData, attempt + 1, onSaved); }, 1200);
                }
            }).catch(function () {
                if (attempt < 3) {
                    setTimeout(function () { ensurePdfSavedForHistory(scanId, scanData, attempt + 1, onSaved); }, 1200);
                }
            });
        }

        async function generateAndUploadPdf(scanId, scanData) {
            try {
                if (!window.jspdf || !window.jspdf.jsPDF) return null;
                const { jsPDF } = window.jspdf;

                const tmp = document.createElement('div');
                tmp.style.position = 'fixed';
                tmp.style.left = '0';
                tmp.style.top = '0';
                tmp.style.width = '794px';
                tmp.style.background = '#ffffff';
                tmp.style.opacity = '0';
                tmp.style.pointerEvents = 'none';
                tmp.style.zIndex = '-1';
                // Use only BODY content (feeding full <html> often renders blank)
                const html = buildHtmlReport(scanData);
                const parsed = new DOMParser().parseFromString(html, 'text/html');
                tmp.innerHTML = parsed.body ? parsed.body.innerHTML : html;
                document.body.appendChild(tmp);

                const doc = new jsPDF({ unit: 'pt', format: 'a4' });
                await doc.html(tmp, {
                    x: 20,
                    y: 20,
                    width: 555,
                    windowWidth: 794,
                    autoPaging: 'text',
                    html2canvas: { scale: 0.9, useCORS: true }
                });
                const ab = doc.output('arraybuffer');
                if (!ab || ab.byteLength < 8000) {
                    document.body.removeChild(tmp);
                    return null;
                }
                const dataUri = doc.output('datauristring');
                document.body.removeChild(tmp);

                const r = await fetch('../Backend/upload_pdf.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ scan_id: scanId, pdf_base64: dataUri })
                });
                const res = await r.json();
                if (res && res.ok && res.download) return res.download;
            } catch (e) { }
            return null;
        }

        let lastScanData = null;
        let lastScanId = null;
        let lastDownloadUrls = {};
        let lastHumanReportText = '';
        let userPackage = 'freemium';

        fetch('../Backend/get_user_package.php').then(r => r.json()).then(data => {
            userPackage = (data.package || 'freemium').toLowerCase();
            const aiBtn = document.getElementById('aiOverviewBtn');
            const upgradeMsg = document.getElementById('upgradeMessage');
            if (aiBtn) aiBtn.style.display = userPackage === 'enterprise' ? 'inline-flex' : 'none';
            if (upgradeMsg && userPackage !== 'enterprise') {
                upgradeMsg.style.display = 'block';
                upgradeMsg.textContent = 'Upgrade to Enterprise for AI Overview and detailed reports.';
            }
        }).catch(() => { });


        // Tab switching: Detailed Findings | Human-readable report
        document.querySelectorAll('.results-tab').forEach(btn => {
            btn.addEventListener('click', () => {
                const tab = btn.getAttribute('data-tab');
                document.querySelectorAll('.results-tab').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.results-panel').forEach(p => p.classList.remove('active'));
                btn.classList.add('active');
                const panel = tab === 'findings' ? document.getElementById('panelFindings') : document.getElementById('panelReport');
                if (panel) panel.classList.add('active');
            });
        });

        const downloadBase = '../Backend/';
        const csvBtn = document.getElementById('downloadCsvBtn');
        const htmlBtn = document.getElementById('downloadHtmlBtn');
        const pdfBtn = document.getElementById('downloadPdfBtn');

        if (csvBtn) {
            csvBtn.addEventListener('click', () => {
                if (!lastScanData) return;
                if (lastScanId && lastDownloadUrls.csv) {
                    window.location.href = lastDownloadUrls.csv.startsWith('http') ? lastDownloadUrls.csv : (downloadBase + lastDownloadUrls.csv);
                    return;
                }
                const csv = buildCsvReport(lastScanData);
                downloadTextFile('scan-report.csv', 'text/csv;charset=utf-8;', csv);
            });
        }
        if (htmlBtn) {
            htmlBtn.addEventListener('click', () => {
                if (!lastScanData) return;
                if (lastScanId && lastDownloadUrls.html) {
                    window.location.href = lastDownloadUrls.html.startsWith('http') ? lastDownloadUrls.html : (downloadBase + lastDownloadUrls.html);
                    return;
                }
                const html = buildHtmlReport(lastScanData);
                downloadTextFile('scan-report.html', 'text/html;charset=utf-8;', html);
            });
        }
        if (pdfBtn) {
            pdfBtn.addEventListener('click', () => {
                if (!lastScanData) return;
                if (lastScanId && lastDownloadUrls.pdf) {
                    window.open((lastDownloadUrls.pdf.startsWith('http') ? lastDownloadUrls.pdf : (downloadBase + lastDownloadUrls.pdf)), '_blank');
                    return;
                }
                if (lastScanId) {
                    generateAndUploadPdf(lastScanId, lastScanData).then((dl) => {
                        if (dl) {
                            lastDownloadUrls.pdf = dl;
                            window.open((dl.startsWith('http') ? dl : (downloadBase + dl)), '_blank');
                        } else {
                            // Try background auto-save for history, then fall back to print view.
                            ensurePdfSavedForHistory(lastScanId, lastScanData, 0, function (savedUrl) {
                                if (savedUrl) showToast('Report saved', 'PDF report saved and available in History.', 'success');
                            });
                            const html = buildHtmlReport(lastScanData);
                            openStyledPrintPreview(html);
                        }
                    });
                    return;
                }
                const html = buildHtmlReport(lastScanData);
                openStyledPrintPreview(html);
            });
        }
        const aiOverviewBtn = document.getElementById('aiOverviewBtn');
        if (aiOverviewBtn) {
            aiOverviewBtn.addEventListener('click', () => {
                if (!lastScanData || userPackage !== 'enterprise') return;
                if (lastScanId) {
                    window.location.href = 'enterprise_ai_overview.php?scan_id=' + lastScanId;
                    return;
                }
                aiOverviewBtn.disabled = true;
                fetch('../Backend/save_scan_report.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ scan_data: lastScanData, detailed_ai: true })
                }).then(r => r.json()).then(res => {
                    aiOverviewBtn.disabled = false;
                    if (res.scan_id) {
                        window.location.href = 'enterprise_ai_overview.php?scan_id=' + res.scan_id;
                    } else if (res.report_text) {
                        const escaped = res.report_text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
                        document.getElementById('humanReportPanel').innerHTML = '<div class="human-report-content"><h3>AI Overview (Enterprise)</h3>' + escaped + '</div>';
                        document.querySelector('[data-tab="report"]')?.click();
                    } else {
                        alert('Save the scan first to open the full Enterprise AI Overview, or review the report tab.');
                    }
                }).catch(() => { aiOverviewBtn.disabled = false; });
            });
        }
        const shareResultsBtn = document.getElementById('shareResultsBtn');
        if (shareResultsBtn) {
            shareResultsBtn.addEventListener('click', () => {
                showToast('Share', 'Share feature is temporarily disabled and will be enabled in a later update.', 'info');
            });
        }
        const shareModal = document.getElementById('shareModal');
        function openShareModal() {
            if (shareModal) { shareModal.classList.add('active'); shareModal.style.display = 'flex'; }
        }
        function closeShareModal() {
            if (shareModal) { shareModal.classList.remove('active'); shareModal.style.display = 'none'; }
        }
        document.getElementById('shareModalClose')?.addEventListener('click', closeShareModal);
        shareModal?.addEventListener('click', (e) => { if (e.target === shareModal) closeShareModal(); });
        function syncShareAllCheckbox() {
            const all = document.getElementById('shareArtefactsAll');
            const pdf = document.getElementById('shareArtefactsPdf');
            const html = document.getElementById('shareArtefactsHtml');
            const csv = document.getElementById('shareArtefactsCsv');
            if (!all || !pdf || !html || !csv) return;
            all.checked = !!(pdf.checked && html.checked && csv.checked);
        }
        document.getElementById('shareArtefactsAll')?.addEventListener('change', (e) => {
            const v = !!e.target.checked;
            const pdf = document.getElementById('shareArtefactsPdf');
            const html = document.getElementById('shareArtefactsHtml');
            const csv = document.getElementById('shareArtefactsCsv');
            if (pdf) pdf.checked = v;
            if (html) html.checked = v;
            if (csv) csv.checked = v;
        });
        ['shareArtefactsPdf', 'shareArtefactsHtml', 'shareArtefactsCsv'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', syncShareAllCheckbox);
        });

        async function waitForPdfUrl(timeoutMs) {
            timeoutMs = timeoutMs || 9000;
            const start = Date.now();
            while (Date.now() - start < timeoutMs) {
                if (lastDownloadUrls && lastDownloadUrls.pdf) return lastDownloadUrls.pdf;
                await new Promise(r => setTimeout(r, 350));
            }
            return null;
        }

        document.getElementById('shareSubmitBtn')?.addEventListener('click', async () => {
            const scanId = parseInt(document.getElementById('shareScanId').value, 10);
            const artefacts = [];
            if (document.getElementById('shareArtefactsPdf').checked) artefacts.push('pdf');
            if (document.getElementById('shareArtefactsHtml').checked) artefacts.push('html');
            if (document.getElementById('shareArtefactsCsv').checked) artefacts.push('csv');

            if (!scanId || artefacts.length === 0) {
                showToast('Share', 'Select at least one format (PDF/HTML/CSV).', 'error');
                return;
            }

            // If PDF selected but not yet saved, try to auto-generate/upload it first.
            if (artefacts.includes('pdf') && (!lastDownloadUrls || !lastDownloadUrls.pdf) && lastScanId && lastScanData) {
                ensurePdfSavedForHistory(lastScanId, lastScanData, 0);
                const pdfUrl = await waitForPdfUrl(9000);
                if (!pdfUrl) {
                    showToast('PDF not ready', 'PDF is still generating. Please try again in a moment (or unselect PDF).', 'error');
                    return;
                }
            }
            document.getElementById('shareSubmitBtn').disabled = true;

            async function urlToBlob(url, mime) {
                const r = await fetch(url, { credentials: 'same-origin' });
                if (!r.ok) return null;
                const blob = await r.blob();
                if (!blob || blob.size < 1200) return null;
                return blob;
            }

            try {
                if (!navigator.share || typeof navigator.share !== 'function') {
                    throw new Error('Web Share is not supported in this browser.');
                }

                const mimeMap = { pdf: 'application/pdf', html: 'text/html', csv: 'text/csv' };
                const files = [];

                for (const type of artefacts) {
                    // Build a usable download URL (same approach as download buttons)
                    let reportUrl = lastDownloadUrls ? lastDownloadUrls[type] : null;
                    if (!reportUrl) continue;

                    const fullUrl = reportUrl.startsWith('http') ? reportUrl : (downloadBase + reportUrl);
                    const blob = await urlToBlob(fullUrl, mimeMap[type]);
                    if (!blob) continue;
                    files.push(new File([blob], `scan-report-${scanId}.${type}`, { type: mimeMap[type] }));
                }

                const shareData = {
                    title: 'ScanQuotient scan results',
                    text: 'ScanQuotient scan results',
                    files
                };

                // If files can't be attached, share text-only.
                if (files.length > 0) {
                    if (navigator.canShare && !navigator.canShare(shareData)) {
                        await navigator.share({ title: shareData.title, text: shareData.text });
                    } else {
                        await navigator.share(shareData);
                    }
                } else {
                    await navigator.share({ title: shareData.title, text: shareData.text });
                }

                closeShareModal();
                showToast('Shared', 'Opened your device share sheet.', 'success');
            } catch (e) {
                showToast('Share failed', (e && e.message) ? e.message : 'Sharing is unavailable.', 'error');
            } finally {
                document.getElementById('shareSubmitBtn').disabled = false;
            }
        });
        function showToast(title, message, type) {
            type = type || 'info';
            var container = document.getElementById('toastContainer');
            if (!container) return;
            var toast = document.createElement('div');
            toast.className = 'share-toast';
            var icon = type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-times-circle' : 'fa-info-circle');
            toast.innerHTML = '<div class="share-toast-icon ' + type + '"><i class="fas ' + icon + '"></i></div><div class="share-toast-content"><h4>' + (title || '') + '</h4><p>' + (message || '') + '</p></div>';
            container.appendChild(toast);
            setTimeout(function () { toast.classList.add('hide'); setTimeout(function () { toast.remove(); }, 300); }, 4000);
        }
        async function copyEvidenceToClipboard() {
            const fullEl = document.getElementById('evidenceFull');
            if (!fullEl) return;
            const text = (fullEl.textContent || '').trim();
            if (!text || text === '-') {
                showToast('Nothing to copy', 'No evidence text available.', 'error');
                return;
            }
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(text);
                } else {
                    const ta = document.createElement('textarea');
                    ta.value = text;
                    ta.setAttribute('readonly', 'readonly');
                    ta.style.position = 'fixed';
                    ta.style.left = '-9999px';
                    document.body.appendChild(ta);
                    ta.select();
                    const ok = document.execCommand('copy');
                    document.body.removeChild(ta);
                    if (!ok) throw new Error('Copy command failed');
                }
                showToast('Copied', 'Evidence copied to clipboard.', 'success');
            } catch (e) {
                showToast('Copy failed', 'Could not copy evidence automatically.', 'error');
            }
        }
        // Evidence modal buttons are rendered after this script, so use delegated events.
        document.addEventListener('click', function (e) {
            const t = e.target;
            if (!(t instanceof Element)) return;
            if (t.closest('#evidenceModalClose') || t.closest('#evidenceModalDone')) {
                closeEvidenceModal();
                return;
            }
            if (t.closest('#copyEvidenceBtn')) {
                copyEvidenceToClipboard();
                return;
            }
            if (t.id === 'evidenceModal') {
                closeEvidenceModal();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeEvidenceModal();
        });
    </script>

    <div id="toastContainer"
        style="position:fixed;bottom:24px;right:24px;z-index:10000;display:flex;flex-direction:column;gap:8px;pointer-events:none;">
    </div>
    <style>
        #evidenceModal .sq-pill-btn {
            border-radius: 999px !important;
            padding: 9px 16px !important;
            font-weight: 700;
            transition: transform .2s ease, box-shadow .2s ease, filter .2s ease, background .2s ease;
        }

        #evidenceModal .sq-pill-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.18);
            filter: brightness(1.03);
        }

        #evidenceModal .sq-pill-btn:active {
            transform: translateY(0);
            box-shadow: 0 4px 10px rgba(15, 23, 42, 0.12);
        }

        #evidenceModal .sq-pill-btn:focus-visible {
            outline: 2px solid var(--brand-color);
            outline-offset: 2px;
        }

        #evidenceModal #copyEvidenceBtn.sq-pill-btn {
            border: 1px solid rgba(59, 130, 246, 0.35);
            background: rgba(59, 130, 246, 0.12);
            color: var(--brand-color);
        }

        #evidenceModal #copyEvidenceBtn.sq-pill-btn:hover {
            background: rgba(59, 130, 246, 0.2);
            border-color: rgba(59, 130, 246, 0.5);
        }

        #evidenceModal #evidenceModalDone.sq-pill-btn {
            background: linear-gradient(135deg, var(--brand-color), var(--accent-color));
            color: #fff;
            border: none;
        }

        #evidenceModal #evidenceModalDone.sq-pill-btn:hover {
            filter: brightness(1.08);
        }

        .view-evidence-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 7px 16px rgba(15, 23, 42, 0.12);
            border-color: rgba(59, 130, 246, 0.45) !important;
            color: var(--brand-color) !important;
        }
    </style>
    <div id="shareModal" class="modal-overlay"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
        <div class="modal"
            style="background:var(--bg-card); padding:24px; border-radius:16px; max-width:420px; width:90%;">
            <h3 style="margin-bottom:16px;">Share scan results</h3>
            <input type="hidden" id="shareScanId" value="">
            <p style="margin-bottom:6px; font-size:13px; font-weight:600;">Share with</p>
            <p style="margin-bottom:8px; font-size:12px; color:var(--text-light);">Enter one or several email addresses
                (comma or space separated):</p>
            <textarea id="shareEmails" rows="3" style="width:100%; margin-bottom:16px; padding:10px; border-radius:8px;"
                placeholder="email1@example.com, email2@example.com"></textarea>
            <p style="margin-bottom:8px; font-size:13px; font-weight:600;">Attach</p>
            <div style="margin-bottom:16px;">
                <label style="display:block; margin:4px 0; font-weight:600;"><input type="checkbox"
                        id="shareArtefactsAll"> Select all</label>
                <label style="display:block; margin:4px 0;"><input type="checkbox" id="shareArtefactsPdf"> PDF</label>
                <label style="display:block; margin:4px 0;"><input type="checkbox" id="shareArtefactsHtml"> HTML</label>
                <label style="display:block; margin:4px 0;"><input type="checkbox" id="shareArtefactsCsv"> CSV</label>
            </div>
            <div style="margin-top:16px; display:flex; gap:8px; justify-content:flex-end;">
                <button type="button" id="shareModalClose" class="modal-btn secondary">Cancel</button>
                <button type="button" id="shareSubmitBtn" class="modal-btn">Send</button>
            </div>
        </div>
    </div>
    <div id="evidenceModal" class="modal-overlay"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:10003; align-items:center; justify-content:center;">
        <div class="modal"
            style="background:var(--bg-card); padding:20px; border-radius:16px; max-width:760px; width:94%; border:1px solid var(--border-color); max-height:88vh; overflow:auto;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:12px;">
                <div>
                    <div id="evidenceModalTitle" style="font-size:16px; font-weight:800;">Vulnerability Evidence</div>
                    <div style="font-size:12px; color:var(--text-light);">Raw evidence details</div>
                </div>
                <button type="button" id="evidenceModalClose" class="icon-btn" title="Close" style="width:38px;height:38px;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
                <div style="border:1px solid var(--border-color); border-radius:10px; background:var(--bg-main); padding:10px;">
                    <div style="font-size:12px; font-weight:700; margin-bottom:6px; color:var(--brand-color);">Expected</div>
                    <div id="evidenceExpected" style="font-size:13px; line-height:1.45; white-space:pre-wrap;">-</div>
                </div>
                <div style="border:1px solid var(--border-color); border-radius:10px; background:var(--bg-main); padding:10px;">
                    <div style="font-size:12px; font-weight:700; margin-bottom:6px; color:var(--warning-color);">But Has (Actual)</div>
                    <div id="evidenceActual" style="font-size:13px; line-height:1.45; white-space:pre-wrap;">-</div>
                </div>
            </div>
            <div style="border:1px solid var(--border-color); border-radius:10px; background:var(--bg-main); padding:10px;">
                <div style="font-size:12px; font-weight:700; margin-bottom:6px;">Full Evidence</div>
                <pre id="evidenceFull"
                    style="margin:0; white-space:pre-wrap; word-break:break-word; font-size:12px; line-height:1.45; color:var(--text-main);">-</pre>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top:12px;">
                <button type="button" id="copyEvidenceBtn" class="modal-btn secondary sq-pill-btn" style="margin-right:8px;">
                    <i class="fas fa-copy" style="margin-right:6px;"></i>Copy Evidence
                </button>
                <button type="button" id="evidenceModalDone" class="modal-btn sq-pill-btn">
                    Close
                </button>
            </div>
        </div>
    </div>
</body>

</html>