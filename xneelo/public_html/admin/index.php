<?php

/*
 * Form submissions admin.
 *
 * Reached at /admin/ - the "Admin" link in the site footer points at /admin,
 * which Apache redirects to /admin/ for you.
 */

$bootstrap = __DIR__ . '/../../avante-app/bootstrap.php';
if (!is_file($bootstrap)) {
    $bootstrap = __DIR__ . '/../avante-app/bootstrap.php';
}

if (!is_file($bootstrap)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The form application is not installed: upload the avante-app folder next to public_html.';
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
