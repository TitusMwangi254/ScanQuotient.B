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
define('DEFAULT_PER_PAGE', 10);

// URL Configuration
define('BASE_URL', '/ScanQuotient/ScanQuotient');
// Images are stored in different project folder
define('STORAGE_URL', '/ScanQuotient.v2/ScanQuotient.B');

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
$recordsStart = 0;
$recordsEnd = 0;
$offset = 0;
$totalPages = 0;

// Flash messages (success)
if (isset($_SESSION['sq_user_create_success']) && is_string($_SESSION['sq_user_create_success'])) {
    $successMessage = $_SESSION['sq_user_create_success'];
    unset($_SESSION['sq_user_create_success']);
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // ---- Create user (Admin modal) ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_user') {
        // Helper functions based on registration handler patterns
        function generateUserID(): string
        {
            $prefix = 'UID';
            $length = 7;
            $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $max = strlen($characters) - 1;
            $randomPart = '';
            for ($i = 0; $i < $length; $i++) {
                $randomPart .= $characters[random_int(0, $max)];
            }
            return $prefix . $randomPart;
        }

        function generatePassword(int $length = 12): string
        {
            $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
            $max = strlen($characters) - 1;
            $password = '';
            for ($i = 0; $i < $length; $i++) {
                $password .= $characters[random_int(0, $max)];
            }
            return $password;
        }

        function getRequestString(string $key): string
        {
            return trim((string) ($_POST[$key] ?? ''));
        }

        $actionUserRole = getRequestString('role');
        $currentAdminRole = $_SESSION['role'] ?? 'admin';

        $validGenders = ['male', 'female', 'other'];
        $validRoles = ['user', 'admin', 'super_admin'];
        $validYesNo = ['yes', 'no'];

        $first_name = getRequestString('first_name');
        $middle_name = getRequestString('middle_name');
        $surname = getRequestString('surname');
        $gender = getRequestString('gender');
        $phone_number = getRequestString('phone_number');
        $email = getRequestString('email');
        $recovery_email = getRequestString('recovery_email');
        $security_question = getRequestString('security_question');
        $security_answer = getRequestString('security_answer');

        $user_name = getRequestString('user_name');
        $account_active = getRequestString('account_active');
        $email_verified = getRequestString('email_verified');
        $role = in_array($actionUserRole, $validRoles, true) ? $actionUserRole : 'user';

        if ($role === 'super_admin' && $currentAdminRole !== 'super_admin') {
            throw new Exception("Only super admins can assign super admin role.");
        }

        if (empty($first_name) || empty($surname) || !in_array($gender, $validGenders, true) || empty($phone_number) || empty($email) || empty($recovery_email) || empty($security_question) || empty($security_answer)) {
            throw new Exception("Please fill in all required fields.");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !filter_var($recovery_email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email or recovery email.");
        }

        if (!in_array($account_active, $validYesNo, true)) {
            $account_active = 'yes';
        }
        if (!in_array($email_verified, $validYesNo, true)) {
            $email_verified = 'no';
        }

        // Optional password (if empty => generate)
        $passwordInput = getRequestString('password');
        $generatedPassword = null;
        if ($passwordInput === '') {
            $generatedPassword = generatePassword(12);
            $passwordHash = password_hash($generatedPassword, PASSWORD_DEFAULT);
        } else {
            if (strlen($passwordInput) < 8) {
                throw new Exception("Password must be at least 8 characters.");
            }
            $passwordHash = password_hash($passwordInput, PASSWORD_DEFAULT);
        }

        // Username default
        if ($user_name === '') {
            $user_name = strtolower($surname);
        }

        // Uniqueness checks (email and user_name)
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $check->execute([$email]);
        if ($check->fetch()) {
            throw new Exception("Email is already in use.");
        }

        $checkUser = $pdo->prepare("SELECT id FROM users WHERE user_name = ? LIMIT 1");
        $checkUser->execute([$user_name]);
        if ($checkUser->fetch()) {
            throw new Exception("Username is already in use.");
        }

        // Generate structured User ID
        $user_id = generateUserID();

        // Hash security answer
        $hashed_security_answer = password_hash($security_answer, PASSWORD_DEFAULT);

        $email_verification_token = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $email_verification_expires = date("Y-m-d H:i:s", strtotime("+5 minutes"));

        // Handle profile photo upload (optional)
        $profile_photo_path = null;
        $allowed = [
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/gif' => '.gif',
            'image/webp' => '.webp'
        ];

        // Match the same storage logic used in Registration_page.php backend
        // (compute project root, then store under Storage/User_Profile_images/).
        $projectRoot = realpath(__DIR__ . '/../../../../');
        if ($projectRoot === false || empty($projectRoot) || !is_dir($projectRoot . DIRECTORY_SEPARATOR . 'Storage')) {
            throw new Exception("Unable to determine project root directory for uploads.");
        }

        $upload_dir = $projectRoot . DIRECTORY_SEPARATOR . 'Storage' . DIRECTORY_SEPARATOR . 'User_Profile_images';
        $db_relative_path = 'Storage/User_Profile_images/';

        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_photo'];
            $size = (int) $file['size'];
            if ($size > 5 * 1024 * 1024) {
                throw new Exception("Profile photo too large. Max 5MB.");
            }

            $tmp = $file['tmp_name'];
            $mime = mime_content_type($tmp);
            if (!isset($allowed[$mime])) {
                throw new Exception("Invalid profile photo type. Use JPG/PNG/GIF/WebP.");
            }

            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true)) {
                    throw new Exception("Failed to create upload directory.");
                }
            }

            if (!is_writable($upload_dir)) {
                throw new Exception("Upload directory is not writable.");
            }

            $safe_user_id = preg_replace('/[^a-zA-Z0-9]/', '', $user_id);
            // Keep the same filename approach as the registration handler:
            // safe_user_id + extension (no timestamp).
            $new_filename = $safe_user_id . $allowed[$mime];
            $destination = $upload_dir . DIRECTORY_SEPARATOR . $new_filename;

            if (!move_uploaded_file($tmp, $destination)) {
                throw new Exception("Failed to upload profile photo.");
            }
            chmod($destination, 0644);

            $profile_photo_path = $db_relative_path . $new_filename;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    user_id,
                    first_name,
                    middle_name,
                    surname,
                    gender,
                    phone_number,
                    email,
                    profile_photo,
                    recovery_email,
                    security_question,
                    security_answer,
                    user_name,
                    password_hash,
                    role,
                    account_active,
                    email_verified,
                    email_verification_token,
                    email_verification_expires
                ) VALUES (
                    :user_id,
                    :first_name,
                    :middle_name,
                    :surname,
                    :gender,
                    :phone,
                    :email,
                    :profile_photo,
                    :recovery_email,
                    :security_question,
                    :security_answer,
                    :user_name,
                    :password_hash,
                    :role,
                    :account_active,
                    :email_verified,
                    :token,
                    :expires
                )
            ");

            $stmt->execute([
                ':user_id' => $user_id,
                ':first_name' => $first_name,
                ':middle_name' => $middle_name !== '' ? $middle_name : null,
                ':surname' => $surname,
                ':gender' => $gender,
                ':phone' => $phone_number,
                ':email' => $email,
                ':profile_photo' => $profile_photo_path,
                ':recovery_email' => $recovery_email,
                ':security_question' => $security_question,
                ':security_answer' => $hashed_security_answer,
                ':user_name' => $user_name,
                ':password_hash' => $passwordHash,
                ':role' => $role,
                ':account_active' => $account_active,
                ':email_verified' => $email_verified,
                ':token' => $email_verification_token,
                ':expires' => $email_verification_expires,
            ]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        $successText = "User created successfully. User ID: " . htmlspecialchars($user_id);
        if ($generatedPassword !== null) {
            $successText .= " | Temporary password: " . htmlspecialchars($generatedPassword);
        }
        $_SESSION['sq_user_create_success'] = $successText;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Filters
    $view = $_GET['view'] ?? 'active';
    $role = $_GET['role'] ?? 'all';
    $search = $_GET['search'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPageParam = $_GET['per_page'] ?? (string) DEFAULT_PER_PAGE;
    $allowedPerPage = ['5', '10', '20', '50', '100', '200', 'all'];
    if (!in_array($perPageParam, $allowedPerPage, true)) {
        $perPageParam = (string) DEFAULT_PER_PAGE;
    }
    $effectivePerPage = $perPageParam === 'all' ? null : (int) $perPageParam;

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

    // Global search across all non-system columns (used for both filtering and "Matched In").
    $systemSearchColumns = ['id', 'deleted_at', 'profile_photo'];
    $searchableColumns = [];
    foreach (['user_id', 'first_name', 'middle_name', 'surname', 'email', 'user_name', 'role', 'account_active', 'email_verified', 'created_at'] as $col) {
        if (!in_array($col, $systemSearchColumns, true)) {
            $searchableColumns[] = $col;
        }
    }

    if (!empty($search) && !empty($searchableColumns)) {
        $searchTerm = "%$search%";
        $orParts = [];
        foreach ($searchableColumns as $col) {
            // All columns in this query are from a trusted static schema list.
            $orParts[] = "CAST($col AS CHAR) LIKE ?";
            $params[] = $searchTerm;
        }
        $whereConditions[] = "(" . implode(" OR ", $orParts) . ")";
    }

    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    // Count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users $whereClause");
    $countStmt->execute($params);
    $totalItems = $countStmt->fetchColumn();
    $totalPages = ($effectivePerPage === null || (int) $totalItems === 0) ? 1 : (int) ceil($totalItems / $effectivePerPage);
    $offset = $effectivePerPage === null ? 0 : ($page - 1) * $effectivePerPage;
    $recordsStart = (int) $totalItems === 0 ? 0 : ($offset + 1);
    $recordsEnd = $effectivePerPage === null ? (int) $totalItems : (int) min($offset + $effectivePerPage, $totalItems);

    // Fetch users
    $queryBase = "SELECT id, user_id, first_name, middle_name, surname, email, user_name, role, account_active, email_verified, created_at, profile_photo, deleted_at 
                  FROM users $whereClause ORDER BY created_at DESC";
    $query = $effectivePerPage === null
        ? $queryBase
        : ($queryBase . " LIMIT " . (int) $effectivePerPage . " OFFSET " . (int) $offset);
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

    // Metrics charts: last 14 days new users + role distribution (active)
    $usersMetricsLabels = [];
    $usersMetricsNewCounts = [];
    $usersMetricsRoleLabels = [];
    $usersMetricsRoleCounts = [];
    try {
        $days = 14;
        $stmt = $pdo->prepare("
            SELECT DATE(created_at) AS d, COUNT(*) AS cnt
            FROM users
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
            $usersMetricsLabels[] = date('M j', strtotime($day));
            $usersMetricsNewCounts[] = (int) ($byDay[$day] ?? 0);
        }

        $roleRows = $pdo->query("
            SELECT role, COUNT(*) AS cnt
            FROM users
            WHERE deleted_at IS NULL
            GROUP BY role
            ORDER BY cnt DESC
        ")->fetchAll();
        foreach ($roleRows as $rr) {
            $usersMetricsRoleLabels[] = (string) ($rr['role'] ?? 'unknown');
            $usersMetricsRoleCounts[] = (int) ($rr['cnt'] ?? 0);
        }
    } catch (Exception $e) {
        $usersMetricsLabels = [];
        $usersMetricsNewCounts = [];
        $usersMetricsRoleLabels = [];
        $usersMetricsRoleCounts = [];
    }

} catch (Exception $e) {
    error_log("Users List Error: " . $e->getMessage());
    $errorMessage = "Database error: " . $e->getMessage();
    $users = [];
    $stats = ['total' => 0, 'admin' => 0, 'user' => 0, 'deleted' => 0];
    $totalPages = 0;
    $totalItems = 0;
    $perPageParam = (string) DEFAULT_PER_PAGE;
    $effectivePerPage = (int) DEFAULT_PER_PAGE;
    $offset = 0;
    $recordsStart = 0;
    $recordsEnd = 0;
    $usersMetricsLabels = [];
    $usersMetricsNewCounts = [];
    $usersMetricsRoleLabels = [];
    $usersMetricsRoleCounts = [];
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

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

        <div class="sq-table-container" style="padding: 18px; margin: 18px 0 22px; overflow: visible;">
            <div style="display:flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 10px;">
                <div style="font-weight: 900; letter-spacing: 0.2px; display:flex; align-items:center; gap: 10px;">
                    <i class="fas fa-chart-bar" style="color: var(--sq-accent);"></i>
                    User metrics
                </div>
                <div style="color: var(--sq-text-light); font-size: 12px; font-weight: 700;">Last 14 days • Role distribution</div>
            </div>
            <div style="display:grid; grid-template-columns: 2fr 1fr; gap: 16px; align-items: stretch;">
                <div style="height: 280px;">
                    <canvas id="sqUsersNewChart"></canvas>
                </div>
                <div style="height: 280px;">
                    <canvas id="sqUsersRoleChart"></canvas>
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

                <button type="button" id="sqOpenCreateUserBtn" class="sq-btn sq-btn-primary" style="height: 42px;"
                    onclick="sqOpenCreateUserModal()">
                    <i class="fas fa-plus"></i> Create User
                </button>
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
                            <?php if (!empty($search)): ?>
                                <th>Matched In</th>
                            <?php endif; ?>
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

                            $matchedInStr = '-';
                            if (!empty($search) && !empty($searchableColumns)) {
                                $matchedCols = [];
                                foreach ($searchableColumns as $col) {
                                    $val = $user[$col] ?? null;
                                    if ($val === null) continue;
                                    if (stripos((string) $val, $search) !== false) {
                                        $matchedCols[] = $col;
                                    }
                                }
                                if (!empty($matchedCols)) $matchedInStr = implode(', ', $matchedCols);
                            }
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
                                <?php if (!empty($search)): ?>
                                    <td><?php echo htmlspecialchars($matchedInStr); ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ((int) $totalItems > 0): ?>
                    <div class="sq-per-page-row"
                        style="display:flex; justify-content:center; align-items:center; gap:12px; flex-wrap:wrap; padding: 10px 0 0; border-top: 1px solid var(--sq-border);">
                        <label style="color: var(--sq-text-light); font-size: 13px; font-weight: 600;">
                            Records
                            <select id="sqPerPageSelect" class="sq-per-page-select"
                                style="margin: 0 8px; padding: 8px 12px; border-radius: 10px; border: 1px solid var(--sq-border); background: var(--sq-bg-card); color: var(--sq-text-main);">
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
                        <span class="sq-record-info" style="color: var(--sq-text-light); font-size: 12px;">
                            Showing <?php echo (int) $recordsStart; ?>–<?php echo (int) $recordsEnd; ?> of <?php echo number_format((int) $totalItems); ?>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($totalPages > 1): ?>
                    <div class="sq-pagination">
                        <?php
                        $queryParams = "view=$view&role=$role&search=" . urlencode($search) . "&per_page=" . urlencode((string) $perPageParam);

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

    <!-- Create User Modal -->
    <div id="sqCreateUserOverlay" class="sq-modal-overlay" style="display:none;"></div>
    <div id="sqCreateUserModal" class="sq-modal" style="display:none;">
        <div class="sq-modal-content">
            <div class="sq-modal-header">
                <h3 class="sq-modal-title">
                    <i class="fas fa-user-plus" style="margin-right: 8px;"></i> Create User
                </h3>
                <button type="button" class="sq-modal-close" onclick="sqCloseCreateUserModal()" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <form id="sqCreateUserForm" action="" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create_user">

                <div class="sq-modal-grid">
                    <div class="sq-form-group">
                        <label class="sq-form-label">First Name *</label>
                        <input type="text" name="first_name" class="sq-form-input" required>
                    </div>
                    <div class="sq-form-group">
                        <label class="sq-form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="sq-form-input">
                    </div>
                    <div class="sq-form-group">
                        <label class="sq-form-label">Surname *</label>
                        <input type="text" name="surname" class="sq-form-input" required>
                    </div>
                    <div class="sq-form-group">
                        <label class="sq-form-label">Gender *</label>
                        <select name="gender" class="sq-form-input" required>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="sq-form-group">
                        <label class="sq-form-label">Phone *</label>
                        <input type="text" name="phone_number" class="sq-form-input" required placeholder="+254...">
                    </div>
                    <div class="sq-form-group">
                        <label class="sq-form-label">Email *</label>
                        <input type="email" name="email" class="sq-form-input" required>
                    </div>
                    <div class="sq-form-group">
                        <label class="sq-form-label">Recovery Email *</label>
                        <input type="email" name="recovery_email" class="sq-form-input" required>
                    </div>

                    <div class="sq-form-group">
                        <label class="sq-form-label">Security Question *</label>
                        <input type="text" name="security_question" class="sq-form-input" required>
                    </div>
                    <div class="sq-form-group">
                        <label class="sq-form-label">Security Answer *</label>
                        <input type="text" name="security_answer" class="sq-form-input" required>
                    </div>

                    <div class="sq-form-group">
                        <label class="sq-form-label">Role *</label>
                        <select name="role" class="sq-form-input" required>
                            <option value="user">User</option>
                            <option value="admin">Admin</option>
                            <?php if ($userRole === 'super_admin'): ?>
                                <option value="super_admin">Super Admin</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="sq-form-group">
                        <label class="sq-form-label">Account Active *</label>
                        <select name="account_active" class="sq-form-input" required>
                            <option value="yes">Yes</option>
                            <option value="no" selected>No</option>
                        </select>
                    </div>
                    <div class="sq-form-group">
                        <label class="sq-form-label">Email Verified *</label>
                        <select name="email_verified" class="sq-form-input" required>
                            <option value="yes">Yes</option>
                            <option value="no" selected>No</option>
                        </select>
                    </div>

                    <div class="sq-form-group">
                        <label class="sq-form-label">Username (optional)</label>
                        <input type="text" name="user_name" class="sq-form-input" placeholder="defaults from surname">
                    </div>
                    <div class="sq-form-group sq-form-group--full">
                        <label class="sq-form-label">Temporary Password (optional)</label>
                        <input type="password" name="password" class="sq-form-input" placeholder="Leave blank to generate">
                        <small class="sq-subtle" style="display:block; margin-top:6px;">
                            Leave blank to auto-generate a secure password (shown in success toast).
                        </small>
                    </div>

                    <div class="sq-form-group sq-form-group--full">
                        <label class="sq-form-label">Profile Photo</label>
                        <div class="sq-photo-upload">
                            <input type="file" name="profile_photo" id="sqProfilePhotoInput"
                                accept="image/jpeg,image/png,image/gif,image/webp">
                            <div class="sq-photo-preview">
                                <img id="sqProfilePhotoPreview" src="" alt="Preview" style="display:none;">
                                <div id="sqProfilePhotoPlaceholder" class="sq-photo-placeholder">
                                    <i class="fas fa-user"></i> No image selected
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sq-modal-actions">
                    <button type="button" class="sq-btn sq-btn-secondary" onclick="sqCloseCreateUserModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="sq-btn sq-btn-primary">
                        <i class="fas fa-save"></i> Create User
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="../../Javascript/user_management.js" defer></script>
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
            background: var(--sq-success);
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
        (function () {
            const perPageSelect = document.getElementById('sqPerPageSelect');
            if (perPageSelect) {
                perPageSelect.addEventListener('change', function () {
                    const url = new URL(window.location.href);
                    url.searchParams.set('per_page', this.value);
                    url.searchParams.set('page', '1');
                    window.location.href = url.toString();
                });
            }

            const backToTopBtn = document.getElementById('backToTopBtn');
            if (backToTopBtn) {
                const onScroll = function () {
                    backToTopBtn.classList.toggle('sq-back-to-top--visible', window.scrollY > 400);
                };
                window.addEventListener('scroll', onScroll);
                onScroll();
                backToTopBtn.addEventListener('click', function () {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                });
            }
        })();
    </script>

    <script>
        (function () {
            const newEl = document.getElementById('sqUsersNewChart');
            const roleEl = document.getElementById('sqUsersRoleChart');
            if (typeof Chart === 'undefined' || (!newEl && !roleEl)) return;

            const labels = <?php echo json_encode($usersMetricsLabels ?? [], JSON_UNESCAPED_SLASHES); ?>;
            const newCounts = <?php echo json_encode($usersMetricsNewCounts ?? [], JSON_UNESCAPED_SLASHES); ?>;
            const roleLabels = <?php echo json_encode($usersMetricsRoleLabels ?? [], JSON_UNESCAPED_SLASHES); ?>;
            const roleCounts = <?php echo json_encode($usersMetricsRoleCounts ?? [], JSON_UNESCAPED_SLASHES); ?>;
            const isDark = document.body.classList.contains('sq-dark');
            const grid = isDark ? 'rgba(148, 163, 184, 0.16)' : 'rgba(148, 163, 184, 0.22)';
            const ticks = isDark ? '#cbd5e1' : '#475569';

            if (newEl && labels.length) {
                new Chart(newEl.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels,
                        datasets: [{
                            label: 'New users',
                            data: newCounts,
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

            if (roleEl && roleLabels.length) {
                new Chart(roleEl.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: roleLabels.map((t) => (t || '').toString().replace(/_/g, ' ')),
                        datasets: [{
                            data: roleCounts,
                            backgroundColor: [
                                'rgba(59, 130, 246, 0.55)',
                                'rgba(16, 185, 129, 0.55)',
                                'rgba(245, 158, 11, 0.55)',
                                'rgba(239, 68, 68, 0.55)',
                                'rgba(139, 92, 246, 0.55)'
                            ],
                            borderColor: isDark ? 'rgba(15, 23, 42, 0.6)' : 'rgba(255, 255, 255, 0.9)',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom', labels: { color: ticks, font: { weight: '700' } } }
                        }
                    }
                });
            }
        })();
    </script>
</body>

</html>