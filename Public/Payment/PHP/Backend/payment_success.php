<?php
// payment_success.php - FIXED VERSION
session_start();

require 'C:/Users/1/vendor/autoload.php';  // PHPMailer autoload

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// DEBUG: Log what we received
error_log("Payment Success - GET: " . print_r($_GET, true));
error_log("Payment Success - SESSION: " . print_r($_SESSION, true));

// Get status from URL
$status = $_GET['status'] ?? 'unknown';
$token = $_GET['token'] ?? null;  // This is the PayPal order ID from URL

// Check session OR use token from URL (PayPal sends token in URL)
$email = $_SESSION['payment_email'] ?? null;
$package = $_SESSION['payment_package'] ?? 'pro';
$price = $_SESSION['payment_price'] ?? 10.00;
$sessionOrderId = $_SESSION['paypal_order_id'] ?? null;

// Use token from URL if session expired (PayPal sends it as 'token')
$orderId = $sessionOrderId ?? $token;

if (!$email) {
    // Try to get from custom_data or show error
    $errorMessage = "Session expired. Please contact support with your transaction details.";
}

// UPDATED PayPal credentials - YOUR NEW WORKING ONES!
$clientId = 'AdkiLHxI45wV5xM6BXokRf_viLiugsnEmPDm2L2wlBq554tQPpIGFpTLhPjqsB4TtQd2qVS66eGUbMRT';
$clientSecret = 'EOkHTWuhomc6v5cxfforNabIY40gJr5juIxku1uajo9XDJ40ajIZteyTYPeU-nF1geb7gwQV0fBkhpdw';
$baseUrl = 'https://api-m.sandbox.paypal.com';

$paymentCompleted = false;
$transactionId = 'PENDING';
$errorMessage = '';

// If coming back from PayPal with success, capture the payment
if ($status === 'success' && $orderId && $email) {

    // Step 1: Get access token
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "$baseUrl/v1/oauth2/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
    curl_setopt($ch, CURLOPT_USERPWD, "$clientId:$clientSecret");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Accept-Language: en_US"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("PayPal Auth Response: $httpCode - $response");

    $auth = json_decode($response, true);
    $accessToken = $auth['access_token'] ?? null;

    if ($accessToken) {
        // Step 2: Capture the payment
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$baseUrl/v2/checkout/orders/$orderId/capture");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer $accessToken"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        error_log("PayPal Capture Response: $httpCode - $response");

        $capture = json_decode($response, true);

        if (isset($capture['status']) && $capture['status'] === 'COMPLETED') {
            $paymentCompleted = true;
            $transactionId = $capture['purchase_units'][0]['payments']['captures'][0]['id'] ?? 'UNKNOWN';

            // Save to database
            $saved = savePaymentToDatabase($email, $package, $price, $transactionId);

            if (!$saved) {
                $errorMessage = "Payment captured but failed to save to database.";
            }
        } else {
            $errorMessage = $capture['message'] ?? $capture['details'][0]['description'] ?? 'Payment capture failed';
            error_log("PayPal Capture Failed: " . print_r($capture, true));
        }
    } else {
        $errorMessage = "Failed to authenticate with PayPal";
    }
} else {
    if (!$email) {
        $errorMessage = "Session expired. Please try again.";
    } elseif (!$orderId) {
        $errorMessage = "No order ID found.";
    }
}

