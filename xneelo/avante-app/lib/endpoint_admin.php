<?php

/*
 * Admin page - the PHP counterpart of server/admin.js.
 *
 * Reached at /admin/ (public_html/admin/index.php). Sign-in uses a signed
 * cookie, so there is no server-side session storage to configure.
 */

const AVANTE_ADMIN_COOKIE = 'avante_admin';
const AVANTE_ADMIN_SESSION_TTL = 43200; // 12 hours

function avante_admin_enabled(): bool
{
    return (string) avante_config_get('admin.password', '') !== '';
}

function avante_admin_secret(): string
{
    $secret = (string) avante_config_get('admin.secret', '');
    return $secret !== '' ? $secret : (string) avante_config_get('admin.password', '');
}

function avante_admin_sign(string $value): string
{
    return avante_base64url(hash_hmac('sha256', $value, avante_admin_secret(), true));
}

function avante_admin_issue_token(): string
{
    $expires = (string) ((int) round(microtime(true) * 1000) + AVANTE_ADMIN_SESSION_TTL * 1000);
    return $expires . '.' . avante_admin_sign($expires);
}

function avante_admin_token_valid($token): bool
{
    if (!is_string($token) || $token === '' || !str_contains($token, '.')) {
        return false;
    }
    [$expires, $mac] = explode('.', $token, 2);
    if ($expires === '' || $mac === '' || !ctype_digit($expires)) {
        return false;
    }
    if (!hash_equals(avante_admin_sign($expires), $mac)) {
        return false;
    }
    return (int) $expires > (int) round(microtime(true) * 1000);
}

function avante_admin_password_matches($input): bool
{
    $expected = (string) avante_config_get('admin.password', '');
    if ($expected === '') {
        return false;
    }
    // Hash both sides first so the comparison does not leak the password length.
    return hash_equals(hash('sha256', (string) $input), hash('sha256', $expected));
}

function avante_admin_authenticated(): bool
{
    return avante_admin_enabled() && avante_admin_token_valid($_COOKIE[AVANTE_ADMIN_COOKIE] ?? null);
}

function avante_admin_set_cookie(): void
{
    $token = avante_admin_issue_token();
    setcookie(AVANTE_ADMIN_COOKIE, $token, [
        'expires' => time() + AVANTE_ADMIN_SESSION_TTL,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => avante_secure_request(),
    ]);
    $_COOKIE[AVANTE_ADMIN_COOKIE] = $token;
}

function avante_admin_clear_cookie(): void
{
    setcookie(AVANTE_ADMIN_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => avante_secure_request(),
    ]);
    unset($_COOKIE[AVANTE_ADMIN_COOKIE]);
}

/*
 * A full URL is used for redirects. It is built from the address the browser
 * asked for (REQUEST_URI) rather than SCRIPT_NAME, because a rewrite rule may
 * have pointed the request at the site's own folder on disk - which must never
 * appear in the address bar.
 */
function avante_admin_absolute_url(string $query = ''): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/admin/'), PHP_URL_PATH);
    if ($path === '') {
        $path = '/admin/';
    }
    if (!str_ends_with($path, '/')) {
        // /admin -> /admin/ and /admin/index.php -> /admin/
        $path = str_ends_with($path, '.php') ? rtrim(dirname($path), '/\\') . '/' : $path . '/';
    }

    $url = (avante_secure_request() ? 'https://' : 'http://')
        . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost') . $path;

    return $query !== '' ? $url . '?' . $query : $url;
}

function avante_admin_form_filter(): ?string
{
    $type = $_GET['type'] ?? '';
    return (is_string($type) && $type !== '' && isset(avante_forms()[$type])) ? $type : null;
}

function avante_admin_redirect(string $query = ''): void
{
    header('Location: ' . avante_admin_absolute_url($query), true, 302);
}

