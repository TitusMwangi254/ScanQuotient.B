<?php
// admin_user_detail.php - Full CRUD user management detail page
session_start();
date_default_timezone_set('Africa/Nairobi');

// Load PHPMailer

require_once 'C:/Users/1/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header("Location: /ScanQuotient.v2/ScanQuotient.B/Public/Login_page/PHP/Frontend/Login_page_site.php?error=not_authenticated");
    exit();
}

$adminRole = $_SESSION['role'] ?? 'user';
if ($adminRole !== 'admin' && $adminRole !== 'super_admin') {
    header("Location: /ScanQuotient.v2/ScanQuotient.B/Public/Login_page/PHP/Frontend/Login_page_site.php?error=unauthorized");
    exit();
}

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// URL Configuration - images are stored in different project folder
define('BASE_URL', '/ScanQuotient.v2/ScanQuotient.B');
define('STORAGE_URL', '/ScanQuotient.v2/ScanQuotient.B');
// Physical path for uploads
define('UPLOAD_PATH', 'C:/xampp/htdocs/ScanQuotient.v2/ScanQuotient.B/Storage/User_Profile_images');
define('DB_STORAGE_PATH', 'Storage/User_Profile_images');

$adminId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 'unknown';
$adminName = $_SESSION['user_name'] ?? 'Admin';
$profile_photo = $_SESSION['profile_photo'] ?? null;
if (!empty($profile_photo)) {
    $photo_path = ltrim((string) $profile_photo, '/');
    $base_url = '/ScanQuotient.v2/ScanQuotient.B';
    $avatar_url = $base_url . '/' . $photo_path;
} else {
    $avatar_url = '/ScanQuotient.v2/ScanQuotient.B/Storage/Public_images/default-avatar.png';
}
$successMessage = '';
$errorMessage = '';
$toastMessage = '';
$toastType = '';

