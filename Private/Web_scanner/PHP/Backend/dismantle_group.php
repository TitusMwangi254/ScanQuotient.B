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
$group_id = isset($input['group_id']) ? (int) $input['group_id'] : 0;
if ($group_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid group']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

    $stmt = $pdo->prepare("SELECT id FROM scan_groups WHERE id = ? AND user_id = ?");
    $stmt->execute([$group_id, $user_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Group not found']);
        exit;
    }

    $pdo->prepare("UPDATE scan_results SET group_id = NULL WHERE group_id = ? AND user_id = ?")->execute([$group_id, $user_id]);
    $pdo->prepare("DELETE FROM scan_groups WHERE id = ? AND user_id = ?")->execute([$group_id, $user_id]);

    echo json_encode(['ok' => true, 'message' => 'Group dismantled; scans are now ungrouped']);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Failed to dismantle group']);
}
