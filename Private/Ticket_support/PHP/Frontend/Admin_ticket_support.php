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

// Fetch all tickets
$sql = "SELECT id, unique_id, email, category, priority, status, created_at 
         FROM SUPPORT_TICKETS 
         WHERE deleted_at IS NULL
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
                    <option value="open" selected>Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="resolved">Resolved</option>
                    <option value="closed">Closed</option>
                    <option value="all">All Status</option>
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
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()):
                            $detailUrl = "../../../../Private/Ticket_support/PHP/Backend/ticket_details.php?unique_id=" . urlencode($row['unique_id']);
                            ?>
                            <!-- FULL ROW CLICKABLE - data-href attribute stores the URL -->
                            <tr data-href="<?php echo htmlspecialchars($detailUrl); ?>"
                                data-id="<?php echo $row['unique_id']; ?>" data-status="<?php echo $row['status']; ?>"
                                class="clickable-row">
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
                                <td><?php echo date('M j, Y g:i A', strtotime($row['created_at'])); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="sq-empty-state">
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
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
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

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.8.2/dist/sweetalert2.all.min.js"></script>
    <script src="../../Javascript/admin_ticket_support.js" defer></script>
</body>

</html>
<?php $conn->close(); ?>