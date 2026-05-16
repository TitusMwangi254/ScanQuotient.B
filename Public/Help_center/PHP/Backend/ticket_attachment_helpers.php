<?php

function sq_ticket_upload_dir(): string
{
    $currentFileDir = __DIR__;
    $projectRoot = dirname(dirname(dirname(dirname($currentFileDir))));

    return $projectRoot . DIRECTORY_SEPARATOR . 'Storage' . DIRECTORY_SEPARATOR . 'Ticket_attachments' . DIRECTORY_SEPARATOR;
}

/**
 * @return list<string>
 */
function sq_validate_ticket_attachments(array $files, int $existingCount = 0): array
{
    $errors = [];
    $maxFiles = 5;
    $maxFileSize = 5 * 1024 * 1024;
    $allowedExts = ['jpg', 'jpeg', 'png', 'pdf', 'txt'];

    if (empty($files['name'][0])) {
        $errors[] = 'Please select at least one file to upload.';
        return $errors;
    }

    $fileCount = count($files['name']);
    if ($existingCount + $fileCount > $maxFiles) {
        $remaining = max(0, $maxFiles - $existingCount);
        $errors[] = "You can upload a maximum of {$maxFiles} files per ticket. You may add {$remaining} more.";
        return $errors;
    }

    for ($i = 0; $i < $fileCount; $i++) {
        $fileName = $files['name'][$i];
        $fileSize = (int) ($files['size'][$i] ?? 0);
        $fileError = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;

        if ($fileError !== UPLOAD_ERR_OK) {
            $errors[] = "Error uploading file '{$fileName}'.";
            continue;
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
            $errors[] = "File type not allowed for '{$fileName}'. Allowed: JPG, JPEG, PNG, PDF, TXT.";
        }
        if ($fileSize > $maxFileSize) {
            $errors[] = "File '{$fileName}' exceeds the maximum size of 5 MB.";
        }
    }

    return $errors;
}

/**
 * @param list<string> $attachmentPathsForDB
 * @param list<string> $attachmentNamesForDB
 */
function sq_process_ticket_attachment_uploads(
    array $files,
    string $uploadDir,
    string $uniqueId,
    array &$attachmentPathsForDB,
    array &$attachmentNamesForDB
): void {
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException('Failed to create upload directory.');
    }

    if (!is_writable($uploadDir)) {
        throw new RuntimeException('Upload directory is not writable.');
    }

    foreach ($files['name'] as $index => $fileOriginalName) {
        if (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }

        $ext = strtolower(pathinfo($fileOriginalName, PATHINFO_EXTENSION));
        $newStoredFileName = $uniqueId . '_' . uniqid('', true) . '.' . $ext;
        $destinationPath = $uploadDir . $newStoredFileName;

        if (move_uploaded_file($files['tmp_name'][$index], $destinationPath)) {
            $attachmentPathsForDB[] = 'Storage/Ticket_attachments/' . $newStoredFileName;
            $attachmentNamesForDB[] = $fileOriginalName;
        }
    }
}

/**
 * @return array{paths: list<string>, names: list<string>}
 */
function sq_parse_ticket_attachment_fields(?string $pathField, ?string $nameField): array
{
    $paths = array_values(array_filter(array_map('trim', explode(',', (string) $pathField))));
    $names = array_values(array_filter(array_map('trim', explode(',', (string) $nameField))));

    if (count($paths) !== count($names)) {
        $count = min(count($paths), count($names));
        $paths = array_slice($paths, 0, $count);
        $names = array_slice($names, 0, $count);
    }

    return ['paths' => $paths, 'names' => $names];
}

function sq_attachment_icon_class(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    return match ($ext) {
        'pdf' => 'fa-file-pdf',
        'jpg', 'jpeg', 'png' => 'fa-file-image',
        'txt' => 'fa-file-lines',
        default => 'fa-file',
    };
}

function sq_ticket_set_timezone(): DateTimeZone
{
    date_default_timezone_set('Africa/Nairobi');

    return new DateTimeZone('Africa/Nairobi');
}

function sq_ticket_now_eat(): string
{
    return (new DateTime('now', sq_ticket_set_timezone()))->format('Y-m-d H:i:s');
}

function sq_ticket_format_eat_display(string $sentAt): string
{
    try {
        $dt = new DateTime($sentAt, sq_ticket_set_timezone());
    } catch (Exception $e) {
        return $sentAt;
    }

    return $dt->format('M j, Y g:i A') . ' EAT';
}

function sq_ticket_apply_db_timezone(PDO|mysqli $db): void
{
    if ($db instanceof PDO) {
        $db->exec("SET time_zone = '+03:00'");
        return;
    }

    $db->query("SET time_zone = '+03:00'");
}

