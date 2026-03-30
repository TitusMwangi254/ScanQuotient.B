<?php
if (headers_sent()) {
    return;
}

// Keep CSP permissive enough for current CDN/inline-heavy pages, but present.
$csp = implode('; ', [
    "default-src 'self' https: data: blob:",
    "script-src 'self' https: 'unsafe-inline' 'unsafe-eval' blob:",
    "style-src 'self' https: 'unsafe-inline'",
    "img-src 'self' https: data: blob:",
    "font-src 'self' https: data:",
    "connect-src 'self' https: ws: wss:",
    "frame-ancestors 'self'",
    "base-uri 'self'",
    "form-action 'self' https:",
]);

header("Content-Security-Policy: {$csp}");
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// HSTS should only be sent on HTTPS responses.
$isHttps = (
    (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (string) ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https'
    || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
);
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

