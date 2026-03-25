<?php
session_start();

// Only allow authenticated admins
if (!isset($_SESSION['authenticated']) || !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'], true)) {
    header('Location: /ScanQuotient.v2/ScanQuotient.B/Public/Login_page/PHP/Frontend/Login_page_site.php');
    exit;
}

// Database configuration (match login handler)
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
$scanRows = [];
$adminError = null;
$allowedViews = ['scans', 'ai', 'server_logs'];
$activeView = (string) ($_GET['view'] ?? 'scans');
if (!in_array($activeView, $allowedViews, true)) {
    $activeView = 'scans';
}
$metrics = [
    'total_scans' => 0,
    'unique_users' => 0,
    'by_risk' => [
        'Critical' => 0,
        'High' => 0,
        'Medium' => 0,
        'Low' => 0,
        'Secure' => 0,
        'Unknown' => 0,
    ],
];

define('DEFAULT_PER_PAGE', 10);
$page = max(1, intval($_GET['page'] ?? 1));
$perPageParam = $_GET['per_page'] ?? (string) DEFAULT_PER_PAGE;
$allowedPerPage = ['5', '10', '20', '50', '100', '200', 'all'];
if (!in_array($perPageParam, $allowedPerPage, true)) {
    $perPageParam = (string) DEFAULT_PER_PAGE;
}
$effectivePerPage = $perPageParam === 'all' ? null : (int) $perPageParam;
$scansSearch = trim((string) ($_GET['scans_search'] ?? $_GET['search'] ?? ''));
$scansRiskFilter = trim((string) ($_GET['scans_risk'] ?? 'all'));
$allowedScanRisks = ['all', 'Critical', 'High', 'Medium', 'Low', 'Secure', 'Unknown'];
if (!in_array($scansRiskFilter, $allowedScanRisks, true)) {
    $scansRiskFilter = 'all';
}

$aiSearch = trim((string) ($_GET['ai_search'] ?? ''));
$aiEventTypeFilter = trim((string) ($_GET['ai_event_type'] ?? 'all'));
$allowedAiEventTypes = ['all', 'ask_submitted', 'ask_success', 'ask_error', 'clear_chat', 'page_view'];
if (!in_array($aiEventTypeFilter, $allowedAiEventTypes, true)) {
    $aiEventTypeFilter = 'all';
}

$logSearch = trim((string) ($_GET['log_search'] ?? ''));
$logLevelFilter = trim((string) ($_GET['log_level'] ?? 'all'));
$allowedLogLevels = ['all', 'info', 'warning', 'error'];
if (!in_array($logLevelFilter, $allowedLogLevels, true)) {
    $logLevelFilter = 'all';
}
$logSourceFilter = trim((string) ($_GET['log_source'] ?? 'all'));
$allowedLogSources = ['all', 'web_scanner.scan_proxy', 'enterprise_ai_api', 'system'];
if (!in_array($logSourceFilter, $allowedLogSources, true)) {
    $logSourceFilter = 'all';
}

