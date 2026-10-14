<?php

/*
 * Form submissions admin.
 *
 * Reached at /admin/ - the "Admin" link in the site footer points there, and
 * Apache redirects /admin to /admin/ so the index file is served.
 */

// The application normally sits in an avante-app folder in the site root, but it
// also works from beside public_html, or one level further up.
$bootstrap = '';
foreach ([
    __DIR__ . '/../avante-app/bootstrap.php',
    __DIR__ . '/../../avante-app/bootstrap.php',
    __DIR__ . '/../../../avante-app/bootstrap.php',
] as $candidate) {
    if (is_file($candidate)) {
        $bootstrap = $candidate;
        break;
    }
}

if ($bootstrap === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The form application is not installed. Upload the avante-app folder into public_html (see the README).';
    return;
}

require $bootstrap;

try {
    avante_run_admin_endpoint();
} catch (Throwable $e) {
    error_log('[avante] admin page failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The admin page could not start. Check the website error log, then see the README.';
}
