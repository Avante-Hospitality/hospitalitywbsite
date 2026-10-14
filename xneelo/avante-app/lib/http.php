<?php

/*
 * Small request/response helpers shared by the entry points.
 */

function avante_escape_html($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function avante_base64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function avante_secure_request(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $proto === 'https';
}

function avante_client_ip(): string
{
    if (avante_config_get('trust_proxy_headers', false)) {
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if ($first !== '') {
                return $first;
            }
        }
    }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip !== '' ? $ip : 'unknown';
}

function avante_json_response(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
}

function avante_read_json_body(int $maxBytes = 32768): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    if (strlen($raw) > $maxBytes) {
        throw new RuntimeException('Request body too large');
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function avante_cors_origin(): string
{
    return trim((string) avante_config_get('cors_origin', ''));
}

/* Only accept POSTs that came from this same host (blocks cross-site attempts). */
function avante_same_origin(): bool
{
    $source = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($source === '') {
        $source = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    }
    if ($source === '') {
        return true;
    }
    $sourceHost = parse_url($source, PHP_URL_HOST);
    if (!is_string($sourceHost) || $sourceHost === '') {
        return false;
    }
    return strcasecmp($sourceHost, (string) ($_SERVER['HTTP_HOST'] ?? '')) === 0;
}

/*
 * Sends the browser back to the correct URL for the admin panel, which may be
 * installed at /admin/ or in a subdirectory of a multi-domain account.
 */
function avante_admin_url(string $query = ''): string
{
    $url = 'index.php';
    if ($query !== '') {
        $url .= '?' . $query;
    }
    return $url;
}
