<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['user_uid'])) {
    header('Location: /ScanQuotient.v2/ScanQuotient.B/Public/Login_page/PHP/Frontend/Login_page_site.php');
    exit;
}
$scan_id = isset($_GET['scan_id']) ? (int) $_GET['scan_id'] : 0;
if ($scan_id <= 0) {
    header('Location: /ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Frontend/historical_scans.php');
    exit;
}
$currentUserId = $_SESSION['user_uid'] ?? $_SESSION['user_id'];
$currentUserName = $_SESSION['user_name'] ?? (string) $currentUserId;
$profile_photo = $_SESSION['profile_photo'] ?? null;
if (!empty($profile_photo)) {
    $photo_path = ltrim((string) $profile_photo, '/');
    $base_url = '/ScanQuotient.v2/ScanQuotient.B';
    $avatar_url = $base_url . '/' . $photo_path;
} else {
    $avatar_url = '/ScanQuotient.v2/ScanQuotient.B/Storage/Public_images/default-avatar.png';
}
$historyPageUrl = '/ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Frontend/historical_scans.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enterprise AI Overview | ScanQuotient</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --bg-main: #f0f7ff;
            --bg-sidebar: #fff;
            --bg-card: #fff;
            --text-main: #1e293b;
            --text-light: #64748b;
            --brand-color: #3b82f6;
            --brand-light: #a78bfa;
            --border-color: #e2e8f0;
            --danger-color: #ef4444;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        body.dark {
            --bg-main: #0f0f1a;
            --bg-sidebar: #1a1a2e;
            --bg-card: #1e1b4b;
            --text-main: #f1f5f9;
            --text-light: #94a3b8;
            --brand-color: #8b5cf6;
            --border-color: rgba(139, 92, 246, 0.2);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Inter, sans-serif;
            background: var(--bg-main);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Transparent glass header so scrolled content blurs underneath */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 32px;
            background: rgba(255, 255, 255, 0.16);
            border-bottom: 1px solid rgba(255, 255, 255, 0.28);
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 120;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }

        body.dark .page-header {
            background: rgba(15, 23, 42, 0.22);
            border-bottom-color: rgba(148, 163, 184, 0.28);
        }

        .header-left {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .header-brand {
            font-weight: 800;
            font-size: 24px;
            color: #8b5cf6;
            text-decoration: none;
            letter-spacing: -0.5px;
        }

        body.dark .header-brand {
            color: var(--brand-light);
        }

        .header-tagline {
            font-size: 12px;
            color: var(--text-light);
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        .welcome-text {
            font-size: 14px;
            color: var(--text-main);
            font-weight: 600;
            margin-right: 16px;
        }

        /* Dark mode: welcome text black */
        body.dark .welcome-text {
            color: #000000;
        }

        .header-right {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .header-profile-photo {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(59, 130, 246, 0.35);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.12);
        }

        /* Light mode: floating blue icons, no background */
        .icon-btn {
            color: #3B82F6;
            font-size: 18px;
            text-decoration: none;
            transition: all 0.3s ease;
            background: transparent;
            border: none;
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            position: relative;
        }

        /* Dark mode: subtle background for visibility */
        body.dark .icon-btn {
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border-color);
            color: var(--text-light);
        }

        .icon-btn:hover {
            background: var(--brand-color);
            color: white;
            border-color: var(--brand-color);
            transform: translateY(-2px);
        }

        body.dark .icon-btn:hover {
            background: var(--brand-color);
            box-shadow: 0 0 15px rgba(139, 92, 246, 0.4);
        }

        .app {
            display: flex;
            flex: 1;
        }

        .sun-icon {
            display: none;
        }

        .moon-icon {
            display: inline-block;
        }

        body.dark .sun-icon {
            display: inline-block;
        }

        body.dark .moon-icon {
            display: none;
        }

        .theme-toggle.icon-btn {
            border: none;
            background: transparent;
        }

        .main {
            flex: 1;
            padding: 32px;
            overflow-y: auto;
        }

        /* Enterprise AI Content Styles */
        .ai-container {
            max-width: 1160px;
            margin: 0 auto;
        }

        .top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            gap: 12px;
            flex-wrap: wrap;
        }

        .back {
            color: var(--text-main);
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            padding: 10px 14px;
            border-radius: 10px;
        }

        .back:hover {
            color: var(--brand-color);
            border-color: var(--brand-color);
        }

        .page-title {
            font-size: 24px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-subtitle {
            color: var(--text-light);
            font-size: 14px;
            margin-bottom: 24px;
        }

        .ai-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .ai-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            min-height: 100%;
        }

        .ai-card h2 {
            font-size: 16px;
            margin-bottom: 12px;
            color: var(--brand-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ai-card p,
        .ai-card ul {
            font-size: 14px;
            line-height: 1.7;
            color: var(--text-main);
        }

        .ai-card ul {
            padding-left: 20px;
        }

        .ai-card li {
            margin-bottom: 6px;
        }

        #cardSummary {
            grid-column: 1 / -1;
        }

        #cardAsk {
            grid-column: 1 / -1;
        }

        .loading {
            color: var(--text-light);
            font-style: italic;
        }

        .ask-row {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }

        .ask-row input[type="text"] {
            flex: 1;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--bg-main);
            color: var(--text-main);
            font-size: 14px;
        }

        .ask-row button {
            padding: 12px 20px;
            border-radius: 10px;
            border: none;
            background: var(--brand-color);
            color: white;
            font-weight: 600;
            cursor: pointer;
        }

        .ask-row button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .ask-toolbar {
            display: flex;
            justify-content: flex-end;
            margin-top: 10px;
            min-height: 36px;
        }

        .clear-chat-btn {
            border: 1px solid var(--border-color);
            background: var(--bg-main);
            color: var(--text-light);
            padding: 8px 14px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all .22s ease;
        }

        .clear-chat-btn:hover {
            border-color: var(--danger-color);
            color: #fff;
            background: var(--danger-color);
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(239, 68, 68, 0.22);
        }

        .clear-chat-btn.hidden {
            display: none !important;
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.52);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 3200;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-card {
            width: min(92vw, 460px);
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            box-shadow: 0 24px 56px rgba(2, 6, 23, 0.28);
            overflow: hidden;
        }

        .modal-head {
            padding: 16px 18px 10px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-head .warn-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(239, 68, 68, 0.12);
            color: var(--danger-color);
        }

        .modal-title {
            font-size: 16px;
            font-weight: 700;
        }

        .modal-body {
            padding: 16px 18px;
            color: var(--text-light);
            font-size: 14px;
            line-height: 1.55;
        }

        .modal-actions {
            padding: 0 18px 16px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .modal-btn {
            border: 1px solid var(--border-color);
            border-radius: 9px;
            padding: 9px 14px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            background: var(--bg-main);
            color: var(--text-main);
        }

        .modal-btn.primary-danger {
            background: var(--danger-color);
            border-color: var(--danger-color);
            color: #fff;
        }

        .chat {
            margin-top: 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .msg {
            max-width: 92%;
            padding: 12px 14px;
            border-radius: 14px;
            font-size: 14px;
            line-height: 1.6;
            white-space: pre-wrap;
            border: 1px solid var(--border-color);
        }

        .msg.user {
            align-self: flex-end;
            background: rgba(59, 130, 246, 0.15);
        }

        .msg.ai {
            align-self: flex-start;
            background: var(--bg-main);
        }

        body.dark .msg.ai {
            background: rgba(0, 0, 0, 0.18);
        }

        .msg .meta {
            font-size: 11px;
            color: var(--text-light);
            margin-bottom: 6px;
        }

        .warn {
            display: none;
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(239, 68, 68, 0.4);
            background: rgba(239, 68, 68, 0.08);
            color: #ef4444;
            font-size: 13px;
        }

        body.dark .warn {
            border: 1px solid rgba(248, 113, 113, 0.4);
            background: rgba(248, 113, 113, 0.08);
            color: #fecaca;
        }

        /* Footer */
        .page-footer {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            align-items: center;
            padding: 20px 32px;
            font-size: 13px;
            color: #8b5cf6;
            background: linear-gradient(to right, #ffffff, #ADD8E6);
            border-top: 1px solid var(--border-color);
            margin-top: auto;
        }

        /* Dark mode: same gradient as header */
        body.dark .page-footer {
            background: linear-gradient(to right, #000000, #D6BCFA);
        }

        .footer-left {
            text-align: left;
        }

        .footer-center {
            text-align: center;
        }

        .footer-right {
            text-align: right;
        }

        .footer-brand {
            font-weight: 700;
            color: #8b5cf6;
            text-decoration: none;
        }

        .footer-sub {
            margin-left: 8px;
            opacity: 0.7;
        }

        body.dark .footer-brand {
            color: var(--brand-light);
        }

        /* Back to Top Button */
        .back-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #22c55e;
            /* Green circle in light mode */
            color: white;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.4);
            transition: all 0.3s ease;
            opacity: 0;
            visibility: hidden;
            z-index: 1000;
        }

        .back-to-top.visible {
            opacity: 1;
            visibility: visible;
        }

        .back-to-top:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.6);
            background: #16a34a;
        }

        /* Dark mode back to top */
        body.dark .back-to-top {
            background: #8b5cf6;
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.4);
        }

        body.dark .back-to-top:hover {
            background: #7c3aed;
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.6);
        }

        .back-to-top i {
            animation: bounce 2s infinite;
        }

        @keyframes bounce {

            0%,
            20%,
            50%,
            80%,
            100% {
                transform: translateY(0);
            }

            40% {
                transform: translateY(-5px);
            }

            60% {
                transform: translateY(-3px);
            }
        }

        @media (max-width: 900px) {
            .ai-grid {
                grid-template-columns: 1fr;
            }

            #cardSummary,
            #cardAsk {
                grid-column: auto;
            }
        }
    </style>
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
            <button type="button" class="theme-toggle icon-btn" id="themeToggle" aria-label="Toggle theme"
                title="Toggle theme">
                <i class="fas fa-sun sun-icon"></i>
                <i class="fas fa-moon moon-icon"></i>
            </button>
            <a href="/ScanQuotient.v2/ScanQuotient.B/Private/User_dashboard/PHP/Frontend/User_dashboard.php"
                class="icon-btn" title="My Profile"><i class="fas fa-home"></i></a>
            <a href="../../../../Public/Login_page/PHP/Frontend/Login_page_site.php" class="icon-btn" title="Logout"><i
                    class="fas fa-sign-out-alt"></i></a>
        </div>
    </header>
    <div class="app">
        <main class="main">
            <div class="ai-container">
                <div class="top-bar">
                    <a href="javascript:history.back()" class="back"><i class="fas fa-arrow-left"></i> Back</a>
                </div>
                <h1 class="page-title"><i class="fas fa-brain"></i> Enterprise AI Overview</h1>
                <p class="page-subtitle">Scan #<span id="scanId">
                        <?php echo (int) $scan_id; ?>
                    </span> — AI-powered
                    insights</p>

                <div class="ai-grid">
                    <div class="ai-card" id="cardSummary">
                        <h2><i class="fas fa-briefcase"></i> Executive Summary</h2>
                        <p id="execSummary" class="loading">Loading…</p>
                    </div>
                    <div class="ai-card" id="cardRisks">
                        <h2><i class="fas fa-exclamation-triangle"></i> Top 3 Risks</h2>
                        <ul id="topRisks">
                            <li class="loading">Loading…</li>
                        </ul>
                    </div>
                    <div class="ai-card" id="cardCompliance">
                        <h2><i class="fas fa-shield-alt"></i> Compliance Snapshot</h2>
                        <p id="complianceText" class="loading">Loading…</p>
                    </div>
                    <div class="ai-card" id="cardAsk">
                        <h2><i class="fas fa-question-circle"></i> Ask about this scan</h2>
                        <p style="color: var(--text-light); font-size: 13px; margin-bottom: 12px;">Ask a question about
                            this
                            scan or web security (max 80 words).</p>
                        <div id="chat" class="chat"></div>
                        <div class="ask-row">
                            <input type="text" id="askInput" placeholder="e.g. What should we fix first?"
                                maxlength="500" />
                            <button type="button" id="askBtn">Ask</button>
                        </div>
                        <div class="ask-toolbar">
                            <button type="button" id="clearChatBtn" class="clear-chat-btn hidden"><i
                                    class="fas fa-trash"></i>Clear chat</button>
                        </div>
                        <div id="askWarn" class="warn"></div>
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

    <!-- Back to Top Button -->
    <button type="button" class="back-to-top" id="backToTop" aria-label="Back to top">
        <i class="fas fa-arrow-up"></i>
    </button>

    <div class="modal-overlay" id="clearChatModal" role="dialog" aria-modal="true" aria-labelledby="clearChatTitle">
        <div class="modal-card">
            <div class="modal-head">
                <span class="warn-icon"><i class="fas fa-trash-alt"></i></span>
                <div class="modal-title" id="clearChatTitle">Clear saved chat?</div>
            </div>
            <div class="modal-body">
                This will remove the saved conversation for this scan from this browser.
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-btn" id="clearChatCancelBtn">Cancel</button>
                <button type="button" class="modal-btn primary-danger" id="clearChatConfirmBtn">Clear chat</button>
            </div>
        </div>
    </div>

    <script>
        // Date and Time
        (function () {
            var d = new Date();
            var el = document.getElementById('current-date');
            if (el) el.textContent = d.toLocaleDateString();
            var t = document.getElementById('current-time');
            if (t) setInterval(function () { t.textContent = new Date().toLocaleTimeString(); }, 1000);
        })();

        // Theme Toggle Logic
        (function () {
            const themeToggle = document.getElementById('themeToggle');
            const body = document.body;

            // Check for saved theme preference or default to light mode
            const currentTheme = localStorage.getItem('theme') || 'light';

            // Apply saved theme on load
            if (currentTheme === 'dark') {
                body.classList.add('dark');
            }

            // Toggle theme function
            themeToggle.addEventListener('click', function () {
                body.classList.toggle('dark');

                // Save preference
                if (body.classList.contains('dark')) {
                    localStorage.setItem('theme', 'dark');
                } else {
                    localStorage.setItem('theme', 'light');
                }
            });
        })();

        // Back to Top Button Logic
        (function () {
            const backToTopBtn = document.getElementById('backToTop');
            const main = document.querySelector('.main');

            // Show/hide button based on scroll position
            function toggleBackToTop() {
                if (main.scrollTop > 300) {
                    backToTopBtn.classList.add('visible');
                } else {
                    backToTopBtn.classList.remove('visible');
                }
            }

            // Listen for scroll on main content area
            main.addEventListener('scroll', toggleBackToTop);

            // Scroll to top when clicked
            backToTopBtn.addEventListener('click', function () {
                main.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });
        })();

        // Enterprise AI API Logic
        (function () {
            var scanId = <?php echo (int) $scan_id; ?>;
            document.getElementById('scanId').textContent = scanId;
            var chatEl = document.getElementById('chat');
            var chatStorageKey = 'enterprise_ai_chat_' + String(scanId);
            var trackUrl = '../Backend/track_enterprise_ai_event.php';

            function trackEvent(eventType, meta) {
                var body = {
                    scan_id: scanId,
                    event_type: eventType
                };
                if (meta && typeof meta === 'object') body.meta = meta;
                fetch(trackUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                }).catch(function () { });
            }

            function cleanAiText(text) {
                var s = (text || '').toString();
                // Remove markdown emphasis markers like **text** and *text*
                s = s.replace(/\*\*(.*?)\*\*/g, '$1');
                s = s.replace(/\*(.*?)\*/g, '$1');
                // Remove any leftover asterisks used as bullets/emphasis
                s = s.replace(/\*/g, '');
                return s;
            }

            function esc(text) {
                return (text || '').toString().replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }

            function appendChatMessage(role, text) {
                if (!chatEl) return;
                var msg = document.createElement('div');
                msg.className = role === 'user' ? 'msg user' : 'msg ai';
                var label = role === 'user' ? 'You' : 'Enterprise AI';
                msg.innerHTML = '<div class="meta">' + label + '</div>' + esc(text);
                chatEl.appendChild(msg);
                chatEl.scrollTop = chatEl.scrollHeight;
            }

            function saveChatHistory() {
                if (!chatEl) return;
                var items = [];
                chatEl.querySelectorAll('.msg').forEach(function (node) {
                    var isUser = node.classList.contains('user');
                    var meta = node.querySelector('.meta');
                    var content = node.textContent || '';
                    if (meta && content.indexOf(meta.textContent) === 0) {
                        content = content.slice(meta.textContent.length).trim();
                    } else {
                        content = content.trim();
                    }
                    if (!content) return;
                    items.push({ role: isUser ? 'user' : 'ai', text: content });
                });
                try {
                    localStorage.setItem(chatStorageKey, JSON.stringify(items));
                } catch (e) { }
                syncClearChatVisibility();
            }

            function loadChatHistory() {
                if (!chatEl) return;
                try {
                    var raw = localStorage.getItem(chatStorageKey);
                    if (!raw) return;
                    var arr = JSON.parse(raw);
                    if (!Array.isArray(arr) || !arr.length) return;
                    chatEl.innerHTML = '';
                    arr.forEach(function (item) {
                        if (!item || !item.text) return;
                        appendChatMessage(item.role === 'user' ? 'user' : 'ai', item.text);
                    });
                } catch (e) { }
                syncClearChatVisibility();
            }

            function syncClearChatVisibility() {
                if (!clearBtn || !chatEl) return;
                var hasMessages = chatEl.querySelectorAll('.msg').length > 0;
                clearBtn.classList.toggle('hidden', !hasMessages);
            }

            loadChatHistory();
            syncClearChatVisibility();
            trackEvent('page_view');

            var clearModal = document.getElementById('clearChatModal');
            var clearBtn = document.getElementById('clearChatBtn');
            var clearCancelBtn = document.getElementById('clearChatCancelBtn');
            var clearConfirmBtn = document.getElementById('clearChatConfirmBtn');
            syncClearChatVisibility();

            function openClearModal() {
                if (!clearModal) return;
                clearModal.classList.add('active');
            }
            function closeClearModal() {
                if (!clearModal) return;
                clearModal.classList.remove('active');
            }

            clearBtn?.addEventListener('click', openClearModal);
            clearCancelBtn?.addEventListener('click', closeClearModal);
            clearModal?.addEventListener('click', function (e) {
                if (e.target === clearModal) closeClearModal();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && clearModal?.classList.contains('active')) closeClearModal();
            });

            clearConfirmBtn?.addEventListener('click', function () {
                if (chatEl) chatEl.innerHTML = '';
                try { localStorage.removeItem(chatStorageKey); } catch (e) { }
                var warnEl = document.getElementById('askWarn');
                if (warnEl) warnEl.style.display = 'none';
                var inputEl = document.getElementById('askInput');
                if (inputEl) {
                    inputEl.value = '';
                    inputEl.focus();
                }
                trackEvent('clear_chat');
                syncClearChatVisibility();
                closeClearModal();
            });

            function api(action, extra) {
                var body = { action: action, scan_id: scanId };
                if (extra) for (var k in extra) body[k] = extra[k];
                return fetch('../Backend/enterprise_ai_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                }).then(function (r) { return r.json(); });
            }

            api('overview').then(function (res) {
                if (!res.ok) {
                    document.getElementById('execSummary').textContent = res.error || 'Failed to load';
                    document.getElementById('execSummary').className = '';
                    return;
                }
                var o = res.overview || {};
                document.getElementById('execSummary').textContent = o.executive_summary || '—';
                document.getElementById('execSummary').className = '';
                var risks = o.top_3_risks || [];
                var ul = document.getElementById('topRisks');
                ul.innerHTML = risks.length ? risks.map(function (r) { return '<li>' + r + '</li>'; }).join('') : '<li>No specific risks listed.</li>';
                document.getElementById('complianceText').textContent = o.compliance_snapshot || '—';
                document.getElementById('complianceText').className = '';
            }).catch(function () {
                document.getElementById('execSummary').textContent = 'Request failed.';
                document.getElementById('execSummary').className = '';
            });

            document.getElementById('askBtn').addEventListener('click', function () {
                var inputEl = document.getElementById('askInput');
                var q = inputEl.value.trim();
                if (!q) return;
                var btn = document.getElementById('askBtn');
                var warnEl = document.getElementById('askWarn');
                var chat = document.getElementById('chat');
                inputEl.value = '';
                warnEl.style.display = 'none';
                btn.disabled = true;
                appendChatMessage('user', q);
                saveChatHistory();
                trackEvent('ask_submitted', { question_length: q.length });
                api('ask', { question: q }).then(function (res) {
                    btn.disabled = false;
                    inputEl.focus();
                    if (res.ok) {
                        var a = cleanAiText((res.answer || '').toString());
                        appendChatMessage('ai', a);
                        saveChatHistory();
                        trackEvent('ask_success', { answer_length: a.length });
                    } else {
                        var rawErr = (res.error || 'Failed').toString();
                        if (rawErr.toLowerCase().indexOf('please ask about web security or this scan') !== -1) {
                            rawErr = 'Please ask a scan-specific or web security question (for example: "What should I fix first?" or "How do I mitigate the top risk?").';
                        }
                        warnEl.textContent = rawErr;
                        warnEl.style.display = 'block';
                        trackEvent('ask_error', { message: rawErr.slice(0, 200) });
                    }
                }).catch(function () {
                    btn.disabled = false;
                    inputEl.focus();
                    warnEl.textContent = 'Request failed';
                    warnEl.style.display = 'block';
                    trackEvent('ask_error', { message: 'Request failed' });
                });
            });
        })();
    </script>
</body>

</html>