<?php
/**
 * Username helpers for surname-based auto-assignment and duplicate-safe login.
 */

/**
 * Normalize a surname into a base username (lowercase, trimmed).
 */
function sq_username_base_from_surname(string $surname): string
{
    $base = strtolower(trim($surname));
    return $base !== '' ? $base : 'user';
}

/**
 * Allocate a unique username from a surname.
 * Uses the plain surname when free; otherwise appends a random suffix (e.g. kamau_a3f9).
 */
function sq_allocate_unique_username(PDO $pdo, string $surname): string
{
    $base = sq_username_base_from_surname($surname);
    $check = $pdo->prepare('SELECT id FROM users WHERE LOWER(user_name) = LOWER(?) LIMIT 1');

    $check->execute([$base]);
    if (!$check->fetch()) {
        return $base;
    }

    for ($attempt = 0; $attempt < 25; $attempt++) {
        $suffix = substr(bin2hex(random_bytes(2)), 0, 4);
        $candidate = $base . '_' . $suffix;
        $check->execute([$candidate]);
        if (!$check->fetch()) {
            return $candidate;
        }
    }

    return $base . '_' . substr(uniqid('', true), -6);
}

/**
 * Find the user row matching username + password when multiple accounts share a username.
 */
function sq_authenticate_user_by_username(PDO $pdo, string $username, string $password): ?array
{
    $username = strtolower(trim($username));
    if ($username === '') {
        password_verify($password, '$2y$10$dummyhashforconstantimetimingattackprevention');
        return null;
    }

    $stmt = $pdo->prepare('
        SELECT * FROM users
        WHERE LOWER(TRIM(user_name)) = LOWER(TRIM(:username))
        ORDER BY id DESC
    ');
    $stmt->execute([':username' => $username]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($candidates as $candidate) {
        if (password_verify($password, $candidate['password_hash'])) {
            return $candidate;
        }
    }

    password_verify($password, '$2y$10$dummyhashforconstantimetimingattackprevention');
    return null;
}
