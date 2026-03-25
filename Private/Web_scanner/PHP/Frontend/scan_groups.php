<?php
session_start();
if (!isset($_SESSION['user_id']) && !isset($_SESSION['user_uid'])) {
    header('Location: /ScanQuotient.v2/ScanQuotient.B/Public/Login_page/PHP/Frontend/Login_page_site.php');
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
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

$groups = [];
$historyPageUrl = '/ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Frontend/historical_scans.php';
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS scan_groups (id INT AUTO_INCREMENT PRIMARY KEY, user_id VARCHAR(64) NOT NULL, name VARCHAR(128) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt = $pdo->prepare("SELECT g.id, g.name, g.created_at, COUNT(r.id) AS scan_count FROM scan_groups g LEFT JOIN scan_results r ON r.group_id = g.id AND r.user_id = g.user_id WHERE g.user_id = ? GROUP BY g.id ORDER BY g.name");
    $stmt->execute([$currentUserId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $groups = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan Groups | ScanQuotient</title>
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

        .sidebar {
            width: 260px;
            background: var(--bg-sidebar);
            border-right: 1px solid var(--border-color);
            padding: 24px 16px;
            display: flex;
            flex-direction: column;
        }

        .brand {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-light);
            text-transform: uppercase;
            margin-bottom: 24px;
        }

        nav {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
        }

        .nav-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 10px;
            color: var(--text-light);
            text-decoration: none;
            font-size: 14px;
            border: none;
            background: transparent;
            cursor: pointer;
            width: 100%;
            text-align: left;
        }

        .nav-btn:hover {
            background: rgba(59, 130, 246, 0.1);
            color: var(--brand-color);
        }

        .nav-btn.active {
            background: var(--brand-color);
            color: white;
            font-weight: 600;
        }

        .nav-divider {
            height: 1px;
            background: var(--border-color);
            margin: 12px 0;
        }

        /* Theme Toggle Styles */
        .theme-toggle-container {
            margin-top: auto;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }

        .theme-toggle {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 10px;
            color: var(--text-light);
            text-decoration: none;
            font-size: 14px;
            border: none;
            background: transparent;
            cursor: pointer;
            width: 100%;
            text-align: left;
            transition: all 0.3s ease;
        }

        .theme-toggle:hover {
            background: rgba(59, 130, 246, 0.1);
            color: var(--brand-color);
        }

        .theme-icon-wrapper {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .theme-icon {
            font-size: 20px;
            transition: all 0.3s ease;
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

        body.dark .theme-toggle:hover {
            background: rgba(139, 92, 246, 0.1);
        }

        .main {
            flex: 1;
            padding: 32px;
            overflow-y: auto;
        }

        .history-container {
            max-width: 900px;
            margin: 0 auto;
        }

        .history-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
        }

        .history-header {
            padding: 24px;
            border-bottom: 1px solid var(--border-color);
        }

        .history-title {
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .group-list {
            padding: 24px;
        }

        .group-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            margin-bottom: 12px;
            background: var(--bg-main);
        }

        .group-item:hover {
            border-color: var(--brand-color);
        }

        .group-info h4 {
            font-size: 16px;
            margin-bottom: 4px;
        }

        .group-info p {
            font-size: 13px;
            color: var(--text-light);
        }

        .group-actions {
            display: flex;
            gap: 10px;
        }

        .group-actions a,
        .group-actions button {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            text-decoration: none;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-main);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .group-actions a:hover {
            background: var(--brand-color);
            color: white;
            border-color: var(--brand-color);
        }

        .group-actions button.dismantle {
            border-color: var(--danger-color);
            color: var(--danger-color);
        }

        .group-actions button.dismantle:hover {
            background: var(--danger-color);
            color: white;
        }

        .empty-groups {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-light);
        }

        .empty-groups a {
            color: var(--brand-color);
            text-decoration: none;
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
            background: linear-gradient(135deg, #22c55e, #16a34a);
            /* Green circle in light mode */
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.28);
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
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.4);
        }

        body.dark .back-to-top:hover {
            background: #7c3aed;
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.6);
        }

        .back-to-top i {
            animation: bounce 2s infinite;
            font-size: 18px;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.22);
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 3000;
            backdrop-filter: blur(2px);
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-card {
            width: min(92vw, 460px);
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            box-shadow: 0 22px 50px rgba(2, 6, 23, 0.28);
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

        .modal-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
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
    </style>
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
            <a href="/ScanQuotient.v2/ScanQuotient.B/Private/User_dashboard/PHP/Frontend/User_dashboard.php"
                class="icon-btn" title="My Profile"><i class="fas fa-home"></i></a>
            <a href="../../../../Public/Login_page/PHP/Frontend/Login_page_site.php" class="icon-btn" title="Logout"><i
                    class="fas fa-sign-out-alt"></i></a>
        </div>
    </header>
    <div class="app">
        <aside class="sidebar">
            <div class="brand">Navigation</div>
            <nav>
                <a href="/ScanQuotient.v2/ScanQuotient.B/Private/User_dashboard/PHP/Frontend/User_dashboard.php"
                    class="nav-btn"><i class="fas fa-home"></i><span>Dashboard</span></a>
                <a href="/ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Frontend/scan.php" class="nav-btn"><i
                        class="fas fa-shield-alt"></i><span>New Scan</span></a>
                <a href="<?php echo htmlspecialchars($historyPageUrl); ?>" class="nav-btn"><i
                        class="fas fa-history"></i><span>History</span></a>
                <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="nav-btn active"><i
                        class="fas fa-layer-group"></i><span>Groups</span></a>
                <div class="nav-divider"></div>
                <a href="/ScanQuotient.v2/ScanQuotient.B/Public/Help_center/PHP/Frontend/Help_center.php"
                    class="nav-btn"><i class="fas fa-headset"></i><span>Help Center</span></a>
                <a href="/ScanQuotient.v2/ScanQuotient.B/Private/User_account/PHP/Frontend/User_subscription.php"
                    class="nav-btn"><i class="fas fa-user-cog"></i><span>Account</span></a>
            </nav>

            <!-- Theme Toggle in Sidebar -->
            <div class="theme-toggle-container">
                <button type="button" class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
                    <div class="theme-icon-wrapper">
                        <i class="fas fa-sun theme-icon sun-icon"></i>
                        <i class="fas fa-moon theme-icon moon-icon"></i>
                    </div>
                    <span class="theme-text" id="themeToggleText">Theme</span>
                </button>
            </div>
        </aside>
        <main class="main">
            <div class="history-container">
                <div class="history-card">
                    <div class="history-header">
                        <div class="history-title"><i class="fas fa-layer-group"></i> Scan Groups</div>
                    </div>
                    <div class="create-group-bar"
                        style="padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <span style="font-size: 14px; font-weight: 600;">Create new group:</span>
                        <input type="text" id="newGroupName" placeholder="e.g. example.com retests"
                            style="padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border-color); width: 220px; background: var(--bg-main); color: var(--text-main);">
                        <button type="button" id="createGroupBtn"
                            style="padding: 8px 16px; border-radius: 8px; border: none; background: var(--brand-color); color: white; font-weight: 600; cursor: pointer;"><i
                                class="fas fa-plus"></i> Create</button>
                    </div>
                    <div class="group-list">
                        <?php if (empty($groups)): ?>
                            <div class="empty-groups">
                                <p>You have no groups yet.</p>
                                <p>Create a group from <a
                                        href="<?php echo htmlspecialchars($historyPageUrl); ?>">History</a> by assigning
                                    scans to a new group.
                                </p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($groups as $g): ?>
                                <div class="group-item" data-group-id="<?php echo (int) $g['id']; ?>">
                                    <div class="group-info">
                                        <h4><?php echo htmlspecialchars($g['name']); ?></h4>
                                        <p><?php echo (int) $g['scan_count']; ?> scan(s) &nbsp; Created:
                                            <?php echo htmlspecialchars($g['created_at'] ?? ''); ?>
                                        </p>
                                    </div>
                                    <div class="group-actions">
                                        <a
                                            href="<?php echo htmlspecialchars($historyPageUrl . '?group_id=' . (int) $g['id']); ?>"><i
                                                class="fas fa-eye"></i> View</a>
                                        <button type="button" class="dismantle" data-group-id="<?php echo (int) $g['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($g['name']); ?>"><i
                                                class="fas fa-ungroup"></i> Dismantle</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
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

    <div class="modal-overlay" id="dismantleModal" role="dialog" aria-modal="true" aria-labelledby="dismantleTitle">
        <div class="modal-card">
            <div class="modal-head">
                <span class="warn-icon"><i class="fas fa-triangle-exclamation"></i></span>
                <div class="modal-title" id="dismantleTitle">Dismantle scan group</div>
            </div>
            <div class="modal-body" id="dismantleMessage">
                Are you sure you want to dismantle this group? Scans will be ungrouped.
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-btn" id="dismantleCancelBtn">Cancel</button>
                <button type="button" class="modal-btn primary-danger" id="dismantleConfirmBtn">Dismantle</button>
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
            const themeToggleText = document.getElementById('themeToggleText');
            const body = document.body;

            // Check for saved theme preference or default to light mode
            const currentTheme = localStorage.getItem('theme') || 'light';

            function syncThemeLabel() {
                if (!themeToggleText) return;
                themeToggleText.textContent = body.classList.contains('dark') ? 'Dark Theme' : 'Light Theme';
            }

            // Apply saved theme on load
            if (currentTheme === 'dark') {
                body.classList.add('dark');
            }
            syncThemeLabel();

            // Toggle theme function
            themeToggle.addEventListener('click', function () {
                body.classList.toggle('dark');

                // Save preference
                if (body.classList.contains('dark')) {
                    localStorage.setItem('theme', 'dark');
                } else {
                    localStorage.setItem('theme', 'light');
                }
                syncThemeLabel();
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

        // Dismantle Group (styled modal)
        (function () {
            var modal = document.getElementById('dismantleModal');
            var msgEl = document.getElementById('dismantleMessage');
            var cancelBtn = document.getElementById('dismantleCancelBtn');
            var confirmBtn = document.getElementById('dismantleConfirmBtn');
            var activeBtn = null;
            var activeGroupId = null;

            function closeModal() {
                if (!modal) return;
                modal.classList.remove('active');
                activeBtn = null;
                activeGroupId = null;
                if (confirmBtn) confirmBtn.disabled = false;
                if (cancelBtn) cancelBtn.disabled = false;
            }

            function openModal(groupId, groupName, triggerBtn) {
                activeGroupId = parseInt(groupId, 10);
                activeBtn = triggerBtn || null;
                if (msgEl) {
                    msgEl.textContent = 'Dismantle group "' + (groupName || '') + '"? Scans in this group will be ungrouped.';
                }
                modal.classList.add('active');
            }

            document.querySelectorAll('.group-actions button.dismantle').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id = this.getAttribute('data-group-id');
                    var name = this.getAttribute('data-name');
                    openModal(id, name, this);
                });
            });

            cancelBtn?.addEventListener('click', closeModal);
            modal?.addEventListener('click', function (e) {
                if (e.target === modal) closeModal();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modal?.classList.contains('active')) closeModal();
            });

            confirmBtn?.addEventListener('click', function () {
                if (!activeGroupId) return;
                if (confirmBtn) confirmBtn.disabled = true;
                if (cancelBtn) cancelBtn.disabled = true;
                if (activeBtn) activeBtn.disabled = true;
                fetch('../Backend/dismantle_group.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ group_id: activeGroupId })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data.ok) {
                        window.location.reload();
                    } else {
                        if (activeBtn) activeBtn.disabled = false;
                        if (msgEl) msgEl.textContent = data.error || 'Failed to dismantle group.';
                        if (confirmBtn) confirmBtn.disabled = false;
                        if (cancelBtn) cancelBtn.disabled = false;
                    }
                }).catch(function () {
                    if (activeBtn) activeBtn.disabled = false;
                    if (msgEl) msgEl.textContent = 'Request failed. Please try again.';
                    if (confirmBtn) confirmBtn.disabled = false;
                    if (cancelBtn) cancelBtn.disabled = false;
                });
            });
        })();

        // Create Group
        document.getElementById('createGroupBtn')?.addEventListener('click', function () {
            var inp = document.getElementById('newGroupName');
            var name = inp ? inp.value.trim() : '';
            if (!name) { alert('Enter a group name'); return; }
            var btn = document.getElementById('createGroupBtn');
            if (btn) btn.disabled = true;
            fetch('../Backend/create_scan_group.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: name })
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (btn) btn.disabled = false;
                if (data.ok) { if (inp) inp.value = ''; window.location.reload(); }
                else alert(data.error || 'Failed to create group');
            }).catch(function () { if (btn) btn.disabled = false; alert('Request failed'); });
        });
    </script>
</body>

</html>