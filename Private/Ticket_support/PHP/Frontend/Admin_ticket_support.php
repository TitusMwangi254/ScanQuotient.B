<?php
session_start();

// 🚫 Prevent browser from caching this page
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header("Location: ../../../../Public/Login_page/PHP/Frontend/Login_page_site.php?error=not_authenticated");
    exit();
}

$allowed_roles = ['user', 'admin', 'super_admin'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: ../../../../Public/Login_page/PHP/Frontend/Login_page_site.php");
    exit();
}

$user_name = $_SESSION['user_name'] ?? 'User';
$userRole = $_SESSION['role'] ?? 'user';
$profile_photo = $_SESSION['profile_photo'] ?? null;
if (!empty($profile_photo)) {
    $photo_path = ltrim((string) $profile_photo, '/');
    $base_url = '/ScanQuotient.v2/ScanQuotient.B';
    $avatar_url = $base_url . '/' . $photo_path;
} else {
    $avatar_url = '/ScanQuotient.v2/ScanQuotient.B/Storage/Public_images/default-avatar.png';
}
$_SESSION['LAST_ACTIVITY'] = time(); // update activity timestamp

// --- DB Connection ---
$servername = '127.0.0.1';
$dbname = 'scanquotient.a1';
$dbuser = 'root';
$dbpass = '';

$conn = new mysqli($servername, $dbuser, $dbpass, $dbname);
if ($conn->connect_error) {
    die("DB connection failed: " . $conn->connect_error);
}

// Ticket visibility picker: active, deleted, or all
$viewFilter = $_GET['view'] ?? 'active';
$allowedViews = ['active', 'deleted', 'all'];
if (!in_array($viewFilter, $allowedViews, true)) {
    $viewFilter = 'active';
}
$isDeletedView = ($viewFilter === 'deleted');

$whereClause = "deleted_at IS NULL";
if ($viewFilter === 'deleted') {
    $whereClause = "deleted_at IS NOT NULL";
} elseif ($viewFilter === 'all') {
    $whereClause = "1=1";
}

$sql = "SELECT id, unique_id, email, category, priority, status, created_at, deleted_at
         FROM support_tickets
         WHERE {$whereClause}
         ORDER BY created_at DESC";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Tickets | ScanQuotient</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.8.2/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../../CSS/admin_ticket_support.css" />

</head>

