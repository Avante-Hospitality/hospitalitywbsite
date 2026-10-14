<?php

/*
 * Form submissions.
 *
 * Reached as /api/submissions/<form>, which .htaccess rewrites onto this file.
 * The application itself lives outside the web root, in ../avante-app.
 */

$bootstrap = __DIR__ . '/../../avante-app/bootstrap.php';
if (!is_file($bootstrap)) {
    // Some hosts will not let you create folders next to public_html. In that
    // case upload avante-app inside public_html instead (see the README).
    $bootstrap = __DIR__ . '/../avante-app/bootstrap.php';
}

if (!is_file($bootstrap)) {
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
