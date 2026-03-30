<?php
// packages.php - Package Selection Page
require_once __DIR__ . '/../../../security_headers.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScanQuotient | Choose Your Plan</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        :root {
            --bg-primary: #f0f4f8;
            --bg-secondary: #ffffff;
            --text-primary: #1a202c;
            --text-secondary: #4a5568;
            --accent-color: #2563eb;
            --accent-hover: #1d4ed8;
            --border-color: rgba(255, 255, 255, 0.2);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
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
            --accent-hover: #2563eb;
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

        /* Header Actions - Container for icons */
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
            max-width: 1200px;
            margin: 0 auto;
            padding: 7rem 1.5rem 3rem;
        }

        .page-header {
            text-align: center;
            margin-bottom: 3rem;
        }

        .page-header h2 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .page-header p {
            color: var(--text-secondary);
            font-size: 1.125rem;
            max-width: 600px;
            margin: 0 auto;
        }

        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            max-width: 900px;
            margin: 0 auto;
        }

        .pricing-card {
            background: var(--bg-secondary);
            border-radius: 20px;
            padding: 2rem;
            border: 2px solid var(--border-color);
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .pricing-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--accent-color);
        }

        .pricing-card.popular::before {
            content: "MOST POPULAR";
            position: absolute;
            top: 0;
            right: 0;
            background: linear-gradient(135deg, var(--accent-color), #7c3aed);
            color: white;
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
            font-weight: 700;
            border-bottom-left-radius: 10px;
        }

        .card-header {
            margin-bottom: 1.5rem;
        }

        .card-header h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .price {
            font-size: 3rem;
            font-weight: 800;
            color: var(--accent-color);
        }

        .price span {
            font-size: 1rem;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .features {
            list-style: none;
            margin: 1.5rem 0;
        }

        .features li {
            padding: 0.5rem 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--text-secondary);
        }

        .features li i {
            color: var(--success-color);
        }

        .select-btn {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, var(--accent-color), #7c3aed);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .select-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(37, 99, 235, 0.4);
        }

        .footer {
            background: var(--footer-gradient);
            padding: 2rem;
            text-align: center;
            border-top: 1px solid var(--border-color);
            margin-top: 3rem;
            color: var(--text-primary);
        }

        .footer-brand {
            font-weight: 700;
            margin-bottom: 0.5rem;
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

            <!-- Header Actions with Home, Help, and Theme Toggle -->
            <div class="header-actions">
                <button class="icon-btn" id="theme-toggle" title="Toggle Theme">
                    <i class="fas fa-moon"></i>
                </button>

                <a href="../../../Help_center/PHP/Frontend/Help_center.php" class="icon-btn" title="Help">
                    <i class="fas fa-question-circle"></i>
                </a>
                <a href="../../../Homepage/PHP/Frontend/Homepage.php" class="icon-btn" title="Home">
                    <i class="fas fa-home"></i>
                </a>

            </div>
        </div>
    </header>

    <div class="container">
        <div class="page-header">
            <h2>Choose Your Plan</h2>
            <p>Select a package that fits your security needs. Upgrade anytime as you grow.</p>
        </div>

        <div class="pricing-grid">
            <!-- Pro Package -->
            <div class="pricing-card popular" onclick="selectPackage('pro', 10.00)">
                <div class="card-header">
                    <h3>Pro</h3>
                    <div class="price">$10<span>/month</span></div>
                </div>
                <ul class="features">
                    <li><i class="fas fa-check-circle"></i> Unlimited scan storage</li>
                    <li><i class="fas fa-check-circle"></i> Full vulnerability modules</li>
                    <li><i class="fas fa-check-circle"></i> Detailed export reports</li>
                    <li><i class="fas fa-check-circle"></i> Priority performance</li>
                </ul>
                <button class="select-btn">Select Pro</button>
            </div>

            <!-- Enterprise Package -->
            <div class="pricing-card" onclick="selectPackage('enterprise', 25.00)">
                <div class="card-header">
                    <h3>Enterprise Suite</h3>
                    <div class="price">$25<span>/month</span></div>
                </div>
                <ul class="features">
                    <li><i class="fas fa-check-circle"></i> Everything in Pro</li>
                    <li><i class="fas fa-check-circle"></i> AI-assisted analysis</li>
                    <li><i class="fas fa-check-circle"></i> Enhanced remediation</li>
                    <li><i class="fas fa-check-circle"></i> Advanced reporting</li>
                </ul>
                <button class="select-btn">Select Enterprise</button>
            </div>
        </div>
    </div>

    <footer class="footer">
        <div class="footer-brand">
            <p>&copy; 2026 ScanQuotient. All rights reserved.</p>
        </div>

    </footer>

    <script>
        // Theme Toggle
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


        function selectPackage(packageName, price) {
            // Store in sessionStorage for next page
            sessionStorage.setItem('selectedPackage', packageName);
            sessionStorage.setItem('packagePrice', price);

            // Redirect to payment page
            window.location.href = '../../../Payment/PHP/Frontend/Payment.php';
        }


        function selectPackage(packageName, price) {

            window.location.href = `../../../Payment/PHP/Frontend/Payment.php?package=${packageName}&price=${price}`;
        }
    </script>

</body>

</html>