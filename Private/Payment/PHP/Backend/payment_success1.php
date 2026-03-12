<?php
// payment_success.php - Handle PayPal return and save to database
session_start();

require 'C:/Users/1/vendor/autoload.php';  // PHPMailer autoload

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


// Get status + token (PayPal returns order id as `token`)
$status = $_GET['status'] ?? 'unknown';
$tokenOrderId = $_GET['token'] ?? null;

// Session can be missing on PayPal cross-site redirect (SameSite), so use token as fallback
$email = $_SESSION['payment_email'] ?? null;
$package = $_SESSION['payment_package'] ?? 'pro';
$price = $_SESSION['payment_price'] ?? 0.00;
$sessionOrderId = $_SESSION['paypal_order_id'] ?? null;
$orderId = $sessionOrderId ?: $tokenOrderId;

// PayPal credentials (match the ones used by paypal_api234.php)
$clientId = 'AdkiLHxI45wV5xM6BXokRf_viLiugsnEmPDm2L2wlBq554tQPpIGFpTLhPjqsB4TtQd2qVS66eGUbMRT';
$clientSecret = 'EOkHTWuhomc6v5cxfforNabIY40gJr5juIxku1uajo9XDJ40ajIZteyTYPeU-nF1geb7gwQV0fBkhpdw';
$baseUrl = 'https://api-m.sandbox.paypal.com';

$paymentCompleted = false;
$transactionId = 'PENDING';
$errorMessage = '';

