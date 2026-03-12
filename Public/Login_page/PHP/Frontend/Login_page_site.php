<?php
// login_page.php
session_start();

// Capture any login error BEFORE clearing the session
$loginError = $_SESSION['loginError'] ?? false;

// Destroy any existing session immediately (but keep $loginError for display)
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = array();

    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }

    session_destroy();
}

// Prevent all caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>ScanQuotient | Sign In</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <link rel="stylesheet" href="../../CSS/login_page.css" />

    <!-- Prevent back button navigation -->
    <script>
        (function () {
            history.pushState(null, null, location.href);
            window.onpopstate = function () {
                history.pushState(null, null, location.href);
            };
        })();
    </script>
</head>

<body>
    <!-- Your existing HTML content remains the same -->
    <div class="top-right-nav">
        <a href="../../../Help_center/PHP/Frontend/Help_center.php" title="Help">
            <i class="fas fa-question-circle"></i>
        </a>
        <a href="../../../Homepage/PHP/Frontend/Homepage.php" title="Back to Home">
            <i class="fas fa-home"></i>
        </a>
    </div>

    <div class="theme-switch-wrapper">
        <label class="theme-switch" for="checkbox" id="theme-label" title="Switch to Light Mode">
            <input type="checkbox" id="checkbox" />
            <div class="slider round"></div>
        </label>
    </div>

    <div class="container">
        <h2>ScanQuotient</h2>

        <?php if ($loginError): ?>
            <div class="error-box" id="loginErrorBox">
                <?php echo htmlspecialchars($loginError); ?>
            </div>
            <script>
                setTimeout(() => {
                    const toast = document.getElementById('loginErrorBox');
                    if (toast) toast.style.display = 'none';
                }, 5000);
            </script>
        <?php endif; ?>

        <form action="../../PHP/Backend/login_handler.php" method="post">
            <div class="input-wrapper">
                <input type="text" name="username" placeholder="Username" required id="usernameField"
                    autocomplete="off" />
                <i class="fas fa-eye toggle-visibility" data-target="usernameField"></i>
            </div>

            <div class="input-wrapper">
                <input type="password" name="password" placeholder="Password" required id="passwordField"
                    autocomplete="off" />
                <i class="fas fa-eye-slash toggle-visibility" data-target="passwordField"></i>
            </div>

            <button class="sign-in-btn" type="submit">Sign In</button>
        </form>

        <div class="links">
            <a href="../../PHP/Frontend/Forgot_password.php">Forgot Password?</a> |
            <a href="../../../Registration_page/PHP/Frontend/Registration_page.php">Don't have an account?</a>
        </div>
    </div>

    <script src="../../Javascript/login_page.js" defer></script>
    <script>
        // Theme switcher logic
        const checkbox = document.getElementById('checkbox');
        const themeLabel = document.getElementById('theme-label');

        function updateTitle() {
            themeLabel.title = checkbox.checked ? "Switch to Dark Mode" : "Switch to Light Mode";
        }

        checkbox.addEventListener('change', updateTitle);
        updateTitle();
    </script>
</body>

</html>