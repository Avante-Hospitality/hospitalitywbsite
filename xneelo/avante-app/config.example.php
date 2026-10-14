<?php

/*
 * Copy this file to config.php in the same folder and fill in your details.
 *
 * config.php is deliberately kept OUTSIDE public_html so that it can never be
 * downloaded over the web, whatever the Apache configuration does.
 *
 *   avante-app/config.php      <- your settings (never web-accessible)
 *   public_html/               <- the website
 */

return [
    // --- Database ---------------------------------------------------------
    // Create the database and user in the xneelo Control Panel under
    // "Manage MySQL", then copy the exact names it shows you.
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'your_database_name',
        'user' => 'your_database_user',
        'pass' => 'your_database_password',
    ],

    // --- Outgoing email (SMTP) --------------------------------------------
    // The mailbox settings from the xneelo Control Panel. Note that the
    // outgoing server is smtp.<domain>; mail.<domain> is the incoming server
    // and is only used for reading mail. Port 465 uses TLS from the first byte.
    'smtp' => [
        'host' => 'smtp.avantehospitality.co.za',
        'port' => 465,
        'user' => 'web@avantehospitality.co.za',
        // The mailbox password. Never commit this file.
        'pass' => '',
        // Leave null to pick TLS automatically from the port above.
        'secure' => null,
        'timeout' => 15,
    ],

    'mail' => [
        // Sent as the authenticated mailbox, which is what the mail server
        // expects; hitting reply still goes back to the person who submitted.
        'from' => 'Avante Website <web@avantehospitality.co.za>',
        // Where submissions are emailed. Separate several addresses with commas.
        'to' => 'info@avantehospitality.co.za',
        'cc' => 'admin@avantehospitality.co.za,liason@avantehospitality.co.za',
    ],

    // --- Admin page -------------------------------------------------------
    // Leave the password empty to keep /admin switched off.
    'admin' => [
        'password' => '',
        // Optional. Changing this signs everybody out. Defaults to the password.
        'secret' => '',
    ],

    // Submissions older than this are removed by bin/prune.php (see README).
    'retention_days' => 730,

    // Only needed if the website is served from another domain and posts here.
    // Leave empty for the normal single-domain setup.
    'cors_origin' => '',

    // Set to true only when the site sits behind a proxy or CDN that sets
    // X-Forwarded-For, so the real visitor IP is recorded and rate limited.
    'trust_proxy_headers' => false,
];
