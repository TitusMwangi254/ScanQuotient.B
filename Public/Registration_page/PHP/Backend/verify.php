<?php
require 'C:/Users/1/vendor/autoload.php';
require_once __DIR__ . '/registration_email_templates.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header("Content-Type: application/json");

$host = '127.0.0.1';
$db = 'scanquotient.a1';
$user = 'root';
$pass = '';

function sq_generate_temp_password(int $length = 12): string
{
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $max = strlen($characters) - 1;
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[random_int(0, $max)];
    }
    return $password;
}

function sq_resolve_login_credentials(PDO $pdo, array $user, string $email): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $emailKey = strtolower(trim($email));
    $pending = $_SESSION['sq_pending_credentials'] ?? null;

    if (
        is_array($pending)
        && ($pending['email'] ?? '') === $emailKey
        && (int) ($pending['expires'] ?? 0) > time()
        && !empty($pending['username'])
        && !empty($pending['password'])
    ) {
        unset($_SESSION['sq_pending_credentials']);
        return [
            'username' => (string) $pending['username'],
            'password' => (string) $pending['password'],
        ];
    }

    $username = (string) ($user['user_name'] ?? strtolower((string) ($user['surname'] ?? '')));
    $tempPassword = sq_generate_temp_password(12);
    $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([$passwordHash, $user['id']]);

    return [
        'username' => $username,
        'password' => $tempPassword,
    ];
}

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request.");
    }

    $email = trim($_POST['email'] ?? '');
    $code = trim($_POST['code'] ?? '');

    if (!$email || !$code) {
        throw new Exception("Missing data.");
    }

    if (strlen($code) !== 6 || !preg_match('/^\d{6}$/', $code)) {
        throw new Exception("Invalid code format.");
    }

    $stmt = $pdo->prepare("
        SELECT id, user_id, user_name, surname, email, first_name
        FROM users
        WHERE LOWER(email) = LOWER(?)
        AND email_verification_token = ?
        AND email_verification_expires > NOW()
    ");

    $stmt->execute([$email, $code]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new Exception("Invalid or expired code.");
    }

    $update = $pdo->prepare("
        UPDATE users
        SET email_verified = 'yes',
            email_verification_token = NULL,
            email_verification_expires = NULL
        WHERE id = ?
    ");
    $update->execute([$user['id']]);

    $credentials = sq_resolve_login_credentials($pdo, $user, $email);
    sendWelcomeEmail($user, $credentials['username'], $credentials['password']);

    echo json_encode([
        "status" => "success",
        "message" => "You're all set. Your sign-in details are in the email we sent, check your inbox or spam folder.",
        "username" => $credentials['username'],
        "redirect" => "../../../Login_page/PHP/Frontend/Login_page_site.php",
    ]);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}

function sendWelcomeEmail(array $user, string $username, string $password): void
{
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
        $mail->addAddress($user['email'], $user['first_name'] . ' ' . $user['surname']);

        $mail->isHTML(true);
        $mail->Subject = 'ScanQuotient - Your login credentials';

        $mail->Body = sq_build_welcome_verified_email_html(
            (string) ($user['first_name'] ?? 'User'),
            $username,
            $password
        );

        $mail->AltBody = "Your email is verified.\n\nUsername: {$username}\nPassword: {$password}\n\nSign in to complete your account setup.\n\n/ScanQuotient.v2/ScanQuotient.B/Public/Login_page/PHP/Frontend/Login_page_site.php";

        $mail->send();
    } catch (Exception $e) {
        error_log("Failed to send welcome email to " . $user['email'] . ": " . $mail->ErrorInfo);
    }
}
