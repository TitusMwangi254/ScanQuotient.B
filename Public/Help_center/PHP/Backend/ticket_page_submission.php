<?php
session_start();

require_once __DIR__ . '/ticket_attachment_helpers.php';
require_once __DIR__ . '/ticket_email_templates.php';

// Autoload PHPMailer
require 'C:/Users/1/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- DB Connection ---
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

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    sq_ticket_apply_db_timezone($pdo);
} catch (Exception $e) {
    die("DB connection failed: " . $e->getMessage());
}

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../../Homepage/PHP/Frontend/Homepage.php');
    exit;
}

// --- 1. Sanitize and Validate Input Data ---
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$category = trim($_POST['category'] ?? '');
$priority = trim($_POST['priority'] ?? '');
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');

$errors = [];
if ($name === '')
    $errors[] = 'Name is required.';
if ($email === '')
    $errors[] = 'Email is required.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL))
    $errors[] = 'Email is invalid.';
if ($phone === '')
    $errors[] = 'Phone is required.';
if ($category === '')
    $errors[] = 'Category is required.';
if ($priority === '')
    $errors[] = 'Priority is required.';
if ($subject === '')
    $errors[] = 'Subject is required.';
if ($message === '')
    $errors[] = 'Message is required.';

// --- 1b. File Upload Validations ---
$maxFiles = 5;
$maxFileSize = 5 * 1024 * 1024;
$allowedExts = ['jpg', 'jpeg', 'png', 'pdf', 'txt'];

if (!empty($_FILES['attachments']['name'][0])) {
    $fileCount = count($_FILES['attachments']['name']);
    if ($fileCount > $maxFiles) {
        $errors[] = "You can upload a maximum of {$maxFiles} files.";
    }

    for ($i = 0; $i < $fileCount; $i++) {
        $fileName = $_FILES['attachments']['name'][$i];
        $fileSize = $_FILES['attachments']['size'][$i];
        $fileError = $_FILES['attachments']['error'][$i];

        if ($fileError !== UPLOAD_ERR_OK) {
            $errors[] = "Error uploading file '{$fileName}'. PHP Error code: {$fileError}.";
            continue;
        }
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) {
            $errors[] = "File type not allowed for '{$fileName}'.";
        }
        if ($fileSize > $maxFileSize) {
            $errors[] = "File '{$fileName}' exceeds max size of 5 MB.";
        }
    }
}

if (!empty($errors)) {
    die("Validation errors: " . implode(' ', $errors));
}

// --- 2. Generate Unique Ticket ID ---
$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
$random8 = '';
for ($i = 0; $i < 8; $i++) {
    $random8 .= $chars[random_int(0, strlen($chars) - 1)];
}
$uniqueId = 'T' . $random8;

// --- 3. Handle Multiple File Uploads ---
// FIXED: Use dirname() to navigate up the directory tree reliably
$currentFileDir = __DIR__; // Directory of this PHP file
// Go up 4 levels: Backend → PHP → Help_center → Public → ScanQuotient.B
$projectRoot = dirname(dirname(dirname(dirname($currentFileDir))));
$uploadDir = $projectRoot . DIRECTORY_SEPARATOR . 'Storage' . DIRECTORY_SEPARATOR . 'Ticket_attachments' . DIRECTORY_SEPARATOR;

// DEBUG: Log the calculated paths
error_log("Current file: " . __FILE__);
error_log("Project root: " . $projectRoot);
error_log("Upload directory: " . $uploadDir);

// Create directory if it doesn't exist
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        error_log("Failed to create directory: " . $uploadDir);
        die("Failed to create upload directory. Check permissions.");
    }
}

// Verify directory is writable
if (!is_writable($uploadDir)) {
    error_log("Directory not writable: " . $uploadDir);
    die("Upload directory is not writable. Check permissions.");
}

$attachmentPathsForDB = [];
$attachmentPathsForEmail = [];
$attachmentNamesForDB = [];

