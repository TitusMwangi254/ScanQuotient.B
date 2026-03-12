<?php
// admin_payments.php - Full CRUD admin interface for payments
session_start();

// Debug: Check session
error_log("Payments Admin Session: " . print_r($_SESSION, true));

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
define('ITEMS_PER_PAGE', 15);

$adminId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 'unknown';
$adminName = $_SESSION['user_name'] ?? 'Admin';
$successMessage = '';
$errorMessage = '';

// Valid enums for status, payment_method, package, and account_status
$validStatuses = ['pending', 'completed', 'failed', 'refunded', 'cancelled'];
$validPaymentMethods = ['paypal', 'stripe', 'bank_transfer', 'crypto', 'manual'];
$validPackages = ['freemium', 'pro', 'enterprise'];
$validAccountStatuses = ['active', 'suspended'];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Handle POST actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'create':
                $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
                $package = in_array($_POST['package'] ?? '', $validPackages) ? $_POST['package'] : 'freemium';
                $accountStatus = in_array($_POST['account_status'] ?? '', $validAccountStatuses) ? $_POST['account_status'] : 'active';
                $amount = floatval($_POST['amount'] ?? 0);
                $transactionId = trim($_POST['transaction_id'] ?? '') ?: null;
                $status = in_array($_POST['status'] ?? '', $validStatuses) ? $_POST['status'] : 'completed';
                $paymentMethod = in_array($_POST['payment_method'] ?? '', $validPaymentMethods) ? $_POST['payment_method'] : 'paypal';
                $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errorMessage = "Invalid email address";
                } elseif ($amount < 0) {
                    $errorMessage = "Amount cannot be negative";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO payments (email, package, account_status, amount, transaction_id, status, payment_method, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$email, $package, $accountStatus, $amount, $transactionId, $status, $paymentMethod, $expiresAt]);
                    $successMessage = "Payment record created successfully";
                }
                break;

            case 'update':
                $paymentId = intval($_POST['payment_id'] ?? 0);
                $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
                $package = in_array($_POST['package'] ?? '', $validPackages) ? $_POST['package'] : 'freemium';
                $accountStatus = in_array($_POST['account_status'] ?? '', $validAccountStatuses) ? $_POST['account_status'] : 'active';
                $amount = floatval($_POST['amount'] ?? 0);
                $transactionId = trim($_POST['transaction_id'] ?? '') ?: null;
                $status = in_array($_POST['status'] ?? '', $validStatuses) ? $_POST['status'] : 'completed';
                $paymentMethod = in_array($_POST['payment_method'] ?? '', $validPaymentMethods) ? $_POST['payment_method'] : 'paypal';
                $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

                if ($paymentId <= 0) {
                    $errorMessage = "Invalid payment ID";
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errorMessage = "Invalid email address";
                } elseif ($amount < 0) {
                    $errorMessage = "Amount cannot be negative";
                } else {
                    $stmt = $pdo->prepare("UPDATE payments SET email = ?, package = ?, account_status = ?, amount = ?, transaction_id = ?, status = ?, payment_method = ?, expires_at = ? WHERE id = ? AND deleted_at IS NULL");
                    $stmt->execute([$email, $package, $accountStatus, $amount, $transactionId, $status, $paymentMethod, $expiresAt, $paymentId]);
                    if ($stmt->rowCount() > 0) {
                        $successMessage = "Payment record updated successfully";
                    } else {
                        $errorMessage = "Payment not found or already deleted";
                    }
                }
                break;

            case 'soft_delete':
                $paymentId = intval($_POST['payment_id'] ?? 0);
                if ($paymentId > 0) {
                    $stmt = $pdo->prepare("UPDATE payments SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND deleted_at IS NULL");
                    $stmt->execute([$adminName, $paymentId]);
                    if ($stmt->rowCount() > 0) {
                        $successMessage = "Payment moved to trash";
                    } else {
                        $errorMessage = "Payment not found or already deleted";
                    }
                }
                break;

            case 'restore':
                $paymentId = intval($_POST['payment_id'] ?? 0);
                if ($paymentId > 0) {
                    $stmt = $pdo->prepare("UPDATE payments SET deleted_at = NULL, deleted_by = NULL WHERE id = ? AND deleted_at IS NOT NULL");
                    $stmt->execute([$paymentId]);
                    if ($stmt->rowCount() > 0) {
                        $successMessage = "Payment restored successfully";
                    } else {
                        $errorMessage = "Payment not found in trash";
                    }
                }
                break;

            case 'permanent_delete':
                $paymentId = intval($_POST['payment_id'] ?? 0);
                if ($paymentId > 0) {
                    $stmt = $pdo->prepare("DELETE FROM payments WHERE id = ? AND deleted_at IS NOT NULL");
                    $stmt->execute([$paymentId]);
                    if ($stmt->rowCount() > 0) {
                        $successMessage = "Payment permanently deleted";
                    } else {
                        $errorMessage = "Payment not found or not in trash";
                    }
                }
                break;

            case 'bulk_action':
                $bulkAction = $_POST['bulk_action'] ?? '';
                $selectedIds = $_POST['selected_ids'] ?? [];

                if (!empty($selectedIds) && is_array($selectedIds)) {
                    $ids = array_map('intval', $selectedIds);
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));

                    switch ($bulkAction) {
                        case 'soft_delete':
                            $stmt = $pdo->prepare("UPDATE payments SET deleted_at = NOW(), deleted_by = ? WHERE id IN ($placeholders) AND deleted_at IS NULL");
                            $params = array_merge([$adminName], $ids);
                            $stmt->execute($params);
                            $successMessage = $stmt->rowCount() . " payment(s) moved to trash";
                            break;
                        case 'restore':
                            $stmt = $pdo->prepare("UPDATE payments SET deleted_at = NULL, deleted_by = NULL WHERE id IN ($placeholders) AND deleted_at IS NOT NULL");
                            $stmt->execute($ids);
                            $successMessage = $stmt->rowCount() . " payment(s) restored";
                            break;
                        case 'permanent_delete':
                            $stmt = $pdo->prepare("DELETE FROM payments WHERE id IN ($placeholders) AND deleted_at IS NOT NULL");
                            $stmt->execute($ids);
                            $successMessage = $stmt->rowCount() . " payment(s) permanently deleted";
                            break;
                        case 'update_status':
                            $newStatus = in_array($_POST['bulk_status'] ?? '', $validStatuses) ? $_POST['bulk_status'] : 'completed';
                            $stmt = $pdo->prepare("UPDATE payments SET status = ? WHERE id IN ($placeholders) AND deleted_at IS NULL");
                            $params = array_merge([$newStatus], $ids);
                            $stmt->execute($params);
                            $successMessage = $stmt->rowCount() . " payment(s) status updated to " . ucfirst($newStatus);
                            break;
                    }
                }
                break;
        }
    }

    // Get filter parameters
    $view = $_GET['view'] ?? 'active'; // active, trash, all
    $status = $_GET['status'] ?? 'all';
    $accountStatusFilter = $_GET['account_status'] ?? 'all';
    $search = $_GET['search'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));

    // Build query
    $whereConditions = [];
    $params = [];

    if ($view === 'active') {
        $whereConditions[] = "p.deleted_at IS NULL";
    } elseif ($view === 'trash') {
        $whereConditions[] = "p.deleted_at IS NOT NULL";
    }

    if ($status !== 'all') {
        $whereConditions[] = "p.status = ?";
        $params[] = $status;
    }

    if ($accountStatusFilter !== 'all') {
        $whereConditions[] = "p.account_status = ?";
        $params[] = $accountStatusFilter;
    }

    if (!empty($search)) {
        $whereConditions[] = "(p.email LIKE ? OR p.package LIKE ? OR p.transaction_id LIKE ?)";
        $searchTerm = "%$search%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
    }

    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    // Get total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM payments p $whereClause");
    $countStmt->execute($params);
    $totalItems = $countStmt->fetchColumn();
    $totalPages = ceil($totalItems / ITEMS_PER_PAGE);
    $offset = ($page - 1) * ITEMS_PER_PAGE;

    // Get payments
    $query = "SELECT p.*, u.first_name, u.surname 
              FROM payments p 
              LEFT JOIN users u ON p.email = u.email 
              $whereClause 
              ORDER BY p.created_at DESC 
              LIMIT " . ITEMS_PER_PAGE . " OFFSET $offset";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $payments = $stmt->fetchAll();

    // Get statistics
    $stats = [
        'total' => $pdo->query("SELECT COUNT(*) FROM payments WHERE deleted_at IS NULL")->fetchColumn(),
        'total_revenue' => $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'completed' AND deleted_at IS NULL")->fetchColumn(),
        'pending' => $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'pending' AND deleted_at IS NULL")->fetchColumn(),
        'completed' => $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'completed' AND deleted_at IS NULL")->fetchColumn(),
        'failed' => $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'failed' AND deleted_at IS NULL")->fetchColumn(),
        'trash' => $pdo->query("SELECT COUNT(*) FROM payments WHERE deleted_at IS NOT NULL")->fetchColumn()
    ];

} catch (Exception $e) {
    error_log("Payments Admin Error: " . $e->getMessage());
    $errorMessage = "Database error: " . $e->getMessage();
    $payments = [];
    $stats = ['total' => 0, 'total_revenue' => 0, 'pending' => 0, 'completed' => 0, 'failed' => 0, 'trash' => 0];
    $totalPages = 0;
}

