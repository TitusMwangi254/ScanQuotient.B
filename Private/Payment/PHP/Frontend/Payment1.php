<?php
// payment.php - Payment Details Page
session_start();

// ============================================
// HANDLE BOTH POST (from form) AND GET (from URL)
// ============================================

// Check for POST data first (from the subscription form), fallback to GET
$package = $_POST['package'] ?? $_GET['package'] ?? 'pro';
$price = floatval($_POST['price'] ?? $_GET['price'] ?? 10.00);
$action = $_POST['action'] ?? $_GET['action'] ?? 'upgrade'; // 'upgrade' or 'downgrade'
$planName = $_POST['plan_name'] ?? null; // Optional custom plan name from form

// ============================================
// VALIDATE PACKAGE DATA
// ============================================

$validPackages = [
    'freemium' => ['name' => 'Freemium', 'price' => 0.00],
    'basic' => ['name' => 'Basic', 'price' => 5.00],
    'pro' => ['name' => 'Pro', 'price' => 10.00],
    'enterprise' => ['name' => 'Enterprise Suite', 'price' => 25.00]
];

// Validate package exists
if (!isset($validPackages[$package])) {
    header('Location: packages.php');
    exit;
}

// ============================================
// SECURITY: Override price with server-side value
// ============================================
// NEVER trust the price from the form - always use server-side value
$packageName = $planName ?? $validPackages[$package]['name'];
$price = $validPackages[$package]['price'];

// Handle freemium/free plans specially
if ($price == 0) {
    header('Location: process_free_plan.php?package=' . urlencode($package) . '&action=' . urlencode($action));
    exit;
}