// Valid enums
$validGenders = ['male', 'female', 'other'];
$validRoles = ['user', 'admin', 'super_admin'];
$validYesNo = ['yes', 'no'];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $userId = intval($_GET['id'] ?? 0);
    if ($userId <= 0) {
        throw new Exception("Invalid user ID");
    }

    // Fetch user
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new Exception("User not found");
    }

    // Prevent non-super-admins from editing super-admins
    if ($user['role'] === 'super_admin' && $adminRole !== 'super_admin') {
        throw new Exception("You do not have permission to edit this user");
    }

    // Handle POST actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        // Handle AJAX photo upload
        if ($action === 'update_profile_photo') {
            header('Content-Type: application/json');

            if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
                exit;
            }

            $file = $_FILES['photo'];

            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($file['type'], $allowedTypes)) {
                echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF allowed.']);
                exit;
            }

            // Validate file size (5MB)
            if ($file['size'] > 5 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'File too large. Max 5MB.']);
                exit;
            }

            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'user_' . $userId . '_' . time() . '.' . $extension;
            $uploadFile = UPLOAD_PATH . '/' . $filename;

            // Ensure upload directory exists
            if (!is_dir(UPLOAD_PATH)) {
                mkdir(UPLOAD_PATH, 0755, true);
            }

            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $uploadFile)) {
                // Delete old photo if exists
                if (!empty($user['profile_photo'])) {
                    $oldPath = UPLOAD_PATH . '/' . basename($user['profile_photo']);
                    if (file_exists($oldPath) && strpos($oldPath, 'default') === false) {
                        unlink($oldPath);
                    }
                }

                // Update database with new path
                $dbPath = DB_STORAGE_PATH . '/' . $filename;
                $stmt = $pdo->prepare("UPDATE users SET profile_photo = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                $stmt->execute([$dbPath, $adminName, $userId]);

                $photoUrl = STORAGE_URL . '/' . $dbPath;
                echo json_encode(['success' => true, 'photoUrl' => $photoUrl]);
                exit;
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file']);
                exit;
            }
        }

        switch ($action) {
            case 'update_profile':
                $firstName = trim($_POST['first_name'] ?? '');
                $middleName = trim($_POST['middle_name'] ?? '') ?: null;
                $surname = trim($_POST['surname'] ?? '');
                $gender = in_array($_POST['gender'] ?? '', $validGenders) ? $_POST['gender'] : 'other';
                $phoneNumber = trim($_POST['phone_number'] ?? '');

                if (empty($firstName) || empty($surname)) {
                    $toastMessage = "First name and surname are required";
                    $toastType = "error";
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET first_name = ?, middle_name = ?, surname = ?, gender = ?, phone_number = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                    $stmt->execute([$firstName, $middleName, $surname, $gender, $phoneNumber, $adminName, $userId]);
                    $toastMessage = "Profile updated successfully";
                    $toastType = "success";
                    // Refresh user data
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch();
                }
                break;

            case 'update_account':
                $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
                $recoveryEmail = filter_var(trim($_POST['recovery_email'] ?? ''), FILTER_SANITIZE_EMAIL);
                $userName = trim($_POST['user_name'] ?? '') ?: null;
                $role = in_array($_POST['role'] ?? '', $validRoles) ? $_POST['role'] : 'user';
                $accountActive = in_array($_POST['account_active'] ?? '', $validYesNo) ? $_POST['account_active'] : 'yes';
                $emailVerified = in_array($_POST['email_verified'] ?? '', $validYesNo) ? $_POST['email_verified'] : 'no';

                // Check permissions
                if ($role === 'super_admin' && $adminRole !== 'super_admin') {
                    $toastMessage = "Only super admins can assign super admin role";
                    $toastType = "error";
                    break;
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $toastMessage = "Invalid primary email";
                    $toastType = "error";
                } elseif (!filter_var($recoveryEmail, FILTER_VALIDATE_EMAIL)) {
                    $toastMessage = "Invalid recovery email";
                    $toastType = "error";
                } else {
                    // Check email uniqueness
                    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                    $checkStmt->execute([$email, $userId]);
                    if ($checkStmt->fetch()) {
                        $toastMessage = "Email is already in use by another account";
                        $toastType = "error";
                    } else {
                        $stmt = $pdo->prepare("UPDATE users SET email = ?, recovery_email = ?, user_name = ?, role = ?, account_active = ?, email_verified = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                        $stmt->execute([$email, $recoveryEmail, $userName, $role, $accountActive, $emailVerified, $adminName, $userId]);
                        $toastMessage = "Account settings updated successfully";
                        $toastType = "success";
                        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                        $stmt->execute([$userId]);
                        $user = $stmt->fetch();
                    }
                }
                break;

            case 'update_security':
                $twoFactorEnabled = in_array($_POST['two_factor_enabled'] ?? '', $validYesNo) ? $_POST['two_factor_enabled'] : 'no';
                $passwordResetStatus = in_array($_POST['password_reset_status'] ?? '', $validYesNo) ? $_POST['password_reset_status'] : 'no';

                $stmt = $pdo->prepare("UPDATE users SET two_factor_enabled = ?, password_reset_status = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                $stmt->execute([$twoFactorEnabled, $passwordResetStatus, $adminName, $userId]);
                $toastMessage = "Security settings updated successfully";
                $toastType = "success";
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                break;

            case 'reset_password':
                // Generate temporary password
                $tempPassword = bin2hex(random_bytes(4)); // 8 characters
                $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
                $nowEAT = date('Y-m-d H:i:s');
                $resetExpiryEAT = date('Y-m-d H:i:s', strtotime('+24 hours'));

                // Store reset timestamps in East Africa Time to avoid DB/session timezone mismatch.
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, password_reset_status = 'yes', last_password_change = ?, password_reset_expires = ?, updated_at = ?, updated_by = ? WHERE id = ?");
                $stmt->execute([$passwordHash, $nowEAT, $resetExpiryEAT, $nowEAT, $adminName, $userId]);

                // Send email with temporary password
                $emailSent = sendPasswordResetEmail($user['email'], $user['first_name'], $tempPassword);

                if ($emailSent) {
                    $toastMessage = "Password reset. Temporary password sent to " . htmlspecialchars($user['email']) . " (24h expiry)";
                    $toastType = "success";
                } else {
                    $toastMessage = "Password reset but email failed to send. Temp password: " . $tempPassword;
                    $toastType = "warning";
                }

                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                break;

            case 'soft_delete':
                if ($user['role'] === 'super_admin') {
                    $toastMessage = "Cannot delete super admin accounts";
                    $toastType = "error";
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET deleted_at = NOW(), deleted_by = ? WHERE id = ?");
                    $stmt->execute([$adminName, $userId]);
                    $toastMessage = "User moved to trash successfully";
                    $toastType = "success";
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch();
                }
                break;

            case 'restore':
                $stmt = $pdo->prepare("UPDATE users SET deleted_at = NULL, deleted_by = NULL, updated_at = NOW(), updated_by = ? WHERE id = ?");
                $stmt->execute([$adminName, $userId]);
                $toastMessage = "User restored successfully";
                $toastType = "success";
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                break;

            case 'permanent_delete':
                if ($user['role'] === 'super_admin') {
                    $toastMessage = "Cannot permanently delete super admin accounts";
                    $toastType = "error";
                } else {
                    // Only allow if already soft deleted
                    if (!$user['deleted_at']) {
                        $toastMessage = "User must be soft deleted first";
                        $toastType = "error";
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                        $stmt->execute([$userId]);
                        header("Location: " . BASE_URL . "/Private/User_management/PHP/Frontend/User_management.php?status=trash&success=permanently_deleted");
                        exit();
                    }
                }
                break;

            case 'update_agreements':
                $privacyAgreed = in_array($_POST['privacy_agreed'] ?? '', $validYesNo) ? $_POST['privacy_agreed'] : 'no';
                $termsAgreed = in_array($_POST['terms_agreed'] ?? '', $validYesNo) ? $_POST['terms_agreed'] : 'no';
                $agreementAgreed = in_array($_POST['agreement_agreed'] ?? '', $validYesNo) ? $_POST['agreement_agreed'] : 'no';

                $privacyAgreedAt = $privacyAgreed === 'yes' ? ($user['privacy_agreed_at'] ?? date('Y-m-d H:i:s')) : null;
                $termsAgreedAt = $termsAgreed === 'yes' ? ($user['terms_agreed_at'] ?? date('Y-m-d H:i:s')) : null;
                $agreementAgreedAt = $agreementAgreed === 'yes' ? ($user['agreement_agreed_at'] ?? date('Y-m-d H:i:s')) : null;

                $stmt = $pdo->prepare("UPDATE users SET privacy_agreed = ?, terms_agreed = ?, agreement_agreed = ?, privacy_agreed_at = ?, terms_agreed_at = ?, agreement_agreed_at = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                $stmt->execute([$privacyAgreed, $termsAgreed, $agreementAgreed, $privacyAgreedAt, $termsAgreedAt, $agreementAgreedAt, $adminName, $userId]);
                $toastMessage = "Agreement status updated";
                $toastType = "success";
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                break;

            case 'clear_certificate_acceptance':
                $certId = intval($_POST['certificate_id'] ?? 0);
                if ($certId <= 0) {
                    $toastMessage = "Invalid certificate request";
                    $toastType = "error";
                    break;
                }
                try {
                    $hasCerts = (bool) $pdo->query("SHOW TABLES LIKE 'security_certificates'")->fetch();
                    $hasAccept = (bool) $pdo->query("SHOW TABLES LIKE 'security_certificate_acceptances'")->fetch();
                    if ($hasCerts && $hasAccept) {
                        $del = $pdo->prepare("DELETE FROM security_certificate_acceptances WHERE certificate_id = ? AND user_id = ? LIMIT 1");
                        $del->execute([$certId, $user['user_id']]);
                        $toastMessage = "Certificate acceptance cleared. User will be asked to sign again.";
                        $toastType = "success";
                    } else {
                        $toastMessage = "Certificates feature is not available in this environment.";
                        $toastType = "warning";
                    }
                } catch (Exception $e) {
                    error_log("Clear certificate acceptance error: " . $e->getMessage());
                    $toastMessage = "Failed to clear certificate acceptance.";
                    $toastType = "error";
                }
                break;

            case 'clear_all_certificate_acceptances':
                try {
                    $hasCerts = (bool) $pdo->query("SHOW TABLES LIKE 'security_certificates'")->fetch();
                    $hasAccept = (bool) $pdo->query("SHOW TABLES LIKE 'security_certificate_acceptances'")->fetch();
                    if ($hasCerts && $hasAccept) {
                        $del = $pdo->prepare("DELETE FROM security_certificate_acceptances WHERE user_id = ?");
                        $del->execute([$user['user_id']]);
                        $toastMessage = "All certificate acceptances cleared. User will be asked to sign again.";
                        $toastType = "success";
                    } else {
                        $toastMessage = "Certificates feature is not available in this environment.";
                        $toastType = "warning";
                    }
                } catch (Exception $e) {
                    error_log("Clear all certificate acceptances error: " . $e->getMessage());
                    $toastMessage = "Failed to clear certificate acceptances.";
                    $toastType = "error";
                }
                break;
        }
    }

    // Certificates overview for this user (optional feature)
    $userCertificates = [];
    $userCertificatesAvailable = false;
    try {
        $userCertificatesAvailable = (bool) $pdo->query("SHOW TABLES LIKE 'security_certificates'")->fetch()
            && (bool) $pdo->query("SHOW TABLES LIKE 'security_certificate_acceptances'")->fetch();
        if ($userCertificatesAvailable) {
            // Include active, non-trashed certificates only
            $certWhereDeleted = '';
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM security_certificates LIKE 'deleted_at'")->fetch();
                if ($cols) {
                    $certWhereDeleted = " AND (c.deleted_at IS NULL)";
                }
            } catch (Exception $e) {
                $certWhereDeleted = '';
            }

            $stmt = $pdo->prepare("
                SELECT
                    c.id,
                    c.title,
                    c.target_type,
                    c.target_value,
                    c.is_active,
                    c.created_at,
                    a.accepted_at
                FROM security_certificates c
                LEFT JOIN security_certificate_acceptances a
                    ON a.certificate_id = c.id AND a.user_id = :uid
                WHERE c.is_active = 'yes'
                  {$certWhereDeleted}
                  AND (
                        c.target_type = 'everyone'
                        OR (c.target_type = 'role' AND c.target_value = :role)
                        OR (c.target_type = 'user_id' AND c.target_value = :uid2)
                        OR (c.target_type = 'username' AND c.target_value = :uname)
                  )
                ORDER BY c.created_at DESC
                LIMIT 100
            ");
            // Important: separate params for native prepares (no reuse issues)
            $stmt->execute([
                ':uid' => $user['user_id'],
                ':uid2' => $user['user_id'],
                ':role' => $user['role'],
                ':uname' => $user['user_name']
            ]);
            $userCertificates = $stmt->fetchAll();
        }
    } catch (Exception $e) {
        $userCertificatesAvailable = false;
        $userCertificates = [];
    }

} catch (Exception $e) {
    error_log("User Detail Error: " . $e->getMessage());
    $toastMessage = $e->getMessage();
    $toastType = "error";
    $user = null;
}

// Helper functions
function formatDate($date)
{
    return $date ? date('F j, Y \a\t g:i A', strtotime($date)) : 'Never';
}

function getYesNoColor($value)
{
    return $value === 'yes' ? 'var(--sq-success)' : 'var(--sq-danger)';
}

function getYesNoIcon($value)
{
    return $value === 'yes' ? 'fa-check-circle' : 'fa-times-circle';
}

// Profile photo URL helper - same as admin_users_list.php
function getProfilePhotoUrl($profilePhoto, $firstName, $surname)
{
    if (!empty($profilePhoto)) {
        // Images are in ScanQuotient.v2/ScanQuotient.B folder
        $photoPath = ltrim($profilePhoto, '/');
        return STORAGE_URL . '/' . $photoPath;
    }

    // Fallback to UI Avatars
    return 'https://ui-avatars.com/api/?name=' . urlencode($firstName . '+' . $surname) . '&background=8b5cf6&color=fff&size=200';
}

// Send password reset email
function sendPasswordResetEmail($toEmail, $firstName, $tempPassword)
{
    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'scanquotient@gmail.com';
        $mail->Password = 'vnht iefe anwl xynb';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('scanquotient@gmail.com', 'ScanQuotient Security');
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Your Temporary Password - ScanQuotient';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f8fafc; padding: 20px;'>
                <div style='background: linear-gradient(135deg, #2563eb, #7c3aed); padding: 30px; border-radius: 10px 10px 0 0; text-align: center;'>
                    <h1 style='color: white; margin: 0; font-size: 24px;'>ScanQuotient Security</h1>
                </div>
                
                <div style='background: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);'>
                    <p style='font-size: 16px; color: #1a202c;'>Hello <strong>" . htmlspecialchars($firstName) . "</strong>,</p>
                    
                    <p style='color: #4a5568; line-height: 1.6;'>Your password has been reset by an administrator. Your temporary password is:</p>
                    
                    <div style='background: #f0f4f8; padding: 25px; text-align: center; border-radius: 10px; margin: 25px 0; border-left: 4px solid #2563eb;'>
                        <div style='font-size: 32px; font-weight: bold; color: #2563eb; letter-spacing: 4px; font-family: monospace;'>
                            {$tempPassword}
                        </div>
                    </div>
                    
                    <p style='color: #4a5568;'>This temporary password expires in <strong>24 hours</strong>.</p>
                    
                    <p style='color: #4a5568;'>Please log in and change your password immediately.</p>
                    
                    <div style='background: #fef3c7; border: 1px solid #f59e0b; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                        <p style='margin: 0; color: #92400e; font-size: 14px;'>
                            <strong>🔒 Security Notice:</strong><br>
                            If you did not request this password reset, please contact your administrator immediately.
                        </p>
                    </div>
                    
                    <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 25px 0;'>
                    
                    <p style='color: #94a3b8; font-size: 12px;'>
                        This is an automated message from ScanQuotient.<br>
                        Do not reply to this email.
                    </p>
                </div>
            </div>
        ";

        $mail->AltBody = "
ScanQuotient Security - Temporary Password

Hello {$firstName},

Your password has been reset by an administrator.

Temporary Password: {$tempPassword}

This password expires in 24 hours. Please log in and change your password immediately.

If you did not request this reset, contact your administrator immediately.
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Password reset email failed: " . $e->getMessage());
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $user ? htmlspecialchars($user['first_name'] . ' ' . $user['surname']) : 'User'; ?> | ScanQuotient
        Admin</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../CSS/user_detail.css" />
    <style>
        /* Toast Notifications */
        .sq-toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .sq-toast {
            padding: 16px 20px;
            border-radius: 10px;
            color: white;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 300px;
            max-width: 400px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            animation: sq-toast-in 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .sq-toast--success {
            background: rgba(16, 185, 129, 0.95);
            border-left: 4px solid #059669;
        }

        .sq-toast--error {
            background: rgba(239, 68, 68, 0.95);
            border-left: 4px solid #dc2626;
        }

        .sq-toast--warning {
            background: rgba(245, 158, 11, 0.95);
            border-left: 4px solid #d97706;
        }

        @keyframes sq-toast-in {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes sq-toast-out {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        .sq-toast.hiding {
            animation: sq-toast-out 0.3s ease forwards;
        }

        .sq-inline-warning {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            margin: 8px 0 18px;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid rgba(245, 158, 11, 0.35);
            background: rgba(245, 158, 11, 0.1);
            color: var(--sq-text-main, #1f2937);
            font-size: 13px;
            line-height: 1.5;
        }

        .sq-inline-warning i {
            color: #d97706;
            margin-top: 2px;
        }

        .sq-inline-warning--hidden {
            display: none;
        }

        /* Profile Image Upload Styles */
        .sq-profile-image-container {
            position: relative;
            display: inline-block;
            cursor: pointer;
        }

        .sq-user-avatar-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--sq-border, #e5e7eb);
            transition: all 0.3s ease;
        }

        .sq-profile-image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .sq-profile-image-container:hover .sq-profile-image-overlay {
            opacity: 1;
        }

        .sq-profile-image-container:hover .sq-user-avatar-large {
            filter: brightness(0.8);
        }

        .sq-profile-image-icon {
            color: white;
            font-size: 24px;
        }

        /* Upload Modal Styles */
        .sq-upload-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }

        .sq-upload-modal.sq-modal--active {
            display: flex;
        }

        .sq-upload-modal-content {
            background: var(--sq-card-bg, white);
            border-radius: 16px;
            padding: 32px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: sq-modal-slide-in 0.3s ease;
        }

        @keyframes sq-modal-slide-in {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .sq-upload-modal-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--sq-text-main, #111827);
        }

        .sq-upload-modal-subtitle {
            color: var(--sq-text-light, #6b7280);
            font-size: 14px;
            margin-bottom: 24px;
        }

        .sq-upload-dropzone {
            border: 2px dashed var(--sq-border, #d1d5db);
            border-radius: 12px;
            padding: 40px 20px;
            text-align: center;
            transition: all 0.3s ease;
            background: var(--sq-bg-secondary, #f9fafb);
            margin-bottom: 24px;
            cursor: pointer;
        }

        .sq-upload-dropzone.sq-dragover {
            border-color: var(--sq-brand, #3b82f6);
            background: rgba(59, 130, 246, 0.1);
        }

        .sq-upload-dropzone-icon {
            font-size: 48px;
            color: var(--sq-text-light, #9ca3af);
            margin-bottom: 16px;
        }

        .sq-upload-dropzone-text {
            color: var(--sq-text-main, #374151);
            font-weight: 500;
            margin-bottom: 8px;
        }

        .sq-upload-dropzone-hint {
            color: var(--sq-text-light, #6b7280);
            font-size: 13px;
        }

        .sq-upload-input {
            display: none;
        }

        .sq-upload-preview {
            display: none;
            margin-bottom: 24px;
            text-align: center;
        }

        .sq-upload-preview.sq-preview-active {
            display: block;
        }

        .sq-upload-preview-image {
            max-width: 200px;
            max-height: 200px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .sq-upload-preview-name {
            margin-top: 12px;
            color: var(--sq-text-light, #6b7280);
            font-size: 14px;
        }

        .sq-upload-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        .sq-btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .sq-btn-primary {
            background: var(--sq-brand, #3b82f6);
            color: white;
        }

        .sq-btn-primary:hover:not(:disabled) {
            background: var(--sq-brand-dark, #2563eb);
        }

        .sq-btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .sq-btn-secondary {
            background: var(--sq-bg-secondary, #f3f4f6);
            color: var(--sq-text-main, #374151);
        }

        .sq-btn-secondary:hover {
            background: var(--sq-border, #e5e7eb);
        }

        /* Dark mode support */
        body.sq-dark .sq-upload-modal-content {
            background: var(--sq-card-bg-dark, #1f2937);
        }

        body.sq-dark .sq-upload-modal-title {
            color: var(--sq-text-main-dark, #f9fafb);
        }

        body.sq-dark .sq-upload-dropzone {
            background: var(--sq-bg-secondary-dark, #374151);
            border-color: var(--sq-border-dark, #4b5563);
        }

        body.sq-dark .sq-upload-dropzone-text {
            color: var(--sq-text-main-dark, #e5e7eb);
        }

        body.sq-dark .sq-btn-secondary {
            background: var(--sq-bg-secondary-dark, #374151);
            color: var(--sq-text-main-dark, #e5e7eb);
        }

        body.sq-dark .sq-user-avatar-large {
            border-color: var(--sq-border-dark, #4b5563);
        }

        /* Edit toggle (read-only on load for better UX) */
        .sq-edit-fab {
            position: fixed;
            right: 22px;
            bottom: 22px;
            z-index: 1200;
            border-radius: 999px;
            border: none;
            background: #3b82f6;
            color: #fff;
            height: 46px;
            padding: 0 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 12px 26px rgba(59, 130, 246, 0.35);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .sq-edit-fab:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 30px rgba(59, 130, 246, 0.45);
        }

        .sq-requires-edit[disabled] {
            opacity: 0.58;
            cursor: not-allowed;
            pointer-events: none;
        }

        .sq-readonly-scope input:disabled,
        .sq-readonly-scope select:disabled,
        .sq-readonly-scope textarea:disabled {
            opacity: 0.72;
            cursor: not-allowed;
        }

        .sq-edit-mode-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid rgba(59, 130, 246, 0.28);
            background: rgba(59, 130, 246, 0.1);
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.2px;
        }

        .sq-edit-mode-pill--editable {
            border-color: rgba(16, 185, 129, 0.32);
            background: rgba(16, 185, 129, 0.14);
            color: #047857;
        }
    </style>
</head>

<body>

    <!-- Toast Container -->
    <div class="sq-toast-container" id="sqToastContainer"></div>
    <?php if ($user && empty($user['deleted_at'])): ?>
        <button type="button" id="sqEditFab" class="sq-edit-fab" title="Enable edit mode">
            <i class="fas fa-pen"></i>
            <span>Edit</span>
        </button>
    <?php endif; ?>
    <header class="sq-admin-header">
        <div class="sq-admin-header-left">
            <a href="/ScanQuotient.v2/ScanQuotient.B/Private/User_management/PHP/Frontend/User_management.php"
                class="sq-admin-back-btn">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div class="brand-wrapper">
                <a href="#" class="sq-admin-brand" style="color:#6c63ff;">ScanQuotient</a>
                <p class="sq-admin-tagline">Quantifying Risk. Strengthening Security.</p>
            </div>
        </div>
        <div class="sq-admin-header-right">
            <img src="<?php echo htmlspecialchars($avatar_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile" class="header-profile-photo">
            <div class="sq-admin-user">
                <i class="fas fa-user-shield"></i>
                <span>
                    <?php echo htmlspecialchars($adminName); ?>
                </span>
            </div>
            <button class="sq-admin-theme-toggle" id="sqThemeToggle" title="Toggle Theme">
                <i class="fas fa-sun"></i>
            </button>
            <a href="../../../../Private/Admin_dashboard/PHP/Frontend/Admin_dashboard.php" class="icon-btn"
                title="Logout"><i class="fas fa-home"></i></a>
            <a href="../../../../Public/Login_page/PHP/Frontend/Login_page_site.php" class="icon-btn" title="Logout"><i
                    class="fas fa-sign-out-alt"></i></a>
        </div>
    </header>
    <!-- Confirmation Modals -->
    <div class="sq-modal" id="sqDeleteModal">
        <div class="sq-modal-content">
            <h3 class="sq-modal-title"><i class="fas fa-exclamation-triangle" style="color: var(--sq-danger);"></i> Move
                to Trash?</h3>
            <p class="sq-modal-text">This will soft delete the user account. They will not be able to log in, but the
                data can be restored later.</p>
            <div class="sq-modal-actions">
                <button class="sq-btn sq-btn-secondary" onclick="sqCloseModal('sqDeleteModal')">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="soft_delete">
                    <button type="submit" class="sq-btn sq-btn-danger">
                        <i class="fas fa-trash"></i> Move to Trash
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="sq-modal" id="sqPermanentDeleteModal">
        <div class="sq-modal-content">
            <h3 class="sq-modal-title"><i class="fas fa-exclamation-circle" style="color: var(--sq-danger);"></i>
                Permanently Delete?</h3>
            <p class="sq-modal-text">This action cannot be undone. All user data will be permanently removed from the
                database.</p>
            <div class="sq-modal-actions">
                <button class="sq-btn sq-btn-secondary" onclick="sqCloseModal('sqPermanentDeleteModal')">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="permanent_delete">
                    <button type="submit" class="sq-btn sq-btn-danger">
                        <i class="fas fa-trash-alt"></i> Delete Forever
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="sq-modal" id="sqTempPasswordModal">
        <div class="sq-modal-content">
            <h3 class="sq-modal-title">
                <i class="fas fa-key" style="color: var(--sq-warning);"></i>
                Generate Temporary Password?
            </h3>
            <p class="sq-modal-text">
                This will reset the user's password and require them to set a new password on next login.
                The temporary password expires in 24 hours.
            </p>
            <div class="sq-modal-actions">
                <button class="sq-btn sq-btn-secondary" onclick="sqCloseModal('sqTempPasswordModal')">Cancel</button>
                <form method="POST" style="display: inline;" onsubmit="return sqHandleTempPasswordSubmit(this)">
                    <input type="hidden" name="action" value="reset_password">
                    <button type="submit" id="sqTempPasswordSubmitBtn" class="sq-btn sq-btn-warning">
                        <i class="fas fa-key"></i> Generate Password
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Profile Image Upload Modal -->
    <div class="sq-upload-modal" id="sqUploadModal">
        <div class="sq-upload-modal-content">
            <h3 class="sq-upload-modal-title">
                <i class="fas fa-camera"></i> Update Profile Photo
            </h3>
            <p class="sq-upload-modal-subtitle">Drag and drop an image or click to browse</p>

            <div class="sq-upload-dropzone" id="sqDropzone">
                <div class="sq-upload-dropzone-content" id="sqDropzoneContent">
                    <i class="fas fa-cloud-upload-alt sq-upload-dropzone-icon"></i>
                    <p class="sq-upload-dropzone-text">Click or drag image here</p>
                    <p class="sq-upload-dropzone-hint">Supports: JPG, PNG, GIF (Max 5MB)</p>
                </div>
                <input type="file" id="sqFileInput" class="sq-upload-input" accept="image/*">
            </div>

            <div class="sq-upload-preview" id="sqPreviewContainer">
                <img src="" alt="Preview" class="sq-upload-preview-image" id="sqPreviewImage">
                <p class="sq-upload-preview-name" id="sqPreviewName"></p>
            </div>

            <div class="sq-upload-actions">
                <button type="button" class="sq-btn sq-btn-secondary" onclick="sqCloseUploadModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="sq-btn sq-btn-primary" id="sqSavePhotoBtn" onclick="sqSavePhoto()"
                    disabled>
                    <i class="fas fa-save"></i> Save Photo
                </button>
            </div>
        </div>
    </div>

    <main class="sq-admin-container">

        <?php if ($user): ?>
            <!-- User Header -->
            <div class="sq-user-header">
                <?php
                // FIXED: Use helper function to get correct image URL
                $avatarUrl = getProfilePhotoUrl($user['profile_photo'], $user['first_name'], $user['surname']);
                ?>

                <!-- Profile Image with Hover Effect -->
                <div class="sq-profile-image-container" onclick="sqOpenUploadModal()" title="Click to change photo">
                    <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="" class="sq-user-avatar-large"
                        id="sqCurrentAvatar"
                        onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($user['first_name'] . '+' . $user['surname']); ?>&background=8b5cf6&color=fff&size=200'">
                    <div class="sq-profile-image-overlay">
                        <i class="fas fa-camera sq-profile-image-icon"></i>
                    </div>
                </div>

                <div class="sq-user-header-info">
                    <h1 class="sq-user-header-name">
                        <?php echo htmlspecialchars($user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['surname']); ?>
                        <?php if ($user['deleted_at']): ?>
                            <span style="color: var(--sq-danger); font-size: 18px;">(DELETED)</span>
                        <?php endif; ?>
                    </h1>

                    <div class="sq-user-header-meta">
                        <span class="sq-user-header-badge"
                            style="background: <?php echo $user['role'] === 'super_admin' ? 'rgba(239,68,68,0.1)' : ($user['role'] === 'admin' ? 'rgba(245,158,11,0.1)' : 'rgba(59,130,246,0.1)'); ?>; color: <?php echo $user['role'] === 'super_admin' ? 'var(--sq-danger)' : ($user['role'] === 'admin' ? 'var(--sq-warning)' : 'var(--sq-brand)'); ?>;">
                            <i
                                class="fas <?php echo $user['role'] === 'super_admin' ? 'fa-crown' : ($user['role'] === 'admin' ? 'fa-user-shield' : 'fa-user'); ?>"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                        </span>

                        <span class="sq-user-header-badge"
                            style="background: <?php echo $user['account_active'] === 'yes' ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)'; ?>; color: <?php echo $user['account_active'] === 'yes' ? 'var(--sq-success)' : 'var(--sq-danger)'; ?>;">
                            <i class="fas <?php echo $user['account_active'] === 'yes' ? 'fa-check' : 'fa-times'; ?>"></i>
                            <?php echo $user['account_active'] === 'yes' ? 'Active' : 'Inactive'; ?>
                        </span>

                        <span class="sq-user-header-badge"
                            style="background: rgba(59,130,246,0.1); color: var(--sq-brand);">
                            <i class="fas fa-id-card"></i>
                            ID: <?php echo htmlspecialchars($user['user_id']); ?>
                        </span>
                    </div>

                    <div class="sq-user-actions">
                        <?php if (empty($user['deleted_at'])): ?>
                            <span id="sqEditModePill" class="sq-edit-mode-pill">
                                <i class="fas fa-lock"></i>
                                <span>Read-only mode</span>
                            </span>
                        <?php endif; ?>
                        <?php if ($user['deleted_at']): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="restore">
                                <button type="submit" class="sq-btn sq-btn-success">
                                    <i class="fas fa-undo"></i> Restore User
                                </button>
                            </form>
                            <button type="button" class="sq-btn sq-btn-danger" onclick="sqOpenModal('sqPermanentDeleteModal')">
                                <i class="fas fa-trash-alt"></i> Delete Forever
                            </button>
                        <?php else: ?>
                            <button type="button" class="sq-btn sq-btn-warning sq-requires-edit" onclick="sqOpenModal('sqDeleteModal')">
                                <i class="fas fa-trash"></i> Deactivate user
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Edit Sections -->
            <div class="sq-sections-grid">

                <!-- Profile Information -->
                <div class="sq-section">
                    <div class="sq-section-header">
                        <div class="sq-section-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <h2 class="sq-section-title">Profile Information</h2>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="sq-form-grid">
                            <div class="sq-form-group">
                                <label class="sq-form-label">First Name</label>
                                <input type="text" name="first_name" class="sq-form-input"
                                    value="<?php echo htmlspecialchars($user['first_name']); ?>" required <?php echo $user['deleted_at'] ? 'disabled' : ''; ?>>
                            </div>

                            <div class="sq-form-group">
                                <label class="sq-form-label">Surname</label>
                                <input type="text" name="surname" class="sq-form-input"
                                    value="<?php echo htmlspecialchars($user['surname']); ?>" required <?php echo $user['deleted_at'] ? 'disabled' : ''; ?>>
                            </div>

                            <div class="sq-form-group">
                                <label class="sq-form-label">Middle Name</label>
                                <input type="text" name="middle_name" class="sq-form-input"
                                    value="<?php echo htmlspecialchars($user['middle_name'] ?? ''); ?>" <?php echo $user['deleted_at'] ? 'disabled' : ''; ?>>
                            </div>

                            <div class="sq-form-group">
                                <label class="sq-form-label">Gender</label>
                                <select name="gender" class="sq-form-select" <?php echo $user['deleted_at'] ? 'disabled' : ''; ?>>
                                    <option value="male" <?php echo $user['gender'] === 'male' ? 'selected' : ''; ?>>Male
                                    </option>
                                    <option value="female" <?php echo $user['gender'] === 'female' ? 'selected' : ''; ?>>
                                        Female</option>
                                    <option value="other" <?php echo $user['gender'] === 'other' ? 'selected' : ''; ?>>Other
                                    </option>
                                </select>
                            </div>

                            <div class="sq-form-group sq-form-group--full">
                                <label class="sq-form-label">Phone Number</label>
                                <input type="tel" name="phone_number" class="sq-form-input"
                                    value="<?php echo htmlspecialchars($user['phone_number']); ?>" <?php echo $user['deleted_at'] ? 'disabled' : ''; ?>>
                            </div>
                        </div>

                        <?php if (!$user['deleted_at']): ?>
                            <div style="margin-top: 24px;">
                                <button type="submit" class="sq-btn sq-btn-primary">
                                    <i class="fas fa-save"></i> Save Profile Changes
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Account Settings -->
                <div class="sq-section">
                    <div class="sq-section-header">
                        <div class="sq-section-icon">
                            <i class="fas fa-cog"></i>
                        </div>
                        <h2 class="sq-section-title">Account Settings</h2>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="update_account">
                        <div class="sq-form-grid">
                            <div class="sq-form-group sq-form-group--full">
                                <label class="sq-form-label">Primary Email</label>
                                <input type="email" name="email" class="sq-form-input"
                                    value="<?php echo htmlspecialchars($user['email']); ?>" required <?php echo $user['deleted_at'] ? 'disabled' : ''; ?>>
                            </div>

                            <div class="sq-form-group sq-form-group--full">
                                <label class="sq-form-label">Recovery Email</label>
                                <input type="email" name="recovery_email" class="sq-form-input"
                                    value="<?php echo htmlspecialchars($user['recovery_email']); ?>" required <?php echo $user['deleted_at'] ? 'disabled' : ''; ?>>
                            </div>

                            <div class="sq-form-group">
                                <label class="sq-form-label">Username</label>
                                <input type="text" name="user_name" class="sq-form-input"
                                    value="<?php echo htmlspecialchars($user['user_name'] ?? ''); ?>" <?php echo $user['deleted_at'] ? 'disabled' : ''; ?>>
                            </div>

                            <div class="sq-form-group">
                                <label class="sq-form-label">Role</label>
                                <select name="role" class="sq-form-select" <?php echo ($user['deleted_at'] || ($user['role'] === 'super_admin' && $adminRole !== 'super_admin')) ? 'disabled' : ''; ?>>
                                    <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>User
                                    </option>
                                    <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin
                                    </option>
                                    <?php if ($adminRole === 'super_admin'): ?>
                                        <option value="super_admin" <?php echo $user['role'] === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
                                    <?php endif; ?>
                                </select>
                                <?php if ($user['role'] === 'super_admin' && $adminRole !== 'super_admin'): ?>
                                    <span class="sq-form-hint" style="color: var(--sq-danger);">Only super admins can change
                                        this</span>
                                <?php endif; ?>
                            </div>

                            <div class="sq-form-group">
                                <label class="sq-form-label">Account Active</label>
                                <select name="account_active" class="sq-form-select" <?php echo $user['deleted_at'] ? 'disabled' : ''; ?>>
                                    <option value="yes" <?php echo $user['account_active'] === 'yes' ? 'selected' : ''; ?>>Yes
                                    </option>
                                    <option value="no" <?php echo $user['account_active'] === 'no' ? 'selected' : ''; ?>>No
                                    </option>
                                </select>
                            </div>

                            <div class="sq-form-group">
                                <label class="sq-form-label">Email Verified</label>
                                <select name="email_verified" class="sq-form-select" <?php echo $user['deleted_at'] ? 'disabled' : ''; ?>>
                                    <option value="yes" <?php echo $user['email_verified'] === 'yes' ? 'selected' : ''; ?>>Yes
                                    </option>
                                    <option value="no" <?php echo $user['email_verified'] === 'no' ? 'selected' : ''; ?>>No
                                    </option>
                                </select>
                            </div>
                        </div>

                        <?php if (!$user['deleted_at']): ?>
                            <div style="margin-top: 24px;">
                                <button type="submit" class="sq-btn sq-btn-primary">
                                    <i class="fas fa-save"></i> Save Account Changes
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Security Settings -->
                <div class="sq-section">
                    <div class="sq-section-header">
                        <div class="sq-section-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h2 class="sq-section-title">Security Settings</h2>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="update_security">
                        <div class="sq-form-grid">
                            <div class="sq-form-group">
                                <label class="sq-form-label">Two-Factor Auth</label>
                                <select name="two_factor_enabled" class="sq-form-select" <?php echo $user['deleted_at'] ? 'disabled' : ''; ?>>
                                    <option value="yes" <?php echo $user['two_factor_enabled'] === 'yes' ? 'selected' : ''; ?>>Enabled</option>
                                    <option value="no" <?php echo $user['two_factor_enabled'] === 'no' ? 'selected' : ''; ?>>
                                        Disabled</option>
                                </select>
                            </div>

                            <div class="sq-form-group">
                                <label class="sq-form-label">Password Reset Required</label>
                                <select name="password_reset_status" class="sq-form-select" <?php echo $user['deleted_at'] ? 'disabled' : ''; ?>>
                                    <option value="yes" <?php echo $user['password_reset_status'] === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                    <option value="no" <?php echo $user['password_reset_status'] === 'no' ? 'selected' : ''; ?>>No</option>
                                </select>
                            </div>
                        </div>

                        <?php if (!$user['deleted_at']): ?>
                            <div style="margin-top: 24px;">
                                <button type="submit" class="sq-btn sq-btn-primary">
                                    <i class="fas fa-save"></i> Save Security Changes
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>

                    <!-- Single Password Reset Button -->
                    <?php if (!$user['deleted_at']): ?>
                        <div style="margin-top: 16px;">
                            <button type="button" id="sqOpenTempPasswordBtn" class="sq-btn sq-btn-warning sq-requires-edit"
                                onclick="sqOpenModal('sqTempPasswordModal')">
                                <i class="fas fa-key"></i> Generate Temp Password
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Legal Agreements -->
                <div class="sq-section">
                    <div class="sq-section-header">
                        <div class="sq-section-icon">
                            <i class="fas fa-file-contract"></i>
                        </div>
                        <h2 class="sq-section-title">Legal Agreements</h2>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="update_agreements">
                        <?php if (!$user['deleted_at']): ?>
                            <div id="sqAgreementsWarning" class="sq-inline-warning sq-inline-warning--hidden">
                                <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                                <span>Setting any agreement to <strong>Not Agreed</strong> will require this user to re-accept missing
                                    policies on their next login.</span>
                            </div>
                        <?php endif; ?>
                        <div class="sq-form-grid">
                            <div class="sq-form-group">
                                <label class="sq-form-label">Privacy Policy</label>
                                <select name="privacy_agreed" class="sq-form-select" <?php echo $user['deleted_at'] ? 'disabled' : ''; ?>>
                                    <option value="yes" <?php echo $user['privacy_agreed'] === 'yes' ? 'selected' : ''; ?>>
                                        Agreed</option>
                                    <option value="no" <?php echo $user['privacy_agreed'] === 'no' ? 'selected' : ''; ?>>Not
                                        Agreed</option>
                                </select>
                                <?php if ($user['privacy_agreed_at']): ?>
                                    <span class="sq-form-hint">Agreed on:
                                        <?php echo formatDate($user['privacy_agreed_at']); ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="sq-form-group">
                                <label class="sq-form-label">Terms of Service</label>
                                <select name="terms_agreed" class="sq-form-select" <?php echo $user['deleted_at'] ? 'disabled' : ''; ?>>
                                    <option value="yes" <?php echo $user['terms_agreed'] === 'yes' ? 'selected' : ''; ?>>
                                        Agreed</option>
                                    <option value="no" <?php echo $user['terms_agreed'] === 'no' ? 'selected' : ''; ?>>Not
                                        Agreed</option>
                                </select>
                                <?php if ($user['terms_agreed_at']): ?>
                                    <span class="sq-form-hint">Agreed on:
                                        <?php echo formatDate($user['terms_agreed_at']); ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="sq-form-group">
                                <label class="sq-form-label">User Agreement</label>
                                <select name="agreement_agreed" class="sq-form-select" <?php echo $user['deleted_at'] ? 'disabled' : ''; ?>>
                                    <option value="yes" <?php echo $user['agreement_agreed'] === 'yes' ? 'selected' : ''; ?>>
                                        Agreed</option>
                                    <option value="no" <?php echo $user['agreement_agreed'] === 'no' ? 'selected' : ''; ?>>Not
                                        Agreed</option>
                                </select>
                                <?php if ($user['agreement_agreed_at']): ?>
                                    <span class="sq-form-hint">Agreed on:
                                        <?php echo formatDate($user['agreement_agreed_at']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!$user['deleted_at']): ?>
                            <div style="margin-top: 24px;">
                                <button type="submit" class="sq-btn sq-btn-primary">
                                    <i class="fas fa-save"></i> Update Agreements
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Certificates -->
                <div class="sq-section">
                    <div class="sq-section-header">
                        <div class="sq-section-icon">
                            <i class="fas fa-file-signature"></i>
                        </div>
                        <h2 class="sq-section-title">Certificates</h2>
                    </div>

                    <?php if (!$userCertificatesAvailable): ?>
                        <div class="sq-form-hint" style="color: var(--sq-text-light);">
                            Certificates are not enabled in this environment.
                        </div>
                    <?php elseif (empty($userCertificates)): ?>
                        <div class="sq-form-hint" style="color: var(--sq-text-light);">
                            No active certificates currently apply to this user.
                        </div>
                    <?php else: ?>
                        <div style="overflow:auto;">
                            <table class="sq-data-table" style="min-width: 720px;">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Title</th>
                                        <th>Target</th>
                                        <th>Signed</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($userCertificates as $c): ?>
                                        <tr>
                                            <td>#<?php echo (int) $c['id']; ?></td>
                                            <td><?php echo htmlspecialchars($c['title']); ?></td>
                                            <td style="color: var(--sq-text-light); font-size: 12px;">
                                                <?php echo htmlspecialchars($c['target_type']); ?>
                                                <?php echo !empty($c['target_value']) ? (': ' . htmlspecialchars($c['target_value'])) : ''; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($c['accepted_at'])): ?>
                                                    <span style="color: var(--sq-success); font-weight: 800;">
                                                        <i class="fas fa-check-circle"></i>
                                                        <?php echo htmlspecialchars(date('M j, Y', strtotime($c['accepted_at']))); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color: var(--sq-danger); font-weight: 800;">
                                                        <i class="fas fa-times-circle"></i> No
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Clear this certificate acceptance? User will be required to sign again.')">
                                                    <input type="hidden" name="action" value="clear_certificate_acceptance">
                                                    <input type="hidden" name="certificate_id" value="<?php echo (int) $c['id']; ?>">
                                                    <button type="submit" class="sq-btn sq-btn-warning sq-requires-edit">
                                                        <i class="fas fa-rotate-left"></i> Clear
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div style="margin-top: 14px; display:flex; justify-content:flex-end; gap: 10px; flex-wrap: wrap;">
                            <form method="POST" style="display:inline;"
                                onsubmit="return confirm('Clear all certificate acceptances for this user? They will be required to sign again.')">
                                <input type="hidden" name="action" value="clear_all_certificate_acceptances">
                                <button type="submit" class="sq-btn sq-btn-danger sq-requires-edit">
                                    <i class="fas fa-broom"></i> Clear All
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- System Information (Read Only) -->
                <div class="sq-section">
                    <div class="sq-section-header">
                        <div class="sq-section-icon">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <h2 class="sq-section-title">System Information</h2>
                    </div>

                    <div class="sq-info-grid">
                        <div class="sq-info-item">
                            <span class="sq-info-label">User ID</span>
                            <span class="sq-info-value"><?php echo htmlspecialchars($user['user_id']); ?></span>
                        </div>

                        <div class="sq-info-item">
                            <span class="sq-info-label">Database ID</span>
                            <span class="sq-info-value">#<?php echo $user['id']; ?></span>
                        </div>

                        <div class="sq-info-item">
                            <span class="sq-info-label">Created At</span>
                            <span class="sq-info-value"><?php echo formatDate($user['created_at']); ?></span>
                        </div>

                        <div class="sq-info-item">
                            <span class="sq-info-label">Last Updated</span>
                            <span class="sq-info-value"><?php echo formatDate($user['updated_at']); ?></span>
                        </div>

                        <?php if ($user['created_by']): ?>
                            <div class="sq-info-item">
                                <span class="sq-info-label">Created By</span>
                                <span class="sq-info-value"><?php echo htmlspecialchars($user['created_by']); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($user['updated_by']): ?>
                            <div class="sq-info-item">
                                <span class="sq-info-label">Last Updated By</span>
                                <span class="sq-info-value"><?php echo htmlspecialchars($user['updated_by']); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($user['last_password_change']): ?>
                            <div class="sq-info-item">
                                <span class="sq-info-label">Last Password Change</span>
                                <span class="sq-info-value"><?php echo formatDate($user['last_password_change']); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($user['deleted_at']): ?>
                            <div class="sq-info-item">
                                <span class="sq-info-label" style="color: var(--sq-danger);">Deleted At</span>
                                <span class="sq-info-value"
                                    style="color: var(--sq-danger);"><?php echo formatDate($user['deleted_at']); ?></span>
                            </div>

                            <div class="sq-info-item">
                                <span class="sq-info-label" style="color: var(--sq-danger);">Deleted By</span>
                                <span class="sq-info-value"
                                    style="color: var(--sq-danger);"><?php echo htmlspecialchars($user['deleted_by']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        <?php else: ?>
            <div class="sq-section" style="text-align: center; padding: 60px;">
                <div style="font-size: 48px; color: var(--sq-text-light); margin-bottom: 16px;">
                    <i class="fas fa-user-times"></i>
                </div>
                <h2 style="color: var(--sq-text-main); margin-bottom: 8px;">User Not Found</h2>
                <p style="color: var(--sq-text-light);">The requested user could not be found or you don't have permission
                    to view them.</p>
                <a href="<?php echo BASE_URL; ?>/Privatepages/Admin_dashboard/PHP/Frontend/admin_users_list.php"
                    class="sq-btn sq-btn-primary" style="margin-top: 24px;">
                    <i class="fas fa-arrow-left"></i> Back to Users List
                </a>
            </div>
        <?php endif; ?>

        <footer class="sq-admin-footer">
            <p>ScanQuotient Security Platform • Quantifying Risk. Strengthening Security.</p>
        </footer>
    </main>

    <script>
        // Theme Toggle
        const sqThemeToggle = document.getElementById('sqThemeToggle');
        const sqBody = document.body;

        function sqSetTheme(theme) {
            sqBody.classList.toggle('sq-dark', theme === 'dark');
            sqThemeToggle.innerHTML = theme === 'dark' ? '<i class="fas fa-moon"></i>' : '<i class="fas fa-sun"></i>';
        }

        sqThemeToggle.addEventListener('click', () => {
            const current = sqBody.classList.contains('sq-dark') ? 'light' : 'dark';
            sqSetTheme(current);
            localStorage.setItem('sq-admin-theme', current);
        });

        sqSetTheme(localStorage.getItem('sq-admin-theme') || 'light');

        // Toast Notification System
        function showToast(message, type = 'success') {
            const container = document.getElementById('sqToastContainer');
            const toast = document.createElement('div');
            toast.className = `sq-toast sq-toast--${type}`;

            const icon = type === 'success' ? 'fa-check-circle' :
                type === 'error' ? 'fa-exclamation-circle' : 'fa-exclamation-triangle';

            toast.innerHTML = `
                <i class="fas ${icon}"></i>
                <span>${message}</span>
            `;

            container.appendChild(toast);

            // Auto remove after 5 seconds
            setTimeout(() => {
                toast.classList.add('hiding');
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }

        // Show toast if PHP set a message
        <?php if (!empty($toastMessage)): ?>
            showToast(<?php echo json_encode($toastMessage); ?>, <?php echo json_encode($toastType); ?>);
        <?php endif; ?>

        function sqHandleTempPasswordSubmit(form) {
            const submitBtn = document.getElementById('sqTempPasswordSubmitBtn');
            const openBtn = document.getElementById('sqOpenTempPasswordBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            }
            if (openBtn) {
                openBtn.disabled = true;
                openBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            }
            if (form) {
                form.dataset.submitting = '1';
            }
            return true;
        }

        // Modal Functions
        function sqOpenModal(modalId) {
            document.getElementById(modalId).classList.add('sq-modal--active');
            sqBody.style.overflow = 'hidden';
        }

        function sqCloseModal(modalId) {
            document.getElementById(modalId).classList.remove('sq-modal--active');
            sqBody.style.overflow = '';
        }

        // Close modals on outside click
        document.querySelectorAll('.sq-modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('sq-modal--active');
                    sqBody.style.overflow = '';
                }
            });
        });

        // Escape key to close modals
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.sq-modal--active, .sq-upload-modal.sq-modal--active').forEach(modal => {
                    modal.classList.remove('sq-modal--active');
                    sqBody.style.overflow = '';
                });
            }
        });

        // Read-only on load; edit icon toggles full editable behavior.
        (function () {
            const editFab = document.getElementById('sqEditFab');
            if (!editFab) return;
            const managedActions = new Set(['update_profile', 'update_account', 'update_security', 'update_agreements', 'reset_password']);
            const managedForms = Array.from(document.querySelectorAll('form')).filter((f) => {
                const actionInput = f.querySelector('input[name="action"]');
                return actionInput && managedActions.has(actionInput.value || '');
            });

            const controls = managedForms.flatMap((form) =>
                Array.from(form.querySelectorAll('input, select, textarea, button[type="submit"]'))
            );
            const editOnlyControls = Array.from(document.querySelectorAll('.sq-requires-edit'));
            const modePill = document.getElementById('sqEditModePill');

            const applyMode = (editable) => {
                managedForms.forEach((form) => form.classList.toggle('sq-readonly-scope', !editable));
                controls.forEach((el) => {
                    if (!el.dataset.initialDisabled) {
                        el.dataset.initialDisabled = el.disabled ? '1' : '0';
                    }
                    const initiallyDisabled = el.dataset.initialDisabled === '1';
                    if (editable) {
                        el.disabled = initiallyDisabled;
                    } else {
                        if (!initiallyDisabled) el.disabled = true;
                    }
                });
                editOnlyControls.forEach((el) => {
                    el.disabled = !editable;
                });
                editFab.innerHTML = editable
                    ? '<i class="fas fa-lock-open"></i><span>Editing enabled</span>'
                    : '<i class="fas fa-pen"></i><span>Edit</span>';
                editFab.title = editable ? 'Editing enabled' : 'Enable edit mode';
                editFab.style.background = editable ? '#10b981' : '#3b82f6';

                if (modePill) {
                    modePill.classList.toggle('sq-edit-mode-pill--editable', editable);
                    modePill.innerHTML = editable
                        ? '<i class="fas fa-lock-open"></i><span>Edit mode enabled</span>'
                        : '<i class="fas fa-lock"></i><span>Read-only mode</span>';
                }
            };

            // Show agreements warning only when agreement fields are interacted with.
            const agreementsForm = managedForms.find((form) => {
                const actionInput = form.querySelector('input[name="action"]');
                return actionInput && actionInput.value === 'update_agreements';
            });
            const agreementsWarning = document.getElementById('sqAgreementsWarning');
            if (agreementsForm && agreementsWarning) {
                const agreementSelects = Array.from(
                    agreementsForm.querySelectorAll('select[name="privacy_agreed"], select[name="terms_agreed"], select[name="agreement_agreed"]')
                );
                const showAgreementsWarning = () => {
                    agreementsWarning.classList.remove('sq-inline-warning--hidden');
                };
                agreementSelects.forEach((select) => {
                    select.addEventListener('focus', showAgreementsWarning);
                    select.addEventListener('click', showAgreementsWarning);
                    select.addEventListener('change', showAgreementsWarning);
                });
            }

            applyMode(false);
            editFab.addEventListener('click', () => {
                const currentlyEditable = editFab.title === 'Editing enabled';
                applyMode(!currentlyEditable);
            });
        })();

        // Profile Image Upload Functionality
        let selectedFile = null;
        const sqUploadModal = document.getElementById('sqUploadModal');
        const sqDropzone = document.getElementById('sqDropzone');
        const sqFileInput = document.getElementById('sqFileInput');
        const sqPreviewContainer = document.getElementById('sqPreviewContainer');
        const sqPreviewImage = document.getElementById('sqPreviewImage');
        const sqPreviewName = document.getElementById('sqPreviewName');
        const sqSavePhotoBtn = document.getElementById('sqSavePhotoBtn');
        const sqDropzoneContent = document.getElementById('sqDropzoneContent');

        function sqOpenUploadModal() {
            sqUploadModal.classList.add('sq-modal--active');
            sqBody.style.overflow = 'hidden';
            resetUpload();
        }

        function sqCloseUploadModal() {
            sqUploadModal.classList.remove('sq-modal--active');
            sqBody.style.overflow = '';
            resetUpload();
        }

        function resetUpload() {
            selectedFile = null;
            sqFileInput.value = '';
            sqPreviewContainer.classList.remove('sq-preview-active');
            sqDropzoneContent.style.display = 'block';
            sqSavePhotoBtn.disabled = true;
        }

        // Click on dropzone to open file input
        sqDropzone.addEventListener('click', (e) => {
            if (e.target !== sqFileInput) {
                sqFileInput.click();
            }
        });

        // File input change
        sqFileInput.addEventListener('change', (e) => {
            if (e.target.files && e.target.files[0]) {
                handleFile(e.target.files[0]);
            }
        });

        // Drag and drop events
        sqDropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            sqDropzone.classList.add('sq-dragover');
        });

        sqDropzone.addEventListener('dragleave', (e) => {
            e.preventDefault();
            sqDropzone.classList.remove('sq-dragover');
        });

        sqDropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            sqDropzone.classList.remove('sq-dragover');

            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                const file = e.dataTransfer.files[0];
                if (file.type.startsWith('image/')) {
                    handleFile(file);
                } else {
                    showToast('Please select an image file (JPG, PNG, GIF)', 'error');
                }
            }
        });

        function handleFile(file) {
            // Validate file size (max 5MB)
            if (file.size > 5 * 1024 * 1024) {
                showToast('File size must be less than 5MB', 'error');
                return;
            }

            selectedFile = file;

            // Show preview
            const reader = new FileReader();
            reader.onload = (e) => {
                sqPreviewImage.src = e.target.result;
                sqPreviewName.textContent = file.name;
                sqPreviewContainer.classList.add('sq-preview-active');
                sqDropzoneContent.style.display = 'none';
                sqSavePhotoBtn.disabled = false;
            };
            reader.readAsDataURL(file);
        }

        function sqSavePhoto() {
            if (!selectedFile) return;

            // Create FormData
            const formData = new FormData();
            formData.append('action', 'update_profile_photo');
            formData.append('photo', selectedFile);
            formData.append('user_id', '<?php echo $userId; ?>');

            // Show loading state
            sqSavePhotoBtn.disabled = true;
            sqSavePhotoBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            // Send AJAX request
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update the avatar image
                        document.getElementById('sqCurrentAvatar').src = data.photoUrl;
                        sqCloseUploadModal();
                        showToast('Profile photo updated successfully', 'success');
                    } else {
                        showToast(data.message || 'Failed to upload photo', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('An error occurred while uploading the photo', 'error');
                })
                .finally(() => {
                    sqSavePhotoBtn.innerHTML = '<i class="fas fa-save"></i> Save Photo';
                });
        }

        // Close upload modal on outside click
        sqUploadModal.addEventListener('click', (e) => {
            if (e.target === sqUploadModal) {
                sqCloseUploadModal();
            }
        });
    </script>

    <button id="backToTopBtn" class="sq-back-to-top" title="Back to top" aria-label="Back to top" type="button">
        <i class="fas fa-arrow-up"></i>
    </button>
    <style>
        .sq-back-to-top{
            position: fixed;
            right: 24px;
            bottom: 86px; /* sits above the edit FAB */
            width: 44px;
            height: 44px;
            border-radius: 999px;
            border: none;
            background: var(--sq-success, #10b981);
            color: white;
            box-shadow: 0 10px 24px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
            z-index: 1100;
        }
        body.sq-dark .sq-back-to-top{
            background: #059669;
            box-shadow: 0 10px 24px rgba(0,0,0,0.4);
        }
        .sq-back-to-top.sq-back-to-top--visible{
            opacity: 1;
            pointer-events: auto;
            transform: translateY(-2px);
        }
    </style>
    <script>
        (function () {
            const backToTopBtn = document.getElementById('backToTopBtn');
            if (!backToTopBtn) return;
            const onScroll = function () {
                backToTopBtn.classList.toggle('sq-back-to-top--visible', window.scrollY > 400);
            };
            window.addEventListener('scroll', onScroll);
            onScroll();
            backToTopBtn.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        })();
    </script>
</body>

</html>