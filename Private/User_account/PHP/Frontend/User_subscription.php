<?php
// user_subscription.php - Subscription management with cancel-at-period-end
session_start();

require_once __DIR__ . '/../../../Shared/PHP/sq_subscription_emails.php';

// Authentication check
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header("Location: /ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Frontend/login_page_site.php?error=not_authenticated");
    exit();
}

$userId = $_SESSION['user_id'] ?? null;
$userEmail = $_SESSION['user_email'] ?? '';
$userName = $_SESSION['user_name'] ?? 'User';
$profile_photo = $_SESSION['profile_photo'] ?? null;
if (!empty($profile_photo)) {
    $photo_path = ltrim((string) $profile_photo, '/');
    $base_url = '/ScanQuotient.v2/ScanQuotient.B';
    $avatar_url = $base_url . '/' . $photo_path;
} else {
    $avatar_url = '/ScanQuotient.v2/ScanQuotient.B/Storage/Public_images/default-avatar.png';
}

// Database configuration
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Plan definitions
$plans = [
    'freemium' => [
        'name' => 'Freemium',
        'price' => 0,
        'period' => 'month',
        'description' => 'Basic security assessments for individuals',
        'features' => [
            'Perform vulnerability scans in authorized environments',
            'Save up to 5 scan reports',
            'Access structured security reports with risk scoring',
            'Community support'
        ],
        'limitations' => ['No unlimited scan storage'],
        'color' => '#64748b',
        'icon' => 'fa-shield-alt',
        'popular' => false
    ],
    'pro' => [
        'name' => 'Pro',
        'price' => 10,
        'period' => 'month',
        'description' => 'Professional security testing for teams',
        'features' => [
            'Unlimited scan storage',
            'Full access to vulnerability scanning modules',
            'Detailed security reports with export options',
            'Priority system performance',
            'Email support'
        ],
        'limitations' => [],
        'color' => '#3b82f6',
        'icon' => 'fa-rocket',
        'popular' => true
    ],
    'enterprise' => [
        'name' => 'Enterprise Suite',
        'price' => 25,
        'period' => 'month',
        'description' => 'Advanced AI-assisted security intelligence',
        'features' => [
            'Everything in Pro',
            'AI-assisted vulnerability review analysis',
            'Enhanced remediation recommendations',
            'Advanced reporting insights for security teams',
            'Dedicated account manager',
            '24/7 Priority support'
        ],
        'limitations' => [],
        'color' => '#8b5cf6',
        'icon' => 'fa-crown',
        'popular' => false
    ]
];

