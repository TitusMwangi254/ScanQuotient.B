<?php
session_start();
require_once __DIR__ . '/ticket_attachment_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../Frontend/Help_center.php');
    exit;
}

$uniqueId = trim($_POST['unique_id'] ?? '');
if ($uniqueId === '') {
    header('Location: ../Frontend/user_ticket_tracking.php?error=missing_ticket');
    exit;
}

$host = '127.0.0.1';
$db = 'scanquotient.a1';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$db};charset={$charset}",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    header('Location: ../Frontend/user_ticket_tracking.php?id=' . urlencode($uniqueId) . '&error=db_error');
    exit;
}

$stmt = $pdo->prepare('SELECT unique_id, status, attachment_path, attachment_name FROM support_tickets WHERE unique_id = ? LIMIT 1');
$stmt->execute([$uniqueId]);
$ticket = $stmt->fetch();

if (!$ticket) {
    header('Location: ../Frontend/user_ticket_tracking.php?error=invalid_ticket');
    exit;
}

if ($ticket['status'] === 'closed') {
    header('Location: ../Frontend/user_ticket_tracking.php?id=' . urlencode($uniqueId) . '&error=closed_attachments');
    exit;
}

$existing = sq_parse_ticket_attachment_fields($ticket['attachment_path'] ?? '', $ticket['attachment_name'] ?? '');
$existingCount = count($existing['paths']);

$errors = sq_validate_ticket_attachments($_FILES['attachments'] ?? [], $existingCount);
if (!empty($errors)) {
    $_SESSION['toast_message'] = implode(' ', $errors);
    header('Location: ../Frontend/user_ticket_tracking.php?id=' . urlencode($uniqueId) . '&error=attachment_validation');
    exit;
}

$newPaths = [];
$newNames = [];

try {
    sq_process_ticket_attachment_uploads(
        $_FILES['attachments'],
        sq_ticket_upload_dir(),
        $uniqueId,
        $newPaths,
        $newNames
    );
} catch (RuntimeException $e) {
    $_SESSION['toast_message'] = 'Could not save attachments. Please try again.';
    header('Location: ../Frontend/user_ticket_tracking.php?id=' . urlencode($uniqueId) . '&error=upload_failed');
    exit;
}

if (empty($newPaths)) {
    $_SESSION['toast_message'] = 'No files were uploaded. Please try again.';
    header('Location: ../Frontend/user_ticket_tracking.php?id=' . urlencode($uniqueId) . '&error=upload_failed');
    exit;
}

$allPaths = array_merge($existing['paths'], $newPaths);
$allNames = array_merge($existing['names'], $newNames);

$update = $pdo->prepare('
    UPDATE support_tickets
    SET attachment_path = ?, attachment_name = ?, updated_at = NOW()
    WHERE unique_id = ?
');
$update->execute([
    implode(',', $allPaths),
    implode(',', $allNames),
    $uniqueId,
]);

header('Location: ../Frontend/user_ticket_tracking.php?id=' . urlencode($uniqueId) . '&success=attachments_added');
exit;
