<?php
// login_handler.php - Secure Login Handler with Unified Session Management
session_start();
date_default_timezone_set('Africa/Nairobi');
require_once 'C:/Users/1/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Database configuration
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Security: Prevent direct access
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../PHP/Frontend/Login_page_site.php');
    exit;
}

// Get and sanitize input
$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');

// Validate input presence
if (empty($username) || empty($password)) {
    $_SESSION['loginError'] = 'Please enter both username and password.';
    header('Location: ../../PHP/Frontend/Login_page_site.php');
    exit;
}

try {
    // Database connection
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    // Fetch user by username (ambiguous error if not found)
    $stmt = $pdo->prepare("
        SELECT * FROM users 
        WHERE user_name = :username 
        LIMIT 1
    ");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    // SECURITY: Always perform password verify to prevent timing attacks
    $passwordValid = false;
    if ($user) {
        $passwordValid = password_verify($password, $user['password_hash']);
    } else {
        password_verify($password, '$2y$10$dummyhashforconstantimetimingattackprevention');
    }

    // AMBIGUOUS ERROR: Don't reveal if username or password is wrong
    if (!$user || !$passwordValid) {
        logSecurityEvent($pdo, $username, 'FAILED_LOGIN', 'Invalid credentials provided');
        $_SESSION['loginError'] = 'Invalid username or password. Please try again.';
        header('Location: ../../PHP/Frontend/Login_page_site.php');
        exit;
    }

    // --- USER FOUND & PASSWORD CORRECT - BEGIN SECURITY CHECKS ---

    // UNIFIED: Always set user_id in session for all subsequent checks
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = $user['user_name'];

    // CHECK 1: Account Deletion Status (Soft Delete)
    if ($user['deleted_at'] !== null) {
        logSecurityEvent($pdo, $username, 'DELETED_ACCOUNT_ACCESS', 'Attempted login to deleted account');
        session_destroy(); // Clear session for deleted user
        $_SESSION['loginError'] = 'This account has been deactivated. Please contact support.';
        header('Location: ../../PHP/Frontend/Login_page_site.php');
        exit;
    }

    // CHECK 2: Email Verification (MOVED UP - before password reset checks)
    if ($user['email_verified'] !== 'yes') {
        $_SESSION['auth_mode'] = 'email_verification';
        $_SESSION['resend_count'] = $user['verification_resend_count'] ?? 0;

        // Generate new 6-digit OTP if needed (expires in 5 minutes)
        if (
            empty($user['email_verification_token']) ||
            ($user['email_verification_expires'] !== null && strtotime($user['email_verification_expires']) < time())
        ) {

            // Generate 6-digit numeric OTP (same as registration)
            $newToken = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $newExpiry = date('Y-m-d H:i:s', strtotime('+5 minutes'));

            $updateStmt = $pdo->prepare("
                UPDATE users 
                SET email_verification_token = :token,
                    email_verification_expires = :expiry
                WHERE user_id = :user_id
            ");
            $updateStmt->execute([
                ':token' => $newToken,
                ':expiry' => $newExpiry,
                ':user_id' => $user['user_id']
            ]);

            $_SESSION['verification_token'] = $newToken;

            // Send email verification OTP
            $emailSent = sendEmailVerificationOTP($user['email'], $newToken, $user['first_name'] ?? 'User');

            if (!$emailSent) {
                error_log("Failed to send email verification OTP to: " . $user['email']);
            }
        } else {
            $_SESSION['verification_token'] = $user['email_verification_token'];
        }

        logSecurityEvent($pdo, $username, 'EMAIL_VERIFICATION_PENDING', 'Redirecting to email verification');
        header('Location: ../../../Registration_page/PHP/Frontend/Email_verification.php?email=' . urlencode($user['email']));
        exit;
    }


    // CHECK 3: Account Active Status
    if ($user['account_active'] !== 'yes') {
        // Set completion mode flag
        $_SESSION['auth_mode'] = 'account_completion';
        $_SESSION['auth_stage'] = 'pending_completion';

        logSecurityEvent($pdo, $username, 'INCOMPLETE_ACCOUNT', 'Redirecting to account completion');
        header('Location: /ScanQuotient/ScanQuotient/Publicpages/Registration_completion_page/PHP/Frontend/registration_completion_site.php');
        exit;
    }


    // CHECK 4: Password Reset Status
    if ($user['password_reset_status'] === 'yes') {
        $_SESSION['auth_mode'] = 'password_reset';

        // Check if reset has expired
        if ($user['password_reset_expires'] !== null && strtotime($user['password_reset_expires']) < time()) {
            $_SESSION['force_reset_reason'] = 'expired';
            logSecurityEvent($pdo, $username, 'PASSWORD_RESET_EXPIRED', 'Forced password reset - expired token');
            header('Location: /ScanQuotient/ScanQuotient/Publicpages/Password_reset/PHP/Frontend/password_reset_page4.php?reason=expired');
            exit;
        }

        // Active reset required
        $_SESSION['force_reset_reason'] = 'required';
        logSecurityEvent($pdo, $username, 'PASSWORD_RESET_REQUIRED', 'Mandatory password reset pending');
        header('Location: /ScanQuotient/ScanQuotient/Publicpages/Password_reset/PHP/Frontend/password_reset_page4.php?reason=required');
        exit;
    }

    // CHECK 5: Password Expiry
    if ($user['password_expiry'] !== null && strtotime($user['password_expiry']) < time()) {
        $_SESSION['auth_mode'] = 'password_reset';
        $_SESSION['force_reset_reason'] = 'password_expired';
        logSecurityEvent($pdo, $username, 'PASSWORD_EXPIRED', 'Password expired - forced reset');
        header('Location: /ScanQuotient/ScanQuotient/Publicpages/Password_reset/PHP/Frontend/password_reset_page4.php?reason=password_expired');
        exit;
    }

    // CHECK 6: Two-Factor Authentication
    if ($user['two_factor_enabled'] === 'yes') {
        $_SESSION['auth_mode'] = '2fa_verification';
        $_SESSION['2fa_pending'] = true;
        $_SESSION['2fa_user_id'] = $user['user_id'];
        $_SESSION['2fa_email'] = $user['email'];
        $_SESSION['2fa_username'] = $user['user_name'];

        // Generate 2FA code (6 digits)
        $twoFactorCode = sprintf('%06d', mt_rand(0, 999999));
        $twoFactorExpiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        $_SESSION['2fa_code'] = $twoFactorCode;
        $_SESSION['2fa_expires'] = $twoFactorExpiry;

        // Store user role for post-2FA redirect
        $_SESSION['2fa_role'] = $user['role'];

        // Send 2FA code via email
        $emailSent = send2FACode($user['email'], $twoFactorCode, $user['first_name'] ?? 'User');

        if (!$emailSent) {
            // Log but don't fail - user can request resend
            error_log("Failed to send 2FA email to: " . $user['email']);
        }

        logSecurityEvent($pdo, $username, '2FA_INITIATED', 'Two-factor authentication code sent to: ' . $user['email']);

        header('Location: /ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Frontend/Login_OTP_verification.php');
        exit;
    }

    // --- ALL CHECKS PASSED - LOGIN SUCCESSFUL ---

    // Clear auth mode flags
    unset($_SESSION['auth_mode']);
    unset($_SESSION['auth_stage']);
    unset($_SESSION['force_reset_reason']);
    unset($_SESSION['2fa_pending']);

    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);

    // Set full session variables
    $_SESSION['authenticated'] = true;
    $_SESSION['login_time'] = time();
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['user_name'];
    $_SESSION['profile_photo'] = $user['profile_photo'];

    // Update last activity
    $updateStmt = $pdo->prepare("UPDATE users SET updated_at = NOW() WHERE user_id = :user_id");
    $updateStmt->execute([':user_id' => $user['user_id']]);

    logSecurityEvent($pdo, $username, 'LOGIN_SUCCESS', 'User logged in successfully');

    // Route based on role
    switch ($user['role']) {
        case 'admin':
        case 'super_admin':
            header('Location: /ScanQuotient/ScanQuotient/Privatepages/Admin_dashboard/PHP/Frontend/admin_main_dashboard.php');
            break;
        case 'user':
        default:
            header('Location: /ScanQuotient/ScanQuotient/Privatepages/Business_owner_portal/PHP/user_dashboard.php');
            break;
    }
    exit;

} catch (PDOException $e) {
    error_log("Database error in login_handler: " . $e->getMessage());
    $_SESSION['loginError'] = 'System error. Please try again later.';
    header('Location: /ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Frontend/login_page_site.php');
    exit;
} catch (Exception $e) {
    error_log("General error in login_handler: " . $e->getMessage());
    $_SESSION['loginError'] = 'An unexpected error occurred. Please try again.';
    header('Location: /ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Frontend/login_page_site.php');
    exit;
}

// --- HELPER FUNCTIONS ---

function logSecurityEvent($pdo, $username, $eventType, $description)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS security_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(255),
                event_type VARCHAR(100) NOT NULL,
                description TEXT,
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event_type (event_type),
                INDEX idx_created_at (created_at)
            )
        ");

        $stmt = $pdo->prepare("
            INSERT INTO security_logs (username, event_type, description, ip_address, user_agent)
            VALUES (:username, :event_type, :description, :ip_address, :user_agent)
        ");

        $stmt->execute([
            ':username' => $username,
            ':event_type' => $eventType,
            ':description' => $description,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        error_log("Failed to log security event: " . $e->getMessage());
    }
}

/**
 * Send Email Verification OTP (6-digit code) - Same style as registration
 */
function sendEmailVerificationOTP($toEmail, $code, $firstName = 'User')
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

        $mail->setFrom('scanquotient@gmail.com', 'ScanQuotient');
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Email Verification - ScanQuotient';

        // Styled HTML email template (same style as registration)
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; background-color: #f4f4f4;">
            <table role="presentation" style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td align="center" style="padding: 20px 0;">
                        <table role="presentation" style="width: 600px; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <!-- Header -->
                            <tr>
                                <td style="padding: 40px 30px; text-align: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px 8px 0 0;">
                                    <h1 style="color: #ffffff; margin: 0; font-size: 28px; font-weight: bold;">Verify Your Email</h1>
                                </td>
                            </tr>
                            
                            <!-- Content -->
                            <tr>
                                <td style="padding: 40px 30px;">
                                    <p style="color: #333333; font-size: 16px; line-height: 1.6; margin: 0 0 20px 0;">Hello <strong>' . htmlspecialchars($firstName) . '</strong>,</p>
                                    
                                    <p style="color: #555555; font-size: 16px; line-height: 1.6; margin: 0 0 30px 0;">You requested a new verification code. Please use the code below to verify your email address:</p>
                                    
                                    <!-- Verification Code Box -->
                                    <div style="text-align: center; margin: 30px 0; padding: 25px; background-color: #fff3cd; border-radius: 6px; border: 1px solid #ffeaa7;">
                                        <p style="color: #856404; margin: 0 0 15px 0; font-size: 14px; font-weight: bold;">EMAIL VERIFICATION CODE</p>
                                        <p style="color: #856404; margin: 0 0 15px 0; font-size: 14px;">Enter this code to verify your email address</p>
                                        <div style="background-color: #ffffff; padding: 15px 30px; border-radius: 6px; display: inline-block; border: 2px dashed #ffc107;">
                                            <span style="font-size: 32px; font-weight: bold; color: #333333; letter-spacing: 8px; font-family: monospace;">' . $code . '</span>
                                        </div>
                                        <p style="color: #856404; margin: 15px 0 0 0; font-size: 13px;">⏰ This code expires in 5 minutes</p>
                                    </div>
                                    
                                    <!-- Security Note -->
                                    <div style="background-color: #d1ecf1; border-left: 4px solid #17a2b8; padding: 15px; margin: 20px 0; border-radius: 0 4px 4px 0;">
                                        <p style="color: #0c5460; margin: 0; font-size: 14px; line-height: 1.5;">
                                            <strong>🔒 Security Tip:</strong> If you didn\'t request this code, please ignore this email or contact support.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                            
                            <!-- Footer -->
                            <tr>
                                <td style="padding: 20px 30px; text-align: center; background-color: #f8f9fa; border-radius: 0 0 8px 8px;">
                                    <p style="color: #6c757d; font-size: 12px; margin: 0;">This is an automated message from ScanQuotient. Please do not reply to this email.</p>
                                    <p style="color: #adb5bd; font-size: 11px; margin: 10px 0 0 0;">© ' . date('Y') . ' ScanQuotient. All rights reserved.</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>';

        // Plain text alternative
        $mail->AltBody = "Verify Your Email - ScanQuotient\n\nHello {$firstName},\n\nYour verification code is: {$code}\n\nThis code expires in 5 minutes.\n\nIf you didn't request this code, please ignore this email.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Failed to send email verification OTP: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send 2FA code via email
 */
function send2FACode($toEmail, $code, $firstName = 'User')
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
        $mail->Subject = 'Your Two-Factor Authentication Code - ScanQuotient';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f8fafc; padding: 20px;'>
                <div style='background: linear-gradient(135deg, #2563eb, #7c3aed); padding: 30px; border-radius: 10px 10px 0 0; text-align: center;'>
                    <h1 style='color: white; margin: 0; font-size: 24px;'>ScanQuotient Security</h1>
                </div>
                
                <div style='background: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);'>
                    <p style='font-size: 16px; color: #1a202c;'>Hello <strong>" . htmlspecialchars($firstName) . "</strong>,</p>
                    
                    <p style='color: #4a5568; line-height: 1.6;'>Your two-factor authentication code is:</p>
                    
                    <div style='background: #f0f4f8; padding: 25px; text-align: center; border-radius: 10px; margin: 25px 0; border-left: 4px solid #2563eb;'>
                        <div style='font-size: 36px; font-weight: bold; color: #2563eb; letter-spacing: 8px; font-family: monospace;'>
                            {$code}
                        </div>
                    </div>
                    
                    <p style='color: #4a5568;'>This code expires in <strong>10 minutes</strong>.</p>
                    
                    <div style='background: #fef3c7; border: 1px solid #f59e0b; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                        <p style='margin: 0; color: #92400e; font-size: 14px;'>
                            <strong>🔒 Security Notice:</strong><br>
                            If you didn't request this code, someone may be trying to access your account. 
                            Please secure your account immediately by changing your password.
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
ScanQuotient Security

Hello {$firstName},

Your two-factor authentication code is: {$code}

This code expires in 10 minutes.

If you didn't request this code, please secure your account immediately.

---
This is an automated message. Do not reply.
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Failed to send 2FA code: " . $mail->ErrorInfo);
        return false;
    }
}

?>