<?php
// admin_security_logs.php - Read-only admin view for security logs
session_start();

// Debug: Check session
error_log("Security Logs Session: " . print_r($_SESSION, true));

// Authentication check
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header("Location: /ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Frontend/login_page_site.php?error=not_authenticated");
    exit();
}

// Check if user is admin
$userRole = $_SESSION['role'] ?? 'user';
if ($userRole !== 'admin' && $userRole !== 'super_admin') {
    header("Location: /ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Frontend/login_page_site.php?error=unauthorized");
    exit();
}

// Database configuration
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Pagination settings
define('ITEMS_PER_PAGE', 25);

$adminName = $_SESSION['user_name'] ?? 'Admin';
$errorMessage = '';
$successMessage = '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Create login rate limit table (used by login handler) if missing
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            scope ENUM('user','ip') NOT NULL,
            scope_key VARCHAR(255) NOT NULL,
            fail_count INT NOT NULL DEFAULT 0,
            first_fail_at DATETIME NULL,
            last_fail_at DATETIME NULL,
            locked_until DATETIME NULL,
            lock_minutes INT NULL,
            reason VARCHAR(50) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_scope_key (scope, scope_key),
            INDEX idx_locked_until (locked_until),
            INDEX idx_last_fail (last_fail_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Admin actions for login rate limits
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['rate_action'] ?? '';
        $scope = $_POST['scope'] ?? '';
        $scopeKey = trim($_POST['scope_key'] ?? '');
        $minutes = intval($_POST['minutes'] ?? 0);

        $validScopes = ['user', 'ip'];
        $validActions = ['lock', 'unlock', 'reset'];

        if (!in_array($scope, $validScopes, true) || !in_array($action, $validActions, true) || $scopeKey === '') {
            $errorMessage = 'Invalid rate limit request.';
        } else {
            if ($action === 'unlock') {
                $stmt = $pdo->prepare("
                    UPDATE login_rate_limits
                    SET fail_count = 0, first_fail_at = NULL, last_fail_at = NULL, locked_until = NULL, lock_minutes = NULL, reason = 'admin_unlock'
                    WHERE scope = ? AND scope_key = ?
                ");
                $stmt->execute([$scope, $scopeKey]);

                // If record didn't exist, create a clean one (optional, keeps admin audit trail)
                if ($stmt->rowCount() === 0) {
                    $ins = $pdo->prepare("
                        INSERT INTO login_rate_limits (scope, scope_key, fail_count, first_fail_at, last_fail_at, locked_until, lock_minutes, reason)
                        VALUES (?, ?, 0, NULL, NULL, NULL, NULL, 'admin_unlock')
                        ON DUPLICATE KEY UPDATE reason = VALUES(reason), locked_until = NULL, fail_count = 0, first_fail_at = NULL, last_fail_at = NULL, lock_minutes = NULL
                    ");
                    $ins->execute([$scope, $scopeKey]);
                }

                $successMessage = ucfirst($scope) . ' unlocked successfully.';
                adminLogSecurityEvent($pdo, $adminName, 'ADMIN_ACTION', "Unlocked {$scope}: {$scopeKey}");
            } elseif ($action === 'reset') {
                $stmt = $pdo->prepare("
                    UPDATE login_rate_limits
                    SET fail_count = 0, first_fail_at = NULL, last_fail_at = NULL, locked_until = NULL, lock_minutes = NULL, reason = 'admin_reset'
                    WHERE scope = ? AND scope_key = ?
                ");
                $stmt->execute([$scope, $scopeKey]);

                if ($stmt->rowCount() === 0) {
                    $ins = $pdo->prepare("
                        INSERT INTO login_rate_limits (scope, scope_key, fail_count, first_fail_at, last_fail_at, locked_until, lock_minutes, reason)
                        VALUES (?, ?, 0, NULL, NULL, NULL, NULL, 'admin_reset')
                        ON DUPLICATE KEY UPDATE reason = VALUES(reason), locked_until = NULL, fail_count = 0, first_fail_at = NULL, last_fail_at = NULL, lock_minutes = NULL
                    ");
                    $ins->execute([$scope, $scopeKey]);
                }

                $successMessage = ucfirst($scope) . ' counters reset.';
                adminLogSecurityEvent($pdo, $adminName, 'ADMIN_ACTION', "Reset rate limits for {$scope}: {$scopeKey}");
            } elseif ($action === 'lock') {
                // Default lock windows aligned with login handler expectations
                if ($minutes <= 0) {
                    $minutes = ($scope === 'ip') ? 15 : 5;
                }

                $lockedUntil = date('Y-m-d H:i:s', time() + ($minutes * 60));

                $stmt = $pdo->prepare("
                    INSERT INTO login_rate_limits (scope, scope_key, fail_count, first_fail_at, last_fail_at, locked_until, lock_minutes, reason)
                    VALUES (?, ?, GREATEST(5, fail_count), NOW(), NOW(), ?, ?, 'admin_lock')
                    ON DUPLICATE KEY UPDATE
                        locked_until = VALUES(locked_until),
                        lock_minutes = VALUES(lock_minutes),
                        reason = VALUES(reason),
                        last_fail_at = NOW()
                ");
                $stmt->execute([$scope, $scopeKey, $lockedUntil, $minutes]);

                $successMessage = ucfirst($scope) . " locked for {$minutes} minute(s).";
                adminLogSecurityEvent($pdo, $adminName, 'ADMIN_ACTION', "Locked {$scope}: {$scopeKey} for {$minutes} minutes");
            }
        }
    }

    // Get filter parameters
    $eventType = $_GET['event_type'] ?? 'all';
    $search = $_GET['search'] ?? '';
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));

    // Rate limit filters
    $rlScope = $_GET['rl_scope'] ?? 'all'; // all, user, ip
    $rlSearch = $_GET['rl_search'] ?? '';

    // Build query
    $whereConditions = [];
    $params = [];

    if ($eventType !== 'all') {
        $whereConditions[] = "event_type = ?";
        $params[] = $eventType;
    }

    if (!empty($search)) {
        $whereConditions[] = "(username LIKE ? OR description LIKE ? OR ip_address LIKE ?)";
        $searchTerm = "%$search%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
    }

    if (!empty($dateFrom)) {
        $whereConditions[] = "created_at >= ?";
        $params[] = $dateFrom . ' 00:00:00';
    }

    if (!empty($dateTo)) {
        $whereConditions[] = "created_at <= ?";
        $params[] = $dateTo . ' 23:59:59';
    }

    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    // Get total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM security_logs $whereClause");
    $countStmt->execute($params);
    $totalItems = $countStmt->fetchColumn();
    $totalPages = ceil($totalItems / ITEMS_PER_PAGE);
    $offset = ($page - 1) * ITEMS_PER_PAGE;

    // Get logs
    $query = "SELECT * FROM security_logs $whereClause ORDER BY created_at DESC LIMIT " . ITEMS_PER_PAGE . " OFFSET $offset";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    // Get distinct event types for filter
    $eventTypes = $pdo->query("SELECT DISTINCT event_type FROM security_logs ORDER BY event_type")->fetchAll(PDO::FETCH_COLUMN);

    // Get statistics
    $stats = [
        'total' => $pdo->query("SELECT COUNT(*) FROM security_logs")->fetchColumn(),
        'today' => $pdo->query("SELECT COUNT(*) FROM security_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
        'unique_users' => $pdo->query("SELECT COUNT(DISTINCT username) FROM security_logs WHERE username IS NOT NULL")->fetchColumn(),
        'unique_ips' => $pdo->query("SELECT COUNT(DISTINCT ip_address) FROM security_logs WHERE ip_address IS NOT NULL")->fetchColumn()
    ];

    // Fetch login rate limit entries for admin view
    $rlWhere = [];
    $rlParams = [];
    if ($rlScope === 'user' || $rlScope === 'ip') {
        $rlWhere[] = "scope = ?";
        $rlParams[] = $rlScope;
    }
    if (!empty($rlSearch)) {
        $rlWhere[] = "scope_key LIKE ?";
        $rlParams[] = '%' . $rlSearch . '%';
    }
    $rlWhereClause = !empty($rlWhere) ? ('WHERE ' . implode(' AND ', $rlWhere)) : '';
    $rlStmt = $pdo->prepare("
        SELECT *
        FROM login_rate_limits
        $rlWhereClause
        ORDER BY
            (locked_until IS NOT NULL AND locked_until > NOW()) DESC,
            locked_until DESC,
            last_fail_at DESC,
            updated_at DESC
        LIMIT 200
    ");
    $rlStmt->execute($rlParams);
    $rateLimits = $rlStmt->fetchAll();

} catch (Exception $e) {
    error_log("Security Logs Error: " . $e->getMessage());
    $errorMessage = "Database error: " . $e->getMessage();
    $logs = [];
    $eventTypes = [];
    $stats = ['total' => 0, 'today' => 0, 'unique_users' => 0, 'unique_ips' => 0];
    $totalPages = 0;
    $rateLimits = [];
}

// Helper function to get event icon
function getEventIcon($eventType)
{
    $icons = [
        'login' => 'fa-sign-in-alt',
        'logout' => 'fa-sign-out-alt',
        'failed_login' => 'fa-exclamation-triangle',
        'password_change' => 'fa-key',
        'profile_update' => 'fa-user-edit',
        'security_alert' => 'fa-shield-alt',
        'access_denied' => 'fa-ban',
        'data_export' => 'fa-download',
        'admin_action' => 'fa-crown'
    ];
    return $icons[strtolower($eventType)] ?? 'fa-circle';
}

// Helper function to get event color
function getEventColor($eventType)
{
    $colors = [
        'login' => '#10b981',
        'logout' => '#64748b',
        'failed_login' => '#ef4444',
        'password_change' => '#f59e0b',
        'profile_update' => '#3b82f6',
        'security_alert' => '#dc2626',
        'access_denied' => '#ef4444',
        'data_export' => '#8b5cf6',
        'admin_action' => '#f59e0b'
    ];
    return $colors[strtolower($eventType)] ?? '#6b7280';
}

function adminLogSecurityEvent(PDO $pdo, string $adminUsername, string $eventType, string $description): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO security_logs (username, event_type, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $adminUsername,
            strtolower($eventType),
            $description,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Failed to log admin security event: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScanQuotient |Security Logs</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --sq-bg-main: #f0f7ff;
            --sq-bg-card: #ffffff;
            --sq-bg-sidebar: #ffffff;
            --sq-text-main: #1e293b;
            --sq-text-light: #64748b;
            --sq-brand: #3b82f6;
            --sq-brand-light: #60a5fa;
            --sq-brand-dark: #1d4ed8;
            --sq-accent: #8b5cf6;
            --sq-border: #e2e8f0;
            --sq-success: #10b981;
            --sq-warning: #f59e0b;
            --sq-danger: #ef4444;
            --sq-info: #06b6d4;
            --sq-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --sq-shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        body.sq-dark {
            --sq-bg-main: #0f0f1a;
            --sq-bg-card: #1e1b4b;
            --sq-bg-sidebar: #1a1a2e;
            --sq-text-main: #f1f5f9;
            --sq-text-light: #94a3b8;
            --sq-brand: #8b5cf6;
            --sq-brand-light: #a78bfa;
            --sq-brand-dark: #6d28d9;
            --sq-accent: #3b82f6;
            --sq-border: rgba(139, 92, 246, 0.2);
            --sq-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
            --sq-shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--sq-bg-main);
            color: var(--sq-text-main);
            min-height: 100vh;
            line-height: 1.6;
        }

        .sq-admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 32px;
            background: transparent;
            /* Removed gradient */
            border-bottom: 1px solid var(--sq-border);
            box-shadow: none;
            /* Removed shadow for cleaner look */
            position: sticky;
            top: 0;
            z-index: 100;
        }

        body.sq-dark .sq-admin-header {
            background: transparent;
            /* Dark mode also transparent */
        }

        /* Optional: Add subtle backdrop blur for readability */
        .sq-admin-header {
            backdrop-filter: blur(8px);
            background: rgba(255, 255, 255, 0.05);
            /* Very subtle tint */
        }

        body.sq-dark .sq-admin-header {
            background: rgba(0, 0, 0, 0.05);
            /* Very subtle dark tint */
        }

        /* Rest of your CSS stays the same */
        .sq-admin-header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .sq-admin-back-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            background: transparent;
            color: #3b82f6;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 18px;
            transition: all 0.3s ease;
            filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.1));
        }

        body.sq-dark .sq-admin-back-btn {
            background: transparent;
            color: #a855f7;
            filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.3));
        }

        .sq-admin-back-btn:hover {
            color: #2563eb;
            transform: translateY(-3px) translateX(-3px) scale(1.1);
            filter: drop-shadow(0 6px 12px rgba(0, 0, 0, 0.15));
        }

        body.sq-dark .sq-admin-back-btn:hover {
            color: #9333ea;
            filter: drop-shadow(0 6px 12px rgba(0, 0, 0, 0.4));
        }

        .brand-wrapper {
            display: flex;
            flex-direction: column;
        }

        .sq-admin-brand {
            color: #4f20bb;
            font-weight: 700;
            text-decoration: none;
            font-size: 18px;
        }

        body.sq-dark .sq-admin-brand {
            color: #a78bfa;
        }

        .sq-admin-tagline {
            font-size: 12px;
            color: black;
            opacity: 0.75;
            margin-top: 2px;
        }

        body.sq-dark .sq-admin-tagline {
            color: var(--sq-text-light);
        }

        .sq-admin-header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sq-admin-user {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--sq-text-main);
            font-weight: 500;
            font-size: 14px;
            margin-right: 8px;
        }

        .sq-admin-user i {
            color: #3b82f6;
            font-size: 16px;
        }

        body.sq-dark .sq-admin-user i {
            color: #a855f7;
        }

        /* Floating icons - theme toggle and action buttons */
        .sq-admin-theme-toggle,
        .icon-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            background: transparent;
            color: #3b82f6;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            text-decoration: none;
            transition: all 0.3s ease;
            filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.1));
            position: relative;
        }

        body.sq-dark .sq-admin-theme-toggle,
        body.sq-dark .icon-btn {
            color: #a855f7;
            filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.3));
        }

        .sq-admin-theme-toggle:hover,
        .icon-btn:hover {
            background: transparent;
            color: #2563eb;
            transform: translateY(-3px) scale(1.1);
            filter: drop-shadow(0 6px 12px rgba(0, 0, 0, 0.15));
        }

        body.sq-dark .sq-admin-theme-toggle:hover,
        body.sq-dark .icon-btn:hover {
            color: #9333ea;
            filter: drop-shadow(0 6px 12px rgba(0, 0, 0, 0.4));
        }

        .sq-admin-theme-toggle:active,
        .icon-btn:active {
            transform: translateY(-1px) scale(1.05);
        }

        .sq-admin-theme-toggle i {
            transition: transform 0.3s ease;
        }

        .sq-admin-theme-toggle:hover i {
            transform: rotate(15deg);
        }


        .sq-admin-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 32px 20px;
        }

        h2 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 24px;
            color: var(--sq-text-main);
        }

        /* Main Container */
        .sq-admin-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 32px 20px;
        }

        /* Alert Messages */
        .sq-admin-alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            font-weight: 500;
            animation: sq-slide-down 0.3s ease;
        }

        @keyframes sq-slide-down {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .sq-admin-alert--error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: var(--sq-danger);
        }

        .sq-admin-alert-close {
            margin-left: auto;
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            font-size: 16px;
            opacity: 0.6;
            transition: opacity 0.2s;
        }

        .sq-admin-alert-close:hover {
            opacity: 1;
        }

        /* Stats Grid */
        .sq-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .sq-stat-card {
            background: var(--sq-bg-card);
            border: 1px solid var(--sq-border);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--sq-shadow);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: transform 0.3s;
        }

        .sq-stat-card:hover {
            transform: translateY(-4px);
        }

        .sq-stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .sq-stat-icon--total {
            background: rgba(59, 130, 246, 0.1);
            color: var(--sq-brand);
        }

        .sq-stat-icon--today {
            background: rgba(16, 185, 129, 0.1);
            color: var(--sq-success);
        }

        .sq-stat-icon--users {
            background: rgba(139, 92, 246, 0.1);
            color: var(--sq-accent);
        }

        .sq-stat-icon--ips {
            background: rgba(6, 182, 212, 0.1);
            color: var(--sq-info);
        }

        .sq-stat-content h3 {
            font-size: 28px;
            font-weight: 800;
            color: var(--sq-text-main);
            line-height: 1;
        }

        .sq-stat-content p {
            font-size: 13px;
            color: var(--sq-text-light);
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Filters Section */
        .sq-filters-section {
            background: var(--sq-bg-card);
            border: 1px solid var(--sq-border);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: var(--sq-shadow);
        }

        .sq-filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            align-items: end;
        }

        .sq-filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .sq-filter-label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--sq-text-light);
        }

        .sq-filter-input,
        .sq-filter-select {
            padding: 10px 14px;
            border: 2px solid var(--sq-border);
            border-radius: 10px;
            background: var(--sq-bg-main);
            color: var(--sq-text-main);
            font-size: 14px;
            transition: all 0.3s;
        }

        .sq-filter-input:focus,
        .sq-filter-select:focus {
            outline: none;
            border-color: var(--sq-brand);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        body.sq-dark .sq-filter-input:focus,
        body.sq-dark .sq-filter-select:focus {
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.2);
        }

        .sq-search-box {
            position: relative;
        }

        .sq-search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--sq-text-light);
        }

        .sq-search-input {
            padding-left: 40px;
            width: 100%;
        }

        .sq-filter-actions {
            display: flex;
            gap: 12px;
        }

        .sq-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            text-decoration: none;
        }

        .sq-btn-primary {
            background: var(--sq-brand);
            color: white;
        }

        .sq-btn-primary:hover {
            background: var(--sq-brand-dark);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3);
        }

        body.sq-dark .sq-btn-primary:hover {
            box-shadow: 0 8px 20px rgba(139, 92, 246, 0.4);
        }

        .sq-btn-secondary {
            background: var(--sq-bg-main);
            color: var(--sq-text-light);
            border: 2px solid var(--sq-border);
        }

        .sq-btn-secondary:hover {
            border-color: var(--sq-brand);
            color: var(--sq-brand);
        }

        /* Table Container */
        .sq-table-container {
            background: var(--sq-bg-card);
            border: 1px solid var(--sq-border);
            border-radius: 16px;
            box-shadow: var(--sq-shadow);
            overflow: hidden;
        }

        .sq-data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .sq-data-table th {
            background: var(--sq-bg-main);
            padding: 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--sq-text-light);
            border-bottom: 2px solid var(--sq-border);
            white-space: nowrap;
        }

        .sq-data-table td {
            padding: 16px;
            border-bottom: 1px solid var(--sq-border);
            vertical-align: top;
        }

        .sq-data-table tr:hover {
            background: rgba(59, 130, 246, 0.03);
        }

        body.sq-dark .sq-data-table tr:hover {
            background: rgba(139, 92, 246, 0.05);
        }

        .sq-data-table tr:last-child td {
            border-bottom: none;
        }

        /* Event Type Badge */
        .sq-event-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(59, 130, 246, 0.1);
            color: var(--sq-brand);
            white-space: nowrap;
        }

        /* User Cell */
        .sq-user-cell {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .sq-user-name {
            font-weight: 600;
            color: var(--sq-text-main);
        }

        .sq-user-system {
            font-style: italic;
            color: var(--sq-text-light);
        }

        /* Description Cell */
        .sq-description-cell {
            max-width: 300px;
            line-height: 1.5;
        }

        /* IP Address */
        .sq-ip-cell {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            color: var(--sq-text-light);
            background: var(--sq-bg-main);
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
        }

        /* Timestamp */
        .sq-timestamp-cell {
            display: flex;
            flex-direction: column;
            gap: 2px;
            white-space: nowrap;
        }

        .sq-timestamp-date {
            font-weight: 600;
        }

        .sq-timestamp-time {
            font-size: 12px;
            color: var(--sq-text-light);
        }

        /* User Agent Preview */
        .sq-ua-preview {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--sq-text-light);
            font-size: 12px;
            cursor: help;
        }

        /* View Details Button */
        .sq-view-btn {
            width: 36px;
            height: 36px;
            border: none;
            border-radius: 8px;
            background: rgba(59, 130, 246, 0.1);
            color: var(--sq-brand);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .sq-view-btn:hover {
            background: var(--sq-brand);
            color: white;
            transform: translateY(-2px);
        }

        /* Modal */
        .sq-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(8px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .sq-modal--active {
            display: flex;
        }

        .sq-modal-content {
            background: var(--sq-bg-card);
            border-radius: 24px;
            max-width: 700px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            animation: sq-modal-bounce 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes sq-modal-bounce {
            from {
                opacity: 0;
                transform: scale(0.8);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .sq-modal-header {
            padding: 24px;
            border-bottom: 1px solid var(--sq-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .sq-modal-title {
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sq-modal-close {
            width: 36px;
            height: 36px;
            border: none;
            background: var(--sq-bg-main);
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--sq-text-light);
            transition: all 0.3s;
        }

        .sq-modal-close:hover {
            background: var(--sq-danger);
            color: white;
        }

        .sq-modal-body {
            padding: 24px;
        }

        .sq-detail-grid {
            display: grid;
            gap: 20px;
        }

        .sq-detail-item {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .sq-detail-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--sq-text-light);
        }

        .sq-detail-value {
            font-size: 15px;
            color: var(--sq-text-main);
            line-height: 1.6;
        }

        .sq-detail-box {
            background: var(--sq-bg-main);
            padding: 16px;
            border-radius: 12px;
            border: 1px solid var(--sq-border);
            font-family: 'Courier New', monospace;
            font-size: 13px;
            word-break: break-all;
            max-height: 200px;
            overflow-y: auto;
        }

        .sq-modal-footer {
            padding: 20px 24px;
            border-top: 1px solid var(--sq-border);
            display: flex;
            justify-content: flex-end;
        }

        /* Pagination */
        .sq-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 24px;
            border-top: 1px solid var(--sq-border);
        }

        .sq-page-btn {
            min-width: 40px;
            height: 40px;
            border: 2px solid var(--sq-border);
            background: var(--sq-bg-main);
            color: var(--sq-text-light);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .sq-page-btn:hover {
            border-color: var(--sq-brand);
            color: var(--sq-brand);
        }

        .sq-page-btn.active {
            background: var(--sq-brand);
            border-color: var(--sq-brand);
            color: white;
        }

        .sq-page-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* Empty State */
        .sq-empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .sq-empty-icon {
            width: 80px;
            height: 80px;
            background: var(--sq-bg-main);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 32px;
            color: var(--sq-text-light);
        }

        .sq-empty-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--sq-text-main);
            margin-bottom: 8px;
        }

        .sq-empty-text {
            color: var(--sq-text-light);
            font-size: 14px;
        }

        /* Footer */
        .sq-admin-footer {
            margin-top: 40px;
            padding: 24px;
            text-align: center;
            color: var(--sq-text-light);
            font-size: 13px;
            border-top: 1px solid var(--sq-border);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sq-admin-header {
                padding: 12px 16px;
            }

            .sq-admin-container {
                padding: 20px 16px;
            }

            .sq-data-table {
                font-size: 13px;
            }

            .sq-data-table th,
            .sq-data-table td {
                padding: 12px 8px;
            }

            .sq-description-cell,
            .sq-ua-preview {
                max-width: 150px;
            }
        }

        /* Rate limit section */
        .sq-section-title {
            font-size: 18px;
            font-weight: 800;
            margin: 0 0 14px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sq-section-subtitle {
            color: var(--sq-text-light);
            font-size: 13px;
            margin-top: -8px;
            margin-bottom: 16px;
        }

        .sq-rate-controls {
            background: var(--sq-bg-card);
            border: 1px solid var(--sq-border);
            border-radius: 16px;
            padding: 18px;
            box-shadow: var(--sq-shadow);
            margin-bottom: 18px;
        }

        .sq-rate-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            align-items: end;
        }

        @media (max-width: 980px) {
            .sq-rate-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 520px) {
            .sq-rate-grid {
                grid-template-columns: 1fr;
            }
        }

        .sq-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            border: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(15, 23, 42, 0.04);
            color: var(--sq-text-main);
            white-space: nowrap;
        }

        body.sq-dark .sq-badge {
            background: rgba(148, 163, 184, 0.08);
            border-color: rgba(148, 163, 184, 0.14);
        }

        .sq-badge--locked {
            background: rgba(239, 68, 68, 0.10);
            border-color: rgba(239, 68, 68, 0.22);
            color: var(--sq-danger);
        }

        .sq-badge--active {
            background: rgba(16, 185, 129, 0.10);
            border-color: rgba(16, 185, 129, 0.22);
            color: var(--sq-success);
        }

        .sq-inline-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .sq-btn-danger {
            background: var(--sq-danger);
            color: #fff;
        }

        .sq-btn-danger:hover {
            background: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.25);
        }

        .sq-btn-warning {
            background: var(--sq-warning);
            color: #111827;
        }

        .sq-btn-warning:hover {
            background: #fbbf24;
            transform: translateY(-2px);
        }
    </style>
</head>

<body>
    <header class="sq-admin-header">
        <div class="sq-admin-header-left">
            <a href="../../../../Private/Admin_dashboard/PHP/Frontend/Admin_dashboard.php" class="sq-admin-back-btn">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div class="brand-wrapper">
                <a href="#" class="sq-admin-brand">ScanQuotient</a>
                <p class="sq-admin-tagline">Quantifying Risk. Strengthening Security.</p>
            </div>
        </div>
        <div class="sq-admin-header-right">
            <div class="sq-admin-user">
                <i class="fas fa-user-shield"></i>
                <span><?php echo htmlspecialchars($adminName); ?></span>
            </div>
            <button class="sq-admin-theme-toggle" id="sqThemeToggle" title="Toggle Theme">
                <i class="fas fa-sun"></i>
            </button>
            <a href="../../../../Private/Admin_dashboard/PHP/Frontend/Admin_dashboard.php" class="icon-btn"
                title="Home">
                <i class="fas fa-home"></i>
            </a>
            <a href="../../../../Public/Login_page/PHP/Frontend/Login_page_site.php" class="icon-btn" title="Logout">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </header>

    <!-- Detail Modal -->
    <div class="sq-modal" id="sqDetailModal">
        <div class="sq-modal-content">
            <div class="sq-modal-header">
                <h3 class="sq-modal-title" id="sqModalTitle">
                    <i class="fas fa-shield-alt"></i> Log Details
                </h3>
                <button class="sq-modal-close" onclick="sqCloseModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="sq-modal-body" id="sqModalBody">
                <!-- Content loaded dynamically -->
            </div>
            <div class="sq-modal-footer">
                <button class="sq-btn sq-btn-secondary" onclick="sqCloseModal()">Close</button>
            </div>
        </div>
    </div>

    <main class="sq-admin-container">

        <?php if ($successMessage): ?>
            <div class="sq-admin-alert"
                style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); color: var(--sq-success);">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($successMessage); ?></span>
                <button class="sq-admin-alert-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="sq-admin-alert sq-admin-alert--error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($errorMessage); ?></span>
                <button class="sq-admin-alert-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="sq-stats-grid">
            <div class="sq-stat-card">
                <div class="sq-stat-icon sq-stat-icon--total">
                    <i class="fas fa-database"></i>
                </div>
                <div class="sq-stat-content">
                    <h3><?php echo number_format($stats['total']); ?></h3>
                    <p>Total Logs</p>
                </div>
            </div>
            <div class="sq-stat-card">
                <div class="sq-stat-icon sq-stat-icon--today">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="sq-stat-content">
                    <h3><?php echo number_format($stats['today']); ?></h3>
                    <p>Today</p>
                </div>
            </div>
            <div class="sq-stat-card">
                <div class="sq-stat-icon sq-stat-icon--users">
                    <i class="fas fa-users"></i>
                </div>
                <div class="sq-stat-content">
                    <h3><?php echo number_format($stats['unique_users']); ?></h3>
                    <p>Unique Users</p>
                </div>
            </div>
            <div class="sq-stat-card">
                <div class="sq-stat-icon sq-stat-icon--ips">
                    <i class="fas fa-network-wired"></i>
                </div>
                <div class="sq-stat-content">
                    <h3><?php echo number_format($stats['unique_ips']); ?></h3>
                    <p>Unique IPs</p>
                </div>
            </div>
        </div>

        <!-- Login Rate Limits -->
        <div style="margin-top: 10px; margin-bottom: 22px;">
            <div class="sq-section-title">
                <i class="fas fa-user-lock" style="color: var(--sq-accent);"></i>
                Login lockouts & rate limits
            </div>
            <div class="sq-section-subtitle">
                Manage username/IP lockouts created by the login handler. Use carefully—locking an IP can block many
                users behind the same network.
            </div>

            <div class="sq-rate-controls">
                <form method="GET" class="sq-rate-grid">
                    <div class="sq-filter-group">
                        <label class="sq-filter-label">Scope</label>
                        <select name="rl_scope" class="sq-filter-select">
                            <option value="all" <?php echo ($rlScope ?? 'all') === 'all' ? 'selected' : ''; ?>>All
                            </option>
                            <option value="user" <?php echo ($rlScope ?? '') === 'user' ? 'selected' : ''; ?>>Usernames
                            </option>
                            <option value="ip" <?php echo ($rlScope ?? '') === 'ip' ? 'selected' : ''; ?>>IP addresses
                            </option>
                        </select>
                    </div>

                    <div class="sq-filter-group">
                        <label class="sq-filter-label">Search key</label>
                        <input type="text" name="rl_search" class="sq-filter-input"
                            placeholder="e.g. admin or 192.168.1.10"
                            value="<?php echo htmlspecialchars($rlSearch ?? ''); ?>">
                    </div>

                    <div class="sq-filter-group">
                        <label class="sq-filter-label">Quick lock</label>
                        <div style="display:flex; gap:10px; flex-wrap: wrap;">
                            <span style="color: var(--sq-text-light); font-size: 12px;">Use the row actions below or the
                                manual lock form.</span>
                        </div>
                    </div>

                    <div class="sq-filter-actions" style="justify-content:flex-end;">
                        <button type="submit" class="sq-btn sq-btn-primary">
                            <i class="fas fa-search"></i> Refresh
                        </button>
                        <a href="?" class="sq-btn sq-btn-secondary">
                            <i class="fas fa-undo"></i> Reset
                        </a>
                    </div>
                </form>

                <form method="POST" style="margin-top: 14px;">
                    <input type="hidden" name="rate_action" value="lock">
                    <div class="sq-rate-grid" style="grid-template-columns: repeat(5, minmax(0, 1fr));">
                        <div class="sq-filter-group">
                            <label class="sq-filter-label">Action</label>
                            <div class="sq-filter-input" style="background: var(--sq-bg-main);">Lock</div>
                        </div>
                        <div class="sq-filter-group">
                            <label class="sq-filter-label">Scope</label>
                            <select name="scope" class="sq-filter-select" required>
                                <option value="user">User</option>
                                <option value="ip">IP</option>
                            </select>
                        </div>
                        <div class="sq-filter-group" style="grid-column: span 2;">
                            <label class="sq-filter-label">Username / IP</label>
                            <input type="text" name="scope_key" class="sq-filter-input" required
                                placeholder="Enter username or IP">
                        </div>
                        <div class="sq-filter-group">
                            <label class="sq-filter-label">Minutes</label>
                            <input type="number" name="minutes" class="sq-filter-input" min="1" placeholder="5 / 15">
                        </div>
                    </div>
                    <div style="display:flex; justify-content:flex-end; margin-top: 12px;">
                        <button type="submit" class="sq-btn sq-btn-warning">
                            <i class="fas fa-lock"></i> Apply lock
                        </button>
                    </div>
                </form>
            </div>

            <div class="sq-table-container">
                <?php if (empty($rateLimits)): ?>
                    <div class="sq-empty-state">
                        <div class="sq-empty-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <h3 class="sq-empty-title">No rate limit entries</h3>
                        <p class="sq-empty-text">No username/IP lockouts recorded yet.</p>
                    </div>
                <?php else: ?>
                    <table class="sq-data-table">
                        <thead>
                            <tr>
                                <th>Scope</th>
                                <th>Key</th>
                                <th>Fails</th>
                                <th>Last fail</th>
                                <th>Locked until</th>
                                <th>Reason</th>
                                <th width="260">Admin actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rateLimits as $rl):
                                $isLocked = !empty($rl['locked_until']) && strtotime($rl['locked_until']) > time();
                                ?>
                                <tr>
                                    <td>
                                        <span class="sq-badge">
                                            <i
                                                class="fas <?php echo $rl['scope'] === 'ip' ? 'fa-network-wired' : 'fa-user'; ?>"></i>
                                            <?php echo strtoupper(htmlspecialchars($rl['scope'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="sq-ip-cell"
                                            style="max-width: 320px; overflow:hidden; text-overflow: ellipsis; white-space: nowrap; display:inline-block; vertical-align:middle;">
                                            <?php echo htmlspecialchars($rl['scope_key']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span
                                            class="sq-badge <?php echo ((int) $rl['fail_count'] > 0) ? 'sq-badge--locked' : 'sq-badge--active'; ?>">
                                            <i class="fas fa-hashtag"></i>
                                            <?php echo (int) $rl['fail_count']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($rl['last_fail_at'])): ?>
                                            <div class="sq-timestamp-cell">
                                                <span
                                                    class="sq-timestamp-date"><?php echo date('M j, Y', strtotime($rl['last_fail_at'])); ?></span>
                                                <span
                                                    class="sq-timestamp-time"><?php echo date('g:i:s A', strtotime($rl['last_fail_at'])); ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--sq-text-light);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($rl['locked_until'])): ?>
                                            <span class="sq-badge <?php echo $isLocked ? 'sq-badge--locked' : ''; ?>">
                                                <i class="fas <?php echo $isLocked ? 'fa-lock' : 'fa-lock-open'; ?>"></i>
                                                <?php echo date('M j, Y g:i A', strtotime($rl['locked_until'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="sq-badge sq-badge--active">
                                                <i class="fas fa-check"></i> Not locked
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="color: var(--sq-text-light); font-size: 12px;">
                                            <?php echo htmlspecialchars($rl['reason'] ?? '-'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="sq-inline-actions">
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="rate_action" value="unlock">
                                                <input type="hidden" name="scope"
                                                    value="<?php echo htmlspecialchars($rl['scope']); ?>">
                                                <input type="hidden" name="scope_key"
                                                    value="<?php echo htmlspecialchars($rl['scope_key']); ?>">
                                                <button type="submit" class="sq-btn sq-btn-secondary"
                                                    style="padding: 8px 12px;">
                                                    <i class="fas fa-unlock"></i> Unlock
                                                </button>
                                            </form>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="rate_action" value="reset">
                                                <input type="hidden" name="scope"
                                                    value="<?php echo htmlspecialchars($rl['scope']); ?>">
                                                <input type="hidden" name="scope_key"
                                                    value="<?php echo htmlspecialchars($rl['scope_key']); ?>">
                                                <button type="submit" class="sq-btn sq-btn-secondary"
                                                    style="padding: 8px 12px;">
                                                    <i class="fas fa-rotate-left"></i> Reset
                                                </button>
                                            </form>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="rate_action" value="lock">
                                                <input type="hidden" name="scope"
                                                    value="<?php echo htmlspecialchars($rl['scope']); ?>">
                                                <input type="hidden" name="scope_key"
                                                    value="<?php echo htmlspecialchars($rl['scope_key']); ?>">
                                                <input type="hidden" name="minutes"
                                                    value="<?php echo $rl['scope'] === 'ip' ? 15 : 5; ?>">
                                                <button type="submit" class="sq-btn sq-btn-danger" style="padding: 8px 12px;">
                                                    <i class="fas fa-lock"></i> Lock
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filters -->
        <div class="sq-filters-section">
            <form method="GET" class="sq-filters-grid">
                <div class="sq-filter-group">
                    <label class="sq-filter-label">Event Type</label>
                    <select name="event_type" class="sq-filter-select">
                        <option value="all">All Events</option>
                        <?php foreach ($eventTypes as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $eventType === $type ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $type))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sq-filter-group">
                    <label class="sq-filter-label">Date From</label>
                    <input type="date" name="date_from" class="sq-filter-input"
                        value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>

                <div class="sq-filter-group">
                    <label class="sq-filter-label">Date To</label>
                    <input type="date" name="date_to" class="sq-filter-input"
                        value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>

                <div class="sq-filter-group">
                    <label class="sq-filter-label">Search</label>
                    <div class="sq-search-box">
                        <i class="fas fa-search sq-search-icon"></i>
                        <input type="text" name="search" class="sq-filter-input sq-search-input"
                            placeholder="User, IP, description..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>

                <div class="sq-filter-actions">
                    <button type="submit" class="sq-btn sq-btn-primary">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                    <a href="?" class="sq-btn sq-btn-secondary">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Data Table -->
        <div class="sq-table-container">
            <?php if (empty($logs)): ?>
                <div class="sq-empty-state">
                    <div class="sq-empty-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <h3 class="sq-empty-title">No logs found</h3>
                    <p class="sq-empty-text">
                        <?php echo !empty($search) || $eventType !== 'all' ? 'Try adjusting your filters.' : 'No security logs available.'; ?>
                    </p>
                </div>
            <?php else: ?>
                <table class="sq-data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Event Type</th>
                            <th>User</th>
                            <th>Description</th>
                            <th>IP Address</th>
                            <th>User Agent</th>
                            <th>Timestamp</th>
                            <th width="60">View</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log):
                            $eventColor = getEventColor($log['event_type']);
                            $eventIcon = getEventIcon($log['event_type']);
                            ?>
                            <tr>
                                <td>#<?php echo $log['id']; ?></td>
                                <td>
                                    <span class="sq-event-badge"
                                        style="background: <?php echo $eventColor; ?>20; color: <?php echo $eventColor; ?>;">
                                        <i class="fas <?php echo $eventIcon; ?>"></i>
                                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $log['event_type']))); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="sq-user-cell">
                                        <?php if (!empty($log['username'])): ?>
                                            <span class="sq-user-name"><?php echo htmlspecialchars($log['username']); ?></span>
                                        <?php else: ?>
                                            <span class="sq-user-system">System</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="sq-description-cell"
                                        title="<?php echo htmlspecialchars($log['description']); ?>">
                                        <?php echo htmlspecialchars($log['description']); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($log['ip_address'])): ?>
                                        <span class="sq-ip-cell"><?php echo htmlspecialchars($log['ip_address']); ?></span>
                                    <?php else: ?>
                                        <span style="color: var(--sq-text-light);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($log['user_agent'])): ?>
                                        <div class="sq-ua-preview" title="<?php echo htmlspecialchars($log['user_agent']); ?>">
                                            <?php echo htmlspecialchars($log['user_agent']); ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: var(--sq-text-light);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="sq-timestamp-cell">
                                        <span
                                            class="sq-timestamp-date"><?php echo date('M j, Y', strtotime($log['created_at'])); ?></span>
                                        <span
                                            class="sq-timestamp-time"><?php echo date('g:i:s A', strtotime($log['created_at'])); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <button type="button" class="sq-view-btn"
                                        onclick="sqViewLog(<?php echo htmlspecialchars(json_encode($log)); ?>, '<?php echo $eventColor; ?>', '<?php echo $eventIcon; ?>')"
                                        title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="sq-pagination">
                        <?php
                        $queryParams = "event_type=$eventType&search=" . urlencode($search) . "&date_from=$dateFrom&date_to=$dateTo";
                        ?>

                        <?php if ($page > 1): ?>
                            <a href="?<?php echo $queryParams; ?>&page=<?php echo $page - 1; ?>" class="sq-page-btn">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php else: ?>
                            <span class="sq-page-btn disabled"><i class="fas fa-chevron-left"></i></span>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);

                        if ($startPage > 1): ?>
                            <a href="?<?php echo $queryParams; ?>&page=1" class="sq-page-btn">1</a>
                            <?php if ($startPage > 2): ?>
                                <span class="sq-page-btn disabled">...</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="sq-page-btn active"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?<?php echo $queryParams; ?>&page=<?php echo $i; ?>" class="sq-page-btn"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <span class="sq-page-btn disabled">...</span>
                            <?php endif; ?>
                            <a href="?<?php echo $queryParams; ?>&page=<?php echo $totalPages; ?>"
                                class="sq-page-btn"><?php echo $totalPages; ?></a>
                        <?php endif; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="?<?php echo $queryParams; ?>&page=<?php echo $page + 1; ?>" class="sq-page-btn">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="sq-page-btn disabled"><i class="fas fa-chevron-right"></i></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <footer class="sq-admin-footer">
            <p>ScanQuotient Security Platform • Quantifying Risk. Strengthening Security.</p>
            <p style="margin-top: 8px; font-size: 12px;">
                Logged in as <?php echo htmlspecialchars($adminName); ?> •
                <a href="/ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Backend/logout_from_the_system.php"
                    style="color: var(--sq-brand); text-decoration: none;">Logout</a>
            </p>
        </footer>
    </main>

    <script>
        // Theme Toggle
        const sqThemeToggle = document.getElementById('sqThemeToggle');
        const sqBody = document.body;

        function sqSetTheme(theme) {
            sqBody.classList.toggle('sq-dark', theme === 'dark');
            sqThemeToggle.innerHTML = theme === 'dark' ? '<i class="fas fa-moon"></i>' : '<i class="fas fa-sun"></i>';
        }

        sqThemeToggle.addEventListener('click', () => {
            const current = sqBody.classList.contains('sq-dark') ? 'light' : 'dark';
            sqSetTheme(current);
            localStorage.setItem('sq-admin-theme', current);
        });

        sqSetTheme(localStorage.getItem('sq-admin-theme') || 'light');

        // Detail Modal
        const sqDetailModal = document.getElementById('sqDetailModal');
        const sqModalTitle = document.getElementById('sqModalTitle');
        const sqModalBody = document.getElementById('sqModalBody');

        function sqViewLog(log, color, icon) {
            const eventTypeFormatted = log.event_type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());

            sqModalTitle.innerHTML = `
                <span style="display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; background: ${color}20; color: ${color}; border-radius: 10px; margin-right: 8px;">
                    <i class="fas ${icon}"></i>
                </span>
                ${eventTypeFormatted}
            `;

            sqModalBody.innerHTML = `
                <div class="sq-detail-grid">
                    <div class="sq-detail-item">
                        <div class="sq-detail-label">Log ID</div>
                        <div class="sq-detail-value">#${log.id}</div>
                    </div>
                    
                    <div class="sq-detail-item">
                        <div class="sq-detail-label">Timestamp</div>
                        <div class="sq-detail-value">${formatDateTime(log.created_at)}</div>
                    </div>
                    
                    <div class="sq-detail-item">
                        <div class="sq-detail-label">Username</div>
                        <div class="sq-detail-value">${log.username ? escapeHtml(log.username) : '<em style="color: var(--sq-text-light);">System / Anonymous</em>'}</div>
                    </div>
                    
                    <div class="sq-detail-item">
                        <div class="sq-detail-label">IP Address</div>
                        <div class="sq-detail-value">
                            ${log.ip_address ? `<span class="sq-ip-cell">${escapeHtml(log.ip_address)}</span>` : '<em style="color: var(--sq-text-light);">Not recorded</em>'}
                        </div>
                    </div>
                    
                    <div class="sq-detail-item">
                        <div class="sq-detail-label">Description</div>
                        <div class="sq-detail-value" style="background: var(--sq-bg-main); padding: 16px; border-radius: 12px; border: 1px solid var(--sq-border);">
                            ${escapeHtml(log.description)}
                        </div>
                    </div>
                    
                    <div class="sq-detail-item">
                        <div class="sq-detail-label">User Agent</div>
                        <div class="sq-detail-box">
                            ${log.user_agent ? escapeHtml(log.user_agent) : '<em style="color: var(--sq-text-light);">Not recorded</em>'}
                        </div>
                    </div>
                </div>
            `;

            sqDetailModal.classList.add('sq-modal--active');
            sqBody.style.overflow = 'hidden';
        }

        function sqCloseModal() {
            sqDetailModal.classList.remove('sq-modal--active');
            sqBody.style.overflow = '';
        }

        // Close modal on outside click
        sqDetailModal?.addEventListener('click', (e) => {
            if (e.target === sqDetailModal) sqCloseModal();
        });

        // Escape key to close modal
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && sqDetailModal.classList.contains('sq-modal--active')) {
                sqCloseModal();
            }
        });

        // Helper functions
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDateTime(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        }

        // Auto-hide alerts after 5 seconds
        document.querySelectorAll('.sq-admin-alert').forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        });
    </script>
</body>

</html>