<?php
session_start();

function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $scriptName = str_replace('/Public/Help_center/PHP/Frontend/user_ticket_tracking.php', '', $_SERVER['SCRIPT_NAME']);
    return $protocol . $host . $scriptName;
}

const BASE_UPLOAD_URL = '/Storage/Ticket_attachments/';

$host = '127.0.0.1';
$db = 'scanquotient.a1';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

$pdo = null;
$page_error_message = '';
$page_success_message = '';

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log("DB connection failed: " . $e->getMessage());
    $page_error_message = 'Database connection failed. Please try again later.';
}

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'close_ticket') {
    $unique_id_to_close = trim($_POST['unique_id_to_close']);
    $sql_fetch_status = "SELECT status FROM support_tickets WHERE unique_id = ?";
    $stmt_fetch_status = $pdo->prepare($sql_fetch_status);
    $stmt_fetch_status->execute([$unique_id_to_close]);
    $current_status = $stmt_fetch_status->fetchColumn();

    if ($current_status === 'closed') {
        header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . urlencode($unique_id_to_close) . "&error=already_closed");
        exit();
    }

    if ($current_status) {
        $sql_close_ticket = "UPDATE support_tickets SET status = 'closed', closed_by = 'user', updated_at = NOW() WHERE unique_id = ?";
        try {
            $stmt_close = $pdo->prepare($sql_close_ticket);
            $stmt_close->execute([$unique_id_to_close]);
            header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . urlencode($unique_id_to_close) . "&success=ticket_closed");
            exit();
        } catch (PDOException $e) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . urlencode($unique_id_to_close) . "&error=close_error");
            exit();
        }
    }
}

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unique_id'], $_POST['new_user_reply'])) {
    $unique_id_post = trim($_POST['unique_id']);
    $new_user_reply = trim($_POST['new_user_reply']);
    $sql_fetch_current = "SELECT user_reply, status FROM support_tickets WHERE unique_id = ?";
    $stmt_fetch = $pdo->prepare($sql_fetch_current);
    $stmt_fetch->execute([$unique_id_post]);
    $current_ticket = $stmt_fetch->fetch();

    if ($current_ticket && $current_ticket['status'] === 'closed') {
        header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . urlencode($unique_id_post) . "&error=closed_reply");
        exit();
    }

    if ($current_ticket) {
        $existing_user_reply = $current_ticket['user_reply'];
        $updated_user_reply = !empty($existing_user_reply) ? $existing_user_reply . ', ' . $new_user_reply : $new_user_reply;
        $sql_update = "UPDATE support_tickets SET user_reply = ?, updated_at = NOW() WHERE unique_id = ?";
        try {
            $stmt_update = $pdo->prepare($sql_update);
            $stmt_update->execute([$updated_user_reply, $unique_id_post]);
            header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . urlencode($unique_id_post) . "&success=reply_added");
            exit();
        } catch (PDOException $e) {
            $page_error_message = 'Error saving your reply.';
        }
    }
}

$ticket = null;
if ($pdo && isset($_GET['id']) && !empty(trim($_GET['id']))) {
    $unique_id = trim($_GET['id']);
    $sql = "SELECT * FROM support_tickets WHERE unique_id = ?";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$unique_id]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            $page_error_message = 'Invalid Ticket ID. Please check your email or contact support.';
        }
    } catch (PDOException $e) {
        $page_error_message = 'Error fetching ticket details.';
    }
} elseif ($pdo) {
    $page_error_message = 'No ticket ID provided.';
}

if (isset($_GET['error'])) {
    switch($_GET['error']) {
        case 'already_closed': $page_error_message = 'Ticket is already closed.'; break;
        case 'close_error': $page_error_message = 'Error closing ticket.'; break;
        case 'closed_reply': $page_error_message = 'Cannot reply to a closed ticket.'; break;
        default: $page_error_message = htmlspecialchars($_GET['error']);
    }
}

if (isset($_GET['success'])) {
    if ($_GET['success'] === 'reply_added') $page_success_message = 'Reply submitted successfully!';
    if ($_GET['success'] === 'ticket_closed') $page_success_message = 'Ticket closed successfully!';
}

