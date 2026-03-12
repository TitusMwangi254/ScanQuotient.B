<?php
// payment.php - Payment Details Page
session_start();

// Get package from URL
$package = $_GET['package'] ?? 'pro';
$price = floatval($_GET['price'] ?? 10.00);

// Validate
$validPackages = [
    'pro' => ['name' => 'Pro', 'price' => 10.00],
    'enterprise' => ['name' => 'Enterprise Suite', 'price' => 25.00]
];

if (!isset($validPackages[$package])) {
    header('Location: packages.php');
    exit;
}

$packageName = $validPackages[$package]['name'];
$price = $validPackages[$package]['price'];
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScanQuotient | Complete Payment</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        :root {
            --bg-primary: #f0f4f8;
            --bg-secondary: #ffffff;
            --text-primary: #1a202c;
            --text-secondary: #4a5568;
            --accent-color: #2563eb;
            --border-color: rgba(255, 255, 255, 0.2);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            --error-color: #ef4444;
            --success-color: #10b981;
            --header-bg: rgba(255, 255, 255, 0.1);
            --header-icon-color: #2563eb;
            --footer-gradient: linear-gradient(to right, #ffffff, #ADD8E6);
        }

        [data-theme="dark"] {
            --bg-primary: #0f172a;
            --bg-secondary: #1e293b;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --accent-color: #3b82f6;
            --border-color: rgba(255, 255, 255, 0.1);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.3);
            --header-bg: rgba(15, 23, 42, 0.6);
            --header-icon-color: #D6BCFA;
            --footer-gradient: linear-gradient(to right, #000000, #D6BCFA);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
        }

        .header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background: var(--header-bg);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 2rem;
            transition: all 0.3s ease;
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .brand h1 {
            font-size: 1.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--accent-color), #7c3aed);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .brand .tagline {
            font-size: 0.7rem;
            color: var(--text-secondary);
            letter-spacing: 1.5px;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .icon-btn {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border-color);
            color: var(--header-icon-color);
            width: 40px;
            height: 40px;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            text-decoration: none;
            backdrop-filter: blur(10px);
        }

        .icon-btn:hover {
            background: var(--header-icon-color);
            color: white;
            border-color: var(--header-icon-color);
            transform: translateY(-2px);
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 7rem 1.5rem 3rem;
        }

        .payment-card {
            background: var(--bg-secondary);
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: var(--shadow-lg);
        }

        .payment-card h2 {
            margin-bottom: 1.5rem;
            font-size: 1.75rem;
            text-align: center;
        }

        .summary-box {
            background: var(--bg-primary);
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            color: var(--text-secondary);
        }

        .summary-row.total {
            border-top: 2px solid var(--border-color);
            padding-top: 0.75rem;
            margin-top: 0.75rem;
            font-weight: 700;
            color: var(--text-primary);
            font-size: 1.25rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .form-control {
            width: 100%;
            padding: 1rem;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 1rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-color);
        }

        .error-message {
            color: var(--error-color);
            font-size: 0.875rem;
            margin-top: 0.5rem;
            display: none;
        }

        .submit-btn {
            width: 100%;
            padding: 1.25rem;
            background: linear-gradient(135deg, #003087, #0070ba);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.125rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }

        .submit-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(0, 112, 186, 0.3);
        }

        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .paypal-logo {
            font-size: 1.5rem;
        }

        .footer {
            background: var(--footer-gradient);
            padding: 2rem;
            text-align: center;
            border-top: 1px solid var(--border-color);
            margin-top: 3rem;
            color: var(--text-primary);
        }

        .secure-badge {
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.875rem;
            margin-top: 1rem;
        }

        .secure-badge i {
            color: var(--success-color);
            margin-right: 0.5rem;
        }
    </style>
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

                <a href="../../../Help_center/PHP/Frontend/Help_center.php" class="icon-btn" title="Help">
                    <i class="fas fa-question-circle"></i>
                </a>

                <a href="../../../Help_center/PHP/Frontend/Help_center.php" class="icon-btn" title="Home">
                    <i class="fas fa-home"></i>
                </a>
            </div>
        </div>
    </header>

    <div class="container">
        <div class="payment-card">
            <h2>Complete Your Purchase</h2>

            <div class="summary-box">
                <div class="summary-row">
                    <span>Selected Plan:</span>
                    <strong>
                        <?php echo htmlspecialchars($packageName); ?>
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
                <input type="hidden" name="package" value="<?php echo htmlspecialchars($package); ?>">
                <input type="hidden" name="price" value="<?php echo $price; ?>">

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

        // NEW: AJAX Submission to PayPal API
        document.getElementById('paymentForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const email = emailInput.value;
            const package = '<?php echo $package; ?>';
            const price = <?php echo $price; ?>;

            // Disable button and show loading
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Connecting to PayPal...';

            // Send to our API endpoint.php
            fetch('../../PHP/Backend/paypal_api543.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    email: email,
                    package: package,
                    price: price
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.approval_url) {
                        // Redirect to PayPal
                        window.location.href = data.approval_url;
                    } else {
                        alert('Error: ' + (data.error || 'Could not connect to PayPal'));
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