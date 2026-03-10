<?php
require 'C:/Users/1/vendor/autoload.php';  // PHPMailer autoload

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- DB Connection ---
$host = '127.0.0.1';
$db = 'scanquotient.a1';   // Updated database name
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
} catch (\PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// --- Process form submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize input
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    // Validate required fields
    if (empty($name) || empty($email) || empty($message)) {
        die("Please fill in all required fields.");
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        die("Invalid email address.");
    }

    // Insert into customer_feedback table
    $stmt = $pdo->prepare("INSERT INTO customer_feedback (name, email, subject, message) VALUES (?, ?, ?, ?)");
    try {
        $stmt->execute([$name, $email, $subject, $message]);
    } catch (Exception $e) {
        die("Failed to save feedback: " . $e->getMessage());
    }

    // Start session to store status
    session_start();

    // Setup PHPMailer and send notification email (to admin)
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
        $mail->addAddress('scanquotient@gmail.com', 'ScanQuotient');

        $mail->isHTML(true);
        $mail->Subject = 'New Customer Feedback Received';

        $mail->Body = "
            <h2>New Customer Feedback</h2>
            <p><strong>Name:</strong> " . htmlspecialchars($name) . "</p>
            <p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
            <p><strong>Subject:</strong> " . htmlspecialchars($subject ?: '(No Subject)') . "</p>
            <p><strong>Message:</strong><br>" . nl2br(htmlspecialchars($message)) . "</p>
        ";

        $mail->send();
        $_SESSION['feedback_status'] = 'Message sent successfully!';

    } catch (Exception $e) {
        $_SESSION['feedback_status'] = 'Message saved but email sending failed: ' . $mail->ErrorInfo;
    }

    // Redirect back or to a thank-you page
    header('Location: ../Frontend/homepage.php');
    exit;
}