<?php
// initiate_reset.php - FIXED: Converts numeric id to varchar user_id
session_start();

// STEP 1: Security check - must be logged in
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header("Location: ../../../Login_page/PHP/Frontend/Login_page_site.php?error=not_authenticated");
    exit();
}

// STEP 2: Get the raw ID from session (could be int id or varchar user_id)
$rawId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;

if (empty($rawId)) {
    header("Location: ../../../Login_page/PHP/Frontend/Login_page_site.php?error=invalid_session");
    exit();
}

// STEP 3: CRITICAL FIX - Convert to varchar user_id if needed
try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=scanquotient.a1;charset=utf8mb4", "root", "");

    $varcharUserId = null;

    // Check if rawId is already varchar (non-numeric) or numeric
    if (!is_numeric($rawId)) {
        // Already looks like varchar user_id (e.g., "usr_12345")
        $varcharUserId = $rawId;
    } else {
        // It's numeric - could be id column, need to fetch user_id
        // Try matching against id column first
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([intval($rawId)]);
        $result = $stmt->fetch();

        if ($result) {
            $varcharUserId = $result['user_id'];
        } else {
            // Try against user_id column just in case
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ? LIMIT 1");
            $stmt->execute([$rawId]);
            $result = $stmt->fetch();
            $varcharUserId = $result['user_id'] ?? null;
        }
    }

    if (empty($varcharUserId)) {
        header("Location: ../../../Login_page/PHP/Frontend/Login_page_site.php?error=user_not_found");
        exit();
    }

} catch (PDOException $e) {
    error_log("Database error in initiate_reset: " . $e->getMessage());
    header("Location: ../../../Login_page/PHP/Frontend/Login_page_site.php?error=system_error");
    exit();
}

// STEP 4: Set up the password reset session with CORRECT varchar user_id
$_SESSION['auth_mode'] = 'password_reset';
$_SESSION['user_id'] = $varcharUserId;  // Now guaranteed to be varchar like "usr_12345"
$_SESSION['user_name'] = $_SESSION['user_name'] ?? '';
$_SESSION['force_reset_reason'] = 'user_requested';

// STEP 5: Redirect to the actual reset page
header("Location: ../Frontend/Password_reset_page.php");
exit();
?>