function sq_ensure_conversation_log_column(PDO|mysqli $db): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    if ($db instanceof PDO) {
        $stmt = $db->query("SHOW COLUMNS FROM support_tickets LIKE 'conversation_log'");
        $exists = $stmt && $stmt->fetch() !== false;
        if (!$exists) {
            try {
                $db->exec('ALTER TABLE support_tickets ADD COLUMN conversation_log MEDIUMTEXT NULL');
                $exists = true;
            } catch (Throwable $e) {
                $exists = false;
            }
        }
        return $exists;
    }

    $res = $db->query("SHOW COLUMNS FROM support_tickets LIKE 'conversation_log'");
    $exists = $res && $res->num_rows > 0;
    if (!$exists) {
        $exists = (bool) $db->query('ALTER TABLE support_tickets ADD COLUMN conversation_log MEDIUMTEXT NULL');
    }

    return $exists;
}

/**
 * @return list<string>
 */
function sq_parse_legacy_user_replies(?string $raw): array
{
    $raw = trim((string) $raw);
    if ($raw === '' || $raw === '—') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn($p) => $p !== ''));
}

/**
 * @return list<string>
 */
function sq_parse_legacy_admin_replies(?string $raw): array
{
    $raw = trim((string) $raw);
    if ($raw === '' || $raw === '—') {
        return [];
    }

    if (str_contains($raw, "\n\n")) {
        $parts = preg_split("/\r?\n\r?\n/", $raw) ?: [];
    } else {
        $parts = explode(',', $raw);
    }

    return array_values(array_filter(array_map('trim', $parts), static fn($p) => $p !== ''));
}

/**
 * @param list<array{role: string, text: string, sent_at: string}> $entries
 * @return list<array{role: string, text: string, sent_at: string}>
 */
function sq_conversation_sort_entries(array $entries): array
{
    usort($entries, static function (array $a, array $b): int {
        return strcmp($a['sent_at'] ?? '', $b['sent_at'] ?? '');
    });

    return $entries;
}

/**
 * @return list<array{role: string, text: string, sent_at: string}>
 */
function sq_conversation_migrate_legacy(array $ticket): array
{
    $tz = sq_ticket_set_timezone();
    try {
        $base = new DateTime((string) ($ticket['created_at'] ?? 'now'), $tz);
    } catch (Exception $e) {
        $base = new DateTime('now', $tz);
    }

    $entries = [];
    $minuteOffset = 0;

    $initial = trim((string) ($ticket['message'] ?? ''));
    if ($initial !== '') {
        $entries[] = [
            'role' => 'user',
            'text' => $initial,
            'sent_at' => $base->format('Y-m-d H:i:s'),
        ];
        $minuteOffset++;
    }

    $userMessages = sq_parse_legacy_user_replies($ticket['user_reply'] ?? null);
    $adminMessages = sq_parse_legacy_admin_replies($ticket['admin_reply'] ?? null);
    $max = max(count($userMessages), count($adminMessages));

    for ($i = 0; $i < $max; $i++) {
        if (isset($userMessages[$i]) && $userMessages[$i] !== '') {
            $sent = clone $base;
            $sent->modify('+' . $minuteOffset . ' minutes');
            $entries[] = [
                'role' => 'user',
                'text' => $userMessages[$i],
                'sent_at' => $sent->format('Y-m-d H:i:s'),
            ];
            $minuteOffset++;
        }
        if (isset($adminMessages[$i]) && $adminMessages[$i] !== '') {
            $sent = clone $base;
            $sent->modify('+' . $minuteOffset . ' minutes');
            $entries[] = [
                'role' => 'admin',
                'text' => $adminMessages[$i],
                'sent_at' => $sent->format('Y-m-d H:i:s'),
            ];
            $minuteOffset++;
        }
    }

    return sq_conversation_sort_entries($entries);
}

/**
 * @return list<array{role: string, text: string, sent_at: string}>
 */
function sq_conversation_get_log(array $ticket): array
{
    $raw = trim((string) ($ticket['conversation_log'] ?? ''));
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && $decoded !== []) {
            $entries = [];
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $role = ($row['role'] ?? '') === 'admin' ? 'admin' : 'user';
                $text = trim((string) ($row['text'] ?? ''));
                $sentAt = trim((string) ($row['sent_at'] ?? ''));
                if ($text === '' || $sentAt === '') {
                    continue;
                }
                $entries[] = ['role' => $role, 'text' => $text, 'sent_at' => $sentAt];
            }
            if ($entries !== []) {
                return sq_conversation_sort_entries($entries);
            }
        }
    }

    return sq_conversation_migrate_legacy($ticket);
}

/**
 * @return list<array{role: string, text: string, sent_at: string, sent_at_label: string}>
 */
function sq_get_ticket_conversation_thread(array $ticket): array
{
    $log = sq_conversation_get_log($ticket);

    return array_map(static function (array $entry): array {
        return [
            'role' => $entry['role'],
            'text' => $entry['text'],
            'sent_at' => $entry['sent_at'],
            'sent_at_label' => sq_ticket_format_eat_display($entry['sent_at']),
        ];
    }, $log);
}

/**
 * @return list<array{role: string, text: string, sent_at: string}>
 */
