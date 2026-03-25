<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

$user_id = $_SESSION['user_uid'] ?? $_SESSION['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
$scan_id = isset($input['scan_id']) ? (int) $input['scan_id'] : 0;
$group_id = isset($input['group_id']) ? ($input['group_id'] === null || $input['group_id'] === '' ? null : (int) $input['group_id']) : null;

if ($scan_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid scan id']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    try {
        $pdo->exec("ALTER TABLE scan_results ADD COLUMN group_id INT NULL DEFAULT NULL");
    } catch (Exception $e) { /* column may exist */ }

    if ($group_id !== null) {
        $chk = $pdo->prepare("SELECT id FROM scan_groups WHERE id = ? AND user_id = ?");
        $chk->execute([$group_id, $user_id]);
        if (!$chk->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Group not found']);
            exit;
        }
    }

    $stmt = $pdo->prepare("UPDATE scan_results SET group_id = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([$group_id, $scan_id, $user_id]);
    if ($stmt->rowCount() === 0) {
        echo json_encode(['ok' => false, 'error' => 'Scan not found']);
        exit;
    }
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Failed to update']);
}