if (!empty($_FILES['attachments']['name'][0])) {
    foreach ($_FILES['attachments']['name'] as $index => $file_original_name) {
        if ($_FILES['attachments']['error'][$index] !== UPLOAD_ERR_OK) {
            continue;
        }

        $ext = strtolower(pathinfo($file_original_name, PATHINFO_EXTENSION));
        $newStoredFileName = $uniqueId . '_' . uniqid() . '.' . $ext;
        $destinationPath = $uploadDir . $newStoredFileName;

        // DEBUG: Log move attempt
        error_log("Attempting to move file from: " . $_FILES['attachments']['tmp_name'][$index]);
        error_log("To destination: " . $destinationPath);

        if (move_uploaded_file($_FILES['attachments']['tmp_name'][$index], $destinationPath)) {
            // FIXED: Use forward slashes for DB storage (cross-platform)
            $dbPath = 'Storage/Ticket_attachments/' . $newStoredFileName;
            $attachmentPathsForDB[] = $dbPath;
            $attachmentNamesForDB[] = $file_original_name;

            // FIXED: Use absolute path for email attachments
            $emailAttachmentPath = $uploadDir . $newStoredFileName;
            $attachmentPathsForEmail[] = $emailAttachmentPath;

            error_log("File moved successfully: " . $destinationPath);
        } else {
            error_log("Failed to move uploaded file '{$file_original_name}' to '{$destinationPath}'.");
            error_log("PHP Error: " . error_get_last()['message'] ?? 'Unknown error');
        }
    }
}

$attachmentPathStringForDB = !empty($attachmentPathsForDB) ? implode(',', $attachmentPathsForDB) : null;
$attachmentNameStringForDB = !empty($attachmentNamesForDB) ? implode(',', $attachmentNamesForDB) : null;

// --- 4. Insert Ticket Data into Database ---
$sql = "INSERT INTO support_tickets
    (unique_id, name, email, phone, category, priority, subject, message, attachment_path, attachment_name)
  VALUES
    (:uid, :name, :email, :phone, :cat, :prio, :subj, :msg, :att_path, :att_name)";
$stmt = $pdo->prepare($sql);
try {
    $stmt->execute([
        ':uid' => $uniqueId,
        ':name' => $name,
        ':email' => $email,
        ':phone' => $phone,
        ':cat' => $category,
        ':prio' => $priority,
        ':subj' => $subject,
        ':msg' => $message,
        ':att_path' => $attachmentPathStringForDB,
        ':att_name' => $attachmentNameStringForDB,
    ]);

    $createdStmt = $pdo->prepare('SELECT created_at FROM support_tickets WHERE unique_id = ? LIMIT 1');
    $createdStmt->execute([$uniqueId]);
    $createdRow = $createdStmt->fetch();
    sq_persist_initial_conversation_log_pdo(
        $pdo,
        $uniqueId,
        $message,
        (string) ($createdRow['created_at'] ?? sq_ticket_now_eat())
    );
} catch (Exception $e) {
    die("DB insert failed: " . $e->getMessage());
}

// --- 5. Send Emails via PHPMailer ---
$mail = new PHPMailer(true);

function logEmailAttempt($pdo, $ticketId, $recipient, $status, $errorMessage = null)
{
    $logSql = "INSERT INTO ticket_email_logs (ticket_unique_id, recipient_email, sent_at, status, error_message)
                VALUES (:ticket_id, :recipient_email, NOW(), :status, :error_message)";
    $logStmt = $pdo->prepare($logSql);
    try {
        $logStmt->execute([
            ':ticket_id' => $ticketId,
            ':recipient_email' => $recipient,
            ':status' => $status,
            ':error_message' => $errorMessage
        ]);
    } catch (PDOException $e) {
        error_log("CRITICAL ERROR: Failed to log email attempt to 'ticket_email_logs' table for ticket {$ticketId}: " . $e->getMessage());
    }
}

