<?php
// paypal_api.php - FIXED VERSION
session_start();
header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$email = $input['email'] ?? '';
$package = $input['package'] ?? 'pro';
$price = floatval($input['price'] ?? 10.00);

// Validate
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['error' => 'Invalid email address']);
    exit;
}

// PayPal Sandbox Credentials - WORKING!
$clientId = 'AdkiLHxI45wV5xM6BXokRf_viLiugsnEmPDm2L2wlBq554tQPpIGFpTLhPjqsB4TtQd2qVS66eGUbMRT';
$clientSecret = 'EOkHTWuhomc6v5cxfforNabIY40gJr5juIxku1uajo9XDJ40ajIZteyTYPeU-nF1geb7gwQV0fBkhpdw';

// ⚠️ CRITICAL FIX: Use the API endpoint, not the website URL!
$baseUrl = 'https://api-m.sandbox.paypal.com';

// Store in session
$_SESSION['payment_email'] = $email;
$_SESSION['payment_package'] = $package;
$_SESSION['payment_price'] = $price;
$_SESSION['payment_status'] = 'pending';

// ==========================================
// STEP 1: Get Access Token
// ==========================================
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$baseUrl/v1/oauth2/token");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
curl_setopt($ch, CURLOPT_USERPWD, "$clientId:$clientSecret");
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Prefer true; if your Windows/XAMPP lacks CA bundle, you'll see the error below.

$headers = [
    "Accept: application/json",
    "Accept-Language: en_US"
];
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$response = curl_exec($ch);
$curlErrNo = curl_errno($ch);
$curlErr = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Debug logging
error_log("PayPal Auth HTTP Code: $httpCode");
error_log("PayPal Auth Response: $response");
if ($curlErrNo) {
    error_log("PayPal Auth cURL Error ($curlErrNo): $curlErr");
}

if ($response === false) {
    echo json_encode([
        'error' => 'Network error while authenticating with PayPal',
        'curl_errno' => $curlErrNo,
        'curl_error' => $curlErr
    ]);
    exit;
}

$auth = json_decode($response, true);
$accessToken = $auth['access_token'] ?? null;

if (!$accessToken) {
    echo json_encode([
        'error' => 'Failed to authenticate with PayPal',
        'http_code' => $httpCode,
        'details' => $auth
    ]);
    exit;
}

// ==========================================
// STEP 2: Create Order - FIXED URLS
// ==========================================

// Build ABSOLUTE return/cancel URLs
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$appBase = "$protocol://$host/ScanQuotient.v2/ScanQuotient.B";

// PayPal requires absolute URLs here
$returnUrl = $appBase . '/Private/Payment/PHP/Backend/payment_success1.php?status=success';
$cancelUrl = $appBase . '/Private/Payment/PHP/Frontend/Payment1.php?package=' . urlencode($package) . '&status=cancelled';

$orderData = [
    'intent' => 'CAPTURE',
    'purchase_units' => [
        [
            'amount' => [
                'currency_code' => 'USD',
                'value' => number_format($price, 2, '.', '')
            ],
            'description' => 'ScanQuotient ' . ucfirst($package) . ' Subscription',
            'custom_id' => json_encode(['email' => $email, 'package' => $package, 'session_id' => session_id()])
        ]
    ],
    'application_context' => [
        'brand_name' => 'ScanQuotient',
        'landing_page' => 'BILLING',
        'shipping_preference' => 'NO_SHIPPING',
        'user_action' => 'PAY_NOW',
        'return_url' => $returnUrl,
        'cancel_url' => $cancelUrl
    ]
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$baseUrl/v2/checkout/orders");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($orderData));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$headers = [
    "Content-Type: application/json",
    "Authorization: Bearer $accessToken"
];
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$response = curl_exec($ch);
$curlErrNo = curl_errno($ch);
$curlErr = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Debug logging
error_log("PayPal Order HTTP Code: $httpCode");
error_log("PayPal Order Response: $response");
if ($curlErrNo) {
    error_log("PayPal Order cURL Error ($curlErrNo): $curlErr");
}

if ($response === false) {
    echo json_encode([
        'error' => 'Network error while creating PayPal order',
        'curl_errno' => $curlErrNo,
        'curl_error' => $curlErr
    ]);
    exit;
}

$order = json_decode($response, true);

if (isset($order['id'])) {
    $_SESSION['paypal_order_id'] = $order['id'];

    $approvalUrl = '';
    foreach ($order['links'] as $link) {
        if ($link['rel'] === 'approve') {
            $approvalUrl = $link['href'];
            break;
        }
    }

    if ($approvalUrl) {
        echo json_encode([
            'success' => true,
            'order_id' => $order['id'],
            'approval_url' => $approvalUrl
        ]);
    } else {
        echo json_encode(['error' => 'Approval URL not found in PayPal response']);
    }
} else {
    echo json_encode([
        'error' => 'Failed to create PayPal order',
        'http_code' => $httpCode,
        'details' => $order
    ]);
}
?>