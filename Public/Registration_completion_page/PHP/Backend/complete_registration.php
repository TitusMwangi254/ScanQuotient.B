<?php
// complete_registration.php - Process account completion
session_start();
header('Content-Type: application/json');

// Database configuration
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Verify completion flow
if (!isset($_SESSION['auth_mode']) || $_SESSION['auth_mode'] !== 'account_completion') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid session. Please start registration again.'
    ]);
    exit();
}

// Verify request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method.'
    ]);
    exit();
}

// Get user ID from session
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Session expired. Please log in again.'
    ]);
    exit();
}

// Get and validate form data
$agreePrivacy = ($_POST['agree_privacy'] ?? '') === 'on' ? 'yes' : 'no';
$agreeTerms = ($_POST['agree_terms'] ?? '') === 'on' ? 'yes' : 'no';
$agreeSecurity = ($_POST['agree_security'] ?? '') === 'on' ? 'yes' : 'no';
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

$errors = [];

// Validate agreements
if ($agreePrivacy !== 'yes') {
    $errors[] = 'You must agree to the Privacy Policy.';
}
if ($agreeTerms !== 'yes') {
    $errors[] = 'You must agree to the Terms of Service.';
}
if ($agreeSecurity !== 'yes') {
    $errors[] = 'You must agree to the Security Agreement.';
}

// Validate username
if (empty($username)) {
    $errors[] = 'Username is required.';
} elseif (!preg_match('/^[a-zA-Z0-9_]{4,20}$/', $username)) {
    $errors[] = 'Username must be 4-20 characters, alphanumeric and underscores only.';
}

// Validate password
if (empty($password)) {
    $errors[] = 'Password is required.';
} elseif (strlen($password) < 12) {
    $errors[] = 'Password must be at least 12 characters.';
} elseif (!preg_match('/[A-Z]/', $password)) {
    $errors[] = 'Password must contain at least one uppercase letter.';
} elseif (!preg_match('/[a-z]/', $password)) {
    $errors[] = 'Password must contain at least one lowercase letter.';
} elseif (!preg_match('/[0-9]/', $password)) {
    $errors[] = 'Password must contain at least one number.';
} elseif (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $password)) {
    $errors[] = 'Password must contain at least one special character.';
} elseif ($password !== $confirmPassword) {
    $errors[] = 'Passwords do not match.';
}

// Check for errors
if (!empty($errors)) {
    echo json_encode([
        'status' => 'error',
        'message' => implode(' ', $errors)
    ]);
    exit();
}

try {
    // Database connection
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Check if username already exists
    $checkStmt = $pdo->prepare("SELECT user_id FROM users WHERE user_name = :username AND user_id != :user_id LIMIT 1");
    $checkStmt->execute([
        ':username' => $username,
        ':user_id' => $userId
    ]);

    if ($checkStmt->fetch()) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Username already taken. Please choose another.'
        ]);
        exit();
    }

    // Hash password
    $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // Update user record
    $updateStmt = $pdo->prepare("
        UPDATE users 
        SET 
            privacy_agreed = :privacy_agreed,
            privacy_agreed_at = CASE WHEN :privacy_agreed = 'yes' THEN NOW() ELSE NULL END,
            terms_agreed = :terms_agreed,
            terms_agreed_at = CASE WHEN :terms_agreed = 'yes' THEN NOW() ELSE NULL END,
            agreement_agreed = :agreement_agreed,
            agreement_agreed_at = CASE WHEN :agreement_agreed = 'yes' THEN NOW() ELSE NULL END,
            user_name = :username,
            password_hash = :password_hash,
            account_active = 'yes',
            completion_completed_at = NOW(),
            updated_at = NOW()
        WHERE user_id = :user_id
    ");

    $updateStmt->execute([
        ':privacy_agreed' => $agreePrivacy,
        ':terms_agreed' => $agreeTerms,
        ':agreement_agreed' => $agreeSecurity,
        ':username' => $username,
        ':password_hash' => $passwordHash,
        ':user_id' => $userId
    ]);

    // Check if update was successful
    if ($updateStmt->rowCount() === 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to update account. Please try again.'
        ]);
        exit();
    }

    // Clear completion session
    unset($_SESSION['auth_mode']);
    unset($_SESSION['auth_stage']);
    unset($_SESSION['user_id']);
    unset($_SESSION['user_email']);
    unset($_SESSION['user_name']);
    unset($_SESSION['first_name']);
    unset($_SESSION['surname']);

    // Set success message for login page
    $_SESSION['completion_success'] = 'Account setup complete! Please log in with your new username and password.';

    echo json_encode([
        'status' => 'success',
        'message' => 'Account setup completed successfully!',
        'redirect' => '../../../Login_page/PHP/Frontend/Login_page_site.php'
    ]);

} catch (PDOException $e) {
    error_log("Database error in complete_registration: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'System error. Please try again later.'
    ]);
} catch (Exception $e) {
    error_log("General error in complete_registration: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'An unexpected error occurred.'
    ]);
}
?>