$totalItems = 0;
$totalPages = 1;
$offset = 0;
$recordsStart = 0;
$recordsEnd = 0;
$aiEventRows = [];
$aiEventTotal = 0;
$serverLogRows = [];
$serverLogTotal = 0;
$serverLogMetrics = [
    'errors_24h' => 0,
    'warnings_24h' => 0,
    'unique_users_24h' => 0,
    'scanner_related_24h' => 0,
];
$aiMetrics = [
    'total_events' => 0,
    'unique_users' => 0,
    'ask_submitted' => 0,
    'ask_success' => 0,
];
$showDeleteSuccess = isset($_GET['deleted']) && (string) $_GET['deleted'] === '1';
$adminName = $_SESSION['user_name'] ?? 'Admin';
$profile_photo = $_SESSION['profile_photo'] ?? null;
if (!empty($profile_photo)) {
    $photo_path = ltrim((string) $profile_photo, '/');
    $base_url = '/ScanQuotient.v2/ScanQuotient.B';
    $avatar_url = $base_url . '/' . $photo_path;
} else {
    $avatar_url = '/ScanQuotient.v2/ScanQuotient.B/Storage/Public_images/default-avatar.png';
}

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Delete action for admins (POST only)
    if (isset($_POST['delete_id'])) {
        $deleteId = (int) $_POST['delete_id'];
        if ($deleteId > 0) {
            $stmt = $pdo->prepare('DELETE FROM scan_results WHERE id = :id');
            $stmt->execute([':id' => $deleteId]);
        }
        header('Location: admin_data_management.php?deleted=1&view=scans');
        exit;
    }

    // 1) Metrics + charts: keep using the most recent 200 rows (fast, stable)
    $metricsSql = "
        SELECT s.id,
               s.user_id,
               s.target_url,
               s.scan_json,
               s.created_at,
               s.pdf_path,
               s.html_path,
               s.csv_path,
               u.email,
               u.user_name
        FROM scan_results s
        LEFT JOIN users u ON u.user_id = s.user_id
        ORDER BY s.created_at DESC
        LIMIT 200
    ";
    $metricStmt = $pdo->query($metricsSql);
    $metricRows = $metricStmt->fetchAll() ?: [];

    $userIds = [];
    foreach ($metricRows as $row) {
        $userIds[$row['user_id']] = true;
        $data = json_decode($row['scan_json'] ?? '', true) ?: [];
        $summary = $data['summary'] ?? [];
        $riskLevel = $summary['risk_level'] ?? 'Unknown';
        if (!isset($metrics['by_risk'][$riskLevel])) {
            $metrics['by_risk'][$riskLevel] = 0;
        }
        $metrics['by_risk'][$riskLevel]++;
    }
    $metrics['total_scans'] = count($metricRows);
    $metrics['unique_users'] = count($userIds);

    // 2) Table: full pagination across all scan_results
    // Global search across all non-system columns in this table.
    // (We treat internal system identifiers as non-searchable.)
    $searchableExprs = [
        's.target_url' => 'target_url',
        's.pdf_path' => 'pdf_path',
        's.html_path' => 'html_path',
        's.csv_path' => 'csv_path',
        'u.email' => 'email',
        'u.user_name' => 'user_name',
    ];

    $whereSql = '';
    $whereParams = [];
    if ($scansSearch !== '') {
        $searchTerm = '%' . $scansSearch . '%';
        $orParts = [];
        foreach ($searchableExprs as $expr => $_label) {
            $orParts[] = "CAST($expr AS CHAR) LIKE ?";
            $whereParams[] = $searchTerm;
        }
        $whereSql = 'WHERE ' . implode(' OR ', $orParts);
    }
    if ($scansRiskFilter !== 'all') {
        $riskClause = "CAST(s.scan_json AS CHAR) LIKE ?";
        if ($whereSql === '') {
            $whereSql = "WHERE $riskClause";
        } else {
            $whereSql .= " AND $riskClause";
        }
        $whereParams[] = '%"risk_level":"' . $scansRiskFilter . '"%';
    }

    $countSql = "
        SELECT COUNT(*)
        FROM scan_results s
        LEFT JOIN users u ON u.user_id = s.user_id
        $whereSql
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($whereParams);
    $totalItems = (int) $countStmt->fetchColumn();
    $totalPages = $effectivePerPage === null || $totalItems === 0 ? 1 : (int) ceil($totalItems / $effectivePerPage);
    $offset = $effectivePerPage === null ? 0 : ($page - 1) * $effectivePerPage;

    $recordsStart = $totalItems === 0 ? 0 : ($offset + 1);
    $recordsEnd = $effectivePerPage === null ? $totalItems : (int) min($offset + $effectivePerPage, $totalItems);

    $tableBaseSql = "
        SELECT s.id,
               s.user_id,
               s.target_url,
               s.scan_json,
               s.created_at,
               s.pdf_path,
               s.html_path,
               s.csv_path,
               u.email,
               u.user_name
        FROM scan_results s
        LEFT JOIN users u ON u.user_id = s.user_id
        ORDER BY s.created_at DESC
    ";

    if ($whereSql !== '') {
        // Insert WHERE clause before ORDER BY.
        $tableBaseSql = str_replace('ORDER BY s.created_at DESC', $whereSql . ' ORDER BY s.created_at DESC', $tableBaseSql);
    }

    if ($effectivePerPage === null) {
        $pageSql = $tableBaseSql;
    } else {
        $pageSql = $tableBaseSql . " LIMIT " . (int) $effectivePerPage . " OFFSET " . (int) $offset;
    }
    $pageStmt = $pdo->prepare($pageSql);
    $pageStmt->execute($whereParams);
    $scanRows = $pageStmt->fetchAll() ?: [];

    // 3) Enterprise AI usage events (latest records for admin visibility)
    $pdo->exec("CREATE TABLE IF NOT EXISTS enterprise_ai_usage_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(64) NOT NULL,
        scan_id INT NOT NULL,
        event_type VARCHAR(50) NOT NULL,
        meta_json TEXT NULL,
        ip_address VARCHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_scan (scan_id),
        INDEX idx_user (user_id),
        INDEX idx_event (event_type),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $aiWhereParts = [];
    $aiWhereParams = [];
    if ($aiSearch !== '') {
        $term = '%' . $aiSearch . '%';
        $aiWhereParts[] = "(CAST(e.user_id AS CHAR) LIKE ? OR CAST(e.event_type AS CHAR) LIKE ? OR CAST(e.meta_json AS CHAR) LIKE ? OR CAST(e.ip_address AS CHAR) LIKE ? OR CAST(u.user_name AS CHAR) LIKE ? OR CAST(u.email AS CHAR) LIKE ?)";
        array_push($aiWhereParams, $term, $term, $term, $term, $term, $term);
    }
    if ($aiEventTypeFilter !== 'all') {
        $aiWhereParts[] = "e.event_type = ?";
        $aiWhereParams[] = $aiEventTypeFilter;
    }
    $aiWhereSql = empty($aiWhereParts) ? '' : ('WHERE ' . implode(' AND ', $aiWhereParts));

    $aiEventTotalStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM enterprise_ai_usage_events e
        LEFT JOIN users u ON u.user_id = e.user_id
        $aiWhereSql
    ");
    $aiEventTotalStmt->execute($aiWhereParams);
    $aiEventTotal = (int) $aiEventTotalStmt->fetchColumn();

    $aiStmt = $pdo->prepare("
        SELECT e.id, e.user_id, e.scan_id, e.event_type, e.meta_json, e.ip_address, e.created_at, u.user_name, u.email
        FROM enterprise_ai_usage_events e
        LEFT JOIN users u ON u.user_id = e.user_id
        $aiWhereSql
        ORDER BY e.created_at DESC
        LIMIT 150
    ");
    $aiStmt->execute($aiWhereParams);
    $aiEventRows = $aiStmt->fetchAll() ?: [];
    $aiMetrics['total_events'] = count($aiEventRows);
    $seenAiUsers = [];
    foreach ($aiEventRows as $ev) {
        $uid = (string) ($ev['user_id'] ?? '');
        if ($uid !== '') {
            $seenAiUsers[$uid] = true;
        }
        $evType = strtolower((string) ($ev['event_type'] ?? ''));
        if ($evType === 'ask_submitted') {
            $aiMetrics['ask_submitted']++;
        } elseif ($evType === 'ask_success') {
            $aiMetrics['ask_success']++;
        }
    }
    $aiMetrics['unique_users'] = count($seenAiUsers);

    // 4) Server logs (security-safe operational visibility for admins)
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_server_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        event_key VARCHAR(80) NOT NULL,
        level ENUM('info','warning','error') NOT NULL DEFAULT 'info',
        source VARCHAR(120) NOT NULL,
        message VARCHAR(255) NOT NULL,
        detail_json LONGTEXT NULL,
        user_id VARCHAR(64) NULL,
        request_ip VARCHAR(64) NULL,
        request_uri VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created_at (created_at),
        INDEX idx_level (level),
        INDEX idx_source (source),
        INDEX idx_event_key (event_key),
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $logWhereParts = [];
    $logWhereParams = [];
    if ($logSearch !== '') {
        $term = '%' . $logSearch . '%';
        $logWhereParts[] = "(CAST(event_key AS CHAR) LIKE ? OR CAST(source AS CHAR) LIKE ? OR CAST(message AS CHAR) LIKE ? OR CAST(user_id AS CHAR) LIKE ? OR CAST(request_ip AS CHAR) LIKE ? OR CAST(request_uri AS CHAR) LIKE ?)";
        array_push($logWhereParams, $term, $term, $term, $term, $term, $term);
    }
    if ($logLevelFilter !== 'all') {
        $logWhereParts[] = "level = ?";
        $logWhereParams[] = $logLevelFilter;
    }
    if ($logSourceFilter !== 'all') {
        if ($logSourceFilter === 'system') {
            $logWhereParts[] = "(source NOT LIKE 'web_scanner.%' AND source <> 'enterprise_ai_api')";
        } else {
            $logWhereParts[] = "source = ?";
            $logWhereParams[] = $logSourceFilter;
        }
    }
    $logWhereSql = empty($logWhereParts) ? '' : ('WHERE ' . implode(' AND ', $logWhereParts));

    $serverLogTotalStmt = $pdo->prepare("SELECT COUNT(*) FROM system_server_logs $logWhereSql");
    $serverLogTotalStmt->execute($logWhereParams);
    $serverLogTotal = (int) $serverLogTotalStmt->fetchColumn();

    $serverLogRowsStmt = $pdo->prepare("
        SELECT id, event_key, level, source, message, user_id, request_ip, request_uri, created_at
        FROM system_server_logs
        $logWhereSql
        ORDER BY created_at DESC
        LIMIT 200
    ");
    $serverLogRowsStmt->execute($logWhereParams);
    $serverLogRows = $serverLogRowsStmt->fetchAll() ?: [];

    $serverLogMetricsStmt = $pdo->query("
        SELECT
            SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) AS errors_24h,
            SUM(CASE WHEN level = 'warning' THEN 1 ELSE 0 END) AS warnings_24h,
            COUNT(DISTINCT NULLIF(user_id, '')) AS unique_users_24h,
            SUM(CASE WHEN source LIKE 'web_scanner.%' THEN 1 ELSE 0 END) AS scanner_related_24h
        FROM system_server_logs
        WHERE created_at >= (NOW() - INTERVAL 24 HOUR)
    ");
    $serverLogMetricRow = $serverLogMetricsStmt->fetch() ?: [];
    $serverLogMetrics['errors_24h'] = (int) ($serverLogMetricRow['errors_24h'] ?? 0);
    $serverLogMetrics['warnings_24h'] = (int) ($serverLogMetricRow['warnings_24h'] ?? 0);
    $serverLogMetrics['unique_users_24h'] = (int) ($serverLogMetricRow['unique_users_24h'] ?? 0);
    $serverLogMetrics['scanner_related_24h'] = (int) ($serverLogMetricRow['scanner_related_24h'] ?? 0);
} catch (Exception $e) {
    $adminError = 'Unable to load scan data right now.';
}

$downloadBaseAdmin = '/ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Backend/download_scan.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Data Management | ScanQuotient</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --critical-color: #dc2626;
            --info-color: #3b82f6;
            --admin-accent: #f59e0b;
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
            --admin-accent: #fbbf24;
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
            transition: background-color 0.3s ease, color 0.3s ease;
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
            transition: all 0.3s ease;
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
            color: #6f0ace;
            text-decoration: none;
            letter-spacing: -0.5px;
            transition: color 0.3s ease;
        }

        body.dark .header-brand {
            color: #6f0ace;
        }

        .header-tagline {
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
            letter-spacing: 0.5px;
            transition: color 0.3s ease;
        }

        body.dark .header-tagline {
            color: #94a3b8;
        }

        .welcome-text {
            font-size: 14px;
            color: #64748b;
            font-weight: 600;
            margin-right: 16px;
            transition: color 0.3s ease;
        }

        body.dark .welcome-text {
            color: #94a3b8;
        }

        .header-right {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .sq-admin-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-wrapper {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .sq-admin-back-btn {
            color: #3b82f6;
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
        }

        body.dark .sq-admin-back-btn {
            color: #94a3b8;
            background: rgba(30, 27, 75, 0.5);
            border-color: rgba(139, 92, 246, 0.2);
        }

        .sq-admin-back-btn:hover {
            background: rgba(59, 130, 246, 0.12);
            color: #1d4ed8;
            transform: translateY(-2px);
        }

        body.dark .sq-admin-back-btn:hover {
            color: #0f0f1a;
        }

        .sq-admin-user {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #3b82f6;
            font-weight: 600;
            margin-right: 6px;
        }

        body.dark .sq-admin-user {
            color: #94a3b8;
        }

        .sq-admin-user i {
            color: #3b82f6;
        }

        body.dark .sq-admin-user i {
            color: #94a3b8;
        }

        .header-profile-photo {
            width: 38px !important;
            height: 38px !important;
            min-width: 38px;
            min-height: 38px;
            max-width: 38px !important;
            max-height: 38px !important;
            display: block;
            flex: 0 0 38px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(59, 130, 246, 0.35);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.12);
        }

        .icon-btn {
            color: #3b82f6;
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

        body.dark .icon-btn {
            color: #94a3b8;
            background: rgba(30, 27, 75, 0.5);
            border-color: rgba(139, 92, 246, 0.2);
        }

        .icon-btn:hover {
            background: rgba(59, 130, 246, 0.12);
            color: #1d4ed8;
            transform: translateY(-2px);
        }

        body.dark .icon-btn:hover {
            color: #0f0f1a;
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

        /* Main Content */
        .app {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        .simple-sidebar {
            width: 220px;
            background: var(--bg-card);
            border-right: 1px solid var(--border-color);
            padding: 18px 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .simple-sidebar-title {
            font-size: 12px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 700;
            padding: 6px 10px;
        }

        .side-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            text-decoration: none;
            color: var(--text-light);
            border: 1px solid transparent;
            font-size: 13px;
            font-weight: 600;
        }

        .side-link:hover {
            background: var(--bg-main);
            border-color: var(--border-color);
            color: var(--text-main);
        }

        .side-link.active {
            background: var(--brand-color);
            color: white;
            border-color: var(--brand-color);
        }

        .main {
            flex: 1;
            padding: 32px;
            overflow-y: auto;
            background: var(--bg-main);
        }

        .admin-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Module Selector */
        .module-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            background: var(--bg-card);
            padding: 8px;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .module-tab {
            padding: 12px 24px;
            border-radius: 12px;
            border: none;
            background: transparent;
            color: var(--text-light);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .module-tab:hover {
            background: var(--bg-main);
            color: var(--text-main);
        }

        .module-tab.active {
            background: var(--brand-color);
            color: white;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--brand-color);
        }

        .kpi-card.success::before {
            background: var(--success-color);
        }

        .kpi-card.warning::before {
            background: var(--warning-color);
        }

        .kpi-card.danger::before {
            background: var(--danger-color);
        }

        .kpi-card.info::before {
            background: var(--info-color);
        }

        .kpi-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .kpi-label {
            font-size: 13px;
            color: var(--text-light);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .kpi-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            background: rgba(59, 130, 246, 0.1);
            color: var(--brand-color);
        }

        .kpi-card.success .kpi-icon {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .kpi-card.warning .kpi-icon {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }

        .kpi-card.danger .kpi-icon {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }

        .kpi-value {
            font-size: 32px;
            font-weight: 800;
            color: var(--text-main);
            line-height: 1;
            margin-bottom: 8px;
        }

        .kpi-change {
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .kpi-change.positive {
            color: var(--success-color);
        }

        .kpi-change.negative {
            color: var(--danger-color);
        }

        /* Chart Grid */
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-bottom: 24px;
        }

        .chart-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 24px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chart-title i {
            color: var(--brand-color);
        }

        .chart-actions {
            display: flex;
            gap: 8px;
        }

        .chart-btn {
            padding: 6px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-main);
            color: var(--text-light);
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .chart-btn:hover,
        .chart-btn.active {
            background: var(--brand-color);
            color: white;
            border-color: var(--brand-color);
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        .chart-container.large {
            height: 400px;
        }

        /* Data Quality Metrics */
        .quality-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }

        .quality-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.3s ease;
        }

        .quality-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .quality-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-main);
        }

        .quality-score {
            font-size: 24px;
            font-weight: 800;
            color: var(--success-color);
        }

        .quality-bar {
            width: 100%;
            height: 8px;
            background: var(--border-color);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 12px;
        }

        .quality-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 1s ease;
        }

        .quality-fill.excellent {
            background: var(--success-color);
            width: 98%;
        }

        .quality-fill.good {
            background: var(--info-color);
            width: 87%;
        }

        .quality-fill.warning {
            background: var(--warning-color);
            width: 72%;
        }

        .quality-details {
            font-size: 12px;
            color: var(--text-light);
        }

        /* System Health Timeline */
        .timeline-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 24px;
            transition: all 0.3s ease;
        }

        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .timeline-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-main);
        }

        .event-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .event-item {
            display: flex;
            gap: 16px;
            align-items: flex-start;
            padding: 16px;
            background: var(--bg-main);
            border-radius: 12px;
            border-left: 3px solid var(--brand-color);
            transition: all 0.3s ease;
        }

        .event-item:hover {
            transform: translateX(4px);
        }

        .event-item.critical {
            border-left-color: var(--critical-color);
        }

        .event-item.warning {
            border-left-color: var(--warning-color);
        }

        .event-item.success {
            border-left-color: var(--success-color);
        }

        .event-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            background: rgba(59, 130, 246, 0.1);
            color: var(--brand-color);
            flex-shrink: 0;
        }

        .event-item.critical .event-icon {
            background: rgba(220, 38, 38, 0.1);
            color: var(--critical-color);
        }

        .event-item.warning .event-icon {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }

        .event-item.success .event-icon {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .event-content {
            flex: 1;
        }

        .event-title {
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 4px;
        }

        .event-desc {
            font-size: 13px;
            color: var(--text-light);
        }

        .event-time {
            font-size: 12px;
            color: var(--text-light);
            white-space: nowrap;
        }

        /* Module Performance Table */
        .performance-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 24px;
            transition: all 0.3 ease;
        }

        .module-table {
            width: 100%;
            border-collapse: collapse;
        }

        .module-table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border-color);
        }

        .module-table td {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
            color: var(--text-main);
        }

        .module-name {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .module-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-main);
            color: var(--brand-color);
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: var(--border-color);
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.5s ease;
        }

        .progress-fill.high {
            background: var(--success-color);
        }

        .progress-fill.medium {
            background: var(--warning-color);
        }

        .progress-fill.low {
            background: var(--danger-color);
        }

        /* Footer */
        /* Footer - match scan history style */
        .page-footer {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            align-items: center;
            padding: 20px 32px;
            font-size: 13px;
            color: #64748b;
            background: linear-gradient(to right, #ffffff, #ADD8E6);
            border-top: 1px solid var(--border-color);
            margin-top: auto;
            transition: all 0.3s ease;
        }

        body.dark .page-footer {
            background: linear-gradient(to right, #0f0f1a, #1e1b4b);
            color: #94a3b8;
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

        body.dark .footer-brand {
            color: #a78bfa;
        }

        /* Toast Notifications */
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
        }

        .toast-icon.success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        .toast-icon.warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .chart-grid {
                grid-template-columns: 1fr;
            }

            .quality-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .kpi-grid {
                grid-template-columns: 1fr;
            }

            .quality-grid {
                grid-template-columns: 1fr;
            }

            .sidebar {
                width: 70px;
                padding: 16px 8px;
            }

            .sidebar .brand,
            .sidebar .nav-btn span,
            .sidebar .system-status {
                display: none;
            }

            .simple-sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid var(--border-color);
            }

            .app {
                flex-direction: column;
            }

            .page-header::before {
                display: none;
            }

            .action-group {
                flex-direction: column;
                width: 100%;
            }

            .action-btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Simple risk pill badges for admin table */
        .risk-pill {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .risk-pill.critical {
            background: rgba(220, 38, 38, 0.1);
            color: var(--critical-color);
        }

        .risk-pill.high {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }

        .risk-pill.medium {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }

        .risk-pill.low {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info-color);
        }

        .risk-pill.secure {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }

        /* Table container for scrolling */
        .table-container {
            overflow-x: auto;
            margin-top: 16px;
        }

        /* Dark mode toggle specific styles */
        .theme-toggle {
            position: relative;
            overflow: hidden;
        }

        .theme-toggle i {
            transition: transform 0.3s ease;
        }

        body.dark .theme-toggle i.fa-moon {
            transform: rotate(360deg);
        }

        body:not(.dark) .theme-toggle i.fa-sun {
            transform: rotate(360deg);
        }

        /* Smooth transitions for all theme changes */
        * {
            transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease, box-shadow 0.3s ease;
        }

        /* ===== IMPROVED ACTION BUTTONS STYLING ===== */

        /* Container for action buttons */
        .action-group {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: nowrap;
        }

        /* Individual action button */
        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid var(--border-color);
            background: var(--bg-main);
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
            min-width: fit-content;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        body.dark .action-btn:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
        }

        /* HTML button - Blue */
        .action-btn.html {
            background: rgba(59, 130, 246, 0.1);
            color: var(--brand-color);
            border-color: rgba(59, 130, 246, 0.2);
        }

        .action-btn.html:hover {
            background: var(--brand-color);
            color: white;
            border-color: var(--brand-color);
        }

        /* CSV button - Green */
        .action-btn.csv {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
            border-color: rgba(16, 185, 129, 0.2);
        }

        .action-btn.csv:hover {
            background: var(--success-color);
            color: white;
            border-color: var(--success-color);
        }

        /* PDF button - Red/Orange */
        .action-btn.pdf {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
            border-color: rgba(239, 68, 68, 0.2);
        }

        .action-btn.pdf:hover {
            background: var(--danger-color);
            color: white;
            border-color: var(--danger-color);
        }

        .action-btn.disabled {
            opacity: 0.55;
            cursor: not-allowed;
            pointer-events: none;
            filter: blur(0.2px);
        }

        /* Delete button - Gray/Red */
        .action-btn.delete {
            background: rgba(100, 116, 139, 0.1);
            color: var(--text-light);
            border-color: rgba(100, 116, 139, 0.2);
        }

        .action-btn.delete:hover {
            background: var(--danger-color);
            color: white;
            border-color: var(--danger-color);
        }

        /* Icon sizing within buttons */
        .action-btn i {
            font-size: 13px;
        }

        /* Ensure table cell doesn't wrap */
        .module-table td:last-child {
            min-width: 320px;
            white-space: nowrap;
        }
    </style>