// If coming back from PayPal with success, capture the payment
if ($status === 'success' && $orderId) {
    // Get access token
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "$baseUrl/v1/oauth2/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
    curl_setopt($ch, CURLOPT_USERPWD, "$clientId:$clientSecret");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $headers = ["Accept: application/json", "Accept-Language: en_US"];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $curlErrNo = curl_errno($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        $errorMessage = "Network error while authenticating with PayPal: " . ($curlErr ?: "cURL error $curlErrNo");
    }

    $auth = json_decode($response, true);
    $accessToken = $auth['access_token'] ?? null;

    if ($accessToken) {
        // Capture the payment
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$baseUrl/v2/checkout/orders/$orderId/capture");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer $accessToken"
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $errorMessage = "Network error while capturing PayPal payment: " . ($curlErr ?: "cURL error $curlErrNo");
        }

        $capture = json_decode($response, true);

        $captureStatus = $capture['status'] ?? null;
        $paypalErrorName = $capture['name'] ?? null; // e.g. ORDER_ALREADY_CAPTURED

        if ($captureStatus === 'COMPLETED' || $paypalErrorName === 'ORDER_ALREADY_CAPTURED') {
            $paymentCompleted = true;
            $transactionId = $capture['purchase_units'][0]['payments']['captures'][0]['id'] ?? 'UNKNOWN';

            // Recover email/package/price from PayPal custom_id if session is missing
            $customIdRaw = $capture['purchase_units'][0]['custom_id'] ?? null;
            if (!$customIdRaw) {
                // Fallback: fetch order details (also helps for ORDER_ALREADY_CAPTURED)
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "$baseUrl/v2/checkout/orders/$orderId");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Accept: application/json",
                    "Authorization: Bearer $accessToken"
                ]);
                $detailsResponse = curl_exec($ch);
                curl_close($ch);
                $details = json_decode($detailsResponse ?: '', true);
                $customIdRaw = $details['purchase_units'][0]['custom_id'] ?? null;
                if ($transactionId === 'UNKNOWN') {
                    $transactionId = $details['purchase_units'][0]['payments']['captures'][0]['id'] ?? $transactionId;
                }
                if (!$email && isset($details['payer']['email_address'])) {
                    $email = $details['payer']['email_address'];
                }
                if (!$price && isset($details['purchase_units'][0]['amount']['value'])) {
                    $price = floatval($details['purchase_units'][0]['amount']['value']);
                }
            }

            if ($customIdRaw) {
                $custom = json_decode($customIdRaw, true);
                if (is_array($custom)) {
                    $email = $email ?: ($custom['email'] ?? null);
                    $package = $custom['package'] ?? $package;
                }
            }

            if (!$price && isset($capture['purchase_units'][0]['payments']['captures'][0]['amount']['value'])) {
                $price = floatval($capture['purchase_units'][0]['payments']['captures'][0]['amount']['value']);
            }

            // Save to database here
            if ($email) {
                savePaymentToDatabase($email, $package, $price, $transactionId);
            } else {
                // Payment completed, but we couldn't map it to a user email reliably
                $errorMessage = "Payment completed, but we couldn't confirm the account email. Please contact support with Order ID: " . htmlspecialchars($orderId);
            }
        } else {
            $errorMessage = $capture['message'] ?? 'Payment capture failed';
        }
    }
} elseif ($status === 'cancelled') {
    $errorMessage = 'Payment was cancelled.';
} else {
    $errorMessage = $errorMessage ?: 'Missing PayPal order details. Please try again.';
}
// Database save function with email notifications
function savePaymentToDatabase($email, $package, $price, $transactionId)
{
    try {
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

        $pdo = new PDO($dsn, $user, $pass, $options);

        // Calculate expiration date (exactly 1 month from now)
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 month'));
        $createdAt = date('Y-m-d H:i:s');

        // Insert payment record (include status/payment_method/account_status to match table)
        $stmt = $pdo->prepare("
            INSERT INTO payments (email, package, account_status, amount, transaction_id, status, payment_method, expires_at, created_at)
            VALUES (?, ?, 'active', ?, ?, 'completed', 'paypal', ?, ?)
        ");
        $stmt->execute([$email, $package, $price, $transactionId, $expiresAt, $createdAt]);

        // --- Send Email Notifications ---
        $adminEmail = 'scanquotient@gmail.com';
        $packageName = ucfirst($package) === 'Pro' ? 'Pro' : 'Enterprise Suite';

        // Send to User
        sendPaymentEmail($email, $email, $packageName, $price, $transactionId, $expiresAt, 'user');

        // Send to Admin
        sendPaymentEmail($adminEmail, $email, $packageName, $price, $transactionId, $expiresAt, 'admin');

        return true;

    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return false;
    } catch (Exception $e) {
        error_log("Email error: " . $e->getMessage());
        // Payment saved but email failed - still return true or handle as needed
        return true;
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
        // Email to Customer
        $mail->Subject = 'Payment Confirmation - ScanQuotient ' . $packageName;
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <h2 style='color: #2563eb;'>Thank You for Your Purchase!</h2>
                <p>Hello,</p>
                <p>Your payment has been successfully processed. Here are your subscription details:</p>
                
                <div style='background: #f0f4f8; padding: 20px; border-radius: 10px; margin: 20px 0;'>
                    <p><strong>Plan:</strong> {$packageName}</p>
                    <p><strong>Amount:</strong> $" . number_format($price, 2) . " USD</p>
                    <p><strong>Transaction ID:</strong> {$transactionId}</p>
                    <p><strong>Valid Until:</strong> " . date('F j, Y', strtotime($expiresAt)) . "</p>
                </div>
                
                <p>You now have full access to all {$packageName} features. Login to your account to get started.</p>
                
                <p style='color: #666; font-size: 12px; margin-top: 30px;'>
                    If you have any questions, please contact us at scanquotient@gmail.com
                </p>
            </div>
        ";
    } else {
        // Email to Admin
        $mail->Subject = 'New Payment Received - ' . $packageName;
        $mail->Body = "
            <h2>New Payment Notification</h2>
            <p>A new payment has been received.</p>
            
            <div style='background: #f0f4f8; padding: 15px; border-radius: 8px;'>
                <p><strong>Customer Email:</strong> {$userEmail}</p>
                <p><strong>Package:</strong> {$packageName}</p>
                <p><strong>Amount:</strong> $" . number_format($price, 2) . " USD</p>
                <p><strong>Transaction ID:</strong> {$transactionId}</p>
                <p><strong>Expires At:</strong> {$expiresAt}</p>
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
    <title>Payment Status - ScanQuotient</title>
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
                <div class="detail-row"><span>Package:</span><strong>
                        <?php echo ucfirst($package); ?>
                    </strong></div>
                <div class="detail-row"><span>Amount:</span><strong>$
                        <?php echo number_format($price, 2); ?>
                    </strong></div>
                <div class="detail-row"><span>Email:</span><strong>
                        <?php echo htmlspecialchars($email); ?>
                    </strong></div>
                <div class="detail-row"><span>Transaction ID:</span><strong>
                        <?php echo $transactionId; ?>
                    </strong></div>
            </div>

            <a href="/ScanQuotient.v2/ScanQuotient.B/Private/User_account/PHP/Frontend/User_subscription.php"
                class="btn">Back to Dashboard</a>

        <?php else: ?>
            <div class="icon"><i class="fas fa-times-circle"></i>
            </div>
            <h1>Payment Not Completed</h1>
            <p>
                <?php echo $errorMessage ?: 'There was an issue processing your payment.'; ?>
            </p>
            <a href="../../PHP/Frontend/payment.php?package=<?php echo $package; ?>" class="btn">Try Again</a>
        <?php endif; ?>
    </div>
</body>

</html>