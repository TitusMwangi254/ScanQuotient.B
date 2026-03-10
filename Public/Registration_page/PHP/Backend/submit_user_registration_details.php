<?php
date_default_timezone_set('Africa/Nairobi');
require 'C:/Users/1/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$host = '127.0.0.1';
$db = 'scanquotient.a1';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

$pdo = new PDO($dsn, $user, $pass, $options);

header("Content-Type: application/json");

/**
 * Generate structured User ID: UID + 7 alphanumeric characters
 * Format: UIDXXXXXXX (where X is random letter or number)
 */
function generateUserID()
{
    $prefix = 'UID';
    $length = 7;
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $max = strlen($characters) - 1;
    $randomPart = '';

    for ($i = 0; $i < $length; $i++) {
        $randomPart .= $characters[random_int(0, $max)];
    }

    return $prefix . $randomPart;
}

/**
 * Generate random password
 */
function generatePassword($length = 12)
{
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $max = strlen($characters) - 1;
    $password = '';

    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[random_int(0, $max)];
    }

    return $password;
}

function getProjectRoot()
{
    // Get the directory of the current script
    $currentDir = __DIR__;

    // Navigate up: Backend -> PHP -> Registration_page -> Public -> ScanQuotient.B
    // We need to go up 4 levels to reach ScanQuotient.B
    $projectRoot = realpath($currentDir . '/../../../../../../');

    // If that doesn't work, try alternative method
    if ($projectRoot === false || !is_dir($projectRoot . '/Storage')) {
        // Fallback: manually construct path
        $parts = explode(DIRECTORY_SEPARATOR, $currentDir);
        $searchIndex = array_search('ScanQuotient.B', $parts);

        if ($searchIndex !== false) {
            $projectRoot = implode(DIRECTORY_SEPARATOR, array_slice($parts, 0, $searchIndex + 1));
        }
    }

    return $projectRoot;
}