// Database save function - FIXED with account_status
function savePaymentToDatabase($email, $package, $price, $transactionId)
{
    try {
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

        $pdo = new PDO($dsn, $user, $pass, $options);

        // Calculate expiration date (exactly 1 month from now)
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 month'));

        // FIXED: Added account_status field
        $stmt = $pdo->prepare("
            INSERT INTO payments 
            (email, package, amount, transaction_id, status, payment_method, account_status, expires_at, created_at) 
            VALUES (?, ?, ?, ?, 'completed', 'paypal', 'active', ?, NOW())
        ");

        $stmt->execute([$email, $package, $price, $transactionId, $expiresAt]);

        // Send emails
        $adminEmail = 'scanquotient@gmail.com';
        $packageName = ucfirst($package) === 'Pro' ? 'Pro' : 'Enterprise Suite';

        sendPaymentEmail($email, $email, $packageName, $price, $transactionId, $expiresAt, 'user');
        sendPaymentEmail($adminEmail, $email, $packageName, $price, $transactionId, $expiresAt, 'admin');

        return true;

    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return false;
    } catch (Exception $e) {
        error_log("Email error: " . $e->getMessage());
        return true; // Payment saved even if email failed
    }
}

// Helper function to send emails
function sendPaymentEmail($toEmail, $userEmail, $packageName, $price, $transactionId, $expiresAt, $type)
{
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'scanquotient@gmail.com';
    $mail->Password = 'vnht iefe anwl xynb';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->setFrom('scanquotient@gmail.com', 'ScanQuotient');
    $mail->addAddress($toEmail);

    $mail->isHTML(true);

    if ($type === 'user') {
        $mail->Subject = 'Payment Confirmation - ScanQuotient ' . $packageName;
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <h2 style='color: #2563eb;'>Thank You for Your Purchase!</h2>
                <p>Hello,</p>
                <p>Your payment has been successfully processed.</p>
                
                <div style='background: #f0f4f8; padding: 20px; border-radius: 10px; margin: 20px 0;'>
                    <p><strong>Plan:</strong> {$packageName}</p>
                    <p><strong>Amount:</strong> $" . number_format($price, 2) . " USD</p>
                    <p><strong>Transaction ID:</strong> {$transactionId}</p>
                    <p><strong>Valid Until:</strong> " . date('F j, Y', strtotime($expiresAt)) . "</p>
                </div>
                
                <p>You now have full access to all {$packageName} features.</p>
                
                <p style='color: #666; font-size: 12px; margin-top: 30px;'>
                    Questions? Contact us at scanquotient@gmail.com
                </p>
            </div>
        ";
    } else {
        $mail->Subject = 'New Payment Received - ' . $packageName;
        $mail->Body = "
            <h2>New Payment Notification</h2>
            <p>A new payment has been received.</p>
            
            <div style='background: #f0f4f8; padding: 15px; border-radius: 8px;'>
                <p><strong>Customer:</strong> {$userEmail}</p>
                <p><strong>Package:</strong> {$packageName}</p>
                <p><strong>Amount:</strong> $" . number_format($price, 2) . " USD</p>
                <p><strong>Transaction ID:</strong> {$transactionId}</p>
                <p><strong>Expires:</strong> {$expiresAt}</p>
            </div>
        ";
    }

    $mail->send();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ScanQuotient | Payment Status</title>
    <link rel="icon" type="image/png" href="../../../../Storage/Public_images/page_icon.png" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        :root {
            --bg-primary: #f0f4f8;
            --bg-secondary: #ffffff;
            --text-primary: #1a202c;
            --text-secondary: #4a5568;
            --accent-color: #2563eb;
            --success-color: #10b981;
            --error-color: #ef4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .status-card {
            background: var(--bg-secondary);
            padding: 3rem;
            border-radius: 20px;
            text-align: center;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .icon {
            font-size: 5rem;
            margin-bottom: 1.5rem;
        }

        .success .icon {
            color: var(--success-color);
        }

        .failed .icon {
            color: var(--error-color);
        }

        h1 {
            margin-bottom: 1rem;
            font-size: 2rem;
        }

        p {
            color: var(--text-secondary);
            margin-bottom: 2rem;
        }

        .details {
            background: var(--bg-primary);
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            text-align: left;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            font-size: 0.95rem;
        }

        .btn {
            display: inline-block;
            padding: 1rem 2rem;
            background: linear-gradient(135deg, var(--accent-color), #7c3aed);
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
        }

        .btn:hover {
            transform: translateY(-2px);
        }
    </style>
</head>

<body>

    <div class="status-card <?php echo $paymentCompleted ? 'success' : 'failed'; ?>">
        <?php if ($paymentCompleted): ?>
            <div class="icon"><i class="fas fa-check-circle"></i></div>
            <h1>Payment Successful!</h1>
            <p>Thank you for subscribing to ScanQuotient.</p>

            <div class="details">
                <div class="detail-row">
                    <span>Package:</span>
                    <strong><?php echo ucfirst(htmlspecialchars($package)); ?></strong>
                </div>
                <div class="detail-row">
                    <span>Amount:</span>
                    <strong>$<?php echo number_format($price, 2); ?></strong>
                </div>
                <div class="detail-row">
                    <span>Email:</span>
                    <strong><?php echo htmlspecialchars($email); ?></strong>
                </div>
                <div class="detail-row">
                    <span>Transaction ID:</span>
                    <strong><?php echo htmlspecialchars($transactionId); ?></strong>
                </div>
            </div>

            <a href="../../../Homepage/PHP/Frontend/Homepage.php" class="btn">Go to Homepage</a>

        <?php else: ?>
            <div class="icon"><i class="fas fa-times-circle"></i></div>
            <h1>Payment Not Completed</h1>
            <p><?php echo htmlspecialchars($errorMessage ?: 'There was an issue processing your payment.'); ?></p>

            <?php if (isset($_GET['token'])): ?>
                <p style="font-size: 0.8rem; color: #999;">
                    Order ID: <?php echo htmlspecialchars($_GET['token']); ?>
                </p>
            <?php endif; ?>

            <a href="../../PHP/Frontend/Payment.php?package=<?php echo htmlspecialchars($package); ?>" class="btn">Try
                Again</a>
        <?php endif; ?>
    </div>

</body>

</html>