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
$scansStatusFilter = trim((string) ($_GET['scans_status'] ?? 'active'));
if (!in_array($scansStatusFilter, ['active', 'deleted', 'all'], true)) {
    $scansStatusFilter = 'active';
}

$aiSearch = trim((string) ($_GET['ai_search'] ?? ''));
$aiEventTypeFilter = trim((string) ($_GET['ai_event_type'] ?? 'all'));
$allowedAiEventTypes = ['all', 'ask_submitted', 'ask_success', 'ask_error', 'clear_chat', 'page_view'];
if (!in_array($aiEventTypeFilter, $allowedAiEventTypes, true)) {
    $aiEventTypeFilter = 'all';
}
$aiStatusFilter = trim((string) ($_GET['ai_status'] ?? 'active'));
if (!in_array($aiStatusFilter, ['active', 'deleted', 'all'], true)) {
    $aiStatusFilter = 'active';
}
$aiPage = max(1, (int) ($_GET['ai_page'] ?? 1));
$aiPerPageParam = (string) ($_GET['ai_per_page'] ?? (string) DEFAULT_PER_PAGE);
if (!in_array($aiPerPageParam, $allowedPerPage, true)) {
    $aiPerPageParam = (string) DEFAULT_PER_PAGE;
}
$aiPerPage = $aiPerPageParam === 'all' ? null : (int) $aiPerPageParam;
$aiTotalPages = 1;
$aiRecordsStart = 0;
$aiRecordsEnd = 0;

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
$logStatusFilter = trim((string) ($_GET['log_status'] ?? 'active'));
if (!in_array($logStatusFilter, ['active', 'deleted', 'all'], true)) {
    $logStatusFilter = 'active';
}
$logPage = max(1, (int) ($_GET['log_page'] ?? 1));
$logPerPageParam = (string) ($_GET['log_per_page'] ?? (string) DEFAULT_PER_PAGE);
if (!in_array($logPerPageParam, $allowedPerPage, true)) {
    $logPerPageParam = (string) DEFAULT_PER_PAGE;
}
$logPerPage = $logPerPageParam === 'all' ? null : (int) $logPerPageParam;
$logTotalPages = 1;
$logRecordsStart = 0;
$logRecordsEnd = 0;

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
$serverLogsTimelineLabels = [];
$serverLogsTimelineErrors = [];
$serverLogsTimelineWarnings = [];
$aiMetrics = [
    'total_events' => 0,
    'unique_users' => 0,
    'ask_submitted' => 0,
    'ask_success' => 0,
];
$showDeleteSuccess = isset($_GET['deleted']) && (string) $_GET['deleted'] === '1';
$showActionToast = isset($_GET['action_done']) && (string) $_GET['action_done'] === '1';
$lastActionName = (string) ($_GET['action'] ?? '');
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

    // Ensure soft-delete columns exist.
    try {
        $pdo->exec("ALTER TABLE scan_results ADD COLUMN deleted_at DATETIME NULL");
    } catch (Exception $e) {
    }
    // Delete/restore action for admins (POST only)
    if (isset($_POST['record_action'], $_POST['record_type'], $_POST['record_id'])) {
        $action = (string) $_POST['record_action'];
        $type = (string) $_POST['record_type'];
        $id = (int) $_POST['record_id'];
        $redirectView = in_array($type, ['scans', 'ai', 'server_logs'], true) ? $type : 'scans';
        if ($id > 0) {
            $tableMap = [
                'scans' => 'scan_results',
                'ai' => 'enterprise_ai_usage_events',
                'server_logs' => 'system_server_logs',
            ];
            if (isset($tableMap[$type])) {
                $table = $tableMap[$type];
                if ($action === 'soft_delete') {
                    $stmt = $pdo->prepare("UPDATE {$table} SET deleted_at = NOW() WHERE id = :id");
                    $stmt->execute([':id' => $id]);
                } elseif ($action === 'restore') {
                    $stmt = $pdo->prepare("UPDATE {$table} SET deleted_at = NULL WHERE id = :id");
                    $stmt->execute([':id' => $id]);
                } elseif ($action === 'hard_delete') {
                    $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id = :id");
                    $stmt->execute([':id' => $id]);
                } elseif ($action === 'clear_chat' && $type === 'ai') {
                    $stmt = $pdo->prepare("UPDATE enterprise_ai_usage_events SET meta_json = NULL WHERE id = :id");
                    $stmt->execute([':id' => $id]);
                }
            }
        }
        header('Location: admin_data_management.php?view=' . urlencode($redirectView) . '&action_done=1&action=' . urlencode($action));
        exit;
    }

    // 1) Metrics + charts: keep using the most recent 200 rows (fast, stable)
    $metricsSql = "
        SELECT s.id,
               s.user_id,
               s.target_url,
               s.scan_json,
               s.created_at,
               s.deleted_at,
               s.pdf_path,
               s.doc_path,
               s.html_path,
               s.csv_path,
               u.email,
               u.user_name
        FROM scan_results s
        LEFT JOIN users u ON u.user_id = s.user_id
        WHERE s.deleted_at IS NULL
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
        's.doc_path' => 'doc_path',
        's.html_path' => 'html_path',
        's.csv_path' => 'csv_path',
        'u.email' => 'email',
        'u.user_name' => 'user_name',
    ];

    if ($scansStatusFilter === 'deleted') {
        $whereSql = 'WHERE s.deleted_at IS NOT NULL';
    } elseif ($scansStatusFilter === 'all') {
        $whereSql = 'WHERE 1=1';
    } else {
        $whereSql = 'WHERE s.deleted_at IS NULL';
    }
    $whereParams = [];
    if ($scansSearch !== '') {
        $searchTerm = '%' . $scansSearch . '%';
        $orParts = [];
        foreach ($searchableExprs as $expr => $_label) {
            $orParts[] = "CAST($expr AS CHAR) LIKE ?";
            $whereParams[] = $searchTerm;
        }
        $whereSql .= ' AND (' . implode(' OR ', $orParts) . ')';
    }
    if ($scansRiskFilter !== 'all') {
        $riskClause = "CAST(s.scan_json AS CHAR) LIKE ?";
        $whereSql .= " AND $riskClause";
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
               s.deleted_at,
               s.pdf_path,
               s.doc_path,
               s.html_path,
               s.csv_path,
               u.email,
               u.user_name
        FROM scan_results s
        LEFT JOIN users u ON u.user_id = s.user_id
        ORDER BY s.created_at DESC
    ";

    // Insert WHERE clause before ORDER BY.
    $tableBaseSql = str_replace('ORDER BY s.created_at DESC', $whereSql . ' ORDER BY s.created_at DESC', $tableBaseSql);

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
        deleted_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_scan (scan_id),
        INDEX idx_user (user_id),
        INDEX idx_event (event_type),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        $pdo->exec("ALTER TABLE enterprise_ai_usage_events ADD COLUMN deleted_at DATETIME NULL");
    } catch (Exception $e) {
    }

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
    if ($aiStatusFilter === 'deleted') {
        $aiWhereParts[] = "e.deleted_at IS NOT NULL";
    } elseif ($aiStatusFilter !== 'all') {
        $aiWhereParts[] = "e.deleted_at IS NULL";
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
    $aiTotalPages = $aiPerPage === null || $aiEventTotal === 0 ? 1 : (int) ceil($aiEventTotal / $aiPerPage);
    if ($aiPage > $aiTotalPages) {
        $aiPage = $aiTotalPages;
    }
    $aiOffset = $aiPerPage === null ? 0 : (($aiPage - 1) * $aiPerPage);
    $aiRecordsStart = $aiEventTotal === 0 ? 0 : ($aiOffset + 1);
    $aiRecordsEnd = $aiPerPage === null ? $aiEventTotal : (int) min($aiOffset + $aiPerPage, $aiEventTotal);

    $aiSql = "
        SELECT e.id, e.user_id, e.scan_id, e.event_type, e.meta_json, e.ip_address, e.created_at, e.deleted_at, u.user_name, u.email
        FROM enterprise_ai_usage_events e
        LEFT JOIN users u ON u.user_id = e.user_id
        $aiWhereSql
        ORDER BY e.created_at DESC
    ";
    if ($aiPerPage !== null) {
        $aiSql .= " LIMIT " . (int) $aiPerPage . " OFFSET " . (int) $aiOffset;
    }
    $aiStmt = $pdo->prepare($aiSql);
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
        deleted_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created_at (created_at),
        INDEX idx_level (level),
        INDEX idx_source (source),
        INDEX idx_event_key (event_key),
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        $pdo->exec("ALTER TABLE system_server_logs ADD COLUMN deleted_at DATETIME NULL");
    } catch (Exception $e) {
    }

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
    if ($logStatusFilter === 'deleted') {
        $logWhereParts[] = "deleted_at IS NOT NULL";
    } elseif ($logStatusFilter !== 'all') {
        $logWhereParts[] = "deleted_at IS NULL";
    }
    $logWhereSql = empty($logWhereParts) ? '' : ('WHERE ' . implode(' AND ', $logWhereParts));

    $serverLogTotalStmt = $pdo->prepare("SELECT COUNT(*) FROM system_server_logs $logWhereSql");
    $serverLogTotalStmt->execute($logWhereParams);
    $serverLogTotal = (int) $serverLogTotalStmt->fetchColumn();
    $logTotalPages = $logPerPage === null || $serverLogTotal === 0 ? 1 : (int) ceil($serverLogTotal / $logPerPage);
    if ($logPage > $logTotalPages) {
        $logPage = $logTotalPages;
    }
    $logOffset = $logPerPage === null ? 0 : (($logPage - 1) * $logPerPage);
    $logRecordsStart = $serverLogTotal === 0 ? 0 : ($logOffset + 1);
    $logRecordsEnd = $logPerPage === null ? $serverLogTotal : (int) min($logOffset + $logPerPage, $serverLogTotal);

    $logSql = "
        SELECT id, event_key, level, source, message, user_id, request_ip, request_uri, created_at, deleted_at
        FROM system_server_logs
        $logWhereSql
        ORDER BY created_at DESC
    ";
    if ($logPerPage !== null) {
        $logSql .= " LIMIT " . (int) $logPerPage . " OFFSET " . (int) $logOffset;
    }
    $serverLogRowsStmt = $pdo->prepare($logSql);
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

    // 7-day timeline for server log chart (errors/warnings by day).
    $timelineStmt = $pdo->query("
        SELECT DATE(created_at) AS day_key,
               SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) AS error_count,
               SUM(CASE WHEN level = 'warning' THEN 1 ELSE 0 END) AS warning_count
        FROM system_server_logs
        WHERE created_at >= (CURDATE() - INTERVAL 6 DAY)
          AND deleted_at IS NULL
        GROUP BY DATE(created_at)
        ORDER BY DATE(created_at) ASC
    ");
    $timelineRows = $timelineStmt->fetchAll() ?: [];
    $timelineMap = [];
    foreach ($timelineRows as $tr) {
        $k = (string) ($tr['day_key'] ?? '');
        if ($k === '') continue;
        $timelineMap[$k] = [
            'error' => (int) ($tr['error_count'] ?? 0),
            'warning' => (int) ($tr['warning_count'] ?? 0),
        ];
    }
    for ($i = 6; $i >= 0; $i--) {
        $dayKey = date('Y-m-d', strtotime("-{$i} day"));
        $serverLogsTimelineLabels[] = date('M j', strtotime($dayKey));
        $serverLogsTimelineErrors[] = (int) (($timelineMap[$dayKey]['error'] ?? 0));
        $serverLogsTimelineWarnings[] = (int) (($timelineMap[$dayKey]['warning'] ?? 0));
    }
} catch (Exception $e) {
    $adminError = 'Unable to load scan data right now.';
}

