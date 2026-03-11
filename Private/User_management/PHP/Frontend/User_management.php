<?php
// admin_users_list.php - User listing page with search and filters
session_start();

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header("Location: ../../../../Public/Login_page/PHP/Frontend/Login_page_site.php?error=not_authenticated");
    exit();
}

$userRole = $_SESSION['role'] ?? 'user';
if ($userRole !== 'admin' && $userRole !== 'super_admin') {
    header("Location: ../../../../Public/Login_page/PHP/Frontend/Login_page_site.php?error=unauthorized");
    exit();
}

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
define('ITEMS_PER_PAGE', 20);

// URL Configuration
define('BASE_URL', '/ScanQuotient/ScanQuotient');
// Images are stored in different project folder
define('STORAGE_URL', '/ScanQuotient.v2/ScanQuotient.B');

$adminName = $_SESSION['user_name'] ?? 'Admin';
$errorMessage = '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Filters
    $view = $_GET['view'] ?? 'active';
    $role = $_GET['role'] ?? 'all';
    $search = $_GET['search'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));

    $whereConditions = [];
    $params = [];

    if ($view === 'active') {
        $whereConditions[] = "deleted_at IS NULL";
    } elseif ($view === 'deleted') {
        $whereConditions[] = "deleted_at IS NOT NULL";
    }

    if ($role !== 'all') {
        $whereConditions[] = "role = ?";
        $params[] = $role;
    }

    if (!empty($search)) {
        $whereConditions[] = "(user_id LIKE ? OR first_name LIKE ? OR surname LIKE ? OR email LIKE ? OR user_name LIKE ?)";
        $searchTerm = "%$search%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }

    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    // Count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users $whereClause");
    $countStmt->execute($params);
    $totalItems = $countStmt->fetchColumn();
    $totalPages = ceil($totalItems / ITEMS_PER_PAGE);
    $offset = ($page - 1) * ITEMS_PER_PAGE;

    // Fetch users
    $query = "SELECT id, user_id, first_name, middle_name, surname, email, user_name, role, account_active, email_verified, created_at, profile_photo, deleted_at 
              FROM users $whereClause ORDER BY created_at DESC LIMIT " . ITEMS_PER_PAGE . " OFFSET $offset";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    // Stats
    $stats = [
        'total' => $pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn(),
        'admin' => $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'super_admin') AND deleted_at IS NULL")->fetchColumn(),
        'user' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user' AND deleted_at IS NULL")->fetchColumn(),
        'deleted' => $pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NOT NULL")->fetchColumn()
    ];

} catch (Exception $e) {
    error_log("Users List Error: " . $e->getMessage());
    $errorMessage = "Database error: " . $e->getMessage();
    $users = [];
    $stats = ['total' => 0, 'admin' => 0, 'user' => 0, 'deleted' => 0];
    $totalPages = 0;
}

