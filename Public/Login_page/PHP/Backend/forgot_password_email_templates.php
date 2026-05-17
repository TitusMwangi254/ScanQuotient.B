<?php

/**
 * HTML + plain-text templates for forgot-password verification codes (initial send & resend).
 */
function sq_build_forgot_password_code_email_html(
    string $firstName,
    string $code,
    string $deliveryLabel = 'your email',
    int $expiryMinutes = 15
): string {
    $safeName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $safeDelivery = htmlspecialchars($deliveryLabel, ENT_QUOTES, 'UTF-8');
    $year = date('Y');

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password reset code</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; background-color: #f4f4f4;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 24px 12px;">
                <table role="presentation" style="width: 600px; max-width: 100%; border-collapse: collapse; background-color: #ffffff; border-radius: 10px; box-shadow: 0 4px 14px rgba(0,0,0,0.08); overflow: hidden;">
                    <tr>
                        <td style="padding: 36px 32px; text-align: center; background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%);">
                            <p style="margin: 0 0 8px 0; font-size: 13px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: rgba(255,255,255,0.85);">ScanQuotient Security</p>
                            <h1 style="color: #ffffff; margin: 0; font-size: 26px; font-weight: bold;">Password reset</h1>
                            <p style="color: rgba(255,255,255,0.92); margin: 12px 0 0; font-size: 15px; line-height: 1.5;">Use the code below to continue resetting your password</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 36px 32px;">
                            <p style="color: #1e293b; font-size: 16px; line-height: 1.6; margin: 0 0 16px 0;">Hello <strong>{$safeName}</strong>,</p>
                            <p style="color: #475569; font-size: 15px; line-height: 1.65; margin: 0 0 24px 0;">
                                We received a request to reset your ScanQuotient password. Enter this verification code on the
                                <strong>Forgot password</strong> page (code sent to {$safeDelivery}):
                            </p>
                            <div style="text-align: center; margin: 28px 0; padding: 24px 20px; background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%); border-radius: 10px; border: 1px solid #e2e8f0;">
                                <p style="color: #64748b; margin: 0 0 14px 0; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em;">Your reset code</p>
                                <div style="background-color: #ffffff; padding: 16px 32px; border-radius: 8px; display: inline-block; border: 2px dashed #8b5cf6; box-shadow: 0 2px 8px rgba(124,58,237,0.12);">
                                    <span style="font-size: 34px; font-weight: bold; color: #1e293b; letter-spacing: 10px; font-family: 'Courier New', Courier, monospace;">{$safeCode}</span>
                                </div>
                                <p style="color: #b45309; margin: 16px 0 0 0; font-size: 13px; font-weight: 600;">
                                    <span style="display: inline-block; padding: 4px 10px; background: #fffbeb; border-radius: 999px; border: 1px solid #fde68a;">Expires in {$expiryMinutes} minutes</span>
                                </p>
                            </div>
                            <table role="presentation" style="width: 100%; margin: 28px 0 0 0; border-collapse: collapse;">
                                <tr>
                                    <td style="padding: 14px 16px; background-color: #eff6ff; border-radius: 8px; border-left: 4px solid #2563eb;">
                                        <p style="margin: 0; color: #1e40af; font-size: 13px; line-height: 1.55;">
                                            <strong>Did not request this?</strong> Ignore this email. Your password will stay the same unless you complete the reset with this code.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 32px; text-align: center; background-color: #f8fafc; border-top: 1px solid #e2e8f0;">
                            <p style="color: #94a3b8; font-size: 12px; margin: 0 0 6px 0;">Quantifying risk. Strengthening security.</p>
                            <p style="color: #94a3b8; font-size: 12px; margin: 0;">&copy; {$year} ScanQuotient. All rights reserved.</p>
                            <p style="color: #cbd5e1; font-size: 11px; margin: 10px 0 0 0;">This is an automated message — please do not reply.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}

function sq_build_forgot_password_code_email_alt(
    string $firstName,
    string $code,
    string $deliveryLabel = 'your email',
    int $expiryMinutes = 15
): string {
    return "ScanQuotient — Password reset\n\n"
        . "Hello {$firstName},\n\n"
        . "Your password reset code (sent to {$deliveryLabel}):\n\n"
        . "  {$code}\n\n"
        . "This code expires in {$expiryMinutes} minutes.\n\n"
        . "Enter it on the Forgot password page to continue.\n\n"
        . "If you did not request a password reset, ignore this email.\n\n"
        . "— ScanQuotient Security";
}
