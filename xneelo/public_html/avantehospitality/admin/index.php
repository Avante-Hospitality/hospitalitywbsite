<?php

/*
 * Form submissions admin.
 *
 * Reached at /admin/ - the "Admin" link in the site footer points there, and the
 * .htaccess rule keeps it on that address rather than exposing the site folder.
 */

// The application usually sits inside the site folder, but it also works from
// beside that folder or beside public_html.
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