<body>

    <!-- STANDARDIZED HEADER (Matches admin_customer_feedback.php) -->
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
                <span><?php echo htmlspecialchars($user_name); ?></span>
            </div>
            <button class="sq-admin-theme-toggle" id="sqThemeToggle" title="Toggle Theme">
                <i class="fas fa-sun"></i>
            </button>
            <a href="../../../../Private/Admin_dashboard/PHP/Frontend/Admin_dashboard.php" class="icon-btn"
                title="Home">
                <i class="fas fa-home"></i>
            </a>
            <a href="#" id="helpBtn" class="icon-btn" title="Help">
                <i class="fas fa-question-circle"></i>
            </a>
            <a href="../../../../Public/Login_page/PHP/Frontend/Login_page_site.php" class="icon-btn" title="Logout">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </header>

    <!-- Help Modal -->
    <div id="helpModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-question-circle"></i> About This Page</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <p>This is the admin ticket support page. Here you can:</p>
                <ul style="margin-left: 20px; margin-bottom: 12px;">
                    <li>View all support tickets submitted by users</li>
                    <li>Search and filter tickets by status</li>
                    <li>Click on any row to view full ticket details</li>
                    <li>Manage ticket workflow (Open → In Progress → Resolved → Closed)</li>
                </ul>
                <p><strong>Note:</strong> Mass delete is disabled to prevent accidental data loss. Use individual ticket
                    details to delete records.</p>
            </div>
        </div>
    </div>

    <!-- MAIN CONTAINER -->
    <main class="sq-admin-container">

        <h2><i class="fas fa-ticket-alt" style="margin-right: 10px; color: var(--sq-brand);"></i>Support Tickets</h2>

        <!-- Controls Section (Styled like feedback page) -->
        <div class="sq-controls-section">
            <div class="sq-filter-group">
                <div class="sq-search-box">
                    <i class="fas fa-search sq-search-icon"></i>
                    <input type="text" id="search" class="sq-search-input" placeholder="Search tickets...">
                </div>

                <select id="statusFilter" class="sq-select-dropdown">
                    <option value="open" <?php echo $viewFilter === 'active' ? 'selected' : ''; ?>>Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="resolved">Resolved</option>
                    <option value="closed">Closed</option>
                    <option value="all" <?php echo $viewFilter !== 'active' ? 'selected' : ''; ?>>All Status</option>
                </select>

                <select id="viewFilter" class="sq-select-dropdown" onchange="changeTicketView(this.value)">
                    <option value="active" <?php echo $viewFilter === 'active' ? 'selected' : ''; ?>>Active Tickets</option>
                    <option value="deleted" <?php echo $viewFilter === 'deleted' ? 'selected' : ''; ?>>Deleted Tickets</option>
                    <option value="all" <?php echo $viewFilter === 'all' ? 'selected' : ''; ?>>All Tickets</option>
                </select>

                <button type="button" class="sq-btn sq-btn-secondary" onclick="clearFilters()">
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>

            <button type="button" class="sq-btn sq-btn-danger" disabled
                title="Mass delete is not permitted to prevent accidental or unauthorized data loss. Click on individual record details to delete a record.">
                <i class="fas fa-trash"></i> Delete
            </button>
        </div>

        <!-- Table Container -->
        <div class="sq-table-container">
            <table class="sq-data-table" id="tickets-table">
                <thead>
                    <tr>
                        <th width="60">No</th>
                        <th width="80">ID</th>
                        <th>Unique ID</th>
                        <th>Email</th>
                        <th>Category</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th id="sqMatchedInHeader" style="display:none;">Matched In</th>
                        <th>Created At</th>
                        <?php if ($isDeletedView): ?>
                            <th>Deleted At</th>
                            <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()):
                            $detailUrl = "../../../../Private/Ticket_support/PHP/Backend/ticket_details.php?unique_id=" . urlencode($row['unique_id']);
                            $isDeletedTicket = !empty($row['deleted_at']);
                            $rowClass = $isDeletedTicket ? 'ticket-row ticket-row--deleted' : 'clickable-row ticket-row';
                            ?>
                            <!-- FULL ROW CLICKABLE - data-href attribute stores the URL -->
                            <tr <?php echo !$isDeletedTicket ? 'data-href="' . htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>
                                data-id="<?php echo $row['unique_id']; ?>" data-status="<?php echo $row['status']; ?>"
                                data-deleted="<?php echo $isDeletedTicket ? '1' : '0'; ?>" class="<?php echo $rowClass; ?>">
                                <td class="row-number"></td>
                                <td><?php echo $row['id']; ?></td>
                                <td>
                                    <span class="unique-link"><?php echo $row['unique_id']; ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($row['email']); ?></td>
                                <td><?php echo ucfirst($row['category']); ?></td>
                                <td>
                                    <span class="sq-status-badge sq-priority-<?php echo $row['priority']; ?>">
                                        <?php echo ucfirst($row['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="sq-status-badge sq-status-<?php echo $row['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $row['status'])); ?>
                                    </span>
                                </td>
                                <td class="sq-matched-in-cell" style="display:none;"></td>
                                <td><?php echo date('M j, Y g:i A', strtotime($row['created_at'])); ?></td>
                                <?php if ($isDeletedView): ?>
                                    <td><?php echo !empty($row['deleted_at']) ? date('M j, Y g:i A', strtotime($row['deleted_at'])) : '—'; ?></td>
                                    <td>
                                        <div class="ticket-actions-wrap">
                                            <button type="button" class="sq-btn sq-btn-secondary ticket-action-btn ticket-action-btn--restore"
                                                onclick="event.stopPropagation(); restoreTicket('<?php echo htmlspecialchars($row['unique_id'], ENT_QUOTES, 'UTF-8'); ?>', this)">
                                                <i class="fas fa-undo"></i> Restore
                                            </button>
                                            <button type="button" class="sq-btn sq-btn-danger ticket-action-btn ticket-action-btn--danger"
                                                onclick="event.stopPropagation(); permanentDeleteTicket('<?php echo htmlspecialchars($row['unique_id'], ENT_QUOTES, 'UTF-8'); ?>', this)">
                                                <i class="fas fa-trash-alt"></i> Delete Forever
                                            </button>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $isDeletedView ? '11' : '9'; ?>" class="sq-empty-state">
                                <div class="sq-empty-icon">
                                    <i class="fas fa-inbox"></i>
                                </div>
                                <h3 class="sq-empty-title">No tickets found</h3>
                                <p class="sq-empty-text">There are no support tickets in the system.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <div class="sq-pagination">
                <div class="pagination-info">
                    <label>
                        Show
                        <select id="rowsPerPage" class="rows-per-page">
                            <option value="5">5</option>
                            <option value="10" selected>10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="200">200</option>
                            <option value="all">All</option>
                        </select>
                        entries
                    </label>
                    <span id="recordInfo">Showing 0–0 of 0</span>
                </div>
                <div style="display: flex; gap: 8px;">
                    <button id="prevPage" class="sq-page-btn"><i class="fas fa-chevron-left"></i></button>
                    <button id="nextPage" class="sq-page-btn"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
        </div>

    </main>

    <!-- Fallback modal (used when SweetAlert is unavailable) -->
    <div id="sqFallbackModal" class="sq-fallback-modal" style="display:none;">
        <div class="sq-fallback-modal__dialog">
            <div class="sq-fallback-modal__icon" id="sqFallbackModalIcon">
                <i class="fas fa-question-circle"></i>
            </div>
            <h3 class="sq-fallback-modal__title" id="sqFallbackModalTitle">Confirm action</h3>
            <p class="sq-fallback-modal__text" id="sqFallbackModalText">Are you sure you want to continue?</p>
            <div class="sq-fallback-modal__actions" id="sqFallbackModalActions">
                <button type="button" class="sq-btn sq-btn-secondary" id="sqFallbackModalCancel">Cancel</button>
                <button type="button" class="sq-btn sq-btn-primary" id="sqFallbackModalConfirm">Confirm</button>
            </div>
        </div>
    </div>

    <!-- STANDARDIZED FOOTER (Matches admin_customer_feedback.php) -->
    <footer class="sq-admin-footer">
        <p>ScanQuotient Security Platform • Quantifying Risk. Strengthening Security.</p>
        <p>
            Logged in as <?php echo htmlspecialchars($user_name); ?> •
            <a href="../../../../Public/Login_page/PHP/Frontend/Login_page_site.php">Logout</a>
        </p>
        <p style="margin-top: 8px; font-size: 12px;">
            <a href="mailto:elevateecomai@gmail.com?subject=Support%20Request">info@ScanQuotientsupport.com</a>
        </p>
    </footer>

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

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.8.2/dist/sweetalert2.all.min.js"></script>
    <script src="../../Javascript/admin_ticket_support.js" defer></script>
</body>

</html>
<?php $conn->close(); ?>