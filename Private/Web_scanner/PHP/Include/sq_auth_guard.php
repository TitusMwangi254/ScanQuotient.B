<?php
/**
 * Shared authentication guard for Web Scanner private endpoints.
 */

if (!function_exists('sq_require_web_scanner_auth')) {
    /**
     * Enforce authenticated session with allowed role.
     */
    function sq_require_web_scanner_auth(bool $json = true): void
    {
        $allowedRoles = ['user', 'admin', 'super_admin'];
        $ok = isset($_SESSION['authenticated'], $_SESSION['role'])
            && $_SESSION['authenticated'] === true
            && in_array((string) $_SESSION['role'], $allowedRoles, true);

        if ($ok) {
            return;
        }

        if (!headers_sent()) {
            http_response_code(401);
            if ($json) {
                header('Content-Type: application/json; charset=utf-8');
            }
        }

        if ($json) {
            echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        } else {
            echo 'Unauthorized';
        }
        exit;
    }
}

