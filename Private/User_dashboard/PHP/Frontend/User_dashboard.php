<?php
// user_dashboard.php
session_start();

$allowed_roles = ['user', 'admin', 'super_admin'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../../../../Public/Login_page/PHP/Frontend/Login_page_site.php");
    exit();
}

$user_name = $_SESSION['user_name'] ?? 'User';
$userRole = $_SESSION['role'] ?? 'user';

// Fix profile photo path - ensure it starts with / and includes full path
$profile_photo = $_SESSION['profile_photo'] ?? null;

if (!empty($profile_photo)) {
    // Remove any leading slashes to avoid double slashes
    $photo_path = ltrim($profile_photo, '/');

    // Build full URL path from your project root
    // Adjust this base path to match your project structure
    $base_url = '/ScanQuotient.v2/ScanQuotient.B';

    // Check if path already contains the base
    if (strpos($photo_path, 'Storage/') === 0) {
        $avatar_url = $base_url . '/' . $photo_path;
    } else {
        $avatar_url = $base_url . '/' . $photo_path;
    }
} else {
    // Default avatar path
    $avatar_url = '/ScanQuotient.v2/ScanQuotient.B/Storage/Public_images/default-avatar.png';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScanQuotient | Dashboard</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../CSS/user_dashboard.css" />
</head>

<body>


    <header class="page-header">
        <div class="header-left">
            <a href="#" class="header-brand">ScanQuotient</a>
            <span class="header-tagline">Quantifying Risk. Strengthening Security.</span>
        </div>
        <div class="header-right">
            <!-- Profile Photo -->
            <img src="<?php echo $avatar_url; ?>" alt="Profile" class="header-profile-photo">

            <span class="welcome-text">
                Welcome,
                <?php echo htmlspecialchars($user_name); ?> |
                <span id="current-date"></span> |
                <span id="current-time"></span>
            </span>

            <button id="helpBtn" class="icon-btn" title="Help"><i class="fas fa-question-circle"></i></button>
            <a href="/ScanQuotient.v2/ScanQuotient.B/Private/My_profile/PHP/Frontend/My_profile.php" class="icon-btn"
                title="My Profile"><i class="fas fa-user"></i></a>
            <a href="../../../../Public/Login_page/PHP/Frontend/Login_page_site.php" class="icon-btn" title="Logout"><i
                    class="fas fa-sign-out-alt"></i></a>
        </div>
    </header>
    <div id="helpModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-shield-alt" style="margin-right: 8px; color: var(--brand-color);"></i>
                    ScanQuotient Platform Overview
                </h3>
                <button class="close">&times;</button>
            </div>

            <div class="modal-body">
                <ul class="info-list">

                    <li>
                        <i class="fas fa-home"></i>
                        <div>
                            <strong>Home Dashboard</strong>
                            <span>Displays real-time system and network information about your device.</span>
                        </div>
                    </li>

                    <li>
                        <i class="fas fa-search"></i>
                        <div>
                            <strong>New Scan</strong>
                            <span>Start a comprehensive vulnerability assessment of a website or application.</span>
                        </div>
                    </li>

                    <li>
                        <i class="fas fa-history"></i>
                        <div>
                            <strong>Scan History</strong>
                            <span>View and review previously completed security scans and results.</span>
                        </div>
                    </li>

                    <li>
                        <i class="fas fa-user-circle"></i>
                        <div>
                            <strong>Account Management</strong>
                            <span>Manage your profile, session details, and security settings.</span>
                        </div>
                    </li>

                    <li> <i class="fas fa-question-circle"></i>
                        <div> <strong>Help Center</strong> <span>Learn how to use ScanQuotient and understand scan
                                results.</span> </div>
                    </li>

                </ul>
            </div>
        </div>
    </div>

    <div class="app">
        <!-- Original Sidebar Preserved -->
        <aside class="sidebar">
            <div class="brand">Navigation</div>
            <nav>
                <a href="#" class="nav-btn active"><i class="fas fa-home"></i><span>Dashboard</span></a>
                <a href="/ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Frontend/scan.php" class="nav-btn"><i
                        class="fas fa-plus-circle"></i><span>New Scan</span></a>
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
            <div class="dashboard-grid">

                <!-- Top Card: Intelligence with Internal Sidebar -->
                <div class="intelligence-card">
                    <!-- Internal Sidebar -->
                    <div class="intel-sidebar">
                        <button class="intel-sidebar-btn active" onclick="switchCategory(0)">
                            <i class="fas fa-network-wired"></i>Network
                        </button>
                        <button class="intel-sidebar-btn" onclick="switchCategory(1)">
                            <i class="fas fa-fingerprint"></i>Browser
                        </button>
                        <button class="intel-sidebar-btn" onclick="switchCategory(2)">
                            <i class="fas fa-lock"></i>Security
                        </button>
                        <button class="intel-sidebar-btn" onclick="switchCategory(3)">
                            <i class="fas fa-microchip"></i>System
                        </button>
                    </div>

                    <!-- Content Area -->
                    <div class="intel-content-area">
                        <div class="intel-header">
                            <div class="intel-title" id="intelTitle">
                                <i class="fas fa-network-wired"></i>Network Intelligence
                            </div>
                        </div>

                        <button class="carousel-arrow prev" onclick="changeSlide(-1)"><i
                                class="fas fa-chevron-left"></i></button>
                        <button class="carousel-arrow next" onclick="changeSlide(1)"><i
                                class="fas fa-chevron-right"></i></button>

                        <div class="carousel-container" id="carouselContainer">
                            <!-- Slides injected by JS -->
                        </div>
                    </div>
                </div>

                <!-- Bottom Action Card -->
                <div class="action-card">
                    <div class="action-content">

                        <div class="action-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>

                        <h2>Ready to Strengthen Your Security</h2>

                        <p>
                            Your ScanQuotient dashboard is active and ready.
                            You can now begin a new security scan to check your website for
                            potential issues and improve its overall protection.
                        </p>

                        <div class="feature-pills">
                            <span class="feature-pill">
                                <i class="fas fa-bug"></i> Vulnerability Checks
                            </span>

                            <span class="feature-pill">
                                <i class="fas fa-lock"></i> Security Review
                            </span>

                            <span class="feature-pill">
                                <i class="fas fa-cog"></i> System Analysis
                            </span>

                            <span class="feature-pill">
                                <i class="fas fa-history"></i> Scan Records
                            </span>
                        </div>

                        <div class="action-buttons">
                            <a href="/ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Frontend/scan.php"
                                class="btn-primary">
                                <img src="../../../../Storage/Public_images/page_icon.png" alt="" class="action-btn-icon" aria-hidden="true"> Start New Scan
                            </a>

                            <a href="/ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Frontend/historical_scans.php"
                                class="btn-secondary">
                                <i class="fas fa-history"></i> View Scan History
                            </a>
                        </div>

                    </div>
                </div>
            </div>

    </div>
    </main>
    </div>

    <footer class="page-footer">

        <!-- Left -->
        <div class="footer-left">
            <a href="#" class="footer-brand">ScanQuotient</a>
            <p>Quantifying Risk. Strengthening Security.</p>
        </div>

        <!-- Center -->
        <div class="footer-center">
            &copy; <?php echo date("Y"); ?> Authorized Security Testing
        </div>

        <!-- Right -->
        <div class="footer-right">
            Built for Web Security Assessment
        </div>

    </footer>

    <script>
        // Theme Management
        const themeToggle = document.getElementById('themeToggle');
        function setTheme(theme) {
            document.body.classList.toggle('dark', theme === 'dark');
            const icon = theme === 'dark' ? 'fa-moon' : 'fa-sun';
            const text = theme === 'dark' ? 'Dark Mode' : 'Light Mode';
            themeToggle.innerHTML = `<i class="fas ${icon}"></i><span>${text}</span>`;
        }
        themeToggle.addEventListener('click', () => {
            const current = document.body.classList.contains('dark') ? 'light' : 'dark';
            setTheme(current);
            localStorage.setItem('theme', current);
        });
        setTheme(localStorage.getItem('theme') || 'light');

        // Help Modal
        const helpBtn = document.getElementById("helpBtn");
        const helpModal = document.getElementById("helpModal");
        const closeBtn = document.querySelector(".close");
        helpBtn.onclick = () => helpModal.style.display = "flex";
        closeBtn.onclick = () => helpModal.style.display = "none";
        window.onclick = (e) => { if (e.target === helpModal) helpModal.style.display = "none"; }

        // Data Structure
        const categories = [
            {
                title: "Network Intelligence",
                icon: "fa-network-wired",
                items: [
                    { label: "Connection Type", id: "connType", icon: "fa-wifi" },
                    { label: "IP Address", id: "ipAddress", icon: "fa-globe" },
                    { label: "Country", id: "country", icon: "fa-map-marker-alt" },
                    { label: "Downlink Speed", id: "downlink", icon: "fa-tachometer-alt" }
                ]
            },
            {
                title: "Browser Security",
                icon: "fa-fingerprint",
                items: [
                    { label: "Browser", id: "browserInfo", icon: "fa-chrome" },
                    { label: "Operating System", id: "osInfo", icon: "fa-desktop" },
                    { label: "Privacy Mode", id: "incognitoStatus", icon: "fa-user-secret" },
                    { label: "Cookies Enabled", id: "cookieStatus", icon: "fa-cookie" }
                ]
            },
            {
                title: "Security Protocols",
                icon: "fa-lock",
                items: [
                    { label: "HTTPS Status", id: "httpsStatus", icon: "fa-shield-alt" },
                    { label: "TLS Version", id: "tlsVersion", icon: "fa-key" },
                    { label: "Certificate", id: "certStatus", icon: "fa-certificate" },
                    { label: "Ad Blocker", id: "adBlocker", icon: "fa-ban" }
                ]
            },
            {
                title: "System Fingerprint",
                icon: "fa-microchip",
                items: [
                    { label: "CPU Cores", id: "cpuCores", icon: "fa-microchip" },
                    { label: "Device Memory", id: "deviceMemory", icon: "fa-memory" },
                    { label: "Viewport", id: "viewportSize", icon: "fa-window-maximize" },
                    { label: "Session Time", id: "sessionTime", icon: "fa-clock" }
                ]
            }
        ];

        let currentCategory = 0;
        let autoRotateInterval;

        // Initialize
        function init() {
            const container = document.getElementById('carouselContainer');

            categories.forEach((cat, idx) => {
                const slide = document.createElement('div');
                slide.className = `carousel-slide ${idx === 0 ? 'active' : ''}`;
                slide.innerHTML = `
                    <div class="data-grid">
                        ${cat.items.map(item => `
                            <div class="data-item">
                                <div class="data-label"><i class="fas ${item.icon}"></i>${item.label}</div>
                                <div class="data-value" id="${item.id}">--</div>
                            </div>
                        `).join('')}
                    </div>
                `;
                container.appendChild(slide);
            });

            startAutoRotate();
            gatherData();
            setInterval(gatherData, 30000);
        }

        function switchCategory(index) {
            currentCategory = index;

            // Update sidebar buttons
            document.querySelectorAll('.intel-sidebar-btn').forEach((btn, idx) => {
                btn.classList.toggle('active', idx === index);
            });

            // Update title
            const cat = categories[index];
            document.getElementById('intelTitle').innerHTML =
                `<i class="fas ${cat.icon}"></i>${cat.title}`;

            // Update slides
            document.querySelectorAll('.carousel-slide').forEach((slide, idx) => {
                slide.classList.remove('active');
                if (idx === index) slide.classList.add('active');
            });

            resetAutoRotate();
        }

        function changeSlide(direction) {
            const newIndex = (currentCategory + direction + categories.length) % categories.length;
            switchCategory(newIndex);
        }

        function startAutoRotate() {
            autoRotateInterval = setInterval(() => {
                const newIndex = (currentCategory + 1) % categories.length;
                switchCategory(newIndex);
            }, 6000);
        }

        function resetAutoRotate() {
            clearInterval(autoRotateInterval);
            startAutoRotate();
        }

        // Data Gathering
        async function gatherData() {
            // Network
            const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
            if (conn) {
                document.getElementById('connType').textContent = conn.effectiveType || conn.type || 'Unknown';
                document.getElementById('downlink').textContent = conn.downlink ? `${conn.downlink} Mbps` : '--';
            } else {
                document.getElementById('connType').textContent = navigator.onLine ? 'Online' : 'Offline';
                document.getElementById('downlink').textContent = '--';
            }

            // IP & Country only
            try {
                const res = await fetch('https://api.ipify.org?format=json', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                if (!res.ok) {
                    throw new Error('Network response was not ok');
                }

                const data = await res.json();

                document.getElementById('ipAddress').textContent = data.ip || 'Unavailable';
                document.getElementById('country').textContent = data.country_name || 'Unknown';

            } catch (error) {
                console.error('IP lookup failed:', error);

                document.getElementById('ipAddress').textContent = 'Unavailable';
                document.getElementById('country').textContent = 'Unknown';
            }

            // Browser
            const ua = navigator.userAgent;
            let browser = 'Unknown';
            if (ua.includes('Firefox/')) browser = 'Firefox';
            else if (ua.includes('Edg/')) browser = 'Edge';
            else if (ua.includes('Chrome/')) browser = 'Chrome';
            else if (ua.includes('Safari/') && !ua.includes('Chrome')) browser = 'Safari';
            document.getElementById('browserInfo').textContent = browser;

            // OS
            let os = 'Unknown';
            if (ua.includes('Windows')) os = 'Windows';
            else if (ua.includes('Mac')) os = 'macOS';
            else if (ua.includes('Linux')) os = 'Linux';
            else if (ua.includes('Android')) os = 'Android';
            else if (ua.includes('iPhone') || ua.includes('iPad')) os = 'iOS';
            document.getElementById('osInfo').textContent = os;

            // Privacy
            const isPrivate = await detectIncognito();
            document.getElementById('incognitoStatus').innerHTML = isPrivate ?
                '<span class="status-warning"><i class="fas fa-eye-slash"></i> Private</span>' :
                '<span class="status-secure"><i class="fas fa-eye"></i> Normal</span>';

            // Cookies
            document.getElementById('cookieStatus').innerHTML = navigator.cookieEnabled ?
                '<span class="status-secure"><i class="fas fa-check"></i> On</span>' :
                '<span class="status-danger"><i class="fas fa-times"></i> Off</span>';

            // HTTPS
            const isHttps = window.location.protocol === 'https:';
            document.getElementById('httpsStatus').innerHTML = isHttps ?
                '<span class="status-secure"><i class="fas fa-lock"></i> Secure</span>' :
                '<span class="status-danger"><i class="fas fa-unlock"></i> Insecure</span>';

            // TLS
            let tls = 'Unknown';
            if (isHttps) {
                if (performance.getEntriesByType) {
                    const entries = performance.getEntriesByType('navigation');
                    if (entries[0]?.nextHopProtocol === 'h3') tls = 'TLS 1.3';
                    else if (entries[0]?.nextHopProtocol === 'h2') tls = 'TLS 1.2';
                }
                if (tls === 'Unknown') tls = 'TLS 1.2+';
            } else {
                tls = 'N/A';
            }
            document.getElementById('tlsVersion').textContent = tls;

            // Certificate
            document.getElementById('certStatus').innerHTML = isHttps ?
                '<span class="status-secure"><i class="fas fa-check-circle"></i> Valid</span>' :
                '<span class="status-warning"><i class="fas fa-exclamation-triangle"></i> None</span>';

            // Ad Blocker
            const adBlock = await detectAdBlocker();
            document.getElementById('adBlocker').innerHTML = adBlock ?
                '<span style="color: var(--brand-color)"><i class="fas fa-shield-alt"></i> Active</span>' :
                '<span>Not Detected</span>';

            // System
            document.getElementById('cpuCores').textContent = navigator.hardwareConcurrency || '--';
            document.getElementById('deviceMemory').textContent = navigator.deviceMemory ? `${navigator.deviceMemory}GB` : '--';
            document.getElementById('viewportSize').textContent = `${window.innerWidth}×${window.innerHeight}`;
        }

        async function detectIncognito() {
            return new Promise((resolve) => {
                if (window.RequestFileSystem || window.webkitRequestFileSystem) {
                    const fs = window.RequestFileSystem || window.webkitRequestFileSystem;
                    fs(window.TEMPORARY, 100, () => resolve(false), () => resolve(true));
                    return;
                }
                if ('MozAppearance' in document.documentElement.style) {
                    const db = indexedDB.open('test');
                    db.onerror = () => resolve(true);
                    db.onsuccess = () => resolve(false);
                    return;
                }
                resolve(false);
            });
        }

        async function detectAdBlocker() {
            return new Promise((resolve) => {
                const test = document.createElement('div');
                test.className = 'adsbox';
                test.style.cssText = 'position:absolute;left:-9999px;';
                document.body.appendChild(test);
                setTimeout(() => {
                    resolve(test.offsetHeight === 0);
                    document.body.removeChild(test);
                }, 100);
            });
        }

        // Session Timer
        let seconds = 0;
        setInterval(() => {
            seconds++;
            const h = Math.floor(seconds / 3600).toString().padStart(2, '0');
            const m = Math.floor((seconds % 3600) / 60).toString().padStart(2, '0');
            const s = (seconds % 60).toString().padStart(2, '0');
            const el = document.getElementById('sessionTime');
            if (el) el.textContent = `${h}:${m}:${s}`;
        }, 1000);

        window.addEventListener('resize', () => {
            const el = document.getElementById('viewportSize');
            if (el) el.textContent = `${window.innerWidth}×${window.innerHeight}`;
        });

        document.addEventListener('DOMContentLoaded', init);
    </script>
    <script>
        function updateDateTime() {
            const now = new Date();

            const options = {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            };

            document.getElementById("current-date").innerHTML =
                now.toLocaleDateString(undefined, options);

            document.getElementById("current-time").innerHTML =
                now.toLocaleTimeString();
        }

        updateDateTime();
        setInterval(updateDateTime, 1000);
    </script>
</body>

</html>