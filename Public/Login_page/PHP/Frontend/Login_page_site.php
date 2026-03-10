<?php
// login_page.php (formerly login_page.php, now primarily HTML and error display)
session_start(); // Start session to access $_SESSION['loginError']

$loginError = $_SESSION['loginError'] ?? false; // Get error flag from session
unset($_SESSION['loginError']); // Clear the error flag after displaying it
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>ScanQuotient | Sign In</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
        integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="../../CSS/login_page.css" />
</head>

<body>
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

<script>
    const checkbox = document.getElementById('checkbox');
    const themeLabel = document.getElementById('theme-label');
    
    // Set initial title based on checkbox state
    function updateTitle() {
        if(checkbox.checked) {
            themeLabel.title = "Switch to Dark Mode";  // Currently in light mode
        } else {
            themeLabel.title = "Switch to Light Mode"; // Currently in dark mode
        }
    }
    
    // Update on change
    checkbox.addEventListener('change', updateTitle);
    
    // Set initial state
    updateTitle();
</script>

    <div class="container">
        <h2>ScanQuotient</h2>

        <?php if ($loginError): ?>
            <div class="error-box" id="loginErrorBox">
                <?php echo htmlspecialchars($loginError); ?>
            </div>
            <script>
                // Auto-hide toast after 5 seconds
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
            <a href="../../PHP/Frontend/Forgot_password.php">Forgot
                Password?</a> |
            <a href="../../../Registration_page/PHP/Frontend/Registration_page.php">
                Don’t have an account?
            </a>
        </div>
    </div>


    <script src="../../Javascript/login_page.js" defer></script>
</body>

</html>