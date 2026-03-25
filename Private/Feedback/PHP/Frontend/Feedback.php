<?php
// admin_customer_feedback.php - Admin interface for customer feedback management
session_start();

// Debug: Check session
error_log("Admin Feedback Session: " . print_r($_SESSION, true));

// Authentication check
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header("Location: /ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Frontend/login_page_site.php?error=not_authenticated");
    exit();
}

// Check if user is admin (adjust role check as needed)
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

// Admin user identification
$adminId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 'unknown';
$adminName = $_SESSION['user_name'] ?? 'Admin';
$profile_photo = $_SESSION['profile_photo'] ?? null;
if (!empty($profile_photo)) {
    $photo_path = ltrim((string) $profile_photo, '/');
    $base_url = '/ScanQuotient.v2/ScanQuotient.B';
    $avatar_url = $base_url . '/' . $photo_path;
} else {
    $avatar_url = '/ScanQuotient.v2/ScanQuotient.B/Storage/Public_images/default-avatar.png';
}
$successMessage = '';
$errorMessage = '';

// Pagination settings
define('DEFAULT_PER_PAGE', 10);

$perPageParam = (string) DEFAULT_PER_PAGE;
$effectivePerPage = (int) DEFAULT_PER_PAGE;
$totalItems = 0;
$totalPages = 0;
$offset = 0;
$recordsStart = 0;
$recordsEnd = 0;
$searchableColumns = [];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Handle actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'mark_viewed':
                $feedbackId = intval($_POST['feedback_id'] ?? 0);
                if ($feedbackId > 0) {
                    $stmt = $pdo->prepare("UPDATE customer_feedback SET is_viewed = 'yes', viewed_by = ?, viewed_at = NOW() WHERE id = ?");
                    $stmt->execute([$adminName, $feedbackId]);
                    $successMessage = "Feedback marked as viewed";
                }
                break;

            case 'mark_unviewed':
                $feedbackId = intval($_POST['feedback_id'] ?? 0);
                if ($feedbackId > 0) {
                    $stmt = $pdo->prepare("UPDATE customer_feedback SET is_viewed = 'no', viewed_by = NULL, viewed_at = NULL WHERE id = ?");
                    $stmt->execute([$feedbackId]);
                    $successMessage = "Feedback marked as unviewed";
                }
                break;

            case 'soft_delete':
                $feedbackId = intval($_POST['feedback_id'] ?? 0);
                if ($feedbackId > 0) {
                    $stmt = $pdo->prepare("UPDATE customer_feedback SET deleted_at = NOW(), deleted_by = ? WHERE id = ?");
                    $stmt->execute([$adminName, $feedbackId]);
                    $successMessage = "Feedback moved to trash";
                }
                break;

            case 'restore':
                $feedbackId = intval($_POST['feedback_id'] ?? 0);
                if ($feedbackId > 0) {
                    $stmt = $pdo->prepare("UPDATE customer_feedback SET deleted_at = NULL, deleted_by = NULL WHERE id = ?");
                    $stmt->execute([$feedbackId]);
                    $successMessage = "Feedback restored successfully";
                }
                break;

            case 'permanent_delete':
                $feedbackId = intval($_POST['feedback_id'] ?? 0);
                if ($feedbackId > 0) {
                    $stmt = $pdo->prepare("DELETE FROM customer_feedback WHERE id = ?");
                    $stmt->execute([$feedbackId]);
                    $successMessage = "Feedback permanently deleted";
                }
                break;

            case 'bulk_action':
                $bulkAction = $_POST['bulk_action'] ?? '';
                $selectedIds = $_POST['selected_ids'] ?? [];

                if (!empty($selectedIds) && is_array($selectedIds)) {
                    $ids = array_map('intval', $selectedIds);
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));

                    switch ($bulkAction) {
                        case 'mark_viewed':
                            $stmt = $pdo->prepare("UPDATE customer_feedback SET is_viewed = 'yes', viewed_by = ?, viewed_at = NOW() WHERE id IN ($placeholders)");
                            $params = array_merge([$adminName], $ids);
                            $stmt->execute($params);
                            $successMessage = count($ids) . " items marked as viewed";
                            break;
                        case 'mark_unviewed':
                            $stmt = $pdo->prepare("UPDATE customer_feedback SET is_viewed = 'no', viewed_by = NULL, viewed_at = NULL WHERE id IN ($placeholders)");
                            $stmt->execute($ids);
                            $successMessage = count($ids) . " items marked as unviewed";
                            break;
                        case 'soft_delete':
                            $stmt = $pdo->prepare("UPDATE customer_feedback SET deleted_at = NOW(), deleted_by = ? WHERE id IN ($placeholders)");
                            $params = array_merge([$adminName], $ids);
                            $stmt->execute($params);
                            $successMessage = count($ids) . " items moved to trash";
                            break;
                    }
                }
                break;
        }
    }

    // Get filter parameters
    $view = $_GET['view'] ?? 'active'; // active, trash, all
    $status = $_GET['status'] ?? 'all'; // viewed, unviewed, all
    $search = $_GET['search'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPageParam = $_GET['per_page'] ?? (string) DEFAULT_PER_PAGE;
    $allowedPerPage = ['5', '10', '20', '50', '100', '200', 'all'];
    if (!in_array($perPageParam, $allowedPerPage, true)) {
        $perPageParam = (string) DEFAULT_PER_PAGE;
    }
    $effectivePerPage = $perPageParam === 'all' ? null : (int) $perPageParam;

    // Build query
    $whereConditions = [];
    $params = [];

    if ($view === 'active') {
        $whereConditions[] = "deleted_at IS NULL";
    } elseif ($view === 'trash') {
        $whereConditions[] = "deleted_at IS NOT NULL";
    }

    if ($status === 'viewed') {
        $whereConditions[] = "is_viewed = 'yes'";
    } elseif ($status === 'unviewed') {
        $whereConditions[] = "is_viewed = 'no'";
    }

    // Global search across all non-system columns.
    // Also used to compute the dynamic "Matched In" column.
    $systemSearchColumns = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
        'deleted_by',
        'submitted_at',
        'viewed_at',
        'viewed_by',
        // searched via filters already
        'is_viewed',
    ];

    $searchableColumns = [];
    try {
        $columnsMeta = $pdo->query("SHOW COLUMNS FROM customer_feedback")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columnsMeta as $meta) {
            $col = $meta['Field'] ?? '';
            if (!$col) continue;
            if (!preg_match('/^[A-Za-z0-9_]+$/', $col)) continue;
            if (in_array($col, $systemSearchColumns, true)) continue;
            $searchableColumns[] = $col;
        }
    } catch (Exception $e) {
        // Fallback to the previously supported fields.
        $searchableColumns = ['name', 'email', 'subject', 'message'];
    }

    if (!empty($search) && !empty($searchableColumns)) {
        $searchTerm = "%$search%";
        $orParts = [];
        foreach ($searchableColumns as $col) {
            $orParts[] = "CAST($col AS CHAR) LIKE ?";
            $params[] = $searchTerm;
        }
        $whereConditions[] = "(" . implode(" OR ", $orParts) . ")";
    }

    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    // Get total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM customer_feedback $whereClause");
    $countStmt->execute($params);
    $totalItems = $countStmt->fetchColumn();
    $totalPages = ($effectivePerPage === null || (int) $totalItems === 0) ? 1 : (int) ceil($totalItems / $effectivePerPage);
    $offset = $effectivePerPage === null ? 0 : ($page - 1) * $effectivePerPage;
    $recordsStart = (int) $totalItems === 0 ? 0 : ($offset + 1);
    $recordsEnd = $effectivePerPage === null ? (int) $totalItems : (int) min($offset + $effectivePerPage, $totalItems);

    // Get feedback entries
    $queryBase = "SELECT * FROM customer_feedback $whereClause ORDER BY submitted_at DESC";
    $query = $effectivePerPage === null
        ? $queryBase
        : ($queryBase . " LIMIT " . (int) $effectivePerPage . " OFFSET " . (int) $offset);
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $feedbackList = $stmt->fetchAll();

    // Get statistics
    $stats = [
        'total' => $pdo->query("SELECT COUNT(*) FROM customer_feedback WHERE deleted_at IS NULL")->fetchColumn(),
        'viewed' => $pdo->query("SELECT COUNT(*) FROM customer_feedback WHERE is_viewed = 'yes' AND deleted_at IS NULL")->fetchColumn(),
        'unviewed' => $pdo->query("SELECT COUNT(*) FROM customer_feedback WHERE is_viewed = 'no' AND deleted_at IS NULL")->fetchColumn(),
        'trash' => $pdo->query("SELECT COUNT(*) FROM customer_feedback WHERE deleted_at IS NOT NULL")->fetchColumn()
    ];

} catch (Exception $e) {
    error_log("Admin Feedback Error: " . $e->getMessage());
    $errorMessage = "Database error: " . $e->getMessage();
    $feedbackList = [];
    $stats = ['total' => 0, 'viewed' => 0, 'unviewed' => 0, 'trash' => 0];
    $totalPages = 0;
    $totalItems = 0;
    $searchableColumns = [];
    $perPageParam = (string) DEFAULT_PER_PAGE;
    $effectivePerPage = (int) DEFAULT_PER_PAGE;
    $offset = 0;
    $recordsStart = 0;
    $recordsEnd = 0;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Feedback | ScanQuotient</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../CSS/feedback.css" />
</head>

<body>

    <header class="sq-admin-header">
        <div class="sq-admin-header-left">
            <a href="../../../../Private/Admin_dashboard/PHP/Frontend/Admin_dashboard.php" class="sq-admin-back-btn">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div class="brand-wrapper">
                <a href="#" class="sq-admin-brand" style="color:#6c63ff;">ScanQuotient</a>
                <p class="sq-admin-tagline">Quantifying Risk. Strengthening Security.</p>
            </div>
        </div>
        <div class="sq-admin-header-right">
            <img src="<?php echo htmlspecialchars($avatar_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile" class="header-profile-photo">
            <div class="sq-admin-user">
                <i class="fas fa-user-shield"></i>
                <span>
                    <?php echo htmlspecialchars($adminName); ?>
                </span>
            </div>
            <button class="sq-admin-theme-toggle" id="sqThemeToggle" title="Toggle Theme">
                <i class="fas fa-sun"></i>
            </button>
            <a href="../../../../Private/Admin_dashboard/PHP/Frontend/Admin_dashboard.php" class="icon-btn"
                title="Logout"><i class="fas fa-home"></i></a>
            <a href="../../../../Public/Login_page/PHP/Frontend/Login_page_site.php" class="icon-btn" title="Logout"><i
                    class="fas fa-sign-out-alt"></i></a>
        </div>
    </header>

    <!-- View Modal -->
    <div class="sq-modal" id="sqViewModal">
        <div class="sq-modal-content">
            <div class="sq-modal-header">
                <h3 class="sq-modal-title"><i class="fas fa-envelope-open-text"></i> Feedback Details</h3>
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

    <!-- Action Confirm Modal -->
    <div class="sq-modal" id="sqActionConfirmModal">
        <div class="sq-modal-content" style="max-width: 520px;">
            <div class="sq-modal-header">
                <h3 class="sq-modal-title" id="sqActionConfirmTitle"><i class="fas fa-exclamation-triangle"></i> Confirm action</h3>
                <button class="sq-modal-close" type="button" id="sqActionConfirmClose">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="sq-modal-body">
                <p id="sqActionConfirmMessage" style="color: var(--sq-text-main); line-height: 1.6; margin: 4px 0 0 0;">
                    Are you sure you want to continue?
                </p>
            </div>
            <div class="sq-modal-footer">
                <button class="sq-btn sq-btn-secondary" type="button" id="sqActionCancelBtn">Cancel</button>
                <button class="sq-btn sq-btn-danger" type="button" id="sqActionConfirmBtn">
                    <i class="fas fa-trash"></i> Confirm
                </button>
            </div>
        </div>
    </div>

    <main class="sq-admin-container">

        <?php if ($successMessage): ?>
            <div class="sq-admin-alert sq-admin-alert--success">
                <i class="fas fa-check-circle"></i>
                <span>
                    <?php echo htmlspecialchars($successMessage); ?>
                </span>
                <button class="sq-admin-alert-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="sq-admin-alert sq-admin-alert--error">
                <i class="fas fa-exclamation-circle"></i>
                <span>
                    <?php echo htmlspecialchars($errorMessage); ?>
                </span>
                <button class="sq-admin-alert-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="sq-stats-grid">
            <div class="sq-stat-card">
                <div class="sq-stat-icon sq-stat-icon--total">
                    <i class="fas fa-inbox"></i>
                </div>
                <div class="sq-stat-content">
                    <h3>
                        <?php echo $stats['total']; ?>
                    </h3>
                    <p>Total Feedback</p>
                </div>
            </div>
            <div class="sq-stat-card">
                <div class="sq-stat-icon sq-stat-icon--viewed">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="sq-stat-content">
                    <h3>
                        <?php echo $stats['viewed']; ?>
                    </h3>
                    <p>Viewed</p>
                </div>
            </div>
            <div class="sq-stat-card">
                <div class="sq-stat-icon sq-stat-icon--unviewed">
                    <i class="fas fa-eye-slash"></i>
                </div>
                <div class="sq-stat-content">
                    <h3>
                        <?php echo $stats['unviewed']; ?>
                    </h3>
                    <p>Unviewed</p>
                </div>
            </div>
            <div class="sq-stat-card">
                <div class="sq-stat-icon sq-stat-icon--trash">
                    <i class="fas fa-trash"></i>
                </div>
                <div class="sq-stat-content">
                    <h3>
                        <?php echo $stats['trash']; ?>
                    </h3>
                    <p>In Trash</p>
                </div>
            </div>
        </div>

        <!-- Search Results Info (shows when searching) -->
        <?php if (!empty($search)): ?>
            <div class="sq-search-results">
                <div>
                    <i class="fas fa-search" style="color: var(--sq-brand); margin-right: 8px;"></i>
                    Search results for: <span class="sq-search-term">
                        <?php echo htmlspecialchars($search); ?>
                    </span>
                    (
                    <?php echo $totalItems; ?> found)
                </div>
                <a href="?view=<?php echo $view; ?>&status=<?php echo $status; ?>" class="sq-clear-btn"
                    style="padding: 6px 12px; font-size: 12px;">
                    <i class="fas fa-times"></i> Clear
                </a>
            </div>
        <?php endif; ?>

        <!-- Controls -->
        <div class="sq-controls-section">
            <div class="sq-filter-group">
                <a href="?view=active&status=<?php echo $status; ?>&search=<?php echo urlencode($search); ?>"
                    class="sq-filter-btn <?php echo $view === 'active' ? 'active' : ''; ?>">
                    <i class="fas fa-inbox"></i> Active
                </a>
                <a href="?view=trash&status=<?php echo $status; ?>&search=<?php echo urlencode($search); ?>"
                    class="sq-filter-btn <?php echo $view === 'trash' ? 'active' : ''; ?>">
                    <i class="fas fa-trash"></i> Trash
                </a>
                <a href="?view=all&status=<?php echo $status; ?>&search=<?php echo urlencode($search); ?>"
                    class="sq-filter-btn <?php echo $view === 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i> All
                </a>
            </div>

            <div class="sq-filter-group">
                <a href="?view=<?php echo $view; ?>&status=all&search=<?php echo urlencode($search); ?>"
                    class="sq-filter-btn <?php echo $status === 'all' ? 'active' : ''; ?>">
                    All Status
                </a>
                <a href="?view=<?php echo $view; ?>&status=viewed&search=<?php echo urlencode($search); ?>"
                    class="sq-filter-btn <?php echo $status === 'viewed' ? 'active' : ''; ?>">
                    <i class="fas fa-check"></i> Viewed
                </a>
                <a href="?view=<?php echo $view; ?>&status=unviewed&search=<?php echo urlencode($search); ?>"
                    class="sq-filter-btn <?php echo $status === 'unviewed' ? 'active' : ''; ?>">
                    <i class="fas fa-eye-slash"></i> Unviewed
                </a>
            </div>

            <!-- FIXED: Proper Search Form with Button -->
            <form method="GET" class="sq-search-form">
                <input type="hidden" name="view" value="<?php echo $view; ?>">
                <input type="hidden" name="status" value="<?php echo $status; ?>">

                <div class="sq-search-box">
                    <i class="fas fa-search sq-search-icon"></i>
                    <input type="text" name="search" class="sq-search-input"
                        placeholder="Search by name, email, subject..."
                        value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <button type="submit" class="sq-search-btn">
                    <i class="fas fa-search"></i> Search
                </button>

                <?php if (!empty($search)): ?>
                    <a href="?view=<?php echo $view; ?>&status=<?php echo $status; ?>" class="sq-clear-btn">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Bulk Actions -->
        <?php if (!empty($feedbackList)): ?>
            <form method="POST" id="sqBulkForm">
                <input type="hidden" name="action" value="bulk_action">
                <div class="sq-controls-section" style="margin-bottom: 16px;">
                    <div class="sq-bulk-actions">
                        <input type="checkbox" class="sq-checkbox" id="sqSelectAll" title="Select All">
                        <select name="bulk_action" class="sq-select-dropdown">
                            <option value="">Bulk Actions</option>
                            <?php if ($view !== 'trash'): ?>
                                <option value="mark_viewed">Mark as Viewed</option>
                                <option value="mark_unviewed">Mark as Unviewed</option>
                                <option value="soft_delete">Move to Trash</option>
                            <?php else: ?>
                                <option value="restore">Restore</option>
                            <?php endif; ?>
                        </select>
                        <button type="submit" class="sq-btn sq-btn-primary"
                            onclick="return confirm('Apply this action to selected items?')">
                            <i class="fas fa-check"></i> Apply
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Data Table -->
            <div class="sq-table-container">
                <?php if (empty($feedbackList)): ?>
                    <div class="sq-empty-state">
                        <div class="sq-empty-icon">
                            <i class="fas fa-inbox"></i>
                        </div>
                        <h3 class="sq-empty-title">No feedback found</h3>
                        <p class="sq-empty-text">
                            <?php echo !empty($search) ? 'Try adjusting your search criteria or <a href="?view=' . $view . '&status=' . $status . '" style="color: var(--sq-brand);">clear the search</a>.' : 'No feedback entries available in this view.'; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <table class="sq-data-table">
                        <thead>
                            <tr>
                                <th width="40"><input type="checkbox" class="sq-checkbox" id="sqSelectAllHeader"></th>
                                <th>Sender</th>
                                <th>Subject</th>
                                <th>Message</th>
                                <?php if (!empty($search)): ?>
                                    <th class="sq-matched-in-col">Matched In</th>
                                <?php endif; ?>
                                <th>Status</th>
                                <th>Date</th>
                                <th width="150">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($feedbackList as $item): ?>
                                <tr class="<?php echo $item['deleted_at'] ? 'deleted' : ''; ?>"
                                    data-id="<?php echo $item['id']; ?>">
                                    <td>
                                        <input type="checkbox" class="sq-checkbox sq-item-checkbox" name="selected_ids[]"
                                            value="<?php echo $item['id']; ?>">
                                    </td>
                                    <td>
                                        <div class="sq-user-info">
                                            <span class="sq-user-name">
                                                <?php echo htmlspecialchars($item['name']); ?>
                                            </span>
                                            <span class="sq-user-email">
                                                <?php echo htmlspecialchars($item['email']); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo !empty($item['subject']) ? htmlspecialchars($item['subject']) : '<em style="color: var(--sq-text-light);">No Subject</em>'; ?>
                                    </td>
                                    <td>
                                        <div class="sq-message-preview">
                                            <?php echo htmlspecialchars($item['message']); ?>
                                        </div>
                                    </td>
                                    <?php
                                    $matchedInStr = '-';
                                    if (!empty($search) && !empty($searchableColumns)) {
                                        $matchedCols = [];
                                        foreach ($searchableColumns as $col) {
                                            $val = $item[$col] ?? null;
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
                                    <?php if (!empty($search)): ?>
                                        <td class="sq-matched-in-col"><?php echo htmlspecialchars($matchedInStr); ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <?php if ($item['is_viewed'] === 'yes'): ?>
                                            <span class="sq-status-badge sq-status-viewed">
                                                <i class="fas fa-check"></i> Viewed
                                            </span>
                                        <?php else: ?>
                                            <span class="sq-status-badge sq-status-unviewed">
                                                <i class="fas fa-clock"></i> New
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($item['submitted_at'])); ?>
                                        <br>
                                        <small style="color: var(--sq-text-light);">
                                            <?php echo date('g:i A', strtotime($item['submitted_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="sq-actions">
                                            <button type="button" class="sq-action-btn sq-action-view"
                                                onclick="sqViewFeedback(<?php echo htmlspecialchars(json_encode($item)); ?>)"
                                                title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>

                                            <?php if ($item['deleted_at']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="restore">
                                                    <input type="hidden" name="feedback_id" value="<?php echo $item['id']; ?>">
                                                    <button type="submit" class="sq-action-btn sq-action-restore" title="Restore">
                                                        <i class="fas fa-undo"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" style="display: inline;" class="js-confirm-action"
                                                    data-confirm-title="Delete feedback permanently?"
                                                    data-confirm-message="This will permanently delete this feedback and cannot be undone."
                                                    data-confirm-btn="Delete forever">
                                                    <input type="hidden" name="action" value="permanent_delete">
                                                    <input type="hidden" name="feedback_id" value="<?php echo $item['id']; ?>">
                                                    <button type="submit" class="sq-action-btn sq-action-delete"
                                                        title="Delete Forever">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <?php if ($item['is_viewed'] === 'no'): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="mark_viewed">
                                                        <input type="hidden" name="feedback_id" value="<?php echo $item['id']; ?>">
                                                        <button type="submit" class="sq-action-btn sq-action-mark"
                                                            title="Mark as Viewed">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="mark_unviewed">
                                                        <input type="hidden" name="feedback_id" value="<?php echo $item['id']; ?>">
                                                        <button type="submit" class="sq-action-btn sq-action-mark"
                                                            title="Mark as Unviewed">
                                                            <i class="fas fa-eye-slash"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="POST" style="display: inline;" class="js-confirm-action"
                                                    data-confirm-title="Move feedback to trash?"
                                                    data-confirm-message="This feedback will be moved to trash. You can restore it later."
                                                    data-confirm-btn="Move to trash">
                                                    <input type="hidden" name="action" value="soft_delete">
                                                    <input type="hidden" name="feedback_id" value="<?php echo $item['id']; ?>">
                                                    <button type="submit" class="sq-action-btn sq-action-delete"
                                                        title="Move to Trash">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if (!empty($totalItems) && (int) $totalItems > 0): ?>
                        <div class="sq-per-page-row"
                            style="display:flex; justify-content:center; align-items:center; gap:12px; flex-wrap:wrap; padding: 10px 0 0; border-top: 1px solid var(--sq-border);">
                            <label style="color: var(--sq-text-light); font-size: 13px; font-weight: 700;">
                                Records
                                <select id="sqPerPageSelect"
                                    style="margin: 0 8px; padding: 8px 12px; border-radius: 10px; border: 1px solid var(--sq-border); background: var(--sq-bg-card); color: var(--sq-text-main);"
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
                            <span style="color: var(--sq-text-light); font-size: 12px;">
                                Showing <?php echo (int) $recordsStart; ?>–<?php echo (int) $recordsEnd; ?> of <?php echo number_format((int) $totalItems); ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="sq-pagination">
                            <?php if ($page > 1): ?>
                                <a href="?view=<?php echo $view; ?>&status=<?php echo $status; ?>&search=<?php echo urlencode($search); ?>&per_page=<?php echo urlencode((string) $perPageParam); ?>&page=<?php echo $page - 1; ?>"
                                    class="sq-page-btn">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="sq-page-btn disabled"><i class="fas fa-chevron-left"></i></span>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <?php if ($i == $page): ?>
                                    <span class="sq-page-btn active">
                                        <?php echo $i; ?>
                                    </span>
                                <?php else: ?>
                                    <a href="?view=<?php echo $view; ?>&status=<?php echo $status; ?>&search=<?php echo urlencode($search); ?>&per_page=<?php echo urlencode((string) $perPageParam); ?>&page=<?php echo $i; ?>"
                                        class="sq-page-btn">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="?view=<?php echo $view; ?>&status=<?php echo $status; ?>&search=<?php echo urlencode($search); ?>&per_page=<?php echo urlencode((string) $perPageParam); ?>&page=<?php echo $page + 1; ?>"
                                    class="sq-page-btn">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="sq-page-btn disabled"><i class="fas fa-chevron-right"></i></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if (!empty($feedbackList)): ?>
            </form>
        <?php endif; ?>

        <footer class="sq-admin-footer">
            <p>ScanQuotient Security Platform • Quantifying Risk. Strengthening Security.</p>
            <p style="margin-top: 8px; font-size: 12px;">
                Logged in as
                <?php echo htmlspecialchars($adminName); ?> •
                <a href="../../../../Public/Login_page/PHP/Frontend/Login_page_site.php"
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
            background: #10b981;
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
            box-shadow: 0 10px 24px rgba(0,0,0,0.4);
        }
        .sq-back-to-top.sq-back-to-top--visible{
            opacity: 1;
            pointer-events: auto;
            transform: translateY(-2px);
        }
    </style>

    <script>
        function sqUpdatePerPage(perPageValue) {
            const url = new URL(window.location.href);
            url.searchParams.set('per_page', perPageValue);
            url.searchParams.set('page', '1');
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
    </script>

    <script src="../../Javascript/feedback.js" defer></script>
</body>

</html>