// Helper function to resolve profile photo URL
function getProfilePhotoUrl($profilePhoto, $firstName, $surname)
{
    if (!empty($profilePhoto)) {
        // Images are in ScanQuotient.v2/ScanQuotient.B folder
        $photoPath = ltrim($profilePhoto, '/');
        return STORAGE_URL . '/' . $photoPath;
    }

    // Fallback to UI Avatars
    return 'https://ui-avatars.com/api/?name=' . urlencode($firstName . '+' . $surname) . '&background=8b5cf6&color=fff&size=100';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScanQuotient | User Management</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../CSS/user_management.css" />

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

    <main class="sq-admin-container">

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
                    <i class="fas fa-users"></i>
                </div>
                <div class="sq-stat-content">
                    <h3>
                        <?php echo number_format($stats['total']); ?>
                    </h3>
                    <p>Total Users</p>
                </div>
            </div>
            <div class="sq-stat-card">
                <div class="sq-stat-icon sq-stat-icon--admin">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="sq-stat-content">
                    <h3>
                        <?php echo number_format($stats['admin']); ?>
                    </h3>
                    <p>Admins</p>
                </div>
            </div>
            <div class="sq-stat-card">
                <div class="sq-stat-icon sq-stat-icon--user">
                    <i class="fas fa-user"></i>
                </div>
                <div class="sq-stat-content">
                    <h3>
                        <?php echo number_format($stats['user']); ?>
                    </h3>
                    <p>Regular Users</p>
                </div>
            </div>
            <div class="sq-stat-card">
                <div class="sq-stat-icon sq-stat-icon--deleted">
                    <i class="fas fa-trash"></i>
                </div>
                <div class="sq-stat-content">
                    <h3>
                        <?php echo number_format($stats['deleted']); ?>
                    </h3>
                    <p>Deactivated</p>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="sq-controls-section">
            <form method="GET" class="sq-filters-row">
                <div class="sq-filter-group">
                    <label class="sq-filter-label">View</label>
                    <select name="view" class="sq-filter-select" onchange="this.form.submit()">
                        <option value="active" <?php echo $view === 'active' ? 'selected' : ''; ?>>Active Users</option>
                        <option value="deleted" <?php echo $view === 'deleted' ? 'selected' : ''; ?>>Deleted Users
                        </option>
                        <option value="all" <?php echo $view === 'all' ? 'selected' : ''; ?>>All Users</option>
                    </select>
                </div>

                <div class="sq-filter-group">
                    <label class="sq-filter-label">Role</label>
                    <select name="role" class="sq-filter-select" onchange="this.form.submit()">
                        <option value="all">All Roles</option>
                        <option value="user" <?php echo $role === 'user' ? 'selected' : ''; ?>>User</option>
                        <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="super_admin" <?php echo $role === 'super_admin' ? 'selected' : ''; ?>>Super Admin
                        </option>
                    </select>
                </div>

                <div class="sq-filter-group sq-search-box">
                    <label class="sq-filter-label">Search</label>
                    <input type="text" name="search" class="sq-filter-input sq-search-input"
                        placeholder="Name, email, username..." value="<?php echo htmlspecialchars($search); ?>">

                </div>

                <button type="submit" class="sq-btn sq-btn-primary" style="height: 42px;">
                    <i class="fas fa-search"></i> Filter / Search
                </button>

                <a href="?" class="sq-btn sq-btn-secondary" style="height: 42px;">
                    <i class="fas fa-undo"></i> Reset
                </a>
            </form>
        </div>

        <!-- Users Table -->
        <div class="sq-table-container">
            <?php if (empty($users)): ?>
                <div class="sq-empty-state">
                    <div class="sq-empty-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3 class="sq-empty-title">No users found</h3>
                    <p class="sq-empty-text">
                        <?php echo !empty($search) ? 'Try adjusting your search criteria.' : 'No users match the selected filters.'; ?>
                    </p>
                </div>
            <?php else: ?>
                <table class="sq-data-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user):
                            // FIXED: Uses STORAGE_URL to point to correct image location
                            $avatarUrl = getProfilePhotoUrl(
                                $user['profile_photo'],
                                $user['first_name'],
                                $user['surname']
                            );

                            $fullName = htmlspecialchars($user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['surname']);
                            $userId = (int) $user['id'];
                            $isDeleted = !empty($user['deleted_at']);
                            $rowClass = $isDeleted ? 'deleted' : '';
                            $userRole = htmlspecialchars($user['role']);
                            $username = $user['user_name'] ? '@' . htmlspecialchars($user['user_name']) : '<em style="color: var(--sq-text-light);">Not set</em>';
                            $email = htmlspecialchars($user['email']);
                            $accountActive = $user['account_active'] === 'yes';
                            $emailVerified = $user['email_verified'] === 'yes';
                            $createdAt = date('M j, Y', strtotime($user['created_at']));
                            $detailUrl = 'User_detail.php?id=' . $userId;
                            ?>
                            <tr class="<?php echo $rowClass; ?>" onclick="window.location.href='<?php echo $detailUrl; ?>'">
                                <td>
                                    <div class="sq-user-cell">
                                        <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="" class="sq-user-avatar"
                                            onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($user['first_name'] . '+' . $user['surname']); ?>&background=8b5cf6&color=fff&size=100'">
                                        <div class="sq-user-info">
                                            <span class="sq-user-name">
                                                <?php echo $fullName; ?>
                                            </span>
                                            <span class="sq-user-email">
                                                <?php echo $email; ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php echo $username; ?>
                                </td>
                                <td>
                                    <span class="sq-role-badge sq-role-<?php echo $userRole; ?>">
                                        <i
                                            class="fas <?php echo $userRole === 'super_admin' ? 'fa-crown' : ($userRole === 'admin' ? 'fa-user-shield' : 'fa-user'); ?>"></i>
                                        <?php echo ucfirst(str_replace('_', ' ', $userRole)); ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                        <span
                                            class="sq-status-badge <?php echo $accountActive ? 'sq-status-active' : 'sq-status-inactive'; ?>">
                                            <i class="fas <?php echo $accountActive ? 'fa-check' : 'fa-times'; ?>"></i>
                                            <?php echo $accountActive ? 'Active' : 'Inactive'; ?>
                                        </span>
                                        <span
                                            class="sq-status-badge <?php echo $emailVerified ? 'sq-status-verified' : 'sq-status-unverified'; ?>">
                                            <i class="fas <?php echo $emailVerified ? 'fa-check-circle' : 'fa-clock'; ?>"></i>
                                            <?php echo $emailVerified ? 'Verified' : 'Unverified'; ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <?php echo $createdAt; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="sq-pagination">
                        <?php
                        $queryParams = "view=$view&role=$role&search=" . urlencode($search);

                        if ($page > 1): ?>
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
                                <span class="sq-page-btn active">
                                    <?php echo $i; ?>
                                </span>
                            <?php else: ?>
                                <a href="?<?php echo $queryParams; ?>&page=<?php echo $i; ?>" class="sq-page-btn">
                                    <?php echo $i; ?>
                                </a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <span class="sq-page-btn disabled">...</span>
                            <?php endif; ?>
                            <a href="?<?php echo $queryParams; ?>&page=<?php echo $totalPages; ?>" class="sq-page-btn">
                                <?php echo $totalPages; ?>
                            </a>
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
        </footer>
    </main>

    <script src="../../Javascript/user_management.js" defer></script>
</body>

</html>