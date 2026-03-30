<?php
/**
 * Shared target normalization for timeline API and history filtering.
 */

declare(strict_types=1);

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
 * SQL LIKE prefixes (scheme + host + %) for matching stored target_url rows.
 *
 * @return list<string>
 */
function sq_target_url_like_prefixes(?string $canonical): array
{
    if ($canonical === null || $canonical === '') {
        return [];
    }
    $p = parse_url($canonical);
    if (!$p || empty($p['host'])) {
        return [];
    }
    $host = strtolower((string) $p['host']);
    $hosts = [$host];
    if (strpos($host, 'www.') === 0) {
        $hosts[] = substr($host, 4);
    } else {
        $hosts[] = 'www.' . $host;
    }
    $hosts = array_values(array_unique(array_filter($hosts)));
    $patterns = [];
    foreach ($hosts as $h) {
        foreach (['https://', 'http://'] as $sch) {
            $patterns[] = $sch . $h . '%';
        }
    }

    return array_values(array_unique($patterns));
}