function avante_run_admin_endpoint(): void
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    // No scripts are used anywhere on these pages, so none are allowed.
    header(
        "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; "
        . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
    );

    if (!avante_admin_enabled()) {
        http_response_code(503);
        echo avante_admin_disabled_page();
        return;
    }

    $action = (string) ($_GET['action'] ?? '');
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($action === 'login') {
        avante_admin_handle_login($method);
        return;
    }
    if ($action === 'logout') {
        avante_admin_clear_cookie();
        avante_admin_redirect('action=login');
        return;
    }

    if (!avante_admin_authenticated()) {
        avante_admin_redirect('action=login');
        return;
    }

    if ($action === 'export') {
        avante_admin_handle_export();
        return;
    }
    if ($action === 'diagnostics') {
        avante_admin_handle_diagnostics($method);
        return;
    }

    echo avante_admin_list_page(avante_admin_form_filter(), (int) ($_GET['page'] ?? 1));
}

function avante_admin_handle_login(string $method): void
{
    if (avante_admin_authenticated()) {
        avante_admin_redirect();
        return;
    }

    if ($method !== 'POST') {
        echo avante_admin_login_page(null);
        return;
    }

    if (!avante_same_origin()) {
        http_response_code(403);
        echo avante_admin_forbidden_page();
        return;
    }

    if (avante_login_rate_limited(avante_client_ip())) {
        http_response_code(429);
        echo avante_admin_login_page('Too many attempts. Please wait 15 minutes and try again.');
        return;
    }

    if (!avante_admin_password_matches($_POST['password'] ?? '')) {
        http_response_code(401);
        echo avante_admin_login_page('Incorrect password.');
        return;
    }

    avante_admin_set_cookie();
    avante_admin_redirect();
}

function avante_admin_handle_export(): void
{
    $formType = avante_admin_form_filter();
    $rows = avante_list_submissions($formType, 5000, 0);
    $filename = 'avante-submissions-' . ($formType !== null ? $formType . '-' : '') . gmdate('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo avante_submissions_csv($rows);
}

function avante_admin_diagnostics_report(): array
{
    $report = [];
    $report['PHP version'] = PHP_VERSION;

    $db = avante_db();
    $report['Database name'] = (string) avante_config_get('db.name', '');
    try {
        $submissions = avante_count_submissions();
        $report['Submissions stored'] = (string) $submissions;
        $report['Throttle table'] = 'ok';
    } catch (Throwable $e) {
        $report['Submissions stored'] = 'ERROR - ' . $e->getMessage();
        $report['Throttle table'] = 'not checked';
    }

    $report['Email configured'] = avante_mail_configured() ? 'yes' : 'no - forms will save but not email';
    $report['SMTP server'] = trim((string) avante_config_get('smtp.host', '')) !== ''
        ? (string) avante_config_get('smtp.host', '') . ':' . (string) avante_config_get('smtp.port', '')
        : '(not set)';
    $report['SMTP username'] = trim((string) avante_config_get('smtp.user', '')) !== ''
        ? (string) avante_config_get('smtp.user', '')
        : '(not set)';
    $report['Mail from'] = (string) avante_config_get('mail.from', '');
    $report['Mail to'] = (string) avante_config_get('mail.to', '');
    $report['Mail cc'] = (string) avante_config_get('mail.cc', '');
    $report['Retention (days)'] = (string) avante_config_get('retention_days', 730);
    $report['Secure (HTTPS) request'] = avante_secure_request() ? 'yes' : 'no';

    return $report;
}

function avante_admin_handle_diagnostics(string $method): void
{
    $test = null;

    if ($method === 'POST' && ($_POST['do'] ?? '') === 'test-email') {
        if (!avante_same_origin()) {
            http_response_code(403);
            echo avante_admin_forbidden_page();
            return;
        }

        $recipient = trim((string) ($_POST['to'] ?? ''));
        if ($recipient === '') {
            $recipient = trim((string) avante_config_get('mail.to', ''));
        }

        if (!preg_match(AVANTE_EMAIL_RE, $recipient)) {
            $test = ['status' => 'failed', 'error' => 'That does not look like an email address.'];
        } else {
            $test = avante_send_submission_email(
                [
                    'property_name' => 'Test Property',
                    'contact_person' => 'Avante test',
                    'phone' => '000 000 0000',
                    'email' => 'test@example.com',
                    'note' => 'This is a test message sent from the admin page.',
                ],
                'Avante website test email',
                ['label' => 'Test email', 'email' => $recipient],
                0,
                $recipient
            );
        }
    }

    echo avante_admin_diagnostics_page(avante_admin_diagnostics_report(), $test);
}
