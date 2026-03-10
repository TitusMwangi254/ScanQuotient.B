<?php
date_default_timezone_set('Africa/Nairobi');
require 'C:/Users/1/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header("Content-Type: application/json");

$pdo = new PDO(
    "mysql:host=127.0.0.1;dbname=scanquotient.a1;charset=utf8mb4",
    "root",
    "",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$email = $_POST['email'] ?? '';

// DEBUG: Log what we received
error_log("Received email: '" . $email . "'");
error_log("Email length: " . strlen($email));
error_log("Email bytes: " . bin2hex($email));

if (!$email) {
    echo json_encode(["status" => "error", "message" => "Invalid request - no email received"]);
    exit;
}

// Trim and normalize
$email = trim($email);
$email = strtolower($email);

// DEBUG: Log after normalization
error_log("Normalized email: '" . $email . "'");

// Check what's in database for this email
$debugStmt = $pdo->prepare("SELECT email, LOWER(email) as lower_email, LENGTH(email) as email_length FROM users WHERE LOWER(TRIM(email)) = ?");
$debugStmt->execute([$email]);
$debugUser = $debugStmt->fetch();

if ($debugUser) {
    error_log("Found in DB: '" . $debugUser['email'] . "' (length: " . $debugUser['email_length'] . ")");
} else {
    error_log("No user found with normalized email: '" . $email . "'");

    // List all emails in DB for comparison
    $allEmails = $pdo->query("SELECT email FROM users WHERE email LIKE '%mwangindekere%'")->fetchAll();
    error_log("Similar emails in DB: " . print_r($allEmails, true));
}

// Now do the actual lookup with TRIM to handle any whitespace issues
$stmt = $pdo->prepare("SELECT verification_resend_count FROM users WHERE TRIM(LOWER(email)) = TRIM(LOWER(?))");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(["status" => "error", "message" => "User not found for: " . $email]);
    exit;
}

if ($user['verification_resend_count'] >= 3) {
    echo json_encode(["status" => "error", "message" => "Maximum resend limit reached."]);
    exit;
}

// Generate new 6-digit code
$code = random_int(100000, 999999);
$expires = date("Y-m-d H:i:s", strtotime("+5 minutes"));

// Update database
$update = $pdo->prepare("
    UPDATE users
    SET email_verification_token = :token,
        email_verification_expires = :expires,
        verification_resend_count = verification_resend_count + 1
    WHERE TRIM(LOWER(email)) = TRIM(LOWER(:email))
");

$update->execute([
    ":token" => $code,
    ":expires" => $expires,
    ":email" => $email
]);

// Send email
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
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = "New Verification Code";

    $mail->Body = "
        Your new verification code is:
        <h2>$code</h2>
        This code expires in 5 minutes.
    ";

    $mail->send();

    echo json_encode([
        "status" => "success",
        "message" => "New code sent successfully."
    ]);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Email sending failed: " . $e->getMessage()
    ]);
}
?>