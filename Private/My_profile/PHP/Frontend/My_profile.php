<?php
// my_profile.php - FIXED VERSION with correct paths matching user_dashboard.php
session_start();

// Debug: Check what session variables are actually set
error_log("Session contents: " . print_r($_SESSION, true));

// Authentication check
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header("Location: /ScanQuotient.v2/ScanQuotient.B/Public/Login_page/PHP/Frontend/Login_page_site.php?error=not_authenticated");
    exit();
}

// Database configuration
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ============================================
// FIXED PATHS - Match user_dashboard.php structure
// ============================================

// Base URL path (web-accessible) - MUST match user_dashboard.php
define('BASE_URL', '/ScanQuotient.v2/ScanQuotient.B');

// Storage paths - unified with user_dashboard.php
define('UPLOAD_PATH', 'C:/xampp/htdocs/ScanQuotient.v2/ScanQuotient.B/Storage/Public_images/User_Profiles/');
define('UPLOAD_URL', BASE_URL . '/Storage/Public_images/User_Profiles/');

// Alternative: If you want to keep Images folder, use this instead:
// define('UPLOAD_PATH', 'C:/xampp/htdocs/ScanQuotient.v2/ScanQuotient.B/Images/User_Profiles/');
// define('UPLOAD_URL', BASE_URL . '/Images/User_Profiles/');

define('DEFAULT_AVATAR', BASE_URL . '/Storage/Public_images/default-avatar.png');

define('MAX_FILE_SIZE', 2 * 1024 * 1024); // 2MB
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/gif']);

// CRITICAL FIX: Check for correct session variable name
$userId = null;
if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
} elseif (isset($_SESSION['id'])) {
    $userId = $_SESSION['id'];
}

