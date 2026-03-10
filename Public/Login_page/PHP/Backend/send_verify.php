<?php
session_start();
header('Content-Type: application/json');

require_once 'C:/Users/1/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['fp_step']) || $_SESSION['fp_step'] !== 'verify') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid session']);
    exit();
}

$method = $_POST['method'] ?? '';

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=scanquotient.a1;charset=utf8mb4", "root", "");

    // Get fresh user data
    $stmt = $pdo->prepare("SELECT security_answer FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['fp_user_id']]);
    $user = $stmt->fetch();

    if ($method === 'question') {
        // Verify security answer
        $answer = strtolower(trim($_POST['answer'] ?? ''));
        if (!password_verify($answer, $user['security_answer'])) {
            echo json_encode(['status' => 'error', 'message' => 'Incorrect answer']);
            exit();
        }
        $_SESSION['fp_verified'] = true;
        echo json_encode(['status' => 'success', 'skip_code' => true]);

    } else {
        // Send email code
        $code = sprintf('%06d', mt_rand(0, 999999));
        $_SESSION['fp_code'] = $code;
        $_SESSION['fp_expires'] = time() + 900; // 15 min

        $target = ($method === 'recovery') ? $_SESSION['fp_recovery'] : $_SESSION['fp_email'];

        // Send email
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'scanquotient@gmail.com';
        $mail->Password = 'vnht iefe anwl xynb';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->setFrom('scanquotient@gmail.com', 'ScanQuotient');
        $mail->addAddress($target);
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Code';
        $mail->Body = "<div style='text-align:center'><h2>Reset Code: {$code}</h2><p>Expires in 15 minutes</p></div>";
        $mail->send();

        echo json_encode(['status' => 'success']);
    }

} catch (Exception $e) {
    error_log("Send verify error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to send']);
}
?>