$successMessage = '';
$errorMessage = '';
$currentPlan = 'freemium';
$paymentHistory = [];
$subscriptionStatus = 'active';
$nextBillingDate = null;
$daysRemaining = 0;
$activeSubscriptionId = null;
$isCancelledPendingExpiry = false;
$cancellationReasons = [
    'too_expensive' => 'Too expensive',
    'not_using' => 'Not using it enough',
    'missing_features' => 'Missing features I need',
    'switching_service' => 'Switching to another service',
    'technical_issues' => 'Technical issues',
    'other' => 'Other (please specify)',
];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Get user's payment/subscription history - include account_status from table
    $stmt = $pdo->prepare("
        SELECT * FROM payments 
        WHERE email = ? AND deleted_at IS NULL 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$userEmail]);
    $paymentHistory = $stmt->fetchAll();

    // Find the latest non-expired paid subscription (includes cancelled-but-still-active)
    $activeSubStmt = $pdo->prepare("
        SELECT * FROM payments
        WHERE email = ? AND deleted_at IS NULL
          AND package IN ('pro', 'enterprise')
          AND (account_status = 'active' OR account_status IS NULL)
          AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $activeSubStmt->execute([$userEmail]);
    $activeSubscription = $activeSubStmt->fetch();

    if ($activeSubscription) {
        $activeSubscriptionId = (int) $activeSubscription['id'];
        $currentPlan = $activeSubscription['package'];
        $nextBillingDate = $activeSubscription['expires_at'];
        $daysRemaining = getDaysRemaining($nextBillingDate);

        if (($activeSubscription['status'] ?? '') === 'cancelled') {
            $subscriptionStatus = 'cancelled';
            $isCancelledPendingExpiry = true;
        } else {
            $subscriptionStatus = 'active';
        }
    } else {
        $currentPlan = 'freemium';
        $subscriptionStatus = 'active';
    }

    // Handle form actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['subscription_action'] ?? '';

        switch ($action) {
            case 'cancel':
                if ($activeSubscriptionId && ($plans[$currentPlan]['price'] ?? 0) > 0 && !$isCancelledPendingExpiry) {
                    $reasonKey = $_POST['cancellation_reason'] ?? '';
                    $reasonOther = trim($_POST['cancellation_reason_other'] ?? '');
                    if ($reasonKey === 'other') {
                        $reasonText = $reasonOther !== '' ? $reasonOther : 'Other';
                    } elseif (isset($cancellationReasons[$reasonKey])) {
                        $reasonText = $cancellationReasons[$reasonKey];
                        if ($reasonKey === 'other' && $reasonOther !== '') {
                            $reasonText = $reasonOther;
                        }
                    } else {
                        $errorMessage = 'Please select a reason for cancellation.';
                        break;
                    }

                    $cancelStmt = $pdo->prepare("
                        UPDATE payments
                        SET status = 'cancelled', cancelled_at = NOW(), cancellation_reason = ?
                        WHERE id = ? AND email = ? AND deleted_at IS NULL
                    ");
                    $cancelStmt->execute([$reasonText, $activeSubscriptionId, $userEmail]);

                    if ($cancelStmt->rowCount() > 0) {
                        sq_send_cancellation_emails($userEmail, $currentPlan, $nextBillingDate, $reasonText);
                        $expiryFormatted = formatDate($nextBillingDate);
                        $successMessage = "Subscription cancelled. You keep {$plans[$currentPlan]['name']} access until {$expiryFormatted}, then your account moves to Freemium.";
                        $subscriptionStatus = 'cancelled';
                        $isCancelledPendingExpiry = true;
                    } else {
                        $errorMessage = 'Unable to cancel subscription. Please try again.';
                    }

                    $stmt->execute([$userEmail]);
                    $paymentHistory = $stmt->fetchAll();
                }
                break;

            case 'resubscribe':
                if ($activeSubscriptionId && $isCancelledPendingExpiry) {
                    $reactivateStmt = $pdo->prepare("
                        UPDATE payments
                        SET status = 'completed'
                        WHERE id = ? AND email = ? AND deleted_at IS NULL AND status = 'cancelled'
                    ");
                    $reactivateStmt->execute([$activeSubscriptionId, $userEmail]);

                    if ($reactivateStmt->rowCount() > 0) {
                        sq_send_reactivation_emails($userEmail, $currentPlan, $nextBillingDate);
                        $successMessage = "Subscription reactivated! Your {$plans[$currentPlan]['name']} plan is active again until " . formatDate($nextBillingDate) . ".";
                        $subscriptionStatus = 'active';
                        $isCancelledPendingExpiry = false;
                    } else {
                        $errorMessage = 'Unable to reactivate subscription. Please try again or contact support.';
                    }

                    $stmt->execute([$userEmail]);
                    $paymentHistory = $stmt->fetchAll();
                }
                break;
        }
    }

} catch (Exception $e) {
    error_log("Subscription error: " . $e->getMessage());
    $errorMessage = "Unable to load subscription data. Please try again later.";
}

// Helper functions
function formatCurrency($amount)
{
    return '$' . number_format($amount, 2);
}

function formatDate($date)
{
    if (empty($date))
        return 'N/A';
    return date('F j, Y', strtotime($date));
}

function getDaysRemaining($expiresAt)
{
    if (empty($expiresAt))
        return 0;
    $diff = strtotime($expiresAt) - time();
    return max(0, floor($diff / 86400));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScanQuotient | My Subscription</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../CSS/user_subscription.css" />

</head>

<body>
    <header class="sq-admin-header">
        <div class="sq-admin-header-left">
            <a href="/ScanQuotient.v2/ScanQuotient.B/Private/User_dashboard/PHP/Frontend/User_dashboard.php"
                class="sq-admin-back-btn">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div class="brand-wrapper">
                <a href="#" class="sq-admin-brand">ScanQuotient</a>
                <p class="sq-admin-tagline">Quantifying Risk. Strengthening Security.</p>
            </div>
        </div>
        <div class="sq-admin-header-right">
            <img src="<?php echo htmlspecialchars($avatar_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile"
                class="header-profile-photo">

            <button class="sq-admin-theme-toggle" id="sqThemeToggle" title="Toggle Theme">
                <i class="fas fa-sun"></i>
            </button>
            <a href="/ScanQuotient.v2/ScanQuotient.B/Private/User_dashboard/PHP/Frontend/User_dashboard.php"
                class="icon-btn" title="Home">
                <i class="fas fa-home"></i>
            </a>
            <a href="../../../../Public/Login_page/PHP/Frontend/Login_page_site.php" class="icon-btn" title="Logout">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </header>

    <!-- Confirmation Modal -->
    <div class="sq-sub-modal-overlay" id="confirmModal">
        <div class="sq-sub-modal">
            <div class="sq-sub-modal-icon" id="modalIcon">
                <i class="fas fa-rocket"></i>
            </div>
            <h3 id="modalTitle">Confirm Change</h3>
            <p id="modalDesc">Are you sure?</p>
            <form method="POST" id="modalForm">
                <input type="hidden" name="subscription_action" id="modalAction">
                <input type="hidden" name="target_plan" id="modalTargetPlan">
                <div class="sq-sub-cancel-reason" id="cancelReasonBlock" style="display: none;">
                    <div class="sq-sub-cancel-reason-head">
                        <i class="fas fa-comment-dots"></i>
                        <span>Help us improve: why are you leaving?</span>
                    </div>
                    <div class="sq-sub-cancel-field">
                        <label class="sq-sub-cancel-label" for="cancellationReason">Cancellation reason</label>
                        <div class="sq-sub-cancel-select-wrap">
                            <i class="fas fa-list-ul sq-sub-cancel-field-icon"></i>
                            <select name="cancellation_reason" id="cancellationReason" class="sq-sub-cancel-select">
                                <option value="" disabled selected>Select a reason...</option>
                                <?php foreach ($cancellationReasons as $key => $label): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>">
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <i class="fas fa-chevron-down sq-sub-cancel-select-chevron"></i>
                        </div>
                    </div>
                    <div class="sq-sub-cancel-other-wrap" id="cancelOtherWrap">
                        <label class="sq-sub-cancel-label" for="cancellationReasonOther">Your reason</label>
                        <div class="sq-sub-cancel-other-inner">
                            <i class="fas fa-pen sq-sub-cancel-field-icon"></i>
                            <textarea name="cancellation_reason_other" id="cancellationReasonOther"
                                class="sq-sub-cancel-other" rows="3" maxlength="300"
                                placeholder="Tell us a bit more so we can improve..."></textarea>
                        </div>
                        <span class="sq-sub-cancel-hint" id="cancelOtherHint">Please describe your reason — up to 300
                            characters</span>
                    </div>
                </div>
                <div class="sq-sub-modal-actions">
                    <button type="button" class="sq-sub-btn sq-sub-btn--secondary"
                        onclick="closeSubModal()">Cancel</button>
                    <button type="submit" class="sq-sub-btn sq-sub-btn--primary" id="modalConfirmBtn">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    <main class="sq-sub-container">

        <?php if ($successMessage): ?>
            <div class="sq-sub-alert sq-sub-alert--success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($successMessage); ?></span>
                <button class="sq-sub-alert-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="sq-sub-alert sq-sub-alert--error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($errorMessage); ?></span>
                <button class="sq-sub-alert-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <!-- Current Plan Status -->
        <div class="sq-sub-current-plan">
            <div class="sq-sub-plan-info">
                <h2>Current Plan</h2>
                <div class="sq-sub-plan-name">
                    <?php echo $plans[$currentPlan]['icon'] ? '<i class="fas ' . $plans[$currentPlan]['icon'] . '" style="color: ' . $plans[$currentPlan]['color'] . ';"></i>' : ''; ?>
                    <?php echo $plans[$currentPlan]['name']; ?>
                    <span class="sq-sub-plan-badge sq-sub-plan-badge--<?php echo $subscriptionStatus; ?>">
                        <i class="fas fa-circle" style="font-size: 8px;"></i>
                        <?php echo ucfirst($subscriptionStatus); ?>
                    </span>
                </div>
                <div class="sq-sub-plan-price">
                    <strong><?php echo formatCurrency($plans[$currentPlan]['price']); ?></strong>
                    <?php echo $plans[$currentPlan]['price'] > 0 ? '/month' : 'Forever Free'; ?>
                </div>
                <?php if ($isCancelledPendingExpiry): ?>
                    <div class="sq-sub-cancel-notice">
                        <i class="fas fa-info-circle"></i>
                        <span>Your subscription is cancelled but remains active until
                            <strong><?php echo formatDate($nextBillingDate); ?></strong> (<?php echo $daysRemaining; ?> days
                            left). After that, your account will move to Freemium.</span>
                    </div>
                <?php endif; ?>
                <div class="sq-sub-plan-meta">
                    <?php if ($nextBillingDate && $plans[$currentPlan]['price'] > 0): ?>
                        <div class="sq-sub-plan-meta-item">
                            <i class="fas fa-calendar"></i>
                            <span><?php echo $isCancelledPendingExpiry ? 'Access ends' : 'Renews'; ?>:
                                <?php echo formatDate($nextBillingDate); ?></span>
                        </div>
                        <div class="sq-sub-plan-meta-item">
                            <i class="fas fa-clock"></i>
                            <span class="sq-sub-days-remaining">
                                <i class="fas fa-hourglass-half"></i>
                                <?php echo $daysRemaining; ?> days remaining
                            </span>
                        </div>
                    <?php endif; ?>
                    <div class="sq-sub-plan-meta-item">
                        <i class="fas fa-envelope"></i>
                        <span><?php echo htmlspecialchars($userEmail); ?></span>
                    </div>
                </div>
            </div>

            <div class="sq-sub-plan-actions">
                <?php if ($isCancelledPendingExpiry): ?>
                    <form method="POST" id="resubscribeForm" style="display: inline;">
                        <input type="hidden" name="subscription_action" value="resubscribe">
                        <button type="submit" class="sq-sub-btn sq-sub-btn--primary" id="resubscribeBtn">
                            <i class="fas fa-redo"></i> Resubscribe
                        </button>
                    </form>
                <?php elseif ($subscriptionStatus === 'active' && $plans[$currentPlan]['price'] > 0): ?>
                    <button class="sq-sub-btn sq-sub-btn--danger" onclick="showCancelModal()">
                        <i class="fas fa-times"></i> Cancel Subscription
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <h2 class="sq-sub-plans-title">Available Plans</h2>

        <div class="sq-sub-plans-grid">
            <?php foreach ($plans as $planKey => $plan):
                $isCurrent = $planKey === $currentPlan;
                $canUpgrade = $plan['price'] > $plans[$currentPlan]['price'];
                $canDowngrade = $plan['price'] < $plans[$currentPlan]['price'] && $plan['price'] > 0;
                ?>
                <div class="sq-sub-plan-card <?php echo $isCurrent ? 'sq-sub-plan-card--current' : ''; ?> <?php echo $plan['popular'] ? 'sq-sub-plan-card--popular' : ''; ?>"
                    data-plan="<?php echo $planKey; ?>">

                    <?php if ($plan['popular']): ?>
                        <span class="sq-sub-plan-popular-badge">Most Popular</span>
                    <?php endif; ?>

                    <div class="sq-sub-plan-header">
                        <div class="sq-sub-plan-icon">
                            <i class="fas <?php echo $plan['icon']; ?>"></i>
                        </div>
                        <h3 class="sq-sub-plan-card-title">
                            <?php echo $plan['name']; ?>
                        </h3>
                        <div class="sq-sub-plan-card-price">
                            <?php echo formatCurrency($plan['price']); ?>
                            <?php if ($plan['price'] > 0): ?>
                                <span>/month</span>
                            <?php else: ?>
                                <span>Free</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <p class="sq-sub-plan-description">
                        <?php echo $plan['description']; ?>
                    </p>

                    <div class="sq-sub-plan-features">
                        <?php foreach ($plan['features'] as $feature): ?>
                            <div class="sq-sub-plan-feature">
                                <i class="fas fa-check-circle"></i>
                                <span>
                                    <?php echo $feature; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>

                        <?php foreach ($plan['limitations'] as $limitation): ?>
                            <div class="sq-sub-plan-feature sq-sub-plan-feature--limitation">
                                <i class="fas fa-times-circle"></i>
                                <span>
                                    <?php echo $limitation; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="sq-sub-plan-cta">
                        <?php if ($isCurrent): ?>
                            <div class="sq-sub-plan-current-label">
                                <i class="fas fa-check"></i> Current Plan
                            </div>
                        <?php elseif ($canUpgrade): ?>
                            <form action="../../../Payment/PHP/Frontend/Payment1.php" method="POST" style="display: inline;">
                                <input type="hidden" name="package" value="<?php echo htmlspecialchars($planKey); ?>">
                                <input type="hidden" name="price" value="<?php echo $plan['price']; ?>">
                                <input type="hidden" name="action" value="upgrade">
                                <input type="hidden" name="plan_name" value="<?php echo htmlspecialchars($plan['name']); ?>">
                                <button type="submit" class="sq-sub-btn sq-sub-btn--primary">
                                    <i class="fas fa-arrow-up"></i> Upgrade
                                </button>
                            </form>
                        <?php elseif ($canDowngrade): ?>
                            <form action="../../../Payment/PHP/Frontend/Payment1.php" method="POST" style="display: inline;">
                                <input type="hidden" name="package" value="<?php echo htmlspecialchars($planKey); ?>">
                                <input type="hidden" name="price" value="<?php echo $plan['price']; ?>">
                                <input type="hidden" name="action" value="downgrade">
                                <input type="hidden" name="plan_name" value="<?php echo htmlspecialchars($plan['name']); ?>">
                                <button type="submit" class="sq-sub-btn sq-sub-btn--secondary">
                                    <i class="fas fa-arrow-down"></i> Downgrade
                                </button>
                            </form>
                        <?php elseif ($planKey === 'freemium' && $currentPlan !== 'freemium' && !$isCancelledPendingExpiry): ?>
                            <button class="sq-sub-btn sq-sub-btn--secondary" onclick="showCancelModal()">
                                <i class="fas fa-arrow-down"></i> Switch to Free
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Payment History -->
        <div class="sq-sub-history">
            <div class="sq-sub-history-header">
                <h3 class="sq-sub-history-title">Payment History</h3>
            </div>

            <?php if (empty($paymentHistory)): ?>
                <div class="sq-sub-empty">
                    <i class="fas fa-receipt"></i>
                    <p>No payment history available</p>
                </div>
            <?php else: ?>
                <table class="sq-sub-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Plan</th>
                            <th>Amount</th>
                            <th>Transaction ID</th>
                            <th>Status</th>
                            <th>Account</th>
                            <th>Expires</th>
                            <th>Cancelled</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paymentHistory as $payment): ?>
                            <tr>
                                <td><?php echo formatDate($payment['created_at']); ?></td>
                                <td>
                                    <span style="font-weight: 600; text-transform: capitalize;">
                                        <?php echo htmlspecialchars($payment['package']); ?>
                                    </span>
                                </td>
                                <td><?php echo formatCurrency($payment['amount']); ?></td>
                                <td>
                                    <code
                                        style="background: var(--sq-sub-bg); padding: 4px 8px; border-radius: 6px; font-size: 12px;">
                                                                                                                                                                                                                <?php echo htmlspecialchars($payment['transaction_id'] ?? 'N/A'); ?>
                                                                                                                                                                                                            </code>
                                </td>
                                <td>
                                    <span class="sq-sub-status sq-sub-status--<?php echo $payment['status']; ?>">
                                        <i class="fas fa-circle" style="font-size: 6px;"></i>
                                        <?php echo ucfirst($payment['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span
                                        class="sq-sub-status sq-sub-status--<?php echo ($payment['account_status'] ?? 'active'); ?>">
                                        <i class="fas fa-circle" style="font-size: 6px;"></i>
                                        <?php echo ucfirst($payment['account_status'] ?? 'active'); ?>
                                    </span>
                                </td>
                                <td><?php echo formatDate($payment['expires_at']); ?></td>
                                <td><?php echo !empty($payment['cancelled_at']) ? formatDate($payment['cancelled_at']) : '—'; ?>
                                </td>
                                <td><?php echo !empty($payment['cancellation_reason']) ? htmlspecialchars($payment['cancellation_reason']) : '—'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <footer class="sq-sub-footer">
            <p>Questions? Contact our billing team at billing@scanquotient.com</p>
            <p style="margin-top: 8px;">ScanQuotient Security Platform • Quantifying Risk. Strengthening Security.</p>
        </footer>
    </main>

    <script>
        // Theme Toggle
        function toggleSubTheme() {
            document.body.classList.toggle('sq-sub-dark');
            const icon = document.getElementById('subThemeIcon');
            icon.className = document.body.classList.contains('sq-sub-dark') ? 'fas fa-moon' : 'fas fa-sun';
            localStorage.setItem('sq-sub-theme', document.body.classList.contains('sq-sub-dark') ? 'dark' : 'light');
        }

        // Load saved theme
        if (localStorage.getItem('sq-sub-theme') === 'dark') {
            document.body.classList.add('sq-sub-dark');
            document.getElementById('subThemeIcon').className = 'fas fa-moon';
        }

        // Modal elements
        const modal = document.getElementById('confirmModal');
        const modalIcon = document.getElementById('modalIcon');
        const modalTitle = document.getElementById('modalTitle');
        const modalDesc = document.getElementById('modalDesc');
        const modalAction = document.getElementById('modalAction');
        const modalTargetPlan = document.getElementById('modalTargetPlan');
        const modalConfirmBtn = document.getElementById('modalConfirmBtn');
        const cancelReasonBlock = document.getElementById('cancelReasonBlock');
        const cancelOtherWrap = document.getElementById('cancelOtherWrap');
        const cancellationReason = document.getElementById('cancellationReason');
        const cancellationReasonOther = document.getElementById('cancellationReasonOther');

        function showCancelModal() {
            modalIcon.className = 'sq-sub-modal-icon sq-sub-modal-icon--cancel';
            modalIcon.innerHTML = '<i class="fas fa-times"></i>';
            modalTitle.textContent = 'Cancel Subscription?';
            modalDesc.textContent = 'Your plan stays active until the end of your billing period. After that, your account moves to Freemium. You can resubscribe anytime before access ends.';
            modalAction.value = 'cancel';
            modalTargetPlan.value = '';
            modalConfirmBtn.textContent = 'Yes, Cancel';
            modalConfirmBtn.className = 'sq-sub-btn sq-sub-btn--danger';
            cancelReasonBlock.style.display = 'block';
            document.getElementById('confirmModal').querySelector('.sq-sub-modal').classList.add('sq-sub-modal--cancel');
            cancellationReason.required = true;
            cancellationReason.selectedIndex = 0;
            cancellationReasonOther.value = '';
            cancelOtherWrap.classList.remove('sq-sub-cancel-other-wrap--visible');
            cancellationReasonOther.required = false;
            modal.classList.add('sq-sub-modal-overlay--active');
        }

        function closeSubModal() {
            modal.classList.remove('sq-sub-modal-overlay--active');
            cancelReasonBlock.style.display = 'none';
            document.getElementById('confirmModal').querySelector('.sq-sub-modal').classList.remove('sq-sub-modal--cancel');
            cancellationReason.required = false;
            cancellationReasonOther.required = false;
            cancelOtherWrap.classList.remove('sq-sub-cancel-other-wrap--visible');
        }

        cancellationReason.addEventListener('change', function () {
            const showOther = this.value === 'other';
            cancelOtherWrap.classList.toggle('sq-sub-cancel-other-wrap--visible', showOther);
            cancellationReasonOther.required = showOther;
            document.getElementById('cancelOtherHint').textContent = showOther
                ? 'Required : tell us more so we can improve (up to 300 characters)'
                : 'Please describe your reason — up to 300 characters';
            if (showOther) {
                setTimeout(function () { cancellationReasonOther.focus(); }, 200);
            }
        });

        function sqLockActionButton(btn, processingText) {
            if (!btn || btn.disabled) return;
            btn.disabled = true;
            btn.classList.add('sq-sub-btn--busy');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + processingText;
        }

        document.getElementById('modalForm').addEventListener('submit', function (e) {
            if (modalAction.value === 'cancel') {
                if (!cancellationReason.value) {
                    e.preventDefault();
                    cancellationReason.focus();
                    return;
                }
                if (cancellationReason.value === 'other' && !cancellationReasonOther.value.trim()) {
                    e.preventDefault();
                    cancellationReasonOther.focus();
                    return;
                }
            }

            if (!e.defaultPrevented) {
                const busyLabels = {
                    cancel: 'Cancelling...',
                    upgrade: 'Upgrading...',
                    downgrade: 'Switching...'
                };
                sqLockActionButton(modalConfirmBtn, busyLabels[modalAction.value] || 'Processing...');
                this.querySelectorAll('button[type="button"]').forEach(function (b) { b.disabled = true; });
            }
        });

        const resubscribeForm = document.getElementById('resubscribeForm');
        if (resubscribeForm) {
            resubscribeForm.addEventListener('submit', function () {
                sqLockActionButton(document.getElementById('resubscribeBtn'), 'Resubscribing...');
            });
        }

        // Close on outside click
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeSubModal();
        });

        // Close on ESC
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeSubModal();
        });

        // Auto-hide alerts
        setTimeout(function () {
            document.querySelectorAll('.sq-sub-alert').forEach(function (alert) {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(function () { alert.remove(); }, 300);
            });
        }, 5000);
    </script>

</body>

</html>