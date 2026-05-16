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
$_SESSION['LAST_ACTIVITY'] = time();

/* --- DB Connection --- */
$servername = '127.0.0.1';
$dbname = 'scanquotient.a1';
$dbuser = 'root';
$dbpass = '';

require_once __DIR__ . '/../../../../Public/Help_center/PHP/Backend/ticket_attachment_helpers.php';

$conn = new mysqli($servername, $dbuser, $dbpass, $dbname);
if ($conn->connect_error) {
    die("DB connection failed: " . $conn->connect_error);
}
sq_ticket_apply_db_timezone($conn);

/* --- Validate input --- */
if (!isset($_GET['unique_id']) || $_GET['unique_id'] === '') {
    die("No ticket reference provided.");
}
$uniqueId = $_GET['unique_id'];

/* --- Fetch ticket --- */
$sql = "SELECT * FROM support_tickets WHERE unique_id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt)
    die("SQL error: " . $conn->error);
$stmt->bind_param("s", $uniqueId);
$stmt->execute();
$result = $stmt->get_result();
$ticket = $result->fetch_assoc();
if (!$ticket)
    die("Ticket not found.");
$stmt->close();
$conn->close();

// FIXED: Correct base URL for ticket attachments
// Files are stored at: C:\xampp\htdocs\ScanQuotient.v2\ScanQuotient.B\Storage\Ticket_attachments
// Web accessible path: /ScanQuotient.v2/ScanQuotient.B/Storage/Ticket_attachments
$attachmentsBaseUrl = "/ScanQuotient.v2/ScanQuotient.B/Storage/Ticket_attachments";

function esc($v)
{
    return htmlspecialchars((string) $v ?? '', ENT_QUOTES, 'UTF-8');
}
function showVal($v, $fallback = '—')
{
    return ($v === null || $v === '') ? $fallback : esc($v);
}

$conversationThread = sq_get_ticket_conversation_thread($ticket);
$initialMessage = trim((string) ($ticket['message'] ?? ''));
if ($initialMessage !== '') {
    $conversationThread = array_values(array_filter(
        $conversationThread,
        static fn(array $entry): bool => !(
            ($entry['role'] ?? '') === 'user'
            && trim((string) ($entry['text'] ?? '')) === $initialMessage
        )
    ));
}

$resolutionText = trim((string) ($ticket['answer'] ?? ''));
$hasResolution = $resolutionText !== '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Resolution | ScanQuotient
        <?php echo esc($ticket['unique_id']); ?>
    </title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../../CSS/ticket_details.css" />

</head>

