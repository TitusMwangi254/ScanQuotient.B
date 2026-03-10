<?php
require 'C:/Users/1/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header("Content-Type: application/json");

$host = '127.0.0.1';
$db = 'scanquotient.a1';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request.");
    }

    $email = $_POST['email'] ?? '';
    $code = $_POST['code'] ?? '';

    if (!$email || !$code) {
        throw new Exception("Missing data.");
    }

    if (strlen($code) !== 6 || !preg_match('/^\d{6}$/', $code)) {
        throw new Exception("Invalid code format.");
    }

    // FIXED: Use plain code (not hashed) since DB stores plain text
    $verificationCode = $code;

    $stmt = $pdo->prepare("
        SELECT id, user_id, surname, email, first_name
        FROM users
        WHERE LOWER(email) = LOWER(?)
        AND email_verification_token = ?
        AND email_verification_expires > NOW()
    ");

    $stmt->execute([$email, $verificationCode]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new Exception("Invalid or expired code.");
    }

    // Activate account
    $update = $pdo->prepare("
        UPDATE users
        SET email_verified = 'yes',
            email_verification_token = NULL,
            email_verification_expires = NULL
        WHERE id = ?
    ");

    $update->execute([$user['id']]);

    // Send welcome email with first-time login credentials
    sendWelcomeEmail($user);

    echo json_encode([
        "status" => "success",
        "message" => "Email verified successfully. Check your email for login credentials.",
        "redirect" => "../../../Login_page/PHP/Frontend/Login_page_site.php"
    ]);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}

/**
 * Send welcome email with first-time login credentials
 */
function sendWelcomeEmail($user)
{
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'scanquotient@gmail.com';
        $mail->Password = 'vnht iefe anwl xynb';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Recipients
        $mail->setFrom('scanquotient@gmail.com', 'ScanQuotient');
        $mail->addAddress($user['email'], $user['first_name'] . ' ' . $user['surname']);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Welcome to ScanQuotient - Your Account is Verified';

        $username = strtoupper($user['surname']); // Username is surname in uppercase
        $password = $user['user_id']; // Password is user_id

        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f8fafc; padding: 20px;'>
                <div style='background: linear-gradient(135deg, #2563eb, #7c3aed); padding: 30px; border-radius: 10px 10px 0 0; text-align: center;'>
                    <h1 style='color: white; margin: 0; font-size: 28px;'>Welcome to ScanQuotient!</h1>
                </div>
                
                <div style='background: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);'>
                    <p style='font-size: 16px; color: #1a202c;'>Hello <strong>" . htmlspecialchars($user['first_name']) . "</strong>,</p>
                    
                    <p style='color: #4a5568; line-height: 1.6;'>Your email has been successfully verified and your account is now active. You can now log in to access your security dashboard.</p>
                    
                    <div style='background: #f0f4f8; border-left: 4px solid #2563eb; padding: 20px; margin: 25px 0; border-radius: 5px;'>
                        <h3 style='margin-top: 0; color: #2563eb; font-size: 18px;'>🔐 Your First-Time Login Credentials</h3>
                        
                        <table style='width: 100%; border-collapse: collapse;'>
                            <tr>
                                <td style='padding: 10px 0; color: #64748b; font-weight: 600; width: 120px;'>Username:</td>
                                <td style='padding: 10px 0; color: #1a202c; font-family: monospace; font-size: 16px; font-weight: bold;'>
                                    " . htmlspecialchars($username) . "
                                </td>
                            </tr>
                            <tr>
                                <td style='padding: 10px 0; color: #64748b; font-weight: 600;'>Password:</td>
                                <td style='padding: 10px 0; color: #1a202c; font-family: monospace; font-size: 16px; font-weight: bold;'>
                                    " . htmlspecialchars($password) . "
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <div style='background: #fef3c7; border: 1px solid #f59e0b; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                        <p style='margin: 0; color: #92400e; font-size: 14px;'>
                            <strong>⚠️ Important Security Notice:</strong><br>
                            For your security, you will be required to change your password upon first login. 
                            Please choose a strong, unique password that you don't use elsewhere.
                        </p>
                    </div>
                    
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='https://yourdomain.com/ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Frontend/login_page_site.php' 
                           style='background: linear-gradient(135deg, #2563eb, #7c3aed); color: white; padding: 12px 30px; 
                                  text-decoration: none; border-radius: 8px; font-weight: 600; display: inline-block;'>
                            Login to Your Account
                        </a>
                    </div>
                    
                    <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 25px 0;'>
                    
                    <p style='color: #64748b; font-size: 14px; margin-bottom: 5px;'>
                        <strong>Need help?</strong> Contact our support team at 
                        <a href='mailto:scanquotient@gmail.com' style='color: #2563eb;'>scanquotient@gmail.com</a>
                    </p>
                    
                    <p style='color: #94a3b8; font-size: 12px; margin-top: 20px;'>
                        This is an automated message. Please do not reply to this email.<br>
                        If you did not create this account, please contact us immediately.
                    </p>
                </div>
                
                <div style='text-align: center; padding: 20px; color: #94a3b8; font-size: 12px;'>
                    <p>&copy; 2026 ScanQuotient. All rights reserved.</p>
                    <p style='margin: 5px 0;'>Quantifying Risk. Strengthening Security.</p>
                </div>
            </div>
        ";

   $mail->AltBody = "
Welcome to ScanQuotient!

Hello " . $user['first_name'] . ",

Your email has been successfully verified and your account is now active.

You can now log in to your ScanQuotient account using the credentials that were sent to you earlier.

For security reasons, you will be required to change your password during your first loginete your account setup.

Login here:
https://yourdomain.com/ScanQuotient/ScanQuotient/Publicpages/Login_Page/PHP/Frontend/login_page_site.php

If you did not receive your login credentials or need assistance, please contact our support team.

Support Email: scanquotient@gmail.com

Thank you for choosing ScanQuotient.
";

        $mail->send();

        // Optional: Log that welcome email was sent
        error_log("Welcome email sent to: " . $user['email'] . " with username: " . $username);

    } catch (Exception $e) {
        // Log error but don't fail the verification - user can still login
        error_log("Failed to send welcome email to " . $user['email'] . ": " . $mail->ErrorInfo);
    }
}
?>