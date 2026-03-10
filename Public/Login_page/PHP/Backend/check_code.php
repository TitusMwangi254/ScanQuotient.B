<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['fp_code'])) {
    echo json_encode(['status' => 'error', 'message' => 'No code']);
    exit();
}

$code = $_POST['code'] ?? '';

if (time() > $_SESSION['fp_expires']) {
    echo json_encode(['status' => 'error', 'message' => 'Code expired']);
    exit();
}

if ($code !== $_SESSION['fp_code']) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid code']);
    exit();
}

$_SESSION['fp_verified'] = true;
echo json_encode(['status' => 'success']);
?>