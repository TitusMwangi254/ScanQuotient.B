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

// Determine completion mode from session + request
$sessionStage = $_SESSION['auth_stage'] ?? 'pending_completion';
$postedMode = (string) ($_POST['completion_mode'] ?? '');
$isAgreementsOnly = ($sessionStage === 'agreements_only' && $postedMode === 'agreements_only');
$pendingAgreements = is_array($_SESSION['pending_agreements'] ?? null) ? $_SESSION['pending_agreements'] : [];
$pendingAgreements = [
    'privacy' => !empty($pendingAgreements['privacy']),
    'terms' => !empty($pendingAgreements['terms']),
    'security' => !empty($pendingAgreements['security']),
];

// Hard guard against mode tampering.
// agreements_only session must post agreements_only, and pending_completion must post pending_completion.
if (
    ($sessionStage === 'agreements_only' && $postedMode !== 'agreements_only') ||
    ($sessionStage === 'pending_completion' && $postedMode !== 'pending_completion')
) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid completion mode for this session.'
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
if ((!$isAgreementsOnly || !empty($pendingAgreements['privacy'])) && $agreePrivacy !== 'yes') {
    $errors[] = 'You must agree to the Privacy Policy.';
}
if ((!$isAgreementsOnly || !empty($pendingAgreements['terms'])) && $agreeTerms !== 'yes') {
    $errors[] = 'You must agree to the Terms of Service.';
}
if ((!$isAgreementsOnly || !empty($pendingAgreements['security'])) && $agreeSecurity !== 'yes') {
    $errors[] = 'You must agree to the Security Agreement.';
}

if (!$isAgreementsOnly) {
    // Explicit guard against manipulated requests hiding/removing these fields.
    if (!array_key_exists('username', $_POST) || !array_key_exists('password', $_POST) || !array_key_exists('confirm_password', $_POST)) {
        $errors[] = 'Username and password setup fields are required for account completion.';
    }

    // Validate username for full setup mode
    if (empty($username)) {
        $errors[] = 'Username is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{4,20}$/', $username)) {
        $errors[] = 'Username must be 4-20 characters, alphanumeric and underscores only.';
    }

    // Validate password for full setup mode
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

    // Load current agreement state so agreements-only mode preserves untouched fields.
    $currentUserStmt = $pdo->prepare("
        SELECT privacy_agreed, privacy_agreed_at, terms_agreed, terms_agreed_at, agreement_agreed, agreement_agreed_at
        FROM users
        WHERE user_id = :user_id
        LIMIT 1
    ");
    $currentUserStmt->execute([':user_id' => $userId]);
    $currentUser = $currentUserStmt->fetch();
    if (!$currentUser) {
        echo json_encode([
            'status' => 'error',
            'message' => 'User account not found.'
        ]);
        exit();
    }

    // Defensive fallback: if session did not carry pending flags, infer from DB.
    if ($isAgreementsOnly && !$pendingAgreements['privacy'] && !$pendingAgreements['terms'] && !$pendingAgreements['security']) {
        $pendingAgreements = [
            'privacy' => (($currentUser['privacy_agreed'] ?? 'no') !== 'yes'),
            'terms' => (($currentUser['terms_agreed'] ?? 'no') !== 'yes'),
            'security' => (($currentUser['agreement_agreed'] ?? 'no') !== 'yes'),
        ];
    }

    if (!$isAgreementsOnly) {
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
    }

    if ($isAgreementsOnly) {
        $finalPrivacy = $pendingAgreements['privacy'] ? $agreePrivacy : (string) ($currentUser['privacy_agreed'] ?? 'no');
        $finalTerms = $pendingAgreements['terms'] ? $agreeTerms : (string) ($currentUser['terms_agreed'] ?? 'no');
        $finalSecurity = $pendingAgreements['security'] ? $agreeSecurity : (string) ($currentUser['agreement_agreed'] ?? 'no');

        $finalPrivacyAt = $finalPrivacy === 'yes' ? ($currentUser['privacy_agreed_at'] ?: date('Y-m-d H:i:s')) : null;
        $finalTermsAt = $finalTerms === 'yes' ? ($currentUser['terms_agreed_at'] ?: date('Y-m-d H:i:s')) : null;
        $finalSecurityAt = $finalSecurity === 'yes' ? ($currentUser['agreement_agreed_at'] ?: date('Y-m-d H:i:s')) : null;

        $updateStmt = $pdo->prepare("
            UPDATE users
            SET
                privacy_agreed = :privacy_agreed,
                privacy_agreed_at = :privacy_agreed_at,
                terms_agreed = :terms_agreed,
                terms_agreed_at = :terms_agreed_at,
                agreement_agreed = :agreement_agreed,
                agreement_agreed_at = :agreement_agreed_at,
                updated_at = NOW()
            WHERE user_id = :user_id
        ");
        $updateStmt->execute([
            ':privacy_agreed' => $finalPrivacy,
            ':privacy_agreed_at' => $finalPrivacyAt,
            ':terms_agreed' => $finalTerms,
            ':terms_agreed_at' => $finalTermsAt,
            ':agreement_agreed' => $finalSecurity,
            ':agreement_agreed_at' => $finalSecurityAt,
            ':user_id' => $userId
        ]);
    } else {
        // Hash password
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        // Update user record (full onboarding mode)
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
    }

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
    unset($_SESSION['pending_agreements']);

    // Set success message for login page
    $_SESSION['completion_success'] = $isAgreementsOnly
        ? 'Policies updated. Please sign in.'
        : 'Account setup complete! Please log in with your new username and password.';

    echo json_encode([
        'status' => 'success',
        'message' => $isAgreementsOnly ? 'Policy acknowledgments saved successfully.' : 'Account setup completed successfully!',
        'redirect' => '/ScanQuotient.v2/ScanQuotient.B/Public/Login_page/PHP/Frontend/Login_page_site.php'
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