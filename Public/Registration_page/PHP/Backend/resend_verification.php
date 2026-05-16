<?php
date_default_timezone_set('Africa/Nairobi');
require 'C:/Users/1/vendor/autoload.php';
require_once __DIR__ . '/registration_email_templates.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header("Content-Type: application/json");

$pdo = new PDO(
    "mysql:host=127.0.0.1;dbname=scanquotient.a1;charset=utf8mb4",
    "root",
    "",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$email = trim((string) ($_POST['email'] ?? ''));

if ($email === '') {
    echo json_encode(["status" => "error", "message" => "Invalid request - no email received"]);
    exit;
}

$email = strtolower($email);

$stmt = $pdo->prepare("
    SELECT verification_resend_count, first_name
    FROM users
    WHERE TRIM(LOWER(email)) = TRIM(LOWER(?))
");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(["status" => "error", "message" => "User not found for this email."]);
    exit;
}

if ((int) ($user['verification_resend_count'] ?? 0) >= 3) {
    echo json_encode(["status" => "error", "message" => "Maximum resend limit reached."]);
    exit;
}

$code = (string) random_int(100000, 999999);
$expires = date("Y-m-d H:i:s", strtotime("+5 minutes"));

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
    ":email" => $email,
]);

$firstName = (string) ($user['first_name'] ?? 'User');

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
    $mail->addAddress($email, $firstName);

    $mail->isHTML(true);
    $mail->Subject = 'ScanQuotient - New verification code';

    $mail->Body = sq_build_verification_code_email_html($firstName, $code, 'New verification code');
    $mail->AltBody = "Hello {$firstName},\n\nYour new verification code is: {$code}\n\nThis code expires in 5 minutes.";

    $mail->send();

    echo json_encode([
        "status" => "success",
        "message" => "A new verification code was sent to your email.",
    ]);
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Email sending failed. Please try again shortly.",
    ]);
}