// ============================================
// VALIDATE ACTION
// ============================================
$validActions = ['upgrade', 'downgrade', 'new'];
if (!in_array($action, $validActions)) {
    $action = 'upgrade'; // Default fallback
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScanQuotient | Complete Payment</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../../CSS/payment1.css" />

</head>

<body>

    <header class="header">
        <div class="nav-container">
            <div class="brand">
                <h1>ScanQuotient</h1>
                <span class="tagline">Quantifying Risk. Strengthening Security</span>
            </div>
            <div class="header-actions">
                <button class="icon-btn" id="theme-toggle" title="Toggle Theme">
                    <i class="fas fa-moon"></i>
                </button>

                <a href="/ScanQuotient.v2/ScanQuotient.B/Private/User_dashboard/PHP/Frontend/User_dashboard.php"
                    class="icon-btn" title="Home">
                    <i class="fas fa-home"></i>
                </a>
            </div>
        </div>
    </header>

    <div class="container">
        <div class="payment-card">
            <h2>Complete Your Purchase</h2>

            <!-- Show action type to user -->
            <div class="action-badge"
                style="text-align: center; margin-bottom: 1rem; padding: 0.5rem; background: <?php echo $action === 'upgrade' ? '#d4edda' : ($action === 'downgrade' ? '#fff3cd' : '#d1ecf1'); ?>; color: <?php echo $action === 'upgrade' ? '#155724' : ($action === 'downgrade' ? '#856404' : '#0c5460'); ?>; border-radius: 6px; font-weight: 600; text-transform: uppercase; font-size: 0.85rem;">
                <i
                    class="fas fa-<?php echo $action === 'upgrade' ? 'arrow-up' : ($action === 'downgrade' ? 'arrow-down' : 'plus'); ?>"></i>
                <?php echo ucfirst($action); ?> Plan
            </div>

            <div class="summary-box">
                <div class="summary-row">
                    <span>Selected Plan:</span>
                    <strong>
                        <?php echo htmlspecialchars($packageName); ?>
                    </strong>
                </div>
                <div class="summary-row">
                    <span>Package ID:</span>
                    <strong>
                        <?php echo htmlspecialchars($package); ?>
                    </strong>
                </div>
                <div class="summary-row">
                    <span>Billing:</span>
                    <strong>Monthly</strong>
                </div>
                <div class="summary-row total">
                    <span>Total Due:</span>
                    <strong>$
                        <?php echo number_format($price, 2); ?> USD
                    </strong>
                </div>
            </div>

            <form id="paymentForm">
                <!-- Pass through all necessary data -->
                <input type="hidden" name="package" value="<?php echo htmlspecialchars($package); ?>">
                <input type="hidden" name="price" value="<?php echo $price; ?>">
                <input type="hidden" name="action" value="<?php echo htmlspecialchars($action); ?>">
                <input type="hidden" name="plan_name" value="<?php echo htmlspecialchars($packageName); ?>">

                <div class="form-group">
                    <label for="email">
                        <i class="fas fa-envelope"></i> Email Address
                    </label>
                    <input type="email" class="form-control" id="email" name="email"
                        placeholder="Enter your registered email address" required>
                    <div class="error-message" id="emailError">Please enter a valid email</div>
                </div>

                <button type="submit" class="submit-btn" id="submitBtn" disabled>
                    <i class="fab fa-paypal paypal-logo"></i>
                    Pay with PayPal
                </button>

                <div class="secure-badge">
                    <i class="fas fa-shield-alt"></i>
                    Secure payment processed by PayPal
                </div>
            </form>
        </div>
    </div>

    <footer class="footer">
        <div style="font-weight: 700; margin-bottom: 0.5rem;">ScanQuotient</div>
        <div>Quantifying Risk. Strengthening Security</div>
    </footer>

    <script>
        // Theme Toggle (keep existing)
        const themeToggle = document.getElementById('theme-toggle');
        const html = document.documentElement;
        const icon = themeToggle.querySelector('i');

        const savedTheme = localStorage.getItem('theme') || 'light';
        html.setAttribute('data-theme', savedTheme);
        updateIcon(savedTheme);

        themeToggle.addEventListener('click', () => {
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            updateIcon(newTheme);
        });

        function updateIcon(theme) {
            icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
        }

        // Form Validation
        const emailInput = document.getElementById('email');
        const submitBtn = document.getElementById('submitBtn');
        const emailError = document.getElementById('emailError');

        emailInput.addEventListener('input', () => {
            const email = emailInput.value;
            const isValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);

            emailError.style.display = email && !isValid ? 'block' : 'none';
            submitBtn.disabled = !isValid;
        });

        // AJAX Submission to PayPal API
        document.getElementById('paymentForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const email = emailInput.value;
            const package = '<?php echo $package; ?>';
            const price = <?php echo $price; ?>;
            const action = '<?php echo $action; ?>';
            const planName = '<?php echo addslashes($packageName); ?>';

            // Disable button and show loading
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Connecting to PayPal...';

            // Send to API endpoint
            fetch('/ScanQuotient.v2/ScanQuotient.B/Private/Payment/PHP/Backend/paypal_api234.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    email: email,
                    package: package,
                    price: price,
                    action: action,
                    plan_name: planName
                })
            })
                .then(async (response) => {
                    const contentType = response.headers.get('content-type') || '';
                    const isJson = contentType.includes('application/json');
                    const payload = isJson ? await response.json().catch(() => null) : await response.text().catch(() => '');
                    return { ok: response.ok, status: response.status, payload };
                })
                .then(data => {
                    const payload = data?.payload || {};
                    if (payload.approval_url) {
                        // Redirect to PayPal
                        window.location.href = payload.approval_url;
                    } else {
                        const msg =
                            payload?.error ||
                            (typeof payload === 'string' && payload ? payload : '') ||
                            `Could not connect to PayPal (HTTP ${data?.status ?? 'unknown'})`;
                        const extra = payload?.curl_error ? `\n\nDetails: ${payload.curl_error}` : '';
                        alert('Error: ' + msg + extra);
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fab fa-paypal paypal-logo"></i> Pay with PayPal';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to initialize PayPal payment. Please try again.');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fab fa-paypal paypal-logo"></i> Pay with PayPal';
                });
        });
    </script>

</body>

</html>