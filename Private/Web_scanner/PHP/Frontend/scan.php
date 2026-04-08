<?php
session_start();

$allowed_roles = ['user', 'admin', 'super_admin'];
if (
    !isset($_SESSION['authenticated'], $_SESSION['role']) ||
    $_SESSION['authenticated'] !== true ||
    !in_array($_SESSION['role'], $allowed_roles, true)
) {
    header('Location: ../../../../Public/Login_page/PHP/Frontend/Login_page_site.php');
    exit();
}

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
            <img src="<?php echo htmlspecialchars($avatar_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile"
                class="header-profile-photo">
            <span class="welcome-text">
                <?php echo htmlspecialchars($currentUserName, ENT_QUOTES, 'UTF-8'); ?> |
                <span id="current-date"></span> |
                <span id="current-time"></span>
            </span>
            <button id="helpBtn" class="icon-btn" title="Help"><i class="fas fa-question-circle"></i></button>
            <button id="notificationsBtn" class="icon-btn notifications-btn" title="Notifications" aria-label="Open notifications">
                <i class="fas fa-bell"></i>
                <span id="notificationsBadge" class="notifications-badge" style="display:none;">0</span>
            </button>
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
                <div id="sidebarReportNav" class="sidebar-report-nav" style="display:none;">
                    <div class="sidebar-report-head">
                        <span class="sidebar-report-head__icon" aria-hidden="true"><i class="fas fa-file-waveform"></i></span>
                        <div class="sidebar-report-head__text">
                            <p class="sidebar-report-head__title">Scan report</p>
                        </div>
                    </div>
                    <button type="button" id="sidebarRiskAnalysisBtn" class="nav-btn sidebar-report-btn sidebar-rich-tip"
                        data-tip-title="Risk score analysis"
                        data-tip-text="Understand the score band, weighted severity math, and why this scan landed in its current risk level."
                        aria-label="Risk score analysis: score model, formula, and severity impact">
                        <i class="fas fa-chart-line"></i><span>Risk score analysis</span>
                    </button>
                    <button type="button" id="sidebarRuntimeBtn" class="nav-btn sidebar-report-btn sidebar-rich-tip"
                        data-tip-title="Scan runtime"
                        data-tip-text="Preview the latest runs for this host, compare score changes, then open the full timeline or host-scoped history."
                        aria-label="Scan runtime: recent runs, deltas, and history for this host">
                        <i class="fas fa-stopwatch"></i><span>Scan runtime</span>
                    </button>
                    <div class="sidebar-report-popover">
                        <button type="button" id="sidebarFindingsBtn" class="nav-btn sidebar-report-btn">
                            <i class="fas fa-layer-group"></i><span>Scan results</span>
                        </button>
                        <div class="sidebar-report-popover-menu" id="sidebarFindingsMenu" role="menu" aria-label="Scan results actions">
                            <button type="button" id="sidebarDetailedReportBtn" class="sidebar-popover-item"><i class="fas fa-list-check"></i><span>Detailed report</span></button>
                            <button type="button" id="sidebarAiSummaryBtn" class="sidebar-popover-item"><i class="fas fa-file-lines"></i><span>AI summary</span></button>
                        </div>
                    </div>
                    <div class="sidebar-report-popover">
                        <button type="button" id="sidebarDownloadsBtn" class="nav-btn sidebar-report-btn">
                            <i class="fas fa-download"></i><span>Available downloads</span>
                        </button>
                        <div class="sidebar-report-popover-menu" id="sidebarDownloadsMenu" role="menu" aria-label="Download formats">
                            <button type="button" id="sidebarDownloadCsvBtn" class="sidebar-popover-item sidebar-rich-tip"
                                data-tip-title="CSV export"
                                data-tip-text="Download a spreadsheet-friendly export of findings and key metadata."><i class="fas fa-file-csv"></i><span>CSV report</span></button>
                            <button type="button" id="sidebarDownloadHtmlBtn" class="sidebar-popover-item sidebar-rich-tip"
                                data-tip-title="HTML report"
                                data-tip-text="Download an interactive web report for browser-based review and sharing."><i class="fas fa-file-code"></i><span>HTML report</span></button>
                            <button type="button" id="sidebarDownloadPdfBtn" class="sidebar-popover-item sidebar-rich-tip"
                                data-tip-title="PDF report"
                                data-tip-text="Download a printable report format suitable for meetings and audit records."><i class="fas fa-file-pdf"></i><span>PDF report</span></button>
                            <button type="button" id="sidebarDownloadDocBtn" class="sidebar-popover-item sidebar-rich-tip"
                                data-tip-title="DOC report ready"
                                data-tip-text="Download the AI summary as a Word document."
                                style="display:none;"><i class="fas fa-file-word"></i><span>DOC AI summary</span></button>
                        </div>
                    </div>
                    <button type="button" id="sidebarShareBtn" class="nav-btn sidebar-report-btn sidebar-rich-tip"
                        data-tip-title="Share report"
                        data-tip-text="Send selected report artefacts by email to one or more recipients."
                        <i class="fas fa-share-alt"></i><span>Share report</span>
                    </button>
                    <button type="button" id="sidebarAiOverviewBtn" class="nav-btn sidebar-report-btn sidebar-rich-tip"
                        data-tip-title="AI overview"
                        data-tip-text="Open the executive AI overview, then use the chatbot for deeper insight and follow-up questions."
                        style="display:none;">
                        <i class="fas fa-brain"></i><span>AI overview</span>
                    </button>
                </div>
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
                                    <i class="fas fa-crosshairs scan-btn-icon" aria-hidden="true"></i>
                                    Start Scan
                                </button>
                                <button type="button" id="clearFindingCacheBtn" class="scan-cancel-btn"
                                    style="display:none;">
                                    <i class="fas fa-broom"></i>
                                    Clear report
                                </button>
                                <button type="button" id="cancelScanBtn" class="scan-cancel-btn" style="display:none;">
                                    <i class="fas fa-stop-circle"></i>
                                    Cancel
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
                            <div class="loader-text">Scanning target website URL</div>
                            <div class="scan-progress-wrap">
                                <div class="scan-progress-row">
                                    <span id="scanStageLabel">Preparing scan...</span>
                                    <span id="scanElapsed">0s</span>
                                </div>
                                <div class="scan-progress-track">
                                    <div id="scanProgressBar" class="scan-progress-bar"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Results -->
                        <div id="results" class="results-container">
                            <!-- Scan overview: risk, narrative, posture cards -->
                            <section id="reportRiskSection" class="report-section report-section--overview">
                            <h2 class="report-section-overview-title"><i class="fas fa-gauge-high" aria-hidden="true"></i> Scan overview</h2>
                            <div class="scan-overview">
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
                            <div id="userSummary" class="user-summary-callout" style="display:none;" role="region" aria-label="Scan summary and suggested next steps"></div>
                            <p id="riskScoreFormulaLine" class="risk-overview-hint" style="display:none;" aria-live="polite"></p>

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
                            </div>
                            </section>

                            <!-- Saved runs for this host: risk deltas & finding changes -->
                            <section id="reportRuntimeSection" class="report-section">
                            <div id="scanRunTimelineWrap" class="scan-run-timeline-wrap" style="display:none;">
                                <div class="scan-run-timeline-head">
                                    <div>
                                        <h3 class="scan-run-timeline-title"><i class="fas fa-stream"></i> Scan run
                                            timeline</h3>
                                        <p id="scanRunTimelineSub" class="scan-run-timeline-sub">Saved scans for this
                                            target — compare risk score movement and what changed between runs.</p>
                                    </div>
                                    <div class="scan-run-timeline-actions">
                                        <button type="button" id="scanRunTimelineRefresh"
                                            class="scan-run-timeline-refresh" title="Refresh timeline"
                                            aria-label="Refresh timeline">
                                            <i class="fas fa-rotate"></i>
                                        </button>
                                        <a id="scanRunTimelineHistoryLink" href="/ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Frontend/historical_scans.php"
                                            class="scan-run-timeline-history-link">Full history <i
                                                class="fas fa-arrow-right"></i></a>
                                    </div>
                                </div>
                                <div id="scanRunTimelineBody" class="scan-run-timeline-body"></div>
                                <div id="scanRunTimelineViewAll" class="scan-run-timeline-viewall"
                                    style="display:none;">
                                    <button type="button" id="scanRunTimelineViewAllBtn"
                                        class="scan-run-timeline-viewall-btn">
                                        <i class="fas fa-expand-alt"></i> View full timeline
                                    </button>
                                </div>
                                <p id="scanRunTimelineEmpty" class="scan-run-timeline-empty" style="display:none;">No
                                    saved scans for this host yet. After your report is stored, repeat scans will appear
                                    here with score deltas and new or resolved findings.</p>
                            </div>
                            </section>

                            <!-- Tabs: Detailed Findings | Human-readable report -->
                            <section id="reportFindingsSection" class="report-section">
                            <div id="detailedFindingsAnchor" class="detailed-findings-scroll-target" tabindex="-1" aria-label="Detailed findings"></div>
                            <div class="results-tabs">
                                <div class="results-tabs-row results-tabs-row-top">
                                    <span id="targetBadge" class="target-badge">example.com</span>
                                    <button type="button" class="results-tab active" data-tab="findings" id="tabFindings">
                                        <i class="fas fa-clipboard-list"></i>
                                        <span>Detailed Findings</span>
                                    </button>
                                    <button type="button" class="results-tab" data-tab="report" id="tabReport">
                                        <i class="fas fa-file-alt"></i>
                                        <span>AI summary</span>
                                    </button>
                                </div>
                                <div class="report-actions report-download-row results-tabs-row results-tabs-row-bottom">
                                    <div class="report-artefact-btns report-artefact-btns-left">
                                        <button type="button" id="downloadDocBtn" class="artefact-btn artefact-rich-tip artefact-rich-tip--doc"
                                            data-tip-title="DOC report"
                                            data-tip-text="Download the AI summary as a Word document."
                                            aria-disabled="true">
                                            <i class="fas fa-file-word"></i><span>DOC</span>
                                        </button>
                                        <button type="button" id="downloadCsvBtn" class="artefact-btn artefact-rich-tip"
                                            data-tip-title="CSV export"
                                            data-tip-text="Download a spreadsheet-friendly export of findings, severities, and key metadata for analysis."
                                            title="Download CSV">
                                            <i class="fas fa-file-csv"></i><span>CSV</span>
                                        </button>
                                        <button type="button" id="downloadPdfBtn" class="artefact-btn artefact-rich-tip"
                                            data-tip-title="PDF report"
                                            data-tip-text="Download a printable report format suitable for review meetings and audit records."
                                            title="Download PDF">
                                            <i class="fas fa-file-pdf"></i><span>PDF</span>
                                        </button>
                                        <button type="button" id="downloadHtmlBtn" class="artefact-btn artefact-rich-tip"
                                            data-tip-title="HTML report"
                                            data-tip-text="Download an interactive web report version for easier browsing and sharing in browsers."
                                            title="Download HTML">
                                            <i class="fas fa-file-code"></i><span>HTML</span>
                                        </button>
                                    </div>
                                    <div class="report-extra-actions report-extra-actions-right">
                                        <button type="button" id="aiOverviewBtn" class="artefact-btn secondary artefact-rich-tip"
                                            data-tip-title="AI overview"
                                            data-tip-text="Open the executive AI overview, then use the chatbot for deeper insight and follow-up questions."
                                            title="AI Overview (Enterprise)" style="display:none;">
                                            <i class="fas fa-brain"></i><span>AI Overview</span>
                                        </button>
                                        <button type="button" id="shareResultsBtn" class="artefact-btn share-btn artefact-rich-tip"
                                            data-tip-title="Share report"
                                            data-tip-text="Send selected report artefacts by email to one or more recipients."
                                            title="Share by email" style="display:inline-flex;">
                                            <i class="fas fa-share-alt"></i><span>Share</span>
                                        </button>
                                    </div>
                                </div>
                                <p id="upgradeMessage" class="upgrade-msg"
                                    style="display:none; margin-top:8px; font-size:12px; color: var(--text-light);"></p>
                            </div>

                            <div class="results-panel active" id="panelFindings">
                                <div class="findings-toolbar">
                                    <input type="text" id="findingSearchInput" class="finding-search-input"
                                        placeholder="Search findings, evidence, or remediation...">
                                    <select id="findingDomainFilter" class="finding-filter-select">
                                        <option value="all">All test domains</option>
                                    </select>
                                    <select id="findingSeverityFilter" class="finding-filter-select">
                                        <option value="all">All severities</option>
                                        <option value="critical">Critical</option>
                                        <option value="high">High</option>
                                        <option value="medium">Medium</option>
                                        <option value="low">Low</option>
                                        <option value="info">Informational</option>
                                        <option value="secure">Secure</option>
                                    </select>
                                    <select id="findingCategoryFilter" class="finding-filter-select">
                                        <option value="all">All categories</option>
                                    </select>
                                    <select id="findingSortSelect" class="finding-filter-select">
                                        <option value="severity_desc">Sort: highest risk first</option>
                                        <option value="severity_asc">Sort: lowest risk first</option>
                                        <option value="name_asc">Sort: name A-Z</option>
                                    </select>
                                    <button type="button" id="findingResetFiltersBtn" class="finding-reset-btn">
                                        <i class="fas fa-rotate-left"></i><span>Reset</span>
                                    </button>
                                </div>
                                <div id="findingsMeta" class="findings-meta">0 finding(s)</div>
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
                            </section>
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
    <button type="button" id="scrollToBottomBtn" class="back-to-top" title="Scroll to bottom" aria-label="Scroll to bottom">
        <i class="fas fa-arrow-down"></i>
    </button>

    <div id="userReportOverlay" class="report-overlay" style="display:none;" aria-hidden="true">
        <div class="report-overlay__backdrop"></div>
        <div class="report-overlay__panel">
            <div class="report-overlay__head">
                <h3><i class="fas fa-file-lines"></i> AI Summary</h3>
                <button type="button" class="report-overlay__close" data-close-overlay="userReportOverlay" aria-label="Close AI summary overlay">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="report-overlay__body" id="userReportOverlayBody"></div>
        </div>
    </div>

    <div id="downloadsOverlay" class="report-overlay" style="display:none;" aria-hidden="true">
        <div class="report-overlay__backdrop"></div>
        <div class="report-overlay__panel">
            <div class="report-overlay__head">
                <h3><i class="fas fa-download"></i> Downloads & Actions</h3>
                <button type="button" class="report-overlay__close" data-close-overlay="downloadsOverlay" aria-label="Close downloads overlay">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="report-overlay__body">
                <p class="report-overlay__sub">Export reports or trigger follow-up actions for this scan.</p>
                <div class="report-overlay__actions" id="downloadsOverlayActions"></div>
            </div>
        </div>
    </div>

    <!-- Risk Score Modal (click the score circle) -->
    <div id="riskModal" class="modal-overlay"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:10002; align-items:center; justify-content:center;">
        <div class="modal risk-analysis-modal risk-modal-band-good"
            style="background:var(--bg-card); padding:22px; border-radius:16px; max-width:900px; width:92%; border:1px solid var(--border-color); max-height:min(92vh,880px); display:flex; flex-direction:column;">
            <div class="risk-modal-top-row" style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:12px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div class="risk-modal-header-icon"
                        style="width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div>
                        <div style="font-size:16px;font-weight:800;">Risk score analysis</div>
                        <div id="riskModalSubtitle" class="risk-modal-subtitle" style="font-size:12px;color:var(--text-light);">Score, bands, and severity breakdown</div>
                    </div>
                </div>
                <button type="button" id="riskModalClose" class="icon-btn" title="Close"
                    style="width:38px;height:38px;"><i class="fas fa-times"></i></button>
            </div>

            <div class="risk-modal-body-grid" style="display:grid; grid-template-columns: 260px 1fr; gap:18px; align-items:start; min-height:0; flex:1;">
                <div class="risk-modal-left risk-modal-left-panel"
                    style="padding:14px; border-radius:14px; border:1px solid var(--border-color);">
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
                        <div id="riskModalLevel" class="risk-modal-level" style="font-weight:800; font-size:14px;">Secure Risk</div>
                        <div id="riskModalDesc" class="risk-modal-desc"
                            style="margin-top:6px; font-size:12px; line-height:1.5;">—</div>
                    </div>
                </div>

                <div class="risk-modal-right risk-modal-right-scroll">
                    <div class="risk-modal-card" id="riskModalSummaryCard" style="display:none;">
                        <div style="font-size:13px; font-weight:700; margin-bottom:8px;">What this scan means</div>
                        <p id="riskModalFriendlyMsg" style="margin:0; font-size:12px; color:var(--text-main); line-height:1.55;"></p>
                        <ul id="riskModalFriendlySteps" class="risk-modal-steps" style="margin:8px 0 0 0; padding-left:18px; font-size:12px; line-height:1.5; color:var(--text-main);"></ul>
                    </div>
                    <div class="risk-modal-card">
                        <div style="font-size:13px; font-weight:700; margin-bottom:8px;">Posture snapshot</div>
                        <div id="riskModalPosture" style="font-size:12px; color:var(--text-light); line-height:1.55;"></div>
                    </div>
                    <div class="risk-modal-card">
                        <div style="font-size:13px; font-weight:700; margin-bottom:8px;">How the score is calculated</div>
                        <p id="riskModalFormula" style="margin:0 0 6px 0; font-size:12px; color:var(--text-main); line-height:1.5;"></p>
                        <div id="riskModalContributions" style="font-size:11px; color:var(--text-light); line-height:1.45;"></div>
                        <p id="riskModalScoreNote" style="margin:8px 0 0 0; font-size:11px; color:var(--text-light); font-style:italic;"></p>
                    </div>
                    <div
                        style="padding:14px; border-radius:14px; border:1px solid var(--border-color); background:var(--bg-main); margin-bottom:12px;">
                        <div style="font-size:13px; font-weight:700; margin-bottom:10px;">Score bands</div>
                        <div class="risk-band" data-risk-band="good">
                            <div class="risk-band-row"><span>Good</span><span>0–30</span></div>
                            <div class="risk-band-bar">
                                <div class="seg good"></div>
                            </div>
                        </div>
                        <div class="risk-band" data-risk-band="neutral">
                            <div class="risk-band-row"><span>Neutral</span><span>31–60</span></div>
                            <div class="risk-band-bar">
                                <div class="seg neutral"></div>
                            </div>
                        </div>
                        <div class="risk-band" data-risk-band="bad">
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
            // Deprecated accordion behavior; details now open in a full report modal.
            openFindingReportModal(index);
        }

        /** Score bands shown in UI: Good 0–30, Neutral 31–60, Bad 61–100 */
        function riskScoreBandKey(score) {
            const s = Number(score);
            const n = Number.isFinite(s) ? s : 0;
            if (n <= 30) return 'good';
            if (n <= 60) return 'neutral';
            return 'bad';
        }

        function scrollToDetailedFindingsSection() {
            const domainFilter = document.getElementById('findingDomainFilter');
            const catFilter = document.getElementById('findingCategoryFilter');
            if (domainFilter) domainFilter.value = 'all';
            if (catFilter) catFilter.value = 'all';
            try {
                if (typeof applyFindingsFilters === 'function') applyFindingsFilters();
            } catch (e) { /* findings list may not exist yet */ }
            document.querySelector('.results-tab[data-tab="findings"]')?.click();
            const anchor = document.getElementById('detailedFindingsAnchor');
            if (anchor) {
                anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
                try {
                    anchor.focus({ preventScroll: true });
                } catch (e) { }
            } else {
                document.getElementById('reportFindingsSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        function getRiskScoreDetail(summary) {
            const s = summary || {};
            if (s.risk_score_detail && typeof s.risk_score_detail === 'object') {
                return s.risk_score_detail;
            }
            const b = s.severity_breakdown || {};
            const c = (b.critical || 0) * 10;
            const h = (b.high || 0) * 5;
            const m = (b.medium || 0) * 2;
            const l = (b.low || 0) * 1;
            const raw = c + h + m + l;
            return {
                raw_points: raw,
                raw_points_cap: 106,
                formula_short: 'Issue points = Critical×10 + High×5 + Medium×2 + Low×1. The 0–100 score scales raw points against a reference cap (106) and is capped at 100.',
                contributions: { critical: c, high: h, medium: m, low: l },
                excluded_from_score: ['info', 'secure'],
                note: 'Informational and "secure" findings are listed separately and are not part of this numeric score.'
            };
        }

        function updateRiskDisplay(summary) {
            const score = summary.risk_score || 0;
            const level = summary.risk_level || 'Secure';
            lastSummaryForModal = summary || null;
            const detail = getRiskScoreDetail(summary);

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
                let band = '';
                if (score <= 30) band = 'Good band (0–30): relatively low modeled risk from weighted findings.';
                else if (score <= 60) band = 'Neutral band (31–60): meaningful exposure — prioritize higher severities.';
                else band = 'High band (61–100): severe modeled risk — treat as urgent.';
                const formula = detail.formula_short ? ' ' + detail.formula_short : '';
                tipDesc.textContent = band + formula;
            }
            if (tipBreak) {
                const b = summary.severity_breakdown || {};
                const items = [
                    { k: 'critical', label: 'Critical', cls: 'critical', v: b.critical || 0 },
                    { k: 'high', label: 'High', cls: 'high', v: b.high || 0 },
                    { k: 'medium', label: 'Medium', cls: 'medium', v: b.medium || 0 },
                    { k: 'low', label: 'Low', cls: 'low', v: b.low || 0 },
                    { k: 'info', label: 'Info', cls: 'info', v: b.info || 0 },
                    { k: 'secure', label: 'Secure', cls: 'secure', v: b.secure || 0 },
                ].filter(it => it.v > 0);
                const show = items.length ? items : [
                    { k: 'critical', label: 'Critical', cls: 'critical', v: 0 },
                    { k: 'high', label: 'High', cls: 'high', v: 0 },
                    { k: 'medium', label: 'Medium', cls: 'medium', v: 0 },
                    { k: 'low', label: 'Low', cls: 'low', v: 0 },
                ];
                tipBreak.innerHTML = show.map(it => `
                    <div class="risk-mini-chip ${it.cls}">
                        <span class="risk-mini-dot"></span>
                        <span>${it.v} ${it.label}</span>
                    </div>
                `).join('');
            }

            // Keep raw point math in the Risk score analysis modal only — not in the main overview.
            const formulaLine = document.getElementById('riskScoreFormulaLine');
            if (formulaLine) {
                formulaLine.style.display = 'none';
                formulaLine.textContent = '';
            }

            // Severity badges
            const container = document.getElementById('severityCounts');
            const breakdown = summary.severity_breakdown || {};
            const badges = [];

            if (breakdown.critical > 0) badges.push(`<span class="severity-badge critical"><i class="fas fa-skull"></i> ${breakdown.critical} Critical</span>`);
            if (breakdown.high > 0) badges.push(`<span class="severity-badge high"><i class="fas fa-exclamation-triangle"></i> ${breakdown.high} High</span>`);
            if (breakdown.medium > 0) badges.push(`<span class="severity-badge medium"><i class="fas fa-exclamation-circle"></i> ${breakdown.medium} Medium</span>`);
            if (breakdown.low > 0) badges.push(`<span class="severity-badge low"><i class="fas fa-info-circle"></i> ${breakdown.low} Low</span>`);
            if (breakdown.info > 0) badges.push(`<span class="severity-badge info"><i class="fas fa-circle-info"></i> ${breakdown.info} Info</span>`);
            if (breakdown.secure > 0) badges.push(`<span class="severity-badge secure"><i class="fas fa-shield-check"></i> ${breakdown.secure} Secure</span>`);

            if (badges.length === 0 && !(summary.total_vulnerabilities > 0)) {
                badges.push(`<span class="severity-badge secure"><i class="fas fa-check-circle"></i> No issues in score</span>`);
            } else if (badges.length === 0) {
                badges.push(`<span class="severity-badge info"><i class="fas fa-list"></i> ${summary.total_vulnerabilities || 0} finding(s)</span>`);
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
                const summary = lastSummaryForModal;
                const score = summary.risk_score || 0;
                const level = summary.risk_level || 'Secure';
                const breakdown = summary.severity_breakdown || {};
                const detail = getRiskScoreDetail(summary);
                const friendly = summary.user_friendly || {};
                const msg = (friendly.message || '').trim();
                const actions = Array.isArray(friendly.priority_actions) ? friendly.priority_actions : [];
                const sumSev = (breakdown.critical || 0) + (breakdown.high || 0) + (breakdown.medium || 0) +
                    (breakdown.low || 0) + (breakdown.info || 0) + (breakdown.secure || 0);
                const total = sumSev > 0 ? sumSev : Math.max(1, summary.total_vulnerabilities || 1);

                const bandKey = riskScoreBandKey(score);
                const modalPanel = modal.querySelector('.risk-analysis-modal');
                if (modalPanel) {
                    modalPanel.classList.remove('risk-modal-band-good', 'risk-modal-band-neutral', 'risk-modal-band-bad');
                    modalPanel.classList.add('risk-modal-band-' + bandKey);
                }
                modal.querySelectorAll('.risk-band[data-risk-band]').forEach(function (row) {
                    row.classList.toggle('risk-band--current', row.getAttribute('data-risk-band') === bandKey);
                });

                const scoreEl = document.getElementById('riskModalScore');
                const levelEl = document.getElementById('riskModalLevel');
                const descEl = document.getElementById('riskModalDesc');
                const subEl = document.getElementById('riskModalSubtitle');
                if (scoreEl) {
                    scoreEl.textContent = score;
                    const fillCol = bandKey === 'good' ? 'var(--success-color)' : (bandKey === 'neutral' ? 'var(--warning-color)' : 'var(--danger-color)');
                    scoreEl.setAttribute('fill', fillCol);
                }
                if (levelEl) levelEl.textContent = level + ' Risk';
                if (subEl) {
                    const bandLabel = bandKey === 'good' ? 'Good band (0–30)' : (bandKey === 'neutral' ? 'Neutral band (31–60)' : 'High band (61–100)');
                    subEl.textContent = bandLabel + ' · severity breakdown';
                }
                if (descEl) {
                    let band = '';
                    if (score <= 30) band = 'Band: Good (0–30). ';
                    else if (score <= 60) band = 'Band: Neutral (31–60). ';
                    else band = 'Band: High (61–100). ';
                    let teaser = '';
                    if (msg) {
                        const dot = msg.indexOf('.');
                        teaser = (dot >= 0 ? msg.slice(0, dot + 1) : (msg.length > 140 ? msg.slice(0, 137) + '…' : msg)) + ' ';
                    }
                    descEl.textContent = band + (teaser || 'See details at right for interpretation and next steps.');
                }

                const sumCard = document.getElementById('riskModalSummaryCard');
                const msgEl = document.getElementById('riskModalFriendlyMsg');
                const stepsEl = document.getElementById('riskModalFriendlySteps');
                if (sumCard && msgEl && stepsEl) {
                    if (msg || actions.length) {
                        sumCard.style.display = 'block';
                        msgEl.textContent = msg || 'Review findings below and use suggested next steps.';
                        stepsEl.innerHTML = '';
                        actions.forEach(function (a) {
                            const li = document.createElement('li');
                            li.textContent = String(a || '');
                            stepsEl.appendChild(li);
                        });
                    } else {
                        sumCard.style.display = 'none';
                    }
                }

                const postureEl = document.getElementById('riskModalPosture');
                if (postureEl) {
                    const data = typeof lastScanData !== 'undefined' && lastScanData ? lastScanData : null;
                    const lines = [];
                    if (data && data.target) {
                        lines.push('<div><strong>Target</strong><br><span style="word-break:break-all;">' + escapeHtml(String(data.target)) + '</span></div>');
                    }
                    if (data && data.ssl) {
                        const g = data.ssl.grade != null ? String(data.ssl.grade) : '—';
                        const st = document.getElementById('sslStatus') ? document.getElementById('sslStatus').textContent : '';
                        lines.push('<div style="margin-top:8px;"><strong>SSL/TLS</strong><br>Grade ' + escapeHtml(g) + (st ? ' · ' + escapeHtml(st) : '') + '</div>');
                    }
                    if (data && data.headers) {
                        const pct = data.headers.score != null ? data.headers.score : 0;
                        const pr = data.headers.present != null ? data.headers.present : 0;
                        const mi = data.headers.missing != null ? data.headers.missing : 0;
                        lines.push('<div style="margin-top:8px;"><strong>Security headers</strong><br>' + escapeHtml(String(pct)) + '% compliance · ' + escapeHtml(String(pr)) + ' present · ' + escapeHtml(String(mi)) + ' missing</div>');
                    }
                    if (data && data.crawler && Array.isArray(data.crawler.discovered_urls)) {
                        const n = data.crawler.discovered_urls.length;
                        const chk = data.crawler.pages_checked != null ? data.crawler.pages_checked : 0;
                        lines.push('<div style="margin-top:8px;"><strong>Discovery</strong><br>' + escapeHtml(String(n)) + ' URL(s) discovered, ' + escapeHtml(String(chk)) + ' page(s) checked for headers.</div>');
                    }
                    postureEl.innerHTML = lines.length ? lines.join('') : '<span style="color:var(--text-light);">Run a scan to see posture details here.</span>';
                }

                const formulaEl = document.getElementById('riskModalFormula');
                const contribEl = document.getElementById('riskModalContributions');
                const noteEl = document.getElementById('riskModalScoreNote');
                if (formulaEl) formulaEl.textContent = detail.formula_short || '';
                if (contribEl) {
                    const co = detail.contributions || {};
                    const w = detail.weights || { critical: 10, high: 5, medium: 2, low: 1 };
                    const critN = breakdown.critical || 0, highN = breakdown.high || 0, medN = breakdown.medium || 0, lowN = breakdown.low || 0;
                    const critPts = co.critical != null ? co.critical : critN * (w.critical || 10);
                    const highPts = co.high != null ? co.high : highN * (w.high || 5);
                    const medPts = co.medium != null ? co.medium : medN * (w.medium || 2);
                    const lowPts = co.low != null ? co.low : lowN * (w.low || 1);
                    const parts = [
                        'Critical: ' + critN + ' × ' + (w.critical || 10) + ' = ' + critPts + ' pts',
                        'High: ' + highN + ' × ' + (w.high || 5) + ' = ' + highPts + ' pts',
                        'Medium: ' + medN + ' × ' + (w.medium || 2) + ' = ' + medPts + ' pts',
                        'Low: ' + lowN + ' × ' + (w.low || 1) + ' = ' + lowPts + ' pts',
                    ];
                    const raw = detail.raw_points != null ? detail.raw_points : '';
                    const cap = detail.raw_points_cap != null ? detail.raw_points_cap : 106;
                    const tail = raw !== '' ? '<div style="margin-top:6px;"><strong>Raw total:</strong> ' + raw + ' pts (reference cap ' + cap + ') → <strong>' + score + '</strong>/100 displayed.</div>' : '';
                    contribEl.innerHTML = parts.join('<br>') + tail;
                }
                if (noteEl) noteEl.textContent = detail.note || '';

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
                        { label: 'Info', cls: 'info', v: breakdown.info || 0 },
                        { label: 'Secure', cls: 'secure', v: breakdown.secure || 0 },
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

            const container = document.getElementById('userSummary');
            if (!container) return;

            if (!message) {
                container.style.display = 'none';
                container.innerHTML = '';
                return;
            }

            container.style.display = 'block';
            container.innerHTML = '';
            if (message) {
                const title = document.createElement('div');
                title.className = 'user-summary-callout__label';
                title.style.cssText = 'font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-light);margin-bottom:6px;';
                title.textContent = 'What this means';
                const p = document.createElement('p');
                p.textContent = message;
                container.appendChild(title);
                container.appendChild(p);
            }
            const sub = document.createElement('div');
            sub.style.cssText = 'font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-light);margin:10px 0 6px 0;';
            sub.textContent = 'Suggested next steps';
            container.appendChild(sub);
            const workflow = document.createElement('ul');
            const workflowItems = [
                'Step 1: Open Detailed findings to review evidence and exact remediation guidance.',
                'Step 2: Use domain, severity, and category filters to prioritize highest-risk items first.',
                'Step 3: Read the AI summary tab for plain-language context you can share with stakeholders.',
                'Step 4: Use download and share actions to hand off the report to your team.'
            ];
            workflowItems.forEach(function (line) {
                const li = document.createElement('li');
                li.textContent = line;
                workflow.appendChild(li);
            });
            container.appendChild(workflow);

            const jump = document.createElement('div');
            jump.className = 'user-summary-callout__jump';
            jump.setAttribute('role', 'note');
            const jumpText = document.createElement('span');
            jumpText.textContent = 'For full evidence, remediation steps, and filters, see ';
            const jumpLink = document.createElement('button');
            jumpLink.type = 'button';
            jumpLink.className = 'user-summary-jump-link';
            jumpLink.setAttribute('aria-label', 'Scroll to detailed findings');
            jumpLink.textContent = 'Detailed findings';
            jumpLink.addEventListener('click', function () {
                scrollToDetailedFindingsSection();
            });
            jump.appendChild(jumpText);
            jump.appendChild(jumpLink);
            jump.appendChild(document.createTextNode(' below.'));
            container.appendChild(jump);
        }

        const findingReportCache = {};
        const WARMUP_CONCURRENCY = 4;
        const warmupInFlight = new Set();
        const inFlightPromises = new Map();
        let scanFindingsAll = [];
        let findingWarmupPromise = null;
        let recommendationStemsSeen = new Set();
        let currentScanRunId = '';
        let activeScanController = null;
        let activeScanToken = 0;
        let scanProgressTimer = null;
        let scanStagePollTimer = null;
        let scanSyntheticTimer = null;
        let scanSyntheticDelayTimer = null;
        let scanSyntheticStartMs = 0;
        let lastBackendProgress = { progress: 0, stage: '', status: '' };
        let sqTimelineRunsStore = [];
        const SQ_HISTORY_PAGE = '/ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Frontend/historical_scans.php';
        const SQ_TIMELINE_INLINE_PREVIEW = 4;
        let reportReconcileTimer = null;
        let reportReconcileTicks = 0;
        const manualRegenInFlight = new Set();
        const manualRegenCount = new Map(); // cacheKey -> times user regenerated
        const autoRegenAttempts = new Map();
        const autoRegenLastTryAt = new Map();
        const autoRegenFirstSeenAt = new Map();
        let currentFindingEvidenceRaw = '';
        let activeFindingModalToken = 0;
        const readyReportToastShown = new Set();
        const reportSourceTelemetrySent = new Set();
        const severityRank = { critical: 5, high: 4, medium: 3, low: 2, info: 1, secure: 0 };
        function quickStableHash(input) {
            const str = String(input || '');
            let h = 2166136261;
            for (let i = 0; i < str.length; i++) {
                h ^= str.charCodeAt(i);
                h += (h << 1) + (h << 4) + (h << 7) + (h << 8) + (h << 24);
            }
            return (h >>> 0).toString(16);
        }
        function makeCacheKey(vuln) {
            const uid = vuln?._sq_uid || '';
            const run = String(currentScanRunId || '');
            const name = vuln?.name || '';
            const sev = vuln?.severity || '';
            const desc = vuln?.description || '';
            const ev = vuln?.evidence || '';
            const fp = quickStableHash(`${name}|${sev}|${desc}|${ev}`);
            return `${run}|${uid}|${fp}`;
        }
        function sortByPriority(vulns) {
            return [...(vulns || [])].sort((a, b) => {
                const sa = severityRank[String(a?.severity || '').toLowerCase()] ?? -1;
                const sb = severityRank[String(b?.severity || '').toLowerCase()] ?? -1;
                return sb - sa;
            });
        }
        function trackFindingReportSourceEvent(vuln, source, quality) {
            const src = String(source || '').toLowerCase();
            if (!src || src === 'ai') return;
            const uid = String(vuln?._sq_uid || '');
            const key = uid + '|' + src;
            if (uid && reportSourceTelemetrySent.has(key)) return;
            if (uid) reportSourceTelemetrySent.add(key);
            const payload = {
                event_key: 'finding_report_source_non_ai',
                source: src,
                vulnerability_uid: uid,
                finding_name: String(vuln?.name || ''),
                severity: String(vuln?.severity || ''),
                scan_run_id: String(currentScanRunId || ''),
                quality_valid: !!quality?.valid,
                quality_issue_count: Array.isArray(quality?.issues) ? quality.issues.length : 0
            };
            const url = '../Backend/track_finding_report_event.php';
            try {
                if (navigator.sendBeacon) {
                    const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
                    navigator.sendBeacon(url, blob);
                } else {
                    fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        keepalive: true,
                        body: JSON.stringify(payload)
                    }).catch(() => { });
                }
            } catch (e) { }
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

            const esc = (s) => (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            const oneLine = (s) => String(s || '').replace(/\s+/g, ' ').trim();
            const clamp = (s, n) => {
                const t = oneLine(s);
                return t.length > n ? t.slice(0, n - 1) + '…' : t;
            };
            const fullTitle = oneLine(vuln.name || 'Security Finding');
            const displayTitle = clamp(fullTitle, 88);
            const shortDesc = (vuln.description || '').length > 165
                ? (vuln.description || '').slice(0, 165) + '...'
                : (vuln.description || '');
            const cvssHint = vuln.cvss_score ? `CVSS ${esc(String(vuln.cvss_score))}` : '';
            const cacheKey = makeCacheKey(vuln);
            const isReady = !!findingReportCache[cacheKey]?.report;
            const btnStateClass = isReady ? 'ready' : 'pending';
            const btnLabel = isReady ? 'Open Report' : 'Preparing...';

            return `
        <div class="vuln-card ${vuln.severity}" id="vuln-${index}">
            <div class="vuln-header">
                <div class="vuln-title-section">
                    <div class="vuln-icon" style="color: ${config.color}">
                        <i class="fas ${icon}"></i>
                    </div>
                    <div class="vuln-title-wrap">
                        <div class="vuln-title" title="${esc(fullTitle)}">${esc(displayTitle)}</div>
                        
                    </div>
                </div>
                <div class="vuln-actions">
                    <span class="vuln-severity">${vuln.severity}</span>
                    <button type="button" class="view-finding-btn ${btnStateClass}" data-vuln-index="${index}" data-vuln-uid="${esc(String(vuln._sq_uid || ''))}" data-vuln-name="${esc(fullTitle)}">
                        <i class="fas fa-file-shield"></i> <span class="btn-label">${btnLabel}</span>
                    </button>
                    <button type="button" class="regen-finding-btn" data-vuln-index="${index}" data-vuln-uid="${esc(String(vuln._sq_uid || ''))}" title="Regenerate this report">
                        <i class="fas fa-rotate-right"></i> <span class="btn-label">Regenerate</span>
                    </button>
                </div>
            </div>
            <div class="vuln-body" style="display:block;">
                <div class="vuln-section">
                    <div class="vuln-section-title"><i class="fas fa-align-left"></i> Executive Overview</div>
                    <div class="vuln-section-content">${esc(shortDesc || 'See detailed report.')}</div>
                </div>
                <div class="vuln-meta-row">
                    <span><i class="fas fa-magnifying-glass-chart"></i> Click Open Report for full analysis</span>
                    ${cvssHint ? `<span><i class="fas fa-chart-line"></i> ${cvssHint}</span>` : ''}
                </div>
            </div>
        </div>
    `;
        }
        function toListHtml(items) {
            if (!Array.isArray(items) || items.length === 0) return '<li>Not provided.</li>';
            return items.map(i => `<li>${escapeHtml(String(i || ''))}</li>`).join('');
        }
        function escapeRegExp(s) {
            return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }
        function normalizeEvidenceLineBreaks(t) {
            let s = String(t || '');
            const keys = [
                'Finding Title:', 'Finding Category:', 'Finding Reference:',
                'Test Performed:', 'Test Method:', 'Request Method:', 'Request URL:', 'Parameter Tested:', 'Injected Payload:',
                'Expected Secure Result:', 'Expected Result:', 'Expected Protocols:', 'Expected Secure Value:',
                'Observed Result:', 'Observed Scheme:', 'Handshake Status:', 'Handshake Completed:', 'Validation Error:', 'Error:',
                'What This Means:', 'Status:', 'Classification:', 'Weak Pattern Matched:',
                'Target:', 'Target URL:', 'Target Hostname:', 'Resolved Hostname:', 'Resolved IP:', 'Port:', 'Target Port:',
                'Detection Time:', 'Timestamp (EAT):', 'Current Date (EAT):',
                'Header Validation Evidence', 'Missing Security Headers:', 'Response Status:', 'Content-Type:'
            ];
            keys.forEach(function (k) {
                const re = new RegExp('([^\\n])\\s*' + escapeRegExp(k), 'g');
                s = s.replace(re, '$1\n' + k);
            });
            return s;
        }
        /** Multi-line aware: values run until the next known section header (Key: at line start). */
        function parseStructuredEvidenceSections(text) {
            const normalized = normalizeEvidenceLineBreaks(String(text || '').trim());
            const lines = normalized.split(/\r?\n/);
            const map = {};
            const EVIDENCE_KNOWN_KEYS = [
                'Header Validation Evidence',
                'Expected Result',
                'Expected Secure Result',
                'Expected Protocols',
                'Expected Secure Value',
                'Observed Scheme',
                'Observed Result',
                'Handshake Status',
                'Handshake Completed',
                'Validation Error',
                'Error',
                'What This Means',
                'Status',
                'Classification',
                'Weak Pattern Matched',
                'Finding Reference',
                'Finding Category',
                'Finding Title',
                'Test Performed',
                'Test Method',
                'Request Method',
                'Request URL',
                'Parameter Tested',
                'Injected Payload',
                'Detection Time',
                'Timestamp (EAT)',
                'Current Date (EAT)',
                'Target'
                , 'Target URL',
                'Target Hostname',
                'Resolved Hostname',
                'Resolved IP',
                'Port',
                'Target Port',
                'Response Status',
                'Content-Type',
                'Missing Security Headers'
            ];
            EVIDENCE_KNOWN_KEYS.sort(function (a, b) { return b.length - a.length; });
            const ALIASES = {
                'target url': 'Target',
                'target hostname': 'Target',
                'resolved hostname': 'Target',
                'resolved ip': 'Target',
                'port': 'Target',
                'target port': 'Target',
                'timestamp (eat)': 'Detection Time',
                'current date (eat)': 'Detection Time',
                'test method': 'Test Performed',
                'request method': 'Test Performed',
                'request url': 'Test Performed',
                'parameter tested': 'Test Performed',
                'injected payload': 'Test Performed',
                'expected result': 'Expected Secure Result',
                'expected secure value': 'Expected Secure Result',
                'expected protocols': 'Expected Secure Result',
                'observed scheme': 'Observed Result',
                'handshake status': 'Observed Result',
                'handshake completed': 'Observed Result',
                'validation error': 'Observed Result',
                'error': 'Observed Result',
                'response status': 'Observed Result',
                'content-type': 'Observed Result',
                'missing security headers': 'Observed Result',
                'status': 'What This Means',
                'classification': 'What This Means',
                'weak pattern matched': 'What This Means'
            };
            function tryMatchKey(trimmed) {
                for (let ki = 0; ki < EVIDENCE_KNOWN_KEYS.length; ki++) {
                    const key = EVIDENCE_KNOWN_KEYS[ki];
                    const prefix = key + ':';
                    if (trimmed.length >= prefix.length && trimmed.slice(0, prefix.length).toLowerCase() === prefix.toLowerCase()) {
                        const canonical = ALIASES[key.toLowerCase()] || key;
                        return { key: canonical, rest: trimmed.slice(prefix.length).trim() };
                    }
                }
                return null;
            }
            let currentKey = null;
            for (let i = 0; i < lines.length; i++) {
                const trimmed = lines[i].trim();
                if (!trimmed) {
                    if (currentKey) map[currentKey] = (map[currentKey] || '') + '\n';
                    continue;
                }
                const m = tryMatchKey(trimmed);
                if (m) {
                    currentKey = m.key;
                    if (map[currentKey] === undefined) map[currentKey] = m.rest;
                    else map[currentKey] += '\n' + m.rest;
                } else if (currentKey) {
                    map[currentKey] = (map[currentKey] || '') + '\n' + trimmed;
                }
            }
            return map;
        }
        function stripInlineFindingMeta(val) {
            let s = String(val || '');
            const cut = s.search(/\s+Finding Title:/i);
            if (cut !== -1) s = s.slice(0, cut);
            return s.trim();
        }
        function renderColoredEvidenceLines(textBlock) {
            const lines = String(textBlock || '').split(/\r?\n/).map(l => l.trim()).filter(Boolean);
            return lines.map(function (ln) {
                const low = ln.toLowerCase();
                let cls = 'evidence-line-neutral';
                if (low.startsWith('expected')) cls = 'evidence-line-expected';
                else if (low.startsWith('observed')) cls = 'evidence-line-observed';
                else if (low.startsWith('test performed') || low.startsWith('test type')) cls = 'evidence-line-test';
                else if (low.startsWith('header checked') || low.startsWith('header validation')) cls = 'evidence-line-test';
                else if (low.startsWith('target') || low.startsWith('detection time')) cls = 'evidence-line-meta';
                else if (low.indexOf('missing security headers') !== -1) cls = 'evidence-line-observed';
                return '<div class="evidence-line ' + cls + '">' + escapeHtml(ln) + '</div>';
            }).join('');
        }
        function buildEvidenceExplainedPanel(rawText) {
            const normalized = normalizeEvidenceLineBreaks(String(rawText || '').trim());
            const sectionMap = parseStructuredEvidenceSections(normalized);
            const lines = normalized.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
            const buckets = { test: [], expected: [], observed: [], meta: [] };
            const pickLines = (regexes, maxItems) => {
                const out = [];
                for (let i = 0; i < lines.length; i++) {
                    const ln = lines[i];
                    if (regexes.some(re => re.test(ln))) {
                        out.push(ln);
                        if (out.length >= maxItems) break;
                    }
                }
                return out;
            };
            const tested = String(sectionMap['Test Performed'] || '').trim();
            const expected = String(sectionMap['Expected Secure Result'] || '').trim();
            const observed = String(sectionMap['Observed Result'] || '').trim();
            const target = String(sectionMap['Target'] || '').trim();
            const detTime = String(sectionMap['Detection Time'] || '').trim();

            buckets.test = tested
                ? tested.split(/\r?\n/).map(s => s.trim()).filter(Boolean)
                : pickLines([/^Test Method\s*:/i, /^Test Performed\s*:/i, /^Request Method\s*:/i, /^Request URL\s*:/i, /^Header Checked\s*:/i, /^Parameter Tested\s*:/i, /^Injected Payload\s*:/i], 4);
            buckets.expected = expected
                ? expected.split(/\r?\n/).map(s => s.trim()).filter(Boolean)
                : pickLines([/^Expected Secure Result\s*:/i, /^Expected Secure Value\s*:/i, /^Expected Result\s*:/i, /^Expected Protocols\s*:/i], 3);
            buckets.observed = observed
                ? observed.split(/\r?\n/).map(s => s.trim()).filter(Boolean)
                : pickLines([/^Observed Result\s*:/i, /^Observed Scheme\s*:/i, /^Handshake Result\s*:/i, /^Handshake Status\s*:/i, /^Handshake Completed\s*:/i, /^Error\s*:/i, /^Response Status\s*:/i, /^Validation Error\s*:/i, /^Missing Security Headers\s*:/i], 5);
            if (target) buckets.meta.push('Target: ' + target);
            if (detTime) buckets.meta.push('Detection Time: ' + detTime);

            function blockHtml(title, subtitle, cls, arr) {
                if (!arr || !arr.length) return '';
                const inner = arr.map(function (ln) {
                    return '<div class="evidence-explain-line">' + escapeHtml(ln) + '</div>';
                }).join('');
                return '<div class="evidence-explain-block ' + cls + '"><div class="evidence-explain-block-head"><span class="evidence-explain-block-title">' + title + '</span><span class="evidence-explain-block-sub">' + subtitle + '</span></div><div class="evidence-explain-lines">' + inner + '</div></div>';
            }
            let html = '';
            html += blockHtml('What was tested', 'How this check was run against the target', 'ev-explain-test', buckets.test);
            html += blockHtml('What was expected', 'Secure configuration the scanner looks for', 'ev-explain-expected', buckets.expected);
            html += blockHtml('What was observed', 'What the server actually returned or omitted', 'ev-explain-observed', buckets.observed);
            html += blockHtml('Context', 'Target and timing metadata', 'ev-explain-meta', buckets.meta);
            if (!html.trim()) {
                html = '<div class="evidence-explain-fallback"><div class="evidence-line evidence-line-neutral">No structured explanation lines were found for this finding. Please review the raw evidence pane.</div></div>';
            }
            return html;
        }
        function renderInlineEvidence(rawText) {
            const text = normalizeEvidenceLineBreaks(String(rawText || '').trim());
            if (!text) return '<div class="evidence-empty">No evidence available.</div>';
            const lines = text.split(/\r?\n/);
            const kv = {};
            const KV_ALIASES = {
                'target url': 'Target',
                'target hostname': 'Target',
                'resolved hostname': 'Target',
                'resolved ip': 'Resolved IP',
                'target port': 'Port',
                'timestamp (eat)': 'Detection Time',
                'current date (eat)': 'Detection Time',
                'test method': 'Test Performed',
                'request method': 'Request Method',
                'request url': 'Request URL',
                'expected result': 'Expected Secure Result',
                'expected secure value': 'Expected Secure Result',
                'expected protocols': 'Expected Secure Result',
                'observed scheme': 'Observed Result',
                'handshake status': 'Observed Result',
                'handshake completed': 'Observed Result',
                'validation error': 'Observed Result',
                'error': 'Observed Result'
            };
            let currentKey = null;
            lines.forEach(raw => {
                const trimmed = raw.trim();
                if (!trimmed) return;
                const m = trimmed.match(/^([A-Za-z][A-Za-z0-9 \-\/()]{2,40})\s*:\s*(.*)$/);
                if (m) {
                    const detected = m[1].trim();
                    currentKey = KV_ALIASES[detected.toLowerCase()] || detected;
                    kv[currentKey] = (kv[currentKey] ? kv[currentKey] + '\n' : '') + m[2].trim();
                } else if (currentKey) {
                    kv[currentKey] = (kv[currentKey] || '') + '\n' + trimmed;
                }
            });
            const observed = (kv['Observed Result'] || '').trim();
            const tested = (kv['Test Performed'] || '').trim();
            const target = (kv['Target'] || '').trim();
            const time = (kv['Detection Time'] || '').trim();
            const inlineKeys = [
                'Injected Payload', 'Parameter Tested', 'Error Pattern Matched', 'Matched Text',
                'Baseline Response Time', 'Payload Response Time', 'Observed Delay Delta',
                'Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials', 'Location Header',
                'Missing Attribute', 'Set-Cookie Header', 'Negotiated Protocol', 'Negotiated Cipher',
                'Days Since Expiry', 'Days Remaining', 'Port', 'Service', 'Resolved IP',
                'Status', 'Response Status', 'Content-Type'
            ];
            const inlineFields = inlineKeys.filter(k => kv[k] && String(kv[k]).trim()).map(k => {
                const v = String(kv[k] || '').trim().split('\n')[0];
                return '<div class="ev-inline-row"><span class="ev-inline-key">' + escapeHtml(k) + '</span><span class="ev-inline-val">' + escapeHtml(v) + '</span></div>';
            }).join('');
            let timingHtml = '';
            if (kv['Baseline Response Time'] && kv['Payload Response Time']) {
                const base = parseFloat(kv['Baseline Response Time']) || 0;
                const payload = parseFloat(kv['Payload Response Time']) || 0;
                const delta = (payload - base).toFixed(2);
                const isDelay = parseFloat(delta) > 3;
                timingHtml = `
                    <div class="ev-timing-table">
                        <div class="ev-timing-row"><span>Baseline request</span><span class="ev-timing-val">${base.toFixed(2)}s</span></div>
                        <div class="ev-timing-row"><span>Payload request</span><span class="ev-timing-val ${isDelay ? 'ev-timing-danger' : ''}">${payload.toFixed(2)}s</span></div>
                        <div class="ev-timing-row ev-timing-delta"><span>Delta</span><span class="ev-timing-val ${isDelay ? 'ev-timing-danger' : ''}">+${delta}s ${isDelay ? ' delay confirms injection' : ''}</span></div>
                    </div>`;
            }
            const observedHtml = observed
                ? `<div class="ev-observed-block"><span class="ev-observed-label">Observed Result</span><div class="ev-observed-body">${escapeHtml(observed)}</div></div>`
                : '';
            const testedHtml = tested ? '<div class="ev-tested-row"><i class="fas fa-flask"></i><span>' + escapeHtml(tested) + '</span></div>' : '';
            const metaHtml = (target || time)
                ? '<div class="ev-meta-row">' +
                (target ? '<span><i class="fas fa-bullseye"></i>' + escapeHtml(target) + '</span>' : '') +
                (time ? '<span><i class="fas fa-clock"></i>' + escapeHtml(time) + '</span>' : '') +
                '</div>'
                : '';
            return '<div class="ev-inline-wrap">' +
                testedHtml +
                metaHtml +
                (inlineFields ? '<div class="ev-inline-fields">' + inlineFields + '</div>' : '') +
                timingHtml +
                observedHtml +
                '<div class="evidence-fullraw-wrap"><button type="button" class="evidence-fullraw-btn" id="findingEvidenceViewRawBtn"><i class="fas fa-layer-group"></i> View full raw evidence</button></div>' +
                '</div>';
        }
        function renderEvidenceStructured(rawText) {
            return renderInlineEvidence(rawText);
        }
        function renderSourceBadge(source, quality) {
            const src = String(source || '').toLowerCase();
            let cls = 'source-badge-fallback';
            let icon = 'fa-rotate';
            let text = 'Deterministic report';
            if (src === 'ai') {
                cls = 'source-badge-ai';
                icon = 'fa-brain';
                text = 'AI · Quality validated';
            } else if (src === 'ai_corrected' || src === 'ai_rebuilt') {
                cls = 'source-badge-corrected';
                icon = 'fa-shield-check';
                text = 'AI · Auto-corrected';
            }
            const issues = quality?.issues?.length || 0;
            const note = issues > 0 ? ` <span class="source-badge-issues">(${issues} quality note${issues > 1 ? 's' : ''})</span>` : '';
            return `<span class="source-badge ${cls}"><i class="fas ${icon}"></i>${text}${note}</span>`;
        }
        function renderFindingModalSkeleton() {
            return `
                <div class="finding-skeleton-grid">
                    <div class="finding-card third finding-kv skeleton-card"><div class="skel skel-label"></div><div class="skel skel-value"></div></div>
                    <div class="finding-card third finding-kv skeleton-card"><div class="skel skel-label"></div><div class="skel skel-value"></div></div>
                    <div class="finding-card third finding-kv skeleton-card"><div class="skel skel-label"></div><div class="skel skel-value"></div></div>
                    <div class="finding-card third finding-kv skeleton-card"><div class="skel skel-label"></div><div class="skel skel-value"></div></div>
                    <div class="finding-card third finding-kv skeleton-card"><div class="skel skel-label"></div><div class="skel skel-value"></div></div>
                    <div class="finding-card third finding-kv skeleton-card"><div class="skel skel-label"></div><div class="skel skel-value"></div></div>
                </div>`;
        }
        function openFindingEvidenceRawModal() {
            const modal = document.getElementById('findingEvidenceRawModal');
            const rawEl = document.getElementById('findingEvidenceRawText');
            const expEl = document.getElementById('findingEvidenceExplained');
            if (!modal || !rawEl || !expEl) return;
            const raw = String(currentFindingEvidenceRaw || '').trim() || '-';
            rawEl.textContent = raw;
            expEl.innerHTML = buildEvidenceExplainedPanel(raw);
            modal.style.display = 'flex';
            modal.classList.add('active');
        }
        function closeFindingEvidenceRawModal() {
            const modal = document.getElementById('findingEvidenceRawModal');
            if (!modal) return;
            modal.classList.remove('active');
            modal.style.display = 'none';
        }
        function setFindingModalContent(report, vuln, source, quality) {
            const set = (id, value) => {
                const el = document.getElementById(id);
                if (el) el.textContent = value || '-';
            };
            const toggleCard = (cardId, value) => {
                const el = document.getElementById(cardId);
                if (!el) return;
                const hasValue = !!(value && String(value).trim());
                el.style.display = hasValue ? '' : 'none';
            };
            set('findingTitle', report.title || vuln.name || 'Security Finding');
            set('findingSeverity', report.severity || vuln.severity || '-');
            set('findingCategory', report.category || inferFindingCategory(vuln));
            set('findingTarget', report.target || (lastScanData?.target || ''));
            set('findingIp', report.ip_address || '');
            set('findingPort', report.port || '');
            set('findingService', report.service_detected || '');
            set('findingState', report.state || '');
            set('findingDetectionTime', report.detection_time || (lastScanData?.timestamp || ''));
            set('findingDescription', report.description || vuln.description || '-');
            // Keep the raw-evidence modal truly raw: prefer scanner-captured evidence first.
            currentFindingEvidenceRaw = String(vuln.evidence || report.evidence || '').trim();
            const evidenceWrap = document.getElementById('findingEvidence');
            if (evidenceWrap) evidenceWrap.innerHTML = renderEvidenceStructured(report.evidence || vuln.evidence || '');
            set('findingRiskExplanation', report.risk_explanation || vuln.indicates || '-');
            set('findingLikelihood', report.likelihood || '-');
            set('findingPriority', report.remediation_priority || '-');
            set('findingStatus', report.result_status || 'Needs Review');
            const sevEl = document.getElementById('findingSeverity');
            if (sevEl) {
                const sev = String(report.severity || vuln.severity || '').toLowerCase();
                sevEl.className = 'v severity-text severity-' + (sev || 'info');
            }
            const qBadge = document.getElementById('findingQualityBadge');
            const qText = document.getElementById('findingQualityText');
            if (qBadge) qBadge.style.display = 'none';
            if (qText) qText.textContent = '';
            toggleCard('findingCardIp', report.ip_address || '');
            toggleCard('findingCardPort', report.port || '');
            toggleCard('findingCardService', report.service_detected || '');
            toggleCard('findingCardState', report.state || '');

            const impacts = document.getElementById('findingImpactList');
            const rawImpact = report.potential_impact;
            const impactItems = Array.isArray(rawImpact) && rawImpact.length > 0
                ? rawImpact
                : (Array.isArray(vuln.potential_impact) && vuln.potential_impact.length > 0 ? vuln.potential_impact : []);
            if (impacts) impacts.innerHTML = toListHtml(impactItems);
            const recs = document.getElementById('findingRecommendations');
            const rawRecs = report.recommendations;
            const recommendationItems = Array.isArray(rawRecs) && rawRecs.length > 0
                ? rawRecs
                : (Array.isArray(vuln.recommendations) && vuln.recommendations.length > 0
                    ? vuln.recommendations
                    : (vuln.remediation ? [vuln.remediation] : []));
            if (recs) recs.innerHTML = toListHtml(recommendationItems);
            window.currentFindingContext = { report: report || {}, vuln: vuln || {} };
        }
        async function fetchFindingReport(vulnInput, opts = {}) {
            const forceFetch = !!opts.forceFetch;
            const useAi = !!opts.useAi;
            const bypassAiCache = !!opts.bypassAiCache;
            const disableRecommendationDiversity = !!opts.disableRecommendationDiversity;
            const vuln = typeof vulnInput === 'number' ? (lastVulnerabilities || [])[vulnInput] : vulnInput;
            if (!vuln) return null;
            const cacheKey = makeCacheKey(vuln);
            const inFlightKey = useAi ? cacheKey + '|ai' : cacheKey + '|rule';
            if (!forceFetch && findingReportCache[cacheKey]) return findingReportCache[cacheKey];

            if (!forceFetch && inFlightPromises.has(inFlightKey)) {
                return inFlightPromises.get(inFlightKey);
            }

            warmupInFlight.add(cacheKey);
            const stemsSnapshot = disableRecommendationDiversity ? [] : Array.from(recommendationStemsSeen);

            const fetchPromise = (async () => {
                try {
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 35000);
                    const r = await fetch('../Backend/finding_ai_report.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        signal: controller.signal,
                        body: JSON.stringify({
                            vulnerability: vuln,
                            vulnerability_uid: vuln._sq_uid || '',
                            scan_run_id: currentScanRunId,
                            use_ai: useAi ? true : false,
                            bypass_cache: bypassAiCache ? true : false,
                            recommendation_stems_seen: stemsSnapshot,
                            scan_data: {
                                target: lastScanData?.target || '',
                                timestamp: lastScanData?.timestamp || '',
                                summary: lastScanData?.summary || {}
                            }
                        })
                    });
                    clearTimeout(timeoutId);
                    if (!r.ok) return null;
                    const data = await r.json();
                    const payload = (data && data.ok && data.report) ? {
                        report: data.report,
                        source: data.source || 'unknown',
                        quality: data.quality || null
                    } : null;
                    const stems = Array.isArray(payload?.report?.recommendation_stems) ? payload.report.recommendation_stems : [];
                    stems.forEach(s => recommendationStemsSeen.add(String(s || '')));
                    if (payload) trackFindingReportSourceEvent(vuln, payload.source, payload.quality);
                    if (payload) findingReportCache[cacheKey] = payload;
                    if (payload) syncClearCacheButton();
                    if (payload?.report) syncReadyButtonsFromCache();
                    return payload;
                } catch (e) {
                    return null;
                }
            })();

            inFlightPromises.set(inFlightKey, fetchPromise);
            return fetchPromise.finally(() => {
                warmupInFlight.delete(cacheKey);
                inFlightPromises.delete(inFlightKey);
            });
        }
        function markFindingButtonReady(vulnIndex, ok, source, vuln) {
            const uid = String(vuln?._sq_uid || '');
            let buttons = [];
            if (uid) {
                buttons = Array.from(document.querySelectorAll(`.view-finding-btn[data-vuln-uid="${uid.replace(/"/g, '\\"')}"]`));
            }
            if (!buttons.length) {
                const byIndex = document.querySelector(`.view-finding-btn[data-vuln-index="${vulnIndex}"]`);
                if (byIndex) buttons = [byIndex];
            }
            if (!buttons.length) return;
            buttons.forEach(btn => {
                btn.classList.remove('pending');
                btn.classList.add(ok ? 'ready' : 'fallback');
                const label = btn.querySelector('.btn-label');
                if (label) label.textContent = 'Open Report';
            });
            if (ok && uid && !readyReportToastShown.has(uid)) {
                readyReportToastShown.add(uid);
                const findingName = String(vuln?.name || buttons[0]?.getAttribute('data-vuln-name') || 'Security finding');
                const shortName = findingName.length > 44 ? findingName.slice(0, 43) + '…' : findingName;
                showToast('Report ready', `${shortName} is ready to open.`, 'success');
            }
        }
        function syncReadyButtonsFromCache() {
            const buttons = Array.from(document.querySelectorAll('.view-finding-btn'));
            if (!buttons.length) return;
            buttons.forEach(btn => {
                const idx = parseInt(btn.getAttribute('data-vuln-index') || '-1', 10);
                if (Number.isNaN(idx) || idx < 0) return;
                const vuln = (lastVulnerabilities || [])[idx];
                if (!vuln) return;
                const key = makeCacheKey(vuln);
                const hasReady = !!findingReportCache[key]?.report;
                if (!hasReady) return;
                btn.classList.remove('pending', 'fallback');
                btn.classList.add('ready');
                const label = btn.querySelector('.btn-label');
                if (label) label.textContent = 'Open Report';
            });
        }
        function stopReportReconcileWindow() {
            if (reportReconcileTimer) {
                clearInterval(reportReconcileTimer);
                reportReconcileTimer = null;
            }
            reportReconcileTicks = 0;
        }
        function startReportReconcileWindow() {
            stopReportReconcileWindow();
            reportReconcileTicks = 0;
            reportReconcileTimer = setInterval(function () {
                reportReconcileTicks += 1;
                syncReadyButtonsFromCache();
                syncClearCacheButton();
                if (reportReconcileTicks % 4 === 0) {
                    const pendingButtons = Array.from(document.querySelectorAll('.view-finding-btn.pending'));
                    pendingButtons.forEach(btn => {
                        const idx = parseInt(btn.getAttribute('data-vuln-index') || '-1', 10);
                        if (Number.isNaN(idx) || idx < 0) return;
                        const vuln = (lastVulnerabilities || [])[idx];
                        if (!vuln) return;
                        const key = makeCacheKey(vuln);
                        const ruleInFlightKey = key + '|rule';
                        const aiInFlightKey = key + '|ai';
                        if (findingReportCache[key]?.report || inFlightPromises.has(ruleInFlightKey) || inFlightPromises.has(aiInFlightKey) || manualRegenInFlight.has(key)) return;
                        const tries = autoRegenAttempts.get(key) || 0;
                        if (tries >= 3) return;
                        const now = Date.now();
                        const firstSeenAt = autoRegenFirstSeenAt.get(key) || now;
                        autoRegenFirstSeenAt.set(key, firstSeenAt);
                        // Wait a bit before the first auto retry to avoid hammering.
                        const AUTO_RETRY_FIRST_DELAY_MS = 8000;
                        if (now < firstSeenAt + AUTO_RETRY_FIRST_DELAY_MS) return;
                        const lastTry = autoRegenLastTryAt.get(key) || 0;
                        const RETRY_BASE_MS = 600;
                        const backoffMs = (RETRY_BASE_MS * Math.pow(2, tries)) + Math.random() * 500;
                        if ((now - lastTry) < backoffMs) return;
                        autoRegenLastTryAt.set(key, Date.now());
                        autoRegenAttempts.set(key, tries + 1);
                        fetchFindingReport(vuln, { forceFetch: true }).then(payload => {
                            if (payload?.report) {
                                markFindingButtonReady(idx, true, payload?.source, vuln);
                                autoRegenAttempts.delete(key);
                                autoRegenLastTryAt.delete(key);
                                autoRegenFirstSeenAt.delete(key);
                            }
                        }).catch(() => { });
                    });
                }
                const pendingCount = document.querySelectorAll('.view-finding-btn.pending').length;
                if (pendingCount === 0 || reportReconcileTicks >= 20) {
                    stopReportReconcileWindow();
                }
            }, 500);
        }

        function calcWarmupConcurrency(findingCount) {
            const MIN_WARMUP = 2;
            const MAX_WARMUP = 8;
            if (findingCount <= 4) return Math.max(MIN_WARMUP, Math.min(4, MAX_WARMUP));
            if (findingCount <= 10) return Math.max(MIN_WARMUP, Math.min(6, MAX_WARMUP));
            return MAX_WARMUP;
        }
        async function warmFindingReports() {
            if (!Array.isArray(lastVulnerabilities) || lastVulnerabilities.length === 0) return;
            const queue = sortByPriority(lastVulnerabilities.map((v, i) => ({ vuln: v, idx: i })));
            async function worker() {
                while (queue.length > 0) {
                    const item = queue.shift();
                    if (!item) return;
                    const { vuln, idx } = item;
                    try {
                        let payload = await fetchFindingReport(vuln);
                        if (!payload?.report) {
                            await new Promise(resolve => setTimeout(resolve, 700));
                            payload = await fetchFindingReport(vuln, { forceFetch: true });
                        }
                        if (payload?.report) markFindingButtonReady(idx, true, payload?.source, vuln);
                    } catch (e) {
                        // Keep pending state; explicit click can still force-fetch this report.
                    }
                }
            }
            const concurrency = calcWarmupConcurrency(queue.length);
            const workers = Array.from({ length: Math.min(concurrency, queue.length) }, () => worker());
            await Promise.allSettled(workers);
            syncReadyButtonsFromCache();
        }
        async function openFindingReportModal(vulnIndex) {
            const modal = document.getElementById('findingReportModal');
            const body = document.getElementById('findingReportBody');
            const loading = document.getElementById('findingReportLoading');
            if (!modal || !body) return;
            const vuln = (lastVulnerabilities || [])[vulnIndex];
            if (!vuln) return;
            window.currentFindingModalIndex = vulnIndex;
            activeFindingModalToken += 1;
            const modalToken = activeFindingModalToken;
            const cacheKey = makeCacheKey(vuln);
            const cached = findingReportCache[cacheKey];
            modal.style.display = 'flex';
            modal.classList.add('active');
            if (cached) {
                if (modalToken !== activeFindingModalToken) return;
                if (loading) loading.style.display = 'none';
                body.style.display = 'grid';
                setFindingModalContent(cached.report || {}, vuln, cached.source, cached.quality);
                return;
            }
            // Fix 4: render deterministic fallback immediately so the user
            // sees real content while the AI report is fetched.
            body.style.display = 'grid';
            if (loading) loading.style.display = 'none';
            const fallbackReport = {
                title: vuln.name || 'Security Finding',
                severity: vuln.severity || '-',
                category: inferFindingCategory(vuln),
                target: lastScanData?.target || '',
                detection_time: lastScanData?.timestamp || '',
                description: vuln.description || '-',
                evidence: vuln.evidence || '',
                risk_explanation: vuln.indicates || '-',
                likelihood: '-',
                remediation_priority: '-',
                result_status: 'Preparing...',
                ip_address: vuln.ip_address || '',
                port: vuln.port || '',
                service_detected: vuln.service_detected || '',
                state: vuln.state || ''
            };
            setFindingModalContent(fallbackReport, vuln, 'loading', null);
            try {
                let payload = await fetchFindingReport(vuln);
                if (!payload?.report) {
                    payload = await fetchFindingReport(vuln, { forceFetch: true });
                }
                if (modalToken !== activeFindingModalToken) return;
                const report = payload?.report || {};
                setFindingModalContent(report, vuln, payload?.source, payload?.quality);
                if (payload?.report) {
                    markFindingButtonReady(vulnIndex, true, payload?.source, vuln);
                } else {
                    markFindingButtonReady(vulnIndex, false, null, vuln);
                }
            } catch (e) {
                if (modalToken !== activeFindingModalToken) return;
                setFindingModalContent({}, vuln, 'fallback', null);
                markFindingButtonReady(vulnIndex, false, null, vuln);
            } finally {
                if (modalToken !== activeFindingModalToken) return;
                body.style.display = 'grid';
                if (loading) loading.style.display = 'none';
            }
        }
        function closeFindingReportModal() {
            const modal = document.getElementById('findingReportModal');
            if (!modal) return;
            activeFindingModalToken += 1;
            modal.classList.remove('active');
            modal.style.display = 'none';
        }
        function findingReportAsText() {
            const read = (id, label) => `${label}: ${(document.getElementById(id)?.textContent || '-').trim()}`;
            const impacts = Array.from(document.querySelectorAll('#findingImpactList li')).map(li => '- ' + li.textContent.trim()).join('\n');
            const recs = Array.from(document.querySelectorAll('#findingRecommendations li')).map(li => '- ' + li.textContent.trim()).join('\n');
            return [
                read('findingTitle', 'Title'),
                read('findingSeverity', 'Severity'),
                read('findingCategory', 'Category'),
                read('findingStatus', 'Result status'),
                read('findingTarget', 'Target'),
                read('findingDetectionTime', 'Detection time'),
                read('findingDescription', 'Description'),
                read('findingEvidence', 'Evidence'),
                read('findingRiskExplanation', 'What this evidence indicates'),
                read('findingLikelihood', 'Likelihood'),
                read('findingPriority', 'Remediation priority'),
                'Potential impact:',
                impacts || '- Not provided',
                'Recommended actions:',
                recs || '- Not provided'
            ].join('\n');
        }
        function printFindingReport() {
            const modalCard = document.querySelector('#findingReportModal .finding-report-modal');
            if (!modalCard || typeof html2canvas !== 'function') {
                showToast('Print failed', 'Modal snapshot engine is unavailable.', 'error');
                return;
            }
            const stage = document.createElement('div');
            stage.style.position = 'fixed';
            stage.style.left = '-10000px';
            stage.style.top = '0';
            stage.style.width = '1400px';
            stage.style.zIndex = '-1';
            stage.style.pointerEvents = 'none';
            stage.style.background = '#ffffff';
            const clone = modalCard.cloneNode(true);
            clone.style.maxHeight = 'none';
            clone.style.height = 'auto';
            clone.style.overflow = 'visible';
            clone.style.transform = 'none';
            clone.style.width = modalCard.getBoundingClientRect().width + 'px';
            const scrollables = clone.querySelectorAll('*');
            scrollables.forEach(function (el) {
                const cs = window.getComputedStyle(el);
                if (cs.overflowY === 'auto' || cs.overflowY === 'scroll' || cs.overflowX === 'auto' || cs.overflowX === 'scroll') {
                    el.style.overflow = 'visible';
                    el.style.maxHeight = 'none';
                    el.style.height = 'auto';
                }
            });
            stage.appendChild(clone);
            document.body.appendChild(stage);
            const shotWidth = Math.ceil(clone.scrollWidth || clone.getBoundingClientRect().width || 1200);
            const shotHeight = Math.ceil(clone.scrollHeight || clone.getBoundingClientRect().height || 1600);
            html2canvas(clone, {
                backgroundColor: '#ffffff',
                scale: 2,
                useCORS: true,
                width: shotWidth,
                height: shotHeight,
                windowWidth: shotWidth,
                windowHeight: shotHeight,
                scrollX: 0,
                scrollY: 0
            }).then(canvas => {
                stage.remove();
                const img = canvas.toDataURL('image/png');
                const html = `<html><head><title>Finding Report</title><style>html,body{margin:0;padding:0;background:#fff}img{display:block;max-width:100%;height:auto;margin:0 auto;page-break-inside:avoid;}</style></head><body><img src="${img}" alt="Finding report snapshot"></body></html>`;
                const frame = document.createElement('iframe');
                frame.style.position = 'fixed';
                frame.style.right = '0';
                frame.style.bottom = '0';
                frame.style.width = '0';
                frame.style.height = '0';
                frame.style.border = '0';
                document.body.appendChild(frame);
                const doc = frame.contentWindow?.document;
                if (!doc || !frame.contentWindow) {
                    showToast('Print failed', 'Could not initialize system print.', 'error');
                    frame.remove();
                    return;
                }
                doc.open();
                doc.write(html);
                doc.close();
                setTimeout(() => {
                    try {
                        frame.contentWindow.focus();
                        frame.contentWindow.print();
                    } catch (e) {
                        showToast('Print failed', 'System print could not be opened.', 'error');
                    } finally {
                        setTimeout(() => frame.remove(), 1200);
                    }
                }, 220);
            }).catch(() => {
                stage.remove();
                showToast('Print failed', 'Could not capture report snapshot.', 'error');
            });
        }
        function buildSectionDeepContent(section, ctx) {
            const report = ctx?.report || {};
            const vuln = ctx?.vuln || {};
            const title = report.title || vuln.name || 'Security finding';
            if (section === 'description') {
                return {
                    section: 'description',
                    title: 'Description',
                    content: `${report.description || vuln.description || '-'}\n\nTest performed:\n${vuln.what_we_tested || 'Not provided by scanner.'}`
                };
            }
            if (section === 'risk_explanation') {
                return {
                    section: 'risk_explanation',
                    title: 'What This Evidence Indicates',
                    content: `${report.risk_explanation || vuln.indicates || '-'}\n\nHow this can be exploited:\n${vuln.how_exploited || 'Not provided by scanner.'}`
                };
            }
            if (section === 'evidence') {
                return {
                    section: 'evidence',
                    title: 'Evidence',
                    content: `${report.evidence || vuln.evidence || '-'}`
                };
            }
            if (section === 'recommendations') {
                const recs = Array.isArray(report.recommendations) ? report.recommendations : [report.recommendations || vuln.remediation || '-'];
                return {
                    section: 'recommendations',
                    title: 'Recommended Actions',
                    content: `For: ${title}\n\n${recs.map((r, i) => `${i + 1}. ${r}`).join('\n')}`
                };
            }
            const impacts = Array.isArray(report.potential_impact) ? report.potential_impact : [report.potential_impact || '-'];
            return {
                section: 'potential_impact',
                title: 'Potential Business and Security Impact',
                content: `${impacts.map((r, i) => `${i + 1}. ${r}`).join('\n')}`
            };
        }
        function openFindingSectionModal(section) {
            const modal = document.getElementById('findingSectionModal');
            const titleEl = document.getElementById('findingSectionTitle');
            const bodyEl = document.getElementById('findingSectionContent');
            const iconEl = document.getElementById('findingSectionIcon');
            const headEl = document.getElementById('findingSectionHead');
            if (!modal || !titleEl || !bodyEl) return;
            const info = buildSectionDeepContent(section, window.currentFindingContext || {});
            titleEl.textContent = info.title;
            bodyEl.textContent = info.content;
            const cfg = {
                description: { icon: 'fa-align-left', cls: 'section-theme-description' },
                evidence: { icon: 'fa-microscope', cls: 'section-theme-evidence' },
                risk_explanation: { icon: 'fa-triangle-exclamation', cls: 'section-theme-risk' },
                recommendations: { icon: 'fa-list-check', cls: 'section-theme-actions' },
                potential_impact: { icon: 'fa-chart-line', cls: 'section-theme-impact' },
            }[info.section || section] || { icon: 'fa-file-lines', cls: 'section-theme-description' };
            if (iconEl) iconEl.className = `fas ${cfg.icon}`;
            if (headEl) headEl.className = `finding-section-head ${cfg.cls}`;
            modal.style.display = 'flex';
        }
        function closeFindingSectionModal() {
            const modal = document.getElementById('findingSectionModal');
            if (modal) modal.style.display = 'none';
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
            const rawEl = document.getElementById('evidenceRawPlain');
            const annEl = document.getElementById('evidenceAnnotated');
            if (titleEl) titleEl.textContent = (vuln.name || 'Vulnerability Evidence');
            const raw = normalizeEvidenceLineBreaks(String(vuln.evidence || '').trim());
            if (rawEl) rawEl.textContent = raw || '-';
            if (annEl) annEl.innerHTML = renderColoredEvidenceLines(raw);
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
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.view-finding-btn');
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            if (btn.classList.contains('pending')) {
                const findingName = String(btn.getAttribute('data-vuln-name') || 'This finding');
                const shortName = findingName.length > 44 ? findingName.slice(0, 43) + '…' : findingName;
                showToast('Preparing report', `${shortName} is still preparing. Please wait a moment.`, 'warning');
                return;
            }
            const idx = parseInt(btn.getAttribute('data-vuln-index') || '-1', 10);
            if (!Number.isNaN(idx) && idx >= 0) openFindingReportModal(idx);
        });
        function updateFindingMeta(total, shown) {
            const el = document.getElementById('findingsMeta');
            if (!el) return;
            el.textContent = `Showing ${shown} of ${total} finding(s)`;
        }
        function syncReportShellVisibility() {
            const sidebarReportNav = document.getElementById('sidebarReportNav');
            const hasRenderedReport = !!(lastScanData && lastScanData.target);
            if (sidebarReportNav) {
                sidebarReportNav.style.display = hasRenderedReport ? 'grid' : 'none';
                if (hasRenderedReport && shouldPulseSidebarReportNav) {
                    sidebarReportNav.classList.remove('sidebar-report-nav--pulse');
                    void sidebarReportNav.offsetWidth;
                    sidebarReportNav.classList.add('sidebar-report-nav--pulse');
                    shouldPulseSidebarReportNav = false;
                }
            }
            syncSidebarReportActions();
            if (!hasRenderedReport) {
                closeReportOverlay('userReportOverlay');
                closeReportOverlay('downloadsOverlay');
            }
        }
        function syncSidebarReportActions() {
            const sourceAiBtn = document.getElementById('aiOverviewBtn');
            const sideAiBtn = document.getElementById('sidebarAiOverviewBtn');
            const sideDocBtn = document.getElementById('sidebarDownloadDocBtn');
            const sideShareBtn = document.getElementById('sidebarShareBtn');
            const bodyDocBtn = document.getElementById('downloadDocBtn');
            const visible = !!(sourceAiBtn && sourceAiBtn.style.display !== 'none');
            const setStyleDisplay = (el, value) => {
                if (!el) return;
                if (el.style.display !== value) el.style.display = value;
            };
            const setDisabled = (el, disabled) => {
                if (!el) return;
                if (!!el.disabled !== !!disabled) el.disabled = !!disabled;
            };
            const setAttr = (el, name, value) => {
                if (!el) return;
                const next = String(value);
                if (el.getAttribute(name) !== next) el.setAttribute(name, next);
            };
            if (sideAiBtn) {
                setStyleDisplay(sideAiBtn, visible ? 'inline-flex' : 'none');
                if (sourceAiBtn) setDisabled(sideAiBtn, !!sourceAiBtn.disabled);
            }
            if (sideDocBtn) {
                const hasDoc = !!(lastScanId && lastDownloadUrls && lastDownloadUrls.doc);
                const docTitle = hasDoc
                    ? 'Download AI summary DOC file'
                    : 'DOC file appears after AI summary generation completes';
                setStyleDisplay(sideDocBtn, hasDoc ? 'flex' : 'none');
                setDisabled(sideDocBtn, !hasDoc);
                setAttr(sideDocBtn, 'title', docTitle);
                if (bodyDocBtn) {
                    setStyleDisplay(bodyDocBtn, 'inline-flex');
                    setDisabled(bodyDocBtn, false);
                    setAttr(bodyDocBtn, 'aria-disabled', hasDoc ? 'false' : 'true');
                    bodyDocBtn.classList.toggle('is-disabled', !hasDoc);
                    setAttr(bodyDocBtn, 'data-tip-title', hasDoc ? 'DOC report ready' : 'DOC report preparing');
                    setAttr(
                        bodyDocBtn,
                        'data-tip-text',
                        hasDoc
                            ? 'Download the AI summary as a Word document.'
                            : 'Document is still being prepared. Please wait a moment, then try again.'
                    );
                    setAttr(bodyDocBtn, 'title', hasDoc ? 'Download DOC' : '');
                }
            }
            if (sideShareBtn) {
                setDisabled(sideShareBtn, !(lastScanId || (lastScanData && lastScanData.target)));
            }
            if (document.getElementById('downloadsOverlay')?.style.display === 'flex') {
                syncReportOverlayContent();
            }
        }
        function inferFindingDomain(v) {
            const cat = String(v?.category || '').toLowerCase();
            const name = String(v?.name || '').toLowerCase();
            const desc = String(v?.description || '').toLowerCase();
            const blob = `${cat} ${name} ${desc}`;
            if (/port|service|open port|exposed service|network/.test(blob)) return 'Network Exposure';
            if (/tls|ssl|certificate|https|cipher|hsts/.test(blob)) return 'Transport Security';
            if (/header|csp|x-frame|x-content-type|permissions-policy|strict-transport-security/.test(blob)) return 'Header Hardening';
            if (/auth|session|token|cookie|login|csrf|idor/.test(blob)) return 'Auth & Session Controls';
            if (/sqli|sql injection|xss|xxe|rce|command|injection|path traversal|lfi|rfi/.test(blob)) return 'Input & Injection';
            if (/secret|exposed|backup|config|discoverable|directory|path discovery|sensitive file|leak/.test(blob)) return 'Content & Exposure';
            if (/crawl|discovery|sitemap|robots/.test(blob)) return 'Crawling & Surface Discovery';
            return 'General Security';
        }
        function inferFindingCategory(v) {
            const explicit = String(v?.category || '').trim();
            if (explicit) return explicit;
            const name = String(v?.name || '').toLowerCase();
            const desc = String(v?.description || '').toLowerCase();
            const blob = `${name} ${desc}`;
            if (/sql|sqli|xss|xxe|rce|command|injection|traversal|lfi|rfi/.test(blob)) return 'Injection & Input Validation';
            if (/cors|redirect/.test(blob)) return 'Access Control & Redirects';
            if (/header|csp|hsts|x-frame|x-content-type|permissions-policy/.test(blob)) return 'Security Headers';
            if (/tls|ssl|certificate|https|cipher|mixed content/.test(blob)) return 'Transport & TLS';
            if (/cookie|session|csrf|auth|token|login|idor/.test(blob)) return 'Session & Authentication';
            if (/secret|exposed|backup|config|\\.env|\\.git|sensitive file|leak/.test(blob)) return 'Sensitive Data Exposure';
            if (/port|service|open port|mongodb|redis|telnet|ftp|ssh/.test(blob)) return 'Network & Services';
            if (/server|x-powered-by|framework|version/.test(blob)) return 'Server Fingerprinting';
            if (/crawl|sitemap|robots|discovery/.test(blob)) return 'Discovery & Surface';
            return 'General Security';
        }
        function populateDomainFilter() {
            const el = document.getElementById('findingDomainFilter');
            if (!el) return;
            const values = Array.from(new Set((scanFindingsAll || []).map(v => inferFindingDomain(v)).filter(Boolean))).sort();
            el.innerHTML = '<option value="all">All test domains</option>' + values.map(v => `<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`).join('');
        }
        function populateCategoryFilter() {
            const el = document.getElementById('findingCategoryFilter');
            if (!el) return;
            const values = Array.from(new Set((scanFindingsAll || []).map(v => inferFindingCategory(v)).filter(Boolean))).sort();
            el.innerHTML = '<option value="all">All categories</option>' + values.map(v => `<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`).join('');
        }
        function populateSidebarResultGroupsMenu() {
            const sideMenu = document.getElementById('sidebarFindingsMenu');
            if (!sideMenu) return;
            const groups = Array.from(new Set((scanFindingsAll || []).map(v => inferFindingDomain(v)).filter(Boolean))).sort();
            const baseActions = `
                <button type="button" id="sidebarDetailedReportBtn" class="sidebar-popover-item"><i class="fas fa-list-check"></i><span>Detailed report</span></button>
                <button type="button" id="sidebarAiSummaryBtn" class="sidebar-popover-item"><i class="fas fa-file-lines"></i><span>AI summary</span></button>
            `;
            const groupItems = groups.length
                ? groups.map((groupName) => `<button type="button" class="sidebar-popover-item sidebar-popover-item--group" data-domain="${escapeHtml(groupName)}"><i class="fas fa-folder-open"></i><span>${escapeHtml(groupName)}</span></button>`).join('')
                : '<button type="button" class="sidebar-popover-item sidebar-popover-item--group" data-domain="all"><i class="fas fa-folder-open"></i><span>All groups</span></button>';
            const groupsClass = groups.length > 4
                ? 'sidebar-popover-groups sidebar-popover-groups--split'
                : 'sidebar-popover-groups';
            sideMenu.innerHTML = `${baseActions}<div class="sidebar-popover-divider"></div><div class="${groupsClass}">${groupItems}</div>`;
        }
        function applyFindingsFilters() {
            const listEl = document.getElementById('vulnerabilitiesList');
            if (!listEl) return;
            const search = (document.getElementById('findingSearchInput')?.value || '').trim().toLowerCase();
            const domain = (document.getElementById('findingDomainFilter')?.value || 'all');
            const severity = (document.getElementById('findingSeverityFilter')?.value || 'all').toLowerCase();
            const category = (document.getElementById('findingCategoryFilter')?.value || 'all');
            const sortBy = (document.getElementById('findingSortSelect')?.value || 'severity_desc');
            let rows = [...(scanFindingsAll || [])];
            rows = rows.filter(v => {
                if (domain !== 'all' && inferFindingDomain(v) !== domain) return false;
                if (severity !== 'all' && String(v.severity || '').toLowerCase() !== severity) return false;
                if (category !== 'all' && inferFindingCategory(v) !== category) return false;
                if (!search) return true;
                const blob = `${v.name || ''} ${v.description || ''} ${v.evidence || ''} ${v.remediation || ''}`.toLowerCase();
                return blob.includes(search);
            });
            rows.sort((a, b) => {
                if (sortBy === 'name_asc') return String(a.name || '').localeCompare(String(b.name || ''));
                const sa = severityRank[String(a.severity || '').toLowerCase()] ?? -1;
                const sb = severityRank[String(b.severity || '').toLowerCase()] ?? -1;
                return sortBy === 'severity_asc' ? sa - sb : sb - sa;
            });
            lastVulnerabilities = rows;
            if (rows.length === 0) {
                listEl.innerHTML = `<div class="empty-state"><i class="fas fa-filter"></i><h3>No matching findings</h3><p>Try changing search text or filters.</p></div>`;
                updateFindingMeta(scanFindingsAll.length, 0);
                populateSidebarResultGroupsMenu();
                return;
            }
            const groups = new Map();
            rows.forEach((v) => {
                const d = inferFindingDomain(v);
                if (!groups.has(d)) groups.set(d, []);
                groups.get(d).push(v);
            });
            const ordered = [];
            let runningIndex = 0;
            let groupedHtml = '';
            Array.from(groups.entries()).forEach(([groupName, items]) => {
                const highestRank = Math.max(...items.map((it) => severityRank[String(it.severity || '').toLowerCase()] ?? -1));
                const highest = Object.keys(severityRank).find((k) => severityRank[k] === highestRank) || 'info';
                groupedHtml += `
                    <section class="finding-domain-group" data-domain="${escapeHtml(groupName)}">
                        <header class="finding-domain-header">
                            <h4>${escapeHtml(groupName)}</h4>
                            <span class="finding-domain-meta">${items.length} finding(s) · highest ${escapeHtml(highest)}</span>
                        </header>
                        <div class="finding-domain-list">
                `;
                items.forEach((vuln) => {
                    ordered.push(vuln);
                    groupedHtml += createVulnerabilityCard(vuln, runningIndex++);
                });
                groupedHtml += '</div></section>';
            });
            lastVulnerabilities = ordered;
            listEl.innerHTML = groupedHtml;
            updateFindingMeta(scanFindingsAll.length, rows.length);
            populateSidebarResultGroupsMenu();
            warmFindingReports();
        }
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

        function buildTimelineCardsHtml(runs) {
            return (runs || []).map(function (run) {
                const dateStr = formatTimestamp(run.created_at);
                const delta = run.delta_vs_previous;
                let deltaHtml = '';
                if (delta !== null && delta !== undefined) {
                    const sign = delta > 0 ? '+' : '';
                    const cls = delta > 0 ? 'delta-worse' : (delta < 0 ? 'delta-better' : 'delta-flat');
                    const hint = delta > 0 ? 'Risk score increased vs previous saved run' : (delta < 0 ? 'Risk score decreased vs previous saved run' : 'Same risk score as previous saved run');
                    deltaHtml = '<span class="timeline-delta ' + cls + '" title="' + escapeHtml(hint) + '">' + sign + delta + ' pts</span>';
                } else {
                    deltaHtml = '<span class="timeline-delta delta-na" title="Oldest run in this list — nothing to compare">—</span>';
                }
                const changes = run.top_changes || [];
                let chHtml = '';
                if (changes.length) {
                    chHtml = '<ul class="timeline-changes">';
                    changes.forEach(function (c) {
                        const tag = c.kind === 'new' ? 'New' : 'Resolved';
                        const tagCls = c.kind === 'new' ? 'ch-new' : 'ch-resolved';
                        const sev = String(c.severity || '').toLowerCase().replace(/[^a-z]/g, '');
                        chHtml += '<li><span class="timeline-ch-tag ' + tagCls + '">' + tag + '</span> ';
                        chHtml += '<span class="timeline-sev sev-' + sev + '">' + escapeHtml(c.severity || '') + '</span> ';
                        chHtml += '<span class="timeline-ch-label">' + escapeHtml(c.label || '') + '</span></li>';
                    });
                    chHtml += '</ul>';
                } else if (delta !== null && delta !== undefined) {
                    chHtml = '<p class="timeline-no-changes">Same finding fingerprints as the previous run in this list — the score can still shift slightly from weighting or evidence updates.</p>';
                }
                const score = Number(run.risk_score || 0);
                const fc = Number(run.findings_count || 0);
                const lvl = String(run.risk_level || '').trim();
                const lvlSlug = (lvl ? lvl.toLowerCase().replace(/\s+/g, '-') : 'unknown');
                const band = String(run.risk_band || '').toLowerCase();
                const bandLabel = band === 'good' ? 'Good band' : (band === 'neutral' ? 'Neutral band' : (band === 'bad' ? 'High band' : ''));
                const bandHtml = band && bandLabel
                    ? '<span class="timeline-band-badge timeline-band-badge--' + escapeHtml(band) + '" title="0–30 Good · 31–60 Neutral · 61–100 High">' + escapeHtml(bandLabel) + '</span>'
                    : '';
                let durStr = '—';
                if (run.scan_duration != null && run.scan_duration !== '' && !Number.isNaN(Number(run.scan_duration))) {
                    const d = Number(run.scan_duration);
                    durStr = (Math.round(d * 10) / 10) + 's';
                }
                const sslG = run.ssl_grade != null && String(run.ssl_grade).trim() !== '' ? escapeHtml(String(run.ssl_grade)) : '—';
                const hdrPct = run.headers_score != null && run.headers_score !== '' ? escapeHtml(String(run.headers_score)) + '%' : '—';
                const bd = run.severity_breakdown || {};
                const sevParts = [];
                if (bd.critical) sevParts.push('<span class="timeline-sev-chip crit">' + bd.critical + ' C</span>');
                if (bd.high) sevParts.push('<span class="timeline-sev-chip high">' + bd.high + ' H</span>');
                if (bd.medium) sevParts.push('<span class="timeline-sev-chip med">' + bd.medium + ' M</span>');
                if (bd.low) sevParts.push('<span class="timeline-sev-chip low">' + bd.low + ' L</span>');
                if (bd.info) sevParts.push('<span class="timeline-sev-chip info">' + bd.info + ' I</span>');
                if (bd.secure) sevParts.push('<span class="timeline-sev-chip sec">' + bd.secure + ' OK</span>');
                let sevRow = '';
                if (sevParts.length) {
                    sevRow = '<div class="timeline-run-sevchips">' + sevParts.join('') + '</div>';
                } else {
                    sevRow = '<div class="timeline-run-sevchips timeline-run-sevchips--empty">No critical–low findings in this snapshot (informational or “secure” items may still appear in the full report).</div>';
                }
                const statsHtml = '<div class="timeline-run-stats">' +
                    '<div class="timeline-run-stat"><span class="timeline-run-stat-lbl">Scan time</span><span class="timeline-run-stat-val">' + durStr + '</span></div>' +
                    '<div class="timeline-run-stat"><span class="timeline-run-stat-lbl">SSL grade</span><span class="timeline-run-stat-val">' + sslG + '</span></div>' +
                    '<div class="timeline-run-stat"><span class="timeline-run-stat-lbl">Headers</span><span class="timeline-run-stat-val">' + hdrPct + '</span></div>' +
                    '</div>' + sevRow;
                return '<div class="timeline-run-card">' +
                    '<div class="timeline-run-top">' +
                    '<div class="timeline-run-scoreline">' +
                    '<span class="timeline-score">' + score + '</span><span class="timeline-score-lbl">/100</span>' +
                    '<span class="timeline-level lvl-' + escapeHtml(lvlSlug) + '">' + escapeHtml(lvl) + '</span>' +
                    bandHtml +
                    deltaHtml +
                    '</div>' +
                    '<div class="timeline-run-meta">' +
                    '<span><i class="fas fa-clock"></i> ' + escapeHtml(dateStr) + '</span>' +
                    '<span><i class="fas fa-bug"></i> ' + fc + ' findings</span>' +
                    '<span class="timeline-scan-id">#' + Number(run.scan_id || 0) + '</span>' +
                    '</div></div>' +
                    statsHtml +
                    chHtml + '</div>';
            }).join('');
        }

        function loadScanRunTimeline(targetUrl) {
            const u = (targetUrl && String(targetUrl).trim()) ? String(targetUrl).trim() : '';
            if (!u) {
                return Promise.resolve({ ok: false, error: 'no_target', runs: [] });
            }
            return fetch('../Backend/scan_run_timeline.php?target=' + encodeURIComponent(u) + '&limit=40', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (r) { return r.json(); }).then(function (res) {
                if (res && res.ok) {
                    sqTimelineRunsStore = res.runs || [];
                } else {
                    sqTimelineRunsStore = [];
                }
                return res || { ok: false, runs: [] };
            }).catch(function () {
                sqTimelineRunsStore = [];
                return { ok: false, error: 'network', runs: [] };
            });
        }

        function openScanTimelineFullModal() {
            const modal = document.getElementById('scanTimelineFullModal');
            const body = document.getElementById('scanTimelineFullBody');
            const sub = document.getElementById('scanTimelineFullSub');
            if (!modal || !body) return;
            body.innerHTML = buildTimelineCardsHtml(sqTimelineRunsStore);
            if (sub) sub.textContent = sqTimelineRunsStore.length + ' saved run(s) for this host';
            modal.style.display = 'flex';
            modal.classList.add('active');
        }
        function closeScanTimelineFullModal() {
            const modal = document.getElementById('scanTimelineFullModal');
            if (!modal) return;
            modal.classList.remove('active');
            modal.style.display = 'none';
        }

        function closeScanTimelinePreviewModal() {
            const modal = document.getElementById('scanTimelinePreviewModal');
            if (!modal) return;
            modal.classList.remove('active');
            modal.style.display = 'none';
        }

        function applyScanRunHistoryLink(targetUrl) {
            const hist = document.getElementById('scanRunTimelineHistoryLink');
            if (!hist) return;
            const u = (targetUrl && String(targetUrl).trim()) ? String(targetUrl).trim() : '';
            if (u) {
                hist.href = SQ_HISTORY_PAGE + '?target=' + encodeURIComponent(u);
            } else {
                hist.href = SQ_HISTORY_PAGE;
            }
        }

        function openScanTimelinePreviewModal(targetUrl) {
            const modal = document.getElementById('scanTimelinePreviewModal');
            const body = document.getElementById('scanTimelinePreviewBody');
            const sub = document.getElementById('scanTimelinePreviewSub');
            const empty = document.getElementById('scanTimelinePreviewEmpty');
            const actions = document.getElementById('scanTimelinePreviewActions');
            const viewFullBtn = document.getElementById('scanTimelinePreviewViewFullBtn');
            if (!modal || !body) return;
            const u = (targetUrl && String(targetUrl).trim()) ? String(targetUrl).trim() : '';
            modal.dataset.timelineTarget = u || '';
            const histBtn = document.getElementById('scanTimelinePreviewHistoryBtn');
            if (histBtn) {
                histBtn.href = u ? (SQ_HISTORY_PAGE + '?target=' + encodeURIComponent(u)) : SQ_HISTORY_PAGE;
            }
            modal.style.display = 'flex';
            modal.classList.add('active');
            if (empty) empty.style.display = 'none';
            if (actions) actions.style.display = 'none';
            if (!u) {
                body.innerHTML = '<p class="scan-run-timeline-error">Enter a target URL or run a scan first to load a timeline.</p>';
                if (sub) sub.textContent = 'No target selected';
                if (actions) actions.style.display = 'none';
                return;
            }
            body.innerHTML = '<p class="scan-run-timeline-loading"><i class="fas fa-circle-notch fa-spin"></i> Loading timeline…</p>';
            if (sub) sub.textContent = 'Loading…';
            loadScanRunTimeline(u).then(function (res) {
                applyScanRunHistoryLink(u);
                if (!res || !res.ok) {
                    body.innerHTML = '<p class="scan-run-timeline-error">Timeline unavailable (sign in required or server error).</p>';
                    if (sub) sub.textContent = 'Could not load timeline';
                    if (actions) actions.style.display = 'flex';
                    if (viewFullBtn) viewFullBtn.style.display = 'none';
                    return;
                }
                if (sub && res.canonical_target) {
                    try {
                        const h = new URL(res.canonical_target).hostname;
                        sub.textContent = 'Last ' + SQ_TIMELINE_INLINE_PREVIEW + ' saved runs for ' + h + ' — same host as the timeline API.';
                    } catch (e) {
                        sub.textContent = 'Saved runs for this target — deltas compare each run to the next older one.';
                    }
                }
                const runs = res.runs || [];
                if (runs.length === 0) {
                    body.innerHTML = '';
                    if (empty) empty.style.display = 'block';
                    if (actions) actions.style.display = 'flex';
                    if (viewFullBtn) viewFullBtn.style.display = 'none';
                    return;
                }
                if (empty) empty.style.display = 'none';
                body.innerHTML = buildTimelineCardsHtml(runs.slice(0, SQ_TIMELINE_INLINE_PREVIEW));
                if (actions) actions.style.display = 'flex';
                if (viewFullBtn) viewFullBtn.style.display = 'inline-flex';
            });
        }

        function refreshScanRunTimeline(targetUrl) {
            const wrap = document.getElementById('scanRunTimelineWrap');
            const body = document.getElementById('scanRunTimelineBody');
            const empty = document.getElementById('scanRunTimelineEmpty');
            const sub = document.getElementById('scanRunTimelineSub');
            const viewAll = document.getElementById('scanRunTimelineViewAll');
            if (!wrap || !body) return;
            if (!targetUrl) {
                wrap.style.display = 'none';
                applyScanRunHistoryLink('');
                return;
            }
            wrap.style.display = 'block';
            body.innerHTML = '<p class="scan-run-timeline-loading"><i class="fas fa-circle-notch fa-spin"></i> Loading timeline…</p>';
            if (empty) empty.style.display = 'none';
            if (viewAll) viewAll.style.display = 'none';
            applyScanRunHistoryLink(targetUrl);
            loadScanRunTimeline(targetUrl).then(function (res) {
                if (!res || !res.ok) {
                    body.innerHTML = '<p class="scan-run-timeline-error">Timeline unavailable (sign in required or server error).</p>';
                    return;
                }
                if (sub && res.canonical_target) {
                    try {
                        const h = new URL(res.canonical_target).hostname;
                        sub.textContent = 'Recent saved scans for ' + h + ' — preview below; open full timeline for the complete list.';
                    } catch (e) {
                        sub.textContent = 'Saved scans for this target — compare risk score movement and what changed between runs.';
                    }
                }
                const runs = res.runs || [];
                if (runs.length === 0) {
                    body.innerHTML = '';
                    if (empty) empty.style.display = 'block';
                    return;
                }
                if (empty) empty.style.display = 'none';
                const preview = runs.slice(0, SQ_TIMELINE_INLINE_PREVIEW);
                body.innerHTML = buildTimelineCardsHtml(preview);
                if (viewAll) viewAll.style.display = runs.length > SQ_TIMELINE_INLINE_PREVIEW ? 'block' : 'none';
            }).catch(function () {
                body.innerHTML = '<p class="scan-run-timeline-error">Could not load timeline.</p>';
            });
        }

        // Replace your scanBtn event listener with this:

        function setScanProgress(stageText, pct) {
            const stageEl = document.getElementById('scanStageLabel');
            const barEl = document.getElementById('scanProgressBar');
            if (stageEl) stageEl.textContent = stageText || 'Scanning...';
            if (barEl) barEl.style.width = Math.max(0, Math.min(100, Number(pct || 0))) + '%';
        }
        function stopSyntheticScanProgress() {
            if (scanSyntheticTimer) clearInterval(scanSyntheticTimer);
            if (scanSyntheticDelayTimer) clearTimeout(scanSyntheticDelayTimer);
            scanSyntheticTimer = null;
            scanSyntheticDelayTimer = null;
            scanSyntheticStartMs = 0;
        }
        function getSyntheticScanStage(elapsedMs) {
            const stages = [
                [0, 12, 'Validating target and initializing scanner modules…'],
                [1400, 15, 'Connecting to target website URL…'],
                [4200, 24, 'Checking SSL/TLS configuration…'],
                [9000, 34, 'Validating security headers…'],
                [15000, 48, 'Testing injection paths and misconfigurations…'],
                [24000, 72, 'Scanning open ports and services…'],
                [36000, 88, 'Finalizing scan results…']
            ];
            let picked = stages[0];
            for (let i = 0; i < stages.length; i++) {
                if (elapsedMs >= stages[i][0]) picked = stages[i];
            }
            return { pct: picked[1], stage: picked[2] };
        }
        function applyMergedScanProgress() {
            const b = lastBackendProgress;
            const backendStage = (b.stage || '').trim();
            const backendStatus = (b.status || '').trim();
            const backendMeaningful = backendStage && backendStage !== 'Waiting for scan' && backendStatus !== 'unknown';
            if (!scanSyntheticStartMs) {
                if (backendMeaningful || Number(b.progress || 0) > 0) {
                    setScanProgress(backendStage || 'Scanning...', Math.max(0, Number(b.progress || 0)));
                }
                return;
            }
            const elapsed = Date.now() - scanSyntheticStartMs;
            const syn = getSyntheticScanStage(elapsed);
            let pct = syn.pct;
            let label = syn.stage;
            if (backendMeaningful) {
                pct = Math.max(syn.pct, Number(b.progress || 0));
                label = backendStage;
            } else if (Number(b.progress || 0) > syn.pct) {
                pct = Number(b.progress || 0);
                if (backendStage) label = backendStage;
            }
            setScanProgress(label, pct);
        }
        function startSyntheticScanProgressDelayed() {
            stopSyntheticScanProgress();
            lastBackendProgress = { progress: 0, stage: '', status: 'unknown' };
            scanSyntheticDelayTimer = setTimeout(function () {
                scanSyntheticStartMs = Date.now();
                applyMergedScanProgress();
                scanSyntheticTimer = setInterval(function () {
                    applyMergedScanProgress();
                }, 450);
            }, 1800);
        }
        function stopScanProgressTimer() {
            if (scanProgressTimer) clearInterval(scanProgressTimer);
            scanProgressTimer = null;
        }
        function stopScanStagePolling() {
            if (scanStagePollTimer) clearInterval(scanStagePollTimer);
            scanStagePollTimer = null;
            stopSyntheticScanProgress();
        }
        function pollScanStageOnce(stageToken, requestToken) {
            return fetch('../Backend/scan_progress.php?token=' + encodeURIComponent(stageToken), {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).then(function (p) {
                if (!p || requestToken !== activeScanToken) return;
                lastBackendProgress = {
                    progress: Number(p.progress || 0),
                    stage: (p.stage || '').trim(),
                    status: (p.status || '').trim()
                };
                applyMergedScanProgress();
                if (p.status === 'completed' || p.status === 'failed') {
                    stopScanStagePolling();
                }
            }).catch(function () { });
        }
        function startScanStagePolling(stageToken, requestToken) {
            stopScanStagePolling();
            startSyntheticScanProgressDelayed();
            pollScanStageOnce(stageToken, requestToken);
            scanStagePollTimer = setInterval(function () {
                if (requestToken !== activeScanToken) {
                    stopScanStagePolling();
                    return;
                }
                pollScanStageOnce(stageToken, requestToken);
            }, 700);
        }
        function runScan() {
            var inp = document.getElementById('targetURL');
            var btn = document.getElementById('scanBtn');
            var cancelBtn = document.getElementById('cancelScanBtn');
            var loaderEl = document.getElementById('loader');
            var resultsEl = document.getElementById('results');
            var errMsgEl = document.getElementById('errorMessage');
            var errTextEl = document.getElementById('errorText');
            var elapsedEl = document.getElementById('scanElapsed');
            var url = (inp && inp.value) ? inp.value.trim() : '';

            if (!url || (url.indexOf('http://') !== 0 && url.indexOf('https://') !== 0)) {
                if (errTextEl) errTextEl.textContent = 'Please enter a valid URL starting with http:// or https://';
                if (errMsgEl) errMsgEl.classList.add('active');
                return;
            }

            if (errMsgEl) errMsgEl.classList.remove('active');
            if (btn) btn.disabled = true;
            if (cancelBtn) cancelBtn.style.display = 'inline-flex';
            if (loaderEl) loaderEl.classList.add('active');
            if (resultsEl) resultsEl.classList.remove('active');
            syncReportShellVisibility();
            setScanProgress('Validating target and initializing scanner modules...', 12);
            stopScanProgressTimer();
            const startMs = Date.now();
            scanProgressTimer = setInterval(() => {
                if (elapsedEl) elapsedEl.textContent = Math.floor((Date.now() - startMs) / 1000) + 's';
            }, 350);

            if (activeScanController) {
                try { activeScanController.abort(); } catch (e) { }
            }
            activeScanController = new AbortController();
            activeScanToken += 1;
            const scanToken = activeScanToken;
            const stageToken = `scan-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
            startScanStagePolling(stageToken, scanToken);

            var scanApiUrl = '../Backend/scan_proxy.php';
            console.log('runScan: sending POST to', scanApiUrl, 'target=', url);
            fetch(scanApiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ target: url, scan_token: stageToken }),
                signal: activeScanController.signal
            }).then(function (response) {
                if (scanToken !== activeScanToken) {
                    throw new Error('Scan superseded by newer request.');
                }
                return response.text().then(function (text) {
                    try {
                        return { ok: response.ok, data: JSON.parse(text), status: response.status };
                    } catch (e) {
                        return { ok: false, data: null, status: response.status, raw: text };
                    }
                });
            }).then(function (result) {
                if (scanToken !== activeScanToken) {
                    throw new Error('Scan superseded by newer request.');
                }
                if (!result || !result.ok) {
                    stopScanStagePolling();
                } else {
                    setScanProgress('Running active tests and collecting findings...', 56);
                }
                if (!result.ok) {
                    let errMsg = result.data && result.data.error ? result.data.error : ('Server error ' + result.status);
                    if (result.raw) errMsg = result.raw.slice(0, 300);
                    throw new Error(errMsg);
                }
                const data = result.data;

                // Check for scan-level errors
                if (data.error) {
                    stopScanStagePolling();
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

                lastScanData = data;
                shouldPulseSidebarReportNav = true;
                try { refreshScanRunTimeline(data.target); } catch (e) { }

                // Update vulnerabilities list
                if (data.vulnerabilities && data.vulnerabilities.length > 0) {
                    const stamp = Date.now();
                    currentScanRunId = `${stamp}`;
                    recommendationStemsSeen = new Set();
                    stopReportReconcileWindow();
                    autoRegenAttempts.clear();
                    autoRegenLastTryAt.clear();
                    autoRegenFirstSeenAt.clear();
                    Object.keys(findingReportCache).forEach(k => delete findingReportCache[k]);
                    warmupInFlight.clear();
                    readyReportToastShown.clear();
                    reportSourceTelemetrySent.clear();
                    scanFindingsAll = data.vulnerabilities.map((v, idx) => ({ ...v, _sq_uid: `${stamp}-${idx}` }));
                    populateCategoryFilter();
                    populateDomainFilter();
                    applyFindingsFilters();
                    startReportReconcileWindow();
                } else {
                    const vulnsList = document.getElementById('vulnerabilitiesList');
                    scanFindingsAll = [];
                    lastVulnerabilities = [];
                    vulnsList.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-shield-alt"></i>
                    <h3>No Vulnerabilities Found</h3>
                    <p>The target appears to be secure. No issues were detected during the scan.</p>
                </div>
            `;
                    updateFindingMeta(0, 0);
                }
                persistScanState();
                if (loaderEl) loaderEl.classList.remove('active');
                if (resultsEl) resultsEl.classList.add('active');
                syncReportShellVisibility();
                if (cancelBtn) cancelBtn.style.display = 'none';
                setScanProgress('Scan completed. Building detailed reports...', 100);
                stopScanProgressTimer();
                stopScanStagePolling();

                // Save scan and generate human-readable report (GPT); store artefacts for download
                const humanReportPanel = document.getElementById('humanReportPanel');
                let humanReportTimerInterval = null;
                let humanReportTimerStart = Date.now();
                function startHumanReportTimer() {
                    if (!humanReportPanel) return;
                    try {
                        humanReportTimerStart = Date.now();
                        humanReportPanel.innerHTML = '<p class="human-report-placeholder">Generating AI summary... <span id="humanReportWaitTimer">0s</span></p>';
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
                    if (res.download && res.download.doc) {
                        showToast('DOC ready', 'Word report generated and ready for download/share.', 'success');
                    }
                    syncSidebarReportActions();
                    persistScanState();
                    try { if (lastScanData && lastScanData.target) refreshScanRunTimeline(lastScanData.target); } catch (e) { }

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
                var cancelBtn2 = document.getElementById('cancelScanBtn');
                var errTextEl2 = document.getElementById('errorText');
                var errMsgEl2 = document.getElementById('errorMessage');
                if (loaderEl2) loaderEl2.classList.remove('active');
                if (cancelBtn2) cancelBtn2.style.display = 'none';
                stopScanProgressTimer();
                stopScanStagePolling();
                stopReportReconcileWindow();
                if (String(err && err.name) === 'AbortError') {
                    if (errTextEl2) errTextEl2.textContent = 'Scan cancelled.';
                    if (errMsgEl2) errMsgEl2.classList.add('active');
                    if (btn2) btn2.disabled = false;
                    return;
                }
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
            var cancelBtn = document.getElementById('cancelScanBtn');
            var inp = document.getElementById('targetURL');
            if (btn && inp) {
                btn.addEventListener('click', runScan);
                inp.addEventListener('keypress', function (e) { if (e.key === 'Enter') { e.preventDefault(); runScan(); } });
            }
            if (cancelBtn) {
                cancelBtn.addEventListener('click', function () {
                    if (activeScanController) {
                        try { activeScanController.abort(); } catch (e) { }
                    }
                });
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initScanButton);
        } else {
            initScanButton();
        }

        (function initTimelineRefresh() {
            const btn = document.getElementById('scanRunTimelineRefresh');
            if (btn) {
                btn.addEventListener('click', function () {
                    const inp = document.getElementById('targetURL');
                    const u = (inp && inp.value) ? inp.value.trim() : '';
                    if (u) refreshScanRunTimeline(u);
                    else if (typeof lastScanData !== 'undefined' && lastScanData && lastScanData.target) refreshScanRunTimeline(lastScanData.target);
                });
            }
            document.getElementById('scanRunTimelineViewAllBtn')?.addEventListener('click', function () {
                openScanTimelineFullModal();
            });
            document.getElementById('scanTimelinePreviewClose')?.addEventListener('click', closeScanTimelinePreviewModal);
        })();

        // Help button popup
        document.getElementById('helpBtn').addEventListener('click', () => {
            alert('Enter a target URL to scan for:\n\n• SQL Injection (error-based + blind time-based)\n• Cross-Site Scripting (reflected + DOM XSS sinks)\n• CORS misconfiguration\n• Open redirect vulnerabilities\n• Exposed sensitive files (.env, .git, backups, admin panels)\n• Missing security headers (CSP, HSTS, X-Frame-Options…)\n• SSL/TLS certificate and cipher issues\n• Mixed content (HTTP resources on HTTPS pages)\n• Exposed secrets (AWS keys, Stripe keys, passwords)\n• Cookie security flags (Secure, HttpOnly, SameSite)\n• Open ports and exposed services (Redis, MongoDB, Telnet…)\n\nEnsure you have permission to scan the target.');
        });

        // Floating scroll controls
        (function () {
            const topBtn = document.getElementById('backToTopBtn');
            const bottomBtn = document.getElementById('scrollToBottomBtn');
            const resultsEl = document.getElementById('results');
            if (!topBtn || !bottomBtn) return;
            const show = (el, on) => { el.style.display = on ? 'inline-flex' : 'none'; };
            const hasLoadedReport = () => !!(resultsEl && resultsEl.classList.contains('active'));
            const nearBottom = () => {
                const doc = document.documentElement;
                return (window.scrollY + window.innerHeight) >= (doc.scrollHeight - 24);
            };
            function sync() {
                if (!hasLoadedReport()) {
                    show(topBtn, false);
                    show(bottomBtn, false);
                    return;
                }
                show(topBtn, window.scrollY > 400);
                show(bottomBtn, !nearBottom());
            }
            window.addEventListener('scroll', sync, { passive: true });
            window.addEventListener('resize', sync);
            sync();
            topBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
            bottomBtn.addEventListener('click', () => {
                window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'smooth' });
            });
            if (resultsEl && window.MutationObserver) {
                const mo = new MutationObserver(sync);
                mo.observe(resultsEl, { attributes: true, attributeFilter: ['class'] });
            }
        })();

        // Report generation helpers
        function deriveKeySignal(vuln) {
            const clean = (value) => String(value || '')
                .replace(/\s+/g, ' ')
                .trim()
                .replace(/[.;,\s]+$/g, '');
            const tested = clean(vuln?.what_we_tested);
            const indicates = clean(vuln?.indicates);
            let sentence = '';
            if (tested && indicates) {
                sentence = `Testing ${tested} indicates ${indicates}.`;
            } else if (indicates) {
                sentence = `The observed signal indicates ${indicates}.`;
            } else if (tested) {
                sentence = `Testing focused on ${tested}.`;
            } else {
                sentence = 'Scanner identified a security-relevant signal requiring review.';
            }
            if (sentence.length > 220) sentence = `${sentence.slice(0, 217).trimEnd()}...`;
            return sentence;
        }

        function buildCsvReport(data) {
            const lines = [];
            lines.push(`Target,${data.target}`);
            lines.push(`Scanned At,${formatTimestamp(data.timestamp)}`);
            lines.push(`Scan Duration (s),${data.scan_duration}`);
            lines.push(`Risk Level,${data.summary.risk_level}`);
            lines.push(`Risk Score,${data.summary.risk_score}`);
            lines.push('');
            lines.push('Severity,Name,Description,Key Indicator,Remediation');

            (data.vulnerabilities || []).forEach(v => {
                const row = [
                    v.severity || '',
                    (v.name || '').replace(/"/g, '""'),
                    (v.description || '').replace(/"/g, '""'),
                    deriveKeySignal(v).replace(/"/g, '""'),
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
            const vulns = Array.isArray(data.vulnerabilities) ? data.vulnerabilities : [];
            const safeRisk = String(summary.risk_level || 'Secure').toLowerCase();
            const riskClass = String(summary.risk_level || 'Secure').toLowerCase().replace(/[^a-z]/g, '');

            const sevCount = { critical: 0, high: 0, medium: 0, low: 0, info: 0, secure: 0 };
            vulns.forEach(v => {
                const k = String(v?.severity || '').toLowerCase();
                if (Object.prototype.hasOwnProperty.call(sevCount, k)) sevCount[k] += 1;
            });

            const listOrDash = (items) => {
                if (!Array.isArray(items) || items.length === 0) return '<li>No item provided.</li>';
                return items.map(x => `<li>${escapeHtml(String(x || ''))}</li>`).join('');
            };

            const findingCardsHtml = vulns.map((v, idx) => {
                const sev = String(v.severity || 'info').toLowerCase();
                return `
                <article class="finding-card">
                    <div class="finding-head">
                        <div class="finding-title-wrap">
                            <span class="finding-index">#${idx + 1}</span>
                            <h3 class="finding-title">${escapeHtml(v.name || 'Security Finding')}</h3>
                        </div>
                        <span class="severity-badge severity-${sev}">${escapeHtml(v.severity || 'Info')}</span>
                    </div>
                    <div class="finding-grid">
                        <div><div class="label">Description</div><div>${escapeHtml(v.description || '-')}</div></div>
                        <div><div class="label">What We Tested</div><div>${escapeHtml(v.what_we_tested || '-')}</div></div>
                        <div><div class="label">What This Indicates</div><div>${escapeHtml(v.indicates || '-')}</div></div>
                        <div><div class="label">Potential Exploitation</div><div>${escapeHtml(v.how_exploited || '-')}</div></div>
                    </div>
                    <div class="finding-block">
                        <div class="label">Key Indicator</div>
                        <div>${escapeHtml(deriveKeySignal(v))}</div>
                    </div>
                    <div class="finding-block">
                        <div class="label">Recommended Remediation</div>
                        <div>${escapeHtml(v.remediation || '-')}</div>
                    </div>
                </article>`;
            }).join('');

            const actionsHtml = (friendly.priority_actions || []).map(a => `<li>${escapeHtml(String(a || ''))}</li>`).join('');

            return `
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>ScanQuotient Security Report</title>
    <style>
        :root { --ink:#0f172a; --muted:#475569; --line:#e2e8f0; --surface:#ffffff; --bg:#f8fafc; --brand:#3b82f6; }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--ink); font-family: "Inter", "Segoe UI", Arial, sans-serif; line-height:1.45; }
        .wrap { max-width: 1100px; margin: 22px auto; padding: 0 16px 28px; }
        .sheet { background:var(--surface); border:1px solid var(--line); border-radius:16px; box-shadow:0 16px 34px rgba(15,23,42,.08); overflow:hidden; }
        .cover { padding:24px 26px; background: linear-gradient(140deg,#e0e7ff 0%, #eef2ff 45%, #ffffff 100%); border-bottom:1px solid var(--line); }
        .brand { display:inline-flex; gap:8px; align-items:center; font-size:12px; font-weight:700; color:#3730a3; background:rgba(79,70,229,.12); border:1px solid rgba(99,102,241,.25); padding:6px 10px; border-radius:999px; }
        .title { margin:12px 0 8px; font-size:30px; line-height:1.15; }
        .subtitle { margin:0; color:#334155; font-size:14px; }
        .meta { margin-top:8px; color:#334155; font-size:13px; }
        .risk-chip { margin-top:12px; display:inline-block; padding:8px 12px; border-radius:999px; font-weight:700; font-size:12px; }
        .risk-chip.secure, .risk-chip.low { background:#dcfce7; color:#166534; }
        .risk-chip.medium { background:#fef3c7; color:#92400e; }
        .risk-chip.high, .risk-chip.critical { background:#fee2e2; color:#991b1b; }
        .risk-chip.info { background:#dcfce7; color:#166534; }
        .section { padding:18px 26px; border-top:1px solid var(--line); }
        h2 { margin:0 0 12px; font-size:18px; }
        .meta-grid { display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap:10px; }
        .kpi { border:1px solid var(--line); border-radius:12px; background:#fff; padding:12px; }
        .kpi .k { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.4px; margin-bottom:6px; font-weight:700; }
        .kpi .v { font-size:18px; font-weight:800; color:#0f172a; }
        .kpi .s { font-size:12px; color:#64748b; margin-top:4px; }
        .sev-row { display:grid; grid-template-columns: repeat(6, minmax(0,1fr)); gap:8px; margin-top:10px; }
        .sev { border-radius:10px; border:1px solid var(--line); padding:8px; text-align:center; font-size:12px; }
        .sev strong { display:block; font-size:16px; margin-top:2px; }
        .sev.critical { background:#fef2f2; color:#991b1b; }
        .sev.high { background:#fff7ed; color:#9a3412; }
        .sev.medium { background:#fffbeb; color:#92400e; }
        .sev.low { background:#eff6ff; color:#1d4ed8; }
        .sev.info { background:#f8fafc; color:#334155; }
        .sev.secure { background:#f0fdf4; color:#166534; }
        .two-col { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .panel { border:1px solid var(--line); border-radius:12px; padding:12px; background:#fff; }
        .panel h3 { margin:0 0 8px; font-size:14px; }
        .pill { display:inline-block; border:1px solid var(--line); border-radius:999px; padding:4px 9px; margin:2px 6px 0 0; font-size:11px; color:#334155; background:#fff; }
        ul { margin:8px 0 0 18px; padding:0; }
        .finding-list { display:grid; gap:12px; }
        .finding-card { border:1px solid var(--line); border-radius:14px; padding:14px; background:#fff; }
        .finding-head { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; margin-bottom:10px; }
        .finding-title-wrap { display:flex; gap:10px; align-items:flex-start; }
        .finding-index { display:inline-block; min-width:28px; text-align:center; padding:4px 8px; font-size:12px; border-radius:999px; background:#eff6ff; color:#1d4ed8; font-weight:700; }
        .finding-title { margin:0; font-size:16px; }
        .severity-badge { font-size:11px; font-weight:800; padding:5px 9px; border-radius:999px; }
        .severity-critical { background:#fee2e2; color:#991b1b; }
        .severity-high { background:#ffedd5; color:#9a3412; }
        .severity-medium { background:#fef3c7; color:#92400e; }
        .severity-low { background:#dbeafe; color:#1d4ed8; }
        .severity-info, .severity-secure { background:#dcfce7; color:#166534; }
        .finding-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px; font-size:13px; color:#1e293b; }
        .label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.3px; font-weight:700; margin-bottom:4px; }
        .finding-block { margin-top:8px; font-size:13px; color:#1e293b; }
        pre { margin:0; white-space:pre-wrap; word-break:break-word; background:#f8fafc; border:1px solid var(--line); border-radius:10px; padding:10px; font-size:12px; max-height:240px; overflow:auto; }
        .footer-note { color:#64748b; font-size:11px; padding:12px 28px 18px; }
        @media print {
            body { background:#fff; }
            .wrap { max-width:none; margin:0; padding:0; }
            .sheet { border:none; box-shadow:none; border-radius:0; }
            .cover, .section { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="wrap">
      <div class="sheet">
        <section class="cover">
            <div class="brand">ScanQuotient <span>Web Security Assessment</span></div>
            <h1 class="title">Security Assessment Report</h1>
            <p class="subtitle">Prepared for <strong>${escapeHtml(data.target || '-')}</strong></p>
            <div class="meta"><strong>Scanned:</strong> ${escapeHtml(formatTimestamp(data.timestamp) || '-')} &nbsp; <strong>Duration:</strong> ${escapeHtml(String(data.scan_duration || 0))}s</div>
            <div class="risk-chip ${riskClass}">${escapeHtml(summary.risk_level || 'Secure')} Risk • Score ${escapeHtml(String(summary.risk_score || 0))}</div>
        </section>

        <section class="section">
            <h2>Scan Overview</h2>
            <div class="meta-grid">
                <div class="kpi"><div class="k">Target</div><div class="v">${escapeHtml(data.target || '-')}</div></div>
                <div class="kpi"><div class="k">HTTPS Enabled</div><div class="v">${ssl.https ? 'Yes' : 'No'}</div></div>
                <div class="kpi"><div class="k">TLS Grade</div><div class="v">${escapeHtml(ssl.grade || 'N/A')}</div></div>
                <div class="kpi"><div class="k">Header Score</div><div class="v">${escapeHtml(String(headers.score || 0))}%</div></div>
            </div>
            <div class="sev-row">
                <div class="sev critical">Critical<strong>${sevCount.critical}</strong></div>
                <div class="sev high">High<strong>${sevCount.high}</strong></div>
                <div class="sev medium">Medium<strong>${sevCount.medium}</strong></div>
                <div class="sev low">Low<strong>${sevCount.low}</strong></div>
                <div class="sev info">Info<strong>${sevCount.info}</strong></div>
                <div class="sev secure">Secure<strong>${sevCount.secure}</strong></div>
            </div>
        </section>

        <section class="section">
            <h2>Executive Summary</h2>
            <p>${escapeHtml(friendly.message || 'This report summarizes identified web security findings, associated risk signals, and recommended remediation actions.')}</p>
            ${actionsHtml ? `<div class="panel"><h3>Priority Actions</h3><ul>${actionsHtml}</ul></div>` : ''}
        </section>

        <section class="section">
            <h2>Security Posture Details</h2>
            <div class="two-col">
                <div class="panel">
                    <h3>TLS / HTTPS</h3>
                    <div><strong>HTTPS enabled:</strong> ${ssl.https ? 'Yes' : 'No'}</div>
                    <div><strong>TLS grade:</strong> ${escapeHtml(ssl.grade || 'N/A')}</div>
                </div>
                <div class="panel">
                    <h3>Security Headers</h3>
                    <div><strong>Header score:</strong> ${escapeHtml(String(headers.score || 0))}%</div>
                    <div style="margin-top:8px;"><strong>Present:</strong> ${presentNames.length ? presentNames.map(h => `<span class="pill">${escapeHtml(h)}</span>`).join('') : '<span class="pill">None</span>'}</div>
                    <div style="margin-top:6px;"><strong>Missing:</strong> ${missingNames.length ? missingNames.map(h => `<span class="pill">${escapeHtml(h)}</span>`).join('') : '<span class="pill">None</span>'}</div>
                </div>
            </div>
        </section>

        <section class="section">
            <h2>Detailed Findings</h2>
            <div class="finding-list">
                ${findingCardsHtml || '<div class="panel">No findings were detected for this scan.</div>'}
            </div>
        </section>

        <div class="footer-note">ScanQuotient • Quantifying Risk. Strengthening Security. • Generated report artefact</div>
      </div>
    </div>
</body>
</html>
`;
        }

        function buildPdfReport(data) {
            const summary = data.summary || {};
            const friendly = summary.user_friendly || {};
            const headers = data.headers || {};
            const ssl = data.ssl || {};
            const vulns = Array.isArray(data.vulnerabilities) ? data.vulnerabilities : [];
            const presentNames = headers.present_names || [];
            const missingNames = headers.missing_names || [];
            const safeRisk = String(summary.risk_level || 'Secure').toLowerCase();
            const riskScore = Number(summary.risk_score || 0);

            const sevCount = { critical: 0, high: 0, medium: 0, low: 0, info: 0, secure: 0 };
            vulns.forEach(v => {
                const s = String(v?.severity || '').toLowerCase();
                if (Object.prototype.hasOwnProperty.call(sevCount, s)) sevCount[s] += 1;
            });

            const actions = Array.isArray(friendly.priority_actions) ? friendly.priority_actions : [];
            const actionsHtml = actions.map(a => `<li>${escapeHtml(String(a || ''))}</li>`).join('');

            const findingsHtml = vulns.map((v, i) => {
                const sev = String(v.severity || 'info').toLowerCase();
                const assessmentSignal = deriveKeySignal(v);
                return `
                <section class="finding">
                    <div class="finding-head">
                        <div class="finding-title"><span class="idx">#${i + 1}</span> ${escapeHtml(v.name || 'Security Finding')}</div>
                        <span class="sev sev-${sev}">${escapeHtml(v.severity || 'Info')}</span>
                    </div>
                    <div class="grid">
                        <div><div class="k">Description</div><div>${escapeHtml(v.description || '-')}</div></div>
                        <div><div class="k">What We Tested</div><div>${escapeHtml(v.what_we_tested || '-')}</div></div>
                        <div><div class="k">What This Indicates</div><div>${escapeHtml(v.indicates || '-')}</div></div>
                        <div><div class="k">How It Can Be Exploited</div><div>${escapeHtml(v.how_exploited || '-')}</div></div>
                    </div>
                    <div class="block">
                        <div class="k">Assessment Signal</div>
                        <div>${escapeHtml(assessmentSignal)}</div>
                    </div>
                    <div class="block">
                        <div class="k">Recommended Remediation</div>
                        <div>${escapeHtml(v.remediation || '-')}</div>
                    </div>
                </section>`;
            }).join('');

            return `
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>ScanQuotient PDF Report</title>
    <style>
        @page { size: A4; margin: 16mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #0f172a; font-family: "Inter", "Segoe UI", Arial, sans-serif; font-size: 12px; line-height: 1.45; background: #fff; }
        .doc { width: 100%; }
        .cover { border: 1px solid #bfdbfe; border-radius: 14px; padding: 16px; background: linear-gradient(145deg, #eef4ff 0%, #f8fbff 48%, #ffffff 100%); box-shadow: 0 10px 24px rgba(30, 64, 175, 0.08); }
        .brand { display: inline-block; font-weight: 800; color: #1d4ed8; font-size: 12px; letter-spacing: .2px; background: rgba(59,130,246,.12); border: 1px solid rgba(59,130,246,.25); border-radius: 999px; padding: 4px 10px; }
        h1 { margin: 8px 0 4px; font-size: 24px; line-height: 1.2; }
        .sub { color: #475569; margin-bottom: 8px; }
        .risk { display: inline-block; border-radius: 999px; padding: 4px 10px; font-weight: 700; font-size: 11px; }
        .risk.secure, .risk.low { background: #dcfce7; color: #166534; }
        .risk.medium { background: #fef3c7; color: #92400e; }
        .risk.high, .risk.critical { background: #fee2e2; color: #991b1b; }
        .row { margin-top: 10px; display: grid; grid-template-columns: repeat(5, minmax(0,1fr)); gap: 8px; }
        .card { border: 1px solid #dbe3ef; border-radius: 10px; padding: 8px; background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%); }
        .card .k { color: #64748b; text-transform: uppercase; letter-spacing: .35px; font-size: 10px; font-weight: 700; }
        .card .v { margin-top: 4px; font-size: 14px; font-weight: 800; color: #0f172a; }
        .section { margin-top: 14px; }
        .section h2 { margin: 0 0 10px; font-size: 15px; color: #0f172a; background: linear-gradient(90deg, #dbeafe 0%, #eff6ff 70%, transparent 100%); border-left: 4px solid #2563eb; border-radius: 8px; padding: 7px 10px; }
        .twocol { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .panel { border: 1px solid #dbe3ef; border-radius: 10px; padding: 10px; background: #fcfdff; }
        .panel h3 { margin: 0 0 8px; font-size: 12px; color: #1e3a8a; }
        .sev-table { width: 100%; border-collapse: collapse; }
        .sev-table th, .sev-table td { border: 1px solid #e2e8f0; padding: 6px; text-align: center; font-size: 11px; }
        .sev-table th { background: #eff6ff; color: #1e3a8a; font-weight: 800; }
        .chips { margin-top: 4px; }
        .chip { display: inline-block; margin: 2px 4px 0 0; padding: 2px 8px; border: 1px solid #dbe3ef; border-radius: 999px; font-size: 10px; color: #334155; }
        .finding { border: 1px solid #dbe3ef; border-radius: 12px; padding: 10px; margin-bottom: 10px; break-inside: avoid; page-break-inside: avoid; background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%); }
        .finding-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 8px; }
        .finding-title { font-size: 13px; font-weight: 800; color: #0f172a; }
        .idx { color: #1d4ed8; }
        .sev { border-radius: 999px; padding: 3px 9px; font-size: 10px; font-weight: 800; }
        .sev-critical { background: #fee2e2; color: #991b1b; }
        .sev-high { background: #ffedd5; color: #9a3412; }
        .sev-medium { background: #fef3c7; color: #92400e; }
        .sev-low { background: #dbeafe; color: #1d4ed8; }
        .sev-info, .sev-secure { background: #dcfce7; color: #166534; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .k { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: .35px; font-weight: 700; margin-bottom: 3px; }
        .block { margin-top: 8px; border: 1px solid #dbeafe; border-radius: 8px; padding: 8px; background: #f8fbff; }
        ul { margin: 6px 0 0 18px; padding: 0; }
        .footer { margin-top: 10px; font-size: 10px; color: #475569; text-align: right; border-top: 1px solid #dbeafe; padding-top: 6px; }
    </style>
</head>
<body>
    <div class="doc">
        <section class="cover">
            <div class="brand">ScanQuotient • Web Security Assessment</div>
            <h1>Security Assessment Report</h1>
            <div class="sub">Target: <strong>${escapeHtml(data.target || '-')}</strong> • Scanned: ${escapeHtml(formatTimestamp(data.timestamp) || '-')}</div>
            <span class="risk ${safeRisk}">${escapeHtml(summary.risk_level || 'Secure')} Risk (Score: ${escapeHtml(String(riskScore))})</span>
            <div class="row">
                <div class="card"><div class="k">Target</div><div class="v">${escapeHtml(data.target || '-')}</div></div>
                <div class="card"><div class="k">Duration</div><div class="v">${escapeHtml(String(data.scan_duration || 0))}s</div></div>
                <div class="card"><div class="k">Findings</div><div class="v">${vulns.length}</div></div>
                <div class="card"><div class="k">HTTPS</div><div class="v">${ssl.https ? 'Yes' : 'No'}</div></div>
                <div class="card"><div class="k">TLS Grade</div><div class="v">${escapeHtml(ssl.grade || 'N/A')}</div></div>
            </div>
        </section>

        <section class="section">
            <h2>Executive Summary</h2>
            <div>${escapeHtml(friendly.message || 'This report summarizes identified web security findings and recommended corrective actions.')}</div>
            ${actionsHtml ? `<div class="panel" style="margin-top:8px;"><h3>Priority Actions</h3><ul>${actionsHtml}</ul></div>` : ''}
        </section>

        <section class="section">
            <h2>Risk Distribution and Security Posture</h2>
            <div class="twocol">
                <div class="panel">
                    <h3>Severity Distribution</h3>
                    <table class="sev-table">
                        <tr><th>Critical</th><th>High</th><th>Medium</th><th>Low</th><th>Info</th><th>Secure</th></tr>
                        <tr><td>${sevCount.critical}</td><td>${sevCount.high}</td><td>${sevCount.medium}</td><td>${sevCount.low}</td><td>${sevCount.info}</td><td>${sevCount.secure}</td></tr>
                    </table>
                </div>
                <div class="panel">
                    <h3>Header Hygiene</h3>
                    <div><strong>Score:</strong> ${escapeHtml(String(headers.score || 0))}%</div>
                    <div class="chips"><strong>Present:</strong> ${presentNames.length ? presentNames.map(h => `<span class="chip">${escapeHtml(h)}</span>`).join('') : '<span class="chip">None</span>'}</div>
                    <div class="chips"><strong>Missing:</strong> ${missingNames.length ? missingNames.map(h => `<span class="chip">${escapeHtml(h)}</span>`).join('') : '<span class="chip">None</span>'}</div>
                </div>
            </div>
        </section>

        <section class="section">
            <h2>Detailed Findings</h2>
            ${findingsHtml || '<div class="panel">No findings detected.</div>'}
        </section>

        <div class="footer">ScanQuotient • Quantifying Risk. Strengthening Security.</div>
    </div>
</body>
</html>`;
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

        async function generateAndUploadPdf(scanId, scanData, renderScale) {
            let tmp = null;
            try {
                if (!window.jspdf || !window.jspdf.jsPDF || typeof html2canvas !== 'function') return null;
                const { jsPDF } = window.jspdf;
                const baseScale = Math.max(2, Math.min(3, Number(window.devicePixelRatio || 1)));
                const scale = (typeof renderScale === 'number' && renderScale > 0) ? renderScale : baseScale;

                tmp = document.createElement('div');
                tmp.style.position = 'absolute';
                tmp.style.left = '-10000px';
                tmp.style.top = '0';
                tmp.style.width = '794px';
                tmp.style.background = '#ffffff';
                tmp.style.opacity = '1';
                tmp.style.pointerEvents = 'none';
                tmp.style.zIndex = '0';
                const html = buildPdfReport(scanData);
                const parsed = new DOMParser().parseFromString(html, 'text/html');
                const inlineStyles = parsed.head ? Array.from(parsed.head.querySelectorAll('style')).map(s => s.textContent || '').join('\n') : '';
                tmp.innerHTML = `${inlineStyles ? `<style>${inlineStyles}</style>` : ''}${parsed.body ? parsed.body.innerHTML : html}`;
                document.body.appendChild(tmp);

                await new Promise(resolve => requestAnimationFrame(() => setTimeout(resolve, 120)));

                const canvas = await html2canvas(tmp, {
                    scale: scale,
                    useCORS: true,
                    backgroundColor: '#ffffff',
                    imageTimeout: 15000
                });
                if (!canvas || canvas.width < 10 || canvas.height < 10) return null;

                const doc = new jsPDF({ unit: 'pt', format: 'a4', compress: false, precision: 16 });
                const pageWidth = doc.internal.pageSize.getWidth();
                const pageHeight = doc.internal.pageSize.getHeight();
                const margin = 20;
                const contentWidth = pageWidth - margin * 2;
                const contentHeight = pageHeight - margin * 2;
                const slicePxHeight = Math.max(1, Math.floor((contentHeight * canvas.width) / contentWidth));

                let srcY = 0;
                let pageIndex = 0;
                while (srcY < canvas.height) {
                    const currentSlicePx = Math.min(slicePxHeight, canvas.height - srcY);
                    const sliceCanvas = document.createElement('canvas');
                    sliceCanvas.width = canvas.width;
                    sliceCanvas.height = currentSlicePx;
                    const ctx = sliceCanvas.getContext('2d');
                    if (!ctx) break;
                    ctx.imageSmoothingEnabled = true;
                    if ('imageSmoothingQuality' in ctx) {
                        ctx.imageSmoothingQuality = 'high';
                    }
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, sliceCanvas.width, sliceCanvas.height);
                    ctx.drawImage(canvas, 0, srcY, canvas.width, currentSlicePx, 0, 0, canvas.width, currentSlicePx);
                    const imgData = sliceCanvas.toDataURL('image/png');
                    const renderedHeight = (currentSlicePx * contentWidth) / canvas.width;
                    if (pageIndex > 0) doc.addPage();
                    doc.addImage(imgData, 'PNG', margin, margin, contentWidth, renderedHeight);
                    srcY += currentSlicePx;
                    pageIndex += 1;
                }

                const ab = doc.output('arraybuffer');
                if (!ab || ab.byteLength < 8000) return null;
                const dataUri = doc.output('datauristring');
                // If the PDF becomes too large, reduce gently to preserve readability.
                if (dataUri && dataUri.length > 6500000 && scale > 1.35) {
                    return await generateAndUploadPdf(scanId, scanData, scale - 0.35);
                }

                const r = await fetch('../Backend/upload_pdf.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ scan_id: scanId, pdf_base64: dataUri })
                });
                let res = null;
                try { res = await r.json(); } catch (e) { return null; }
                if (res && res.ok && res.download) return res.download;
            } catch (e) { }
            finally {
                if (tmp && tmp.parentNode) tmp.parentNode.removeChild(tmp);
            }
            return null;
        }

        let lastScanData = null;
        let lastScanId = null;
        let lastDownloadUrls = {};
        let lastHumanReportText = '';
        let userPackage = 'freemium';
        let shouldPulseSidebarReportNav = false;
        const scanStateStorageKey = 'sq_scan_state_v1';
        function persistScanState() {
            try {
                sessionStorage.setItem(scanStateStorageKey, JSON.stringify({
                    savedAt: Date.now(),
                    lastScanData: lastScanData,
                    lastScanId: lastScanId,
                    lastDownloadUrls: lastDownloadUrls,
                    lastHumanReportText: lastHumanReportText,
                    scanFindingsAll: scanFindingsAll
                }));
            } catch (e) { }
        }
        function restoreScanState() {
            try {
                const raw = sessionStorage.getItem(scanStateStorageKey);
                if (!raw) return;
                const st = JSON.parse(raw);
                if (!st || !st.lastScanData || !st.lastScanData.target) return;
                lastScanData = st.lastScanData;
                lastScanId = st.lastScanId || null;
                lastDownloadUrls = st.lastDownloadUrls || {};
                lastHumanReportText = st.lastHumanReportText || '';
                currentScanRunId = String(lastScanData?.scan_id || lastScanData?.timestamp || st.savedAt || Date.now());
                scanFindingsAll = Array.isArray(st.scanFindingsAll)
                    ? st.scanFindingsAll
                    : (Array.isArray(lastScanData.vulnerabilities) ? lastScanData.vulnerabilities : []);
                const restoreStamp = String(st.savedAt || Date.now());
                scanFindingsAll = scanFindingsAll.map((v, idx) => {
                    if (v && v._sq_uid) return v;
                    return { ...v, _sq_uid: `${restoreStamp}-${idx}` };
                });
                try {
                    const tb = document.getElementById('targetBadge');
                    if (tb) tb.textContent = new URL(lastScanData.target).hostname;
                } catch (e) {
                    const tb = document.getElementById('targetBadge');
                    if (tb) tb.textContent = String(lastScanData.target || '-');
                }
                const dur = document.getElementById('scanDuration');
                const ts = document.getElementById('scanTimestamp');
                if (dur) dur.textContent = (lastScanData.scan_duration || 0) + 's';
                if (ts) ts.textContent = formatTimestamp(lastScanData.timestamp);
                updateRiskDisplay(lastScanData.summary || {});
                updateUserFriendlySummary(lastScanData.summary || {});
                updateSSLDisplay(lastScanData.ssl || {});
                updateHeadersDisplay(lastScanData.headers || {});
                updateServerDisplay(lastScanData.server_info || {});
                updateCrawlerDisplay(lastScanData.crawler || {});
                populateCategoryFilter();
                populateDomainFilter();
                applyFindingsFilters();
                const loaderEl = document.getElementById('loader');
                const resultsEl = document.getElementById('results');
                if (loaderEl) loaderEl.classList.remove('active');
                if (resultsEl) resultsEl.classList.add('active');
                syncReportShellVisibility();
                if (lastHumanReportText) {
                    const panel = document.getElementById('humanReportPanel');
                    if (panel) panel.innerHTML = '<div class="human-report-content">' + renderStyledHumanReport(lastHumanReportText) + '</div>';
                }
            } catch (e) { }
        }

        fetch('../Backend/get_user_package.php').then(r => r.json()).then(data => {
            userPackage = (data.package || 'freemium').toLowerCase();
            const aiBtn = document.getElementById('aiOverviewBtn');
            const upgradeMsg = document.getElementById('upgradeMessage');
            if (aiBtn) aiBtn.style.display = userPackage === 'enterprise' ? 'inline-flex' : 'none';
            syncSidebarReportActions();
            if (upgradeMsg && userPackage !== 'enterprise') {
                upgradeMsg.style.display = 'block';
                upgradeMsg.textContent = 'Upgrade to Enterprise for AI Overview and detailed reports.';
            }
        }).catch(() => { });
        restoreScanState();

        function openReportOverlay(id) {
            const overlay = document.getElementById(id);
            if (!overlay) return;
            overlay.style.display = 'flex';
            overlay.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }
        function closeReportOverlay(id) {
            const overlay = document.getElementById(id);
            if (!overlay) return;
            overlay.style.display = 'none';
            overlay.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }
        (function initReportOverlayDismiss() {
            document.querySelectorAll('.report-overlay').forEach((overlay) => {
                const id = overlay.id;
                if (!id) return;
                overlay.addEventListener('click', (e) => {
                    const isCloseButton = !!e.target.closest('[data-close-overlay]');
                    const isBackdrop = !!e.target.closest('.report-overlay__backdrop');
                    if (!isCloseButton && !isBackdrop) return;
                    closeReportOverlay(id);
                });
            });
        })();
        function syncReportOverlayContent() {
            const reportBody = document.getElementById('userReportOverlayBody');
            const panel = document.getElementById('humanReportPanel');
            if (reportBody && panel) {
                reportBody.innerHTML = panel.innerHTML || '<p class="human-report-placeholder">Run a scan first to generate the AI summary.</p>';
            }
            const actions = document.getElementById('downloadsOverlayActions');
            if (actions) {
                const ids = ['downloadDocBtn', 'downloadCsvBtn', 'downloadPdfBtn', 'downloadHtmlBtn', 'shareResultsBtn', 'aiOverviewBtn'];
                const controls = ids.map((id) => document.getElementById(id)).filter(Boolean);
                actions.innerHTML = controls.map((el) => el.outerHTML).join('');
                actions.querySelectorAll('button').forEach((clonedBtn) => {
                    const sourceId = clonedBtn.id;
                    const sourceBtn = sourceId ? document.getElementById(sourceId) : null;
                    if (!sourceBtn) return;
                    if (sourceId) {
                        clonedBtn.setAttribute('data-source-id', sourceId);
                        clonedBtn.id = sourceId + '__overlay';
                    }
                    clonedBtn.disabled = sourceBtn.disabled;
                    clonedBtn.style.display = sourceBtn.style.display;
                    clonedBtn.setAttribute('aria-disabled', sourceBtn.getAttribute('aria-disabled') || 'false');
                    clonedBtn.className = sourceBtn.className;
                    clonedBtn.title = sourceBtn.title || '';
                    clonedBtn.addEventListener('click', () => sourceBtn.click());
                });
            }
        }
        (function initSidebarReportNav() {
            const riskBtn = document.getElementById('sidebarRiskAnalysisBtn');
            const runtimeBtn = document.getElementById('sidebarRuntimeBtn');
            const findingsBtn = document.getElementById('sidebarFindingsBtn');
            const findingsMenu = document.getElementById('sidebarFindingsMenu');
            const csvBtnSide = document.getElementById('sidebarDownloadCsvBtn');
            const htmlBtnSide = document.getElementById('sidebarDownloadHtmlBtn');
            const pdfBtnSide = document.getElementById('sidebarDownloadPdfBtn');
            const docBtnSide = document.getElementById('sidebarDownloadDocBtn');
            const shareBtnSide = document.getElementById('sidebarShareBtn');
            const aiOverviewSideBtn = document.getElementById('sidebarAiOverviewBtn');
            const popovers = Array.from(document.querySelectorAll('.sidebar-report-popover'));

            function closeAllPopovers(except = null) {
                popovers.forEach((popover) => {
                    if (except && popover === except) return;
                    popover.classList.remove('is-open');
                });
            }
            popovers.forEach((popover) => {
                const trigger = popover.querySelector('.sidebar-report-btn');
                const menu = popover.querySelector('.sidebar-report-popover-menu');
                trigger?.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const willOpen = !popover.classList.contains('is-open');
                    closeAllPopovers(popover);
                    if (willOpen) popover.classList.add('is-open');
                    else popover.classList.remove('is-open');
                });
                menu?.addEventListener('click', function (e) {
                    e.stopPropagation();
                });
            });
            document.addEventListener('click', function () {
                closeAllPopovers();
            });

            riskBtn?.addEventListener('click', function () {
                document.getElementById('riskScoreWrapper')?.click();
            });
            runtimeBtn?.addEventListener('click', function () {
                closeAllPopovers();
                const inp = document.getElementById('targetURL');
                const u = (typeof lastScanData !== 'undefined' && lastScanData && lastScanData.target)
                    ? String(lastScanData.target).trim()
                    : ((inp && inp.value) ? String(inp.value).trim() : '');
                openScanTimelinePreviewModal(u || '');
            });
            findingsBtn?.addEventListener('click', function () {
                const firstDomain = document.querySelector('.finding-domain-group[data-domain]');
                const firstGroup = firstDomain ? (firstDomain.getAttribute('data-domain') || 'all') : 'all';
                const domainFilter = document.getElementById('findingDomainFilter');
                const catFilter = document.getElementById('findingCategoryFilter');
                if (domainFilter) domainFilter.value = firstGroup;
                if (catFilter) catFilter.value = 'all';
                document.querySelector('[data-tab="findings"]')?.click();
                applyFindingsFilters();
                document.getElementById('reportFindingsSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                closeAllPopovers();
            });
            findingsMenu?.addEventListener('click', function (e) {
                const item = e.target.closest('.sidebar-popover-item');
                if (!item) return;
                if (item.id === 'sidebarDetailedReportBtn') {
                    const domainFilter = document.getElementById('findingDomainFilter');
                    const catFilter = document.getElementById('findingCategoryFilter');
                    if (domainFilter) domainFilter.value = 'all';
                    if (catFilter) catFilter.value = 'all';
                    applyFindingsFilters();
                    document.querySelector('[data-tab="findings"]')?.click();
                    document.getElementById('reportFindingsSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    closeAllPopovers();
                    return;
                }
                if (item.id === 'sidebarAiSummaryBtn') {
                    syncReportOverlayContent();
                    openReportOverlay('userReportOverlay');
                    closeAllPopovers();
                    return;
                }
                const domain = item.getAttribute('data-domain');
                if (domain !== null) {
                    const domainFilter = document.getElementById('findingDomainFilter');
                    const catFilter = document.getElementById('findingCategoryFilter');
                    if (domainFilter) domainFilter.value = domain;
                    if (catFilter) catFilter.value = 'all';
                    document.querySelector('[data-tab="findings"]')?.click();
                    applyFindingsFilters();
                    const section = document.querySelector(`.finding-domain-group[data-domain="${CSS.escape(domain)}"]`);
                    (section || document.getElementById('reportFindingsSection'))?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    closeAllPopovers();
                }
            });
            csvBtnSide?.addEventListener('click', function () {
                document.getElementById('downloadCsvBtn')?.click();
                closeAllPopovers();
            });
            htmlBtnSide?.addEventListener('click', function () {
                document.getElementById('downloadHtmlBtn')?.click();
                closeAllPopovers();
            });
            pdfBtnSide?.addEventListener('click', function () {
                document.getElementById('downloadPdfBtn')?.click();
                closeAllPopovers();
            });
            docBtnSide?.addEventListener('click', function () {
                if (!lastScanId || !lastDownloadUrls || !lastDownloadUrls.doc) {
                    closeAllPopovers();
                    return;
                }
                const docUrl = lastDownloadUrls.doc.startsWith('http') ? lastDownloadUrls.doc : (downloadBase + lastDownloadUrls.doc);
                window.open(docUrl, '_blank');
                closeAllPopovers();
            });
            shareBtnSide?.addEventListener('click', function () {
                document.getElementById('shareResultsBtn')?.click();
                closeAllPopovers();
            });
            aiOverviewSideBtn?.addEventListener('click', function () {
                document.getElementById('aiOverviewBtn')?.click();
                closeAllPopovers();
            });
        })();


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
        const docBtn = document.getElementById('downloadDocBtn');

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
        if (docBtn) {
            docBtn.addEventListener('click', () => {
                if (!lastScanId || !lastDownloadUrls || !lastDownloadUrls.doc) {
                    return;
                }
                const docUrl = lastDownloadUrls.doc.startsWith('http') ? lastDownloadUrls.doc : (downloadBase + lastDownloadUrls.doc);
                window.open(docUrl, '_blank');
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
                syncSidebarReportActions();
                fetch('../Backend/save_scan_report.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ scan_data: lastScanData, detailed_ai: true })
                }).then(r => r.json()).then(res => {
                    aiOverviewBtn.disabled = false;
                    syncSidebarReportActions();
                    if (res.scan_id) {
                        window.location.href = 'enterprise_ai_overview.php?scan_id=' + res.scan_id;
                    } else if (res.report_text) {
                        const escaped = res.report_text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
                        document.getElementById('humanReportPanel').innerHTML = '<div class="human-report-content"><h3>AI Overview (Enterprise)</h3>' + escaped + '</div>';
                        document.querySelector('[data-tab="report"]')?.click();
                    } else {
                        alert('Save the scan first to open the full Enterprise AI Overview, or review the report tab.');
                    }
                }).catch(() => { aiOverviewBtn.disabled = false; syncSidebarReportActions(); });
            });
        }
        const shareResultsBtn = document.getElementById('shareResultsBtn');
        if (shareResultsBtn) {
            shareResultsBtn.addEventListener('click', () => {
                if (!lastScanId) {
                    showToast('Share', 'Run and save a scan first, then share it by email.', 'error');
                    return;
                }
                document.getElementById('shareScanId').value = String(lastScanId);
                const pdf = document.getElementById('shareArtefactsPdf');
                const doc = document.getElementById('shareArtefactsDoc');
                const html = document.getElementById('shareArtefactsHtml');
                const csv = document.getElementById('shareArtefactsCsv');
                if (pdf) pdf.checked = true;
                if (doc) doc.checked = !!(lastDownloadUrls && lastDownloadUrls.doc);
                if (html) html.checked = true;
                if (csv) csv.checked = true;
                syncShareAllCheckbox();
                openShareModal();
            });
        }
        function getShareModal() {
            return document.getElementById('shareModal');
        }
        function openShareModal() {
            const shareModal = getShareModal();
            if (shareModal) {
                shareModal.classList.add('active');
                shareModal.style.display = 'flex';
                ensureShareEmailRows();
            }
        }
        function closeShareModal() {
            const shareModal = getShareModal();
            if (shareModal) { shareModal.classList.remove('active'); shareModal.style.display = 'none'; }
        }
        document.getElementById('shareModalClose')?.addEventListener('click', closeShareModal);
        document.getElementById('notificationsBtn')?.addEventListener('click', function () {
            openNotificationsModal();
        });
        document.addEventListener('click', function (e) {
            const shareModal = getShareModal();
            if (!shareModal) return;
            if (e.target === shareModal) closeShareModal();
        });
        function syncShareAllCheckbox() {
            const all = document.getElementById('shareArtefactsAll');
            const pdf = document.getElementById('shareArtefactsPdf');
            const doc = document.getElementById('shareArtefactsDoc');
            const html = document.getElementById('shareArtefactsHtml');
            const csv = document.getElementById('shareArtefactsCsv');
            if (!all || !pdf || !doc || !html || !csv) return;
            all.checked = !!(pdf.checked && doc.checked && html.checked && csv.checked);
        }
        document.getElementById('shareArtefactsAll')?.addEventListener('change', (e) => {
            const v = !!e.target.checked;
            const pdf = document.getElementById('shareArtefactsPdf');
            const doc = document.getElementById('shareArtefactsDoc');
            const html = document.getElementById('shareArtefactsHtml');
            const csv = document.getElementById('shareArtefactsCsv');
            if (pdf) pdf.checked = v;
            if (doc) doc.checked = v;
            if (html) html.checked = v;
            if (csv) csv.checked = v;
        });
        ['shareArtefactsPdf', 'shareArtefactsDoc', 'shareArtefactsHtml', 'shareArtefactsCsv'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', syncShareAllCheckbox);
        });

        function createShareEmailRow(value) {
            const row = document.createElement('div');
            row.className = 'share-email-row';
            const input = document.createElement('input');
            input.type = 'email';
            input.className = 'share-email-input';
            input.placeholder = 'email@example.com';
            input.value = value || '';
            input.autocomplete = 'email';
            const addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'share-email-btn share-email-btn--add';
            addBtn.title = 'Add another email';
            addBtn.textContent = '+';
            const rmBtn = document.createElement('button');
            rmBtn.type = 'button';
            rmBtn.className = 'share-email-btn share-email-btn--remove';
            rmBtn.title = 'Remove this email';
            rmBtn.textContent = '-';
            row.appendChild(input);
            row.appendChild(addBtn);
            row.appendChild(rmBtn);
            return row;
        }

        function ensureShareEmailRows(seedList) {
            const wrap = document.getElementById('shareEmailsWrap');
            if (!wrap) return;
            const seeds = Array.isArray(seedList) ? seedList.filter(Boolean) : [];
            if (wrap.children.length === 0) {
                const initial = seeds.length ? seeds : [''];
                initial.forEach(v => wrap.appendChild(createShareEmailRow(v)));
            }
            updateShareEmailRowButtons();
            const first = wrap.querySelector('.share-email-input');
            if (first && !first.value) first.focus();
        }

        function updateShareEmailRowButtons() {
            const wrap = document.getElementById('shareEmailsWrap');
            if (!wrap) return;
            const rows = Array.from(wrap.querySelectorAll('.share-email-row'));
            rows.forEach((row, idx) => {
                const rm = row.querySelector('.share-email-btn--remove');
                if (rm) {
                    rm.disabled = rows.length <= 1;
                    rm.style.display = (idx === 0) ? 'none' : 'inline-flex';
                }
                const add = row.querySelector('.share-email-btn--add');
                if (add) add.style.display = (idx === rows.length - 1) ? 'inline-flex' : 'none';
            });
        }

        function collectShareRecipients() {
            const wrap = document.getElementById('shareEmailsWrap');
            if (!wrap) return [];
            return Array.from(wrap.querySelectorAll('.share-email-input'))
                .map(el => String(el.value || '').trim())
                .filter(Boolean);
        }

        document.addEventListener('click', function (e) {
            const t = e.target;
            if (!(t instanceof Element)) return;
            const row = t.closest('.share-email-row');
            const wrap = document.getElementById('shareEmailsWrap');
            if (!row || !wrap || !wrap.contains(row)) return;
            if (t.closest('.share-email-btn--add')) {
                wrap.appendChild(createShareEmailRow(''));
                updateShareEmailRowButtons();
                const lastInput = wrap.querySelector('.share-email-row:last-child .share-email-input');
                if (lastInput) lastInput.focus();
                return;
            }
            if (t.closest('.share-email-btn--remove')) {
                if (wrap.querySelectorAll('.share-email-row').length <= 1) return;
                row.remove();
                updateShareEmailRowButtons();
            }
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

        async function ensurePdfReadyForShare(scanId, scanData) {
            if (lastDownloadUrls && lastDownloadUrls.pdf) return lastDownloadUrls.pdf;
            if (!scanId || !scanData) return null;
            return await new Promise((resolve) => {
                let resolved = false;
                const done = (url) => {
                    if (resolved) return;
                    resolved = true;
                    resolve(url || null);
                };
                ensurePdfSavedForHistory(scanId, scanData, 0, done);
                // Safety timeout in case callback is never reached.
                setTimeout(async () => {
                    if (resolved) return;
                    const url = await waitForPdfUrl(1200);
                    done(url);
                }, 11000);
            });
        }

        async function submitShareScan() {
            const scanId = parseInt(document.getElementById('shareScanId').value, 10);
            const artefacts = [];
            if (document.getElementById('shareArtefactsPdf').checked) artefacts.push('pdf');
            if (document.getElementById('shareArtefactsDoc').checked) artefacts.push('doc');
            if (document.getElementById('shareArtefactsHtml').checked) artefacts.push('html');
            if (document.getElementById('shareArtefactsCsv').checked) artefacts.push('csv');
            const recipients = collectShareRecipients();

            if (!scanId || artefacts.length === 0 || recipients.length === 0) {
                showToast('Share', 'Provide recipient email(s) and at least one format (PDF/DOC/HTML/CSV).', 'error');
                return;
            }

            // If PDF selected but server-side PDF is missing, generate/upload first
            // so share can include PDF consistently even without Dompdf.
            if (artefacts.includes('pdf') && (!lastDownloadUrls || !lastDownloadUrls.pdf) && lastScanId && lastScanData) {
                showToast('Preparing PDF', 'Generating PDF for sharing...', 'info');
                const sharePdf = await ensurePdfReadyForShare(lastScanId, lastScanData);
                if (!sharePdf) {
                    showToast('PDF note', 'PDF will be automatically generated when available. Sending selected available files now.', 'info');
                }
            }
            document.getElementById('shareSubmitBtn').disabled = true;

            try {
                const res = await fetch('../Backend/share_scan.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        scan_id: scanId,
                        artefacts: artefacts,
                        recipients: recipients
                    })
                });
                let payload = null;
                try { payload = await res.json(); } catch (e) { }
                if (!res.ok || !payload || !payload.ok) {
                    const msg = (payload && payload.error) ? payload.error : ('Share failed (HTTP ' + res.status + ')');
                    throw new Error(msg);
                }
                closeShareModal();
                if (payload && Array.isArray(payload.missing) && payload.missing.includes('pdf')) {
                    showToast('Shared with fallback', payload.message || 'PDF was unavailable, sent available files.', 'info');
                } else {
                    showToast('Share sent', payload.message || ('Scan results sent successfully to ' + recipients.length + ' recipient(s).'), 'success');
                }
            } catch (e) {
                showToast('Share failed', (e && e.message) ? e.message : 'Email sharing is unavailable.', 'error');
            } finally {
                document.getElementById('shareSubmitBtn').disabled = false;
            }
        }
        document.getElementById('shareSubmitBtn')?.addEventListener('click', submitShareScan);
        const toastQueue = [];
        let toastActive = false;
        function processToastQueue() {
            if (toastActive || toastQueue.length === 0) return;
            const container = document.getElementById('toastContainer');
            if (!container) {
                toastQueue.length = 0;
                return;
            }
            toastActive = true;
            const next = toastQueue.shift();
            const type = next.type || 'info';
            const toast = document.createElement('div');
            toast.className = 'share-toast';
            const icon = type === 'success'
                ? 'fa-check-circle'
                : (type === 'error'
                    ? 'fa-times-circle'
                    : (type === 'warning' ? 'fa-triangle-exclamation' : 'fa-info-circle'));
            toast.innerHTML = '<div class="share-toast-icon ' + type + '"><i class="fas ' + icon + '"></i></div><div class="share-toast-content"><h4>' + (next.title || '') + '</h4><p>' + (next.message || '') + '</p></div>';
            container.appendChild(toast);
            setTimeout(function () {
                toast.classList.add('hide');
                setTimeout(function () {
                    toast.remove();
                    toastActive = false;
                    processToastQueue();
                }, 320);
            }, 2600);
        }
        function showToast(title, message, type) {
            const maxTitle = 40;
            const maxMsg = 88;
            const safeTitle = String(title || '');
            const safeMsg = String(message || '');
            const t = safeTitle.length > maxTitle ? safeTitle.slice(0, maxTitle - 1) + '…' : safeTitle;
            const m = safeMsg.length > maxMsg ? safeMsg.slice(0, maxMsg - 1) + '…' : safeMsg;
            pushNotification(t, m, type || 'info');
            toastQueue.push({ title: t, message: m, type: type || 'info' });
            processToastQueue();
        }
        const notificationStore = [];
        let notificationSeq = 0;
        let notificationBadgeShown = 0;
        let notificationBadgeTimer = null;
        let notificationBadgeTarget = 0;
        function getNotificationModal() {
            return document.getElementById('notificationsModal');
        }
        function getNotificationById(id) {
            const nid = String(id || '');
            if (!nid) return null;
            return notificationStore.find(n => n.id === nid) || null;
        }
        function formatNotifTime(ts) {
            try {
                return new Date(ts).toLocaleString();
            } catch (e) {
                return '';
            }
        }
        function stepBadgeCount(target) {
            const badge = document.getElementById('notificationsBadge');
            if (!badge) return;
            notificationBadgeTarget = Math.max(0, Number(target) || 0);
            if (notificationBadgeTimer) return;
            if (notificationBadgeShown > 0 || notificationBadgeTarget > 0) {
                badge.style.display = 'inline-flex';
            }
            const tick = function () {
                if (notificationBadgeShown === notificationBadgeTarget) {
                    if (notificationBadgeShown <= 0) {
                        badge.style.display = 'none';
                        badge.textContent = '0';
                    } else {
                        badge.style.display = 'inline-flex';
                        badge.textContent = notificationBadgeShown > 99 ? '99+' : String(notificationBadgeShown);
                    }
                    notificationBadgeTimer = null;
                    return;
                }
                notificationBadgeShown += (notificationBadgeShown < notificationBadgeTarget ? 1 : -1);
                badge.style.display = 'inline-flex';
                badge.textContent = notificationBadgeShown > 99 ? '99+' : String(notificationBadgeShown);
                notificationBadgeTimer = setTimeout(tick, 85);
            };
            notificationBadgeTimer = setTimeout(tick, 85);
        }
        function updateNotificationBadge() {
            const unread = notificationStore.filter(n => !n.read).length;
            stepBadgeCount(unread);
        }
        function renderNotificationsList() {
            const list = document.getElementById('notificationsList');
            const empty = document.getElementById('notificationsEmpty');
            if (!list || !empty) return;
            if (!notificationStore.length) {
                list.innerHTML = '';
                empty.style.display = 'block';
                return;
            }
            empty.style.display = 'none';
            list.innerHTML = notificationStore.slice().reverse().map(n => {
                const type = String(n.type || 'info');
                const unread = !n.read ? ' is-unread' : '';
                const state = !n.read ? 'Unread' : 'Read';
                return '<button type="button" class="notif-item notif-' + escapeHtml(type) + unread + '" data-notif-id="' + escapeHtml(n.id) + '">' +
                    '<div class="notif-dot"></div>' +
                    '<div class="notif-body">' +
                    '<div class="notif-head"><h4>' + escapeHtml(n.title || 'Notification') + '</h4><span class="notif-state">' + state + '</span></div>' +
                    '<p>' + escapeHtml(n.message || '') + '</p>' +
                    '<span class="notif-time">' + escapeHtml(formatNotifTime(n.ts)) + '</span>' +
                    '</div></button>';
            }).join('');
        }
        function markNotificationRead(id) {
            const item = getNotificationById(id);
            if (!item || item.read) return;
            item.read = true;
            updateNotificationBadge();
            renderNotificationsList();
        }
        function pushNotification(title, message, type) {
            notificationSeq += 1;
            notificationStore.push({
                id: 'n' + notificationSeq,
                title: String(title || ''),
                message: String(message || ''),
                type: String(type || 'info'),
                ts: Date.now(),
                read: false,
            });
            updateNotificationBadge();
            renderNotificationsList();
        }
        function markAllNotificationsRead() {
            notificationStore.forEach(n => { n.read = true; });
            updateNotificationBadge();
            renderNotificationsList();
        }
        function clearAllNotifications() {
            notificationStore.length = 0;
            updateNotificationBadge();
            renderNotificationsList();
        }
        function openNotificationDetailModal(id) {
            const item = getNotificationById(id);
            const modal = document.getElementById('notificationDetailModal');
            if (!item || !modal) return;
            markNotificationRead(item.id);
            const titleEl = document.getElementById('notificationDetailTitle');
            const msgEl = document.getElementById('notificationDetailMessage');
            const metaEl = document.getElementById('notificationDetailMeta');
            const level = String(item.type || 'info').toUpperCase();
            if (titleEl) titleEl.textContent = String(item.title || 'Notification');
            if (msgEl) msgEl.textContent = String(item.message || '');
            if (metaEl) metaEl.textContent = level + ' - ' + formatNotifTime(item.ts);
            modal.style.display = 'flex';
            modal.classList.add('active');
        }
        function closeNotificationDetailModal() {
            const modal = document.getElementById('notificationDetailModal');
            if (!modal) return;
            modal.classList.remove('active');
            modal.style.display = 'none';
        }
        function openNotificationsModal() {
            const modal = getNotificationModal();
            if (!modal) return;
            renderNotificationsList();
            modal.style.display = 'flex';
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closeNotificationsModal() {
            const modal = getNotificationModal();
            if (!modal) return;
            modal.classList.remove('active');
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
        function copyTextToClipboard(text) {
            const raw = String(text || '').trim();
            if (!raw || raw === '-') return Promise.resolve(false);
            if (navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(raw).then(() => true).catch(() => false);
            }
            try {
                const ta = document.createElement('textarea');
                ta.value = raw;
                ta.setAttribute('readonly', 'readonly');
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                const ok = document.execCommand('copy');
                document.body.removeChild(ta);
                return Promise.resolve(!!ok);
            } catch (e) {
                return Promise.resolve(false);
            }
        }
        function syncClearCacheButton() {
            const btn = document.getElementById('clearFindingCacheBtn');
            if (!btn) return;
            const hasRenderedReport = !!(lastScanData && lastScanData.target);
            btn.style.display = hasRenderedReport ? 'inline-flex' : 'none';
            syncReportShellVisibility();
        }
        function clearFindingReportCache() {
            Object.keys(findingReportCache).forEach(k => delete findingReportCache[k]);
            warmupInFlight.clear();
            manualRegenInFlight.clear();
            autoRegenAttempts.clear();
            autoRegenLastTryAt.clear();
            autoRegenFirstSeenAt.clear();
            readyReportToastShown.clear();
            reportSourceTelemetrySent.clear();
            recommendationStemsSeen = new Set();
            currentScanRunId = '';
            lastVulnerabilities = [];
            scanFindingsAll = [];
            lastScanData = null;
            lastScanId = null;
            lastDownloadUrls = {};
            lastHumanReportText = '';
            clearAllNotifications();
            stopReportReconcileWindow();
            try { sessionStorage.removeItem(scanStateStorageKey); } catch (e) { }
            window.location.href = window.location.pathname;
        }
        async function regenerateFindingReport(vulnIndex) {
            const vuln = (lastVulnerabilities || [])[vulnIndex];
            if (!vuln) return;
            const key = makeCacheKey(vuln);
            const MAX_MANUAL_REGEN_PER_FINDING = 2;
            const shortName = String(vuln.name || 'Finding').replace(/\s+/g, ' ').trim().slice(0, 44) + (String(vuln.name || 'Finding').replace(/\s+/g, ' ').trim().length > 44 ? '…' : '');
            const prevCount = manualRegenCount.get(key) || 0;
            if (prevCount >= MAX_MANUAL_REGEN_PER_FINDING) {
                showToast('Regenerate limit reached', 'You can regenerate a finding report at most twice per issue.', 'warning');
                return;
            }
            if (manualRegenInFlight.has(key)) {
                showToast('Regenerating', 'This report is already being regenerated. Please wait.', 'warning');
                return;
            }
            manualRegenInFlight.add(key);
            manualRegenCount.set(key, prevCount + 1);
            document.querySelectorAll('.regen-finding-btn').forEach(btn => {
                const idx = parseInt(btn.getAttribute('data-vuln-index') || '-1', 10);
                if (idx !== vulnIndex) return;
                if (!(btn instanceof HTMLButtonElement)) return;
                btn.disabled = true;
                btn.classList.add('is-loading');
                const label = btn.querySelector('.btn-label');
                if (label) label.textContent = 'Regenerating...';
            });
            document.querySelectorAll('.view-finding-btn').forEach(btn => {
                const idx = parseInt(btn.getAttribute('data-vuln-index') || '-1', 10);
                if (idx !== vulnIndex) return;
                btn.classList.remove('ready', 'fallback');
                btn.classList.add('pending');
                const label = btn.querySelector('.btn-label');
                if (label) label.textContent = 'Regenerating...';
            });
            delete findingReportCache[key];
            warmupInFlight.delete(key);
            showToast('Regenerating', `${shortName} report is being regenerated…`, 'info');
            try {
                const payload = await fetchFindingReport(vuln, { forceFetch: true, useAi: true, bypassAiCache: true, disableRecommendationDiversity: true });
                if (payload?.report) {
                    const src = String(payload?.source || '');
                    markFindingButtonReady(vulnIndex, true, payload?.source, vuln);
                    // If the modal is open, update it in-place with the AI result.
                    const modal = document.getElementById('findingReportModal');
                    if (modal && modal.style.display !== 'none') {
                        const bodyEl = document.getElementById('findingReportBody');
                        if (bodyEl && bodyEl.style.display !== 'none') {
                            setFindingModalContent(payload.report, vuln, payload.source, payload.quality);
                        }
                    }
                    if (src === 'ai' || src === 'ai_corrected') {
                        showToast('Regenerated', `${shortName} report regenerated — ScanQuotient.`, 'success');
                    } else {
                        // AI route returned something else (usually deterministic fallback).
                        showToast(
                            'Regenerated',
                            `${shortName} report regenerated — ScanQuotient (fallback: ${src || 'unknown'}).`,
                            'warning'
                        );
                    }
                } else {
                    showToast('Regenerate failed', 'Report did not return valid content yet. Try again.', 'warning');
                }
            } catch (e) {
                showToast('Regenerate failed', 'Could not regenerate this report right now.', 'error');
            } finally {
                manualRegenInFlight.delete(key);
                document.querySelectorAll('.regen-finding-btn').forEach(btn => {
                    const idx = parseInt(btn.getAttribute('data-vuln-index') || '-1', 10);
                    if (idx !== vulnIndex) return;
                    if (!(btn instanceof HTMLButtonElement)) return;
                    btn.disabled = false;
                    btn.classList.remove('is-loading');
                    const label = btn.querySelector('.btn-label');
                    if (label) label.textContent = 'Regenerate';
                });
                syncReadyButtonsFromCache();
            }
        }
        function setFindingModalRegenerateState(isLoading) {
            const ids = ['findingModalRegenerateBtn', 'findingModalRegenerateBtnBottom'];
            ids.forEach(id => {
                const btn = document.getElementById(id);
                if (!(btn instanceof HTMLButtonElement)) return;
                if (!btn.dataset.originalHtml) {
                    btn.dataset.originalHtml = btn.innerHTML;
                }
                if (isLoading) {
                    btn.disabled = true;
                    btn.setAttribute('aria-busy', 'true');
                    btn.classList.add('is-loading');
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Regenerating...</span>';
                } else {
                    btn.disabled = false;
                    btn.removeAttribute('aria-busy');
                    btn.classList.remove('is-loading');
                    btn.innerHTML = btn.dataset.originalHtml || 'Regenerate';
                }
            });
        }
        function triggerFindingModalRegenerate() {
            const idx = Number(window.currentFindingModalIndex);
            if (Number.isFinite(idx) && idx >= 0) {
                return regenerateFindingReport(idx);
            }
            const uid = String(window.currentFindingContext?.vuln?._sq_uid || '');
            const fallbackIdx = uid ? (lastVulnerabilities || []).findIndex(v => String(v?._sq_uid || '') === uid) : -1;
            if (fallbackIdx >= 0) {
                return regenerateFindingReport(fallbackIdx);
            }
            return Promise.resolve();
        }
        async function copyEvidenceToClipboard() {
            const fullEl = document.getElementById('evidenceRawPlain');
            if (!fullEl) return;
            const text = (fullEl.textContent || '').trim();
            if (!text || text === '-') {
                showToast('Nothing to copy', 'No evidence text available.', 'error');
                return;
            }
            try {
                const ok = await copyTextToClipboard(text);
                if (!ok) throw new Error('Copy command failed');
                showToast('Copied', 'Evidence copied to clipboard.', 'success');
            } catch (e) {
                showToast('Copy failed', 'Could not copy evidence automatically.', 'error');
            }
        }
        // Evidence modal buttons are rendered after this script, so use delegated events.
        document.addEventListener('click', function (e) {
            const t = e.target;
            if (!(t instanceof Element)) return;
            if (t.closest('#findingModalRegenerateBtn') || t.closest('#findingModalRegenerateBtnBottom')) {
                e.preventDefault();
                e.stopPropagation();
                const clicked = t.closest('#findingModalRegenerateBtn, #findingModalRegenerateBtnBottom');
                if (clicked instanceof HTMLButtonElement && clicked.disabled) return;
                setFindingModalRegenerateState(true);
                Promise.resolve(triggerFindingModalRegenerate()).finally(() => {
                    setFindingModalRegenerateState(false);
                });
                return;
            }
            if (t.closest('#shareResultsBtn')) {
                if (!lastScanId) {
                    showToast('Share', 'Run and save a scan first, then share it by email.', 'error');
                    return;
                }
                const idEl = document.getElementById('shareScanId');
                if (idEl) idEl.value = String(lastScanId);
                const pdf = document.getElementById('shareArtefactsPdf');
                const doc = document.getElementById('shareArtefactsDoc');
                const html = document.getElementById('shareArtefactsHtml');
                const csv = document.getElementById('shareArtefactsCsv');
                if (pdf) pdf.checked = true;
                if (doc) doc.checked = !!(lastDownloadUrls && lastDownloadUrls.doc);
                if (html) html.checked = true;
                if (csv) csv.checked = true;
                syncShareAllCheckbox();
                openShareModal();
                return;
            }
            if (t.closest('#shareModalClose')) {
                closeShareModal();
                return;
            }
            if (t.closest('#notificationsModalClose') || t.id === 'notificationsModal') {
                closeNotificationsModal();
                return;
            }
            if (t.closest('#notificationDetailClose') || t.id === 'notificationDetailModal') {
                closeNotificationDetailModal();
                return;
            }
            if (t.closest('#notificationsMarkAllReadBtn')) {
                markAllNotificationsRead();
                return;
            }
            if (t.closest('#notificationsClearAllBtn')) {
                clearAllNotifications();
                return;
            }
            const notifItem = t.closest('.notif-item[data-notif-id]');
            if (notifItem) {
                const id = notifItem.getAttribute('data-notif-id') || '';
                openNotificationDetailModal(id);
                return;
            }
            if (t.closest('#shareSubmitBtn')) {
                submitShareScan();
                return;
            }
            if (t.closest('#findingEvidenceViewRawBtn')) {
                e.preventDefault();
                e.stopPropagation();
                openFindingEvidenceRawModal();
                return;
            }
            if (t.closest('#findingEvidenceRawClose') || t.id === 'findingEvidenceRawModal') {
                closeFindingEvidenceRawModal();
                return;
            }
            if (t.closest('#findingRawPaneOpenBtn')) {
                const fullModal = document.getElementById('findingRawFullscreenModal');
                const fullText = document.getElementById('findingRawFullscreenText');
                if (fullText) fullText.textContent = String(currentFindingEvidenceRaw || '').trim() || '-';
                if (fullModal) {
                    fullModal.style.display = 'flex';
                    fullModal.classList.add('active');
                }
                return;
            }
            if (t.closest('#findingRawFullscreenClose') || t.id === 'findingRawFullscreenModal') {
                const fullModal = document.getElementById('findingRawFullscreenModal');
                if (fullModal) {
                    fullModal.classList.remove('active');
                    fullModal.style.display = 'none';
                }
                return;
            }
            if (t.closest('#findingRawFullscreenCopyBtn')) {
                const raw = String(currentFindingEvidenceRaw || '').trim() || '-';
                copyTextToClipboard(raw).then(ok => {
                    showToast(ok ? 'Copied' : 'Copy failed', ok ? 'Raw finding evidence copied.' : 'Unable to copy raw finding evidence.', ok ? 'success' : 'error');
                });
                return;
            }
            if (t.closest('#scanTimelinePreviewClose') || t.id === 'scanTimelinePreviewModal') {
                closeScanTimelinePreviewModal();
                return;
            }
            if (t.closest('#scanTimelinePreviewViewFullBtn')) {
                e.preventDefault();
                e.stopPropagation();
                closeScanTimelinePreviewModal();
                openScanTimelineFullModal();
                return;
            }
            if (t.closest('#scanTimelineFullClose') || t.id === 'scanTimelineFullModal') {
                closeScanTimelineFullModal();
                return;
            }
            if (t.closest('#findingPrintBtn')) {
                printFindingReport();
                return;
            }
            if (t.closest('#clearFindingCacheBtn')) {
                clearFindingReportCache();
                return;
            }
            if (t.closest('.regen-finding-btn')) {
                const idx = parseInt(t.closest('.regen-finding-btn')?.getAttribute('data-vuln-index') || '-1', 10);
                if (!Number.isNaN(idx) && idx >= 0) regenerateFindingReport(idx);
                return;
            }
            if (t.closest('#findingReportClose') || t.closest('#findingReportDone')) {
                closeFindingReportModal();
                return;
            }
            const trigger = t.closest('.section-clickable');
            if (trigger) {
                const section = trigger.getAttribute('data-section') || '';
                openFindingSectionModal(section);
                trigger.classList.add('hint-dismissed');
                return;
            }
            if (t.closest('#findingSectionClose') || t.id === 'findingSectionModal') {
                closeFindingSectionModal();
                return;
            }
            if (t.id === 'findingReportModal') {
                closeFindingReportModal();
                return;
            }
            if (t.closest('#evidenceModalClose') || t.closest('#evidenceModalDone')) {
                closeEvidenceModal();
                return;
            }
            if (t.closest('#copyEvidenceBtn')) {
                return;
            }
            if (t.id === 'evidenceModal') {
                closeEvidenceModal();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeNotificationDetailModal();
                closeNotificationsModal();
                closeReportOverlay('userReportOverlay');
                closeReportOverlay('downloadsOverlay');
                closeScanTimelinePreviewModal();
                closeScanTimelineFullModal();
                closeFindingEvidenceRawModal();
                closeEvidenceModal();
                closeFindingReportModal();
                closeFindingSectionModal();
            }
        });
        document.getElementById('findingPrintBtn')?.addEventListener('click', printFindingReport);
        document.getElementById('findingSearchInput')?.addEventListener('input', applyFindingsFilters);
        document.getElementById('findingDomainFilter')?.addEventListener('change', applyFindingsFilters);
        document.getElementById('findingSeverityFilter')?.addEventListener('change', applyFindingsFilters);
        document.getElementById('findingCategoryFilter')?.addEventListener('change', applyFindingsFilters);
        document.getElementById('findingSortSelect')?.addEventListener('change', applyFindingsFilters);
        document.getElementById('findingResetFiltersBtn')?.addEventListener('click', function () {
            const s = document.getElementById('findingSearchInput');
            const dom = document.getElementById('findingDomainFilter');
            const sev = document.getElementById('findingSeverityFilter');
            const cat = document.getElementById('findingCategoryFilter');
            const sort = document.getElementById('findingSortSelect');
            if (s) s.value = '';
            if (dom) dom.value = 'all';
            if (sev) sev.value = 'all';
            if (cat) cat.value = 'all';
            if (sort) sort.value = 'severity_desc';
            applyFindingsFilters();
        });
        syncClearCacheButton();
        document.querySelectorAll('.section-clickable').forEach(el => {
            el.addEventListener('mouseenter', () => el.classList.add('hint-visible'));
            el.addEventListener('mouseleave', () => el.classList.remove('hint-visible'));
        });
    </script>

    <div id="notificationsModal" class="modal-overlay notifications-modal-overlay"
        style="display:none; position:fixed; inset:0; background:rgba(2, 6, 23, 0.65); z-index:10019; align-items:center; justify-content:center;">
        <div class="modal notifications-modal">
            <div class="notifications-modal-head">
                <h3><i class="fas fa-bell"></i> Notifications</h3>
                <button type="button" id="notificationsModalClose" class="icon-btn" title="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="notifications-modal-actions">
                <button type="button" id="notificationsMarkAllReadBtn" class="modal-btn secondary">Mark all as read</button>
                <button type="button" id="notificationsClearAllBtn" class="modal-btn secondary">Clear all</button>
            </div>
            <div id="notificationsEmpty" class="notifications-empty">No notifications yet.</div>
            <div id="notificationsList" class="notifications-list"></div>
        </div>
    </div>
    <div id="notificationDetailModal" class="modal-overlay notifications-modal-overlay"
        style="display:none; position:fixed; inset:0; background:rgba(2, 6, 23, 0.65); z-index:10021; align-items:center; justify-content:center;">
        <div class="modal notification-detail-modal">
            <h3 id="notificationDetailTitle">Notification</h3>
            <p id="notificationDetailMessage"></p>
            <div id="notificationDetailMeta" class="notification-detail-meta"></div>
            <div class="notifications-modal-actions">
                <button type="button" id="notificationDetailClose" class="modal-btn secondary">Close</button>
            </div>
        </div>
    </div>
    <div id="toastContainer"
        style="position:fixed;bottom:24px;right:24px;z-index:10020;display:flex;flex-direction:column;gap:8px;pointer-events:none;">
    </div>
    <style>
        /* Prevent findings/report action controls from overlapping on wide layouts */
        .results-tabs {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .results-tabs .results-tabs-row {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            flex-wrap: wrap;
            width: 100%;
            box-sizing: border-box;
        }

        .results-tabs .results-tabs-row-top .results-tab {
            flex: 1 1 calc(50% - 4px);
            max-width: calc(50% - 4px);
            white-space: nowrap;
            justify-content: center;
        }

        .results-tabs .results-tabs-row-top #targetBadge {
            flex: 1 0 100%;
            max-width: 100%;
        }

        .results-tabs .results-tabs-row-bottom {
            justify-content: space-between;
        }

        #backToTopBtn,
        #scrollToBottomBtn {
            position: fixed;
            right: 22px;
            width: 46px;
            height: 46px;
            border-radius: 999px;
            color: #fff;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 10018;
            cursor: pointer;
            transition: transform .16s ease, box-shadow .2s ease, opacity .2s ease;
        }
        #backToTopBtn { bottom: 76px; }
        #scrollToBottomBtn { bottom: 22px; }
        /* Light mode colors */
        #backToTopBtn {
            border: 1px solid rgba(22, 163, 74, 0.35);
            background: linear-gradient(180deg, #22c55e 0%, #16a34a 100%);
            box-shadow: 0 12px 28px rgba(22, 163, 74, 0.35);
        }
        #scrollToBottomBtn {
            border: 1px solid rgba(8, 145, 178, 0.35);
            background: linear-gradient(180deg, #06b6d4 0%, #0891b2 100%);
            box-shadow: 0 12px 28px rgba(8, 145, 178, 0.32);
        }
        /* Keep distinct colors in dark mode too */
        body.dark #backToTopBtn {
            border-color: rgba(74, 222, 128, 0.4);
            background: linear-gradient(180deg, #22c55e 0%, #15803d 100%);
            box-shadow: 0 12px 28px rgba(21, 128, 61, 0.45);
        }
        body.dark #scrollToBottomBtn {
            border-color: rgba(34, 211, 238, 0.4);
            background: linear-gradient(180deg, #06b6d4 0%, #0e7490 100%);
            box-shadow: 0 12px 28px rgba(14, 116, 144, 0.45);
        }
        #backToTopBtn:hover,
        #scrollToBottomBtn:hover {
            transform: translateY(-2px);
        }
        #backToTopBtn:hover { box-shadow: 0 16px 32px rgba(22, 163, 74, 0.42); }
        #scrollToBottomBtn:hover { box-shadow: 0 16px 32px rgba(8, 145, 178, 0.42); }
        #backToTopBtn:active,
        #scrollToBottomBtn:active {
            transform: translateY(0);
        }
        #backToTopBtn:focus-visible,
        #scrollToBottomBtn:focus-visible {
            outline: 3px solid rgba(147, 197, 253, 0.9);
            outline-offset: 2px;
        }

        .results-tabs .report-actions {
            width: 100%;
            margin-left: 0;
            margin-right: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            min-width: 0;
            max-width: 100%;
            flex-wrap: wrap;
            box-sizing: border-box;
            padding-left: 0;
            padding-right: 0;
        }

        /* Keep artefacts/action row aligned with the same inner margins */
        .results-tabs .results-tabs-row-bottom {
            padding-left: 2px;
            padding-right: 2px;
            box-sizing: border-box;
        }

        .results-tabs .report-artefact-btns-left,
        .results-tabs .report-extra-actions-right {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }

        .results-tabs .report-extra-actions-right {
            margin-left: auto;
            justify-content: flex-end;
        }

        .results-tabs .artefact-btn {
            flex: 0 0 auto;
            white-space: nowrap;
        }

        #findingModalRegenerateBtn,
        #findingModalRegenerateBtnBottom {
            display: inline-block;
            transition: transform .15s ease, filter .2s ease, opacity .2s ease;
        }

        #findingModalRegenerateBtn:hover:not(:disabled),
        #findingModalRegenerateBtnBottom:hover:not(:disabled) {
            transform: translateY(-1px);
            filter: drop-shadow(0 8px 14px rgba(15, 23, 42, 0.25));
        }

        #findingModalRegenerateBtn:active:not(:disabled),
        #findingModalRegenerateBtnBottom:active:not(:disabled) {
            transform: translateY(0);
            filter: drop-shadow(0 4px 10px rgba(15, 23, 42, 0.18));
        }

        #findingModalRegenerateBtn.is-loading,
        #findingModalRegenerateBtnBottom.is-loading {
            opacity: .7;
            cursor: not-allowed !important;
            filter: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        #findingModalRegenerateBtnBottom.modal-btn.sq-pill-btn {
            border-radius: 999px !important;
            padding: 9px 16px !important;
            font-weight: 700;
            border: 1px solid rgba(59, 130, 246, 0.35);
            background: rgba(59, 130, 246, 0.12);
            color: var(--brand-color);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: transform .2s ease, box-shadow .2s ease, filter .2s ease, background .2s ease, border-color .2s ease;
        }

        #findingModalRegenerateBtnBottom.modal-btn.sq-pill-btn:hover:not(:disabled) {
            background: rgba(59, 130, 246, 0.2);
            border-color: rgba(59, 130, 246, 0.5);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.18);
            transform: translateY(-1px);
        }

        #findingModalRegenerateBtnBottom.modal-btn.sq-pill-btn:active:not(:disabled) {
            transform: translateY(0);
            box-shadow: 0 4px 10px rgba(15, 23, 42, 0.12);
        }

        #findingModalRegenerateBtnBottom.modal-btn.sq-pill-btn:focus-visible {
            outline: 2px solid var(--brand-color);
            outline-offset: 2px;
        }

        .regen-finding-btn.is-loading {
            opacity: .72;
            cursor: not-allowed !important;
            pointer-events: none;
        }

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

        #shareModal #shareModalClose.modal-btn {
            border-radius: 999px;
            padding: 10px 16px;
            border: 1px solid var(--border-color);
            background: var(--bg-main);
            color: var(--text-main);
            font-weight: 700;
            transition: transform .15s ease, box-shadow .2s ease, background .2s ease;
        }

        #shareModal #shareModalClose.modal-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(15, 23, 42, .15);
            background: rgba(100, 116, 139, .12);
        }

        #shareModal #shareSubmitBtn.modal-btn {
            border-radius: 999px;
            padding: 10px 18px;
            border: none;
            background: linear-gradient(135deg, var(--brand-color), var(--accent-color));
            color: #fff;
            font-weight: 800;
            transition: transform .15s ease, box-shadow .2s ease, filter .2s ease;
        }

        #shareModal #shareSubmitBtn.modal-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 24px rgba(37, 99, 235, .28);
            filter: brightness(1.05);
        }

        #shareModal #shareSubmitBtn.modal-btn:disabled {
            opacity: .6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        #shareModal .modal {
            border: 1px solid var(--border-color);
            box-shadow: 0 24px 60px rgba(2, 6, 23, .35);
            border-radius: 18px;
            background: linear-gradient(180deg, rgba(255, 255, 255, .96), rgba(248, 250, 252, .98));
            backdrop-filter: blur(3px);
        }

        #shareModal h3 {
            margin: 0 0 12px 0;
            font-size: 18px;
            font-weight: 800;
            color: var(--text-main);
        }

        #shareModal label {
            color: var(--text-main);
        }

        #shareModal input[type="checkbox"] {
            accent-color: #2563eb;
            transform: translateY(1px);
            margin-right: 6px;
        }

        .share-emails-stack {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 14px;
        }
        .share-email-row {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 8px;
            align-items: center;
        }
        .share-email-input {
            width: 100%;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: #fff;
            color: var(--text-main);
            transition: border-color .15s ease, box-shadow .2s ease;
        }
        .share-email-input:focus {
            outline: none;
            border-color: rgba(59, 130, 246, .55);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, .14);
        }
        .share-email-btn {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--bg-main);
            color: var(--text-main);
            font-weight: 800;
            font-size: 18px;
            cursor: pointer;
            line-height: 1;
        }
        .share-email-btn:hover { filter: brightness(0.96); }
        .share-email-btn--add {
            color: #0f766e;
            border-color: rgba(20, 184, 166, 0.35);
            background: rgba(20, 184, 166, 0.10);
        }
        .share-email-btn--remove {
            color: #b91c1c;
            border-color: rgba(239, 68, 68, 0.35);
            background: rgba(239, 68, 68, 0.10);
        }

        .sidebar-rich-tip { position: relative; overflow: visible; }
        .sidebar-rich-tip::after,
        .sidebar-rich-tip::before {
            position: absolute;
            left: calc(100% + 12px);
            opacity: 0;
            visibility: hidden;
            transition: opacity .16s ease, transform .16s ease;
            pointer-events: none;
            z-index: 90;
        }
        .sidebar-rich-tip::after {
            content: attr(data-tip-title) "\A" attr(data-tip-text);
            white-space: pre-line;
            top: 50%;
            transform: translateY(-50%) translateX(-4px);
            min-width: 260px;
            max-width: 320px;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-main);
            box-shadow: 0 10px 24px rgba(2, 6, 23, 0.18);
            font-size: 12px;
            line-height: 1.45;
            text-align: left;
        }
        .sidebar-rich-tip::before {
            content: "";
            top: 50%;
            transform: translateY(-50%) translateX(-4px);
            border: 7px solid transparent;
            border-right-color: var(--border-color);
            left: calc(100% + 0px);
        }
        .sidebar-rich-tip:hover::after,
        .sidebar-rich-tip:hover::before,
        .sidebar-rich-tip:focus-visible::after,
        .sidebar-rich-tip:focus-visible::before {
            opacity: 1;
            visibility: visible;
            transform: translateY(-50%) translateX(0);
        }
        /* For download items inside the sidebar popover, show tooltip beside each item. */
        .sidebar-popover-item.sidebar-rich-tip::after,
        .sidebar-popover-item.sidebar-rich-tip::before {
            left: calc(100% + 12px);
            top: 50%;
            right: auto;
        }
        .sidebar-popover-item.sidebar-rich-tip::after {
            bottom: auto;
            transform: translateY(-50%) translateX(-4px);
            min-width: 220px;
            max-width: 300px;
        }
        .sidebar-popover-item.sidebar-rich-tip::before {
            bottom: auto;
            left: calc(100% + 0px);
            transform: translateY(-50%) translateX(-4px);
            border: 7px solid transparent;
            border-right-color: var(--border-color);
            border-top-color: transparent;
        }
        .sidebar-popover-item.sidebar-rich-tip:hover::after,
        .sidebar-popover-item.sidebar-rich-tip:hover::before,
        .sidebar-popover-item.sidebar-rich-tip:focus-visible::after,
        .sidebar-popover-item.sidebar-rich-tip:focus-visible::before {
            transform: translateY(-50%) translateX(0);
        }
    </style>
    <div id="shareModal" class="modal-overlay"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
        <div class="modal"
            style="background:var(--bg-card); padding:24px; border-radius:16px; max-width:420px; width:90%;">
            <h3 style="margin-bottom:16px;">Share scan results</h3>
            <input type="hidden" id="shareScanId" value="">
            <p style="margin-bottom:6px; font-size:13px; font-weight:600;">Share with</p>
            <p style="margin-bottom:8px; font-size:12px; color:var(--text-light);">Enter one or several email addresses:</p>
            <div id="shareEmailsWrap" class="share-emails-stack" aria-label="Recipient email addresses"></div>
            <p style="margin-bottom:8px; font-size:13px; font-weight:600;">Attach</p>
            <div style="margin-bottom:16px;">
                <label style="display:block; margin:4px 0; font-weight:600;"><input type="checkbox"
                        id="shareArtefactsAll"> Select all</label>
                <label style="display:block; margin:4px 0;"><input type="checkbox" id="shareArtefactsPdf"> PDF</label>
                <label style="display:block; margin:4px 0;"><input type="checkbox" id="shareArtefactsDoc"> DOC</label>
                <label style="display:block; margin:4px 0;"><input type="checkbox" id="shareArtefactsHtml"> HTML</label>
                <label style="display:block; margin:4px 0;"><input type="checkbox" id="shareArtefactsCsv"> CSV</label>
            </div>
            <div style="margin-top:16px; display:flex; gap:8px; justify-content:flex-end;">
                <button type="button" id="shareModalClose" class="modal-btn secondary">Cancel</button>
                <button type="button" id="shareSubmitBtn" class="modal-btn">Send</button>
            </div>
        </div>
    </div>
    <div id="findingReportModal" class="modal-overlay"
        style="display:none; position:fixed; inset:0; background:rgba(2, 6, 23, 0.72); z-index:10004; align-items:center; justify-content:center;">
        <div class="finding-report-modal">
            <div
                style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:12px;">
                <div>
                    <div style="font-size:20px; font-weight:800; color:var(--text-main);" id="findingTitle">Finding
                        report</div>
                    <div style="display:none; font-size:12px; color:var(--text-light);">Structured evidence-based
                        security report</div>
                    <div id="findingQualityBadge" class="finding-quality-badge ok"
                        style="display:none; margin-top:8px;">
                        <i class="fas fa-shield-check"></i><span id="findingQualityText">Quality validated</span>
                    </div>
                </div>
                <button type="button" id="findingReportClose" class="icon-btn" title="Close"
                    style="width:40px;height:40px;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="findingReportLoading" class="finding-loading">
                <i class="fas fa-spinner fa-spin"></i> Building detailed report...
            </div>
            <div id="findingReportBody" class="finding-modal-grid" style="display:none;">
                <div class="finding-card third finding-kv">
                    <div class="k">Severity</div>
                    <div class="v" id="findingSeverity">-</div>
                </div>
                <div class="finding-card third finding-kv">
                    <div class="k">Category</div>
                    <div class="v" id="findingCategory">-</div>
                </div>
                <div class="finding-card third finding-kv">
                    <div class="k">Result Status</div>
                    <div class="v" id="findingStatus">-</div>
                </div>
                <div class="finding-card third finding-kv">
                    <div class="k">Target</div>
                    <div class="v" id="findingTarget">-</div>
                </div>
                <div class="finding-card third finding-kv" id="findingCardIp">
                    <div class="k">IP Address</div>
                    <div class="v" id="findingIp">-</div>
                </div>
                <div class="finding-card third finding-kv">
                    <div class="k">Detection Time</div>
                    <div class="v" id="findingDetectionTime">-</div>
                </div>
                <div class="finding-card third finding-kv" id="findingCardPort">
                    <div class="k">Port</div>
                    <div class="v" id="findingPort">-</div>
                </div>
                <div class="finding-card third finding-kv" id="findingCardService">
                    <div class="k">Service</div>
                    <div class="v" id="findingService">-</div>
                </div>
                <div class="finding-card third finding-kv" id="findingCardState">
                    <div class="k">State</div>
                    <div class="v" id="findingState">-</div>
                </div>
                <div class="finding-card half finding-kv">
                    <div class="k">Likelihood</div>
                    <div class="v" id="findingLikelihood">-</div>
                </div>
                <div class="finding-card half finding-kv">
                    <div class="k">Remediation Priority</div>
                    <div class="v" id="findingPriority">-</div>
                </div>
                <div class="finding-card half finding-kv important-card section-clickable" id="findingDescCard"
                    data-section="description">
                    <div class="k">Description</div>
                    <div class="v" id="findingDescription">-</div>
                    <div class="section-hint">Click to view more</div>
                </div>
                <div class="finding-card half finding-kv important-card section-clickable" id="findingEvidenceCard"
                    data-section="evidence">
                    <div class="k">Evidence</div>
                    <div class="v" id="findingEvidence">-</div>
                    <div class="section-hint">Click to view more</div>
                </div>
                <div class="finding-card half finding-kv important-card section-clickable" id="findingRiskCard"
                    data-section="risk_explanation">
                    <div class="k">What This Evidence Indicates</div>
                    <div class="v" id="findingRiskExplanation">-</div>
                    <div class="section-hint">Click to view more</div>
                </div>
                <div class="finding-card half important-card section-clickable" id="findingImpactCard"
                    data-section="potential_impact">
                    <div class="k"
                        style="font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:var(--text-light); font-weight:700; margin-bottom:8px;">
                        Potential Business and Security Impact</div>
                    <ul id="findingImpactList" class="finding-list">
                        <li>Not provided.</li>
                    </ul>
                    <div class="section-hint">Click to view more</div>
                </div>
                <div class="finding-card important-card section-clickable" id="findingRecoCard"
                    data-section="recommendations">
                    <div class="k"
                        style="font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:var(--text-light); font-weight:700; margin-bottom:8px;">
                        Recommended Actions</div>
                    <ul id="findingRecommendations" class="finding-list">
                        <li>Not provided.</li>
                    </ul>
                    <div class="section-hint">Click to view more</div>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
                <button type="button" id="findingPrintBtn" class="modal-btn secondary sq-pill-btn" title="Print report"
                    aria-label="Print report"><i class="fas fa-print"></i></button>
                <button type="button" id="findingModalRegenerateBtnBottom" class="modal-btn secondary sq-pill-btn"
                    title="Regenerate this report">
                    <i class="fas fa-rotate-right"></i><span>Regenerate</span>
                </button>
                <button type="button" id="findingReportDone" class="modal-btn sq-pill-btn finding-close-btn"><i
                        class="fas fa-check-circle"></i><span>Close Report</span></button>
            </div>
            <div style="margin-top:10px; font-size:12px; color:var(--text-light);">
                If you are not satisfied with the report results, click
                <button type="button" id="findingModalRegenerateBtn"
                    style="padding:0;border:0;background:transparent;color:var(--brand-color);font-weight:inherit;font-size:inherit;cursor:pointer;text-decoration:none;">
                    Regenerate
                </button>
                to request an AI-enhanced alternative version of this finding.
            </div>
        </div>
    </div>
    <div id="findingSectionModal" class="modal-overlay"
        style="display:none; position:fixed; inset:0; background:rgba(2,6,23,0.72); z-index:10006; align-items:center; justify-content:center;">
        <div class="modal"
            style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; width:94%; max-width:860px; max-height:88vh; overflow:auto; padding:18px;">
            <div id="findingSectionHead" class="finding-section-head section-theme-description"
                style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                <div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span class="finding-section-icon"><i id="findingSectionIcon"
                                class="fas fa-align-left"></i></span>
                        <div id="findingSectionTitle" style="font-size:18px;font-weight:800;">Section details</div>
                    </div>
                    <div style="font-size:12px;color:var(--text-light);">Extended analysis for this finding section
                    </div>
                </div>
                <button type="button" id="findingSectionClose" class="icon-btn" style="width:38px;height:38px;"><i
                        class="fas fa-times"></i></button>
            </div>
            <div id="findingSectionContent" style="white-space:pre-wrap;line-height:1.6;font-size:14px;"></div>
        </div>
    </div>
    <div id="findingEvidenceRawModal" class="modal-overlay sq-evidence-raw-modal"
        style="display:none; position:fixed; inset:0; background:rgba(2,6,23,0.78); z-index:10009; align-items:center; justify-content:center;">
        <div class="modal sq-evidence-raw-inner"
            style="background:var(--bg-card); padding:20px; border-radius:16px; width:96%; max-width:1100px; border:1px solid var(--border-color); max-height:90vh; overflow:hidden; display:flex; flex-direction:column;">
            <div
                style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:12px; flex-shrink:0;">
                <div>
                    <div style="font-size:17px; font-weight:800; color:var(--text-main);">Full raw evidence</div>
                    <div style="font-size:12px; color:var(--text-light); margin-top:4px;">Verbatim capture (left) ·
                        Explained breakdown (right)</div>
                </div>
                <button type="button" id="findingEvidenceRawClose" class="icon-btn" title="Close"
                    style="width:40px;height:40px;"><i class="fas fa-times"></i></button>
            </div>
            <div class="sq-evidence-raw-split">
                <div class="sq-evidence-raw-pane sq-evidence-raw-pane-left" id="findingRawPaneOpenBtn"
                    title="Click to open larger raw evidence modal">
                    <div class="sq-evidence-raw-pane-label">Raw evidence (as captured)</div>
                    <pre id="findingEvidenceRawText" class="sq-evidence-raw-pre">-</pre>
                </div>
                <div class="sq-evidence-raw-pane sq-evidence-raw-pane-right">
                    <div class="sq-evidence-raw-pane-label">Explained</div>
                    <div id="findingEvidenceExplained" class="sq-evidence-explained-scroll"></div>
                </div>
            </div>
        </div>
    </div>
    <div id="findingRawFullscreenModal" class="modal-overlay"
        style="display:none; position:fixed; inset:0; background:rgba(2,6,23,0.84); z-index:10012; align-items:center; justify-content:center;">
        <div class="modal"
            style="background:var(--bg-card); padding:18px; border-radius:16px; width:96%; max-width:1200px; border:1px solid var(--border-color); max-height:92vh; overflow:hidden; display:flex; flex-direction:column;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:12px;">
                <div>
                    <div style="font-size:17px; font-weight:800; color:var(--text-main);">Raw evidence (full view)</div>
                    <div style="font-size:12px; color:var(--text-light);">Expanded for easier inspection and copying.
                    </div>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <button type="button" id="findingRawFullscreenCopyBtn"
                        class="modal-btn secondary sq-pill-btn finding-raw-copy-btn">
                        <i class="fas fa-copy"></i><span>Copy</span>
                    </button>
                    <button type="button" id="findingRawFullscreenClose" class="icon-btn" title="Close"
                        style="width:40px;height:40px;"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <pre id="findingRawFullscreenText" class="sq-evidence-raw-pre" style="flex:1; min-height:280px;">-</pre>
        </div>
    </div>
    <div id="scanTimelinePreviewModal" class="modal-overlay sq-timeline-preview-modal"
        style="display:none; position:fixed; inset:0; background:rgba(2,6,23,0.72); z-index:10007; align-items:center; justify-content:center;">
        <div class="modal sq-timeline-preview-inner"
            style="background:var(--bg-card); padding:22px; border-radius:16px; width:94%; max-width:920px; border:1px solid var(--border-color); max-height:88vh; display:flex; flex-direction:column;">
            <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:10px;">
                <div>
                    <div style="font-size:17px; font-weight:800; color:var(--text-main);">Scan run timeline</div>
                    <div id="scanTimelinePreviewSub" class="sq-timeline-preview-sub" style="font-size:12px; color:var(--text-light); margin-top:4px;">—</div>
                </div>
                <button type="button" id="scanTimelinePreviewClose" class="icon-btn" title="Close"
                    style="width:40px;height:40px;"><i class="fas fa-times"></i></button>
            </div>
            <div id="scanTimelinePreviewBody" class="scan-run-timeline-body sq-timeline-preview-body" style="flex:1; min-height:0; overflow-y:auto;"></div>
            <p id="scanTimelinePreviewEmpty" class="scan-run-timeline-empty" style="display:none; margin:12px 0;">No
                saved scans for this host yet. After a report is stored, repeat scans will show score deltas and changes.</p>
            <div id="scanTimelinePreviewActions" class="sq-timeline-preview-actions" style="display:none; flex-wrap:wrap; gap:10px; margin-top:14px; padding-top:14px; border-top:1px solid var(--border-color);">
                <button type="button" id="scanTimelinePreviewViewFullBtn" class="scan-run-timeline-viewall-btn" style="display:none;">
                    <i class="fas fa-expand-alt"></i> View full timeline
                </button>
                <a id="scanTimelinePreviewHistoryBtn" class="scan-run-timeline-history-link sq-timeline-preview-history-btn" href="/ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Frontend/historical_scans.php">
                    Full history <i class="fas fa-external-link-alt" style="font-size:11px;" aria-hidden="true"></i>
                </a>
            </div>
        </div>
    </div>
    <div id="scanTimelineFullModal" class="modal-overlay sq-timeline-full-modal"
        style="display:none; position:fixed; inset:0; background:rgba(2,6,23,0.72); z-index:10008; align-items:center; justify-content:center;">
        <div class="modal sq-timeline-full-inner"
            style="background:var(--bg-card); padding:22px; border-radius:16px; width:94%; max-width:720px; border:1px solid var(--border-color); max-height:88vh; overflow:auto;">
            <div
                style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:14px;">
                <div>
                    <div style="font-size:18px; font-weight:800; color:var(--text-main);">Full scan run timeline</div>
                    <div id="scanTimelineFullSub" style="font-size:12px; color:var(--text-light); margin-top:4px;">All
                        saved runs for this host</div>
                </div>
                <button type="button" id="scanTimelineFullClose" class="icon-btn" title="Close"
                    style="width:40px;height:40px;"><i class="fas fa-times"></i></button>
            </div>
            <div id="scanTimelineFullBody" class="scan-run-timeline-body"></div>
        </div>
    </div>
    <div id="evidenceModal" class="modal-overlay"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:10003; align-items:center; justify-content:center;">
        <div class="modal evidence-modal-wide"
            style="background:var(--bg-card); padding:20px; border-radius:16px; max-width:920px; width:94%; border:1px solid var(--border-color); max-height:88vh; overflow:auto;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:12px;">
                <div>
                    <div id="evidenceModalTitle" style="font-size:16px; font-weight:800;">Vulnerability Evidence</div>
                    <div style="font-size:12px; color:var(--text-light);">Technical capture (left) · Highlighted lines
                        (right)</div>
                </div>
                <button type="button" id="evidenceModalClose" class="icon-btn" title="Close"
                    style="width:38px;height:38px;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="evidence-modal-split">
                <div class="evidence-modal-pane evidence-modal-pane-raw">
                    <div class="evidence-pane-label">Raw technical data</div>
                    <pre id="evidenceRawPlain" class="evidence-pre-raw">-</pre>
                </div>
                <div class="evidence-modal-pane evidence-modal-pane-colored">
                    <div class="evidence-pane-label">Highlighted (same data)</div>
                    <div id="evidenceAnnotated" class="evidence-annotated-wrap"></div>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top:12px;">
                <button type="button" id="evidenceModalDone" class="modal-btn sq-pill-btn">
                    Close
                </button>
            </div>
        </div>
    </div>
</body>

</html>