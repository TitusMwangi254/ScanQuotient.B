<?php
// Site_security.php - Admin view for site security controls + logs
session_start();
// Keep all lock/unlock timestamps in East Africa Time (UTC+03:00).
date_default_timezone_set('Africa/Nairobi');

// Debug: Check session
error_log("Security Logs Session: " . print_r($_SESSION, true));

// Authentication check
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header("Location: /ScanQuotient.v2/ScanQuotient.B/Public/Login_page/PHP/Frontend/Login_page_site.php?error=not_authenticated");
    exit();
}

// Check if user is admin
$userRole = $_SESSION['role'] ?? 'user';
if ($userRole !== 'admin' && $userRole !== 'super_admin') {
    header("Location: /ScanQuotient.v2/ScanQuotient.B/Public/Login_page/PHP/Frontend/Login_page_site.php?error=unauthorized");
    exit();
}

// Database configuration
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Pagination settings
define('DEFAULT_PER_PAGE', 10);

$adminName = $_SESSION['user_name'] ?? 'Admin';
$profile_photo = $_SESSION['profile_photo'] ?? null;
if (!empty($profile_photo)) {
    $photo_path = ltrim((string) $profile_photo, '/');
    $base_url = '/ScanQuotient.v2/ScanQuotient.B';
    $avatar_url = $base_url . '/' . $photo_path;
} else {
    $avatar_url = '/ScanQuotient.v2/ScanQuotient.B/Storage/Public_images/default-avatar.png';
}
$errorMessage = '';
$successMessage = '';
$perPageParam = (string) DEFAULT_PER_PAGE;
$effectivePerPage = (int) DEFAULT_PER_PAGE;
$totalItems = 0;
$totalPages = 0;
$offset = 0;
$recordsStart = 0;
$recordsEnd = 0;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    // Align MySQL session time with EAT so NOW(), comparisons and ordering match UI time.
    $pdo->exec("SET time_zone = '+03:00'");

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
            deleted_at DATETIME NULL,
            deleted_by VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_scope_key (scope, scope_key),
            INDEX idx_locked_until (locked_until),
            INDEX idx_last_fail (last_fail_at),
            INDEX idx_deleted_at (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Add soft-delete columns if table existed previously
    try { $pdo->exec("ALTER TABLE login_rate_limits ADD COLUMN deleted_at DATETIME NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE login_rate_limits ADD COLUMN deleted_by VARCHAR(255) NULL"); } catch (Exception $e) {}

    // Certificates: admin-created user agreements that must be accepted
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS security_certificates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            target_type ENUM('everyone','role','user_id','username') NOT NULL DEFAULT 'everyone',
            target_value VARCHAR(255) NULL,
            is_active ENUM('yes','no') NOT NULL DEFAULT 'yes',
            created_by VARCHAR(255) NULL,
            updated_by VARCHAR(255) NULL,
            deleted_at DATETIME NULL,
            deleted_by VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_active (is_active),
            INDEX idx_deleted_at (deleted_at),
            INDEX idx_target (target_type, target_value),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Add soft-delete / audit columns if table existed previously
    try { $pdo->exec("ALTER TABLE security_certificates ADD COLUMN updated_by VARCHAR(255) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE security_certificates ADD COLUMN deleted_at DATETIME NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE security_certificates ADD COLUMN deleted_by VARCHAR(255) NULL"); } catch (Exception $e) {}

    // Avoid FK constraints to prevent schema drift issues.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS security_certificate_acceptances (
            id INT AUTO_INCREMENT PRIMARY KEY,
            certificate_id INT NOT NULL,
            user_id VARCHAR(255) NOT NULL,
            accepted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            accepted_ip VARCHAR(45) NULL,
            accepted_user_agent TEXT NULL,
            UNIQUE KEY uniq_cert_user (certificate_id, user_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Admin actions for login rate limits / policies
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Security policies (bulk apply) - independent controls
        if (($_POST['action'] ?? '') === 'apply_2fa_policy') {
            $target = $_POST['target'] ?? 'everyone'; // everyone|admins|users
            $twoFa = ($_POST['two_factor_enabled'] ?? 'no') === 'yes' ? 'yes' : 'no';

            $where = '';
            if ($target === 'admins') {
                $where = "WHERE role IN ('admin','super_admin')";
            } elseif ($target === 'users') {
                $where = "WHERE role = 'user'";
            } else {
                $target = 'everyone';
                $where = "WHERE role IN ('user','admin','super_admin')";
            }

            $sql = "
                UPDATE users
                SET
                    two_factor_enabled = :twofa,
                    updated_at = NOW(),
                    updated_by = :admin
                {$where}
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':twofa' => $twoFa,
                ':admin' => $adminName
            ]);

            $twoFaLabel = $twoFa === 'yes' ? 'enabled' : 'disabled';
            $successMessage = "2FA {$twoFaLabel} for {$target}.";
            adminLogSecurityEvent($pdo, $adminName, 'ADMIN_ACTION', "Applied 2FA policy target={$target} 2fa={$twoFa}");
        }

        if (($_POST['action'] ?? '') === 'apply_password_reset_policy') {
            $target = $_POST['target'] ?? 'everyone'; // everyone|admins|users
            $pwReset = ($_POST['password_reset_required'] ?? 'no') === 'yes' ? 'yes' : 'no';

            $where = '';
            if ($target === 'admins') {
                $where = "WHERE role IN ('admin','super_admin')";
            } elseif ($target === 'users') {
                $where = "WHERE role = 'user'";
            } else {
                $target = 'everyone';
                $where = "WHERE role IN ('user','admin','super_admin')";
            }

            $resetExpiresSql = $pwReset === 'yes'
                ? "DATE_ADD(NOW(), INTERVAL 7 DAY)"
                : "NULL";

            $sql = "
                UPDATE users
                SET
                    password_reset_status = :pwreset,
                    password_reset_expires = {$resetExpiresSql},
                    updated_at = NOW(),
                    updated_by = :admin
                {$where}
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':pwreset' => $pwReset,
                ':admin' => $adminName
            ]);

            $pwLabel = $pwReset === 'yes' ? 'enabled' : 'disabled';
            $successMessage = "Password reset {$pwLabel} for {$target}.";
            adminLogSecurityEvent($pdo, $adminName, 'ADMIN_ACTION', "Applied password reset target={$target} pw_reset={$pwReset}");
        }

        // Create certificate
        if (($_POST['action'] ?? '') === 'create_certificate') {
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $targetType = $_POST['target_type'] ?? 'everyone';
            $targetValue = trim((string)($_POST['target_value'] ?? ''));
            $isActive = (($_POST['is_active'] ?? 'yes') === 'no') ? 'no' : 'yes';

            $validTargetTypes = ['everyone', 'role', 'user_id', 'username'];
            if (!in_array($targetType, $validTargetTypes, true)) {
                $targetType = 'everyone';
            }
            if ($targetType === 'everyone') {
                $targetValue = '';
            }

            if ($title === '' || $body === '') {
                $errorMessage = 'Certificate title and body are required.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO security_certificates (title, body, target_type, target_value, is_active, created_by)
                    VALUES (?, ?, ?, NULLIF(?, ''), ?, ?)
                ");
                $stmt->execute([$title, $body, $targetType, $targetValue, $isActive, $adminName]);
                $successMessage = 'Certificate created successfully.';
                adminLogSecurityEvent($pdo, $adminName, 'ADMIN_ACTION', "Created certificate '{$title}' target={$targetType} value={$targetValue} active={$isActive}");
            }
        }

        // Toggle active status
        if (($_POST['action'] ?? '') === 'toggle_certificate_active') {
            $certId = (int)($_POST['certificate_id'] ?? 0);
            $next = (($_POST['next_active'] ?? 'no') === 'yes') ? 'yes' : 'no';
            if ($certId > 0) {
                $stmt = $pdo->prepare("UPDATE security_certificates SET is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
                $stmt->execute([$next, $adminName, $certId]);
                $successMessage = $next === 'yes' ? 'Certificate marked active.' : 'Certificate marked not active.';
                adminLogSecurityEvent($pdo, $adminName, 'ADMIN_ACTION', "Set certificate {$certId} active={$next}");
            }
        }

        // Soft delete
        if (($_POST['action'] ?? '') === 'soft_delete_certificate') {
            $certId = (int)($_POST['certificate_id'] ?? 0);
            if ($certId > 0) {
                $stmt = $pdo->prepare("UPDATE security_certificates SET deleted_at = NOW(), deleted_by = ?, updated_by = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
                $stmt->execute([$adminName, $adminName, $certId]);
                $successMessage = 'Certificate moved to trash.';
                adminLogSecurityEvent($pdo, $adminName, 'ADMIN_ACTION', "Soft deleted certificate {$certId}");
            }
        }

        // Restore
        if (($_POST['action'] ?? '') === 'restore_certificate') {
            $certId = (int)($_POST['certificate_id'] ?? 0);
            if ($certId > 0) {
                $stmt = $pdo->prepare("UPDATE security_certificates SET deleted_at = NULL, deleted_by = NULL, updated_by = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
                $stmt->execute([$adminName, $certId]);
                $successMessage = 'Certificate restored.';
                adminLogSecurityEvent($pdo, $adminName, 'ADMIN_ACTION', "Restored certificate {$certId}");
            }
        }

        // Forever delete
        if (($_POST['action'] ?? '') === 'delete_certificate_forever') {
            $certId = (int)($_POST['certificate_id'] ?? 0);
            if ($certId > 0) {
                // Remove acceptances first (no FK)
                $pdo->prepare("DELETE FROM security_certificate_acceptances WHERE certificate_id = ?")->execute([$certId]);
                $pdo->prepare("DELETE FROM security_certificates WHERE id = ? LIMIT 1")->execute([$certId]);
                $successMessage = 'Certificate permanently deleted.';
                adminLogSecurityEvent($pdo, $adminName, 'ADMIN_ACTION', "Permanently deleted certificate {$certId}");
            }
        }

        // Edit certificate (and force re-acceptance by clearing acceptances)
        if (($_POST['action'] ?? '') === 'update_certificate') {
            $certId = (int)($_POST['certificate_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            $targetType = $_POST['target_type'] ?? 'everyone';
            $targetValue = trim((string)($_POST['target_value'] ?? ''));
            $isActive = (($_POST['is_active'] ?? 'yes') === 'no') ? 'no' : 'yes';

            $validTargetTypes = ['everyone', 'role', 'user_id', 'username'];
            if (!in_array($targetType, $validTargetTypes, true)) {
                $targetType = 'everyone';
            }
            if ($targetType === 'everyone') {
                $targetValue = '';
            }

            if ($certId <= 0 || $title === '' || $body === '') {
                $errorMessage = 'Invalid certificate update request.';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE security_certificates
                    SET title = ?, body = ?, target_type = ?, target_value = NULLIF(?, ''), is_active = ?, updated_by = ?, updated_at = NOW()
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->execute([$title, $body, $targetType, $targetValue, $isActive, $adminName, $certId]);

                // Force re-acceptance
                $pdo->prepare("DELETE FROM security_certificate_acceptances WHERE certificate_id = ?")->execute([$certId]);

                $successMessage = 'Certificate updated. Users will be required to sign again.';
                adminLogSecurityEvent($pdo, $adminName, 'ADMIN_ACTION', "Updated certificate {$certId} and cleared acceptances");
            }
        }

        // Rate limit actions: only run when explicitly posted
        if (isset($_POST['rate_action'])) {
            $action = $_POST['rate_action'] ?? '';
            $scope = $_POST['scope'] ?? '';
            $scopeKey = trim($_POST['scope_key'] ?? '');
            $minutes = intval($_POST['minutes'] ?? 0);

            $validScopes = ['user', 'ip'];
            $validActions = ['lock', 'unlock', 'reset', 'soft_delete', 'restore', 'permanent_delete'];

            if (!in_array($scope, $validScopes, true) || !in_array($action, $validActions, true) || $scopeKey === '') {
                $errorMessage = 'Invalid rate limit request.';
            } else {
                if ($action === 'unlock') {
                $stmt = $pdo->prepare("
                    UPDATE login_rate_limits
                    SET fail_count = 0, first_fail_at = NULL, last_fail_at = NULL, locked_until = NULL, lock_minutes = NULL, reason = 'admin_unlock', deleted_at = NULL, deleted_by = NULL
                    WHERE scope = ? AND scope_key = ?
                ");
                $stmt->execute([$scope, $scopeKey]);

                // If record didn't exist, create a clean one (optional, keeps admin audit trail)
                if ($stmt->rowCount() === 0) {
                    $ins = $pdo->prepare("
                        INSERT INTO login_rate_limits (scope, scope_key, fail_count, first_fail_at, last_fail_at, locked_until, lock_minutes, reason, deleted_at, deleted_by)
                        VALUES (?, ?, 0, NULL, NULL, NULL, NULL, 'admin_unlock', NULL, NULL)
                        ON DUPLICATE KEY UPDATE reason = VALUES(reason), locked_until = NULL, fail_count = 0, first_fail_at = NULL, last_fail_at = NULL, lock_minutes = NULL, deleted_at = NULL, deleted_by = NULL
                    ");
                    $ins->execute([$scope, $scopeKey]);
                }

                $successMessage = ucfirst($scope) . ' unlocked successfully.';
                adminLogSecurityEvent($pdo, $adminName, 'ADMIN_ACTION', "Unlocked {$scope}: {$scopeKey}");
                } elseif ($action === 'reset') {
                $stmt = $pdo->prepare("
                    UPDATE login_rate_limits
                    SET fail_count = 0, first_fail_at = NULL, last_fail_at = NULL, locked_until = NULL, lock_minutes = NULL, reason = 'admin_reset', deleted_at = NULL, deleted_by = NULL
                    WHERE scope = ? AND scope_key = ?
                ");
                $stmt->execute([$scope, $scopeKey]);

                if ($stmt->rowCount() === 0) {
                    $ins = $pdo->prepare("
                        INSERT INTO login_rate_limits (scope, scope_key, fail_count, first_fail_at, last_fail_at, locked_until, lock_minutes, reason, deleted_at, deleted_by)
                        VALUES (?, ?, 0, NULL, NULL, NULL, NULL, 'admin_reset', NULL, NULL)
                        ON DUPLICATE KEY UPDATE reason = VALUES(reason), locked_until = NULL, fail_count = 0, first_fail_at = NULL, last_fail_at = NULL, lock_minutes = NULL, deleted_at = NULL, deleted_by = NULL
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
                    INSERT INTO login_rate_limits (scope, scope_key, fail_count, first_fail_at, last_fail_at, locked_until, lock_minutes, reason, deleted_at, deleted_by)
                    VALUES (?, ?, GREATEST(5, fail_count), NOW(), NOW(), ?, ?, 'admin_lock', NULL, NULL)
                    ON DUPLICATE KEY UPDATE
                        locked_until = VALUES(locked_until),
                        lock_minutes = VALUES(lock_minutes),
                        reason = VALUES(reason),
                        last_fail_at = NOW(),
                        deleted_at = NULL,
                        deleted_by = NULL
                ");
                $stmt->execute([$scope, $scopeKey, $lockedUntil, $minutes]);

                $successMessage = ucfirst($scope) . " locked for {$minutes} minute(s).";
                adminLogSecurityEvent($pdo, $adminName, 'ADMIN_ACTION', "Locked {$scope}: {$scopeKey} for {$minutes} minutes");
                } elseif ($action === 'soft_delete') {
                $stmt = $pdo->prepare("
                    UPDATE login_rate_limits
                    SET
                        fail_count = 0,
                        first_fail_at = NULL,
                        last_fail_at = NULL,
                        locked_until = NULL,
                        lock_minutes = NULL,
                        reason = 'admin_trash',
                        deleted_at = NOW(),
                        deleted_by = ?
                    WHERE scope = ? AND scope_key = ?
                ");
                $stmt->execute([$adminName, $scope, $scopeKey]);

                if ($stmt->rowCount() === 0) {
                    $ins = $pdo->prepare("
                        INSERT INTO login_rate_limits (scope, scope_key, fail_count, first_fail_at, last_fail_at, locked_until, lock_minutes, reason, deleted_at, deleted_by)
                        VALUES (?, ?, 0, NULL, NULL, NULL, NULL, 'admin_trash', NOW(), ?)
                        ON DUPLICATE KEY UPDATE
                            fail_count = 0,
                            first_fail_at = NULL,
                            last_fail_at = NULL,
                            locked_until = NULL,
                            lock_minutes = NULL,
                            reason = VALUES(reason),
                            deleted_at = VALUES(deleted_at),
                            deleted_by = VALUES(deleted_by)
                    ");
                    $ins->execute([$scope, $scopeKey, $adminName]);
                }

                $successMessage = ucfirst($scope) . ' moved to trash.';
                adminLogSecurityEvent($pdo, $adminName, 'ADMIN_ACTION', "Trashed rate limit {$scope}: {$scopeKey}");
                } elseif ($action === 'restore') {
                $stmt = $pdo->prepare("
                    UPDATE login_rate_limits
                    SET deleted_at = NULL, deleted_by = NULL, reason = 'admin_restore', updated_at = NOW()
                    WHERE scope = ? AND scope_key = ?
                ");
                $stmt->execute([$scope, $scopeKey]);
                $successMessage = ucfirst($scope) . ' restored.';
                adminLogSecurityEvent($pdo, $adminName, 'ADMIN_ACTION', "Restored rate limit {$scope}: {$scopeKey}");
                } elseif ($action === 'permanent_delete') {
                $stmt = $pdo->prepare("DELETE FROM login_rate_limits WHERE scope = ? AND scope_key = ? LIMIT 1");
                $stmt->execute([$scope, $scopeKey]);
                $successMessage = ucfirst($scope) . ' deleted forever.';
                adminLogSecurityEvent($pdo, $adminName, 'ADMIN_ACTION', "Permanently deleted rate limit {$scope}: {$scopeKey}");
                }
            }
        }
    }

    // Get filter parameters
    $eventType = $_GET['event_type'] ?? 'all';
    $search = $_GET['search'] ?? '';
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPageParam = $_GET['per_page'] ?? (string) DEFAULT_PER_PAGE;
    $allowedPerPage = ['5', '10', '20', '50', '100', '200', 'all'];
    if (!in_array($perPageParam, $allowedPerPage, true)) {
        $perPageParam = (string) DEFAULT_PER_PAGE;
    }
    $effectivePerPage = $perPageParam === 'all' ? null : (int) $perPageParam;

    // Rate limit filters
    $rlScope = $_GET['rl_scope'] ?? 'all'; // all, user, ip
    $rlView = $_GET['rl_view'] ?? 'active'; // active, trashed, all
    $rlSearch = $_GET['rl_search'] ?? '';
    $rlStatus = $_GET['rl_status'] ?? 'all'; // all, locked, unlocked
    $rlPage = max(1, intval($_GET['rl_page'] ?? 1));
    $rlPerPageParam = $_GET['rl_per_page'] ?? (string) DEFAULT_PER_PAGE;
    if (!in_array((string) $rlPerPageParam, $allowedPerPage, true)) {
        $rlPerPageParam = (string) DEFAULT_PER_PAGE;
    }
    $rlEffectivePerPage = $rlPerPageParam === 'all' ? null : (int) $rlPerPageParam;

    // Certificate filters
    $certView = $_GET['cert_view'] ?? 'all'; // all, active, inactive, trashed
    $certSearch = $_GET['cert_search'] ?? '';
    $certPage = max(1, intval($_GET['cert_page'] ?? 1));
    $certPerPageParam = $_GET['cert_per_page'] ?? (string) DEFAULT_PER_PAGE;
    if (!in_array((string) $certPerPageParam, $allowedPerPage, true)) {
        $certPerPageParam = (string) DEFAULT_PER_PAGE;
    }
    $certEffectivePerPage = $certPerPageParam === 'all' ? null : (int) $certPerPageParam;

    // Build query
    $whereConditions = [];
    $params = [];
    $securitySearchColumns = [];

    if ($eventType !== 'all') {
        $whereConditions[] = "event_type = ?";
        $params[] = $eventType;
    }

    if (!empty($search)) {
        // Global search across all non-system columns.
        // This also powers the dynamic "Matched In" column.
        $systemSearchColumns = ['id', 'created_at', 'updated_at'];
        $securitySearchColumns = [];
        try {
            $colsMeta = $pdo->query("SHOW COLUMNS FROM security_logs")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($colsMeta as $meta) {
                $col = $meta['Field'] ?? '';
                if (!$col) continue;
                if (!preg_match('/^[A-Za-z0-9_]+$/', $col)) continue;
                if (in_array($col, $systemSearchColumns, true)) continue;
                $securitySearchColumns[] = $col;
            }
        } catch (Exception $e) {
            // Fallback: previous behavior
            $securitySearchColumns = ['username', 'description', 'ip_address'];
        }

        if (!empty($securitySearchColumns)) {
            $searchTerm = "%$search%";
            $orParts = [];
            foreach ($securitySearchColumns as $col) {
                $orParts[] = "CAST($col AS CHAR) LIKE ?";
                $params[] = $searchTerm;
            }
            $whereConditions[] = "(" . implode(" OR ", $orParts) . ")";
        }
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
    $totalPages = ($effectivePerPage === null || (int) $totalItems === 0) ? 1 : (int) ceil($totalItems / $effectivePerPage);
    $offset = $effectivePerPage === null ? 0 : ($page - 1) * $effectivePerPage;
    $recordsStart = (int) $totalItems === 0 ? 0 : ($offset + 1);
    $recordsEnd = $effectivePerPage === null ? (int) $totalItems : (int) min($offset + $effectivePerPage, $totalItems);

    // Get logs
    $queryBase = "SELECT * FROM security_logs $whereClause ORDER BY created_at DESC";
    $query = $effectivePerPage === null
        ? $queryBase
        : ($queryBase . " LIMIT " . (int) $effectivePerPage . " OFFSET " . (int) $offset);
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

    // Metrics charts: last 14 days logs volume + top event types (30 days)
    $logsMetricsLabels = [];
    $logsMetricsCounts = [];
    $logsMetricsTopLabels = [];
    $logsMetricsTopCounts = [];
    try {
        $days = 14;
        $stmt = $pdo->prepare("
            SELECT DATE(created_at) AS d, COUNT(*) AS cnt
            FROM security_logs
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY d ASC
        ");
        $stmt->execute([$days - 1]);
        $rows = $stmt->fetchAll();
        $byDay = [];
        foreach ($rows as $r) {
            $key = (string) ($r['d'] ?? '');
            if ($key === '') continue;
            $byDay[$key] = (int) ($r['cnt'] ?? 0);
        }
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $logsMetricsLabels[] = date('M j', strtotime($day));
            $logsMetricsCounts[] = (int) ($byDay[$day] ?? 0);
        }

        $topStmt = $pdo->query("
            SELECT event_type, COUNT(*) AS cnt
            FROM security_logs
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY event_type
            ORDER BY cnt DESC
            LIMIT 6
        ");
        $topRows = $topStmt->fetchAll();
        foreach ($topRows as $tr) {
            $logsMetricsTopLabels[] = (string) ($tr['event_type'] ?? 'unknown');
            $logsMetricsTopCounts[] = (int) ($tr['cnt'] ?? 0);
        }
    } catch (Exception $e) {
        $logsMetricsLabels = [];
        $logsMetricsCounts = [];
        $logsMetricsTopLabels = [];
        $logsMetricsTopCounts = [];
    }

    // Fetch login rate limit entries for admin view
    $rlWhere = [];
    $rlParams = [];
    if (($rlView ?? 'active') === 'active') {
        $rlWhere[] = "deleted_at IS NULL";
    } elseif (($rlView ?? '') === 'trashed') {
        $rlWhere[] = "deleted_at IS NOT NULL";
    } else {
        $rlView = 'all';
        // no where
    }
    if ($rlScope === 'user' || $rlScope === 'ip') {
        $rlWhere[] = "scope = ?";
        $rlParams[] = $rlScope;
    }
    if (!empty($rlSearch)) {
        $rlWhere[] = "scope_key LIKE ?";
        $rlParams[] = '%' . $rlSearch . '%';
    }
    if ($rlStatus === 'locked') {
        $rlWhere[] = "(locked_until IS NOT NULL AND locked_until > NOW())";
    } elseif ($rlStatus === 'unlocked') {
        $rlWhere[] = "(locked_until IS NULL OR locked_until <= NOW())";
    } else {
        $rlStatus = 'all';
    }
    $rlWhereClause = !empty($rlWhere) ? ('WHERE ' . implode(' AND ', $rlWhere)) : '';
    $rlTotalItems = 0;
    $rlTotalPages = 1;
    $rlOffset = 0;
    $rlRecordsStart = 0;
    $rlRecordsEnd = 0;

    $rlCountStmt = $pdo->prepare("SELECT COUNT(*) FROM login_rate_limits $rlWhereClause");
    $rlCountStmt->execute($rlParams);
    $rlTotalItems = (int) $rlCountStmt->fetchColumn();
    $rlTotalPages = ($rlEffectivePerPage === null || $rlTotalItems === 0) ? 1 : (int) ceil($rlTotalItems / $rlEffectivePerPage);
    if ($rlPage > $rlTotalPages) $rlPage = $rlTotalPages;
    $rlOffset = $rlEffectivePerPage === null ? 0 : ($rlPage - 1) * $rlEffectivePerPage;
    $rlRecordsStart = $rlTotalItems === 0 ? 0 : ($rlOffset + 1);
    $rlRecordsEnd = $rlEffectivePerPage === null ? $rlTotalItems : (int) min($rlOffset + $rlEffectivePerPage, $rlTotalItems);

    $rlQueryBase = "
        SELECT *
        FROM login_rate_limits
        $rlWhereClause
        ORDER BY
            (locked_until IS NOT NULL AND locked_until > NOW()) DESC,
            locked_until DESC,
            last_fail_at DESC,
            updated_at DESC
    ";
    $rlQuery = $rlEffectivePerPage === null
        ? $rlQueryBase
        : ($rlQueryBase . " LIMIT " . (int) $rlEffectivePerPage . " OFFSET " . (int) $rlOffset);
    $rlStmt = $pdo->prepare($rlQuery);
    $rlStmt->execute($rlParams);
    $rateLimits = $rlStmt->fetchAll();

    // Certificates list (latest first) with filters + pagination
    $certs = [];
    $certTotalItems = 0;
    $certTotalPages = 1;
    $certOffset = 0;
    $certRecordsStart = 0;
    $certRecordsEnd = 0;
    $certSearchColumns = ['id', 'title', 'target_type', 'target_value', 'is_active', 'created_by', 'updated_by', 'deleted_by', 'signed_by'];

    $certWhere = [];
    $certParams = [];
    if ($certView === 'active') {
        $certWhere[] = "deleted_at IS NULL";
        $certWhere[] = "is_active = 'yes'";
    } elseif ($certView === 'inactive') {
        $certWhere[] = "deleted_at IS NULL";
        $certWhere[] = "is_active = 'no'";
    } elseif ($certView === 'trashed') {
        $certWhere[] = "deleted_at IS NOT NULL";
    } else {
        $certView = 'all';
        // no where
    }
    if (!empty($certSearch)) {
        $certOr = [];
        foreach ($certSearchColumns as $col) {
            $certOr[] = "CAST($col AS CHAR) LIKE ?";
            $certParams[] = '%' . $certSearch . '%';
        }
        $certWhere[] = "(" . implode(" OR ", $certOr) . ")";
    }
    $certWhereClause = !empty($certWhere) ? ('WHERE ' . implode(' AND ', $certWhere)) : '';
    // Re-map unaliased columns to "c." since certificates query uses alias c
    if ($certWhereClause !== '') {
        $certWhereClause = preg_replace('/\b(id|title|target_type|target_value|is_active|created_by|updated_by|deleted_by|deleted_at)\b/', 'c.$1', $certWhereClause);
    }

    try {
        $certCountStmt = $pdo->prepare("SELECT COUNT(*) FROM security_certificates $certWhereClause");
        $certCountStmt->execute($certParams);
        $certTotalItems = (int) $certCountStmt->fetchColumn();
        $certTotalPages = ($certEffectivePerPage === null || $certTotalItems === 0) ? 1 : (int) ceil($certTotalItems / $certEffectivePerPage);
        if ($certPage > $certTotalPages) $certPage = $certTotalPages;
        $certOffset = $certEffectivePerPage === null ? 0 : ($certPage - 1) * $certEffectivePerPage;
        $certRecordsStart = $certTotalItems === 0 ? 0 : ($certOffset + 1);
        $certRecordsEnd = $certEffectivePerPage === null ? $certTotalItems : (int) min($certOffset + $certEffectivePerPage, $certTotalItems);

        $certQueryBase = "
            SELECT
                c.*,
                COALESCE(a.signed_by, 0) AS signed_by
            FROM security_certificates c
            LEFT JOIN (
                SELECT certificate_id, COUNT(*) AS signed_by
                FROM security_certificate_acceptances
                GROUP BY certificate_id
            ) a ON a.certificate_id = c.id
            $certWhereClause
            ORDER BY c.created_at DESC
        ";
        $certQuery = $certEffectivePerPage === null
            ? $certQueryBase
            : ($certQueryBase . " LIMIT " . (int) $certEffectivePerPage . " OFFSET " . (int) $certOffset);
        $certStmt = $pdo->prepare($certQuery);
        $certStmt->execute($certParams);
        $certs = $certStmt->fetchAll();
    } catch (Exception $e) {
        $certs = [];
        $certTotalItems = 0;
        $certTotalPages = 1;
        $certRecordsStart = 0;
        $certRecordsEnd = 0;
    }

} catch (Exception $e) {
    error_log("Security Logs Error: " . $e->getMessage());
    $errorMessage = "Database error: " . $e->getMessage();
    $logs = [];
    $eventTypes = [];
    $stats = ['total' => 0, 'today' => 0, 'unique_users' => 0, 'unique_ips' => 0];
    $totalPages = 0;
    $totalItems = 0;
    $perPageParam = (string) DEFAULT_PER_PAGE;
    $effectivePerPage = (int) DEFAULT_PER_PAGE;
    $offset = 0;
    $recordsStart = 0;
    $recordsEnd = 0;
    $rateLimits = [];
    $certs = [];
    $logsMetricsLabels = [];
    $logsMetricsCounts = [];
    $logsMetricsTopLabels = [];
    $logsMetricsTopCounts = [];
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
        'certificate_required' => '#f97316',
        'certificate_accepted' => '#10b981',
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
    <title>ScanQuotient | Site Security</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
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

        /* When a filters section is used inside a results table container,
           remove the "box-in-a-box" look and keep spacing tidy. */
        .sq-table-container > .sq-filters-section {
            background: transparent;
            border: none;
            box-shadow: none;
            border-radius: 0;
            padding: 16px 16px 12px;
            margin-bottom: 0;
            border-bottom: 1px solid var(--sq-border);
        }

        .sq-metrics-card{
            background: var(--sq-bg-card);
            border: 1px solid var(--sq-border);
            border-radius: 16px;
            box-shadow: var(--sq-shadow);
            padding: 18px;
            margin: 18px 0 22px;
        }
        .sq-metrics-head{
            display:flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 10px;
        }
        .sq-metrics-title{
            font-weight: 900;
            letter-spacing: 0.2px;
            display:flex;
            align-items:center;
            gap: 10px;
        }
        .sq-metrics-sub{
            color: var(--sq-text-light);
            font-size: 12px;
            font-weight: 700;
            margin-top: 4px;
        }
        .sq-metrics-grid{
            display:grid;
            grid-template-columns: 2fr 1fr;
            gap: 16px;
            align-items: stretch;
        }
        @media (max-width: 920px){
            .sq-metrics-grid{ grid-template-columns: 1fr; }
        }
        .sq-metrics-canvas-wrap{ height: 280px; }

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

        /* Tabs layout */
        .sq-tabs-layout {
            display: grid;
            grid-template-columns: 260px 1fr;
            gap: 18px;
            align-items: start;
            margin-top: 12px;
        }

        @media (max-width: 980px) {
            .sq-tabs-layout {
                grid-template-columns: 1fr;
            }
        }

        .sq-tab-nav {
            position: sticky;
            top: 92px;
            background: var(--sq-bg-card);
            border: 1px solid var(--sq-border);
            border-radius: 16px;
            box-shadow: var(--sq-shadow);
            padding: 12px;
        }

        .sq-tab-btn {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 12px;
            margin: 6px 0;
            border: 1px solid transparent;
            background: transparent;
            color: var(--sq-text-main);
            border-radius: 12px;
            cursor: pointer;
            font-weight: 800;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            transition: all 0.2s ease;
        }

        .sq-tab-btn i {
            width: 18px;
            text-align: center;
            color: var(--sq-brand);
        }

        body.sq-dark .sq-tab-btn i {
            color: var(--sq-brand-light);
        }

        .sq-tab-btn:hover {
            background: rgba(59, 130, 246, 0.08);
            border-color: rgba(59, 130, 246, 0.18);
        }

        .sq-tab-btn--active {
            background: rgba(59, 130, 246, 0.12);
            border-color: rgba(59, 130, 246, 0.26);
        }

        body.sq-dark .sq-tab-btn:hover {
            background: rgba(139, 92, 246, 0.14);
            border-color: rgba(139, 92, 246, 0.22);
        }

        body.sq-dark .sq-tab-btn--active {
            background: rgba(139, 92, 246, 0.18);
            border-color: rgba(139, 92, 246, 0.26);
        }

        .sq-tab-panel {
            display: none;
            animation: sq-fade-in 0.18s ease;
        }

        .sq-tab-panel--active {
            display: block;
        }

        @keyframes sq-fade-in {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .sq-form-card {
            background: var(--sq-bg-card);
            border: 1px solid var(--sq-border);
            border-radius: 16px;
            padding: 18px;
            box-shadow: var(--sq-shadow);
            margin-bottom: 18px;
        }

        /* Certificate edit modal */
        .sq-cert-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.70);
            backdrop-filter: blur(8px);
            z-index: 2100;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .sq-cert-modal--active {
            display: flex;
        }

        .sq-cert-card {
            width: 100%;
            max-width: 780px;
            background: var(--sq-bg-card);
            border: 1px solid var(--sq-border);
            border-radius: 22px;
            box-shadow: var(--sq-shadow-lg);
            overflow: hidden;
        }

        .sq-cert-card-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--sq-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .sq-cert-card-title {
            font-size: 18px;
            font-weight: 900;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sq-cert-card-body {
            padding: 18px 20px;
        }

        .sq-cert-card-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--sq-border);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }

        .sq-cert-close {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.22);
            background: var(--sq-bg-main);
            color: var(--sq-text-light);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .sq-cert-close:hover {
            background: var(--sq-danger);
            color: #fff;
            border-color: rgba(239, 68, 68, 0.35);
        }

        .sq-form-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        @media (max-width: 860px) {
            .sq-form-row {
                grid-template-columns: 1fr;
            }
        }

        .sq-help-text {
            color: var(--sq-text-light);
            font-size: 13px;
            margin-top: 6px;
        }

        /* Confirm modal (replace browser confirm) */
        .sq-confirm-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            backdrop-filter: blur(8px);
            /* Keep above other modals (e.g., certificate edit modal) */
            z-index: 3000;
            align-items: center;
            justify-content: center;
            padding: 18px;
        }

        .sq-confirm-modal--active {
            display: flex;
        }

        .sq-confirm-card {
            width: 100%;
            max-width: 520px;
            background: var(--sq-bg-card);
            border: 1px solid var(--sq-border);
            border-radius: 20px;
            box-shadow: var(--sq-shadow-lg);
            overflow: hidden;
            animation: sq-modal-bounce 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .sq-confirm-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--sq-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .sq-confirm-title {
            font-size: 16px;
            font-weight: 900;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sq-confirm-title i {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(245, 158, 11, 0.12);
            color: var(--sq-warning);
        }

        .sq-confirm-close {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.25);
            background: var(--sq-bg-main);
            color: var(--sq-text-light);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .sq-confirm-close:hover {
            color: white;
            background: var(--sq-danger);
            border-color: rgba(239, 68, 68, 0.3);
        }

        .sq-confirm-body {
            padding: 18px 20px;
            color: var(--sq-text-main);
            line-height: 1.65;
        }

        .sq-confirm-body p {
            margin: 0;
            color: var(--sq-text-light);
            font-size: 14px;
        }

        .sq-confirm-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--sq-border);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }

        .sq-btn--compact {
            padding: 10px 14px;
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
            <img src="<?php echo htmlspecialchars($avatar_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile" class="header-profile-photo">
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

    <!-- Confirm Modal -->
    <div class="sq-confirm-modal" id="sqConfirmModal" aria-hidden="true">
        <div class="sq-confirm-card" role="dialog" aria-modal="true" aria-labelledby="sqConfirmTitle">
            <div class="sq-confirm-header">
                <div class="sq-confirm-title" id="sqConfirmTitle">
                    <i class="fas fa-triangle-exclamation"></i>
                    Confirm action
                </div>
                <button type="button" class="sq-confirm-close" id="sqConfirmClose" aria-label="Close confirmation">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="sq-confirm-body">
                <p id="sqConfirmMessage">Are you sure you want to continue?</p>
            </div>
            <div class="sq-confirm-footer">
                <button type="button" class="sq-btn sq-btn-secondary sq-btn--compact" id="sqConfirmCancel">
                    <i class="fas fa-xmark"></i> Cancel
                </button>
                <button type="button" class="sq-btn sq-btn-warning sq-btn--compact" id="sqConfirmOk">
                    <i class="fas fa-check"></i> Confirm
                </button>
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

        <div class="sq-tabs-layout">
            <aside class="sq-tab-nav" aria-label="Site security tabs">
                <button type="button" class="sq-tab-btn sq-tab-btn--active" data-tab="lockouts">
                    <i class="fas fa-user-lock"></i>
                    Login lockouts
                </button>
                <button type="button" class="sq-tab-btn" data-tab="logs">
                    <i class="fas fa-clipboard-list"></i>
                    Security logs
                </button>
                <button type="button" class="sq-tab-btn" data-tab="policy-2fa">
                    <i class="fas fa-user-shield"></i>
                    2FA policy
                </button>
                <button type="button" class="sq-tab-btn" data-tab="policy-reset">
                    <i class="fas fa-key"></i>
                    Password reset
                </button>
                <button type="button" class="sq-tab-btn" data-tab="certificates">
                    <i class="fas fa-file-signature"></i>
                    New certificate
                </button>
                <div class="sq-help-text" style="padding: 10px 6px 4px;">
                    Use these tabs to manage site-wide security controls.
                </div>
            </aside>

            <div>
                <!-- Statistics (applies to logs overall) -->
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

                <div class="sq-tab-panel sq-tab-panel--active" data-tab-panel="lockouts">
                    <!-- Login Rate Limits -->
                    <div style="margin-top: 10px; margin-bottom: 22px;">
            <div class="sq-section-title">
                <i class="fas fa-user-lock" style="color: var(--sq-accent);"></i>
                Login lockouts
            </div>
            <div class="sq-section-subtitle">
                Manage username/IP lockouts created by the login handler. Use carefully—locking an IP can block many
                users behind the same network.
            </div>

            <div class="sq-rate-controls">
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
                        <button type="submit" class="sq-btn sq-btn-warning" data-sq-confirm="Apply this lockout? This may prevent users from logging in.">
                            <i class="fas fa-lock"></i> Apply lock
                        </button>
                    </div>
                </form>
            </div>

            <div class="sq-table-container">
                <div class="sq-filters-section" style="margin-top: 14px;">
                    <form method="GET" class="sq-filters-grid" style="grid-template-columns: repeat(4, minmax(0, 1fr));">
                        <div class="sq-filter-group">
                            <label class="sq-filter-label">View</label>
                            <select name="rl_view" class="sq-filter-select">
                                <option value="active" <?php echo ($rlView ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="trashed" <?php echo ($rlView ?? '') === 'trashed' ? 'selected' : ''; ?>>Trashed</option>
                                <option value="all" <?php echo ($rlView ?? '') === 'all' ? 'selected' : ''; ?>>All</option>
                            </select>
                        </div>
                        <div class="sq-filter-group">
                            <label class="sq-filter-label">Scope</label>
                            <select name="rl_scope" class="sq-filter-select">
                                <option value="all" <?php echo ($rlScope ?? 'all') === 'all' ? 'selected' : ''; ?>>All</option>
                                <option value="user" <?php echo ($rlScope ?? '') === 'user' ? 'selected' : ''; ?>>Usernames</option>
                                <option value="ip" <?php echo ($rlScope ?? '') === 'ip' ? 'selected' : ''; ?>>IP addresses</option>
                            </select>
                        </div>

                        <div class="sq-filter-group">
                            <label class="sq-filter-label">Status</label>
                            <select name="rl_status" class="sq-filter-select">
                                <option value="all" <?php echo ($rlStatus ?? 'all') === 'all' ? 'selected' : ''; ?>>All</option>
                                <option value="locked" <?php echo ($rlStatus ?? '') === 'locked' ? 'selected' : ''; ?>>Locked</option>
                                <option value="unlocked" <?php echo ($rlStatus ?? '') === 'unlocked' ? 'selected' : ''; ?>>Unlocked</option>
                            </select>
                        </div>

                        <div class="sq-filter-group">
                            <label class="sq-filter-label">Search key</label>
                            <input type="text" name="rl_search" class="sq-filter-input"
                                placeholder="e.g. admin or 192.168.1.10"
                                value="<?php echo htmlspecialchars($rlSearch ?? ''); ?>">
                        </div>

                        <div class="sq-filter-group">
                            <label class="sq-filter-label">Records</label>
                            <select name="rl_per_page" class="sq-filter-select">
                                <?php
                                $perPageOptions = ['5', '10', '20', '50', '100', '200', 'all'];
                                foreach ($perPageOptions as $opt):
                                    $selected = ((string) ($rlPerPageParam ?? (string) DEFAULT_PER_PAGE) === (string) $opt) ? 'selected' : '';
                                    ?>
                                    <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $selected; ?>>
                                        <?php echo $opt === 'all' ? 'All' : $opt; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sq-filter-actions" style="justify-content:flex-end; grid-column: 1 / -1;">
                            <button type="submit" class="sq-btn sq-btn-primary">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <a href="?" class="sq-btn sq-btn-secondary">
                                <i class="fas fa-undo"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>

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
                                <?php if (!empty($rlSearch)): ?>
                                    <th>Matched In</th>
                                <?php endif; ?>
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
                                    <?php if (!empty($rlSearch)): ?>
                                        <td><?php echo htmlspecialchars('scope_key'); ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <div class="sq-inline-actions">
                                            <?php if (empty($rl['deleted_at'])): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="rate_action" value="unlock">
                                                    <input type="hidden" name="scope"
                                                        value="<?php echo htmlspecialchars($rl['scope']); ?>">
                                                    <input type="hidden" name="scope_key"
                                                        value="<?php echo htmlspecialchars($rl['scope_key']); ?>">
                                                    <button type="submit" class="sq-btn sq-btn-secondary"
                                                        style="padding: 8px 12px;"
                                                        data-sq-confirm="Unlock this entry? Failed-attempt counters will be cleared and login will be allowed.">
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
                                                        style="padding: 8px 12px;"
                                                        data-sq-confirm="Reset counters for this entry? Lock status will be cleared and fail counters will be reset.">
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
                                                    <button type="submit" class="sq-btn sq-btn-danger" style="padding: 8px 12px;"
                                                        data-sq-confirm="Lock this entry now? This will block login attempts for the lock duration.">
                                                        <i class="fas fa-lock"></i> Lock
                                                    </button>
                                                </form>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="rate_action" value="soft_delete">
                                                    <input type="hidden" name="scope"
                                                        value="<?php echo htmlspecialchars($rl['scope']); ?>">
                                                    <input type="hidden" name="scope_key"
                                                        value="<?php echo htmlspecialchars($rl['scope_key']); ?>">
                                                    <button type="submit" class="sq-btn sq-btn-danger" style="padding: 8px 12px;"
                                                        data-sq-confirm="Move this lockout entry to trash? This will clear the lock and counters.">
                                                        <i class="fas fa-trash"></i> Trash
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="rate_action" value="restore">
                                                    <input type="hidden" name="scope"
                                                        value="<?php echo htmlspecialchars($rl['scope']); ?>">
                                                    <input type="hidden" name="scope_key"
                                                        value="<?php echo htmlspecialchars($rl['scope_key']); ?>">
                                                    <button type="submit" class="sq-btn sq-btn-secondary" style="padding: 8px 12px;"
                                                        data-sq-confirm="Restore this lockout entry from trash?">
                                                        <i class="fas fa-undo"></i> Restore
                                                    </button>
                                                </form>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="rate_action" value="permanent_delete">
                                                    <input type="hidden" name="scope"
                                                        value="<?php echo htmlspecialchars($rl['scope']); ?>">
                                                    <input type="hidden" name="scope_key"
                                                        value="<?php echo htmlspecialchars($rl['scope_key']); ?>">
                                                    <button type="submit" class="sq-btn sq-btn-danger" style="padding: 8px 12px;"
                                                        data-sq-confirm="Permanently delete this lockout entry? This cannot be undone.">
                                                        <i class="fas fa-trash-can"></i> Delete forever
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ((int) ($rlTotalItems ?? 0) > 0): ?>
                        <div class="sq-per-page-row"
                            style="display:flex; justify-content:center; align-items:center; gap:12px; flex-wrap:wrap; padding: 10px 0 0; border-top: 1px solid var(--sq-border);">
                            <span class="sq-record-info" style="color: var(--sq-text-light); font-size: 12px;">
                                Showing <?php echo (int) ($rlRecordsStart ?? 0); ?>–<?php echo (int) ($rlRecordsEnd ?? 0); ?> of <?php echo number_format((int) ($rlTotalItems ?? 0)); ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php if ((int) ($rlTotalPages ?? 1) > 1): ?>
                        <div class="sq-pagination">
                            <?php
                            $rlQueryParams = "rl_scope=" . urlencode((string) ($rlScope ?? 'all'))
                                . "&rl_status=" . urlencode((string) ($rlStatus ?? 'all'))
                                . "&rl_search=" . urlencode((string) ($rlSearch ?? ''))
                                . "&rl_per_page=" . urlencode((string) ($rlPerPageParam ?? (string) DEFAULT_PER_PAGE));
                            ?>

                            <?php if (($rlPage ?? 1) > 1): ?>
                                <a href="?<?php echo $rlQueryParams; ?>&rl_page=<?php echo (int) $rlPage - 1; ?>" class="sq-page-btn">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="sq-page-btn disabled"><i class="fas fa-chevron-left"></i></span>
                            <?php endif; ?>

                            <?php
                            $rlStartPage = max(1, (int) ($rlPage ?? 1) - 2);
                            $rlEndPage = min((int) ($rlTotalPages ?? 1), (int) ($rlPage ?? 1) + 2);

                            if ($rlStartPage > 1): ?>
                                <a href="?<?php echo $rlQueryParams; ?>&rl_page=1" class="sq-page-btn">1</a>
                                <?php if ($rlStartPage > 2): ?>
                                    <span class="sq-page-btn disabled">...</span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($i = $rlStartPage; $i <= $rlEndPage; $i++): ?>
                                <?php if ($i == (int) ($rlPage ?? 1)): ?>
                                    <span class="sq-page-btn active"><?php echo (int) $i; ?></span>
                                <?php else: ?>
                                    <a href="?<?php echo $rlQueryParams; ?>&rl_page=<?php echo (int) $i; ?>" class="sq-page-btn"><?php echo (int) $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($rlEndPage < (int) ($rlTotalPages ?? 1)): ?>
                                <?php if ($rlEndPage < (int) ($rlTotalPages ?? 1) - 1): ?>
                                    <span class="sq-page-btn disabled">...</span>
                                <?php endif; ?>
                                <a href="?<?php echo $rlQueryParams; ?>&rl_page=<?php echo (int) ($rlTotalPages ?? 1); ?>" class="sq-page-btn">
                                    <?php echo (int) ($rlTotalPages ?? 1); ?>
                                </a>
                            <?php endif; ?>

                            <?php if (($rlPage ?? 1) < (int) ($rlTotalPages ?? 1)): ?>
                                <a href="?<?php echo $rlQueryParams; ?>&rl_page=<?php echo (int) $rlPage + 1; ?>" class="sq-page-btn">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="sq-page-btn disabled"><i class="fas fa-chevron-right"></i></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
                </div>

                <div class="sq-tab-panel" data-tab-panel="logs">
                    <div class="sq-metrics-card">
                        <div class="sq-metrics-head">
                            <div>
                                <div class="sq-metrics-title">
                                    <i class="fas fa-chart-area" style="color: var(--sq-accent);"></i>
                                    Security logs metrics
                                </div>
                                <div class="sq-metrics-sub">Last 14 days volume • Top event types (30 days)</div>
                            </div>
                        </div>
                        <div class="sq-metrics-grid">
                            <div class="sq-metrics-canvas-wrap">
                                <canvas id="sqSecurityLogsVolumeChart"></canvas>
                            </div>
                            <div class="sq-metrics-canvas-wrap">
                                <canvas id="sqSecurityLogsTopTypesChart"></canvas>
                            </div>
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
                            <?php if (!empty($search)): ?>
                                <th>Matched In</th>
                            <?php endif; ?>
                            <th width="60">View</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log):
                            $eventColor = getEventColor($log['event_type']);
                            $eventIcon = getEventIcon($log['event_type']);
                            $matchedInStr = '-';
                            if (!empty($search) && !empty($securitySearchColumns)) {
                                $matchedCols = [];
                                foreach ($securitySearchColumns as $col) {
                                    $val = $log[$col] ?? null;
                                    if ($val === null) continue;
                                    if (stripos((string) $val, $search) !== false) {
                                        $matchedCols[] = $col;
                                    }
                                }
                                if (!empty($matchedCols)) {
                                    $matchedInStr = implode(', ', $matchedCols);
                                }
                            }
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
                                <?php if (!empty($search)): ?>
                                    <td class="sq-matched-in-col"><?php echo htmlspecialchars($matchedInStr); ?></td>
                                <?php endif; ?>
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
                <?php if (!empty($totalItems) && (int) $totalItems > 0): ?>
                    <div class="sq-per-page-row"
                        style="display:flex; justify-content:center; align-items:center; gap:12px; flex-wrap:wrap; padding: 10px 0 0; border-top: 1px solid var(--sq-border, rgba(0,0,0,0.08));">
                        <label style="color: var(--text-light, #64748b); font-size: 13px; font-weight: 700;">
                            Records
                            <select id="sqPerPageSelect"
                                style="margin: 0 8px; padding: 8px 12px; border-radius: 10px; border: 1px solid rgba(0,0,0,0.1); background: rgba(255,255,255,0.85); color: var(--text-main, #1e293b);"
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
                            per page
                        </label>
                        <span style="color: var(--text-light, #64748b); font-size: 12px;">
                            Showing <?php echo (int) $recordsStart; ?>–<?php echo (int) $recordsEnd; ?> of <?php echo number_format((int) $totalItems); ?>
                        </span>
                    </div>
                <?php endif; ?>
                <?php if ($totalPages > 1): ?>
                    <div class="sq-pagination">
                        <?php
                        $queryParams = "event_type=$eventType&search=" . urlencode($search) . "&date_from=$dateFrom&date_to=$dateTo&per_page=" . urlencode((string) $perPageParam);
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
                </div>

                <div class="sq-tab-panel" data-tab-panel="policy-2fa">
                    <div class="sq-form-card">
                        <div class="sq-section-title">
                            <i class="fas fa-user-shield" style="color: var(--sq-accent);"></i>
                            2FA policy
                        </div>
                        <div class="sq-section-subtitle">
                            Apply 2FA settings in bulk. This will affect accounts in the selected target group.
                        </div>

                        <form method="POST">
                            <input type="hidden" name="action" value="apply_2fa_policy">
                            <div class="sq-form-row">
                                <div class="sq-filter-group">
                                    <label class="sq-filter-label">Target</label>
                                    <select name="target" class="sq-filter-select" required>
                                        <option value="everyone">Everyone</option>
                                        <option value="admins">Admins</option>
                                        <option value="users">Normal users (user)</option>
                                    </select>
                                </div>

                                <div class="sq-filter-group">
                                    <label class="sq-filter-label">Force 2FA</label>
                                    <select name="two_factor_enabled" class="sq-filter-select" required>
                                        <option value="yes">Enable</option>
                                        <option value="no" selected>Disable</option>
                                    </select>
                                </div>

                                <div class="sq-filter-group" style="display:flex; justify-content:flex-end; align-items:end;">
                                    <button type="submit" class="sq-btn sq-btn-primary" data-sq-confirm="Apply this 2FA policy to the selected target?">
                                        <i class="fas fa-bolt"></i> Apply policy
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="sq-tab-panel" data-tab-panel="policy-reset">
                    <div class="sq-form-card">
                        <div class="sq-section-title">
                            <i class="fas fa-key" style="color: var(--sq-accent);"></i>
                            Password reset
                        </div>
                        <div class="sq-section-subtitle">
                            Apply password reset requirements in bulk. This will affect accounts in the selected target group.
                        </div>

                        <form method="POST">
                            <input type="hidden" name="action" value="apply_password_reset_policy">
                            <div class="sq-form-row">
                                <div class="sq-filter-group">
                                    <label class="sq-filter-label">Target</label>
                                    <select name="target" class="sq-filter-select" required>
                                        <option value="everyone">Everyone</option>
                                        <option value="admins">Admins</option>
                                        <option value="users">Normal users (user)</option>
                                    </select>
                                </div>

                                <div class="sq-filter-group">
                                    <label class="sq-filter-label">Require password reset</label>
                                    <select name="password_reset_required" class="sq-filter-select" required>
                                        <option value="yes">Yes (force on next login)</option>
                                        <option value="no" selected>No</option>
                                    </select>
                                    <div class="sq-help-text">If enabled, users will be forced to reset within 7 days.</div>
                                </div>

                                <div class="sq-filter-group" style="display:flex; justify-content:flex-end; align-items:end;">
                                    <button type="submit" class="sq-btn sq-btn-primary" data-sq-confirm="Apply this password reset policy to the selected target?">
                                        <i class="fas fa-bolt"></i> Apply policy
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="sq-tab-panel" data-tab-panel="certificates">
                    <div class="sq-form-card">
                        <div class="sq-section-title">
                            <i class="fas fa-file-signature" style="color: var(--sq-accent);"></i>
                            New certificate
                        </div>
                        <div class="sq-section-subtitle">
                            Create a new certificate/acknowledgement that users must agree to during login before they can access their dashboard.
                        </div>

                        <form method="POST">
                            <input type="hidden" name="action" value="create_certificate">
                            <div class="sq-form-row">
                                <div class="sq-filter-group" style="grid-column: 1 / -1;">
                                    <label class="sq-filter-label">Certificate title</label>
                                    <input type="text" name="title" class="sq-filter-input" placeholder="e.g. 2026 Acceptable Use Certificate" required>
                                </div>

                                <div class="sq-filter-group" style="grid-column: 1 / -1;">
                                    <label class="sq-filter-label">Certificate details</label>
                                    <textarea name="body" class="sq-filter-input" rows="8" placeholder="Enter the certificate text users must agree to..." required></textarea>
                                </div>

                                <div class="sq-filter-group">
                                    <label class="sq-filter-label">Applies to</label>
                                    <select name="target_type" class="sq-filter-select" id="sqCertTargetType" onchange="sqToggleCertTargetValue()" required>
                                        <option value="everyone">Everyone</option>
                                        <option value="role">Role (user/admin)</option>
                                        <option value="user_id">Specific user id</option>
                                        <option value="username">Specific username</option>
                                    </select>
                                </div>

                                <div class="sq-filter-group">
                                    <label class="sq-filter-label">Target value</label>
                                    <input type="text" name="target_value" class="sq-filter-input" id="sqCertTargetValue" placeholder="e.g. user or UIDWRB3O1P or johndoe" disabled>
                                    <div class="sq-help-text">Only required for role / specific user targeting.</div>
                                </div>

                                <div class="sq-filter-group">
                                    <label class="sq-filter-label">Active</label>
                                    <select name="is_active" class="sq-filter-select" required>
                                        <option value="yes" selected>Yes</option>
                                        <option value="no">No</option>
                                    </select>
                                </div>

                                <div class="sq-filter-group" style="display:flex; justify-content:flex-end; align-items:end;">
                                    <button type="submit" class="sq-btn sq-btn-primary" data-sq-confirm="Create this certificate? Users matching the target will be forced to accept it during login.">
                                        <i class="fas fa-plus"></i> Create certificate
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="sq-filters-section" style="margin-top: 14px;">
                        <form method="GET" class="sq-filters-grid" style="grid-template-columns: repeat(4, minmax(0, 1fr));">
                            <div class="sq-filter-group">
                                <label class="sq-filter-label">View</label>
                                <select name="cert_view" class="sq-filter-select">
                                    <option value="all" <?php echo ($certView ?? 'all') === 'all' ? 'selected' : ''; ?>>All</option>
                                    <option value="active" <?php echo ($certView ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($certView ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="trashed" <?php echo ($certView ?? '') === 'trashed' ? 'selected' : ''; ?>>Trashed</option>
                                </select>
                            </div>
                            <div class="sq-filter-group">
                                <label class="sq-filter-label">Search</label>
                                <input type="text" name="cert_search" class="sq-filter-input" placeholder="Title, target, id..."
                                    value="<?php echo htmlspecialchars($certSearch ?? ''); ?>">
                            </div>
                            <div class="sq-filter-group">
                                <label class="sq-filter-label">Records</label>
                                <select name="cert_per_page" class="sq-filter-select">
                                    <?php
                                    $perPageOptions = ['5', '10', '20', '50', '100', '200', 'all'];
                                    foreach ($perPageOptions as $opt):
                                        $selected = ((string) ($certPerPageParam ?? (string) DEFAULT_PER_PAGE) === (string) $opt) ? 'selected' : '';
                                        ?>
                                        <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $selected; ?>>
                                            <?php echo $opt === 'all' ? 'All' : $opt; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sq-filter-actions" style="justify-content:flex-end;">
                                <button type="submit" class="sq-btn sq-btn-primary">
                                    <i class="fas fa-search"></i> Search
                                </button>
                                <a href="?" class="sq-btn sq-btn-secondary">
                                    <i class="fas fa-undo"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>

                    <div class="sq-table-container">
                        <?php if (empty($certs)): ?>
                            <div class="sq-empty-state">
                                <div class="sq-empty-icon"><i class="fas fa-file-circle-plus"></i></div>
                                <h3 class="sq-empty-title">No certificates yet</h3>
                                <p class="sq-empty-text">Create your first certificate above.</p>
                            </div>
                        <?php else: ?>
                            <table class="sq-data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Title</th>
                                        <th>Target</th>
                                        <th>Active</th>
                                        <th>Status</th>
                                        <th>Signed by</th>
                                        <th>Created</th>
                                        <?php if (!empty($certSearch)): ?>
                                            <th>Matched In</th>
                                        <?php endif; ?>
                                        <th width="260">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($certs as $c):
                                        $certMatchedIn = '-';
                                        if (!empty($certSearch)) {
                                            $matched = [];
                                            foreach ($certSearchColumns as $col) {
                                                $val = $c[$col] ?? null;
                                                if ($val === null) continue;
                                                if (stripos((string) $val, (string) $certSearch) !== false) $matched[] = $col;
                                            }
                                            if (!empty($matched)) $certMatchedIn = implode(', ', $matched);
                                        }
                                        ?>
                                        <tr>
                                            <td>#<?php echo (int)$c['id']; ?></td>
                                            <td><?php echo htmlspecialchars($c['title']); ?></td>
                                            <td>
                                                <span class="sq-badge">
                                                    <i class="fas fa-bullseye"></i>
                                                    <?php echo htmlspecialchars($c['target_type']); ?>
                                                    <?php echo !empty($c['target_value']) ? (': ' . htmlspecialchars($c['target_value'])) : ''; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="sq-badge <?php echo ($c['is_active'] === 'yes') ? 'sq-badge--active' : ''; ?>">
                                                    <i class="fas <?php echo ($c['is_active'] === 'yes') ? 'fa-check' : 'fa-pause'; ?>"></i>
                                                    <?php echo htmlspecialchars(strtoupper($c['is_active'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php $isDeleted = !empty($c['deleted_at']); ?>
                                                <span class="sq-badge <?php echo $isDeleted ? 'sq-badge--locked' : 'sq-badge--active'; ?>">
                                                    <i class="fas <?php echo $isDeleted ? 'fa-trash' : 'fa-circle-check'; ?>"></i>
                                                    <?php echo $isDeleted ? 'TRASHED' : 'OK'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="sq-badge">
                                                    <i class="fas fa-signature"></i>
                                                    <?php echo (int) ($c['signed_by'] ?? 0); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo !empty($c['created_at']) ? date('M j, Y g:i A', strtotime($c['created_at'])) : '-'; ?>
                                            </td>
                                            <?php if (!empty($certSearch)): ?>
                                                <td><?php echo htmlspecialchars($certMatchedIn); ?></td>
                                            <?php endif; ?>
                                            <td>
                                                <div class="sq-inline-actions">
                                                    <?php if (empty($c['deleted_at'])): ?>
                                                        <button type="button"
                                                            class="sq-btn sq-btn-secondary"
                                                            style="padding: 8px 12px;"
                                                            onclick="sqOpenEditCert(<?php echo htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8'); ?>)">
                                                            <i class="fas fa-pen"></i> Edit
                                                        </button>

                                                        <form method="POST" style="display:inline;">
                                                            <input type="hidden" name="action" value="toggle_certificate_active">
                                                            <input type="hidden" name="certificate_id" value="<?php echo (int)$c['id']; ?>">
                                                            <input type="hidden" name="next_active" value="<?php echo ($c['is_active'] === 'yes') ? 'no' : 'yes'; ?>">
                                                            <button type="submit"
                                                                class="sq-btn <?php echo ($c['is_active'] === 'yes') ? 'sq-btn-secondary' : 'sq-btn-warning'; ?>"
                                                                style="padding: 8px 12px;"
                                                                data-sq-confirm="<?php echo ($c['is_active'] === 'yes') ? 'Mark this certificate as not active?' : 'Mark this certificate as active?'; ?>">
                                                                <i class="fas <?php echo ($c['is_active'] === 'yes') ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                                                                <?php echo ($c['is_active'] === 'yes') ? 'Deactivate' : 'Activate'; ?>
                                                            </button>
                                                        </form>

                                                        <form method="POST" style="display:inline;">
                                                            <input type="hidden" name="action" value="soft_delete_certificate">
                                                            <input type="hidden" name="certificate_id" value="<?php echo (int)$c['id']; ?>">
                                                            <button type="submit" class="sq-btn sq-btn-danger" style="padding: 8px 12px;"
                                                                data-sq-confirm="Move this certificate to trash? Users may stop being forced to sign it if inactive.">
                                                                <i class="fas fa-trash"></i> Trash
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="POST" style="display:inline;">
                                                            <input type="hidden" name="action" value="restore_certificate">
                                                            <input type="hidden" name="certificate_id" value="<?php echo (int)$c['id']; ?>">
                                                            <button type="submit" class="sq-btn sq-btn-secondary" style="padding: 8px 12px;"
                                                                data-sq-confirm="Restore this certificate from trash?">
                                                                <i class="fas fa-undo"></i> Restore
                                                            </button>
                                                        </form>
                                                        <form method="POST" style="display:inline;">
                                                            <input type="hidden" name="action" value="delete_certificate_forever">
                                                            <input type="hidden" name="certificate_id" value="<?php echo (int)$c['id']; ?>">
                                                            <button type="submit" class="sq-btn sq-btn-danger" style="padding: 8px 12px;"
                                                                data-sq-confirm="Permanently delete this certificate? This cannot be undone.">
                                                                <i class="fas fa-trash-can"></i> Delete forever
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <?php if ((int) ($certTotalItems ?? 0) > 0): ?>
                                <div class="sq-per-page-row"
                                    style="display:flex; justify-content:center; align-items:center; gap:12px; flex-wrap:wrap; padding: 10px 0 0; border-top: 1px solid var(--sq-border);">
                                    <span class="sq-record-info" style="color: var(--sq-text-light); font-size: 12px;">
                                        Showing <?php echo (int) ($certRecordsStart ?? 0); ?>–<?php echo (int) ($certRecordsEnd ?? 0); ?> of <?php echo number_format((int) ($certTotalItems ?? 0)); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if ((int) ($certTotalPages ?? 1) > 1): ?>
                                <div class="sq-pagination">
                                    <?php
                                    $certQueryParams = "cert_view=" . urlencode((string) ($certView ?? 'all'))
                                        . "&cert_search=" . urlencode((string) ($certSearch ?? ''))
                                        . "&cert_per_page=" . urlencode((string) ($certPerPageParam ?? (string) DEFAULT_PER_PAGE));
                                    ?>

                                    <?php if (($certPage ?? 1) > 1): ?>
                                        <a href="?<?php echo $certQueryParams; ?>&cert_page=<?php echo (int) $certPage - 1; ?>" class="sq-page-btn">
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="sq-page-btn disabled"><i class="fas fa-chevron-left"></i></span>
                                    <?php endif; ?>

                                    <?php
                                    $certStartPage = max(1, (int) ($certPage ?? 1) - 2);
                                    $certEndPage = min((int) ($certTotalPages ?? 1), (int) ($certPage ?? 1) + 2);
                                    if ($certStartPage > 1): ?>
                                        <a href="?<?php echo $certQueryParams; ?>&cert_page=1" class="sq-page-btn">1</a>
                                        <?php if ($certStartPage > 2): ?>
                                            <span class="sq-page-btn disabled">...</span>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php for ($i = $certStartPage; $i <= $certEndPage; $i++): ?>
                                        <?php if ($i == (int) ($certPage ?? 1)): ?>
                                            <span class="sq-page-btn active"><?php echo (int) $i; ?></span>
                                        <?php else: ?>
                                            <a href="?<?php echo $certQueryParams; ?>&cert_page=<?php echo (int) $i; ?>" class="sq-page-btn"><?php echo (int) $i; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>

                                    <?php if ($certEndPage < (int) ($certTotalPages ?? 1)): ?>
                                        <?php if ($certEndPage < (int) ($certTotalPages ?? 1) - 1): ?>
                                            <span class="sq-page-btn disabled">...</span>
                                        <?php endif; ?>
                                        <a href="?<?php echo $certQueryParams; ?>&cert_page=<?php echo (int) ($certTotalPages ?? 1); ?>" class="sq-page-btn">
                                            <?php echo (int) ($certTotalPages ?? 1); ?>
                                        </a>
                                    <?php endif; ?>

                                    <?php if (($certPage ?? 1) < (int) ($certTotalPages ?? 1)): ?>
                                        <a href="?<?php echo $certQueryParams; ?>&cert_page=<?php echo (int) $certPage + 1; ?>" class="sq-page-btn">
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="sq-page-btn disabled"><i class="fas fa-chevron-right"></i></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

        <!-- Certificate Edit Modal -->
        <div class="sq-cert-modal" id="sqCertEditModal" aria-hidden="true">
            <div class="sq-cert-card" role="dialog" aria-modal="true" aria-labelledby="sqCertEditTitle">
                <div class="sq-cert-card-header">
                    <div class="sq-cert-card-title" id="sqCertEditTitle">
                        <i class="fas fa-pen" style="color: var(--sq-accent);"></i>
                        Edit certificate
                    </div>
                    <button type="button" class="sq-cert-close" onclick="sqCloseEditCert()" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form method="POST" id="sqCertEditForm">
                    <input type="hidden" name="action" value="update_certificate">
                    <input type="hidden" name="certificate_id" id="sqEditCertId" value="">
                    <div class="sq-cert-card-body">
                        <div class="sq-form-row">
                            <div class="sq-filter-group" style="grid-column: 1 / -1;">
                                <label class="sq-filter-label">Certificate title</label>
                                <input type="text" name="title" class="sq-filter-input" id="sqEditCertTitle" required>
                            </div>

                            <div class="sq-filter-group" style="grid-column: 1 / -1;">
                                <label class="sq-filter-label">Certificate details</label>
                                <textarea name="body" class="sq-filter-input" rows="10" id="sqEditCertBody" required></textarea>
                                <div class="sq-help-text">Editing a certificate will require all users to sign it again.</div>
                            </div>

                            <div class="sq-filter-group">
                                <label class="sq-filter-label">Applies to</label>
                                <select name="target_type" class="sq-filter-select" id="sqEditCertTargetType" onchange="sqToggleEditCertTargetValue()" required>
                                    <option value="everyone">Everyone</option>
                                    <option value="role">Role (user/admin)</option>
                                    <option value="user_id">Specific user id</option>
                                    <option value="username">Specific username</option>
                                </select>
                            </div>

                            <div class="sq-filter-group">
                                <label class="sq-filter-label">Target value</label>
                                <input type="text" name="target_value" class="sq-filter-input" id="sqEditCertTargetValue" disabled>
                            </div>

                            <div class="sq-filter-group">
                                <label class="sq-filter-label">Active</label>
                                <select name="is_active" class="sq-filter-select" id="sqEditCertActive" required>
                                    <option value="yes">Yes</option>
                                    <option value="no">No</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="sq-cert-card-footer">
                        <button type="button" class="sq-btn sq-btn-secondary" onclick="sqCloseEditCert()">
                            <i class="fas fa-xmark"></i> Cancel
                        </button>
                        <button type="submit" class="sq-btn sq-btn-warning"
                            data-sq-confirm="Save changes to this certificate? Everyone who signed will be required to sign again.">
                            <i class="fas fa-save"></i> Save changes
                        </button>
                    </div>
                </form>
            </div>
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

    <button id="backToTopBtn" class="sq-back-to-top" title="Back to top" aria-label="Back to top" type="button">
        <i class="fas fa-arrow-up"></i>
    </button>

    <style>
        .sq-back-to-top{
            position: fixed;
            right: 24px;
            bottom: 24px;
            width: 44px;
            height: 44px;
            border-radius: 999px;
            border: none;
            background: var(--sq-success, #10b981);
            color: white;
            box-shadow: 0 10px 24px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
            z-index: 1000;
        }
        body.sq-dark .sq-back-to-top{
            background: #059669;
            box-shadow: 0 10px 24px rgba(0,0,0,0.4);
        }
        .sq-back-to-top.sq-back-to-top--visible{
            opacity: 1;
            pointer-events: auto;
            transform: translateY(-2px);
        }
    </style>

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

        // Tabs
        (function () {
            const tabButtons = Array.from(document.querySelectorAll('.sq-tab-btn'));
            const panels = Array.from(document.querySelectorAll('[data-tab-panel]'));
            const setTab = (tab) => {
                tabButtons.forEach((b) => b.classList.toggle('sq-tab-btn--active', b.dataset.tab === tab));
                panels.forEach((p) => p.classList.toggle('sq-tab-panel--active', p.dataset.tabPanel === tab));
                try { localStorage.setItem('sq-site-security-tab', tab); } catch (e) {}
            };
            tabButtons.forEach((b) => b.addEventListener('click', () => setTab(b.dataset.tab)));
            const initial = (location.hash || '').replace('#', '') || (localStorage.getItem('sq-site-security-tab') || 'lockouts');
            if (tabButtons.some((b) => b.dataset.tab === initial)) setTab(initial);
        })();

        // Styled confirmations (replaces window.confirm)
        (function () {
            const modal = document.getElementById('sqConfirmModal');
            const msg = document.getElementById('sqConfirmMessage');
            const closeBtn = document.getElementById('sqConfirmClose');
            const cancelBtn = document.getElementById('sqConfirmCancel');
            const okBtn = document.getElementById('sqConfirmOk');
            if (!modal || !msg || !closeBtn || !cancelBtn || !okBtn) return;

            let pendingSubmit = null;

            const open = (message, onConfirm) => {
                msg.textContent = message || 'Are you sure you want to continue?';
                pendingSubmit = onConfirm || null;
                modal.classList.add('sq-confirm-modal--active');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            };
            const close = () => {
                modal.classList.remove('sq-confirm-modal--active');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
                pendingSubmit = null;
            };

            const confirmAndRun = () => {
                const fn = pendingSubmit;
                close();
                if (typeof fn === 'function') fn();
            };

            closeBtn.addEventListener('click', close);
            cancelBtn.addEventListener('click', close);
            okBtn.addEventListener('click', confirmAndRun);

            modal.addEventListener('click', (e) => {
                if (e.target === modal) close();
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && modal.classList.contains('sq-confirm-modal--active')) close();
            });

            // Intercept submits where a button has data-sq-confirm
            document.addEventListener('click', (e) => {
                const btn = e.target?.closest?.('[data-sq-confirm]');
                if (!btn) return;

                const form = btn.closest('form');
                const isSubmit = btn.tagName === 'BUTTON' && (btn.getAttribute('type') || 'submit') === 'submit';
                if (!form || !isSubmit) return;

                e.preventDefault();
                const message = btn.getAttribute('data-sq-confirm') || 'Are you sure you want to continue?';
                open(message, () => form.submit());
            });
        })();

        // Certificate targeting
        function sqToggleCertTargetValue() {
            const type = document.getElementById('sqCertTargetType');
            const value = document.getElementById('sqCertTargetValue');
            if (!type || !value) return;
            const needsValue = ['role', 'user_id', 'username'].includes(type.value);
            value.disabled = !needsValue;
            if (!needsValue) value.value = '';
        }
        sqToggleCertTargetValue();

        // Certificate edit modal helpers
        const sqCertEditModal = document.getElementById('sqCertEditModal');
        function sqOpenEditCert(cert) {
            if (!sqCertEditModal || !cert) return;
            document.getElementById('sqEditCertId').value = cert.id || '';
            document.getElementById('sqEditCertTitle').value = cert.title || '';
            document.getElementById('sqEditCertBody').value = cert.body || '';
            document.getElementById('sqEditCertTargetType').value = cert.target_type || 'everyone';
            document.getElementById('sqEditCertTargetValue').value = cert.target_value || '';
            document.getElementById('sqEditCertActive').value = cert.is_active || 'yes';
            sqToggleEditCertTargetValue();

            sqCertEditModal.classList.add('sq-cert-modal--active');
            sqCertEditModal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function sqCloseEditCert() {
            if (!sqCertEditModal) return;
            sqCertEditModal.classList.remove('sq-cert-modal--active');
            sqCertEditModal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        function sqToggleEditCertTargetValue() {
            const type = document.getElementById('sqEditCertTargetType');
            const value = document.getElementById('sqEditCertTargetValue');
            if (!type || !value) return;
            const needsValue = ['role', 'user_id', 'username'].includes(type.value);
            value.disabled = !needsValue;
            if (!needsValue) value.value = '';
        }

        sqCertEditModal?.addEventListener('click', (e) => {
            if (e.target === sqCertEditModal) sqCloseEditCert();
        });
    </script>

    <script>
        function sqUpdatePerPage(perPageValue) {
            const url = new URL(window.location.href);
            url.searchParams.set('per_page', perPageValue);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }

        (function () {
            const volEl = document.getElementById('sqSecurityLogsVolumeChart');
            const topEl = document.getElementById('sqSecurityLogsTopTypesChart');
            if (typeof Chart === 'undefined' || (!volEl && !topEl)) return;

            const labels = <?php echo json_encode($logsMetricsLabels ?? [], JSON_UNESCAPED_SLASHES); ?>;
            const counts = <?php echo json_encode($logsMetricsCounts ?? [], JSON_UNESCAPED_SLASHES); ?>;
            const topLabels = <?php echo json_encode($logsMetricsTopLabels ?? [], JSON_UNESCAPED_SLASHES); ?>;
            const topCounts = <?php echo json_encode($logsMetricsTopCounts ?? [], JSON_UNESCAPED_SLASHES); ?>;

            const isDark = document.body.classList.contains('sq-dark');
            const grid = isDark ? 'rgba(148, 163, 184, 0.16)' : 'rgba(148, 163, 184, 0.22)';
            const ticks = isDark ? '#cbd5e1' : '#475569';
            const severityColor = (rawType) => {
                const t = (rawType || '').toString().toLowerCase();
                // Critical / high-risk
                if (t === 'failed_login') return { b: '#ef4444', bgL: 'rgba(239, 68, 68, 0.20)', bgD: 'rgba(239, 68, 68, 0.26)' };
                if (t === 'security_alert') return { b: '#dc2626', bgL: 'rgba(220, 38, 38, 0.20)', bgD: 'rgba(220, 38, 38, 0.26)' };
                if (t === 'access_denied') return { b: '#ef4444', bgL: 'rgba(239, 68, 68, 0.20)', bgD: 'rgba(239, 68, 68, 0.26)' };
                if (t === 'certificate_required') return { b: '#f97316', bgL: 'rgba(249, 115, 22, 0.22)', bgD: 'rgba(249, 115, 22, 0.28)' };
                // Medium
                if (t === 'password_change') return { b: '#f59e0b', bgL: 'rgba(245, 158, 11, 0.22)', bgD: 'rgba(245, 158, 11, 0.28)' };
                if (t === 'admin_action') return { b: '#8b5cf6', bgL: 'rgba(139, 92, 246, 0.18)', bgD: 'rgba(139, 92, 246, 0.24)' };
                // Normal / informational
                if (t === 'login') return { b: '#10b981', bgL: 'rgba(16, 185, 129, 0.18)', bgD: 'rgba(16, 185, 129, 0.24)' };
                if (t === 'logout') return { b: '#64748b', bgL: 'rgba(100, 116, 139, 0.18)', bgD: 'rgba(100, 116, 139, 0.24)' };
                if (t === 'certificate_accepted') return { b: '#10b981', bgL: 'rgba(16, 185, 129, 0.18)', bgD: 'rgba(16, 185, 129, 0.24)' };
                return { b: isDark ? '#a78bfa' : '#3b82f6', bgL: 'rgba(59, 130, 246, 0.16)', bgD: 'rgba(167, 139, 250, 0.18)' };
            };

            if (volEl && labels.length) {
                new Chart(volEl.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels,
                        datasets: [{
                            label: 'Logs',
                            data: counts,
                            borderColor: isDark ? '#a78bfa' : '#3b82f6',
                            backgroundColor: isDark ? 'rgba(167, 139, 250, 0.18)' : 'rgba(59, 130, 246, 0.14)',
                            tension: 0.35,
                            fill: true,
                            pointRadius: 2,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { labels: { color: ticks, font: { weight: '700' } } } },
                        scales: {
                            x: { grid: { color: grid }, ticks: { color: ticks, font: { weight: '700' } } },
                            y: { grid: { color: grid }, ticks: { color: ticks }, beginAtZero: true }
                        }
                    }
                });
            }

            if (topEl && topLabels.length) {
                const barBackground = topLabels.map((t) => {
                    const c = severityColor(t);
                    return isDark ? c.bgD : c.bgL;
                });
                const barBorder = topLabels.map((t) => severityColor(t).b);
                new Chart(topEl.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: topLabels.map((t) => (t || '').toString().replace(/_/g, ' ')),
                        datasets: [{
                            label: 'Events',
                            data: topCounts,
                            borderColor: barBorder,
                            backgroundColor: barBackground,
                            borderWidth: 1,
                            borderRadius: 10,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { labels: { color: ticks, font: { weight: '700' } } } },
                        scales: {
                            x: { grid: { display: false }, ticks: { color: ticks, font: { weight: '700' } } },
                            y: { grid: { color: grid }, ticks: { color: ticks }, beginAtZero: true }
                        }
                    }
                });
            }
        })();

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
    </script>
</body>

</html>