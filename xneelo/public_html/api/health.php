<?php

/*
 * A quick check that the deployment can reach its database, and whether email
 * is set up. Open /api/health.php in a browser after uploading.
 */

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
    echo json_encode(['ok' => false, 'database' => 'not-checked', 'error' => 'The form application is not installed.']);
    return;
}

require $bootstrap;

$database = 'ok';
try {
    avante_count_submissions();
} catch (Throwable $e) {
    $database = 'error';
    error_log('[avante] health check database error: ' . $e->getMessage());
}

avante_json_response(200, [
    'ok' => $database === 'ok',
    'database' => $database,
    'email' => avante_mail_configured() ? 'configured' : 'not-configured',
    'forms' => array_keys(avante_forms()),
]);