$downloadBaseAdmin = '/ScanQuotient.v2/ScanQuotient.B/Private/Admin_dashboard/PHP/Backend/download_scan_admin.php';
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
            --glass-surface: rgba(255, 255, 255, 0.58);
            --glass-surface-strong: rgba(255, 255, 255, 0.72);
            --glass-border: rgba(255, 255, 255, 0.65);
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
            --glass-surface: rgba(15, 23, 42, 0.48);
            --glass-surface-strong: rgba(30, 27, 75, 0.62);
            --glass-border: rgba(148, 163, 184, 0.28);
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
            font-size: 18px;
            color: #6f0ace;
            text-decoration: none;
            letter-spacing: -0.2px;
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
            overflow: visible;
            min-width: 0;
        }

        .simple-sidebar {
            width: 220px;
            min-width: 220px;
            flex: 0 0 220px;
            background: var(--glass-surface-strong);
            border-right: 1px solid var(--glass-border);
            padding: 18px 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            box-shadow: inset -1px 0 0 rgba(148, 163, 184, 0.1);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            position: sticky;
            top: 76px;
            align-self: flex-start;
            height: fit-content;
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
            overflow: visible;
            min-width: 0;
            overflow-x: hidden;
            background:
                radial-gradient(circle at 12% 4%, rgba(59, 130, 246, 0.08), transparent 30%),
                radial-gradient(circle at 92% 18%, rgba(16, 185, 129, 0.08), transparent 30%),
                var(--bg-main);
        }

        .admin-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 18px;
            min-width: 0;
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .kpi-card {
            background: var(--glass-surface);
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            padding: 24px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
            isolation: isolate;
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            min-width: 0;
        }

        .kpi-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .kpi-card::after {
            content: '';
            position: absolute;
            width: 150px;
            height: 150px;
            right: -55px;
            top: -70px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.2), transparent 70%);
            z-index: -1;
            transition: transform 0.3s ease;
        }

        .kpi-card:hover::after {
            transform: scale(1.06);
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
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
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.16), rgba(139, 92, 246, 0.16));
            color: var(--brand-color);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.45), 0 10px 18px rgba(59, 130, 246, 0.15);
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
            letter-spacing: -0.4px;
            text-shadow: 0 1px 0 rgba(255, 255, 255, 0.35);
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
            background: var(--glass-surface);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 24px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        .chart-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 18px 42px rgba(15, 23, 42, 0.12);
        }

        .chart-card::after {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: linear-gradient(90deg, var(--brand-color), #10b981, #f59e0b);
            opacity: 0.75;
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
            height: 320px;
            border-radius: 14px;
            padding: 10px;
            background: rgba(255, 255, 255, 0.32);
            border: 1px solid rgba(255, 255, 255, 0.55);
        }

        .chart-container canvas {
            filter: drop-shadow(0 10px 22px rgba(30, 41, 59, 0.1));
        }

        body.dark .chart-container {
            border-color: rgba(148, 163, 184, 0.35);
            background: rgba(15, 23, 42, 0.4);
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
            background: var(--glass-surface);
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            padding: 20px;
            transition: all 0.3s ease;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.05);
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        .quality-card::before {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            top: 0;
            height: 3px;
            background: linear-gradient(90deg, rgba(16, 185, 129, 0.85), rgba(59, 130, 246, 0.85));
        }

        .quality-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.12);
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
            background: var(--glass-surface);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 24px;
            transition: all 0.3s ease;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.06);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        .timeline-card:hover {
            box-shadow: 0 18px 38px rgba(15, 23, 42, 0.1);
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
            background: var(--glass-surface);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 24px;
            transition: all 0.3s ease;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.06);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        .performance-card:hover {
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
        }

        .module-table tr {
            transition: background-color 0.2s ease;
        }

        .module-table tbody tr:hover {
            background: rgba(59, 130, 246, 0.06);
        }

        body.dark .module-table tbody tr:hover {
            background: rgba(139, 92, 246, 0.14);
        }

        body.dark .kpi-value {
            text-shadow: none;
        }

        .module-table {
            width: 100%;
            border-collapse: collapse;
            border-radius: 14px;
            overflow: hidden;
        }

        .module-table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.24);
            background: rgba(255, 255, 255, 0.28);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .module-table td {
            padding: 16px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.16);
            font-size: 14px;
            color: var(--text-main);
            background: rgba(255, 255, 255, 0.12);
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
            padding: 18px 32px;
            font-size: 13px;
            color: #64748b;
            background: linear-gradient(135deg, #f8fafc, #dbeafe);
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
            font-weight: 700;
        }

        .footer-center {
            text-align: center;
        }

        .footer-right {
            text-align: right;
        }

        .footer-brand {
            font-weight: 700;
            color: #6f0ace;
            text-decoration: none;
            display: block;
        }

        .footer-tagline {
            margin-top: 2px;
            opacity: 0.9;
            font-weight: 500;
            display: block;
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
            z-index: 4000;
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

        .toast-content h4 {
            font-size: 13px;
            margin: 0 0 2px;
            color: var(--text-main);
        }

        .toast-content p {
            font-size: 12px;
            margin: 0;
            color: var(--text-light);
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
            .main {
                padding: 16px;
            }

            .page-header {
                padding: 10px 14px;
            }

            .header-brand {
                font-size: 16px;
            }

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
                position: static;
                top: auto;
                max-height: none;
                overflow: visible;
                margin: 0;
                border-radius: 0;
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
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            background: var(--glass-surface);
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.08);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            position: relative;
            overflow: hidden;
        }

        body.dark .module-table th {
            background: rgba(30, 41, 59, 0.42);
            border-bottom-color: rgba(148, 163, 184, 0.28);
        }

        body.dark .module-table td {
            background: rgba(15, 23, 42, 0.22);
            border-bottom-color: rgba(148, 163, 184, 0.2);
        }

        @keyframes glassSweep {
            0% {
                background-position: -220% 0;
            }

            100% {
                background-position: 220% 0;
            }
        }

        .kpi-card:hover,
        .chart-card:hover,
        .quality-card:hover,
        .timeline-card:hover,
        .performance-card:hover,
        .table-container:hover {
            background-image: linear-gradient(110deg, transparent 0%, rgba(255, 255, 255, 0.26) 46%, transparent 62%);
            background-size: 220% 100%;
            animation: glassSweep 0.85s ease;
        }

        body.dark .kpi-card:hover,
        body.dark .chart-card:hover,
        body.dark .quality-card:hover,
        body.dark .timeline-card:hover,
        body.dark .performance-card:hover,
        body.dark .table-container:hover {
            background-image: linear-gradient(110deg, transparent 0%, rgba(255, 255, 255, 0.14) 46%, transparent 62%);
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
            min-width: 180px;
            white-space: nowrap;
        }

        .server-logs-scroll {
            overflow-x: auto;
            overflow-y: hidden;
        }

        .server-logs-table {
            min-width: 1450px;
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
                <a href="#" class="header-brand">ScanQuotient</a>
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
                <i class="fas fa-robot"></i> Enterprise AI Events
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
                    <div class="chart-card" style="margin-bottom:18px;">
                        <div class="chart-header">
                            <div class="chart-title"><i class="fas fa-chart-pie"></i> Scan Risk Distribution</div>
                        </div>
                        <div class="chart-container"><canvas id="scansRiskChart"></canvas></div>
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
                                <select name="scans_status"
                                    style="padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); min-width: 150px;">
                                    <option value="active" <?php echo $scansStatusFilter === 'active' ? 'selected' : ''; ?>>Active only</option>
                                    <option value="deleted" <?php echo $scansStatusFilter === 'deleted' ? 'selected' : ''; ?>>Deleted only</option>
                                    <option value="all" <?php echo $scansStatusFilter === 'all' ? 'selected' : ''; ?>>All records</option>
                                </select>
                                <button type="submit" class="action-btn" style="padding: 10px 14px; text-decoration:none;">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                            </form>
                            <?php if ($adminError): ?>
                                <span style="color:#fecaca;font-size:13px;"><?php echo htmlspecialchars($adminError); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="table-container server-logs-scroll">
                            <table class="module-table server-logs-table">
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
                                                foreach (['target_url', 'pdf_path', 'doc_path', 'html_path', 'csv_path', 'email', 'user_name'] as $key) {
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
                                                        <span><?php echo htmlspecialchars($userLabel ?: 'N/A'); ?></span>
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
                                                    <button type="button" class="action-btn js-open-scan-actions"
                                                        data-scan-id="<?php echo (int) $row['id']; ?>"
                                                        data-has-pdf="<?php echo !empty($row['pdf_path']) ? '1' : '0'; ?>"
                                                        data-deleted="<?php echo !empty($row['deleted_at']) ? '1' : '0'; ?>">
                                                        <i class="fas fa-ellipsis-h"></i> Actions
                                                    </button>
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
                                    if ($scansStatusFilter !== 'active') {
                                        $queryParams .= "&scans_status=" . urlencode($scansStatusFilter);
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
                    <div class="chart-card" style="margin-bottom:18px;">
                        <div class="chart-header">
                            <div class="chart-title"><i class="fas fa-chart-bar"></i> Enterprise AI Event Types</div>
                        </div>
                        <div class="chart-container"><canvas id="aiEventsChart"></canvas></div>
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
                                <select name="ai_status"
                                    style="padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); min-width: 150px;">
                                    <option value="active" <?php echo $aiStatusFilter === 'active' ? 'selected' : ''; ?>>Active only</option>
                                    <option value="deleted" <?php echo $aiStatusFilter === 'deleted' ? 'selected' : ''; ?>>Deleted only</option>
                                    <option value="all" <?php echo $aiStatusFilter === 'all' ? 'selected' : ''; ?>>All records</option>
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
                                                <td><?php echo htmlspecialchars($evUser ?: 'N/A'); ?></td>
                                                <td><?php echo $evScanId; ?></td>
                                                <td><?php echo htmlspecialchars((string) ($ev['event_type'] ?? '')); ?></td>
                                                <td title="<?php echo htmlspecialchars((string) ($ev['meta_json'] ?? '')); ?>">
                                                    <?php echo htmlspecialchars($metaShort); ?>
                                                </td>
                                                <td><?php echo htmlspecialchars((string) ($ev['ip_address'] ?? '-')); ?></td>
                                                <td><?php echo htmlspecialchars((string) ($ev['created_at'] ?? '')); ?></td>
                                                <td>
                                                    <?php if ($evScanId > 0): ?>
                                                        <a class="action-btn html"
                                                            href="/ScanQuotient.v2/ScanQuotient.B/Private/Web_scanner/PHP/Frontend/enterprise_ai_overview.php?scan_id=<?php echo $evScanId; ?>">
                                                            <i class="fas fa-up-right-from-square"></i> Open AI Page
                                                        </a>
                                                    <?php endif; ?>
                                                    <form method="POST" action="admin_data_management.php" style="display:inline;">
                                                        <input type="hidden" name="record_action" value="clear_chat">
                                                        <input type="hidden" name="record_type" value="ai">
                                                        <input type="hidden" name="record_id" value="<?php echo (int) $ev['id']; ?>">
                                                        <button type="submit" class="action-btn" title="Clear chat"><i class="fas fa-eraser"></i></button>
                                                    </form>
                                                    <?php if (!empty($ev['deleted_at'])): ?>
                                                        <form method="POST" action="admin_data_management.php" style="display:inline;">
                                                            <input type="hidden" name="record_action" value="restore">
                                                            <input type="hidden" name="record_type" value="ai">
                                                            <input type="hidden" name="record_id" value="<?php echo (int) $ev['id']; ?>">
                                                            <button type="submit" class="action-btn" title="Restore"><i class="fas fa-rotate-left"></i></button>
                                                        </form>
                                                    <?php else: ?>
                                                        <button type="button" class="action-btn delete js-record-action" title="Delete"
                                                            data-record-action="soft_delete" data-record-type="ai"
                                                            data-record-id="<?php echo (int) $ev['id']; ?>">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button type="button" class="action-btn delete js-record-action" title="Delete forever"
                                                        data-record-action="hard_delete" data-record-type="ai"
                                                        data-record-id="<?php echo (int) $ev['id']; ?>">
                                                        <i class="fas fa-ban"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($aiEventTotal > 0): ?>
                            <div class="sq-admin-pagination-bar">
                                <label>
                                    Show
                                    <select onchange="sqUpdateTabPerPage('ai', this.value)">
                                        <?php foreach (['5', '10', '20', '50', '100', '200', 'all'] as $opt): ?>
                                            <option value="<?php echo $opt; ?>" <?php echo $aiPerPageParam === $opt ? 'selected' : ''; ?>>
                                                <?php echo $opt === 'all' ? 'All' : $opt; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    entries
                                </label>
                                <span class="sq-admin-record-info">
                                    Showing <?php echo (int) $aiRecordsStart; ?>–<?php echo (int) $aiRecordsEnd; ?> of
                                    <?php echo number_format((int) $aiEventTotal); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <?php if ($aiTotalPages > 1): ?>
                            <div class="sq-admin-pagination">
                                <?php
                                $aiParams = 'view=ai&ai_per_page=' . urlencode($aiPerPageParam) . '&ai_search=' . urlencode($aiSearch) . '&ai_event_type=' . urlencode($aiEventTypeFilter) . '&ai_status=' . urlencode($aiStatusFilter);
                                ?>
                                <?php if ($aiPage > 1): ?>
                                    <a class="sq-admin-page-btn" href="admin_data_management.php?<?php echo $aiParams; ?>&ai_page=<?php echo $aiPage - 1; ?>"><i class="fas fa-chevron-left"></i></a>
                                <?php else: ?>
                                    <span class="sq-admin-page-btn disabled"><i class="fas fa-chevron-left"></i></span>
                                <?php endif; ?>
                                <span class="sq-admin-page-btn active"><?php echo $aiPage; ?></span>
                                <?php if ($aiPage < $aiTotalPages): ?>
                                    <a class="sq-admin-page-btn" href="admin_data_management.php?<?php echo $aiParams; ?>&ai_page=<?php echo $aiPage + 1; ?>"><i class="fas fa-chevron-right"></i></a>
                                <?php else: ?>
                                    <span class="sq-admin-page-btn disabled"><i class="fas fa-chevron-right"></i></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
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
                    <div class="chart-card" style="margin-bottom:18px;">
                        <div class="chart-header">
                            <div class="chart-title"><i class="fas fa-chart-column"></i> Server Log Levels (24h)</div>
                        </div>
                        <div class="chart-container"><canvas id="serverLogLevelsChart"></canvas></div>
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
                                <select name="log_status"
                                    style="padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); min-width: 150px;">
                                    <option value="active" <?php echo $logStatusFilter === 'active' ? 'selected' : ''; ?>>Active only</option>
                                    <option value="deleted" <?php echo $logStatusFilter === 'deleted' ? 'selected' : ''; ?>>Deleted only</option>
                                    <option value="all" <?php echo $logStatusFilter === 'all' ? 'selected' : ''; ?>>All records</option>
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
                                        <th>Actions</th>
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
                                                <td>
                                                    <?php if (!empty($log['deleted_at'])): ?>
                                                        <form method="POST" action="admin_data_management.php" style="display:inline;">
                                                            <input type="hidden" name="record_action" value="restore">
                                                            <input type="hidden" name="record_type" value="server_logs">
                                                            <input type="hidden" name="record_id" value="<?php echo (int) $log['id']; ?>">
                                                            <button type="submit" class="action-btn" title="Restore"><i class="fas fa-rotate-left"></i></button>
                                                        </form>
                                                    <?php else: ?>
                                                        <button type="button" class="action-btn delete js-record-action" title="Delete"
                                                            data-record-action="soft_delete" data-record-type="server_logs"
                                                            data-record-id="<?php echo (int) $log['id']; ?>">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button type="button" class="action-btn delete js-record-action" title="Delete forever"
                                                        data-record-action="hard_delete" data-record-type="server_logs"
                                                        data-record-id="<?php echo (int) $log['id']; ?>">
                                                        <i class="fas fa-ban"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($serverLogTotal > 0): ?>
                            <div class="sq-admin-pagination-bar">
                                <label>
                                    Show
                                    <select onchange="sqUpdateTabPerPage('server_logs', this.value)">
                                        <?php foreach (['5', '10', '20', '50', '100', '200', 'all'] as $opt): ?>
                                            <option value="<?php echo $opt; ?>" <?php echo $logPerPageParam === $opt ? 'selected' : ''; ?>>
                                                <?php echo $opt === 'all' ? 'All' : $opt; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    entries
                                </label>
                                <span class="sq-admin-record-info">
                                    Showing <?php echo (int) $logRecordsStart; ?>–<?php echo (int) $logRecordsEnd; ?> of
                                    <?php echo number_format((int) $serverLogTotal); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <?php if ($logTotalPages > 1): ?>
                            <div class="sq-admin-pagination">
                                <?php
                                $logParams = 'view=server_logs&log_per_page=' . urlencode($logPerPageParam) . '&log_search=' . urlencode($logSearch) . '&log_level=' . urlencode($logLevelFilter) . '&log_source=' . urlencode($logSourceFilter) . '&log_status=' . urlencode($logStatusFilter);
                                ?>
                                <?php if ($logPage > 1): ?>
                                    <a class="sq-admin-page-btn" href="admin_data_management.php?<?php echo $logParams; ?>&log_page=<?php echo $logPage - 1; ?>"><i class="fas fa-chevron-left"></i></a>
                                <?php else: ?>
                                    <span class="sq-admin-page-btn disabled"><i class="fas fa-chevron-left"></i></span>
                                <?php endif; ?>
                                <span class="sq-admin-page-btn active"><?php echo $logPage; ?></span>
                                <?php if ($logPage < $logTotalPages): ?>
                                    <a class="sq-admin-page-btn" href="admin_data_management.php?<?php echo $logParams; ?>&log_page=<?php echo $logPage + 1; ?>"><i class="fas fa-chevron-right"></i></a>
                                <?php else: ?>
                                    <span class="sq-admin-page-btn disabled"><i class="fas fa-chevron-right"></i></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>
        </main>
    </div>

    <button id="backToTopBtn" class="sq-back-to-top" title="Back to top" aria-label="Back to top" type="button">
        <i class="fas fa-arrow-up"></i>
    </button>

    <div class="modal-overlay" id="scanActionsModal" role="dialog" aria-modal="true" aria-labelledby="scanActionsTitle">
        <div class="modal-card">
            <div class="modal-head">
                <span class="warn-icon" style="background: rgba(59,130,246,0.12); color: var(--brand-color);"><i
                        class="fas fa-layer-group"></i></span>
                <div class="modal-title" id="scanActionsTitle">Scan Actions</div>
            </div>
            <div class="modal-body">
                <div id="scanActionsList" class="action-group" style="flex-wrap: wrap;"></div>
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-btn" id="scanActionsCloseBtn">Close</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="deleteModal" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
        <div class="modal-card">
            <div class="modal-head">
                <span class="warn-icon"><i class="fas fa-trash-alt"></i></span>
                <div class="modal-title" id="deleteModalTitle">Confirm Action</div>
            </div>
            <div class="modal-body" id="deleteModalBody">Are you sure you want to continue?</div>
            <div class="modal-actions">
                <button type="button" class="modal-btn" id="deleteCancelBtn">Cancel</button>
                <button type="button" class="modal-btn primary-danger" id="deleteConfirmBtn">Confirm</button>
            </div>
        </div>
    </div>
    <form id="recordActionForm" method="POST" action="admin_data_management.php" style="display:none;">
        <input type="hidden" name="record_action" id="recordActionInput" value="">
        <input type="hidden" name="record_type" id="recordTypeInput" value="">
        <input type="hidden" name="record_id" id="recordIdInput" value="">
    </form>
    <div class="toast-container" id="toastContainer"></div>

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
            <span class="footer-brand">ScanQuotient</span>
            <span class="footer-tagline">Quantifying Risk. Strengthening Security.</span>
        </div>
        <div class="footer-center">
            &copy; 2026 Authorized Security Testing
        </div>
        <div class="footer-right">
            Built for Web Security Assessment
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
        function sqUpdateTabPerPage(view, perPageValue) {
            const url = new URL(window.location.href);
            url.searchParams.set('view', view);
            if (view === 'ai') {
                url.searchParams.set('ai_per_page', perPageValue);
                url.searchParams.set('ai_page', '1');
            } else if (view === 'server_logs') {
                url.searchParams.set('log_per_page', perPageValue);
                url.searchParams.set('log_page', '1');
            } else {
                url.searchParams.set('per_page', perPageValue);
                url.searchParams.set('page', '1');
            }
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
            const deleteModal = document.getElementById('deleteModal');
            const deleteBody = document.getElementById('deleteModalBody');
            const cancelBtn = document.getElementById('deleteCancelBtn');
            const confirmBtn = document.getElementById('deleteConfirmBtn');
            const form = document.getElementById('recordActionForm');
            const actionInput = document.getElementById('recordActionInput');
            const typeInput = document.getElementById('recordTypeInput');
            const idInput = document.getElementById('recordIdInput');
            const scanActionsModal = document.getElementById('scanActionsModal');
            const scanActionsList = document.getElementById('scanActionsList');
            const scanActionsCloseBtn = document.getElementById('scanActionsCloseBtn');
            let pending = null;

            function showToast(title, message, kind) {
                const container = document.getElementById('toastContainer');
                if (!container) return;
                const toast = document.createElement('div');
                toast.className = 'toast';
                const icon = kind === 'error' ? 'fa-circle-xmark' : 'fa-circle-check';
                toast.innerHTML = '<div class="toast-icon ' + (kind === 'error' ? 'warning' : 'success') + '"><i class="fas ' + icon + '"></i></div><div class="toast-content"><h4>' + title + '</h4><p>' + message + '</p></div>';
                container.appendChild(toast);
                setTimeout(() => { toast.remove(); }, 3400);
            }

            const actionLabel = { soft_delete: 'delete this record', hard_delete: 'permanently delete this record', restore: 'restore this record' };
            function openConfirm(action, type, id) {
                pending = { action, type, id };
                if (deleteBody) deleteBody.textContent = 'Are you sure you want to ' + (actionLabel[action] || 'continue') + '?';
                deleteModal?.classList.add('active');
            }
            function closeConfirm() { deleteModal?.classList.remove('active'); pending = null; }

            document.addEventListener('click', function (e) {
                const t = e.target;
                if (!(t instanceof Element)) return;
                const actionBtn = t.closest('.js-record-action');
                if (actionBtn) {
                    e.preventDefault();
                    openConfirm(actionBtn.getAttribute('data-record-action') || '', actionBtn.getAttribute('data-record-type') || '', actionBtn.getAttribute('data-record-id') || '');
                    return;
                }
                const scanBtn = t.closest('.js-open-scan-actions');
                if (scanBtn) {
                    const id = scanBtn.getAttribute('data-scan-id');
                    const hasPdf = scanBtn.getAttribute('data-has-pdf') === '1';
                    const isDeleted = scanBtn.getAttribute('data-deleted') === '1';
                    if (scanActionsList) {
                        scanActionsList.innerHTML =
                            '<a class="action-btn html" href="<?php echo $downloadBaseAdmin; ?>?id=' + id + '&type=html"><i class="fas fa-file-code"></i> HTML</a>' +
                            '<a class="action-btn csv" href="<?php echo $downloadBaseAdmin; ?>?id=' + id + '&type=csv"><i class="fas fa-file-csv"></i> CSV</a>' +
                            '<a class="action-btn" href="<?php echo $downloadBaseAdmin; ?>?id=' + id + '&type=doc"><i class="fas fa-file-word"></i> DOC</a>' +
                            (hasPdf ? '<a class="action-btn pdf" href="<?php echo $downloadBaseAdmin; ?>?id=' + id + '&type=pdf"><i class="fas fa-file-pdf"></i> PDF</a>' : '<span class="action-btn disabled"><i class="fas fa-file-pdf"></i> PDF</span>') +
                            (isDeleted
                                ? '<button type="button" class="action-btn js-record-action" data-record-action="restore" data-record-type="scans" data-record-id="' + id + '" title="Restore"><i class="fas fa-rotate-left"></i></button>'
                                : '<button type="button" class="action-btn delete js-record-action" data-record-action="soft_delete" data-record-type="scans" data-record-id="' + id + '" title="Delete"><i class="fas fa-trash"></i></button>') +
                            '<button type="button" class="action-btn delete js-record-action" data-record-action="hard_delete" data-record-type="scans" data-record-id="' + id + '" title="Delete forever"><i class="fas fa-ban"></i></button>';
                    }
                    scanActionsModal?.classList.add('active');
                    return;
                }
            });

            cancelBtn?.addEventListener('click', closeConfirm);
            scanActionsCloseBtn?.addEventListener('click', () => scanActionsModal?.classList.remove('active'));
            deleteModal?.addEventListener('click', (e) => { if (e.target === deleteModal) closeConfirm(); });
            scanActionsModal?.addEventListener('click', (e) => { if (e.target === scanActionsModal) scanActionsModal.classList.remove('active'); });
            confirmBtn?.addEventListener('click', function () {
                if (!pending) return;
                actionInput.value = pending.action;
                typeInput.value = pending.type;
                idInput.value = pending.id;
                form.submit();
            });

            const done = <?php echo $showActionToast ? 'true' : 'false'; ?>;
            if (done) {
                const map = {
                    soft_delete: 'Record moved to deleted.',
                    hard_delete: 'Record deleted permanently.',
                    restore: 'Record restored.',
                    clear_chat: 'Chat/meta cleared.'
                };
                showToast('Action completed', map[<?php echo json_encode($lastActionName); ?>] || 'Changes saved.', 'success');
                const url = new URL(window.location.href);
                url.searchParams.delete('action_done');
                url.searchParams.delete('action');
                window.history.replaceState({}, '', url.toString());
            }
        })();

        (function () {
            const activeView = <?php echo json_encode($activeView); ?>;
            if (activeView === 'scans') {
                const el = document.getElementById('scansRiskChart');
                if (!el) return;
                new Chart(el, {
                    type: 'doughnut',
                    data: {
                        labels: <?php echo json_encode(array_keys($metrics['by_risk'])); ?>,
                        datasets: [{
                            data: <?php echo json_encode(array_values($metrics['by_risk'])); ?>,
                            backgroundColor: ['#dc2626', '#ef4444', '#f59e0b', '#3b82f6', '#10b981', '#94a3b8']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } },
                        cutout: '62%'
                    }
                });
            } else if (activeView === 'ai') {
                const el = document.getElementById('aiEventsChart');
                if (!el) return;
                new Chart(el, {
                    type: 'bar',
                    data: {
                        labels: ['Submitted', 'Success', 'Other'],
                        datasets: [{
                            data: [
                                <?php echo (int) $aiMetrics['ask_submitted']; ?>,
                                <?php echo (int) $aiMetrics['ask_success']; ?>,
                                <?php echo max(0, (int) $aiEventTotal - (int) $aiMetrics['ask_submitted'] - (int) $aiMetrics['ask_success']); ?>
                            ],
                            backgroundColor: ['#3b82f6', '#10b981', '#94a3b8']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                    }
                });
            } else if (activeView === 'server_logs') {
                const el = document.getElementById('serverLogLevelsChart');
                if (!el) return;
                new Chart(el, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($serverLogsTimelineLabels); ?>,
                        datasets: [{
                            label: 'Errors',
                            data: <?php echo json_encode($serverLogsTimelineErrors); ?>,
                            borderColor: '#ef4444',
                            backgroundColor: 'rgba(239, 68, 68, 0.15)',
                            pointBackgroundColor: '#ef4444',
                            pointBorderColor: '#ffffff',
                            pointRadius: 5,
                            pointHoverRadius: 6,
                            tension: 0.35,
                            fill: true
                        }, {
                            label: 'Warnings',
                            data: <?php echo json_encode($serverLogsTimelineWarnings); ?>,
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245, 158, 11, 0.12)',
                            pointBackgroundColor: '#f59e0b',
                            pointBorderColor: '#ffffff',
                            pointRadius: 5,
                            pointHoverRadius: 6,
                            tension: 0.35,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: true, position: 'bottom' } },
                        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                    }
                });
            }
        })();
    </script>

</body>

</html>