// Helper functions
function getStatusColor($status)
{
    $colors = [
        'pending' => '#f59e0b',
        'completed' => '#10b981',
        'failed' => '#ef4444',
        'refunded' => '#6b7280',
        'cancelled' => '#dc2626'
    ];
    return $colors[$status] ?? '#6b7280';
}

function getStatusIcon($status)
{
    $icons = [
        'pending' => 'fa-clock',
        'completed' => 'fa-check-circle',
        'failed' => 'fa-times-circle',
        'refunded' => 'fa-undo',
        'cancelled' => 'fa-ban'
    ];
    return $icons[$status] ?? 'fa-circle';
}

function getAccountStatusColor($accountStatus)
{
    return $accountStatus === 'active' ? '#10b981' : '#ef4444';
}

function formatCurrency($amount)
{
    return '$' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScanQuotient Admin | Payments Management</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../CSS/payment_accounts.css" />
    <style>
        /* Local polish for view/edit/confirm modals */
        .sq-modal .sq-modal-content {
            border: 1px solid rgba(148, 163, 184, 0.18);
            box-shadow: 0 24px 80px rgba(2, 6, 23, 0.35);
            backdrop-filter: blur(10px);
        }

        .sq-dark .sq-modal .sq-modal-content {
            border-color: rgba(148, 163, 184, 0.12);
            box-shadow: 0 28px 90px rgba(0, 0, 0, 0.55);
        }

        /* Modal footer buttons (base .sq-btn is missing in global CSS) */
        .sq-modal .sq-btn {
            appearance: none;
            border: none;
            border-radius: 12px;
            padding: 11px 16px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 42px;
            transition: transform 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease, border-color 0.18s ease,
                color 0.18s ease, opacity 0.18s ease;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }

        .sq-modal .sq-btn:disabled,
        .sq-modal .sq-btn[disabled] {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .sq-modal .sq-btn:focus {
            outline: none;
        }

        .sq-modal .sq-btn:focus-visible {
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.18);
        }

        .sq-dark .sq-modal .sq-btn:focus-visible {
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.28);
        }

        .sq-modal .sq-btn-primary {
            background: linear-gradient(135deg, var(--sq-brand), var(--sq-brand-dark));
            color: #fff;
            box-shadow: 0 12px 24px rgba(59, 130, 246, 0.25);
        }

        .sq-dark .sq-modal .sq-btn-primary {
            box-shadow: 0 12px 24px rgba(139, 92, 246, 0.28);
        }

        .sq-modal .sq-btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 16px 34px rgba(59, 130, 246, 0.32);
        }

        .sq-dark .sq-modal .sq-btn-primary:hover {
            box-shadow: 0 16px 34px rgba(139, 92, 246, 0.34);
        }

        .sq-modal .sq-btn-secondary {
            background: rgba(15, 23, 42, 0.04);
            color: var(--sq-text-main);
            border: 2px solid rgba(148, 163, 184, 0.22);
        }

        .sq-dark .sq-modal .sq-btn-secondary {
            background: rgba(148, 163, 184, 0.08);
            border-color: rgba(148, 163, 184, 0.18);
            color: var(--sq-text-main);
        }

        .sq-modal .sq-btn-secondary:hover {
            transform: translateY(-1px);
            border-color: color-mix(in srgb, var(--sq-brand) 55%, rgba(148, 163, 184, 0.22));
            box-shadow: 0 10px 24px rgba(2, 6, 23, 0.10);
        }

        .sq-modal-content--view .sq-modal-body {
            padding-top: 18px;
        }

        .sq-view-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .sq-view-field {
            padding: 12px 14px;
            border-radius: 14px;
            background: rgba(15, 23, 42, 0.04);
            border: 1px solid rgba(148, 163, 184, 0.18);
        }

        .sq-dark .sq-view-field {
            background: rgba(148, 163, 184, 0.06);
            border-color: rgba(148, 163, 184, 0.14);
        }

        .sq-view-field--full {
            grid-column: 1 / -1;
        }

        .sq-view-label {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            color: var(--sq-text-light);
            margin-bottom: 8px;
        }

        .sq-view-value {
            color: var(--sq-text);
            font-weight: 650;
            line-height: 1.35;
            word-break: break-word;
        }

        .sq-view-value--mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-weight: 700;
            letter-spacing: 0.01em;
        }

        .sq-view-badge {
            --sq-badge-color: #64748b;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            border-radius: 12px;
            background: color-mix(in srgb, var(--sq-badge-color) 14%, transparent);
            color: var(--sq-badge-color);
            border: 1px solid color-mix(in srgb, var(--sq-badge-color) 24%, transparent);
            font-weight: 800;
        }

        .sq-view-muted {
            color: var(--sq-text-light);
            font-style: italic;
            font-weight: 600;
        }

        /* Confirm modal */
        .sq-confirm-modal .sq-modal-content {
            max-width: 520px;
        }

        .sq-confirm-message {
            font-size: 14px;
            line-height: 1.55;
            color: var(--sq-text);
        }

        .sq-confirm-meta {
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            background: rgba(15, 23, 42, 0.04);
            border: 1px solid rgba(148, 163, 184, 0.18);
            color: var(--sq-text-light);
            font-size: 12px;
        }

        .sq-dark .sq-confirm-meta {
            background: rgba(148, 163, 184, 0.06);
            border-color: rgba(148, 163, 184, 0.14);
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


    <!-- Create/Edit Modal -->
    <div class="sq-modal" id="sqPaymentModal">
        <div class="sq-modal-content">
            <div class="sq-modal-header">
                <h3 class="sq-modal-title" id="sqModalTitle">
                    <i class="fas fa-plus-circle"></i> Add Payment
                </h3>
                <button class="sq-modal-close" onclick="sqCloseModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="sqPaymentForm">
                <input type="hidden" name="action" id="sqFormAction" value="create">
                <input type="hidden" name="payment_id" id="sqPaymentId" value="">

                <div class="sq-modal-body">
                    <div class="sq-form-grid">
                        <div class="sq-form-group sq-form-group--full">
                            <label class="sq-form-label">Email Address</label>
                            <input type="email" name="email" id="sqEmail" class="sq-form-input" required
                                placeholder="customer@example.com">
                        </div>

                        <div class="sq-form-group">
                            <label class="sq-form-label">Package</label>
                            <select name="package" id="sqPackage" class="sq-form-select" required>
                                <?php foreach ($validPackages as $pkg): ?>
                                    <option value="<?php echo $pkg; ?>">
                                        <?php echo ucfirst($pkg); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sq-form-group">
                            <label class="sq-form-label">Account Status</label>
                            <select name="account_status" id="sqAccountStatus" class="sq-form-select">
                                <?php foreach ($validAccountStatuses as $as): ?>
                                    <option value="<?php echo $as; ?>">
                                        <?php echo ucfirst($as); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sq-form-group">
                            <label class="sq-form-label">Amount ($)</label>
                            <input type="number" name="amount" id="sqAmount" class="sq-form-input" step="0.01" min="0"
                                required placeholder="99.99">
                        </div>

                        <div class="sq-form-group">
                            <label class="sq-form-label">Status</label>
                            <select name="status" id="sqStatus" class="sq-form-select">
                                <?php foreach ($validStatuses as $s): ?>
                                    <option value="<?php echo $s; ?>">
                                        <?php echo ucfirst($s); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sq-form-group">
                            <label class="sq-form-label">Payment Method</label>
                            <select name="payment_method" id="sqPaymentMethod" class="sq-form-select">
                                <?php foreach ($validPaymentMethods as $m): ?>
                                    <option value="<?php echo $m; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $m)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sq-form-group">
                            <label class="sq-form-label">Transaction ID</label>
                            <input type="text" name="transaction_id" id="sqTransactionId" class="sq-form-input"
                                placeholder="Optional">
                        </div>

                        <div class="sq-form-group">
                            <label class="sq-form-label">Expires At</label>
                            <input type="datetime-local" name="expires_at" id="sqExpiresAt" class="sq-form-input">
                        </div>
                    </div>
                </div>

                <div class="sq-form-footer">
                    <button type="button" class="sq-btn sq-btn-secondary" onclick="sqCloseModal()">Cancel</button>
                    <button type="submit" class="sq-btn sq-btn-primary">
                        <i class="fas fa-save"></i> Save Payment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Modal -->
    <div class="sq-modal" id="sqViewModal">
        <div class="sq-modal-content sq-modal--large sq-modal-content--view">
            <div class="sq-modal-header">
                <h3 class="sq-modal-title">
                    <i class="fas fa-receipt"></i> Payment Details
                </h3>
                <button class="sq-modal-close" onclick="sqCloseViewModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="sq-modal-body" id="sqViewModalBody">
                <!-- Content loaded dynamically -->
            </div>
            <div class="sq-form-footer">
                <button type="button" class="sq-btn sq-btn-secondary" onclick="sqCloseViewModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Confirm Modal (replaces browser confirm()) -->
    <div class="sq-modal sq-confirm-modal" id="sqConfirmModal" aria-hidden="true">
        <div class="sq-modal-content">
            <div class="sq-modal-header">
                <h3 class="sq-modal-title" id="sqConfirmTitle">
                    <i class="fas fa-triangle-exclamation"></i> Confirm action
                </h3>
                <button class="sq-modal-close" type="button" onclick="sqCloseConfirmModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="sq-modal-body">
                <div class="sq-confirm-message" id="sqConfirmMessage">Are you sure?</div>
                <div class="sq-confirm-meta" id="sqConfirmMeta" style="display:none;"></div>
            </div>
            <div class="sq-form-footer">
                <button type="button" class="sq-btn sq-btn-secondary" onclick="sqCloseConfirmModal()">Cancel</button>
                <button type="button" class="sq-btn sq-btn-primary" id="sqConfirmOkBtn">
                    <i class="fas fa-check"></i> Yes, continue
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
                    <i class="fas fa-credit-card"></i>
                </div>
                <div class="sq-stat-content">
                    <h3>
                        <?php echo number_format($stats['total']); ?>
                    </h3>
                    <p>Total Payments</p>
                </div>
            </div>
            <div class="sq-stat-card">
                <div class="sq-stat-icon sq-stat-icon--revenue">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="sq-stat-content">
                    <h3>
                        <?php echo formatCurrency($stats['total_revenue']); ?>
                    </h3>
                    <p>Total Revenue</p>
                </div>
            </div>
            <div class="sq-stat-card">
                <div class="sq-stat-icon sq-stat-icon--pending">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="sq-stat-content">
                    <h3>
                        <?php echo number_format($stats['pending']); ?>
                    </h3>
                    <p>Pending</p>
                </div>
            </div>
            <div class="sq-stat-card">
                <div class="sq-stat-icon sq-stat-icon--completed">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="sq-stat-content">
                    <h3>
                        <?php echo number_format($stats['completed']); ?>
                    </h3>
                    <p>Completed</p>
                </div>
            </div>
            <div class="sq-stat-card">
                <div class="sq-stat-icon sq-stat-icon--failed">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="sq-stat-content">
                    <h3>
                        <?php echo number_format($stats['failed']); ?>
                    </h3>
                    <p>Failed</p>
                </div>
            </div>
            <div class="sq-stat-card">
                <div class="sq-stat-icon sq-stat-icon--trash">
                    <i class="fas fa-trash"></i>
                </div>
                <div class="sq-stat-content">
                    <h3>
                        <?php echo number_format($stats['trash']); ?>
                    </h3>
                    <p>In Trash</p>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="sq-controls-section">
            <form method="GET" class="sq-controls-grid">
                <div class="sq-filters-group">
                    <div class="sq-filter-item">
                        <label class="sq-filter-label">View</label>
                        <select name="view" class="sq-filter-select" onchange="this.form.submit()">
                            <option value="active" <?php echo $view === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="trash" <?php echo $view === 'trash' ? 'selected' : ''; ?>>Trash</option>
                            <option value="all" <?php echo $view === 'all' ? 'selected' : ''; ?>>All</option>
                        </select>
                    </div>

                    <div class="sq-filter-item">
                        <label class="sq-filter-label">Payment Status</label>
                        <select name="status" class="sq-filter-select" onchange="this.form.submit()">
                            <option value="all">All Status</option>
                            <?php foreach ($validStatuses as $s): ?>
                                <option value="<?php echo $s; ?>" <?php echo $status === $s ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($s); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sq-filter-item">
                        <label class="sq-filter-label">Account Status</label>
                        <select name="account_status" class="sq-filter-select" onchange="this.form.submit()">
                            <option value="all">All Account Status</option>
                            <?php foreach ($validAccountStatuses as $as): ?>
                                <option value="<?php echo $as; ?>" <?php echo $accountStatusFilter === $as ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($as); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sq-filter-item sq-search-box">
                        <label class="sq-filter-label">Search</label>
                        <input type="text" name="search" class="sq-filter-input sq-search-input"
                            placeholder="Email, package, transaction..."
                            value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <button type="submit" class="sq-filter-btn sq-btn-primary" style="height: 42px;">
                        <i class="fas fa-filter"></i> Filter
                    </button>

                    <a href="?" class="sq-filter-btn sq-btn-secondary" style="height: 42px;">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Bulk Actions -->
        <form method="POST" id="sqBulkForm">
            <input type="hidden" name="action" value="bulk_action">
            <div class="sq-bulk-section" id="sqBulkSection">
                <input type="checkbox" class="sq-checkbox" id="sqSelectAllBulk" title="Select All">
                <select name="bulk_action" class="sq-filter-select" style="min-width: 150px;"
                    onchange="sqHandleBulkAction(this)">
                    <option value="">Bulk Actions</option>
                    <?php if ($view !== 'trash'): ?>
                        <option value="update_status">Change Status</option>
                        <option value="soft_delete">Move to Trash</option>
                    <?php else: ?>
                        <option value="restore">Restore</option>
                        <option value="permanent_delete">Delete Forever</option>
                    <?php endif; ?>
                </select>
                <select name="bulk_status" class="sq-filter-select" id="sqBulkStatus"
                    style="display: none; min-width: 150px;">
                    <?php foreach ($validStatuses as $s): ?>
                        <option value="<?php echo $s; ?>">
                            <?php echo ucfirst($s); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="sq-filter-btn sq-btn-primary"
                    data-sq-confirm="Apply this action to selected items?" data-sq-confirm-title="Confirm bulk action">
                    <i class="fas fa-check"></i> Apply
                </button>
            </div>

            <!-- Data Table -->
            <div class="sq-table-container">
                <?php if (empty($payments)): ?>
                    <div class="sq-empty-state">
                        <div class="sq-empty-icon">
                            <i class="fas fa-credit-card"></i>
                        </div>
                        <h3 class="sq-empty-title">No payments found</h3>
                        <p class="sq-empty-text">
                            <?php echo !empty($search) ? 'Try adjusting your search criteria.' : 'No payment records available.'; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <table class="sq-data-table">
                        <thead>
                            <tr>
                                <th width="40"><input type="checkbox" class="sq-checkbox" id="sqSelectAll"></th>
                                <th>ID</th>
                                <th>Customer</th>
                                <th>Package</th>
                                <th>Account</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Method</th>
                                <th>Transaction</th>
                                <th>Created</th>
                                <th>Expires</th>
                                <th width="140">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment):
                                $statusColor = getStatusColor($payment['status']);
                                $statusIcon = getStatusIcon($payment['status']);
                                $accountStatusColor = getAccountStatusColor($payment['account_status']);
                                ?>
                                <tr class="<?php echo $payment['deleted_at'] ? 'deleted' : ''; ?>"
                                    data-id="<?php echo $payment['id']; ?>">
                                    <td>
                                        <input type="checkbox" class="sq-checkbox sq-item-checkbox" name="selected_ids[]"
                                            value="<?php echo $payment['id']; ?>" onchange="sqUpdateBulkVisibility()">
                                    </td>
                                    <td>#
                                        <?php echo $payment['id']; ?>
                                    </td>
                                    <td>
                                        <div class="sq-user-info">
                                            <?php if (!empty($payment['first_name'])): ?>
                                                <span class="sq-user-name">
                                                    <?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['surname']); ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="sq-user-email">
                                                <?php echo htmlspecialchars($payment['email']); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="sq-package">
                                            <i class="fas fa-box"></i>
                                            <?php echo htmlspecialchars($payment['package']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="sq-account-status" style="color: <?php echo $accountStatusColor; ?>;">
                                            <i class="fas fa-circle" style="font-size: 8px; margin-right: 4px;"></i>
                                            <?php echo ucfirst($payment['account_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="sq-amount" style="color: <?php echo $statusColor; ?>">
                                            <?php echo formatCurrency($payment['amount']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="sq-status-badge"
                                            style="background: <?php echo $statusColor; ?>20; color: <?php echo $statusColor; ?>;">
                                            <i class="fas <?php echo $statusIcon; ?>"></i>
                                            <?php echo ucfirst($payment['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="sq-payment-method">
                                            <?php
                                            $pm = $payment['payment_method'];
                                            if ($pm === 'paypal') {
                                                echo '<i class="fab fa-paypal" aria-hidden="true"></i>';
                                            } elseif ($pm === 'stripe') {
                                                echo '<i class="fab fa-cc-stripe" aria-hidden="true"></i>';
                                            } else {
                                                echo '<i class="fas fa-money-bill" aria-hidden="true"></i>';
                                            }
                                            ?>
                                            <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($payment['transaction_id']): ?>
                                            <span class="sq-transaction-id"
                                                title="<?php echo htmlspecialchars($payment['transaction_id']); ?>">
                                                <?php echo htmlspecialchars($payment['transaction_id']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--sq-text-light);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="sq-date-cell">
                                            <span class="sq-date-primary">
                                                <?php echo date('M j, Y', strtotime($payment['created_at'])); ?>
                                            </span>
                                            <span class="sq-date-secondary">
                                                <?php echo date('g:i A', strtotime($payment['created_at'])); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($payment['expires_at']): ?>
                                            <div class="sq-date-cell">
                                                <span class="sq-date-primary">
                                                    <?php echo date('M j, Y', strtotime($payment['expires_at'])); ?>
                                                </span>
                                                <span class="sq-date-secondary">
                                                    <?php echo date('g:i A', strtotime($payment['expires_at'])); ?>
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--sq-text-light);">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="sq-actions">
                                            <button type="button" class="sq-action-btn sq-action-view"
                                                onclick="sqViewPayment(<?php echo htmlspecialchars(json_encode($payment)); ?>)"
                                                title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>

                                            <?php if ($payment['deleted_at']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="restore">
                                                    <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                                    <button type="submit" class="sq-action-btn sq-action-restore" title="Restore">
                                                        <i class="fas fa-undo"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" style="display: inline;"
                                                    data-sq-confirm="Permanently delete this payment? This cannot be undone."
                                                    data-sq-confirm-title="Delete forever">
                                                    <input type="hidden" name="action" value="permanent_delete">
                                                    <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                                    <button type="submit" class="sq-action-btn sq-action-delete"
                                                        title="Delete Forever">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button type="button" class="sq-action-btn sq-action-edit"
                                                    onclick="sqEditPayment(<?php echo htmlspecialchars(json_encode($payment)); ?>)"
                                                    title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" style="display: inline;"
                                                    data-sq-confirm="Move this payment to trash?"
                                                    data-sq-confirm-title="Move to trash">
                                                    <input type="hidden" name="action" value="soft_delete">
                                                    <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
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

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="sq-pagination">
                            <?php
                            $queryParams = "view=$view&status=$status&account_status=$accountStatusFilter&search=" . urlencode($search);

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
        </form>

        <footer class="sq-admin-footer">
            <p>ScanQuotient Security Platform • Quantifying Risk. Strengthening Security.</p>
            <p style="margin-top: 8px; font-size: 12px;">
                Logged in as
                <?php echo htmlspecialchars($adminName); ?> •
                <a href="/ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Frontend/login_page_site.php"
                    style="color: var(--sq-brand); text-decoration: none;">Logout</a>
            </p>
        </footer>
    </main>

    <!-- Floating Add Button -->
    <button class="sq-add-btn" onclick="sqOpenCreateModal()" title="Add New Payment">
        <i class="fas fa-plus"></i>
    </button>

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

        // Modal Management
        const sqPaymentModal = document.getElementById('sqPaymentModal');
        const sqViewModal = document.getElementById('sqViewModal');
        const sqModalTitle = document.getElementById('sqModalTitle');
        const sqFormAction = document.getElementById('sqFormAction');
        const sqPaymentId = document.getElementById('sqPaymentId');

        function sqOpenCreateModal() {
            sqFormAction.value = 'create';
            sqPaymentId.value = '';
            document.getElementById('sqPaymentForm').reset();
            sqModalTitle.innerHTML = '<i class="fas fa-plus-circle"></i> Add Payment';
            sqPaymentModal.classList.add('sq-modal--active');
            sqBody.style.overflow = 'hidden';
        }

        function sqEditPayment(payment) {
            sqFormAction.value = 'update';
            sqPaymentId.value = payment.id;

            document.getElementById('sqEmail').value = payment.email;
            document.getElementById('sqPackage').value = payment.package;
            document.getElementById('sqAccountStatus').value = payment.account_status || 'active';
            document.getElementById('sqAmount').value = payment.amount;
            document.getElementById('sqStatus').value = payment.status;
            document.getElementById('sqPaymentMethod').value = payment.payment_method;
            document.getElementById('sqTransactionId').value = payment.transaction_id || '';
            document.getElementById('sqExpiresAt').value = payment.expires_at ? payment.expires_at.slice(0, 16) : '';

            sqModalTitle.innerHTML = '<i class="fas fa-edit"></i> Edit Payment #' + payment.id;
            sqPaymentModal.classList.add('sq-modal--active');
            sqBody.style.overflow = 'hidden';
        }

        function sqCloseModal() {
            sqPaymentModal.classList.remove('sq-modal--active');
            sqBody.style.overflow = '';
        }

        function sqViewPayment(payment) {
            const statusColor = getStatusColor(payment.status);
            const statusIcon = getStatusIcon(payment.status);
            const accountStatusColor = getAccountStatusColor(payment.account_status);

            document.getElementById('sqViewModalBody').innerHTML = `
                <div class="sq-view-grid">
                    <div class="sq-view-field">
                        <div class="sq-view-label">Payment ID</div>
                        <div class="sq-view-value sq-view-value--mono">#${payment.id}</div>
                    </div>

                    <div class="sq-view-field">
                        <div class="sq-view-label">Payment status</div>
                        <div class="sq-view-badge" style="--sq-badge-color:${statusColor}">
                            <i class="fas ${statusIcon}"></i>
                            ${payment.status.charAt(0).toUpperCase() + payment.status.slice(1)}
                        </div>
                    </div>

                    <div class="sq-view-field sq-view-field--full">
                        <div class="sq-view-label">Customer email</div>
                        <div class="sq-view-value">${escapeHtml(payment.email)}</div>
                    </div>

                    <div class="sq-view-field">
                        <div class="sq-view-label">Package</div>
                        <div class="sq-view-value" style="text-transform: capitalize;">${escapeHtml(payment.package)}</div>
                    </div>

                    <div class="sq-view-field">
                        <div class="sq-view-label">Account status</div>
                        <div class="sq-view-badge" style="--sq-badge-color:${accountStatusColor}">
                            <i class="fas fa-circle" style="font-size: 8px;"></i>
                            ${(payment.account_status || 'active').charAt(0).toUpperCase() + (payment.account_status || 'active').slice(1)}
                        </div>
                    </div>

                    <div class="sq-view-field">
                        <div class="sq-view-label">Amount</div>
                        <div class="sq-view-value sq-view-value--mono" style="color:${statusColor}">$${parseFloat(payment.amount).toFixed(2)}</div>
                    </div>

                    <div class="sq-view-field">
                        <div class="sq-view-label">Payment method</div>
                        <div class="sq-view-value" style="text-transform: capitalize;">${escapeHtml(payment.payment_method.replace('_', ' '))}</div>
                    </div>

                    <div class="sq-view-field">
                        <div class="sq-view-label">Transaction ID</div>
                        <div class="sq-view-value sq-view-value--mono">
                            ${payment.transaction_id ? escapeHtml(payment.transaction_id) : '<span class="sq-view-muted">Not provided</span>'}
                        </div>
                    </div>

                    <div class="sq-view-field">
                        <div class="sq-view-label">Created at</div>
                        <div class="sq-view-value">${formatDateTime(payment.created_at)}</div>
                    </div>

                    <div class="sq-view-field">
                        <div class="sq-view-label">Expires at</div>
                        <div class="sq-view-value">${payment.expires_at ? formatDateTime(payment.expires_at) : '<span class="sq-view-muted">Never</span>'}</div>
                    </div>

                    ${payment.deleted_at ? `
                    <div class="sq-view-field sq-view-field--full" style="border-color: color-mix(in srgb, var(--sq-danger) 35%, transparent);">
                        <div class="sq-view-label" style="color: var(--sq-danger);">Deleted information</div>
                        <div class="sq-view-value">
                            <span class="sq-view-badge" style="--sq-badge-color: var(--sq-danger)">
                                <i class="fas fa-trash"></i>
                                Deleted by ${escapeHtml(payment.deleted_by)} on ${formatDateTime(payment.deleted_at)}
                            </span>
                        </div>
                    </div>
                    ` : ''}
                </div>
            `;

            sqViewModal.classList.add('sq-modal--active');
            sqBody.style.overflow = 'hidden';
        }

        function sqCloseViewModal() {
            sqViewModal.classList.remove('sq-modal--active');
            sqBody.style.overflow = '';
        }

        // Close modals on outside click
        sqPaymentModal?.addEventListener('click', (e) => {
            if (e.target === sqPaymentModal) sqCloseModal();
        });

        sqViewModal?.addEventListener('click', (e) => {
            if (e.target === sqViewModal) sqCloseViewModal();
        });

        // Escape key to close modals
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (sqPaymentModal.classList.contains('sq-modal--active')) sqCloseModal();
                if (sqViewModal.classList.contains('sq-modal--active')) sqCloseViewModal();
                if (sqConfirmModal?.classList.contains('sq-modal--active')) sqCloseConfirmModal();
            }
        });

        // Confirm modal (replaces native browser confirm)
        const sqConfirmModal = document.getElementById('sqConfirmModal');
        const sqConfirmOkBtn = document.getElementById('sqConfirmOkBtn');
        const sqConfirmMessage = document.getElementById('sqConfirmMessage');
        const sqConfirmTitle = document.getElementById('sqConfirmTitle');
        const sqConfirmMeta = document.getElementById('sqConfirmMeta');
        let sqPendingConfirmForm = null;

        function sqOpenConfirmModal({ title, message, meta, onConfirmForm }) {
            sqConfirmTitle.innerHTML = `<i class="fas fa-triangle-exclamation"></i> ${escapeHtml(title || 'Confirm action')}`;
            sqConfirmMessage.textContent = message || 'Are you sure?';

            if (meta) {
                sqConfirmMeta.style.display = '';
                sqConfirmMeta.textContent = meta;
            } else {
                sqConfirmMeta.style.display = 'none';
                sqConfirmMeta.textContent = '';
            }

            sqPendingConfirmForm = onConfirmForm || null;
            sqConfirmModal.classList.add('sq-modal--active');
            sqBody.style.overflow = 'hidden';
        }

        function sqCloseConfirmModal() {
            sqConfirmModal.classList.remove('sq-modal--active');
            sqPendingConfirmForm = null;
            sqBody.style.overflow = '';
        }

        sqConfirmModal?.addEventListener('click', (e) => {
            if (e.target === sqConfirmModal) sqCloseConfirmModal();
        });

        sqConfirmOkBtn?.addEventListener('click', () => {
            const form = sqPendingConfirmForm;
            sqCloseConfirmModal();
            if (form) form.submit();
        });

        // Wire up any form/button with data-sq-confirm
        document.addEventListener('submit', (e) => {
            const form = e.target;
            if (!(form instanceof HTMLFormElement)) return;

            const message = form.getAttribute('data-sq-confirm');
            if (!message) return;

            e.preventDefault();
            const title = form.getAttribute('data-sq-confirm-title') || 'Confirm action';
            const action = (form.querySelector('input[name="action"]')?.value || '').trim();
            const pid = (form.querySelector('input[name="payment_id"]')?.value || '').trim();
            const meta = pid ? `Payment #${pid} • action: ${action || 'submit'}` : (action ? `action: ${action}` : '');

            sqOpenConfirmModal({ title, message, meta, onConfirmForm: form });
        }, true);

        document.addEventListener('click', (e) => {
            const btn = e.target?.closest?.('[data-sq-confirm]');
            if (!btn) return;

            const form = btn.closest('form');
            if (!form) return;

            if (!form.getAttribute('data-sq-confirm')) {
                form.setAttribute('data-sq-confirm', btn.getAttribute('data-sq-confirm'));
            }
            if (!form.getAttribute('data-sq-confirm-title') && btn.getAttribute('data-sq-confirm-title')) {
                form.setAttribute('data-sq-confirm-title', btn.getAttribute('data-sq-confirm-title'));
            }
        }, true);

        // Select All Checkboxes
        const sqSelectAll = document.getElementById('sqSelectAll');
        const sqSelectAllBulk = document.getElementById('sqSelectAllBulk');
        const sqItemCheckboxes = document.querySelectorAll('.sq-item-checkbox');
        const sqBulkSection = document.getElementById('sqBulkSection');

        function sqUpdateBulkVisibility() {
            const checked = document.querySelectorAll('.sq-item-checkbox:checked').length > 0;
            sqBulkSection.classList.toggle('active', checked);
        }

        sqSelectAll?.addEventListener('change', (e) => {
            sqItemCheckboxes.forEach(cb => cb.checked = e.target.checked);
            sqSelectAllBulk.checked = e.target.checked;
            sqUpdateBulkVisibility();
        });

        sqSelectAllBulk?.addEventListener('change', (e) => {
            sqItemCheckboxes.forEach(cb => cb.checked = e.target.checked);
            sqSelectAll.checked = e.target.checked;
            sqUpdateBulkVisibility();
        });

        sqItemCheckboxes.forEach(cb => {
            cb.addEventListener('change', sqUpdateBulkVisibility);
        });

        // Bulk Action Handler
        function sqHandleBulkAction(select) {
            const statusSelect = document.getElementById('sqBulkStatus');
            if (select.value === 'update_status') {
                statusSelect.style.display = 'block';
            } else {
                statusSelect.style.display = 'none';
            }
        }

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
                minute: '2-digit'
            });
        }

        function getStatusColor(status) {
            const colors = {
                'pending': '#f59e0b',
                'completed': '#10b981',
                'failed': '#ef4444',
                'refunded': '#6b7280',
                'cancelled': '#dc2626'
            };
            return colors[status] || '#6b7280';
        }

        function getStatusIcon(status) {
            const icons = {
                'pending': 'fa-clock',
                'completed': 'fa-check-circle',
                'failed': 'fa-times-circle',
                'refunded': 'fa-undo',
                'cancelled': 'fa-ban'
            };
            return icons[status] || 'fa-circle';
        }

        function getAccountStatusColor(accountStatus) {
            return accountStatus === 'active' ? '#10b981' : '#ef4444';
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