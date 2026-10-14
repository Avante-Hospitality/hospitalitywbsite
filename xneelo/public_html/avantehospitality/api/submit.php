<?php

/*
 * Form submissions.
 *
 * The pages POST to /api/submit.php?form=<type>. When the site is served from a
 * sub-folder, the .htaccess rewrite points the public URL at this file, so the
 * address bar never shows the folder name.
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
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'The form application is not installed: upload the avante-app folder next to public_html.',
    ]);
    return;
}

require $bootstrap;

try {
    avante_run_submit_endpoint();
} catch (Throwable $e) {
    error_log('[avante] submission endpoint failed: ' . $e->getMessage());
    avante_json_response(500, [
        'success' => false,
        'error' => 'Could not save your submission. Please email us directly instead.',
    ]);
}