$userName = $_SESSION['user_name'] ?? 'User';
$successMessage = '';
$errorMessage = '';
$user = null;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    error_log("Looking for user with ID: " . var_export($userId, true));

    // Try to fetch by user_id (varchar) first, then by id (int) as fallback
    $user = null;

    if (!empty($userId)) {
        // Try user_id column (varchar)
        $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        // If not found, try id column (int) as fallback
        if (!$user && is_numeric($userId)) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([intval($userId)]);
            $user = $stmt->fetch();
        }
    }

    if (!$user) {
        throw new Exception("User not found in database. Session ID: " . var_export($userId, true) .
            ". Please log out and log in again.");
    }

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'update_email':
                $newEmail = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
                $newRecoveryEmail = filter_var(trim($_POST['recovery_email'] ?? ''), FILTER_SANITIZE_EMAIL);

                if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                    $errorMessage = "Invalid primary email address";
                } elseif (!filter_var($newRecoveryEmail, FILTER_VALIDATE_EMAIL)) {
                    $errorMessage = "Invalid recovery email address";
                } elseif ($newEmail === $newRecoveryEmail) {
                    $errorMessage = "Primary and recovery emails must be different";
                } else {
                    $checkStmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1");
                    $checkStmt->execute([$newEmail, $user['user_id']]);
                    if ($checkStmt->fetch()) {
                        $errorMessage = "This email is already in use by another account";
                    } else {
                        $updateStmt = $pdo->prepare("UPDATE users SET email = ?, recovery_email = ?, updated_at = NOW(), updated_by = ? WHERE user_id = ?");
                        $updateStmt->execute([$newEmail, $newRecoveryEmail, $user['user_id'], $user['user_id']]);
                        $successMessage = "Email addresses updated successfully";
                        $user['email'] = $newEmail;
                        $user['recovery_email'] = $newRecoveryEmail;
                    }
                }
                break;

            case 'toggle_2fa':
                $currentStatus = $user['two_factor_enabled'] ?? 'no';
                $newStatus = $currentStatus === 'yes' ? 'no' : 'yes';

                if ($newStatus === 'yes') {
                    $secret = generate2FASecret();
                    $backupCodes = generateBackupCodes();
                    $updateStmt = $pdo->prepare("UPDATE users SET two_factor_enabled = 'yes', two_factor_secret = ?, two_factor_backup_codes = ?, updated_at = NOW() WHERE user_id = ?");
                    $updateStmt->execute([$secret, json_encode($backupCodes), $user['user_id']]);
                    $successMessage = "Two-factor authentication enabled. Save these backup codes: " . implode(', ', $backupCodes);
                } else {
                    $updateStmt = $pdo->prepare("UPDATE users SET two_factor_enabled = 'no', two_factor_secret = NULL, two_factor_backup_codes = NULL, updated_at = NOW() WHERE user_id = ?");
                    $updateStmt->execute([$user['user_id']]);
                    $successMessage = "Two-factor authentication disabled";
                }
                $user['two_factor_enabled'] = $newStatus;
                break;

            case 'upload_photo':
                if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['profile_photo'];

                    if ($file['size'] > MAX_FILE_SIZE) {
                        $errorMessage = "File size too large. Maximum 2MB allowed.";
                        break;
                    }

                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);

                    if (!in_array($mimeType, ALLOWED_TYPES)) {
                        $errorMessage = "Invalid file type. Only JPG, PNG, and GIF allowed.";
                        break;
                    }

                    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $newFilename = $user['user_id'] . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
                    $targetPath = UPLOAD_PATH . $newFilename;

                    // Create directory if not exists
                    if (!is_dir(UPLOAD_PATH)) {
                        mkdir(UPLOAD_PATH, 0755, true);
                    }

                    // Delete old photo if exists
                    if (!empty($user['profile_photo'])) {
                        // Extract just the filename from the stored path
                        $oldFilename = basename($user['profile_photo']);
                        $oldFile = UPLOAD_PATH . $oldFilename;
                        if (file_exists($oldFile)) {
                            unlink($oldFile);
                        }
                    }

                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        // ============================================
                        // FIXED: Store path that matches user_dashboard.php format
                        // ============================================
                        // Store relative path from project root, matching user_dashboard.php structure
                        $dbPath = 'Storage/Public_images/User_Profiles/' . $newFilename;

                        // Alternative if using Images folder:
                        // $dbPath = 'Images/User_Profiles/' . $newFilename;

                        $updateStmt = $pdo->prepare("UPDATE users SET profile_photo = ?, updated_at = NOW() WHERE user_id = ?");
                        $updateStmt->execute([$dbPath, $user['user_id']]);
                        $successMessage = "Profile photo updated successfully";
                        $user['profile_photo'] = $dbPath;

                        // Update session for consistency
                        $_SESSION['profile_photo'] = $dbPath;
                    } else {
                        $errorMessage = "Failed to upload photo. Please try again.";
                    }
                } else {
                    $errorMessage = "Please select a valid image file";
                }
                break;
        }
    }

    // ============================================
    // FIXED: Profile Photo URL - Match user_dashboard.php logic
    // ============================================
    if (!empty($user['profile_photo'])) {
        // Remove any leading slashes to avoid double slashes
        $photo_path = ltrim($user['profile_photo'], '/');

        // Build full URL path from project root (same as user_dashboard.php)
        if (strpos($photo_path, 'Storage/') === 0 || strpos($photo_path, 'Images/') === 0) {
            $profilePhotoUrl = BASE_URL . '/' . $photo_path;
        } else {
            // Fallback: assume it's in the User_Profiles folder
            $profilePhotoUrl = UPLOAD_URL . basename($photo_path);
        }
    } else {
        // Default avatar - same path as user_dashboard.php
        $profilePhotoUrl = DEFAULT_AVATAR;
    }

    $joinDate = isset($user['created_at']) ? date('F j, Y', strtotime($user['created_at'])) : 'Unknown';
    $lastUpdated = (!empty($user['updated_at'])) ? date('F j, Y \a\t g:i A', strtotime($user['updated_at'])) : 'Never';

} catch (Exception $e) {
    error_log("Profile error: " . $e->getMessage());
    $errorMessage = "Error: " . $e->getMessage();
    $user = [
        'first_name' => 'Unknown',
        'surname' => 'User',
        'user_name' => 'unknown',
        'email' => '',
        'recovery_email' => '',
        'role' => 'user',
        'gender' => 'other',
        'phone_number' => '',
        'two_factor_enabled' => 'no',
        'created_at' => date('Y-m-d'),
        'updated_at' => null,
        'profile_photo' => null
    ];
    $profilePhotoUrl = DEFAULT_AVATAR;
}

function generate2FASecret()
{
    return bin2hex(random_bytes(16));
}

function generateBackupCodes()
{
    $codes = [];
    for ($i = 0; $i < 5; $i++) {
        $codes[] = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 8);
    }
    return $codes;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScanQuotient | My Profile</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../CSS/my_profile.css" />
</head>