$baseUrl = getBaseUrl();

function getPriorityStyle($priority) {
    switch(strtolower($priority)) {
        case 'high': return 'bg-red-500/20 text-red-400 border-red-500/30';
        case 'medium': return 'bg-amber-500/20 text-amber-400 border-amber-500/30';
        case 'low': return 'bg-blue-500/20 text-blue-400 border-blue-500/30';
        default: return 'bg-slate-500/20 text-slate-400 border-slate-500/30';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScanQuotient | Ticket #<?= $ticket ? htmlspecialchars($ticket['unique_id']) : 'Details' ?></title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="stylesheet" type="text/css" href="../../CSS/user_ticket_tracking_site.css">
</head>
<body class="min-h-screen flex flex-col">

    <!-- Header with Gradient -->
    <header class="gradient-header fixed w-full top-0 z-50 px-6 py-4">
        <div class="max-w-7xl mx-auto flex justify-between items-center">
            <div class="flex items-center gap-3">
              
                <div>
                    <h1 class="text-xl font-bold tracking-tight header-brand">
                        ScanQuotient
                    </h1>
                    <p class="text-xs text-black/80 font-medium">Quantifying Risk. Strengthening Security</p>
                </div>
            </div>

            <div class="flex items-center gap-6">
                <button id="theme-toggle" class="header-icon focus:outline-none" title="Toggle Theme">
                    <i class="fas fa-moon text-xl" id="theme-icon"></i>
                </button>

                <a href="Help_center.php" 
                   class="header-icon" title="Help Center">
                    <i class="fas fa-circle-question text-xl"></i>
                </a>

                <a href="../../../Homepage/PHP/Frontend/Homepage.php" 
                   class="header-icon" title="Home">
                    <i class="fas fa-house text-xl"></i>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow pt-28 pb-12 px-4 sm:px-6">
        <div class="max-w-5xl mx-auto">
            
            <?php if (!empty($page_success_message)): ?>
                <div id="successToast" class="mb-6 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center gap-3 animate-slide-in">
                    <i class="fas fa-check-circle text-xl"></i>
                    <span class="font-medium"><?= htmlspecialchars($page_success_message) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($page_error_message)): ?>
                <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 flex items-center gap-3 animate-slide-in">
                    <i class="fas fa-exclamation-circle text-xl"></i>
                    <span class="font-medium"><?= htmlspecialchars($page_error_message) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($ticket): ?>
                <!-- Ticket Header -->
                <div class="ticket-card p-6 mb-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4 animate-slide-in">
                    <div>
                        <div class="flex items-center gap-3 mb-1">
                            <h2 class="text-2xl font-bold">Ticket #<?= htmlspecialchars($ticket['unique_id']) ?></h2>
                            <span class="status-badge status-<?= htmlspecialchars($ticket['status']) ?>">
                                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $ticket['status']))) ?>
                            </span>
                        </div>
                        <p class="text-sm text-slate-500">Created on <?= htmlspecialchars(date('F j, Y \a\t g:i A', strtotime($ticket['created_at']))) ?></p>
                    </div>
                    <div class="flex gap-2">
                        <span class="px-3 py-1 rounded-lg text-xs font-semibold border priority-<?= htmlspecialchars(strtolower($ticket['priority'])) ?>">
                            <i class="fas fa-flag mr-1"></i><?= htmlspecialchars(ucwords($ticket['priority'])) ?> Priority
                        </span>
                        <span class="px-3 py-1 rounded-lg bg-slate-100 text-xs font-semibold text-slate-600 border border-slate-300">
                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $ticket['category']))) ?>
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Left Column: Details -->
                    <div class="lg:col-span-1 space-y-6">
                        <div class="ticket-card p-6 animate-slide-in" style="animation-delay: 0.1s;">
                            <h3 class="text-lg font-semibold mb-4 flex items-center gap-2 icon-colored">
                                <i class="fas fa-user-circle"></i> Requester Info
                            </h3>
                            <div class="space-y-4">
                                <div>
                                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">Name</label>
                                    <p class="text-sm font-medium mt-1"><?= htmlspecialchars($ticket['name']) ?></p>
                                </div>
                                <div>
                                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">Email</label>
                                    <p class="text-sm font-medium mt-1 break-all"><?= htmlspecialchars($ticket['email']) ?></p>
                                </div>
                                <div>
                                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wider">Phone</label>
                                    <p class="text-sm font-medium mt-1"><?= htmlspecialchars($ticket['phone'] ?: 'N/A') ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="ticket-card p-6 animate-slide-in" style="animation-delay: 0.2s;">
                            <h3 class="text-lg font-semibold mb-4 flex items-center gap-2 icon-colored">
                                <i class="fas fa-clock"></i> Timeline
                            </h3>
                            <div class="space-y-4 relative pl-4 border-l-2 border-slate-300">
                                <div class="relative">
                                    <div class="absolute -left-[21px] top-1 w-3 h-3 rounded-full bg-violet-500"></div>
                                    <p class="text-xs text-slate-500">Created</p>
                                    <p class="text-sm font-medium"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($ticket['created_at']))) ?></p>
                                </div>
                                <div class="relative">
                                    <div class="absolute -left-[21px] top-1 w-3 h-3 rounded-full bg-blue-500"></div>
                                    <p class="text-xs text-slate-500">Last Updated</p>
                                    <p class="text-sm font-medium"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($ticket['updated_at']))) ?></p>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($ticket['attachment_name'])): ?>
                        <div class="ticket-card p-6 animate-slide-in" style="animation-delay: 0.3s;">
                            <h3 class="text-lg font-semibold mb-4 flex items-center gap-2 icon-colored">
                                <i class="fas fa-paperclip"></i> Attachments
                            </h3>
                            <div class="space-y-2">
                                <?php
                                $paths = explode(',', $ticket['attachment_path']);
                                $names = explode(',', $ticket['attachment_name']);
                                $paths = array_filter(array_map('trim', $paths));
                                $names = array_filter(array_map('trim', $names));
                                
                                if (!empty($paths) && count($paths) === count($names)):
                                    foreach ($paths as $index => $path):
                                        $filename = htmlspecialchars($names[$index]);
                                        $fileUrl = $baseUrl . BASE_UPLOAD_URL . basename($path);
                                ?>
                                    <div class="attachment-card p-3 flex items-center gap-3">
                                        <div class="w-8 h-8 rounded bg-violet-100 flex items-center justify-center icon-colored flex-shrink-0">
                                            <i class="fas fa-file"></i>
                                        </div>
                                        
                                        <!-- Filename with tooltip -->
                                        <div class="attachment-filename-container">
                                            <div class="attachment-filename" title="Hover to see full name">
                                                <?= $filename ?>
                                            </div>
                                            <!-- Tooltip showing full filename -->
                                            <div class="filename-tooltip"><?= $filename ?></div>
                                        </div>
                                        
                                        <div class="attachment-actions">
                                            <a href="<?= $fileUrl ?>" target="_blank" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="<?= $fileUrl ?>" download title="Download">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </div>
                                    </div>
                                <?php 
                                    endforeach;
                                endif;
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right Column: Content -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Subject -->
                        <div class="ticket-card p-6 animate-slide-in" style="animation-delay: 0.1s;">
                            <h3 class="text-lg font-semibold mb-2 text-slate-500 text-sm uppercase tracking-wider">Subject</h3>
                            <p class="text-xl font-medium"><?= htmlspecialchars($ticket['subject']) ?></p>
                        </div>

                        <!-- Original Message -->
                        <div class="ticket-card p-6 animate-slide-in" style="animation-delay: 0.2s;">
                            <h3 class="text-lg font-semibold mb-4 flex items-center gap-2 icon-colored">
                                <i class="fas fa-align-left"></i> Description
                            </h3>
                            <div class="prose max-w-none text-slate-700 leading-relaxed">
                                <?= nl2br(htmlspecialchars($ticket['message'])) ?>
                            </div>
                        </div>

                        <!-- Official Response -->
                        <?php if (!empty($ticket['answer'])): ?>
                        <div class="ticket-card p-6 border-l-4 border-l-emerald-500 animate-slide-in" style="animation-delay: 0.3s;">
                            <h3 class="text-lg font-semibold mb-4 flex items-center gap-2 text-emerald-600">
                                <i class="fas fa-check-circle"></i> Official Response
                            </h3>
                            <div class="prose max-w-none text-slate-700 leading-relaxed bg-emerald-50 p-4 rounded-lg border border-emerald-200">
                                <?= nl2br(htmlspecialchars($ticket['answer'])) ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Additional Info Section -->
                        <div class="ticket-card p-6 animate-slide-in" style="animation-delay: 0.4s;">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold flex items-center gap-2 icon-colored">
                                    <i class="fas fa-comments"></i> Additional Info
                                </h3>
                                <?php if ($ticket['status'] !== 'closed'): ?>
                                    <span class="text-xs text-slate-500 bg-slate-100 px-2 py-1 rounded border border-slate-300">
                                        <i class="fas fa-info-circle mr-1"></i>Send additional details here
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="space-y-3 mb-6">
                                <?php
                                $user_replies_array = [];
                                if (!empty($ticket['user_reply'])) {
                                    $user_replies_array = array_filter(array_map('trim', explode(',', $ticket['user_reply'])));
                                }
                                
                                if (!empty($user_replies_array)):
                                    foreach ($user_replies_array as $reply):
                                ?>
                                    <div class="reply-card p-3 flex gap-3">
                                        <div class="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 flex-shrink-0 text-xs font-bold">
                                            YOU
                                        </div>
                                        <div class="flex-1">
                                            <p class="text-sm text-slate-700"><?= nl2br(htmlspecialchars($reply)) ?></p>
                                        </div>
                                    </div>
                                <?php 
                                    endforeach;
                                else:
                                ?>
                                    <p class="text-sm text-slate-500 italic text-center py-4">No additional information submitted yet.</p>
                                <?php endif; ?>
                            </div>

                            <!-- Reply Form -->
                            <?php if ($ticket['status'] !== 'closed'): ?>
                                <form action="<?= htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . urlencode($ticket['unique_id']) ?>" method="POST" class="mt-4">
                                    <input type="hidden" name="unique_id" value="<?= htmlspecialchars($ticket['unique_id']) ?>">
                                    <div class="relative">
                                        <textarea name="new_user_reply" rows="3" placeholder="Type additional information here..." required
                                            class="w-full input-bordered p-3 text-sm resize-none"></textarea>
                                    </div>
                                    <div class="mt-3 flex justify-end">
                                        <button type="submit" class="btn-primary px-6 py-2 rounded-lg text-sm font-semibold flex items-center gap-2">
                                            <i class="fas fa-paper-plane"></i> Submit Info
                                        </button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="p-4 rounded-lg bg-red-50 border-2 border-red-300 text-red-600 text-center text-sm font-medium">
                                    <i class="fas fa-lock mr-2"></i> This ticket is closed. No further information can be added.
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Admin Note -->
                        <?php if (!empty($ticket['admin_reply'])): ?>
                        <div class="admin-note p-6 animate-slide-in" style="animation-delay: 0.5s;">
                            <div class="flex items-center gap-2 mb-3">
                                <div class="w-8 h-8 rounded-full bg-violet-100 flex items-center justify-center icon-colored">
                                    <i class="fas fa-user-shield"></i>
                                </div>
                                <div>
                                    <h3 class="text-sm font-bold text-violet-600 uppercase tracking-wider">Admin Note</h3>
                                    <p class="text-xs text-slate-500">For internal reference</p>
                                </div>
                            </div>
                            <div class="pl-10">
                                <p class="text-sm text-slate-700 leading-relaxed bg-white/50 p-3 rounded-lg border border-violet-200">
                                    <?= nl2br(htmlspecialchars($ticket['admin_reply'])) ?>
                                </p>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Action Bar -->
                        <div class="flex justify-between items-center pt-4 animate-slide-in" style="animation-delay: 0.5s;">
                            <a href="Help_center.php" 
                               class="text-sm text-slate-500 hover:text-violet-600 flex items-center gap-2 transition-colors">
                                <i class="fas fa-arrow-left"></i> Back to Tickets
                            </a>
                            
                            <?php if ($ticket['status'] !== 'closed'): ?>
                                <button type="button" id="openCloseModalBtn" class="btn-danger px-6 py-2.5 rounded-lg text-sm font-semibold flex items-center gap-2 shadow-lg shadow-red-500/20">
                                    <i class="fas fa-times-circle"></i> Close Ticket
                                </button>
                            <?php else: ?>
                                <span class="px-4 py-2 rounded-lg bg-slate-100 text-slate-500 text-sm font-medium border-2 border-slate-300 cursor-not-allowed">
                                    <i class="fas fa-check mr-2"></i>Closed
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Empty State -->
                <div class="ticket-card p-12 text-center animate-slide-in">
                    <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-slate-100 flex items-center justify-center icon-colored text-3xl">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-2">No Ticket Found</h3>
                    <p class="text-slate-500 mb-6 max-w-md mx-auto">We couldn't find the ticket you're looking for. Please check the ID or create a new support request.</p>
                    <a href="Help_center.php" 
                       class="btn-primary px-8 py-3 rounded-lg font-semibold inline-flex items-center gap-2">
                        <i class="fas fa-plus"></i> Create New Ticket
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer with Gradient -->
    <footer class="gradient-footer mt-auto">
        <div class="max-w-7xl mx-auto px-6 py-8">
            <div class="flex flex-col md:flex-row justify-between items-center gap-6">
                <div class="text-center md:text-left">
                    <h2 class="text-lg font-bold header-brand">
                        ScanQuotient
                    </h2>
                    <p class="text-xs text-black/80 font-medium">Quantifying Risk. Strengthening Security</p>
                </div>
                
                <div class="text-sm font-medium" style="color: var(--header-text);">
                    <span>&copy; 2026 ScanQuotient. All rights reserved.</span>
                </div>
                
                <div class="flex items-center gap-4 text-sm">
                    <span class="opacity-80" style="color: var(--header-text);">Contact:</span>
                    <a href="mailto:scanquotient@gmail.com" class="header-icon flex items-center gap-2 font-medium">
                        <i class="fas fa-envelope"></i>
                        scanquotient@gmail.com
                    </a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Close Ticket Modal -->
    <div id="closeTicketModal" class="modal-overlay fixed inset-0 z-[100] hidden flex items-center justify-center p-4">
        <div class="ticket-card max-w-md w-full p-6 transform transition-all scale-95 opacity-0" id="modalContent">
            <div class="text-center mb-6">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-red-100 flex items-center justify-center text-red-500 text-2xl">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3 class="text-xl font-bold mb-2">Close Ticket?</h3>
                <p class="text-slate-600 text-sm">Are you sure you want to close this ticket? This action cannot be undone.</p>
            </div>
            <div class="flex gap-3">
                <button type="button" id="cancelCloseBtn" class="flex-1 py-2.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium transition-colors border-2 border-slate-300">
                    Cancel
                </button>
                <form action="<?= htmlspecialchars($_SERVER['PHP_SELF']) . '?id=' . urlencode($ticket['unique_id'] ?? '') ?>" method="POST" class="flex-1">
                    <input type="hidden" name="action" value="close_ticket">
                    <input type="hidden" name="unique_id_to_close" value="<?= htmlspecialchars($ticket['unique_id'] ?? '') ?>">
                    <button type="submit" class="w-full py-2.5 rounded-lg btn-danger font-medium shadow-lg shadow-red-500/20">
                        Yes, Close It
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Back to Top -->
    <button id="back-to-top" class="fixed bottom-6 right-6 w-12 h-12 rounded-full text-white shadow-lg flex items-center justify-center transform translate-y-20 opacity-0 transition-all duration-300 hover:scale-110 z-40">
        <i class="fas fa-arrow-up"></i>
    </button>
<script src="../../Javascript/user_ticket_tracking_site.js"></script>
    
</body>
</html>