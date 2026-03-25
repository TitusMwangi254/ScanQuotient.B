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
$name = isset($input['name']) ? trim($input['name']) : '';
if ($name === '') {
    echo json_encode(['ok' => false, 'error' => 'Group name is required']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scan_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(64) NOT NULL,
            name VARCHAR(128) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $pdo->exec("ALTER TABLE scan_results ADD COLUMN group_id INT NULL DEFAULT NULL");
        $pdo->exec("ALTER TABLE scan_results ADD INDEX idx_group (group_id)");
    } catch (Exception $e) { /* column may exist */ }

    $stmt = $pdo->prepare("INSERT INTO scan_groups (user_id, name) VALUES (?, ?)");
    $stmt->execute([$user_id, $name]);
    $id = (int) $pdo->lastInsertId();
    echo json_encode(['ok' => true, 'id' => $id, 'name' => $name]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Failed to create group']);
}
