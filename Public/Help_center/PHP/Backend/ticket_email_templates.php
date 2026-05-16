<?php

function sq_ticket_email_base_url(): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (($_SERVER['SERVER_PORT'] ?? '') == 443))
        ? 'https://'
        : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $protocol . $host . '/ScanQuotient.v2/ScanQuotient.B';
}

function sq_ticket_email_priority_color(string $priority): string
{
    return match (strtolower(trim($priority))) {
        'high' => '#dc2626',
        'medium' => '#d97706',
        'low' => '#059669',
        default => '#3b82f6',
    };
}

/**
 * @param list<string> $attachmentNames
 */
function sq_ticket_email_normalize_attachment_names(array $attachmentNames): array
{
    return array_values(array_filter(array_map(static fn($n) => trim((string) $n), $attachmentNames)));
}

/**
 * @param list<string> $attachmentNames
 */
function sq_ticket_email_attachment_names_plain(array $attachmentNames): string
{
    $names = sq_ticket_email_normalize_attachment_names($attachmentNames);

    return $names === [] ? '' : implode(', ', $names);
}

/**
 * @param list<string> $attachmentNames
 */
function sq_ticket_email_attachments_user_html(array $attachmentNames): string
{
    $names = sq_ticket_email_normalize_attachment_names($attachmentNames);
    if ($names === []) {
        return '';
    }

    $items = '';
    foreach ($names as $name) {
        $safe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $items .= '<li style="margin:0 0 6px;font-size:14px;color:#334155;line-height:1.45;">' . $safe . '</li>';
    }

    return <<<HTML
<tr>
<td style="padding:16px 18px;border-top:1px solid #e2e8f0;">
<p style="margin:0 0 8px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;">Attachments</p>
<ul style="margin:0;padding-left:18px;">{$items}</ul>
<p style="margin:10px 0 0;font-size:12px;line-height:1.5;color:#64748b;">These files are saved on your ticket and can be viewed on the tracking page.</p>
</td>
</tr>
HTML;
}

/**
 * @param list<string> $attachmentNames
 */
function sq_ticket_email_attachments_admin_html(array $attachmentNames, bool $filesAttachedToEmail = true): string
{
    $names = sq_ticket_email_normalize_attachment_names($attachmentNames);
    if ($names === []) {
        return '';
    }

    $items = '';
    foreach ($names as $name) {
        $safe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $items .= '<li style="margin:0 0 4px;color:#0f172a;">' . $safe . '</li>';
    }

    $note = $filesAttachedToEmail
        ? '<p style="margin:8px 0 0;font-size:12px;color:#64748b;">File(s) are also attached to this email.</p>'
        : '';

    return '<tr><td style="padding:8px 0;border-bottom:1px solid #e2e8f0;color:#64748b;vertical-align:top;">Attachments</td>'
        . '<td style="padding:8px 0;border-bottom:1px solid #e2e8f0;color:#0f172a;">'
        . '<ul style="margin:0;padding-left:18px;">' . $items . '</ul>' . $note . '</td></tr>';
}

/**
 * @param array{unique_id: string, name: string, email: string, category: string, priority: string, subject: string, message: string, attachment_names?: list<string>} $ticket
 */