</head>

<body>

    <header class="page-header">
        <div class="sq-admin-header-left">
            <a href="/ScanQuotient.v2/ScanQuotient.B/Private/Admin_dashboard/PHP/Frontend/Admin_dashboard.php"
                class="sq-admin-back-btn">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div class="brand-wrapper">
                <a href="#" class="header-brand" style="color:#6c63ff;">ScanQuotient</a>
                <span class="header-tagline">Quantifying Risk. Strengthening Security.</span>
            </div>
        </div>
        <div class="header-right">
            <img src="<?php echo htmlspecialchars($avatar_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile"
                class="header-profile-photo">
            <div class="sq-admin-user">
                <i class="fas fa-user-shield"></i>
                <span><?php echo htmlspecialchars($adminName); ?> | <span id="current-time"></span></span>
            </div>
            <button class="icon-btn theme-toggle" id="theme-toggle" title="Toggle Dark Mode">
                <i class="fas fa-moon"></i>
            </button>
            <a href="/ScanQuotient.v2/ScanQuotient.B/Private/Admin_dashboard/PHP/Frontend/Admin_dashboard.php"
                class="icon-btn" title="Home"><i class="fas fa-home"></i></a>
            <a href="/ScanQuotient.v2/ScanQuotient.B/Public/Login_page/PHP/Frontend/Login_page_site.php"
                class="icon-btn" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </header>

    <div class="app">
        <aside class="simple-sidebar">
            <div class="simple-sidebar-title">Data Modules</div>
            <a class="side-link <?php echo $activeView === 'scans' ? 'active' : ''; ?>"
                href="admin_data_management.php?view=scans">
                <i class="fas fa-database"></i> Scan Results
            </a>
            <a class="side-link <?php echo $activeView === 'ai' ? 'active' : ''; ?>"
                href="admin_data_management.php?view=ai">
                <i class="fas fa-brain"></i> Enterprise AI Events
            </a>
            <a class="side-link <?php echo $activeView === 'server_logs' ? 'active' : ''; ?>"
                href="admin_data_management.php?view=server_logs">
                <i class="fas fa-server"></i> Server Logs
            </a>
        </aside>
        <main class="main">
            <div class="admin-container">

                <?php if ($activeView === 'scans'): ?>
                    <div class="kpi-grid">
                        <div class="kpi-card info">
                            <div class="kpi-header">
                                <span class="kpi-label">Total Scans</span>
                                <div class="kpi-icon"><i class="fas fa-tasks"></i></div>
                            </div>
                            <div class="kpi-value"><?php echo number_format($metrics['total_scans']); ?></div>
                        </div>
                        <div class="kpi-card success">
                            <div class="kpi-header">
                                <span class="kpi-label">Unique Users</span>
                                <div class="kpi-icon"><i class="fas fa-users"></i></div>
                            </div>
                            <div class="kpi-value"><?php echo number_format($metrics['unique_users']); ?></div>
                        </div>
                        <div class="kpi-card warning">
                            <div class="kpi-header">
                                <span class="kpi-label">High & Critical</span>
                                <div class="kpi-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            </div>
                            <div class="kpi-value">
                                <?php echo (int) ($metrics['by_risk']['High'] + $metrics['by_risk']['Critical']); ?>
                            </div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-header">
                                <span class="kpi-label">Secure Scans</span>
                                <div class="kpi-icon"><i class="fas fa-shield-alt"></i></div>
                            </div>
                            <div class="kpi-value"><?php echo (int) $metrics['by_risk']['Secure']; ?></div>
                        </div>
                    </div>

                    <div class="card quality-card">
                        <div class="quality-header">
                            <span class="quality-title">All Scan Results</span>
                            <form method="GET" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                <input type="hidden" name="view" value="scans" />
                                <input type="hidden" name="page" value="1" />
                                <input type="hidden" name="per_page"
                                    value="<?php echo htmlspecialchars((string) $perPageParam); ?>" />
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <i class="fas fa-search" style="color: var(--text-light);"></i>
                                    <input type="text" name="scans_search" placeholder="Search scans..."
                                        value="<?php echo htmlspecialchars($scansSearch); ?>"
                                        style="padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); min-width: 260px;" />
                                </div>
                                <select name="scans_risk"
                                    style="padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); min-width: 170px;">
                                    <?php foreach ($allowedScanRisks as $riskOpt): ?>
                                        <option value="<?php echo htmlspecialchars($riskOpt); ?>"
                                            <?php echo $scansRiskFilter === $riskOpt ? 'selected' : ''; ?>>
                                            <?php echo $riskOpt === 'all' ? 'All Risks' : $riskOpt; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="action-btn" style="padding: 10px 14px; text-decoration:none;">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                            </form>
                            <?php if ($adminError): ?>
                                <span style="color:#fecaca;font-size:13px;"><?php echo htmlspecialchars($adminError); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="table-container">
                            <table class="module-table">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Target (masked)</th>
                                        <th>Risk</th>
                                        <th>Score</th>
                                        <th>Total Issues</th>
                                        <th>Scanned At</th>
                                        <?php if ($scansSearch !== ''): ?>
                                            <th>Matched In</th>
                                        <?php endif; ?>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($scanRows)): ?>
                                        <tr>
                                            <td colspan="<?php echo $scansSearch !== '' ? 8 : 7; ?>">No scan data available yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($scanRows as $row): ?>
                                            <?php
                                            $data = json_decode($row['scan_json'] ?? '', true) ?: [];
                                            $summary = $data['summary'] ?? [];
                                            $riskLevel = $summary['risk_level'] ?? 'Unknown';
                                            $riskScore = $summary['risk_score'] ?? 0;
                                            $totalVulns = $summary['total_vulnerabilities'] ?? 0;
                                            $createdAt = $row['created_at'] ?? '';
                                            $targetUrl = $row['target_url'] ?? ($data['target'] ?? '');
                                            $userLabel = $row['user_name'] ?: ($row['email'] ?: $row['user_id']);
                                            // Mask user label and URL to reduce data mining risk
                                            $maskedUser = $userLabel ? (substr($userLabel, 0, 1) . '***') : 'N/A';
                                            $parsed = parse_url($targetUrl);
                                            $host = $parsed['host'] ?? '';
                                            if ($host !== '') {
                                                $parts = explode('.', $host);
                                                $tld = array_pop($parts);
                                                $base = implode('.', $parts);
                                                $maskedHost = substr($base, 0, 3) . '***.' . $tld;
                                                $maskedTarget = ($parsed['scheme'] ?? 'https') . '://' . $maskedHost;
                                            } else {
                                                $maskedTarget = 'masked-target';
                                            }
                                            $badgeClass = strtolower($riskLevel);
                                            $matchedInStr = '-';
                                            if ($scansSearch !== '') {
                                                $matchedCols = [];
                                                foreach (['target_url', 'pdf_path', 'html_path', 'csv_path', 'email', 'user_name'] as $key) {
                                                    $val = $row[$key] ?? '';
                                                    if ($val === null)
                                                        continue;
                                                    if (stripos((string) $val, $scansSearch) !== false) {
                                                        $matchedCols[] = $key;
                                                    }
                                                }
                                                if (!empty($matchedCols))
                                                    $matchedInStr = implode(', ', $matchedCols);
                                            }
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="module-name">
                                                        <div class="module-icon">
                                                            <i class="fas fa-user-shield"></i>
                                                        </div>
                                                        <span><?php echo htmlspecialchars($maskedUser); ?></span>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($maskedTarget); ?></td>
                                                <td>
                                                    <span
                                                        class="risk-pill <?php echo htmlspecialchars(strtolower($badgeClass)); ?>">
                                                        <?php echo htmlspecialchars($riskLevel); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo (int) $riskScore; ?></td>
                                                <td><?php echo (int) $totalVulns; ?></td>
                                                <td><?php echo htmlspecialchars($createdAt); ?></td>
                                                <?php if ($scansSearch !== ''): ?>
                                                    <td><?php echo htmlspecialchars($matchedInStr); ?></td>
                                                <?php endif; ?>
                                                <td>
                                                    <div class="action-group">
                                                        <a class="action-btn html"
                                                            href="<?php echo $downloadBaseAdmin . '?id=' . (int) $row['id'] . '&type=html'; ?>">
                                                            <i class="fas fa-file-code"></i> HTML
                                                        </a>
                                                        <a class="action-btn csv"
                                                            href="<?php echo $downloadBaseAdmin . '?id=' . (int) $row['id'] . '&type=csv'; ?>">
                                                            <i class="fas fa-file-csv"></i> CSV
                                                        </a>
                                                        <span class="action-btn pdf disabled" title="PDF temporarily disabled">
                                                            <i class="fas fa-file-pdf"></i> PDF
                                                        </span>
                                                        <button type="button" class="action-btn delete js-delete-btn"
                                                            data-delete-id="<?php echo (int) $row['id']; ?>">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>

                            <?php if (!empty($totalItems) && (int) $totalItems > 0): ?>
                                <div class="sq-admin-pagination-bar">
                                    <label>
                                        Show
                                        <select id="sqPerPageSelect" class="sq-per-page-select"
                                            onchange="sqUpdatePerPage(this.value)">
                                            <?php
                                            $perPageOptions = ['5', '10', '20', '50', '100', '200', 'all'];
                                            foreach ($perPageOptions as $opt):
                                                $selected = ((string) $perPageParam === (string) $opt) ? 'selected' : '';
                                                ?>
                                                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $selected; ?>>
                                                    <?php echo $opt === 'all' ? 'All' : $opt; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        entries
                                    </label>
                                    <span class="sq-admin-record-info">
                                        Showing <?php echo (int) $recordsStart; ?>–<?php echo (int) $recordsEnd; ?> of
                                        <?php echo number_format((int) $totalItems); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if ($totalPages > 1): ?>
                                <div class="sq-admin-pagination">
                                    <?php
                                    $queryParams = "view=scans&per_page=" . urlencode((string) $perPageParam);
                                    if ($scansSearch !== '') {
                                        $queryParams .= "&scans_search=" . urlencode($scansSearch);
                                    }
                                    if ($scansRiskFilter !== 'all') {
                                        $queryParams .= "&scans_risk=" . urlencode($scansRiskFilter);
                                    }
                                    $startPage = max(1, $page - 2);
                                    $endPage = min((int) $totalPages, $page + 2);
                                    ?>

                                    <?php if ($page > 1): ?>
                                        <a class="sq-admin-page-btn"
                                            href="admin_data_management.php?<?php echo $queryParams; ?>&page=<?php echo $page - 1; ?>">
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="sq-admin-page-btn disabled">
                                            <i class="fas fa-chevron-left"></i>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($startPage > 1): ?>
                                        <a class="sq-admin-page-btn"
                                            href="admin_data_management.php?<?php echo $queryParams; ?>&page=1">1</a>
                                        <?php if ($startPage > 2): ?>
                                            <span class="sq-admin-page-btn disabled">...</span>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                        <?php if ($i == $page): ?>
                                            <span class="sq-admin-page-btn active"><?php echo $i; ?></span>
                                        <?php else: ?>
                                            <a class="sq-admin-page-btn"
                                                href="admin_data_management.php?<?php echo $queryParams; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>

                                    <?php if ($endPage < $totalPages): ?>
                                        <?php if ($endPage < $totalPages - 1): ?>
                                            <span class="sq-admin-page-btn disabled">...</span>
                                        <?php endif; ?>
                                        <a class="sq-admin-page-btn"
                                            href="admin_data_management.php?<?php echo $queryParams; ?>&page=<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a>
                                    <?php endif; ?>

                                    <?php if ($page < $totalPages): ?>
                                        <a class="sq-admin-page-btn"
                                            href="admin_data_management.php?<?php echo $queryParams; ?>&page=<?php echo $page + 1; ?>">
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="sq-admin-page-btn disabled">
                                            <i class="fas fa-chevron-right"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($activeView === 'ai'): ?>
                    <div class="kpi-grid">
                        <div class="kpi-card info">
                            <div class="kpi-header">
                                <span class="kpi-label">Total AI Events</span>
                                <div class="kpi-icon"><i class="fas fa-chart-line"></i></div>
                            </div>
                            <div class="kpi-value"><?php echo number_format((int) $aiEventTotal); ?></div>
                        </div>
                        <div class="kpi-card success">
                            <div class="kpi-header">
                                <span class="kpi-label">Unique AI Users</span>
                                <div class="kpi-icon"><i class="fas fa-users"></i></div>
                            </div>
                            <div class="kpi-value"><?php echo number_format((int) $aiMetrics['unique_users']); ?></div>
                        </div>
                        <div class="kpi-card warning">
                            <div class="kpi-header">
                                <span class="kpi-label">Questions Asked</span>
                                <div class="kpi-icon"><i class="fas fa-question-circle"></i></div>
                            </div>
                            <div class="kpi-value"><?php echo number_format((int) $aiMetrics['ask_submitted']); ?></div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-header">
                                <span class="kpi-label">Answers Generated</span>
                                <div class="kpi-icon"><i class="fas fa-robot"></i></div>
                            </div>
                            <div class="kpi-value"><?php echo number_format((int) $aiMetrics['ask_success']); ?></div>
                        </div>
                    </div>

                    <div class="card quality-card">
                        <div class="quality-header">
                            <span class="quality-title">Enterprise AI Usage Events</span>
                            <form method="GET" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                <input type="hidden" name="view" value="ai" />
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <i class="fas fa-search" style="color: var(--text-light);"></i>
                                    <input type="text" name="ai_search" placeholder="Search AI events..."
                                        value="<?php echo htmlspecialchars($aiSearch); ?>"
                                        style="padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); min-width: 240px;" />
                                </div>
                                <select name="ai_event_type"
                                    style="padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); min-width: 170px;">
                                    <?php foreach ($allowedAiEventTypes as $eventOpt): ?>
                                        <option value="<?php echo htmlspecialchars($eventOpt); ?>"
                                            <?php echo $aiEventTypeFilter === $eventOpt ? 'selected' : ''; ?>>
                                            <?php echo $eventOpt === 'all' ? 'All Event Types' : $eventOpt; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="action-btn" style="padding: 10px 14px; text-decoration:none;">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                            </form>
                            <span style="font-size: 13px; color: var(--text-light);">
                                Showing <?php echo number_format((int) count($aiEventRows)); ?> / Total
                                <?php echo number_format((int) $aiEventTotal); ?>
                            </span>
                        </div>
                        <div class="table-container">
                            <table class="module-table">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Scan ID</th>
                                        <th>Event Type</th>
                                        <th>Meta</th>
                                        <th>IP</th>
                                        <th>Created At</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($aiEventRows)): ?>
                                        <tr>
                                            <td colspan="7">No Enterprise AI usage events logged yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($aiEventRows as $ev): ?>
                                            <?php
                                            $evUser = $ev['user_name'] ?: ($ev['email'] ?: $ev['user_id']);
                                            $evMaskedUser = $evUser ? (substr($evUser, 0, 1) . '***') : 'N/A';
                                            $metaShort = '';
                                            if (!empty($ev['meta_json'])) {
                                                $metaShort = trim((string) $ev['meta_json']);
                                                if (strlen($metaShort) > 140) {
                                                    $metaShort = substr($metaShort, 0, 140) . '...';
                                                }
                                            } else {
                                                $metaShort = '-';
                                            }
                                            $evScanId = (int) ($ev['scan_id'] ?? 0);
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($evMaskedUser); ?></td>
                                                <td><?php echo $evScanId; ?></td>
                                                <td><?php echo htmlspecialchars((string) ($ev['event_type'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars($metaShort); ?></td>
                                                <td><?php echo htmlspecialchars((string) ($ev['ip_address'] ?? '-')); ?></td>
                                                <td><?php echo htmlspecialchars((string) ($ev['created_at'] ?? '')); ?></td>
                                                <td>
                                                    <?php if ($evScanId > 0): ?>
                                                        <a class="action-btn html"
                                                            href="/ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Frontend/enterprise_ai_overview.php?scan_id=<?php echo $evScanId; ?>">
                                                            <i class="fas fa-up-right-from-square"></i> Open AI Page
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="action-btn disabled">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="kpi-grid">
                        <div class="kpi-card warning">
                            <div class="kpi-header">
                                <span class="kpi-label">Errors (24h)</span>
                                <div class="kpi-icon"><i class="fas fa-triangle-exclamation"></i></div>
                            </div>
                            <div class="kpi-value"><?php echo number_format((int) $serverLogMetrics['errors_24h']); ?></div>
                        </div>
                        <div class="kpi-card info">
                            <div class="kpi-header">
                                <span class="kpi-label">Warnings (24h)</span>
                                <div class="kpi-icon"><i class="fas fa-circle-info"></i></div>
                            </div>
                            <div class="kpi-value"><?php echo number_format((int) $serverLogMetrics['warnings_24h']); ?></div>
                        </div>
                        <div class="kpi-card success">
                            <div class="kpi-header">
                                <span class="kpi-label">Active Users (24h)</span>
                                <div class="kpi-icon"><i class="fas fa-users"></i></div>
                            </div>
                            <div class="kpi-value"><?php echo number_format((int) $serverLogMetrics['unique_users_24h']); ?>
                            </div>
                        </div>
                        <div class="kpi-card">
                            <div class="kpi-header">
                                <span class="kpi-label">Scanner Logs (24h)</span>
                                <div class="kpi-icon"><i class="fas fa-shield-halved"></i></div>
                            </div>
                            <div class="kpi-value">
                                <?php echo number_format((int) $serverLogMetrics['scanner_related_24h']); ?>
                            </div>
                        </div>
                    </div>

                    <div class="card quality-card">
                        <div class="quality-header">
                            <span class="quality-title">System Server Logs</span>
                            <form method="GET" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                <input type="hidden" name="view" value="server_logs" />
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <i class="fas fa-search" style="color: var(--text-light);"></i>
                                    <input type="text" name="log_search" placeholder="Search logs..."
                                        value="<?php echo htmlspecialchars($logSearch); ?>"
                                        style="padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); min-width: 220px;" />
                                </div>
                                <select name="log_level"
                                    style="padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); min-width: 120px;">
                                    <option value="all" <?php echo $logLevelFilter === 'all' ? 'selected' : ''; ?>>All Levels</option>
                                    <option value="info" <?php echo $logLevelFilter === 'info' ? 'selected' : ''; ?>>Info</option>
                                    <option value="warning" <?php echo $logLevelFilter === 'warning' ? 'selected' : ''; ?>>Warning</option>
                                    <option value="error" <?php echo $logLevelFilter === 'error' ? 'selected' : ''; ?>>Error</option>
                                </select>
                                <select name="log_source"
                                    style="padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); min-width: 180px;">
                                    <option value="all" <?php echo $logSourceFilter === 'all' ? 'selected' : ''; ?>>All Sources</option>
                                    <option value="web_scanner.scan_proxy" <?php echo $logSourceFilter === 'web_scanner.scan_proxy' ? 'selected' : ''; ?>>Scanner Proxy</option>
                                    <option value="enterprise_ai_api" <?php echo $logSourceFilter === 'enterprise_ai_api' ? 'selected' : ''; ?>>Enterprise AI API</option>
                                    <option value="system" <?php echo $logSourceFilter === 'system' ? 'selected' : ''; ?>>System (Other)</option>
                                </select>
                                <button type="submit" class="action-btn" style="padding: 10px 14px; text-decoration:none;">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                            </form>
                            <span style="font-size: 13px; color: var(--text-light);">
                                Showing <?php echo number_format((int) count($serverLogRows)); ?> / Total
                                <?php echo number_format((int) $serverLogTotal); ?>
                            </span>
                        </div>
                        <div class="table-container">
                            <table class="module-table">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Level</th>
                                        <th>Source</th>
                                        <th>Event Key</th>
                                        <th>Message</th>
                                        <th>User</th>
                                        <th>IP</th>
                                        <th>URI</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($serverLogRows)): ?>
                                        <tr>
                                            <td colspan="8">No server logs available yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($serverLogRows as $log): ?>
                                            <?php
                                            $level = strtolower((string) ($log['level'] ?? 'info'));
                                            $levelBadgeStyle = 'background: rgba(59,130,246,0.15); color: #3b82f6;';
                                            if ($level === 'warning') {
                                                $levelBadgeStyle = 'background: rgba(245,158,11,0.16); color: #d97706;';
                                            } elseif ($level === 'error') {
                                                $levelBadgeStyle = 'background: rgba(239,68,68,0.16); color: #dc2626;';
                                            }
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string) ($log['created_at'] ?? '')); ?></td>
                                                <td><span class="risk-pill" style="<?php echo $levelBadgeStyle; ?>"><?php echo htmlspecialchars(strtoupper($level)); ?></span>
                                                </td>
                                                <td><?php echo htmlspecialchars((string) ($log['source'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars((string) ($log['event_key'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars((string) ($log['message'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars((string) ($log['user_id'] ?? '-')); ?></td>
                                                <td><?php echo htmlspecialchars((string) ($log['request_ip'] ?? '-')); ?></td>
                                                <td><?php echo htmlspecialchars((string) ($log['request_uri'] ?? '-')); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </main>
    </div>

    <button id="backToTopBtn" class="sq-back-to-top" title="Back to top" aria-label="Back to top" type="button">
        <i class="fas fa-arrow-up"></i>
    </button>

    <div class="modal-overlay" id="deleteModal" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
        <div class="modal-card">
            <div class="modal-head">
                <span class="warn-icon"><i class="fas fa-trash-alt"></i></span>
                <div class="modal-title" id="deleteModalTitle">Delete scan record</div>
            </div>
            <div class="modal-body">
                Are you sure you want to delete this scan record? This action cannot be undone.
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-btn" id="deleteCancelBtn">Cancel</button>
                <button type="button" class="modal-btn primary-danger" id="deleteConfirmBtn">Delete</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="deleteSuccessModal" role="dialog" aria-modal="true"
        aria-labelledby="deleteSuccessTitle">
        <div class="modal-card">
            <div class="modal-head">
                <span class="warn-icon" style="background: rgba(16, 185, 129, 0.15); color: var(--success-color);">
                    <i class="fas fa-check-circle"></i>
                </span>
                <div class="modal-title" id="deleteSuccessTitle">Deleted successfully</div>
            </div>
            <div class="modal-body">
                The scan record has been removed successfully.
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-btn" id="deleteSuccessOkBtn">OK</button>
            </div>
        </div>
    </div>

    <form id="deleteForm" method="POST" action="admin_data_management.php" style="display:none;">
        <input type="hidden" name="delete_id" id="deleteIdInput" value="">
    </form>

    <style>
        .sq-admin-pagination-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-top: 1px solid var(--border-color);
            flex-wrap: wrap;
        }

        .sq-admin-pagination-bar label {
            font-weight: 700;
            color: var(--text-light);
            font-size: 13px;
        }

        .sq-admin-pagination-bar select {
            margin: 0 8px;
            padding: 8px 12px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-main);
        }

        .sq-admin-record-info {
            color: var(--text-light);
            font-size: 12px;
        }

        .sq-admin-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 16px 0 0;
        }

        .sq-admin-page-btn {
            min-width: 40px;
            height: 40px;
            border-radius: 10px;
            border: 2px solid var(--border-color);
            background: var(--bg-main);
            color: var(--text-light);
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .sq-admin-page-btn:hover {
            border-color: var(--brand-color);
            color: var(--brand-color);
        }

        .sq-admin-page-btn.active {
            background: var(--brand-color);
            border-color: var(--brand-color);
            color: white;
        }

        .sq-admin-page-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        .sq-back-to-top {
            position: fixed;
            right: 24px;
            bottom: 24px;
            width: 44px;
            height: 44px;
            border-radius: 999px;
            border: none;
            background: #10b981;
            color: white;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
            z-index: 1000;
        }

        body.dark .sq-back-to-top {
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.4);
        }

        .sq-back-to-top.sq-back-to-top--visible {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(-2px);
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
            box-shadow: var(--shadow-lg);
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
    </style>

    <footer class="page-footer">
        <div class="footer-left">
            <span class="footer-brand">ScanQuotient Admin</span>
            <span style="margin-left: 8px; opacity: 0.7;">v2.4.1</span>
        </div>
        <div class="footer-center">
            System Status: Operational | Last Backup: 2 hours ago
        </div>
        <div class="footer-right">
            Authorized Personnel Only
        </div>
    </footer>

    <script>
        // Theme Management
        const themeToggle = document.getElementById('theme-toggle');
        const themeIcon = themeToggle.querySelector('i');

        // Check for saved theme preference or default to 'light'
        const currentTheme = localStorage.getItem('theme') || 'light';

        // Apply saved theme on page load
        if (currentTheme === 'dark') {
            document.body.classList.add('dark');
            themeIcon.classList.remove('fa-moon');
            themeIcon.classList.add('fa-sun');
        }

        // Toggle theme function
        themeToggle.addEventListener('click', () => {
            document.body.classList.toggle('dark');

            if (document.body.classList.contains('dark')) {
                localStorage.setItem('theme', 'dark');
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
            } else {
                localStorage.setItem('theme', 'light');
                themeIcon.classList.remove('fa-sun');
                themeIcon.classList.add('fa-moon');
            }
        });

        // Time Display
        function updateTime() {
            const el = document.getElementById('current-time');
            if (el) el.textContent = new Date().toLocaleTimeString();
        }
        updateTime();
        setInterval(updateTime, 1000);
    </script>

    <script>
        function sqUpdatePerPage(perPageValue) {
            const url = new URL(window.location.href);
            url.searchParams.set('per_page', perPageValue);
            url.searchParams.set('page', '1');
            url.searchParams.set('view', 'scans');
            window.location.href = url.toString();
        }

        (function () {
            const backToTopBtn = document.getElementById('backToTopBtn');
            if (!backToTopBtn) return;

            const onScroll = function () {
                backToTopBtn.classList.toggle('sq-back-to-top--visible', window.scrollY > 400);
            };

            window.addEventListener('scroll', onScroll);
            onScroll();

            backToTopBtn.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        })();

        (function () {
            const modal = document.getElementById('deleteModal');
            const cancelBtn = document.getElementById('deleteCancelBtn');
            const confirmBtn = document.getElementById('deleteConfirmBtn');
            const deleteIdInput = document.getElementById('deleteIdInput');
            const deleteForm = document.getElementById('deleteForm');
            let selectedId = null;

            function openDeleteModal(id) {
                selectedId = parseInt(id, 10);
                if (!selectedId) return;
                modal.classList.add('active');
            }

            function closeDeleteModal() {
                modal.classList.remove('active');
                selectedId = null;
            }

            document.querySelectorAll('.js-delete-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    openDeleteModal(this.getAttribute('data-delete-id'));
                });
            });

            cancelBtn?.addEventListener('click', closeDeleteModal);
            modal?.addEventListener('click', function (e) {
                if (e.target === modal) closeDeleteModal();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modal?.classList.contains('active')) closeDeleteModal();
            });

            confirmBtn?.addEventListener('click', function () {
                if (!selectedId) return;
                deleteIdInput.value = String(selectedId);
                deleteForm.submit();
            });
        })();

        (function () {
            const shouldShow = <?php echo $showDeleteSuccess ? 'true' : 'false'; ?>;
            if (!shouldShow) return;
            const successModal = document.getElementById('deleteSuccessModal');
            const okBtn = document.getElementById('deleteSuccessOkBtn');
            if (!successModal) return;

            function closeSuccessModal() {
                successModal.classList.remove('active');
                const url = new URL(window.location.href);
                url.searchParams.delete('deleted');
                window.history.replaceState({}, '', url.toString());
            }

            successModal.classList.add('active');
            okBtn?.addEventListener('click', closeSuccessModal);
            successModal.addEventListener('click', function (e) {
                if (e.target === successModal) closeSuccessModal();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && successModal.classList.contains('active')) closeSuccessModal();
            });
        })();
    </script>

</body>

</html>