<body>

    <header class="sq-admin-header">
        <div class="sq-admin-header-left">
            <a href="javascript:history.back()" class="sq-admin-back-btn" title="Previous Page">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div class="brand-wrapper">
                <a href="#" class="sq-admin-brand" style="color:#6c63ff;">ScanQuotient</a>
                <p class="sq-admin-tagline">Quantifying Risk. Strengthening Security.</p>
            </div>
        </div>
        <div class="sq-admin-header-right">
            <div class="sq-admin-user">
                <i class="fas fa-user-shield"></i>
                <span>
                    <?php echo htmlspecialchars($user['user_name']); ?>
                </span>
            </div>
            <button class="sq-admin-theme-toggle" id="sqThemeToggle" title="Toggle Theme">
                <i class="fas fa-sun"></i>
            </button>

            <a href="../../../../Public/Login_page/PHP/Frontend/Login_page_site.php" class="icon-btn" title="Logout"><i
                    class="fas fa-sign-out-alt"></i></a>
        </div>
    </header>

    <!-- Photo Upload Modal -->
    <div class="sq-upload-modal" id="sqUploadModal">
        <div class="sq-upload-container">
            <h3 style="margin-bottom: 8px; color: var(--sq-text-main);">Update Profile Photo</h3>
            <p style="color: var(--sq-text-light); font-size: 14px; margin-bottom: 24px;">JPG, PNG or GIF. Max 2MB.</p>

            <img src="" alt="Preview" class="sq-upload-preview" id="sqUploadPreview">

            <div class="sq-upload-placeholder" id="sqUploadPlaceholder">
                <i class="fas fa-cloud-upload-alt"></i>
                <span>Click to select photo</span>
            </div>

            <form method="POST" enctype="multipart/form-data" id="sqUploadForm">
                <input type="hidden" name="action" value="upload_photo">
                <input type="file" name="profile_photo" class="sq-upload-input" id="sqUploadInput"
                    accept="image/jpeg,image/png,image/gif">

                <label for="sqUploadInput" class="sq-upload-btn">
                    <i class="fas fa-camera"></i> Choose Photo
                </label>

                <div class="sq-upload-actions">
                    <button type="button" class="sq-upload-cancel" onclick="sqCloseUploadModal()">Cancel</button>
                    <button type="submit" class="sq-upload-submit" id="sqUploadSubmit" disabled>Save Photo</button>
                </div>
            </form>
        </div>
    </div>

    <main class="sq-profile-container">

        <?php if (strpos($errorMessage, 'User not found') !== false): ?>
            <div class="sq-error-banner">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($errorMessage); ?>
                <br><a href="/ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Backend/logout_from_the_system.php"
                    style="color: inherit; text-decoration: underline;">Click here to re-login</a>
            </div>
        <?php endif; ?>

        <?php if ($successMessage): ?>
            <div class="sq-profile-alert sq-profile-alert--success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($successMessage); ?></span>
                <button class="sq-profile-alert-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage && strpos($errorMessage, 'User not found') === false): ?>
            <div class="sq-profile-alert sq-profile-alert--error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($errorMessage); ?></span>
                <button class="sq-profile-alert-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <div class="sq-profile-grid">

            <!-- Left Column: Profile Photo & Info -->
            <div class="sq-profile-photo-card">
                <div class="sq-profile-photo-wrapper" onclick="sqOpenUploadModal()">
                    <img src="<?php echo htmlspecialchars($profilePhotoUrl); ?>" alt="Profile" class="sq-profile-photo"
                        id="sqProfilePhoto">
                    <div class="sq-profile-photo-overlay">
                        <i class="fas fa-camera sq-profile-photo-btn"></i>
                    </div>
                </div>

                <h2 class="sq-profile-name">
                    <?php echo htmlspecialchars(($user['first_name'] ?? 'Unknown') . ' ' . ($user['surname'] ?? 'User')); ?>
                </h2>
                <p class="sq-profile-username">@<?php echo htmlspecialchars($user['user_name'] ?? 'unknown'); ?></p>

                <span class="sq-profile-role">
                    <i class="fas fa-shield-alt"></i>
                    <?php echo htmlspecialchars($user['role'] ?? 'user'); ?>
                </span>

                <div class="sq-profile-meta">
                    <div class="sq-profile-meta-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Joined <?php echo htmlspecialchars($joinDate); ?></span>
                    </div>
                    <div class="sq-profile-meta-item">
                        <i class="fas fa-clock"></i>
                        <span>Updated <?php echo htmlspecialchars($lastUpdated); ?></span>
                    </div>
                    <div class="sq-profile-meta-item">
                        <i class="fas fa-envelope"></i>
                        <span><?php echo htmlspecialchars($user['email'] ?? 'No email'); ?></span>
                    </div>

                </div>
            </div>

            <!-- Right Column: Settings -->
            <div class="sq-profile-settings">

                <!-- Email Settings -->
                <div class="sq-profile-section">
                    <div class="sq-profile-section-header">
                        <div class="sq-profile-section-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div>
                            <h3 class="sq-profile-section-title">Email Addresses</h3>
                            <p class="sq-profile-section-desc">Update your primary and recovery email</p>
                        </div>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="update_email">

                        <div class="sq-profile-form-group">
                            <label class="sq-profile-label">Primary Email</label>
                            <div class="sq-profile-input-wrapper">
                                <i class="fas fa-envelope sq-profile-input-icon"></i>
                                <input type="email" name="email" class="sq-profile-input"
                                    value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>"
                                    placeholder="your@email.com" readonly>
                            </div>
                        </div>

                        <div class="sq-profile-form-group">
                            <label class="sq-profile-label">Recovery Email</label>
                            <div class="sq-profile-input-wrapper">
                                <i class="fas fa-shield-alt sq-profile-input-icon"></i>
                                <input type="email" name="recovery_email" class="sq-profile-input"
                                    value="<?php echo htmlspecialchars($user['recovery_email'] ?? ''); ?>"
                                    placeholder="backup@email.com" required>
                            </div>
                        </div>

                        <button type="submit" class="sq-profile-btn">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </form>
                </div>

                <!-- 2FA Settings -->
                <div class="sq-profile-section">
                    <div class="sq-profile-section-header">
                        <div class="sq-profile-section-icon">
                            <i class="fas fa-lock"></i>
                        </div>
                        <div>
                            <h3 class="sq-profile-section-title">Two-Factor Authentication</h3>
                            <p class="sq-profile-section-desc">Add extra security to your account</p>
                        </div>
                    </div>

                    <form method="POST" id="sq2faForm">
                        <input type="hidden" name="action" value="toggle_2fa">

                        <div class="sq-profile-2fa-status">
                            <div class="sq-profile-2fa-info">
                                <div
                                    class="sq-profile-2fa-icon <?php echo ($user['two_factor_enabled'] ?? 'no') === 'no' ? 'sq-profile-2fa-icon--disabled' : ''; ?>">
                                    <i
                                        class="fas <?php echo ($user['two_factor_enabled'] ?? 'no') === 'yes' ? 'fa-shield-alt' : 'fa-unlock'; ?>"></i>
                                </div>
                                <div class="sq-profile-2fa-text">
                                    <h4>2FA is
                                        <?php echo ($user['two_factor_enabled'] ?? 'no') === 'yes' ? 'Enabled' : 'Disabled'; ?>
                                    </h4>
                                    <p><?php echo ($user['two_factor_enabled'] ?? 'no') === 'yes' ? 'Your account is protected with an additional security layer' : 'Enable 2FA to secure your account with email verification'; ?>
                                    </p>
                                </div>
                            </div>

                            <label class="sq-profile-toggle">
                                <input type="checkbox" name="toggle_2fa" <?php echo ($user['two_factor_enabled'] ?? 'no') === 'yes' ? 'checked' : ''; ?>
                                    onchange="document.getElementById('sq2faForm').submit()">
                                <span class="sq-profile-toggle-slider"></span>
                            </label>
                        </div>
                    </form>
                    <!-- Password Reset Link -->
                    <div class="sq-profile-2fa-status" style="margin-top:15px;">
                        <div class="sq-profile-2fa-info">
                            <div class="sq-profile-2fa-icon">
                                <i class="fas fa-key"></i>
                            </div>
                            <div class="sq-profile-2fa-text">
                                <h4>Reset Password</h4>
                                <p>Change your account password if you feel it may be compromised</p>
                            </div>
                        </div>

                        <a href="../../../../Public/Reset_password/PHP/Backend/initiate_reset.php"
                            class="sq-profile-btn-primary">
                            <i class="fas fa-arrow-right"></i> Go
                        </a>
                    </div>
                </div>

                <!-- Account Info (Read Only) -->
                <div class="sq-profile-section">
                    <div class="sq-profile-section-header">
                        <div class="sq-profile-section-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <div>
                            <h3 class="sq-profile-section-title">Account Information</h3>
                            <p class="sq-profile-section-desc">Your registered details (contact admin to modify)</p>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div class="sq-profile-form-group">
                            <label class="sq-profile-label">First Name</label>
                            <input type="text" class="sq-profile-input"
                                value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" disabled
                                style="opacity: 0.6; padding-left: 16px;">
                        </div>
                        <div class="sq-profile-form-group">
                            <label class="sq-profile-label">Surname</label>
                            <input type="text" class="sq-profile-input"
                                value="<?php echo htmlspecialchars($user['surname'] ?? ''); ?>" disabled
                                style="opacity: 0.6; padding-left: 16px;">
                        </div>
                        <div class="sq-profile-form-group">
                            <label class="sq-profile-label">Gender</label>
                            <input type="text" class="sq-profile-input"
                                value="<?php echo htmlspecialchars(ucfirst($user['gender'] ?? 'other')); ?>" disabled
                                style="opacity: 0.6; padding-left: 16px;">
                        </div>
                        <div class="sq-profile-form-group">
                            <label class="sq-profile-label">Phone Number</label>
                            <input type="text" class="sq-profile-input"
                                value="<?php echo htmlspecialchars($user['phone_number'] ?? ''); ?>" disabled
                                style="opacity: 0.6; padding-left: 16px;">
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <footer class="sq-profile-footer">
            <p>ScanQuotient Security Platform • Quantifying Risk. Strengthening Security.</p>
        </footer>
    </main>

    <script src="../../Javascript/my_profile.js" defer></script>
</body>

</html>