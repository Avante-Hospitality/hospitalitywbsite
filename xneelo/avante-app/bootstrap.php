<?php

/*
 * Loads configuration and the library. Every entry point includes this first.
 */

if (!defined('AVANTE_APP_DIR')) {
    define('AVANTE_APP_DIR', __DIR__);
}

function avante_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = AVANTE_APP_DIR . '/config.php';
    if (!is_file($path)) {
        throw new RuntimeException(
            'Missing ' . $path . ' - copy config.example.php to config.php in the same folder '
            . 'and fill in your database and mail settings (see the README).'
        );
    }

    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('config.php must return an array.');
    }

    return $config;
}

function avante_config_get(string $path, $default = null)
{
    $value = avante_config();
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return $default;
        }
        $value = $value[$key];
    }
    return $value;
}

require_once AVANTE_APP_DIR . '/lib/forms.php';
require_once AVANTE_APP_DIR . '/lib/http.php';
require_once AVANTE_APP_DIR . '/lib/db.php';
require_once AVANTE_APP_DIR . '/lib/throttle.php';
require_once AVANTE_APP_DIR . '/lib/mailer.php';
require_once AVANTE_APP_DIR . '/lib/csv.php';
require_once AVANTE_APP_DIR . '/lib/views.php';
require_once AVANTE_APP_DIR . '/lib/endpoint_submit.php';
require_once AVANTE_APP_DIR . '/lib/endpoint_admin.php';
