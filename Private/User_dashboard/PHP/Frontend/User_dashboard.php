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
                                <i class="fas fa-crosshairs action-btn-icon" aria-hidden="true"></i> Start New Scan
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

    <script src="../../Javascript/user_dashboard.js" defer></script>
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