try {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        throw new Exception("Invalid request.");
    }

    $first_name = trim($_POST['first-name'] ?? '');
    $middle_name = trim($_POST['middle-name'] ?? null);
    $surname = trim($_POST['surname'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $recovery_email = trim($_POST['recovery-email'] ?? '');
    $security_question = trim($_POST['security-question'] ?? '');
    $security_answer = trim($_POST['security-answer'] ?? '');

    if (
        empty($first_name) ||
        empty($surname) ||
        empty($gender) ||
        empty($phone) ||
        empty($email) ||
        empty($recovery_email) ||
        empty($security_question) ||
        empty($security_answer)
    ) {
        throw new Exception("Invalid input.");
    }

    if (
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        !filter_var($recovery_email, FILTER_VALIDATE_EMAIL)
    ) {
        throw new Exception("Invalid input.");
    }

    if (!in_array($gender, ['male', 'female', 'other'])) {
        throw new Exception("Invalid input.");
    }

    $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $check->execute([$email]);

    if ($check->fetch()) {
        throw new Exception("Registration failed try again later.");
    }

    // Generate structured User ID (UID + 7 alphanumeric)
    $user_id = generateUserID();

    // Generate random password and hash it
    $generated_password = generatePassword(12);
    $password_hash = password_hash($generated_password, PASSWORD_DEFAULT);

    // Username is surname (lowercase for consistency)
    $user_name = strtolower($surname);

    // 6-digit verification code
    $email_verification_token = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $email_verification_expires = date("Y-m-d H:i:s", strtotime("+5 minutes"));

    // Hash security answer
    $hashed_security_answer = password_hash($security_answer, PASSWORD_DEFAULT);

    // PORTABLE PATH: Calculate paths based on project structure
    $projectRoot = getProjectRoot();

    if ($projectRoot === false || empty($projectRoot)) {
        throw new Exception("Unable to determine project root directory.");
    }

    // Absolute path for saving files
    $upload_dir = $projectRoot . DIRECTORY_SEPARATOR . 'Storage' . DIRECTORY_SEPARATOR . 'User_Profile_images' . DIRECTORY_SEPARATOR;

    // Relative path for database (always use forward slashes for web paths)
    $db_relative_path = 'Storage/User_Profile_images/';

    // Debug logging (remove in production)
    error_log("Project Root: " . $projectRoot);
    error_log("Upload Directory: " . $upload_dir);

    if (!empty($_FILES['passport-photo']['name'])) {
        $tmp = $_FILES['passport-photo']['tmp_name'];
        $size = $_FILES['passport-photo']['size'];

        if ($size > 5242880) {
            throw new Exception("File too large.");
        }

        $mime = mime_content_type($tmp);
        $allowed = [
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/gif' => '.gif',
            'image/webp' => '.webp'
        ];

        if (!isset($allowed[$mime])) {
            throw new Exception("Invalid file type.");
        }

        // Create safe filename using user_id
        $safe_user_id = preg_replace('/[^a-zA-Z0-9]/', '', $user_id);
        $new_filename = $safe_user_id . $allowed[$mime];

        // Ensure directory exists (create if not)
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                throw new Exception("Failed to create upload directory: " . $upload_dir);
            }
        }

        // Check if directory is writable
        if (!is_writable($upload_dir)) {
            throw new Exception("Upload directory is not writable: " . $upload_dir);
        }

        $destination = $upload_dir . $new_filename;

        if (!move_uploaded_file($tmp, $destination)) {
            $error = error_get_last();
            throw new Exception("Upload failed: " . ($error['message'] ?? 'Unknown error'));
        }

        chmod($destination, 0644);

        // Store relative path in DB
        $profile_photo_path = $db_relative_path . $new_filename;
    } else {
        $profile_photo_path = null;
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO users (
            user_id,
            first_name,
            middle_name,
            surname,
            gender,
            phone_number,
            email,
            profile_photo,
            recovery_email,
            security_question,
            security_answer,
            user_name,
            password_hash,
            role,
            account_active,
            email_verified,
            email_verification_token,
            email_verification_expires
        ) VALUES (
            :user_id,
            :first_name,
            :middle_name,
            :surname,
            :gender,
            :phone,
            :email,
            :profile_photo,
            :recovery_email,
            :security_question,
            :security_answer,
            :user_name,
            :password_hash,
            'user',
            'no',
            'no',
            :token,
            :expires
        )
    ");

    $stmt->execute([
        ':user_id' => $user_id,
        ':first_name' => $first_name,
        ':middle_name' => $middle_name,
        ':surname' => $surname,
        ':gender' => $gender,
        ':phone' => $phone,
        ':email' => $email,
        ':profile_photo' => $profile_photo_path,
        ':recovery_email' => $recovery_email,
        ':security_question' => $security_question,
        ':security_answer' => $hashed_security_answer,
        ':user_name' => $user_name,
        ':password_hash' => $password_hash,
        ':token' => $email_verification_token,
        ':expires' => $email_verification_expires
    ]);

    $pdo->commit();

    // Send beautifully styled email with credentials
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'scanquotient@gmail.com';
        $mail->Password = 'vnht iefe anwl xynb';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('scanquotient@gmail.com', 'ScanQuotient');
        $mail->addAddress($email, $first_name);

        $mail->isHTML(true);
        $mail->Subject = 'Welcome to ScanQuotient - Your Login Credentials';

        // Styled HTML email template
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
                                    <h1 style="color: #ffffff; margin: 0; font-size: 28px; font-weight: bold;">Welcome to ScanQuotient!</h1>
                                </td>
                            </tr>
                            
                            <!-- Content -->
                            <tr>
                                <td style="padding: 40px 30px;">
                                    <p style="color: #333333; font-size: 16px; line-height: 1.6; margin: 0 0 20px 0;">Hello <strong>' . htmlspecialchars($first_name) . '</strong>,</p>
                                    
                                    <p style="color: #555555; font-size: 16px; line-height: 1.6; margin: 0 0 30px 0;">Your account has been created successfully. Here are your login credentials:</p>
                                    
                                    <!-- Credentials Box -->
                                    <table role="presentation" style="width: 100%; background-color: #f8f9fa; border-radius: 6px; margin: 20px 0;">
                                        <tr>
                                            <td style="padding: 25px;">
                                                <table role="presentation" style="width: 100%;">
                                                    <tr>
                                                        <td style="padding: 10px 0; border-bottom: 1px solid #e9ecef;">
                                                            <span style="color: #6c757d; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;">Username</span>
                                                            <p style="margin: 5px 0 0 0; font-size: 18px; color: #333333; font-weight: bold;">' . htmlspecialchars($surname) . '</p>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td style="padding: 10px 0;">
                                                            <span style="color: #6c757d; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;">Password</span>
                                                            <p style="margin: 5px 0 0 0; font-size: 18px; color: #333333; font-weight: bold; font-family: monospace; background-color: #e9ecef; padding: 8px; border-radius: 4px; display: inline-block;">' . htmlspecialchars($generated_password) . '</p>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                    
                                    <!-- Verification Section -->
                                    <div style="text-align: center; margin: 30px 0; padding: 25px; background-color: #fff3cd; border-radius: 6px; border: 1px solid #ffeaa7;">
                                        <p style="color: #856404; margin: 0 0 15px 0; font-size: 14px; font-weight: bold;">EMAIL VERIFICATION CODE</p>
                                        <p style="color: #856404; margin: 0 0 15px 0; font-size: 14px;">Enter this code to verify your email address</p>
                                        <div style="background-color: #ffffff; padding: 15px 30px; border-radius: 6px; display: inline-block; border: 2px dashed #ffc107;">
                                            <span style="font-size: 32px; font-weight: bold; color: #333333; letter-spacing: 8px; font-family: monospace;">' . $email_verification_token . '</span>
                                        </div>
                                        <p style="color: #856404; margin: 15px 0 0 0; font-size: 13px;">⏰ This code expires in 5 minutes</p>
                                    </div>
                                    
                                    <!-- Security Note -->
                                    <div style="background-color: #d1ecf1; border-left: 4px solid #17a2b8; padding: 15px; margin: 20px 0; border-radius: 0 4px 4px 0;">
                                        <p style="color: #0c5460; margin: 0; font-size: 14px; line-height: 1.5;">
                                            <strong>🔒 Security Tip:</strong> Please change your password after your first login for enhanced security.
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
        $mail->AltBody = "Welcome to ScanQuotient!\n\nHello $first_name,\n\nYour account has been created successfully.\n\nUsername: $surname\nPassword: $generated_password\n\nYour verification code is: $email_verification_token\nThis code expires in 5 minutes.\n\nPlease change your password after your first login.";

        $mail->send();

    } catch (Exception $e) {
        error_log("Email error: " . $mail->ErrorInfo);
    }

    echo json_encode([
        "status" => "success",
        "redirect" => "../Frontend/Email_verification.php?email=" . urlencode($email)
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());

    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage(),
        "back" => "../Frontend/Registration_page.php"
    ]);
}
?>