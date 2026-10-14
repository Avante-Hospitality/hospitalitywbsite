<?php

/*
 * Checks the installation from the command line.
 *
 * From SSH:
 *   php /usr/home/YOURUSER/avante-app/bin/selfcheck.php
 *   php /usr/home/YOURUSER/avante-app/bin/selfcheck.php --email
 *   php /usr/home/YOURUSER/avante-app/bin/selfcheck.php --email you@example.com
 *
 * With --email it sends a test message to mail.to, or to the address given
 * after it. Either way, real submissions keep going to mail.to.
 */

require __DIR__ . '/../bootstrap.php';

$failures = 0;
$report = function (string $label, bool $ok, string $detail = '') use (&$failures) {
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? '  OK   ' : '  FAIL ') . $label . ($detail !== '' ? ' - ' . $detail : '') . PHP_EOL;
};

echo 'Avante forms self-check' . PHP_EOL;
echo 'PHP ' . PHP_VERSION . PHP_EOL . PHP_EOL;

$configFile = AVANTE_APP_DIR . '/config.php';
$report('config.php present', is_file($configFile), $configFile);
if (!is_file($configFile)) {
    echo PHP_EOL . 'Copy config.example.php to config.php and fill it in, then run this again.' . PHP_EOL;
    exit(1);
}

$report('pdo_mysql available', in_array('mysql', PDO::getAvailableDrivers(), true));
$report('openssl available (TLS to the mail server)', extension_loaded('openssl'));

try {
    avante_ensure_schema();
    $report('database reachable', true, 'database "' . avante_config_get('db.name', '') . '"');
    $report('submissions readable', true, avante_count_submissions() . ' stored');
} catch (Throwable $e) {
    $report('database reachable', false, $e->getMessage());
}

$recipient = (string) avante_config_get('mail.to', '');
if (avante_mail_configured()) {
    $report('SMTP configured', true, (string) avante_config_get('smtp.host', '') . ':' . (string) avante_config_get('smtp.port', '') . ' -> ' . $recipient);
} else {
    $report('SMTP configured', false, 'set smtp.host and mail.to in config.php');
}

$emailIndex = array_search('--email', $argv, true);
if ($emailIndex !== false && avante_mail_configured()) {
    $asked = trim((string) ($argv[$emailIndex + 1] ?? ''));
    $testTo = preg_match(AVANTE_EMAIL_RE, $asked) ? $asked : $recipient;
    echo PHP_EOL . 'Sending a test email to ' . $testTo . ' ...' . PHP_EOL;
    $result = avante_send_submission_email(
        ['note' => 'Test message from selfcheck.php', 'email' => $testTo],
        'Avante self-check test email',
        ['label' => 'Self-check', 'email' => $testTo],
        0,
        $testTo
    );
    $report('test email', $result['status'] === 'sent', $result['status'] . ($result['error'] !== null ? ' - ' . $result['error'] : ''));
}

echo PHP_EOL . ($failures === 0 ? 'All checks passed.' : $failures . ' check(s) need attention.') . PHP_EOL;
exit($failures === 0 ? 0 : 1);