<body>

    <!-- STANDARDIZED HEADER -->
    <header class="sq-admin-header">
        <div class="sq-admin-header-left">
            <a href="../../PHP/Frontend/Admin_ticket_support.php" class="sq-admin-back-btn">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div class="brand-wrapper">
                <a href="#" class="sq-admin-brand">ScanQuotient</a>
                <p class="sq-admin-tagline">Quantifying Risk. Strengthening Security.</p>
            </div>
        </div>
        <div class="sq-admin-header-right">
            <img src="<?php echo htmlspecialchars($avatar_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile" class="header-profile-photo" style="width:38px;height:38px;min-width:38px;min-height:38px;max-width:38px;max-height:38px;object-fit:cover;border-radius:50%;display:block;flex:0 0 38px;">
            <div class="sq-admin-user">
                <i class="fas fa-user-shield"></i>
                    <span>
                        <?php echo htmlspecialchars($user_name); ?>
                    </span>
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
    <div id="helpModal" class="help-modal">
        <div class="help-content">
            <div class="help-header">
                <h3 class="help-title"><i class="fas fa-question-circle"></i> About This Page</h3>
                <button class="help-close" onclick="closeHelpModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="help-body">
                <p>This is the ticket details resolution page. Here you can:</p>
                <ul>
                    <li>View complete ticket information in the left sidebar</li>
                    <li>Add a resolution, or use <strong>Edit resolution</strong> to change an existing one</li>
                    <li>Reply to user messages and view conversation history</li>
                    <li>Change ticket status (Open, In Progress, Resolved, Closed)</li>
                    <li>Delete tickets permanently (use with caution)</li>
                </ul>
                <p><strong>Note:</strong> All actions are logged and changes cannot be undone.</p>
            </div>
        </div>
    </div>

    <!-- MAIN CONTAINER -->
    <main class="sq-admin-container">

        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-ticket-alt"></i>
                Ticket Resolution
                <span class="ticket-id-badge">
                    <?php echo esc($ticket['unique_id']); ?>
                </span>
            </h1>
            <a href="../../PHP/Frontend/Admin_ticket_support.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Tickets
            </a>
        </div>

        <!-- DASHBOARD GRID LAYOUT -->
        <div class="dashboard-grid">

            <!-- LEFT SIDEBAR: Ticket Information -->
            <aside class="sidebar-card">
                <div class="card-header">
                    <div class="card-icon info">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <h2 class="card-title">Ticket Info</h2>
                </div>

                <div class="detail-item">
                    <div class="detail-label">ID</div>
                    <div class="detail-value">#
                        <?php echo showVal($ticket['id']); ?>
                    </div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">Status</div>
                    <div class="detail-value">
                        <span class="status-badge status-<?php echo $ticket['status']; ?>" id="status-badge">
                            <i class="fas fa-circle" style="font-size: 8px;"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                        </span>
                    </div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">Priority</div>
                    <div class="detail-value">
                        <span class="status-badge priority-<?php echo $ticket['priority']; ?>">
                            <?php echo ucfirst($ticket['priority']); ?>
                        </span>
                    </div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">Category</div>
                    <div class="detail-value">
                        <?php echo ucfirst(showVal($ticket['category'])); ?>
                    </div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">Requester</div>
                    <div class="detail-value"><?php echo showVal($ticket['name']); ?>
                </div>
        </div>

        <div class="detail-item">
            <div class="detail-label">Email</div>
            <div class="detail-value email">
                <a href="mailto:<?php echo esc($ticket['email']); ?>">
                        <i class="fas fa-envelope" style="margin-right: 4px;"></i>
                    <?php echo showVal($ticket['email']); ?>
                </a>
            </div>
        </div>

        <div class="detail-item">
            <div class="detail-label">Phone</div>
                    <div class=" detail-value">
                <?php echo $ticket['phone'] ? '<i class="fas fa-phone" style="margin-right: 4px;"></i>' . showVal($ticket['phone']) : '<em style="color: var(--sq-text-light);">Not provided</em>'; ?>
            </div>
        </div>

        <div class="detail-item">
            <div class="detail-label">Subject</div>
            <div class="detail-value" style="font-weight: 600;">
                <?php echo showVal($ticket['subject']); ?>
            </div>
        </div>

        <div class="detail-item">
            <div class="detail-label">Message</div>
            <div class="message-box">
                <?php echo nl2br(showVal($ticket['message'])); ?>
            </div>
        </div>

        <div class="detail-item">
            <div class="detail-label">Attachments</div>
            <?php if (!empty($ticket['attachment_path'])):
                $paths = array_values(array_filter(array_map('trim', explode(',', $ticket['attachment_path'])), 'strlen'));
                $names = !empty($ticket['attachment_name']) ? array_values(array_filter(array_map('trim', explode(',', $ticket['attachment_name'])), fn($x) => $x !== '')) : [];
                if (count($paths) > 0): ?>
                        <ul class="attachments-list">
                    <?php foreach ($paths as $i => $rawPath):
                        // FIXED: Extract just the filename from the stored path
                        $fileName = basename($rawPath);
                        // Build the correct URL to the attachment
                        $fileUrl = $attachmentsBaseUrl . '/' . $fileName;
                        $linkText = $names[$i] ?? $fileName;
                        ?>
                                                <li>
                                                    <span class="attachment-name">
                                                        <i class="fas fa-paperclip"></i>
                                                        <?php echo esc(strlen($linkText) > 20 ? substr($linkText, 0, 20) . '...' : $linkText); ?>
                                                    </span>
                                                    <div class="attachment-actions">
                                                        <a href="<?php echo esc($fileUrl); ?>" target="_blank" class="attachment-btn view" title="View">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="<?php echo esc($fileUrl); ?>" download class="attachment-btn
            download" title="Download">
                        <i class="fas fa-download"></i>
                        </a>
                    </div>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="reply-empty" style="margin-top: 8px;"> <i class="fas fa-times-circle"></i> No attachments
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="reply-empty" style="margin-top: 8px;">
                <i class="fas fa-times-circle"></i> No attachments
                </div>
            <?php endif; ?>
        </div>

        <div class="detail-item">
            <div class="detail-label">Created
        </div>
        <div class="detail-value">
            <i class="fas fa-calendar" style="margin-right: 4px; color: var(--sq-text-light);"></i>
            <?php echo date('M j, Y g:i A', strtotime($ticket['created_at'])); ?>
            </div>
        </div>

        <?php if ($ticket['updated_at']): ?>
            <div class="detail-item">
                <div class="detail-label">Last Updated</div>
                <div class="detail-value">
                        <i class="fas fa-clock" style="margin-right: 4px; color: var(--sq-text-light);"></i>
                    <?php echo date('M j, Y g:i A', strtotime($ticket['updated_at'])); ?>
                </div>
            </div>
        <?php endif; ?>
        </aside>

        <!-- RIGHT TOP: Resolution Card -->
        <section class="main-top-card resolution-card">
            <div class="card-header">
                <div class="card-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h2 class="card-title">Resolution &amp; Answer</h2>
            </div>

            <div id="resolution-view" class="resolution-view<?= $hasResolution ? '' : ' sq-is-hidden' ?>">
                <div id="resolution-display" class="resolution-display-body"><?= nl2br(esc($resolutionText)) ?></div>
                <?php if (!empty($ticket['updated_at'])): ?>
                    <p class="resolution-meta">
                        <i class="fas fa-clock"></i>
                        Last updated <?= esc(date('M j, Y g:i A', strtotime($ticket['updated_at']))) ?>
                    </p>
                <?php endif; ?>
                <div class="resolution-actions">
                    <button type="button" class="btn btn-secondary" id="editResolutionBtn">
                        <i class="fas fa-pen"></i> Edit resolution
                    </button>
                </div>
            </div>

            <div id="resolution-edit" class="resolution-edit<?= $hasResolution ? ' sq-is-hidden' : '' ?>">
                <div class="form-group">
                    <label class="form-label" for="ticket-resolution">Resolution / Final Answer</label>
                    <textarea id="ticket-resolution" class="form-textarea" rows="8"
                        placeholder="Enter the resolution or final answer for this ticket..."><?= esc($resolutionText) ?></textarea>
                </div>
                <div class="resolution-actions">
                    <button type="button" class="btn btn-success" id="resolutionBtn">
                        <i class="fas fa-save"></i> <?= $hasResolution ? 'Save changes' : 'Save resolution' ?>
                    </button>
                    <button type="button" class="btn btn-secondary sq-is-hidden" id="cancelResolutionBtn">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </div>
        </section>

        <!-- RIGHT BOTTOM: Conversation Card -->
        <section class="main-bottom-card">
            <div class="card-header">
                <div class="card-icon chat">
                    <i class="fas fa-comments"></i>
                </div>
                <h2 class="card-title">Conversation History</h2>
            </div>

            <div class="replies-section conversation-section">
                <div class="replies-title">
                    <i class="fas fa-comments" style="margin-right: 6px;"></i> Conversation
                </div>

                <div class="conversation-thread" id="conversation-thread">
                    <?php if (!empty($conversationThread)): ?>
                        <div class="conversation-list" id="conversation-list">
                            <?php foreach ($conversationThread as $entry): ?>
                                <?php $isAdmin = ($entry['role'] === 'admin'); ?>
                                <div class="reply-item <?= $isAdmin ? 'admin' : 'user' ?>">
                                    <div class="reply-bubble-meta">
                                        <span class="reply-avatar"><?= $isAdmin ? 'SQ' : 'U' ?></span>
                                        <span class="reply-author"><?= $isAdmin ? 'Support Team' : 'Customer' ?></span>
                                        <?php if (!empty($entry['sent_at_label'])): ?>
                                            <span class="reply-time"><?= esc($entry['sent_at_label']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="reply-bubble-body"><?= nl2br(esc($entry['text'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="reply-empty" id="conversation-empty">
                            <i class="fas fa-comment-slash"></i> No messages yet. Customer follow-ups and your replies will appear here.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

                <!-- New Reply Form -->
                <div class="form-group" style="margin-top: 24px;">
                    <label class="form-label">Add New Reply</label>
                    <textarea id="admin-reply" class="form-textarea"
                        placeholder="Type your reply to the user..."></textarea>
                </div>

                <div class="action-bar">
                    <button class="btn btn-primary" id="replyBtn">
                        <i class="fas fa-paper-plane"></i> Send Reply
                    </button>
                    <button class="btn btn-secondary" id="statusBtn">
                        <i class="fas fa-exchange-alt"></i> Change Status
                    </button>
                    <button class="btn btn-danger" id="deleteBtn">
                        <i class="fas fa-trash-alt"></i> Delete Ticket
                    </button>
                </div>
            </section>

        </div>

    </main>

    <!-- Status Change Modal -->
    <div id="modal-status" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-exchange-alt"></i> Change Status</h3>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Select New Status</label>
                    <select id="statusSelect" class="form-select">
                        <option value="">-- Choose --</option>
                        <option value="open">Open</option>
                        <option value="in_progress">In Progress</option>
                        <option value="resolved">Resolved</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="statusCancelBtn">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button class="btn btn-primary" id="statusConfirmBtn">
                    <i class="fas fa-check"></i> Change
                </button>
            </div>
        </div>
    </div>

    <!-- STANDARDIZED FOOTER -->
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

                <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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

                    // Help Modal
                    const helpBtn = document.getElementById('helpBtn');
                    const helpModal = document.getElementById('helpModal');

                    helpBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        helpModal.classList.add('active');
                    });

                    function closeHelpModal() {
                        helpModal.classList.remove('active');
                    }

                    window.addEventListener('click', (e) => {
                        if (e.target === helpModal) closeHelpModal();
                    });

                    // API Helper
                    function postAction(url, data) {
                        return fetch(url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(data)
                        }).then(r => r.json());
                    }

                    function showSwal(msg, type = 'success') {
                        Swal.fire({
                            title: type === 'success' ? 'Success!' : 'Error!',
                            text: msg,
                            icon: type,
                            timer: 3000,
                            timerProgressBar: true,
                            showConfirmButton: false,
                            toast: true,
                            position: 'top-end'
                        });
                    }

                    // Status Modal
                    const statusModal = document.getElementById('modal-status');
                    const statusSelect = document.getElementById('statusSelect');

                    document.getElementById('statusBtn').addEventListener('click', () => {
                        const current = document.getElementById('status-badge').textContent.trim().toLowerCase().replace(' ', '_');
                        statusSelect.value = current;
                        statusModal.classList.add('active');
                    });

                    document.getElementById('statusCancelBtn').addEventListener('click', () => {
                        statusModal.classList.remove('active');
                    });

                    document.getElementById('statusConfirmBtn').addEventListener('click', async () => {
                        const newStatus = statusSelect.value;
                        if (!newStatus) {
                            Swal.fire({ icon: 'warning', title: 'Select a status', text: 'Please choose a status first.' });
                            return;
                        }

                        const btn = document.getElementById('statusConfirmBtn');
                        btn.disabled = true;
                        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Changing...';

                        try {
                            const res = await postAction('../../PHP/Backend/ticket_actions.php', {
                                action: 'status',
                                unique_id: '<?php echo esc($ticket['unique_id']); ?>',
                                status: newStatus
                            });

                            showSwal(res.message, res.status === 'ok' ? 'success' : 'error');

                            if (res.status === 'ok') {
                                const badge = document.getElementById('status-badge');
                                badge.className = 'status-badge status-' + newStatus;
                                badge.innerHTML = '<i class="fas fa-circle" style="font-size: 8px;"></i> ' + newStatus.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
                                statusModal.classList.remove('active');
                            }
                        } catch (e) {
                            showSwal('Failed to change status', 'error');
                        } finally {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-check"></i> Change';
                        }
                    });

                    window.addEventListener('click', (e) => {
                        if (e.target === statusModal) statusModal.classList.remove('active');
                    });

                    document.addEventListener('keydown', (e) => {
                        if (e.key === 'Escape') {
                            statusModal.classList.remove('active');
                            closeHelpModal();
                        }
                    });

                    // Resolution view / edit
                    const resolutionView = document.getElementById('resolution-view');
                    const resolutionEdit = document.getElementById('resolution-edit');
                    const resolutionDisplay = document.getElementById('resolution-display');
                    const resolutionTextarea = document.getElementById('ticket-resolution');
                    const resolutionBtn = document.getElementById('resolutionBtn');
                    const cancelResolutionBtn = document.getElementById('cancelResolutionBtn');
                    const editResolutionBtn = document.getElementById('editResolutionBtn');
                    let resolutionSavedText = resolutionTextarea.value.trim();

                    function escapeHtml(str) {
                        return String(str)
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/"/g, '&quot;')
                            .replace(/'/g, '&#039;');
                    }

                    function formatResolutionHtml(text) {
                        return escapeHtml(text).replace(/\r?\n/g, '<br>');
                    }

                    function setResolutionSaveLabel(isUpdate) {
                        resolutionBtn.innerHTML = '<i class="fas fa-save"></i> ' + (isUpdate ? 'Save changes' : 'Save resolution');
                    }

                    function showResolutionView(text) {
                        resolutionSavedText = text;
                        resolutionDisplay.innerHTML = formatResolutionHtml(text);
                        resolutionView.classList.remove('sq-is-hidden');
                        resolutionEdit.classList.add('sq-is-hidden');
                        cancelResolutionBtn.classList.add('sq-is-hidden');
                    }

                    function showResolutionEdit(text) {
                        resolutionTextarea.value = text;
                        resolutionView.classList.add('sq-is-hidden');
                        resolutionEdit.classList.remove('sq-is-hidden');
                        cancelResolutionBtn.classList.toggle('sq-is-hidden', resolutionSavedText === '');
                        setResolutionSaveLabel(resolutionSavedText !== '');
                        resolutionTextarea.focus();
                    }

                    editResolutionBtn.addEventListener('click', () => {
                        showResolutionEdit(resolutionSavedText);
                    });

                    cancelResolutionBtn.addEventListener('click', () => {
                        if (resolutionSavedText === '') {
                            resolutionTextarea.value = '';
                            resolutionEdit.classList.remove('sq-is-hidden');
                            resolutionView.classList.add('sq-is-hidden');
                            return;
                        }
                        showResolutionView(resolutionSavedText);
                    });

                    resolutionBtn.addEventListener('click', async () => {
                        const text = resolutionTextarea.value.trim();

                        if (!text) {
                            Swal.fire({ icon: 'error', title: 'Empty', text: 'Resolution cannot be empty!' });
                            return;
                        }

                        resolutionBtn.disabled = true;
                        resolutionBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

                        try {
                            const res = await postAction('../../PHP/Backend/ticket_actions.php', {
                                action: 'resolution',
                                unique_id: '<?php echo esc($ticket['unique_id']); ?>',
                                resolution: text
                            });
                            showSwal(res.message, res.status === 'ok' ? 'success' : 'error');

                            if (res.status === 'ok') {
                                const saved = (res.answer || text).trim();
                                showResolutionView(saved);
                                if (res.updated_at) {
                                    let meta = resolutionView.querySelector('.resolution-meta');
                                    if (!meta) {
                                        meta = document.createElement('p');
                                        meta.className = 'resolution-meta';
                                        resolutionDisplay.insertAdjacentElement('afterend', meta);
                                    }
                                    meta.innerHTML = '<i class="fas fa-clock"></i> Last updated ' + res.updated_at;
                                }
                            }
                        } catch (e) {
                            showSwal('Failed to save', 'error');
                        } finally {
                            resolutionBtn.disabled = false;
                            setResolutionSaveLabel(resolutionSavedText !== '');
                        }
                    });

                    // Reply
                    document.getElementById('replyBtn').addEventListener('click', async () => {
                        const btn = document.getElementById('replyBtn');
                        const text = document.getElementById('admin-reply').value.trim();

                        if (!text) {
                            Swal.fire({ icon: 'error', title: 'Empty', text: 'Reply cannot be empty!' });
                            return;
                        }

                        btn.disabled = true;
                        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

                        try {
                            const res = await postAction('../../PHP/Backend/ticket_actions.php', {
                                action: 'reply',
                                unique_id: '<?php echo esc($ticket['unique_id']); ?>',
                                reply: text
                            });

                            showSwal(res.message, res.status === 'ok' ? 'success' : 'error');

                            if (res.status === 'ok') {
                                const thread = document.getElementById('conversation-thread');
                                const empty = document.getElementById('conversation-empty');
                                if (empty) empty.remove();

                                let list = document.getElementById('conversation-list');
                                if (!list) {
                                    list = document.createElement('div');
                                    list.className = 'conversation-list';
                                    list.id = 'conversation-list';
                                    thread.appendChild(list);
                                }

                                const item = document.createElement('div');
                                item.className = 'reply-item admin';
                                const timeLabel = res.sent_at_label || '';
                                const timeHtml = timeLabel
                                    ? '<span class="reply-time">' + timeLabel.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>'
                                    : '';
                                item.innerHTML = '<div class="reply-bubble-meta"><span class="reply-avatar">SQ</span><span class="reply-author">Support Team</span>' + timeHtml + '</div><div class="reply-bubble-body"></div>';
                                item.querySelector('.reply-bubble-body').textContent = text;
                                list.appendChild(item);

                                document.getElementById('admin-reply').value = '';
                                thread.scrollTop = thread.scrollHeight;
                            }
                        } catch (e) {
                            showSwal('Failed to send', 'error');
                        } finally {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Reply';
                        }
                    });

                    // Soft delete
                    const deleteBtn = document.getElementById('deleteBtn');
                    if (deleteBtn) {
                        deleteBtn.addEventListener('click', () => {
                            Swal.fire({
                                title: 'Move ticket to trash?',
                                text: 'You can restore it later from deleted tickets.',
                                icon: 'warning',
                                showCancelButton: true,
                                confirmButtonColor: '#ef4444',
                                cancelButtonColor: '#3b82f6',
                                confirmButtonText: 'Yes, move to trash'
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    deleteBtn.disabled = true;
                                    deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';

                                    postAction('../../PHP/Backend/ticket_actions.php', {
                                        action: 'delete',
                                        unique_id: '<?php echo esc($ticket['unique_id']); ?>'
                                    }).then(res => {
                                        Swal.fire({
                                            title: res.status === 'ok' ? 'Moved to Trash' : 'Error!',
                                            text: res.message,
                                            icon: res.status === 'ok' ? 'success' : 'error',
                                            timer: 1500,
                                            showConfirmButton: false
                                        }).then(() => {
                                            if (res.status === 'ok') {
                                                window.location.href = '../../PHP/Frontend/Admin_ticket_support.php?view=deleted';
                                            }
                                        });
                                    }).catch(() => {
                                        Swal.fire({ title: 'Error!', text: 'Failed to delete', icon: 'error', timer: 1500, showConfirmButton: false });
                                    }).finally(() => {
                                        deleteBtn.disabled = false;
                                        deleteBtn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete Ticket';
                                    });
                                }
                            });
                        });
                    }

                </script>
</body>

</html>