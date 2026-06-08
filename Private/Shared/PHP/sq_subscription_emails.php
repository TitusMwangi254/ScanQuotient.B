<?php
/**
 * Subscription lifecycle emails (cancellation, reactivation).
 */
require_once 'C:/Users/1/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sq_send_subscription_email(string $toEmail, string $subject, string $htmlBody): bool
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
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Subscription email error: ' . $e->getMessage());
        return false;
    }
}

function sq_plan_display_name(string $package): string
{
    $map = [
        'pro' => 'Pro',
        'enterprise' => 'Enterprise Suite',
        'freemium' => 'Freemium',
    ];
    return $map[strtolower($package)] ?? ucfirst($package);
}

function sq_send_cancellation_emails(
    string $userEmail,
    string $package,
    ?string $expiresAt,
    string $reason
): void {
    $planName = sq_plan_display_name($package);
    $expiryText = $expiresAt ? date('F j, Y', strtotime($expiresAt)) : 'the end of your billing period';
    $reasonEsc = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');

    $userBody = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #dc2626;'>Subscription Cancelled</h2>
            <p>Hello,</p>
            <p>Your <strong>{$planName}</strong> subscription has been cancelled as requested.</p>
            <div style='background: #fef2f2; padding: 20px; border-radius: 10px; margin: 20px 0; border-left: 4px solid #dc2626;'>
                <p><strong>Plan:</strong> {$planName}</p>
                <p><strong>Access until:</strong> {$expiryText}</p>
                <p><strong>Reason:</strong> {$reasonEsc}</p>
            </div>
            <p>You will keep full access to your {$planName} features until <strong>{$expiryText}</strong>. After that date, your account will move to the Freemium plan.</p>
            <p>You can resubscribe at any time from your subscription page before or after your access ends.</p>
            <p style='color: #666; font-size: 12px; margin-top: 30px;'>
                Questions? Contact us at scanquotient@gmail.com
            </p>
        </div>
    ";

    $adminBody = "
        <h2>Subscription Cancellation</h2>
        <p>A user has cancelled their subscription.</p>
        <div style='background: #f0f4f8; padding: 15px; border-radius: 8px;'>
            <p><strong>Customer:</strong> {$userEmail}</p>
            <p><strong>Plan:</strong> {$planName}</p>
            <p><strong>Access until:</strong> {$expiryText}</p>
            <p><strong>Reason:</strong> {$reasonEsc}</p>
        </div>
    ";

    sq_send_subscription_email($userEmail, 'Subscription Cancelled - ScanQuotient ' . $planName, $userBody);
    sq_send_subscription_email('scanquotient@gmail.com', 'Subscription Cancelled - ' . $planName, $adminBody);
}

function sq_send_reactivation_emails(
    string $userEmail,
    string $package,
    ?string $expiresAt
): void {
    $planName = sq_plan_display_name($package);
    $expiryText = $expiresAt ? date('F j, Y', strtotime($expiresAt)) : 'N/A';

    $userBody = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #10b981;'>Subscription Reactivated</h2>
            <p>Hello,</p>
            <p>Great news! Your <strong>{$planName}</strong> subscription has been reactivated.</p>
            <div style='background: #ecfdf5; padding: 20px; border-radius: 10px; margin: 20px 0; border-left: 4px solid #10b981;'>
                <p><strong>Plan:</strong> {$planName}</p>
                <p><strong>Active until:</strong> {$expiryText}</p>
            </div>
            <p>Your premium features are fully restored. Thank you for staying with ScanQuotient!</p>
            <p style='color: #666; font-size: 12px; margin-top: 30px;'>
                Questions? Contact us at scanquotient@gmail.com
            </p>
        </div>
    ";

    $adminBody = "
        <h2>Subscription Reactivated</h2>
        <p>A user has reactivated their cancelled subscription.</p>
        <div style='background: #f0f4f8; padding: 15px; border-radius: 8px;'>
            <p><strong>Customer:</strong> {$userEmail}</p>
            <p><strong>Plan:</strong> {$planName}</p>
            <p><strong>Active until:</strong> {$expiryText}</p>
        </div>
    ";

    sq_send_subscription_email($userEmail, 'Subscription Reactivated - ScanQuotient ' . $planName, $userBody);
    sq_send_subscription_email('scanquotient@gmail.com', 'Subscription Reactivated - ' . $planName, $adminBody);
}
