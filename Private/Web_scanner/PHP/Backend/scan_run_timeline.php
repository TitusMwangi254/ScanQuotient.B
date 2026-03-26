<?php
/**
 * JSON API: last N saved scans for the same target (normalized host + scheme) with
 * risk score delta vs the previous run and top new/resolved findings between consecutive runs.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'scanquotient.a1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

$userId = $_SESSION['user_uid'] ?? $_SESSION['user_id'] ?? null;
if (!$userId) {
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$target = isset($_GET['target']) ? trim((string) $_GET['target']) : '';
if ($target === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing target']);
    exit;
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 8;
$limit = max(1, min(50, $limit));

/**
 * Match scans that share the same scheme + host (path/query ignored).
 */
function sq_canonical_target(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    $p = parse_url($url);
    if (!$p || empty($p['host'])) {
        return null;
    }
    $scheme = strtolower((string) ($p['scheme'] ?? 'https'));
    if ($scheme !== 'http' && $scheme !== 'https') {
        $scheme = 'https';
    }
    $host = strtolower((string) $p['host']);

    return $scheme . '://' . $host . '/';
}

/**
 * Align with Python Vulnerability::dedup_key — stable per-finding identity in stored JSON.
 */
function sq_finding_key(array $v): string
{
    $name = strtolower(trim((string) ($v['name'] ?? '')));
    $sev = $v['severity'] ?? '';
    if (is_array($sev)) {
        $sev = $sev['value'] ?? $sev['severity'] ?? '';
    }
    $sev = strtolower(trim((string) $sev));
    $desc = substr(preg_replace('/\s+/', ' ', (string) ($v['description'] ?? '')), 0, 60);

    return md5($name . '|' . $sev . '|' . $desc);
}

function sq_severity_weight(string $sev): int
{
    $s = strtolower($sev);
    $map = [
        'critical' => 5,
        'high' => 4,
        'medium' => 3,
        'low' => 2,
        'info' => 1,
        'informational' => 1,
        'secure' => 0,
    ];

    return $map[$s] ?? 1;
}

/**
 * @return array<string, array{name:string, severity:string}>
 */
function sq_finding_map(array $vulns): array
{
    $out = [];
    foreach ($vulns as $v) {
        if (!is_array($v)) {
            continue;
        }
        $k = sq_finding_key($v);
        $sev = $v['severity'] ?? '';
        if (is_array($sev)) {
            $sev = $sev['value'] ?? $sev['severity'] ?? 'info';
        }
        $out[$k] = [
            'name' => (string) ($v['name'] ?? 'Finding'),
            'severity' => strtolower((string) $sev),
        ];
    }

    return $out;
}

/**
 * @return list<array{kind:string, label:string, severity:string}>
 */
function sq_top_changes(array $newerJson, array $olderJson, int $max = 5): array
{
    $vNew = $newerJson['vulnerabilities'] ?? [];
    $vOld = $olderJson['vulnerabilities'] ?? [];
    if (!is_array($vNew)) {
        $vNew = [];
    }
    if (!is_array($vOld)) {
        $vOld = [];
    }

    $mNew = sq_finding_map($vNew);
    $mOld = sq_finding_map($vOld);

    $changes = [];
    foreach ($mNew as $k => $row) {
        if (!isset($mOld[$k])) {
            $changes[] = ['kind' => 'new', 'label' => $row['name'], 'severity' => $row['severity']];
        }
    }
    foreach ($mOld as $k => $row) {
        if (!isset($mNew[$k])) {
            $changes[] = ['kind' => 'resolved', 'label' => $row['name'], 'severity' => $row['severity']];
        }
    }

    usort($changes, static function (array $a, array $b): int {
        $wa = sq_severity_weight($a['severity']);
        $wb = sq_severity_weight($b['severity']);
        if ($wa !== $wb) {
            return $wb <=> $wa;
        }
        if ($a['kind'] !== $b['kind']) {
            return $a['kind'] === 'new' ? -1 : 1;
        }

        return 0;
    });

    return array_slice($changes, 0, $max);
}

$canonical = sq_canonical_target($target);
if ($canonical === null) {
    echo json_encode(['ok' => false, 'error' => 'Invalid target URL']);
    exit;
}

$userPk = null;
try {
    $dsnTmp = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdoTmp = new PDO($dsnTmp, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $stmtPk = $pdoTmp->prepare('SELECT id FROM users WHERE user_id = ? LIMIT 1');
    $stmtPk->execute([$userId]);
    $rowPk = $stmtPk->fetch();
    $userPk = $rowPk['id'] ?? null;
} catch (Exception $e) {
    $userPk = null;
}

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET time_zone = '+03:00'");

    $whereSql = 'user_id = :uid';
    $params = [':uid' => $userId];
    if ($userPk !== null) {
        $whereSql = '(user_id = :uid OR user_id = :pk)';
        $params[':pk'] = (string) $userPk;
    }

    $stmt = $pdo->prepare("
        SELECT id, target_url, scan_json, created_at
        FROM scan_results
        WHERE {$whereSql}
        ORDER BY created_at DESC
        LIMIT 400
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $matched = [];
    foreach ($rows as $row) {
        $tu = (string) ($row['target_url'] ?? '');
        $c = sq_canonical_target($tu);
        if ($c !== null && $c === $canonical) {
            $matched[] = $row;
        }
        if (count($matched) >= $limit) {
            break;
        }
    }

    $runs = [];
    $n = count($matched);
    for ($i = 0; $i < $n; $i++) {
        $row = $matched[$i];
        $json = json_decode((string) ($row['scan_json'] ?? ''), true);
        if (!is_array($json)) {
            $json = [];
        }
        $summary = $json['summary'] ?? [];
        $score = isset($summary['risk_score']) ? (int) $summary['risk_score'] : 0;
        $level = (string) ($summary['risk_level'] ?? 'Unknown');
        $vulns = $json['vulnerabilities'] ?? [];
        $fc = is_array($vulns) ? count($vulns) : 0;

        $delta = null;
        $direction = null;
        $changes = [];
        if ($i + 1 < $n) {
            $older = json_decode((string) ($matched[$i + 1]['scan_json'] ?? ''), true);
            if (!is_array($older)) {
                $older = [];
            }
            $olderScore = isset($older['summary']['risk_score']) ? (int) $older['summary']['risk_score'] : 0;
            $delta = $score - $olderScore;
            if ($delta > 0) {
                $direction = 'worse';
            } elseif ($delta < 0) {
                $direction = 'better';
            } else {
                $direction = 'unchanged';
            }
            $changes = sq_top_changes($json, $older, 5);
        }

        $runs[] = [
            'scan_id' => (int) $row['id'],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'risk_score' => $score,
            'risk_level' => $level,
            'findings_count' => $fc,
            'delta_vs_previous' => $delta,
            'delta_direction' => $direction,
            'top_changes' => $changes,
        ];
    }

    echo json_encode([
        'ok' => true,
        'canonical_target' => $canonical,
        'requested_target' => $target,
        'limit' => $limit,
        'runs' => $runs,
        'total_matched' => count($runs),
    ],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('scan_run_timeline: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Unable to load timeline']);
}