function sq_build_ticket_received_user_email_html(array $ticket, string $trackUrl): string
{
    $safeName = htmlspecialchars($ticket['name'], ENT_QUOTES, 'UTF-8');
    $safeId = htmlspecialchars($ticket['unique_id'], ENT_QUOTES, 'UTF-8');
    $safeSubject = htmlspecialchars($ticket['subject'], ENT_QUOTES, 'UTF-8');
    $safeCategory = htmlspecialchars(ucwords(str_replace('_', ' ', $ticket['category'])), ENT_QUOTES, 'UTF-8');
    $safePriority = htmlspecialchars(ucwords($ticket['priority']), ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($ticket['message'], ENT_QUOTES, 'UTF-8'));
    $safeTrackUrl = htmlspecialchars($trackUrl, ENT_QUOTES, 'UTF-8');
    $priorityColor = sq_ticket_email_priority_color($ticket['priority']);
    $year = date('Y');
    $helpCenterUrl = htmlspecialchars(sq_ticket_email_base_url() . '/Public/Help_center/PHP/Frontend/Help_center.php', ENT_QUOTES, 'UTF-8');
    $attachmentsRow = sq_ticket_email_attachments_user_html($ticket['attachment_names'] ?? []);

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support ticket received</title>
</head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#eef2ff;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#eef2ff;">
<tr>
<td align="center" style="padding:28px 16px;">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="border-collapse:collapse;max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 12px 32px rgba(30,41,59,0.12);">

<tr>
<td style="padding:36px 32px 28px;text-align:center;background:linear-gradient(135deg,#3b82f6 0%,#6366f1 48%,#8b5cf6 100%);">
<p style="margin:0 0 8px;font-size:13px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:rgba(255,255,255,0.85);">ScanQuotient Support</p>
<h1 style="margin:0;font-size:26px;line-height:1.25;color:#ffffff;font-weight:800;">We received your request</h1>
<p style="margin:12px 0 0;font-size:15px;line-height:1.5;color:rgba(255,255,255,0.92);">Your ticket is in our queue and our team will review it shortly.</p>
</td>
</tr>

<tr>
<td style="padding:32px 32px 8px;">
<p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#1e293b;">Hello <strong>{$safeName}</strong>,</p>
<p style="margin:0 0 24px;font-size:15px;line-height:1.65;color:#475569;">Thank you for contacting ScanQuotient. Save your ticket ID below to track replies and status updates at any time.</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 24px;">
<tr>
<td align="center" style="padding:20px 18px;background:linear-gradient(180deg,#f8fafc 0%,#f1f5f9 100%);border:1px solid #e2e8f0;border-radius:12px;">
<p style="margin:0 0 8px;font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:#64748b;">Your ticket ID</p>
<p style="margin:0;font-size:28px;font-weight:800;letter-spacing:0.06em;color:#1d4ed8;font-family:Consolas,Monaco,monospace;">{$safeId}</p>
</td>
</tr>
</table>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 24px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;">
<tr>
<td style="padding:16px 18px;border-bottom:1px solid #e2e8f0;">
<p style="margin:0 0 4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;">Subject</p>
<p style="margin:0;font-size:15px;font-weight:700;color:#0f172a;">{$safeSubject}</p>
</td>
</tr>
<tr>
<td style="padding:14px 18px;border-bottom:1px solid #e2e8f0;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
<tr>
<td width="50%" valign="top" style="padding-right:8px;">
<p style="margin:0 0 4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;">Category</p>
<p style="margin:0;font-size:14px;font-weight:600;color:#334155;">{$safeCategory}</p>
</td>
<td width="50%" valign="top" style="padding-left:8px;">
<p style="margin:0 0 4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;">Priority</p>
<p style="margin:0;font-size:14px;font-weight:700;color:{$priorityColor};">{$safePriority}</p>
</td>
</tr>
</table>
</td>
</tr>
<tr>
<td style="padding:16px 18px;">
<p style="margin:0 0 8px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#94a3b8;">Your message</p>
<p style="margin:0;font-size:14px;line-height:1.6;color:#475569;">{$safeMessage}</p>
</td>
</tr>
{$attachmentsRow}
</table>

<table role="presentation" cellspacing="0" cellpadding="0" align="center" style="border-collapse:collapse;margin:0 auto 28px;">
<tr>
<td align="center" style="border-radius:999px;background:linear-gradient(135deg,#3b82f6,#6366f1);">
<a href="{$safeTrackUrl}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">Track your ticket</a>
</td>
</tr>
</table>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 8px;">
<tr>
<td style="padding:16px 18px;background:#eff6ff;border-left:4px solid #3b82f6;border-radius:0 10px 10px 0;">
<p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#1e40af;">What happens next?</p>
<p style="margin:0;font-size:13px;line-height:1.55;color:#334155;">Our support team will review your ticket and respond through the ticket thread. You will receive email updates when we reply or when your ticket status changes.</p>
</td>
</tr>
</table>
</td>
</tr>

<tr>
<td style="padding:8px 32px 28px;">
<p style="margin:0;font-size:13px;line-height:1.55;color:#64748b;text-align:center;">
Need more help? Visit the <a href="{$helpCenterUrl}" style="color:#3b82f6;font-weight:600;text-decoration:none;">Help Center</a>
or reply on your ticket tracking page.
</p>
</td>
</tr>

<tr>
<td style="padding:18px 32px;text-align:center;background:#f8fafc;border-top:1px solid #e2e8f0;">
<p style="margin:0 0 6px;font-size:12px;color:#94a3b8;">&copy; {$year} ScanQuotient. All rights reserved.</p>
<p style="margin:0;font-size:11px;color:#cbd5e1;">Quantifying Risk. Strengthening Security.</p>
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

/**
 * @param array{unique_id: string, name: string, email: string, category: string, priority: string, subject: string, message: string, attachment_names?: list<string>} $ticket
 */
function sq_build_ticket_received_admin_email_html(array $ticket): string
{
    $safeName = htmlspecialchars($ticket['name'], ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($ticket['email'], ENT_QUOTES, 'UTF-8');
    $safeId = htmlspecialchars($ticket['unique_id'], ENT_QUOTES, 'UTF-8');
    $safeSubject = htmlspecialchars($ticket['subject'], ENT_QUOTES, 'UTF-8');
    $safeCategory = htmlspecialchars(ucwords(str_replace('_', ' ', $ticket['category'])), ENT_QUOTES, 'UTF-8');
    $safePriority = htmlspecialchars(ucwords($ticket['priority']), ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($ticket['message'], ENT_QUOTES, 'UTF-8'));
    $priorityColor = sq_ticket_email_priority_color($ticket['priority']);
    $year = date('Y');
    $attachmentsRow = sq_ticket_email_attachments_admin_html($ticket['attachment_names'] ?? []);

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f1f5f9;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">
<tr><td align="center" style="padding:24px 16px;">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="border-collapse:collapse;max-width:600px;width:100%;background:#fff;border-radius:12px;box-shadow:0 8px 24px rgba(15,23,42,0.1);">
<tr>
<td style="padding:24px 28px;background:#0f172a;border-radius:12px 12px 0 0;">
<h1 style="margin:0;font-size:20px;color:#fff;">New support ticket</h1>
<p style="margin:8px 0 0;font-size:13px;color:#94a3b8;">Requires triage in the admin panel</p>
</td>
</tr>
<tr>
<td style="padding:24px 28px;">
<p style="margin:0 0 16px;font-size:14px;color:#475569;">Ticket <strong style="color:#1d4ed8;font-family:monospace;">{$safeId}</strong> was submitted.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:14px;">
<tr><td style="padding:8px 0;border-bottom:1px solid #e2e8f0;color:#64748b;width:120px;">Submitter</td><td style="padding:8px 0;border-bottom:1px solid #e2e8f0;color:#0f172a;"><strong>{$safeName}</strong> &lt;{$safeEmail}&gt;</td></tr>
<tr><td style="padding:8px 0;border-bottom:1px solid #e2e8f0;color:#64748b;">Category</td><td style="padding:8px 0;border-bottom:1px solid #e2e8f0;color:#0f172a;">{$safeCategory}</td></tr>
<tr><td style="padding:8px 0;border-bottom:1px solid #e2e8f0;color:#64748b;">Priority</td><td style="padding:8px 0;border-bottom:1px solid #e2e8f0;font-weight:700;color:{$priorityColor};">{$safePriority}</td></tr>
<tr><td style="padding:8px 0;border-bottom:1px solid #e2e8f0;color:#64748b;">Subject</td><td style="padding:8px 0;border-bottom:1px solid #e2e8f0;color:#0f172a;">{$safeSubject}</td></tr>
{$attachmentsRow}
<tr><td style="padding:8px 0;color:#64748b;vertical-align:top;">Message</td><td style="padding:8px 0;color:#334155;line-height:1.55;">{$safeMessage}</td></tr>
</table>
</td>
</tr>
<tr>
<td style="padding:16px 28px;text-align:center;background:#f8fafc;border-radius:0 0 12px 12px;">
<p style="margin:0;font-size:12px;color:#94a3b8;">&copy; {$year} ScanQuotient Support Desk</p>
</td>
</tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}
