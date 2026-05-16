<?php
/**
 * Shared certificate target types and SQL matching for login gating.
 */

function sq_certificate_valid_target_types(): array
{
    return ['everyone', 'admins', 'users', 'user_id', 'username', 'role'];
}

function sq_certificate_migrate_target_schema(PDO $pdo): void
{
    try {
        $pdo->exec("
            ALTER TABLE security_certificates
            MODIFY target_type ENUM('everyone','admins','users','role','user_id','username')
            NOT NULL DEFAULT 'everyone'
        ");
    } catch (Throwable $e) {
        // Table may not exist yet or ENUM already updated.
    }

    try {
        $pdo->exec("
            UPDATE security_certificates
            SET target_type = 'admins', target_value = NULL
            WHERE target_type = 'role' AND LOWER(TRIM(target_value)) IN ('admin', 'super_admin')
        ");
        $pdo->exec("
            UPDATE security_certificates
            SET target_type = 'users', target_value = NULL
            WHERE target_type = 'role' AND LOWER(TRIM(target_value)) = 'user'
        ");
    } catch (Throwable $e) {
    }
}

/**
 * Normalize posted certificate target fields.
 *
 * @return array{0: string, 1: string}
 */
function sq_certificate_normalize_target(string $targetType, string $targetValue): array
{
    $targetType = strtolower(trim($targetType));
    $targetValue = trim($targetValue);

    if (!in_array($targetType, sq_certificate_valid_target_types(), true)) {
        $targetType = 'everyone';
    }

    if (in_array($targetType, ['everyone', 'admins', 'users'], true)) {
        return [$targetType, ''];
    }

    if ($targetType === 'role') {
        $role = strtolower($targetValue);
        if (in_array($role, ['admin', 'super_admin'], true)) {
            return ['admins', ''];
        }
        if ($role === 'user') {
            return ['users', ''];
        }
    }

    return [$targetType, $targetValue];
}

/**
 * @return array{0: string, 1: string}
 */
function sq_certificate_sanitize_text_fields(string $title, string $body): array
{
    $title = trim(strip_tags($title));
    $body = trim(strip_tags($body));
    $title = preg_replace('/\s+/u', ' ', $title) ?? $title;

    return [$title, $body];
}

function sq_certificate_validate_text_fields(string $title, string $body): ?string
{
    if ($title === '') {
        return 'Certificate title cannot be blank.';
    }
    if ($body === '') {
        return 'Certificate details cannot be blank.';
    }
    if (mb_strlen($title) > 255) {
        return 'Certificate title must be 255 characters or fewer.';
    }

    return null;
}

function sq_certificate_resolve_target(string $targetType, string $targetValue, ?PDO $pdo = null): array
{
    [$targetType, $targetValue] = sq_certificate_normalize_target($targetType, $targetValue);

    if ($targetType === 'user_id') {
        if ($targetValue === '') {
            return [null, null, 'User ID is required when targeting a specific account.'];
        }
        $targetValue = strtoupper(preg_replace('/\s+/', '', $targetValue));
        if (!preg_match('/^UID[A-Z0-9]{7}$/', $targetValue)) {
            return [null, null, 'User ID must be in the format UID plus 7 letters or digits (e.g. UIDWRB3O1P).'];
        }
        if ($pdo !== null) {
            $stmt = $pdo->prepare('SELECT 1 FROM users WHERE user_id = ? LIMIT 1');
            $stmt->execute([$targetValue]);
            if (!$stmt->fetchColumn()) {
                return [null, null, 'No account exists with that user ID.'];
            }
        }
    } elseif ($targetType === 'username') {
        if ($targetValue === '') {
            return [null, null, 'Username is required when targeting a specific account.'];
        }
        $targetValue = strtolower(preg_replace('/\s+/', '', $targetValue));
        if (!preg_match('/^[a-z0-9_]{2,64}$/', $targetValue)) {
            return [null, null, 'Username must be 2–64 lowercase letters, numbers, or underscores.'];
        }
        if ($pdo !== null) {
            $stmt = $pdo->prepare('SELECT 1 FROM users WHERE user_name = ? LIMIT 1');
            $stmt->execute([$targetValue]);
            if (!$stmt->fetchColumn()) {
                return [null, null, 'No account exists with that username.'];
            }
        }
    }

    return [$targetType, $targetValue, null];
}

/**
 * Role/target matching for pending certificates.
 * Each placeholder is unique — required for PDO native prepares (EMULATE_PREPARES=false).
 */
function sq_certificate_applies_where_sql(): string
{
    return "
        c.target_type = 'everyone'
        OR (c.target_type = 'admins' AND :role_for_admins IN ('admin', 'super_admin'))
        OR (c.target_type = 'users' AND :role_for_users = 'user')
        OR (c.target_type = 'role' AND c.target_value = :role_for_legacy)
        OR (c.target_type = 'user_id' AND c.target_value = :uid_for_match)
        OR (c.target_type = 'username' AND c.target_value = :uname_for_match)
    ";
}

/**
 * @param array{user_id?: string, user_name?: string, role?: string} $user
 * @return array<string, string>
 */
function sq_certificate_pending_bind_params(array $user): array
{
    $role = strtolower(trim((string) ($user['role'] ?? '')));
    if ($role === '') {
        $role = 'user';
    }
    $uid = trim((string) ($user['user_id'] ?? ''));
    $uname = trim((string) ($user['user_name'] ?? ''));

    return [
        ':role_for_admins' => $role,
        ':role_for_users' => $role,
        ':role_for_legacy' => $role,
        ':uid_for_match' => $uid,
        ':uname_for_match' => $uname,
        ':uid_check' => $uid,
    ];
}

function sq_certificate_pending_select_sql(): string
{
    return "
        SELECT c.id
        FROM security_certificates c
        WHERE c.is_active = 'yes'
          AND c.deleted_at IS NULL
          AND (
                " . sq_certificate_applies_where_sql() . "
          )
          AND NOT EXISTS (
                SELECT 1
                FROM security_certificate_acceptances a
                WHERE a.certificate_id = c.id AND a.user_id = :uid_check
          )
        ORDER BY c.created_at DESC
        LIMIT 1
    ";
}

/**
 * @param array{user_id?: string, user_name?: string, role?: string} $user
 */
function sq_certificate_find_pending_id(PDO $pdo, array $user): ?int
{
    sq_certificate_migrate_target_schema($pdo);
    $stmt = $pdo->prepare(sq_certificate_pending_select_sql());
    $stmt->execute(sq_certificate_pending_bind_params($user));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? (int) $row['id'] : null;
}

function sq_certificate_format_target_label(string $targetType, ?string $targetValue = null): string
{
    $targetValue = trim((string) $targetValue);
    $labels = [
        'everyone' => 'Everyone',
        'admins' => 'Admins',
        'users' => 'Normal users',
        'user_id' => 'Specific user ID',
        'username' => 'Specific username',
        'role' => 'Role',
    ];

    $label = $labels[$targetType] ?? $targetType;
    if ($targetValue !== '' && !in_array($targetType, ['everyone', 'admins', 'users'], true)) {
        return $label . ': ' . $targetValue;
    }

    return $label;
}
