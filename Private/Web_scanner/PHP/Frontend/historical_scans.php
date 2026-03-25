<?php
session_start();

// Require login
if (!isset($_SESSION['user_id']) && !isset($_SESSION['user_uid'])) {
    header('Location: /ScanQuotient.v2/ScanQuotient.B/Public/Login_page/PHP/Frontend/Login_page_site.php');
    exit;
}

// Use the UID-style identifier for scan ownership
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

// Database configuration (match login handler)
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
$scans = [];
$historyError = null;
$userPackage = 'freemium';
$filterGroupId = isset($_GET['group_id']) ? (int) $_GET['group_id'] : null;

// Pagination (server-side)
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$allowedPerPages = [5, 20, 50, 100, 200];
$perPageParam = $_GET['per_page'] ?? '10';
$effectivePerPage = 10; // int, unless "all"
if ($perPageParam === 'all') {
    $effectivePerPage = null;
} else {
    $candidate = (int) $perPageParam;
    if (in_array($candidate, $allowedPerPages, true)) {
        $effectivePerPage = $candidate;
        $perPageParam = (string) $candidate;
    } else {
        $effectivePerPage = 10;
        $perPageParam = '10';
    }
}
$totalItems = 0;
$totalPages = 1;

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Ensure consistent timezone when reading TIMESTAMP values.
    $pdo->exec("SET time_zone = '+03:00'");
    $pdo->exec("CREATE TABLE IF NOT EXISTS scan_groups (id INT AUTO_INCREMENT PRIMARY KEY, user_id VARCHAR(64) NOT NULL, name VARCHAR(128) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        $pdo->exec("ALTER TABLE scan_results ADD COLUMN group_id INT NULL");
    } catch (Exception $e) {
    }

    // Try to also include any legacy scans that stored the numeric user primary key
    $userPk = null;
    try {
        $stmtUser = $pdo->prepare("SELECT id FROM users WHERE user_id = :uid LIMIT 1");
        $stmtUser->execute([':uid' => $currentUserId]);
        $rowUser = $stmtUser->fetch();
        if ($rowUser && isset($rowUser['id'])) {
            $userPk = (string) $rowUser['id'];
        }
    } catch (Exception $eUser) {
        // Ignore; history will just use UID-based rows
    }

    // Build WHERE clause (supports legacy numeric user_pk in older scans)
    $whereSql = '';
    $params = [];
    if ($userPk !== null) {
        $whereSql = "(user_id = :uid OR user_id = :pk)";
        $params = [':uid' => $currentUserId, ':pk' => $userPk];
    } else {
        $whereSql = "user_id = :uid";
        $params = [':uid' => $currentUserId];
    }
    if ($filterGroupId) {
        $whereSql .= " AND group_id = :gid";
        $params[':gid'] = $filterGroupId;
    }

    // Total count for pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM scan_results WHERE {$whereSql}");
    $countStmt->execute($params);
    $totalItems = (int) ($countStmt->fetch()['total'] ?? 0);

    if ($effectivePerPage === null) {
        // "all"
        $totalPages = 1;
        $page = 1;
    } else {
        $totalPages = max(1, (int) ceil($totalItems / $effectivePerPage));
        if ($page > $totalPages)
            $page = $totalPages;
    }

    $limitOffsetSql = '';
    if ($effectivePerPage !== null) {
        $offset = ($page - 1) * $effectivePerPage;
        $limitOffsetSql = " LIMIT " . (int) $effectivePerPage . " OFFSET " . (int) $offset;
    }

    $stmt = $pdo->prepare("
        SELECT id, target_url, scan_json, created_at, pdf_path, html_path, csv_path, group_id
        FROM scan_results
        WHERE {$whereSql}
        ORDER BY created_at DESC
        {$limitOffsetSql}
    ");
    $stmt->execute($params);
    $scans = $stmt->fetchAll() ?: [];
    $groups = [];
    $stmtG = $pdo->prepare("SELECT id, name FROM scan_groups WHERE user_id = ? ORDER BY name");
    $stmtG->execute([$currentUserId]);
    $groups = $stmtG->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $userPackage = 'freemium';
    $stmtEmail = $pdo->prepare("SELECT email FROM users WHERE user_id = ? LIMIT 1");
    $stmtEmail->execute([$currentUserId]);
    $userRow = $stmtEmail->fetch();
    $userEmail = $userRow['email'] ?? null;
    if ($userEmail) {
        $stmtPay = $pdo->prepare("
            SELECT package FROM payments
            WHERE email = ? AND (account_status = 'active' OR account_status IS NULL)
            AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmtPay->execute([$userEmail]);
        $payRow = $stmtPay->fetch();
        if ($payRow && in_array(strtolower(trim($payRow['package'] ?? '')), ['freemium', 'pro', 'enterprise'], true)) {
            $userPackage = strtolower(trim($payRow['package']));
        }
    }
} catch (Exception $e) {
    $historyError = 'Unable to load your scan history right now.';
    $userPackage = 'freemium';
}

$downloadBase = '/ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Backend/download_scan.php';
$ensurePdfBase = '/ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Backend/ensure_pdf_scan.php';
$scanPageUrl = '/ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Frontend/scan.php';
$packageLabel = ucfirst($userPackage ?: 'freemium');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scan History | ScanQuotient</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --bg-main: #f0f7ff;
            --bg-sidebar: #ffffff;
            --bg-card: #ffffff;
            --text-main: #1e293b;
            --text-light: #64748b;
            --brand-color: #3b82f6;
            --brand-light: #60a5fa;
            --brand-dark: #1d4ed8;
            --accent-color: #8b5cf6;
            --border-color: #e2e8f0;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            --gradient-start: #f3e8ff;
            --gradient-end: #ffffff;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --critical-color: #dc2626;
            --info-color: #3b82f6;
        }

        body.dark {
            --bg-main: #0f0f1a;
            --bg-sidebar: #1a1a2e;
            --bg-card: #1e1b4b;
            --text-main: #f1f5f9;
            --text-light: #94a3b8;
            --brand-color: #8b5cf6;
            --brand-light: #a78bfa;
            --brand-dark: #6d28d9;
            --accent-color: #3b82f6;
            --border-color: rgba(139, 92, 246, 0.2);
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
            --gradient-start: #2e1065;
            --gradient-end: #1e1b4b;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-main);
            color: var(--text-main);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            line-height: 1.6;
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

        .package-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid var(--border-color);
            background: rgba(59, 130, 246, 0.08);
            color: var(--text-main);
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .package-badge i {
            color: var(--brand-color);
            font-size: 12px;
        }

        .package-badge.enterprise {
            background: rgba(16, 185, 129, 0.14);
            border-color: rgba(16, 185, 129, 0.35);
        }

        .package-badge.pro {
            background: rgba(245, 158, 11, 0.15);
            border-color: rgba(245, 158, 11, 0.35);
        }

        .package-badge.freemium {
            background: rgba(107, 114, 128, 0.12);
            border-color: rgba(107, 114, 128, 0.32);
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

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            width: 18px;
            height: 18px;
            background: var(--danger-color);
            color: white;
            font-size: 10px;
            font-weight: 700;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.1);
            }
        }

        /* App Layout */
        .app {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            background: var(--bg-sidebar);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            padding: 24px 16px;
            box-shadow: var(--shadow);
        }

        .brand {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 24px;
            padding-left: 12px;
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
            font-weight: 500;
            transition: all 0.2s ease;
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

        body.dark .nav-btn:hover {
            background: rgba(139, 92, 246, 0.15);
            color: var(--brand-light);
        }

        .nav-btn.active {
            background: var(--brand-color);
            color: white;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        body.dark .nav-btn.active {
            background: var(--brand-color);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.4);
        }

        .nav-btn i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .nav-divider {
            height: 1px;
            background: var(--border-color);
            margin: 20px 12px;
        }

        .theme-toggle-btn {
            margin-top: auto;
            border-top: 1px solid var(--border-color);
            border-radius: 0;
            padding-top: 20px;
            margin-top: 20px;
        }

        /* Main Content */
        .main {
            flex: 1;
            padding: 32px;
            overflow-y: auto;
            background: var(--bg-main);
        }

        /* History Container */
        .history-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Stats Overview */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .stat-icon.blue {
            background: rgba(59, 130, 246, 0.1);
            color: var(--brand-color);
        }

        .stat-icon.green {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .stat-icon.orange {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }

        .stat-icon.red {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }

        .stat-info h3 {
            font-size: 28px;
            font-weight: 800;
            color: var(--text-main);
            line-height: 1;
        }

        .stat-info p {
            font-size: 13px;
            color: var(--text-light);
            margin-top: 4px;
        }

        /* Filters Section */
        .filters-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-light);
        }

        .filter-input {
            padding: 10px 14px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            font-size: 14px;
            background: var(--bg-main);
            color: var(--text-main);
            min-width: 200px;
            transition: all 0.3s ease;
        }

        .filter-input:focus {
            outline: none;
            border-color: var(--brand-color);
        }

        .filter-select {
            padding: 10px 14px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            font-size: 14px;
            background: var(--bg-main);
            color: var(--text-main);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--brand-color);
        }

        .filter-btn {
            padding: 10px 20px;
            background: var(--brand-color);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .filter-btn:hover {
            background: var(--brand-dark);
            transform: translateY(-1px);
        }

        .filter-btn.secondary {
            background: var(--bg-main);
            color: var(--text-main);
            border: 2px solid var(--border-color);
        }

        .filter-btn.secondary:hover {
            background: var(--border-color);
        }

        /* History Table */
        .history-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }

        .history-header {
            padding: 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .history-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .history-title i {
            color: var(--brand-color);
        }

        .history-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
        }

        .action-btn {
            padding: 8px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-main);
            color: var(--text-light);
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
        }

        .action-btn:hover {
            background: var(--brand-color);
            color: white;
            border-color: var(--brand-color);
        }

        .actions-menu-btn {
            white-space: nowrap;
        }

        .actions-modal-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 6px;
        }

        .actions-modal-item.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* Temporarily disabled actions: still clickable to show a message */
        .actions-modal-item.coming-soon {
            opacity: 0.55;
            cursor: not-allowed;
            filter: grayscale(0.35);
        }

        .table-container {
            overflow-x: auto;
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
        }

        .history-table th {
            background: var(--bg-main);
            padding: 16px 20px;
            text-align: left;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border-color);
        }

        .history-table td {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
            color: var(--text-main);
        }

        .history-table tr:hover {
            background: rgba(59, 130, 246, 0.03);
        }

        body.dark .history-table tr:hover {
            background: rgba(139, 92, 246, 0.05);
        }

        .history-table tr:last-child td {
            border-bottom: none;
        }

        /* Target Cell */
        .target-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .target-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--bg-main);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--brand-color);
            font-size: 16px;
        }

        .target-info {
            display: flex;
            flex-direction: column;
        }

        .target-url {
            font-weight: 600;
            color: var(--text-main);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .target-url:hover {
            color: var(--brand-color);
        }

        .target-meta {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 2px;
        }

        /* Risk Badge */
        .risk-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .risk-badge.critical {
            background: rgba(220, 38, 38, 0.1);
            color: var(--critical-color);
        }

        .risk-badge.high {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }

        .risk-badge.medium {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }

        .risk-badge.low {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info-color);
        }

        .risk-badge.secure {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        /* Score Circle Small */
        .score-circle-sm {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
            color: white;
            position: relative;
        }

        .score-circle-sm.critical {
            background: conic-gradient(var(--critical-color) 100%, transparent 0);
        }

        .score-circle-sm.high {
            background: conic-gradient(var(--danger-color) 100%, transparent 0);
        }

        .score-circle-sm.medium {
            background: conic-gradient(var(--warning-color) 100%, transparent 0);
        }

        .score-circle-sm.low {
            background: conic-gradient(var(--info-color) 100%, transparent 0);
        }

        .score-circle-sm.secure {
            background: conic-gradient(var(--success-color) 100%, transparent 0);
        }

        .score-inner {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--bg-card);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-main);
            font-size: 11px;
        }

        /* Vulnerabilities Count */
        .vuln-count {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .vuln-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .vuln-dot.critical {
            background: var(--critical-color);
        }

        .vuln-dot.high {
            background: var(--danger-color);
        }

        .vuln-dot.medium {
            background: var(--warning-color);
        }

        .vuln-dot.low {
            background: var(--info-color);
        }

        .vuln-text {
            font-size: 13px;
            color: var(--text-light);
        }

        /* Status Indicator */
        .status-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        .status-dot.completed {
            background: var(--success-color);
        }

        .status-dot.failed {
            background: var(--danger-color);
        }

        .status-dot.running {
            background: var(--warning-color);
        }

        /* Action Buttons */
        .row-actions {
            display: flex;
            gap: 8px;
        }

        .row-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--bg-main);
            color: var(--text-light);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .row-btn:hover {
            background: var(--brand-color);
            color: white;
            border-color: var(--brand-color);
        }

        .row-btn.delete:hover {
            background: var(--danger-color);
            border-color: var(--danger-color);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-top: 1px solid var(--border-color);
        }

        .page-info {
            font-size: 14px;
            color: var(--text-light);
        }

        .page-controls {
            display: flex;
            gap: 8px;
        }

        .page-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--bg-main);
            color: var(--text-main);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .page-btn:hover:not(:disabled) {
            background: var(--brand-color);
            color: white;
            border-color: var(--brand-color);
        }

        .page-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .page-btn.active {
            background: var(--brand-color);
            color: white;
            border-color: var(--brand-color);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            color: var(--text-light);
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--text-main);
            margin-bottom: 8px;
        }

        /* Toast Notification */
        .toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            z-index: 1000;
        }

        .toast {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px 20px;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 300px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .toast-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .toast-icon.success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .toast-icon.error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }

        .toast-icon.info {
            background: rgba(59, 130, 246, 0.1);
            color: var(--brand-color);
        }

        .toast-content h4 {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 2px;
        }

        .toast-content p {
            font-size: 13px;
            color: var(--text-light);
        }

        /* Loading Skeleton */
        .skeleton {
            background: linear-gradient(90deg, var(--bg-main) 25%, var(--border-color) 50%, var(--bg-main) 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
            border-radius: 6px;
        }

        @keyframes loading {
            0% {
                background-position: 200% 0;
            }

            100% {
                background-position: -200% 0;
            }
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            backdrop-filter: blur(4px);
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 32px;
            max-width: 500px;
            width: 90%;
            box-shadow: var(--shadow-lg);
            animation: modalSlide 0.3s ease;
        }

        @keyframes modalSlide {
            from {
                transform: scale(0.9);
                opacity: 0;
            }

            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }

        .modal-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-main);
        }

        .modal-text {
            color: var(--text-light);
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        .modal-btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .modal-btn.secondary {
            background: var(--bg-main);
            color: var(--text-main);
            border: 1px solid var(--border-color);
        }

        .modal-btn.secondary:hover {
            background: var(--border-color);
        }

        .modal-btn.danger {
            background: var(--danger-color);
            color: white;
            border: none;
        }

        .modal-btn.danger:hover {
            background: var(--critical-color);
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

        /* Responsive */
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filters-card {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group {
                width: 100%;
            }

            .filter-input,
            .filter-select {
                width: 100%;
            }

            .sidebar {
                width: 70px;
                padding: 16px 8px;
            }

            .sidebar .brand,
            .sidebar .nav-btn span {
                display: none;
            }

            .history-table th:nth-child(3),
            .history-table td:nth-child(3),
            .history-table th:nth-child(5),
            .history-table td:nth-child(5) {
                display: none;
            }
        }

        /* Simulated Real-time Activity */
        .activity-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--success-color);
            margin-left: 12px;
        }

        .activity-dot {
            width: 6px;
            height: 6px;
            background: var(--success-color);
            border-radius: 50%;
            animation: blink 1.5s infinite;
        }

        /* Back to Top Button */
        .back-to-top {
            position: fixed;
            right: 24px;
            bottom: 24px;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, 0.28);
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: #fff;
            box-shadow: 0 10px 24px rgba(34, 197, 94, 0.35);
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 1200;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        .back-to-top:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(34, 197, 94, 0.45);
            background: linear-gradient(135deg, #16a34a, #15803d);
        }

        body.dark .back-to-top {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            box-shadow: 0 10px 24px rgba(139, 92, 246, 0.38);
        }

        body.dark .back-to-top:hover {
            box-shadow: 0 12px 28px rgba(139, 92, 246, 0.48);
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
        }

        @keyframes blink {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.3;
            }
        }
    </style>
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
            <span class="package-badge <?php echo htmlspecialchars(strtolower($userPackage)); ?>"
                title="Current package">
                <i class="fas fa-box-open"></i> Package: <?php echo htmlspecialchars($packageLabel); ?>
            </span>

            <a href="/ScanQuotient.v2/ScanQuotient.B/Private/User_dashboard/PHP/Frontend/User_dashboard.php"
                class="icon-btn" title="My Profile"><i class="fas fa-home"></i></a>
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
                <a href="/ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Frontend/scan.php" class="nav-btn"><i
                        class="fas fa-shield-alt"></i><span>New Scan</span></a>
                <a href="<?php echo htmlspecialchars(strtok($_SERVER['REQUEST_URI'], '?')); ?>"
                    class="nav-btn active"><i class="fas fa-history"></i><span>History</span></a>
                <a href="/ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Frontend/scan_groups.php"
                    class="nav-btn"><i class="fas fa-layer-group"></i><span>Groups</span></a>
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
            <div class="history-container">

                <!-- History Table -->
                <div class="history-card">
                    <div class="history-header">
                        <div class="history-title">
                            <i class="fas fa-list-alt"></i>
                            My Scan History
                        </div>
                        <?php if ($historyError): ?>
                            <div style="color: #b91c1c; font-size: 13px;"><?php echo htmlspecialchars($historyError); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($userPackage === 'freemium' && count($scans) >= 5): ?>
                        <div
                            style="padding: 10px 24px; background: rgba(245,158,11,0.15); border-bottom: 1px solid var(--border-color); font-size: 13px; color: var(--warning-color);">
                            You have reached the 5-scan limit. Upgrade to Pro for unlimited saves and the ability to delete
                            old scans.
                        </div>
                    <?php endif; ?>
                    <div
                        style="padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; flex-wrap: wrap; align-items: center; gap: 16px;">
                        <span style="font-size: 13px; font-weight: 600; color: var(--text-light);">Group:</span>
                        <a href="<?php echo strtok($_SERVER['REQUEST_URI'], '?'); ?>?per_page=<?php echo htmlspecialchars($perPageParam, ENT_QUOTES); ?>&page=1"
                            class="action-btn" style="text-decoration:none;">All</a>
                        <select id="groupFilter" style="padding: 8px 12px; border-radius: 8px; min-width: 160px;"
                            onchange="var v=this.value; var base='<?php echo strtok($_SERVER['REQUEST_URI'], '?'); ?>'; var per=document.getElementById('perPageSelect').value; if(v) window.location.href=base+'?group_id='+encodeURIComponent(v)+'&per_page='+encodeURIComponent(per)+'&page=1'; else window.location.href=base+'?per_page='+encodeURIComponent(per)+'&page=1';">
                            <option value="">— All —</option>
                            <?php foreach ($groups as $g): ?>
                                <option value="<?php echo (int) $g['id']; ?>" <?php echo ($filterGroupId === (int) $g['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($g['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span
                            style="margin-left: 12px; font-size: 13px; font-weight: 600; color: var(--text-light);">Records:</span>
                        <select id="perPageSelect" style="padding: 8px 12px; border-radius: 8px; min-width: 160px;"
                            onchange="var v=this.value; var g=document.getElementById('groupFilter').value; var base='<?php echo strtok($_SERVER['REQUEST_URI'], '?'); ?>'; if(g) window.location.href=base+'?group_id='+encodeURIComponent(g)+'&per_page='+encodeURIComponent(v)+'&page=1'; else window.location.href=base+'?per_page='+encodeURIComponent(v)+'&page=1';">
                            <?php foreach ([5, 10, 20, 50, 100, 200] as $opt): ?>
                                <option value="<?php echo (int) $opt; ?>" <?php echo ((string) $perPageParam === (string) $opt) ? 'selected' : ''; ?>><?php echo (int) $opt; ?> per page</option>
                            <?php endforeach; ?>
                            <option value="all" <?php echo $perPageParam === 'all' ? 'selected' : ''; ?>>All</option>
                        </select>
                        <span style="margin-left: 12px; font-size: 13px;">New group:</span>
                        <input type="text" id="newGroupName" placeholder="Group name"
                            style="padding: 8px 12px; border-radius: 8px; width: 140px;">
                        <button type="button" class="action-btn" id="createGroupBtn"><i class="fas fa-plus"></i>
                            Create</button>
                    </div>
                    <?php if ($filterGroupId): ?>
                        <div
                            style="padding: 10px 24px; background: rgba(59,130,246,0.1); border-bottom: 1px solid var(--border-color); font-size: 13px; color: var(--text-main);">
                            You are viewing a filtered group. Some scans are hidden.
                            <a href="<?php echo strtok($_SERVER['REQUEST_URI'], '?'); ?>?per_page=<?php echo htmlspecialchars($perPageParam, ENT_QUOTES); ?>&page=1"
                                style="margin-left:8px;">Show all scans</a>
                        </div>
                    <?php endif; ?>
                    <div class="table-container">
                        <table class="history-table" id="historyTable">
                            <thead>
                                <tr>
                                    <th>Target</th>
                                    <th>Risk Level</th>
                                    <th>Risk Score</th>
                                    <th>Vulnerabilities</th>
                                    <th>Date & Time</th>
                                    <th>Group</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($scans)): ?>
                                    <tr>
                                        <td colspan="7">You do not have any saved scans yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($scans as $row): ?>
                                        <?php
                                        $data = json_decode($row['scan_json'] ?? '', true) ?: [];
                                        $summary = $data['summary'] ?? [];
                                        $riskLevel = $summary['risk_level'] ?? 'Unknown';
                                        $riskScore = $summary['risk_score'] ?? 0;
                                        $totalVulns = $summary['total_vulnerabilities'] ?? 0;
                                        $createdAt = $row['created_at'] ?? '';
                                        $targetUrl = $row['target_url'] ?? ($data['target'] ?? '');
                                        $badgeClass = strtolower($riskLevel);
                                        ?>
                                        <tr>
                                            <td class="target-cell">
                                                <div class="target-icon">
                                                    <i class="fas fa-globe"></i>
                                                </div>
                                                <div class="target-info">
                                                    <a href="<?php echo htmlspecialchars($targetUrl); ?>" target="_blank"
                                                        class="target-url">
                                                        <?php echo htmlspecialchars($targetUrl); ?>
                                                    </a>
                                                </div>
                                            </td>
                                            <td>
                                                <span
                                                    class="risk-badge <?php echo htmlspecialchars(strtolower($badgeClass)); ?>">
                                                    <?php echo htmlspecialchars($riskLevel); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div
                                                    class="score-circle-sm <?php echo htmlspecialchars(strtolower($badgeClass)); ?>">
                                                    <div class="score-inner">
                                                        <?php echo (int) $riskScore; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo (int) $totalVulns; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($createdAt); ?>
                                            </td>
                                            <td>
                                                <select class="scan-group-select" data-scan-id="<?php echo (int) $row['id']; ?>"
                                                    style="padding: 6px 10px; border-radius: 6px; font-size: 13px;">
                                                    <option value="">—</option>
                                                    <?php foreach ($groups as $g): ?>
                                                        <option value="<?php echo (int) $g['id']; ?>" <?php echo (isset($row['group_id']) && (int) $row['group_id'] === (int) $g['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($g['name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <div class="history-actions">
                                                    <button type="button" class="action-btn actions-menu-btn"
                                                        title="More actions" data-scan-id="<?php echo (int) $row['id']; ?>"
                                                        data-target-url="<?php echo htmlspecialchars($targetUrl, ENT_QUOTES); ?>"
                                                        data-has-pdf="<?php echo !empty($row['pdf_path']) ? '1' : '0'; ?>"
                                                        data-has-html="<?php echo !empty($row['html_path']) ? '1' : '0'; ?>"
                                                        data-has-csv="<?php echo !empty($row['csv_path']) ? '1' : '0'; ?>"
                                                        data-can-delete="<?php echo $userPackage !== 'freemium' ? '1' : '0'; ?>"
                                                        data-can-ai="<?php echo $userPackage === 'enterprise' ? '1' : '0'; ?>">
                                                        <i class="fas fa-ellipsis-h"></i> Actions
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($totalPages > 1 && $effectivePerPage !== null): ?>
                        <?php
                        $baseHistoryUrl = strtok($_SERVER['REQUEST_URI'], '?');
                        $perPageQ = urlencode($perPageParam);
                        $groupQ = $filterGroupId ? ('&group_id=' . (int) $filterGroupId) : '';

                        $pagesSet = [];
                        if ($totalPages <= 7) {
                            $pagesSet = range(1, $totalPages);
                        } else {
                            $pagesSet = [1, $totalPages];
                            for ($p = $page - 2; $p <= $page + 2; $p++) {
                                if ($p >= 1 && $p <= $totalPages)
                                    $pagesSet[] = $p;
                            }
                        }
                        $pagesSet = array_values(array_unique($pagesSet));
                        sort($pagesSet);
                        ?>
                        <div class="pagination">
                            <div class="page-info">
                                Page <?php echo (int) $page; ?> of <?php echo (int) $totalPages; ?>
                                (<?php echo (int) $totalItems; ?> total)
                            </div>
                            <div class="page-controls">
                                <?php if ($page > 1): ?>
                                    <a class="page-btn"
                                        href="<?php echo $baseHistoryUrl . '?page=' . ($page - 1) . '&per_page=' . $perPageQ . $groupQ; ?>"
                                        title="Previous page">&laquo;</a>
                                <?php else: ?>
                                    <span class="page-btn" style="cursor:not-allowed;opacity:0.5;"
                                        aria-disabled="true">&laquo;</span>
                                <?php endif; ?>

                                <?php
                                $prevPage = null;
                                foreach ($pagesSet as $p): ?>
                                    <?php if ($prevPage !== null && $p - $prevPage > 1): ?>
                                        <span class="page-btn" style="cursor:default;opacity:0.5;" aria-disabled="true">…</span>
                                    <?php endif; ?>
                                    <?php if ((int) $p === (int) $page): ?>
                                        <span class="page-btn active" aria-current="page"><?php echo (int) $p; ?></span>
                                    <?php else: ?>
                                        <a class="page-btn"
                                            href="<?php echo $baseHistoryUrl . '?page=' . (int) $p . '&per_page=' . $perPageQ . $groupQ; ?>"
                                            title="Page <?php echo (int) $p; ?>"><?php echo (int) $p; ?></a>
                                    <?php endif; ?>
                                    <?php $prevPage = $p; ?>
                                <?php endforeach; ?>

                                <?php if ($page < $totalPages): ?>
                                    <a class="page-btn"
                                        href="<?php echo $baseHistoryUrl . '?page=' . ($page + 1) . '&per_page=' . $perPageQ . $groupQ; ?>"
                                        title="Next page">&raquo;</a>
                                <?php else: ?>
                                    <span class="page-btn" style="cursor:not-allowed;opacity:0.5;"
                                        aria-disabled="true">&raquo;</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
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

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <button type="button" id="backToTopBtn" class="back-to-top" title="Back to top" aria-label="Back to top">
        <i class="fas fa-arrow-up"></i>
    </button>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-icon">
                    <i class="fas fa-trash-alt"></i>
                </div>
                <h3 class="modal-title">Delete Scan Record</h3>
            </div>
            <p class="modal-text">
                Are you sure you want to delete this scan record? This action cannot be undone and all associated
                vulnerability data will be permanently removed.
            </p>
            <div class="modal-actions">
                <button class="modal-btn secondary" onclick="closeModal()">Cancel</button>
                <button class="modal-btn danger" onclick="confirmDelete()">Delete</button>
            </div>
        </div>
    </div>

    <!-- Share Modal -->
    <div class="modal-overlay" id="shareModal" style="display:none;">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Share scan results</h3>
                <button type="button" onclick="closeShareModal()"
                    style="background:none;border:none;cursor:pointer;font-size:20px;">&times;</button>
            </div>
            <input type="hidden" id="shareModalScanId" value="">
            <p style="margin-bottom:8px; font-weight:600;">Share with</p>
            <p style="margin-bottom:8px; font-size:12px; color:var(--text-light);">Enter one or several email addresses
                (comma or space separated):</p>
            <textarea id="shareModalEmails" rows="3"
                style="width:100%; margin-bottom:16px; padding:10px; border-radius:8px;"
                placeholder="email1@example.com, email2@example.com"></textarea>
            <p style="margin-bottom:8px; font-weight:600;">Attach</p>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-weight:600;"><input type="checkbox" id="shareModalAll"> Select
                    all</label>
                <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="shareModalPdf"> PDF
                    <span id="shareModalPdfHint" style="display:none; font-size:12px; color:var(--text-light);">(not
                        available)</span></label>
                <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="shareModalHtml">
                    HTML <span id="shareModalHtmlHint"
                        style="display:none; font-size:12px; color:var(--text-light);">(not available)</span></label>
                <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="shareModalCsv"> CSV
                    <span id="shareModalCsvHint" style="display:none; font-size:12px; color:var(--text-light);">(not
                        available)</span></label>
            </div>
            <div class="modal-actions" style="margin-top:16px;">
                <button class="modal-btn secondary" onclick="closeShareModal()">Cancel</button>
                <button class="modal-btn" id="shareModalSend">Send</button>
            </div>
        </div>
    </div>

    <!-- Actions Modal -->
    <div class="modal-overlay" id="actionsModal">
        <div class="modal" style="max-width:560px;">
            <div class="modal-header">
                <div class="modal-icon" style="background: rgba(59,130,246,0.1); color: var(--brand-color);">
                    <i class="fas fa-list-ul"></i>
                </div>
                <h3 class="modal-title">Actions</h3>
                <button type="button" onclick="closeActionsModal()"
                    style="background:none;border:none;cursor:pointer;font-size:20px;">&times;</button>
            </div>

            <div class="actions-modal-list">
                <a id="actionsRescanLink" class="action-btn actions-modal-item" href="#" title="Rescan this URL">
                    <i class="fas fa-redo"></i> Rescan
                </a>

                <button type="button" id="actionsShareBtn" class="action-btn actions-modal-item disabled"
                    title="Share scan results">
                    <i class="fas fa-share-alt"></i> Share (Coming soon)
                </button>

                <a id="actionsAiOverviewLink" class="action-btn actions-modal-item" href="enterprise_ai_overview.php"
                    title="Enterprise AI Overview">
                    <i class="fas fa-brain"></i> AI Overview
                </a>

                <a id="actionsHtmlLink" class="action-btn actions-modal-item" href="#" target="_blank"
                    title="Download HTML">
                    <i class="fas fa-file-code"></i> HTML
                </a>
                <a id="actionsCsvLink" class="action-btn actions-modal-item" href="#" target="_blank"
                    title="Download CSV">
                    <i class="fas fa-file-csv"></i> CSV
                </a>
                <a id="actionsPdfLink" class="action-btn actions-modal-item coming-soon" href="#"
                    title="PDF from history — temporarily disabled">
                    <i class="fas fa-file-pdf"></i> PDF (Coming soon)
                </a>

                <button type="button" id="actionsDeleteBtn" class="action-btn actions-modal-item delete"
                    title="Delete scan">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </div>
        </div>
    </div>

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

        // Server-provided endpoints (used by the actions modal)
        const scanPageUrlBase = <?php echo json_encode($scanPageUrl); ?>;
        const downloadBaseUrl = <?php echo json_encode($downloadBase); ?>;
        const ensurePdfBaseUrl = <?php echo json_encode($ensurePdfBase); ?>;

        // Date/Time
        function updateDateTime() {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            document.getElementById("current-date").textContent = now.toLocaleDateString(undefined, options);
            document.getElementById("current-time").textContent = now.toLocaleTimeString();
        }
        updateDateTime();
        setInterval(updateDateTime, 1000);

        // Fake Scan Data Generator
        const domains = [
            'https://example.com', 'https://testsite.org', 'https://demo-app.io',
            'https://secure-bank.com', 'https://ecommerce-shop.net', 'https://api-service.com',
            'https://blog-platform.org', 'https://cloud-storage.io', 'https://social-network.com',
            'https://payment-gateway.net', 'https://healthcare-portal.org', 'https://education-lms.io'
        ];

        const riskLevels = ['critical', 'high', 'medium', 'low', 'secure'];

        function generateFakeData() {
            const data = [];
            const now = new Date();

            for (let i = 0; i < 47; i++) {
                const date = new Date(now.getTime() - (i * 2 + Math.random() * 10) * 60 * 60 * 1000);
                const domain = domains[Math.floor(Math.random() * domains.length)];
                const risk = riskLevels[Math.floor(Math.random() * riskLevels.length)];
                const score = risk === 'secure' ? 0 :
                    risk === 'low' ? Math.floor(Math.random() * 5) + 1 :
                        risk === 'medium' ? Math.floor(Math.random() * 5) + 5 :
                            risk === 'high' ? Math.floor(Math.random() * 10) + 10 :
                                Math.floor(Math.random() * 20) + 20;

                const vulns = {
                    critical: risk === 'critical' ? Math.floor(Math.random() * 3) + 1 : 0,
                    high: (risk === 'critical' || risk === 'high') ? Math.floor(Math.random() * 4) + 1 : Math.floor(Math.random() * 2),
                    medium: Math.floor(Math.random() * 3),
                    low: Math.floor(Math.random() * 4) + 1
                };

                data.push({
                    id: i + 1,
                    target: domain,
                    riskLevel: risk,
                    riskScore: score,
                    vulnerabilities: vulns,
                    status: Math.random() > 0.1 ? 'completed' : 'failed',
                    date: date,
                    duration: (Math.random() * 15 + 5).toFixed(1)
                });
            }
            return data;
        }

        let scanData = generateFakeData();
        let currentPage = 1;
        const itemsPerPage = 10;
        let itemToDelete = null;
        // Used by the per-row "Actions" modal.
        // Keep it as an object (not null) to avoid "Cannot read properties of null" runtime errors.
        let actionsContext = {
            scanId: null,
            targetUrl: '',
            hasPdf: false,
            hasHtml: false,
            hasCsv: false,
            canDelete: false,
            canAi: false
        };

        // Render Functions
        function getRiskBadge(level) {
            const config = {
                critical: { icon: 'fa-skull-crossbones', label: 'Critical' },
                high: { icon: 'fa-exclamation-triangle', label: 'High' },
                medium: { icon: 'fa-exclamation-circle', label: 'Medium' },
                low: { icon: 'fa-info-circle', label: 'Low' },
                secure: { icon: 'fa-check-circle', label: 'Secure' }
            };
            const c = config[level];
            return `<span class="risk-badge ${level}"><i class="fas ${c.icon}"></i> ${c.label}</span>`;
        }

        function getScoreCircle(score, level) {
            return `
                <div class="score-circle-sm ${level}">
                    <div class="score-inner">${score}</div>
                </div>
            `;
        }

        function getVulnerabilityDots(vulns) {
            const total = vulns.critical + vulns.high + vulns.medium + vulns.low;
            let html = '<div class="vuln-count">';

            if (vulns.critical > 0) html += `<span class="vuln-dot critical" title="${vulns.critical} Critical"></span>`;
            if (vulns.high > 0) html += `<span class="vuln-dot high" title="${vulns.high} High"></span>`;
            if (vulns.medium > 0) html += `<span class="vuln-dot medium" title="${vulns.medium} Medium"></span>`;
            if (vulns.low > 0) html += `<span class="vuln-dot low" title="${vulns.low} Low"></span>`;

            html += `<span class="vuln-text">${total} issues</span></div>`;
            return html;
        }

        function formatDate(date) {
            const now = new Date();
            const diff = now - date;
            const hours = Math.floor(diff / (1000 * 60 * 60));

            if (hours < 1) return 'Just now';
            if (hours < 24) return `${hours} hours ago`;
            if (hours < 48) return 'Yesterday';
            return date.toLocaleDateString();
        }

        function renderTable() {
            const tbody = document.getElementById('tableBody');
            if (!tbody) return;
            const start = (currentPage - 1) * itemsPerPage;
            const end = start + itemsPerPage;
            const pageData = scanData.slice(start, end);

            tbody.innerHTML = pageData.map(scan => `
                <tr data-id="${scan.id}">
                    <td>
                        <div class="target-cell">
                            <div class="target-icon">
                                <i class="fas fa-globe"></i>
                            </div>
                            <div class="target-info">
                                <a href="#" class="target-url">${scan.target}</a>
                                <span class="target-meta">${scan.duration}s scan duration</span>
                            </div>
                        </div>
                    </td>
                    <td>${getRiskBadge(scan.riskLevel)}</td>
                    <td>${getScoreCircle(scan.riskScore, scan.riskLevel)}</td>
                    <td>${getVulnerabilityDots(scan.vulnerabilities)}</td>
                    <td>
                        <div class="status-indicator">
                            <span class="status-dot ${scan.status}"></span>
                            <span style="text-transform: capitalize;">${scan.status}</span>
                        </div>
                    </td>
                    <td>${formatDate(scan.date)}</td>
                    <td>
                        <div class="row-actions">
                            <button class="row-btn" onclick="viewDetails(${scan.id})" title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="row-btn" onclick="rescan(${scan.id})" title="Rescan">
                                <i class="fas fa-redo"></i>
                            </button>
                            <button class="row-btn delete" onclick="deleteScan(${scan.id})" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');

            // Update pagination
            document.getElementById('startRange').textContent = start + 1;
            document.getElementById('endRange').textContent = Math.min(end, scanData.length);
            document.getElementById('totalItems').textContent = scanData.length;

            document.getElementById('prevBtn').disabled = currentPage === 1;
            document.getElementById('nextBtn').disabled = end >= scanData.length;

            // Update active page button
            document.querySelectorAll('.page-btn').forEach((btn, idx) => {
                if (idx > 0 && idx < 6) {
                    btn.classList.toggle('active', idx === currentPage);
                }
            });
        }

        // Interactive Functions
        function changePage(direction) {
            const newPage = currentPage + direction;
            if (newPage >= 1 && newPage <= Math.ceil(scanData.length / itemsPerPage)) {
                currentPage = newPage;
                renderTable();
                showToast('Page updated', `Showing page ${currentPage}`, 'info');
            }
        }

        function goToPage(page) {
            currentPage = page;
            renderTable();
        }

        function applyFilters() {
            const search = document.getElementById('searchInput').value.toLowerCase();
            const risk = document.getElementById('riskFilter').value;
            const dateRange = document.getElementById('dateFilter').value;

            let filtered = generateFakeData().filter(scan => {
                if (search && !scan.target.toLowerCase().includes(search)) return false;
                if (risk !== 'all' && scan.riskLevel !== risk) return false;

                if (dateRange !== 'all') {
                    const now = new Date();
                    const scanDate = scan.date;
                    const diffDays = (now - scanDate) / (1000 * 60 * 60 * 24);

                    if (dateRange === 'today' && diffDays > 1) return false;
                    if (dateRange === 'week' && diffDays > 7) return false;
                    if (dateRange === 'month' && diffDays > 30) return false;
                }
                return true;
            });

            scanData = filtered;
            currentPage = 1;
            renderTable();
            showToast('Filters Applied', `Found ${filtered.length} matching scans`, 'success');
        }

        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('riskFilter').value = 'all';
            document.getElementById('dateFilter').value = 'all';
            scanData = generateFakeData();
            currentPage = 1;
            renderTable();
            showToast('Filters Reset', 'Showing all scan records', 'info');
        }

        function viewDetails(id) {
            const scan = scanData.find(s => s.id === id);
            showToast('Opening Details', `Loading scan report for ${scan.target}`, 'info');

            // Simulate loading delay
            setTimeout(() => {
                showToast('Report Ready', `Viewing detailed analysis for ${scan.target}`, 'success');
            }, 1500);
        }

        function rescan(id) {
            const scan = scanData.find(s => s.id === id);
            showToast('Initiating Rescan', `Starting new scan of ${scan.target}...`, 'info');

            // Simulate scan progress
            let progress = 0;
            const interval = setInterval(() => {
                progress += 20;
                if (progress >= 100) {
                    clearInterval(interval);
                    showToast('Scan Complete', `Rescan of ${scan.target} finished successfully`, 'success');
                    // Update the scan data with new random results
                    scan.date = new Date();
                    scan.riskScore = Math.floor(Math.random() * 30);
                    renderTable();
                }
            }, 500);
        }

        function deleteScan(id) {
            itemToDelete = id;
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('deleteModal').classList.remove('active');
            itemToDelete = null;
        }

        function closeActionsModal() {
            const m = document.getElementById('actionsModal');
            if (m) m.classList.remove('active');
            actionsContext = {
                scanId: null,
                targetUrl: '',
                hasPdf: false,
                hasHtml: false,
                hasCsv: false,
                canDelete: false,
                canAi: false
            };
        }

        function openActionsModal(scanId, opts) {
            opts = opts || {};
            actionsContext = {
                scanId: parseInt(scanId, 10) || null,
                targetUrl: opts.targetUrl || '',
                hasPdf: !!opts.hasPdf,
                hasHtml: !!opts.hasHtml,
                hasCsv: !!opts.hasCsv,
                canDelete: !!opts.canDelete,
                canAi: !!opts.canAi
            };

            const m = document.getElementById('actionsModal');
            if (!m) return;

            // Rescan
            const rescanLink = document.getElementById('actionsRescanLink');
            if (rescanLink) {
                rescanLink.href = scanPageUrlBase + '?url=' + encodeURIComponent(actionsContext.targetUrl);
            }

            // Downloads
            const htmlLink = document.getElementById('actionsHtmlLink');
            const csvLink = document.getElementById('actionsCsvLink');
            const pdfLink = document.getElementById('actionsPdfLink');
            const setDisabled = (el, disabled) => {
                if (!el) return;
                el.classList.toggle('disabled', !!disabled);
            };

            if (htmlLink) {
                htmlLink.href = downloadBaseUrl + '?id=' + actionsContext.scanId + '&type=html';
                setDisabled(htmlLink, !actionsContext.hasHtml);
            }
            if (csvLink) {
                csvLink.href = downloadBaseUrl + '?id=' + actionsContext.scanId + '&type=csv';
                setDisabled(csvLink, !actionsContext.hasCsv);
            }
            if (pdfLink) {
                // PDF from history temporarily disabled (re-enable download routes + client helpers later).
                pdfLink.href = '#';
                pdfLink.classList.add('coming-soon');
                setDisabled(pdfLink, false);
            }

            // Share
            const shareBtn = document.getElementById('actionsShareBtn');
            if (shareBtn) shareBtn.classList.add('disabled');

            // AI Overview
            const aiLink = document.getElementById('actionsAiOverviewLink');
            if (aiLink) {
                aiLink.href = 'enterprise_ai_overview.php?scan_id=' + actionsContext.scanId;
                // Must open in the same tab.
                aiLink.target = '_self';
                aiLink.classList.toggle('disabled', !actionsContext.canAi);
                if (!actionsContext.canAi) {
                    aiLink.title = 'AI Overview requires Enterprise package';
                } else {
                    aiLink.title = 'Enterprise AI Overview';
                }
            }

            // Delete
            const delBtn = document.getElementById('actionsDeleteBtn');
            if (delBtn) {
                delBtn.classList.toggle('disabled', !actionsContext.canDelete);
                delBtn.title = actionsContext.canDelete ? 'Delete scan' : 'Delete requires Pro or Enterprise package';
            }

            m.classList.add('active');
        }

        function confirmDelete() {
            if (!itemToDelete) return;
            const id = parseInt(itemToDelete, 10);
            document.querySelector('#deleteModal button.modal-btn.danger')?.setAttribute('disabled', 'disabled');

            fetch('../Backend/delete_scan.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            }).then(r => r.json()).then(data => {
                document.querySelector('#deleteModal button.modal-btn.danger')?.removeAttribute('disabled');
                if (data.ok) {
                    showToast('Deleted', 'Scan record has been permanently removed', 'success');
                    closeModal();
                    closeActionsModal();
                    // Allow toast to remain visible before the page reloads.
                    setTimeout(() => window.location.reload(), 2200);
                } else {
                    showToast('Delete failed', data.error || 'Could not delete', 'error');
                }
            }).catch(() => {
                document.querySelector('#deleteModal button.modal-btn.danger')?.removeAttribute('disabled');
                showToast('Delete failed', 'Request failed. Try again.', 'error');
            });
        }

        function updateStats() {
            const total = scanData.length;
            const secure = scanData.filter(s => s.riskLevel === 'secure').length;
            const warnings = scanData.filter(s => s.riskLevel === 'medium' || s.riskLevel === 'low').length;
            const critical = scanData.filter(s => s.riskLevel === 'critical' || s.riskLevel === 'high').length;

            // Animate numbers
            animateNumber('totalScans', total);
            animateNumber('secureSites', secure);
            animateNumber('warnings', warnings);
            animateNumber('criticalIssues', critical);
        }

        function animateNumber(id, target) {
            const el = document.getElementById(id);
            const start = parseInt(el.textContent);
            const diff = target - start;
            const steps = 20;
            let current = 0;

            const timer = setInterval(() => {
                current++;
                el.textContent = Math.round(start + (diff * current / steps));
                if (current >= steps) clearInterval(timer);
            }, 30);
        }

        function exportData() {
            showToast('Preparing Export', 'Generating CSV file with scan data...', 'info');

            setTimeout(() => {
                // Simulate file download
                const csv = 'data:text/csv;charset=utf-8,' +
                    'Target,Risk Level,Score,Vulnerabilities,Date\n' +
                    scanData.map(s => `${s.target},${s.riskLevel},${s.riskScore},${s.vulnerabilities.critical + s.vulnerabilities.high},${s.date.toISOString()}`).join('\n');

                const link = document.createElement('a');
                link.setAttribute('href', encodeURI(csv));
                link.setAttribute('download', `scanquotient_export_${new Date().toISOString().split('T')[0]}.csv`);
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);

                showToast('Export Complete', 'CSV file downloaded successfully', 'success');
            }, 1000);
        }

        function refreshData() {
            showToast('Refreshing Data', 'Fetching latest scan records...', 'info');

            // Simulate network request
            setTimeout(() => {
                scanData = generateFakeData();
                currentPage = 1;
                renderTable();
                updateStats();
                showToast('Data Updated', 'History synchronized with server', 'success');
            }, 1200);
        }

        // Toast Notification System
        function showToast(title, message, type = 'info') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast';

            const icons = {
                success: 'fa-check-circle',
                error: 'fa-times-circle',
                info: 'fa-info-circle'
            };

            toast.innerHTML = `
                <div class="toast-icon ${type}">
                    <i class="fas ${icons[type]}"></i>
                </div>
                <div class="toast-content">
                    <h4>${title}</h4>
                    <p>${message}</p>
                </div>
            `;

            container.appendChild(toast);

            // Remove after 4 seconds
            setTimeout(() => {
                toast.style.animation = 'slideIn 0.3s ease reverse';
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        // Simulated Real-time Updates
        function simulateRealtimeActivity() {
            // Randomly update a stat or show notification
            const actions = [
                () => showToast('New Scan Complete', 'A new vulnerability scan has finished', 'success'),
                () => {
                    const badge = document.querySelector('.notification-badge');
                    badge.textContent = parseInt(badge.textContent) + 1;
                    badge.style.display = 'flex';
                },
                () => {
                    // Simulate a new scan being added
                    const newScan = {
                        id: Date.now(),
                        target: domains[Math.floor(Math.random() * domains.length)],
                        riskLevel: riskLevels[Math.floor(Math.random() * riskLevels.length)],
                        riskScore: Math.floor(Math.random() * 25),
                        vulnerabilities: {
                            critical: Math.floor(Math.random() * 2),
                            high: Math.floor(Math.random() * 3),
                            medium: Math.floor(Math.random() * 2),
                            low: Math.floor(Math.random() * 3)
                        },
                        status: 'completed',
                        date: new Date(),
                        duration: (Math.random() * 10 + 5).toFixed(1)
                    };
                    scanData.unshift(newScan);
                    if (currentPage === 1) renderTable();
                    updateStats();
                    showToast('Live Update', 'New scan result received', 'info');
                }
            ];

            // Perform random action every 15-30 seconds
            setTimeout(() => {
                if (Math.random() > 0.6) {
                    const action = actions[Math.floor(Math.random() * actions.length)];
                    action();
                }
                simulateRealtimeActivity();
            }, Math.random() * 15000 + 15000);
        }

        // Event Listeners (guard: elements may not exist on PHP-rendered table view)
        document.getElementById('notificationsBtn')?.addEventListener('click', () => {
            showToast('Notifications', 'You have 3 unread security alerts', 'info');
            const badge = document.querySelector('.notification-badge');
            if (badge) badge.style.display = 'none';
        });

        document.getElementById('helpBtn')?.addEventListener('click', () => {
            showToast('Help Center', 'Access documentation and support resources', 'info');
        });

        // Search input debounce
        let searchTimeout;
        document.getElementById('searchInput')?.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                if (e.target.value.length > 2 && typeof applyFilters === 'function') applyFilters();
            }, 500);
        });

        // Close modal on overlay click
        document.getElementById('deleteModal')?.addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeModal();
        });

        // Close actions modal on overlay click
        document.getElementById('actionsModal')?.addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeActionsModal();
        });

        // Open per-row actions modal
        document.querySelector('.history-table')?.addEventListener('click', (e) => {
            const btn = e.target.closest('.actions-menu-btn');
            if (!btn) return;
            e.preventDefault();

            openActionsModal(
                btn.getAttribute('data-scan-id'),
                {
                    targetUrl: btn.getAttribute('data-target-url') || '',
                    hasPdf: btn.getAttribute('data-has-pdf') === '1',
                    hasHtml: btn.getAttribute('data-has-html') === '1',
                    hasCsv: btn.getAttribute('data-has-csv') === '1',
                    canDelete: btn.getAttribute('data-can-delete') === '1',
                    canAi: btn.getAttribute('data-can-ai') === '1'
                }
            );
        });

        // Actions modal button wiring
        document.getElementById('actionsShareBtn')?.addEventListener('click', () => {
            showToast('Share', 'Share feature is temporarily disabled and will be enabled in a later update.', 'info');
            return;
            if (!actionsContext || !actionsContext.scanId) return;
            const hasAnyArtefact = actionsContext.hasPdf || actionsContext.hasHtml || actionsContext.hasCsv;
            if (!hasAnyArtefact) {
                showToast('Share', 'No report files (PDF/HTML/CSV) are available for this scan.', 'error');
                return;
            }
            const scanId = actionsContext.scanId;
            closeActionsModal();
            openShareModal(scanId, {
                hasPdf: actionsContext.hasPdf,
                hasHtml: actionsContext.hasHtml,
                hasCsv: actionsContext.hasCsv
            });
        });

        document.getElementById('actionsDeleteBtn')?.addEventListener('click', () => {
            if (!actionsContext || !actionsContext.scanId) return;
            if (!actionsContext.canDelete) {
                showToast('Delete', 'Delete is available on Pro and Enterprise packages.', 'info');
                return;
            }
            const scanId = actionsContext.scanId;
            closeActionsModal();
            deleteScan(scanId);
        });

        // Close actions modal when launching any navigation/download link
        ['actionsRescanLink', 'actionsHtmlLink', 'actionsCsvLink', 'actionsAiOverviewLink'].forEach(id => {
            document.getElementById(id)?.addEventListener('click', () => closeActionsModal());
        });

        document.getElementById('actionsPdfLink')?.addEventListener('click', function (e) {
            e.preventDefault();
            showToast('PDF', 'PDF download from history is temporarily disabled. We will restore it in a later update.', 'info');
        });

        // Delete scan (Pro/Enterprise) - real table
        document.querySelector('.history-table')?.addEventListener('click', (e) => {
            const btn = e.target.closest('.delete-scan-btn');
            if (!btn) return;
            e.preventDefault();
            const id = btn.getAttribute('data-scan-id');
            if (!id || !confirm('Delete this scan record? This cannot be undone.')) return;
            fetch('../Backend/delete_scan.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            }).then(r => r.json()).then(data => {
                if (data.ok) {
                    window.location.reload();
                } else {
                    alert(data.error || 'Failed to delete');
                }
            }).catch(() => alert('Request failed'));
        });

        function closeShareModal() {
            const m = document.getElementById('shareModal');
            if (m) { m.style.display = 'none'; }
        }
        function syncHistoryShareAll() {
            const all = document.getElementById('shareModalAll');
            const pdf = document.getElementById('shareModalPdf');
            const html = document.getElementById('shareModalHtml');
            const csv = document.getElementById('shareModalCsv');
            if (!all || !pdf || !html || !csv) return;
            all.checked = !!(pdf.checked && html.checked && csv.checked);
        }
        document.getElementById('shareModalAll')?.addEventListener('change', (e) => {
            const v = !!e.target.checked;
            const pdf = document.getElementById('shareModalPdf');
            const html = document.getElementById('shareModalHtml');
            const csv = document.getElementById('shareModalCsv');
            if (pdf && !pdf.disabled) pdf.checked = v;
            if (html && !html.disabled) html.checked = v;
            if (csv && !csv.disabled) csv.checked = v;
        });
        ['shareModalPdf', 'shareModalHtml', 'shareModalCsv'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', syncHistoryShareAll);
        });

        function openShareModal(scanId, opts) {
            opts = opts || {};
            document.getElementById('shareModalScanId').value = scanId || '';
            const pdf = document.getElementById('shareModalPdf');
            const html = document.getElementById('shareModalHtml');
            const csv = document.getElementById('shareModalCsv');
            const pdfHint = document.getElementById('shareModalPdfHint');
            const htmlHint = document.getElementById('shareModalHtmlHint');
            const csvHint = document.getElementById('shareModalCsvHint');
            if (pdf) { pdf.disabled = !(opts.hasPdf || opts.hasHtml); pdf.checked = !!(opts.hasPdf || opts.hasHtml); }
            if (html) { html.disabled = !opts.hasHtml; html.checked = !!opts.hasHtml; }
            if (csv) { csv.disabled = !opts.hasCsv; csv.checked = !!opts.hasCsv; }
            if (pdfHint) pdfHint.style.display = (opts.hasPdf || opts.hasHtml) ? 'none' : 'inline';
            if (htmlHint) htmlHint.style.display = opts.hasHtml ? 'none' : 'inline';
            if (csvHint) csvHint.style.display = opts.hasCsv ? 'none' : 'inline';
            const all = document.getElementById('shareModalAll');
            if (all) all.checked = !!((pdf && pdf.checked) && (html && html.checked) && (csv && csv.checked));
            document.getElementById('shareModalEmails').value = '';
            document.getElementById('shareModal').style.display = 'flex';
        }
        document.querySelector('.history-table')?.addEventListener('click', (e) => {
            const btn = e.target.closest('.share-scan-btn');
            if (!btn) return;
            e.preventDefault();
            const scanId = btn.getAttribute('data-scan-id');
            if (scanId) {
                openShareModal(scanId, {
                    hasPdf: btn.getAttribute('data-has-pdf') === '1',
                    hasHtml: btn.getAttribute('data-has-html') === '1',
                    hasCsv: btn.getAttribute('data-has-csv') === '1'
                });
            }
        });
        document.getElementById('shareModalSend')?.addEventListener('click', async () => {
            const scanId = parseInt(document.getElementById('shareModalScanId').value, 10);
            const artefacts = [];
            if (document.getElementById('shareModalPdf').checked) artefacts.push('pdf');
            if (document.getElementById('shareModalHtml').checked) artefacts.push('html');
            if (document.getElementById('shareModalCsv').checked) artefacts.push('csv');

            // Share sheet mode: ignore emails; use native OS share features when available.
            if (!scanId || artefacts.length === 0) {
                showToast('Share', 'Select at least one format (PDF/HTML/CSV).', 'error');
                return;
            }
            document.getElementById('shareModalSend').disabled = true;
            try {
                if (!navigator.share || typeof navigator.share !== 'function') {
                    throw new Error('Web Share is not supported in this browser.');
                }

                const files = [];
                const mimeMap = { pdf: 'application/pdf', html: 'text/html', csv: 'text/csv' };

                for (const type of artefacts) {
                    const url = (type === 'pdf')
                        ? (ensurePdfBaseUrl + '?scan_id=' + encodeURIComponent(scanId))
                        : (downloadBaseUrl + '?id=' + encodeURIComponent(scanId) + '&type=' + encodeURIComponent(type));
                    const r = await fetch(url, { credentials: 'same-origin', redirect: 'follow' });
                    if (!r.ok) continue;
                    const blob = await r.blob();
                    if (!blob || blob.size < 1200) continue; // skip tiny/invalid downloads
                    files.push(new File([blob], `scan-report-${scanId}.${type}`, { type: mimeMap[type] || blob.type || 'application/octet-stream' }));
                }

                const shareData = {
                    title: 'ScanQuotient scan results',
                    text: 'ScanQuotient scan results',
                    files
                };

                // If files can't be shared, fall back to sharing text only.
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
                document.getElementById('shareModalSend').disabled = false;
            }
        });
        document.getElementById('shareModal')?.addEventListener('click', (e) => { if (e.target.id === 'shareModal') closeShareModal(); });

        function handleCreateGroup() {
            const nameInput = document.getElementById('newGroupName');
            const name = nameInput ? nameInput.value.trim() : '';
            if (!name) { showToast('Create group', 'Enter a group name', 'error'); return; }
            const btn = document.getElementById('createGroupBtn');
            if (btn) btn.disabled = true;
            fetch('../Backend/create_scan_group.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: name })
            }).then(r => r.json()).then(data => {
                if (btn) btn.disabled = false;
                if (data.ok) {
                    showToast('Group created', '"' + name + '" created. Assign scans to it using the Group column.', 'success');
                    if (nameInput) nameInput.value = '';
                    window.location.reload();
                } else {
                    showToast('Create group failed', data.error || 'Failed to create group', 'error');
                }
            }).catch(() => {
                if (btn) btn.disabled = false;
                showToast('Create group failed', 'Request failed', 'error');
            });
        }
        const createGroupBtn = document.getElementById('createGroupBtn');
        if (createGroupBtn) {
            createGroupBtn.addEventListener('click', handleCreateGroup);
        }
        document.querySelector('.history-table')?.addEventListener('change', (e) => {
            const sel = e.target.closest('.scan-group-select');
            if (!sel) return;
            const scanId = sel.getAttribute('data-scan-id');
            const groupId = sel.value === '' ? null : sel.value;
            fetch('../Backend/update_scan_group.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ scan_id: parseInt(scanId, 10), group_id: groupId })
            }).then(r => r.json()).then(data => {
                if (!data.ok) alert(data.error || 'Failed to update');
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeModal();
                closeShareModal();
                closeActionsModal();
            }
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                refreshData();
            }
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('searchInput').focus();
            }
        });

        // Initialize (only run JS table/pagination if tableBody exists; otherwise page uses PHP-rendered table)
        if (document.getElementById('tableBody')) {
            renderTable();
            updateStats();
            simulateRealtimeActivity();
        }

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

        // Welcome toast (show once per session)
        (function () {
            try {
                const key = 'sq_history_welcome_toast_shown';
                if (sessionStorage.getItem(key)) return;
                sessionStorage.setItem(key, '1');
                setTimeout(() => {
                    showToast('Welcome Back', 'You have <?php echo (int) $totalItems; ?> scan records in your history', 'success');
                }, 700);
            } catch (e) { }
        })();
    </script>

</body>

</html>