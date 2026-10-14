<?php

/*
 * Creates the database tables.
 *
 * The site also creates them automatically on the first submission, so this is
 * only needed if you want to set things up beforehand or check the connection.
 *
 * From SSH:
 *   php /usr/home/YOURUSER/avante-app/bin/install.php
 */

require __DIR__ . '/../bootstrap.php';

try {
    avante_ensure_schema();
    echo 'Tables ready in database "' . avante_config_get('db.name', '') . '".' . PHP_EOL;
    echo 'Submissions stored: ' . avante_count_submissions() . PHP_EOL;
    echo 'Email: ' . (avante_mail_configured()
        ? 'configured'
        : 'NOT configured - submissions will be saved but not emailed') . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