$adminEmailSent = false;
$userEmailSent = false;

$ticketEmailData = [
    'unique_id' => $uniqueId,
    'name' => $name,
    'email' => $email,
    'category' => $category,
    'priority' => $priority,
    'subject' => $subject,
    'message' => $message,
    'attachment_names' => $attachmentNamesForDB,
];
$attachmentNamesPlain = sq_ticket_email_attachment_names_plain($attachmentNamesForDB);
$attachmentAltSuffix = $attachmentNamesPlain !== '' ? " Attachments: {$attachmentNamesPlain}." : '';
$trackTicketUrl = sq_ticket_email_base_url()
    . '/Public/Help_center/PHP/Frontend/user_ticket_tracking.php?id='
    . urlencode($uniqueId);

try {
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'scanquotient@gmail.com';
    $mail->Password = 'vnht iefe anwl xynb';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->isHTML(true);
    $mail->setFrom('scanquotient@gmail.com', 'ScanQuotient Support Desk');

    // --- 5a) Send Admin Notification Email ---
    try {
        $mail->clearAllRecipients();
        $mail->clearAttachments();

        $mail->addAddress('scanquotient@gmail.com', 'Support Desk ScanQuotient');
        $mail->Subject = "New Support Ticket Received: {$uniqueId}";
        $mail->Body = sq_build_ticket_received_admin_email_html($ticketEmailData);
        $mail->AltBody = "New ticket {$uniqueId} from {$name} ({$email}). Subject: {$subject}.{$attachmentAltSuffix}";

        if (!empty($attachmentPathsForEmail)) {
            foreach ($attachmentPathsForEmail as $filePath) {
                if (file_exists($filePath)) {
                    $mail->addAttachment($filePath);
                    error_log("Attaching file to admin email: " . $filePath);
                } else {
                    error_log("Attachment file not found: " . $filePath);
                }
            }
        }

        $mail->send();
        logEmailAttempt($pdo, $uniqueId, 'scanquotient@gmail.com', 'success', null);
        $adminEmailSent = true;
    } catch (Exception $e) {
        logEmailAttempt($pdo, $uniqueId, 'scanquotient@gmail.com', 'failure', $e->getMessage());
        error_log("ERROR: Admin email failed for ticket {$uniqueId}: " . $e->getMessage());
    }

    // --- 5b) Send User Confirmation Email ---
    try {
        $mail->clearAllRecipients();
        $mail->clearAttachments();

        $mail->addAddress($email, $name);
        $mail->Subject = "Your support ticket {$uniqueId} has been received";
        $mail->Body = sq_build_ticket_received_user_email_html($ticketEmailData, $trackTicketUrl);
        $mail->AltBody = "Hello {$name}, your ticket {$uniqueId} was received. Track it here: {$trackTicketUrl}.{$attachmentAltSuffix}";

        $mail->send();
        logEmailAttempt($pdo, $uniqueId, $email, 'success', null);
        $userEmailSent = true;
    } catch (Exception $e) {
        logEmailAttempt($pdo, $uniqueId, $email, 'failure', $e->getMessage());
        error_log("ERROR: User email failed for ticket {$uniqueId}: " . $e->getMessage());
    }

    if ($adminEmailSent && $userEmailSent) {
        $_SESSION['toast_message'] = "Ticket created successfully! Confirmation email sent.";
    } elseif ($adminEmailSent || $userEmailSent) {
        $_SESSION['toast_message'] = "Ticket created, but one confirmation email failed to send.";
    } else {
        $_SESSION['toast_message'] = "Ticket created, but both confirmation emails failed to send.";
    }

} catch (Exception $e) {
    error_log("CRITICAL ERROR: PHPMailer setup failed for ticket {$uniqueId}: " . $e->getMessage());
    $_SESSION['toast_message'] = "Ticket created, but there was a critical error with the email system.";
}

// --- 6. Redirect User Back ---
header('Location:../Frontend/Help_center.php');
exit;
?>