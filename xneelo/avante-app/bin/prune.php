<?php

/*
 * Deletes submissions older than the retention window.
 *
 * Intended for the Cronjob Manager in the xneelo Control Panel. Set the command to:
 *
 *   php /usr/home/YOURUSER/avante-app/bin/prune.php
 *
 * Add a number of days to override retention_days from config.php.
 */

require __DIR__ . '/../bootstrap.php';

$days = (isset($argv[1]) && ctype_digit((string) $argv[1]))
    ? (int) $argv[1]
    : (int) avante_config_get('retention_days', 730);

try {
    $removed = avante_prune_older_than($days);
    echo 'Deleted ' . $removed . ' submission(s) older than ' . $days . ' days.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
