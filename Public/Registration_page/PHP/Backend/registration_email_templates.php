<?php

function sq_build_verification_code_email_html(string $firstName, string $code, string $headline = 'Email verification code'): string
{
    $safeName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $year = date('Y');

    return <<<HTML
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
                <table role="presentation" style="width: 600px; max-width: 100%; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="padding: 40px 30px; text-align: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px 8px 0 0;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 26px; font-weight: bold;">ScanQuotient</h1>
                            <p style="color: rgba(255,255,255,0.9); margin: 10px 0 0; font-size: 14px;">{$headline}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 36px 30px;">
                            <p style="color: #333333; font-size: 16px; line-height: 1.6; margin: 0 0 20px 0;">Hello <strong>{$safeName}</strong>,</p>
                            <p style="color: #555555; font-size: 16px; line-height: 1.6; margin: 0 0 24px 0;">Enter this code on the verification page to confirm your email address:</p>
                            <div style="text-align: center; margin: 24px 0; padding: 22px; background-color: #fff3cd; border-radius: 8px; border: 1px solid #ffeaa7;">
                                <p style="color: #856404; margin: 0 0 12px 0; font-size: 13px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px;">Your verification code</p>
                                <div style="background-color: #ffffff; padding: 14px 28px; border-radius: 6px; display: inline-block; border: 2px dashed #ffc107;">
                                    <span style="font-size: 32px; font-weight: bold; color: #333333; letter-spacing: 8px; font-family: monospace;">{$safeCode}</span>
                                </div>
                                <p style="color: #856404; margin: 14px 0 0 0; font-size: 13px;">This code expires in 5 minutes.</p>
                            </div>
                            <p style="color: #6c757d; font-size: 13px; line-height: 1.5; margin: 0;">If you did not request this code, you can safely ignore this email.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px 30px; text-align: center; background-color: #f8f9fa; border-radius: 0 0 8px 8px;">
                            <p style="color: #6c757d; font-size: 12px; margin: 0;">&copy; {$year} ScanQuotient. All rights reserved.</p>
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

function sq_build_welcome_verified_email_html(string $firstName, string $username, string $password): string
{
    $safeName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
    $safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $safePass = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
    $year = date('Y');

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f4f4f4;">
<table role="presentation" style="width:100%;border-collapse:collapse;">
<tr><td align="center" style="padding:20px 0;">
<table role="presentation" style="width:600px;max-width:100%;border-collapse:collapse;background:#fff;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);">
<tr>
<td style="padding:36px 30px;text-align:center;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border-radius:8px 8px 0 0;">
<h1 style="color:#fff;margin:0;font-size:26px;">Account verified</h1>
</td>
</tr>
<tr>
<td style="padding:32px 30px;">
<p style="color:#333;font-size:16px;">Hello <strong>{$safeName}</strong>,</p>
<p style="color:#555;line-height:1.6;">Your email is verified. Use these credentials to sign in and complete your account setup:</p>
<table role="presentation" style="width:100%;background:#f8f9fa;border-radius:6px;margin:20px 0;">
<tr><td style="padding:20px;">
<p style="margin:0 0 8px;color:#6c757d;font-size:12px;text-transform:uppercase;">Username</p>
<p style="margin:0 0 16px;font-size:18px;font-weight:bold;font-family:monospace;">{$safeUser}</p>
<p style="margin:0 0 8px;color:#6c757d;font-size:12px;text-transform:uppercase;">Password</p>
<p style="margin:0;font-size:18px;font-weight:bold;font-family:monospace;background:#e9ecef;padding:8px;border-radius:4px;display:inline-block;">{$safePass}</p>
</td></tr>
</table>
<p style="color:#856404;font-size:14px;background:#fff3cd;padding:12px;border-radius:6px;border:1px solid #ffeaa7;">You will choose a permanent username and password during account setup after signing in.</p>
</td>
</tr>
<tr>
<td style="padding:16px 30px;text-align:center;background:#f8f9fa;border-radius:0 0 8px 8px;">
<p style="color:#6c757d;font-size:12px;margin:0;">&copy; {$year} ScanQuotient</p>
</td>
</tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}
