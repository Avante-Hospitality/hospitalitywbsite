<?php

/*
 * Form submissions.
 *
 * The pages POST to /api/submit.php?form=<type>. This file sits at the site root
 * and is called directly - there is no rewrite or .htaccess involved.
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
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'The form application is not installed. Upload the avante-app folder into public_html (see the README).',
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
