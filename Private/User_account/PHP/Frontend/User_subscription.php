<?php
// user_subscription.php
session_start();

// Authentication check
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header("Location: /ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Frontend/login_page_site.php?error=not_authenticated");
    exit();
}

$userId = $_SESSION['user_id'] ?? null;
$userEmail = $_SESSION['user_email'] ?? '';
$userName = $_SESSION['user_name'] ?? 'User';

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

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Get user's payment/subscription history
    $stmt = $pdo->prepare("
        SELECT * FROM payments 
        WHERE email = ? AND deleted_at IS NULL 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$userEmail]);
    $paymentHistory = $stmt->fetchAll();

    // Determine current active plan
    if (!empty($paymentHistory)) {
        $latestPayment = $paymentHistory[0];
        if (
            $latestPayment['status'] === 'completed' &&
            (!isset($latestPayment['expires_at']) || strtotime($latestPayment['expires_at']) > time())
        ) {
            $currentPlan = strtolower($latestPayment['package']);
            $subscriptionStatus = 'active';
            $nextBillingDate = $latestPayment['expires_at'];
        } elseif (isset($latestPayment['expires_at']) && strtotime($latestPayment['expires_at']) < time()) {
            $subscriptionStatus = 'expired';
            $currentPlan = 'freemium'; // Fallback to free
        }
    }

    // Handle plan upgrade/downgrade
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['subscription_action'] ?? '';

        switch ($action) {
            case 'upgrade':
            case 'downgrade':
                $targetPlan = $_POST['target_plan'] ?? '';
                if (isset($plans[$targetPlan])) {
                    // In real implementation, redirect to payment gateway
                    // For now, simulate successful payment
                    $amount = $plans[$targetPlan]['price'];
                    $transactionId = 'TXN_' . strtoupper(bin2hex(random_bytes(8)));
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

                    $insertStmt = $pdo->prepare("
                        INSERT INTO payments (email, package, amount, transaction_id, status, payment_method, expires_at, created_at) 
                        VALUES (?, ?, ?, ?, 'completed', 'paypal', ?, NOW())
                    ");
                    $insertStmt->execute([$userEmail, $targetPlan, $amount, $transactionId, $expiresAt]);

                    $successMessage = "Successfully " . ($action === 'upgrade' ? 'upgraded to' : 'downgraded to') . " " . $plans[$targetPlan]['name'] . "!";

                    // Refresh data
                    $stmt->execute([$userEmail]);
                    $paymentHistory = $stmt->fetchAll();
                    $currentPlan = $targetPlan;
                    $nextBillingDate = $expiresAt;
                    $subscriptionStatus = 'active';
                }
                break;

            case 'cancel':
                // Soft delete latest payment (simulate cancellation)
                if (!empty($paymentHistory)) {
                    $updateStmt = $pdo->prepare("
                        UPDATE payments 
                        SET deleted_at = NOW(), deleted_by = ? 
                        WHERE id = ? AND deleted_at IS NULL
                    ");
                    $updateStmt->execute([$userId, $paymentHistory[0]['id']]);
                    $successMessage = "Subscription cancelled. You will revert to Freemium at the end of your billing period.";
                    $subscriptionStatus = 'canceling';
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
    <style>
        :root {
            --sq-sub-bg: #f0f7ff;
            --sq-sub-card: #ffffff;
            --sq-sub-text: #1e293b;
            --sq-sub-text-light: #64748b;
            --sq-sub-primary: #3b82f6;
            --sq-sub-primary-light: #60a5fa;
            --sq-sub-success: #10b981;
            --sq-sub-warning: #f59e0b;
            --sq-sub-danger: #ef4444;
            --sq-sub-purple: #8b5cf6;
            --sq-sub-gray: #94a3b8;
            --sq-sub-border: #e2e8f0;
            --sq-sub-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --sq-sub-shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        body.sq-sub-dark {
            --sq-sub-bg: #0f172a;
            --sq-sub-card: #1e293b;
            --sq-sub-text: #f1f5f9;
            --sq-sub-text-light: #94a3b8;
            --sq-sub-primary: #60a5fa;
            --sq-sub-primary-light: #93c5fd;
            --sq-sub-border: rgba(148, 163, 184, 0.2);
            --sq-sub-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
            --sq-sub-shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--sq-sub-bg);
            color: var(--sq-sub-text);
            min-height: 100vh;
            line-height: 1.6;
        }

        /* Header */
        .sq-sub-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 32px;
            background: linear-gradient(to right, #ffffff, #ADD8E6);
            border-bottom: 1px solid var(--sq-sub-border);
            box-shadow: var(--sq-sub-shadow);
        }

        body.sq-sub-dark .sq-sub-header {
            background: linear-gradient(135deg, #2e1065 0%, #1e293b 100%);
        }

        .sq-sub-header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .sq-sub-back-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: 1px solid var(--sq-sub-border);
            background: rgba(255, 255, 255, 0.8);
            color: var(--sq-sub-text-light);
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 16px;
            transition: all 0.3s;
        }

        body.sq-sub-dark .sq-sub-back-btn {
            background: rgba(0, 0, 0, 0.2);
        }

        .sq-sub-back-btn:hover {
            background: var(--sq-sub-primary);
            color: white;
            transform: translateX(-3px);
        }

        .sq-sub-brand {
            font-weight: 800;
            font-size: 24px;
            color: var(--sq-sub-primary);
            text-decoration: none;
        }

        body.sq-sub-dark .sq-sub-brand {
            color: var(--sq-sub-primary-light);
        }

        .sq-sub-header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sq-sub-theme-toggle {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: 1px solid var(--sq-sub-border);
            background: rgba(255, 255, 255, 0.8);
            color: var(--sq-sub-text-light);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            transition: all 0.3s;
        }

        body.sq-sub-dark .sq-sub-theme-toggle {
            background: rgba(0, 0, 0, 0.2);
        }

        .sq-sub-theme-toggle:hover {
            background: var(--sq-sub-primary);
            color: white;
        }

        /* Container */
        .sq-sub-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        /* Alert */
        .sq-sub-alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            font-weight: 500;
            animation: sq-sub-slide-down 0.3s ease;
        }

        @keyframes sq-sub-slide-down {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .sq-sub-alert--success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: var(--sq-sub-success);
        }

        .sq-sub-alert--error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: var(--sq-sub-danger);
        }

        .sq-sub-alert-close {
            margin-left: auto;
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            font-size: 16px;
            opacity: 0.6;
        }

        .sq-sub-alert-close:hover {
            opacity: 1;
        }

        /* Current Plan Banner */
        .sq-sub-current-plan {
            background: var(--sq-sub-card);
            border: 1px solid var(--sq-sub-border);
            border-radius: 20px;
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: var(--sq-sub-shadow);
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 32px;
            align-items: center;
        }

        @media (max-width: 768px) {
            .sq-sub-current-plan {
                grid-template-columns: 1fr;
            }
        }

        .sq-sub-plan-info h2 {
            font-size: 14px;
            font-weight: 700;
            color: var(--sq-sub-text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .sq-sub-plan-name {
            font-size: 32px;
            font-weight: 800;
            color: var(--sq-sub-text);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sq-sub-plan-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .sq-sub-plan-badge--active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--sq-sub-success);
        }

        .sq-sub-plan-badge--expired {
            background: rgba(239, 68, 68, 0.1);
            color: var(--sq-sub-danger);
        }

        .sq-sub-plan-badge--canceling {
            background: rgba(245, 158, 11, 0.1);
            color: var(--sq-sub-warning);
        }

        .sq-sub-plan-price {
            font-size: 18px;
            color: var(--sq-sub-text-light);
            margin-bottom: 16px;
        }

        .sq-sub-plan-price strong {
            color: var(--sq-sub-text);
            font-size: 24px;
        }

        .sq-sub-plan-meta {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }

        .sq-sub-plan-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--sq-sub-text-light);
        }

        .sq-sub-plan-meta-item i {
            color: var(--sq-sub-primary);
        }

        .sq-sub-plan-actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .sq-sub-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 28px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            text-decoration: none;
        }

        .sq-sub-btn--primary {
            background: var(--sq-sub-primary);
            color: white;
            box-shadow: 0 4px 14px rgba(59, 130, 246, 0.3);
        }

        .sq-sub-btn--primary:hover {
            background: var(--sq-sub-primary-light);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
        }

        .sq-sub-btn--secondary {
            background: transparent;
            color: var(--sq-sub-text-light);
            border: 2px solid var(--sq-sub-border);
        }

        .sq-sub-btn--secondary:hover {
            border-color: var(--sq-sub-danger);
            color: var(--sq-sub-danger);
        }

        .sq-sub-btn--danger {
            background: var(--sq-sub-danger);
            color: white;
        }

        .sq-sub-btn--danger:hover {
            background: #dc2626;
        }

        /* Plans Grid */
        .sq-sub-plans-title {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 24px;
            color: var(--sq-sub-text);
        }

        .sq-sub-plans-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-bottom: 40px;
        }

        @media (max-width: 968px) {
            .sq-sub-plans-grid {
                grid-template-columns: 1fr;
            }
        }

        .sq-sub-plan-card {
            background: var(--sq-sub-card);
            border: 2px solid var(--sq-sub-border);
            border-radius: 20px;
            padding: 32px;
            position: relative;
            transition: all 0.3s;
            display: flex;
            flex-direction: column;
        }

        .sq-sub-plan-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--sq-sub-shadow-lg);
        }

        .sq-sub-plan-card--current {
            border-color: var(--sq-sub-success);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
        }

        .sq-sub-plan-card--popular {
            border-color: var(--sq-sub-primary);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .sq-sub-plan-popular-badge {
            position: absolute;
            top: -12px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--sq-sub-primary);
            color: white;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .sq-sub-plan-header {
            text-align: center;
            margin-bottom: 24px;
        }

        .sq-sub-plan-icon {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin: 0 auto 16px;
        }

        .sq-sub-plan-card[data-plan="freemium"] .sq-sub-plan-icon {
            background: rgba(100, 116, 139, 0.1);
            color: #64748b;
        }

        .sq-sub-plan-card[data-plan="pro"] .sq-sub-plan-icon {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .sq-sub-plan-card[data-plan="enterprise"] .sq-sub-plan-icon {
            background: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
        }

        .sq-sub-plan-card-title {
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .sq-sub-plan-card-price {
            font-size: 36px;
            font-weight: 800;
            color: var(--sq-sub-text);
        }

        .sq-sub-plan-card-price span {
            font-size: 16px;
            font-weight: 500;
            color: var(--sq-sub-text-light);
        }

        .sq-sub-plan-description {
            font-size: 14px;
            color: var(--sq-sub-text-light);
            text-align: center;
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .sq-sub-plan-features {
            flex: 1;
            margin-bottom: 24px;
        }

        .sq-sub-plan-feature {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
            font-size: 14px;
        }

        .sq-sub-plan-feature i {
            color: var(--sq-sub-success);
            margin-top: 3px;
        }

        .sq-sub-plan-feature--limitation {
            color: var(--sq-sub-text-light);
        }

        .sq-sub-plan-feature--limitation i {
            color: var(--sq-sub-gray);
        }

        .sq-sub-plan-cta {
            width: 100%;
        }

        .sq-sub-plan-cta .sq-sub-btn {
            width: 100%;
        }

        .sq-sub-plan-current-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px;
            background: rgba(16, 185, 129, 0.1);
            color: var(--sq-sub-success);
            border-radius: 12px;
            font-weight: 700;
        }

        /* Payment History */
        .sq-sub-history {
            background: var(--sq-sub-card);
            border: 1px solid var(--sq-sub-border);
            border-radius: 20px;
            padding: 32px;
            box-shadow: var(--sq-sub-shadow);
        }

        .sq-sub-history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .sq-sub-history-title {
            font-size: 20px;
            font-weight: 800;
        }

        .sq-sub-table {
            width: 100%;
            border-collapse: collapse;
        }

        .sq-sub-table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 12px;
            font-weight: 700;
            color: var(--sq-sub-text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--sq-sub-border);
        }

        .sq-sub-table td {
            padding: 16px;
            border-bottom: 1px solid var(--sq-sub-border);
            font-size: 14px;
        }

        .sq-sub-table tr:last-child td {
            border-bottom: none;
        }

        .sq-sub-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .sq-sub-status--completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--sq-sub-success);
        }

        .sq-sub-status--pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--sq-sub-warning);
        }

        .sq-sub-status--failed {
            background: rgba(239, 68, 68, 0.1);
            color: var(--sq-sub-danger);
        }

        .sq-sub-empty {
            text-align: center;
            padding: 40px;
            color: var(--sq-sub-text-light);
        }

        .sq-sub-empty i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.3;
        }

        /* Modal */
        .sq-sub-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(8px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .sq-sub-modal-overlay--active {
            display: flex;
        }

        .sq-sub-modal {
            background: var(--sq-sub-card);
            border-radius: 24px;
            max-width: 480px;
            width: 100%;
            padding: 40px;
            text-align: center;
            animation: sq-sub-modal-in 0.3s ease;
        }

        @keyframes sq-sub-modal-in {
            from {
                opacity: 0;
                transform: scale(0.9);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .sq-sub-modal-icon {
            width: 80px;
            height: 80px;
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            margin: 0 auto 24px;
        }

        .sq-sub-modal-icon--upgrade {
            background: rgba(59, 130, 246, 0.1);
            color: var(--sq-sub-primary);
        }

        .sq-sub-modal-icon--downgrade {
            background: rgba(245, 158, 11, 0.1);
            color: var(--sq-sub-warning);
        }

        .sq-sub-modal-icon--cancel {
            background: rgba(239, 68, 68, 0.1);
            color: var(--sq-sub-danger);
        }

        .sq-sub-modal h3 {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 12px;
        }

        .sq-sub-modal p {
            color: var(--sq-sub-text-light);
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .sq-sub-modal-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .sq-sub-footer {
            margin-top: 40px;
            padding: 24px;
            text-align: center;
            color: var(--sq-sub-text-light);
            font-size: 13px;
            border-top: 1px solid var(--sq-sub-border);
        }

        .sq-sub-header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        /* Back Button */
        .sq-sub-back-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border: none;
            border-radius: 6px;
            background-color: #8b5cf6;
            /* Brand Purple */
            color: #ffffff;
            font-size: 14px;
            cursor: pointer;
        }

        .sq-sub-back-btn:hover {
            opacity: 0.9;
        }

        /* Brand Wrapper */
        .sq-brand-wrapper {
            display: flex;
            flex-direction: column;
        }

        /* Brand Name */
        .sq-sub-brand {
            color: #8b5cf6;
            font-weight: 700;
            text-decoration: none;
            font-size: 18px;
        }

        /* Tagline */
        .sq-sub-tagline {
            font-size: 12px;
            color: #8b5cf6;
            opacity: 0.75;
            margin: 2px 0 0 0;
        }
    </style>
</head>

<body>

    <header class="sq-sub-header">
        <div class="sq-sub-header-left">

            <!-- Normal Previous Button -->
            <button type="button" class="sq-sub-back-btn" onclick="history.back()">
                <i class="fas fa-arrow-left"></i>

            </button>

            <!-- Brand + Tagline -->
            <div class="sq-brand-wrapper">
                <a href="#" class="sq-sub-brand">ScanQuotient</a>
                <p class="sq-sub-tagline">Quantifying Risk. Strengthening Security.</p>
            </div>

        </div>
        <div class="sq-sub-header-right">
            <button class="sq-sub-theme-toggle" onclick="toggleSubTheme()">
                <i class="fas fa-sun" id="subThemeIcon"></i>
            </button>
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
                <div class="sq-sub-plan-meta">
                    <?php if ($nextBillingDate && $plans[$currentPlan]['price'] > 0): ?>
                        <div class="sq-sub-plan-meta-item">
                            <i class="fas fa-calendar"></i>
                            <span>Next billing: <?php echo formatDate($nextBillingDate); ?></span>
                        </div>
                        <div class="sq-sub-plan-meta-item">
                            <i class="fas fa-clock"></i>
                            <span><?php echo getDaysRemaining($nextBillingDate); ?> days remaining</span>
                        </div>
                    <?php endif; ?>
                    <div class="sq-sub-plan-meta-item">
                        <i class="fas fa-envelope"></i>
                        <span><?php echo htmlspecialchars($userEmail); ?></span>
                    </div>
                </div>
            </div>

            <div class="sq-sub-plan-actions">
                <?php if ($subscriptionStatus === 'active' && $plans[$currentPlan]['price'] > 0): ?>
                    <button class="sq-sub-btn sq-sub-btn--secondary" onclick="showCancelModal()">
                        <i class="fas fa-times"></i> Cancel Subscription
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Available Plans -->
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
                        <h3 class="sq-sub-plan-card-title"><?php echo $plan['name']; ?></h3>
                        <div class="sq-sub-plan-card-price">
                            <?php echo formatCurrency($plan['price']); ?>
                            <?php if ($plan['price'] > 0): ?>
                                <span>/month</span>
                            <?php else: ?>
                                <span>Free</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <p class="sq-sub-plan-description"><?php echo $plan['description']; ?></p>

                    <div class="sq-sub-plan-features">
                        <?php foreach ($plan['features'] as $feature): ?>
                            <div class="sq-sub-plan-feature">
                                <i class="fas fa-check-circle"></i>
                                <span><?php echo $feature; ?></span>
                            </div>
                        <?php endforeach; ?>

                        <?php foreach ($plan['limitations'] as $limitation): ?>
                            <div class="sq-sub-plan-feature sq-sub-plan-feature--limitation">
                                <i class="fas fa-times-circle"></i>
                                <span><?php echo $limitation; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="sq-sub-plan-cta">
                        <?php if ($isCurrent): ?>
                            <div class="sq-sub-plan-current-label">
                                <i class="fas fa-check"></i> Current Plan
                            </div>
                        <?php elseif ($canUpgrade): ?>
                            <button class="sq-sub-btn sq-sub-btn--primary"
                                onclick="showUpgradeModal('<?php echo $planKey; ?>', '<?php echo $plan['name']; ?>')">
                                <i class="fas fa-arrow-up"></i> Upgrade
                            </button>
                        <?php elseif ($canDowngrade): ?>
                            <button class="sq-sub-btn sq-sub-btn--secondary"
                                onclick="showDowngradeModal('<?php echo $planKey; ?>', '<?php echo $plan['name']; ?>')">
                                <i class="fas fa-arrow-down"></i> Downgrade
                            </button>
                        <?php elseif ($planKey === 'freemium'): ?>
                            <button class="sq-sub-btn sq-sub-btn--secondary"
                                onclick="showDowngradeModal('<?php echo $planKey; ?>', '<?php echo $plan['name']; ?>')">
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
                            <th>Expires</th>
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
                                <td><?php echo formatDate($payment['expires_at']); ?></td>
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

        // Modal Functions
        const modal = document.getElementById('confirmModal');
        const modalIcon = document.getElementById('modalIcon');
        const modalTitle = document.getElementById('modalTitle');
        const modalDesc = document.getElementById('modalDesc');
        const modalAction = document.getElementById('modalAction');
        const modalTargetPlan = document.getElementById('modalTargetPlan');
        const modalConfirmBtn = document.getElementById('modalConfirmBtn');

        function showUpgradeModal(planKey, planName) {
            modalIcon.className = 'sq-sub-modal-icon sq-sub-modal-icon--upgrade';
            modalIcon.innerHTML = '<i class="fas fa-arrow-up"></i>';
            modalTitle.textContent = 'Upgrade to ' + planName;
            modalDesc.textContent = 'You will be charged immediately and gain access to all ' + planName + ' features. Your current plan will be upgraded today.';
            modalAction.value = 'upgrade';
            modalTargetPlan.value = planKey;
            modalConfirmBtn.textContent = 'Upgrade Now';
            modalConfirmBtn.className = 'sq-sub-btn sq-sub-btn--primary';
            modal.classList.add('sq-sub-modal-overlay--active');
        }

        function showDowngradeModal(planKey, planName) {
            modalIcon.className = 'sq-sub-modal-icon sq-sub-modal-icon--downgrade';
            modalIcon.innerHTML = '<i class="fas fa-arrow-down"></i>';
            modalTitle.textContent = 'Switch to ' + planName;
            modalDesc.textContent = 'Your current plan features will remain active until the end of your billing period, then switch to ' + planName + '.';
            modalAction.value = 'downgrade';
            modalTargetPlan.value = planKey;
            modalConfirmBtn.textContent = 'Confirm Switch';
            modalConfirmBtn.className = 'sq-sub-btn sq-sub-btn--secondary';
            modal.classList.add('sq-sub-modal-overlay--active');
        }

        function showCancelModal() {
            modalIcon.className = 'sq-sub-modal-icon sq-sub-modal-icon--cancel';
            modalIcon.innerHTML = '<i class="fas fa-times"></i>';
            modalTitle.textContent = 'Cancel Subscription?';
            modalDesc.textContent = 'You will lose access to premium features at the end of your current billing period. Your data will be preserved on the free plan.';
            modalAction.value = 'cancel';
            modalTargetPlan.value = '';
            modalConfirmBtn.textContent = 'Yes, Cancel';
            modalConfirmBtn.className = 'sq-sub-btn sq-sub-btn--danger';
            modal.classList.add('sq-sub-modal-overlay--active');
        }

        function closeSubModal() {
            modal.classList.remove('sq-sub-modal-overlay--active');
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