function sq_conversation_append_message(array $ticket, string $role, string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return sq_conversation_get_log($ticket);
    }

    $role = $role === 'admin' ? 'admin' : 'user';
    $log = sq_conversation_get_log($ticket);
    $log[] = [
        'role' => $role,
        'text' => $text,
        'sent_at' => sq_ticket_now_eat(),
    ];

    return sq_conversation_sort_entries($log);
}

function sq_conversation_log_to_json(array $log): string
{
    return json_encode($log, JSON_UNESCAPED_UNICODE);
}

/**
 * @param list<array{role: string, text: string, sent_at: string}> $log
 */
function sq_sync_legacy_reply_fields_from_log(array $log, ?string $ticketMessage = null): array
{
    $userParts = [];
    $adminParts = [];
    $ticketMessage = trim((string) $ticketMessage);
    $skippedInitial = ($ticketMessage === '');

    foreach ($log as $entry) {
        if (($entry['role'] ?? '') === 'admin') {
            $adminParts[] = $entry['text'];
        } else {
            if (!$skippedInitial && trim((string) ($entry['text'] ?? '')) === $ticketMessage) {
                $skippedInitial = true;
                continue;
            }
            $userParts[] = $entry['text'];
        }
    }

    return [
        'user_reply' => $userParts === [] ? null : implode(', ', $userParts),
        'admin_reply' => $adminParts === [] ? null : implode("\n\n", $adminParts),
    ];
}

function sq_persist_conversation_log_pdo(PDO $pdo, string $uniqueId, array $log, ?string $ticketMessage = null): bool
{
    sq_ticket_apply_db_timezone($pdo);
    $legacy = sq_sync_legacy_reply_fields_from_log($log, $ticketMessage);
    $json = sq_conversation_log_to_json($log);
    $now = sq_ticket_now_eat();

    if (sq_ensure_conversation_log_column($pdo)) {
        $stmt = $pdo->prepare(
            'UPDATE support_tickets SET conversation_log = ?, user_reply = ?, admin_reply = ?, updated_at = ? WHERE unique_id = ?'
        );
        return $stmt->execute([$json, $legacy['user_reply'], $legacy['admin_reply'], $now, $uniqueId]);
    }

    $stmt = $pdo->prepare(
        'UPDATE support_tickets SET user_reply = ?, admin_reply = ?, updated_at = ? WHERE unique_id = ?'
    );

    return $stmt->execute([$legacy['user_reply'], $legacy['admin_reply'], $now, $uniqueId]);
}

function sq_persist_conversation_log_mysqli(mysqli $conn, string $uniqueId, array $log, ?string $ticketMessage = null): bool
{
    sq_ticket_apply_db_timezone($conn);
    $legacy = sq_sync_legacy_reply_fields_from_log($log, $ticketMessage);
    $json = sq_conversation_log_to_json($log);
    $now = sq_ticket_now_eat();

    if (sq_ensure_conversation_log_column($conn)) {
        $stmt = $conn->prepare(
            'UPDATE support_tickets SET conversation_log = ?, user_reply = ?, admin_reply = ?, updated_at = ? WHERE unique_id = ?'
        );
        $stmt->bind_param('sssss', $json, $legacy['user_reply'], $legacy['admin_reply'], $now, $uniqueId);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }

    $stmt = $conn->prepare(
        'UPDATE support_tickets SET user_reply = ?, admin_reply = ?, updated_at = ? WHERE unique_id = ?'
    );
    $stmt->bind_param('ssss', $legacy['user_reply'], $legacy['admin_reply'], $now, $uniqueId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function sq_persist_initial_conversation_log_pdo(PDO $pdo, string $uniqueId, string $message, string $createdAt): void
{
    sq_ticket_apply_db_timezone($pdo);
    $tz = sq_ticket_set_timezone();
    try {
        $sentAt = (new DateTime($createdAt, $tz))->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        $sentAt = sq_ticket_now_eat();
    }

    $log = [['role' => 'user', 'text' => trim($message), 'sent_at' => $sentAt]];
    if (!sq_ensure_conversation_log_column($pdo)) {
        return;
    }

    $json = sq_conversation_log_to_json($log);
    $stmt = $pdo->prepare('UPDATE support_tickets SET conversation_log = ? WHERE unique_id = ?');
    $stmt->execute([$json, $uniqueId]);
}

/**
 * @deprecated Use sq_get_ticket_conversation_thread($ticket) instead.
 * @param list<string> $userMessages
 * @param list<string> $adminMessages
 * @return list<array{role: string, text: string}>
 */
function sq_build_ticket_conversation_thread(array $userMessages, array $adminMessages): array
{
    $ticket = [
        'message' => '',
        'user_reply' => $userMessages === [] ? null : implode(', ', $userMessages),
        'admin_reply' => $adminMessages === [] ? null : implode("\n\n", $adminMessages),
        'created_at' => sq_ticket_now_eat(),
        'conversation_log' => null,
    ];

    return sq_get_ticket_conversation_thread($ticket);
}
