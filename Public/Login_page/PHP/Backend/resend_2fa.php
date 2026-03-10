<?php
// resend_2fa.php - Resend 2FA code
session_start();
header('Content-Type: application/json');

require_once 'C:/Users/1/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Verify 2FA session
if (!isset($_SESSION['auth_mode']) || $_SESSION['auth_mode'] !== '2fa_verification') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid session']);
    exit();
}

// Generate new code
$newCode = sprintf('%06d', mt_rand(0, 999999));
$newExpiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

// Update session
$_SESSION['2fa_code'] = $newCode;
$_SESSION['2fa_expires'] = $newExpiry;

// Send email
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
    $mail->addAddress($_SESSION['2fa_email']);

    $mail->isHTML(true);
    $mail->Subject = 'New 2FA Code - ScanQuotient';
    $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #2563eb;'>New Verification Code</h2>
            <p>Your new two-factor authentication code is:</p>
            <div style='background: #f0f4f8; padding: 20px; text-align: center; font-size: 32px; 
                        font-weight: bold; letter-spacing: 5px; border-radius: 10px; margin: 20px 0;'>
                {$newCode}
            </div>
            <p>This code expires in 10 minutes.</p>
        </div>
    ";

    $mail->send();

    echo json_encode(['status' => 'success', 'message' => 'New code sent']);

} catch (Exception $e) {
    error_log("Failed to resend 2FA: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to send email']);
}
?>