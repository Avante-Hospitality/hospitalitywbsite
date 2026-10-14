<?php

/*
 * Dependency-free SMTP sender - the PHP counterpart of server/mailer.js.
 *
 * A tiny SMTP client is used instead of a Composer package so that there is
 * nothing to install on the host: upload the files and it works.
 *
 * xneelo requires SMTP authentication, so a username and password are
 * normally needed (see README, "Sending email").
 */

function avante_smtp_host(): string
{
    return trim((string) avante_config_get('smtp.host', ''));
}

function avante_mail_configured(): bool
{
    return avante_smtp_host() !== ''
        && trim((string) avante_config_get('mail.from', '')) !== ''
        && trim((string) avante_config_get('mail.to', '')) !== '';
}

/*
 * Anything placed in a mail header must not contain CR/LF, or a submitter could
 * inject extra headers (such as a hidden Bcc) through their own email address.
 */
function avante_header_value($value): string
{
    return trim((string) preg_replace('/[\r\n]+/', ' ', (string) ($value ?? '')));
}

function avante_pretty_label(string $key): string
{
    $label = str_replace('_', ' ', $key);
    return (string) preg_replace_callback('/\b[a-z]/', fn($m) => strtoupper($m[0]), $label);
}

function avante_split_addresses(string $value): array
{
    $parts = array_map('trim', explode(',', $value));
    return array_values(array_filter($parts, fn($part) => $part !== ''));
}

/* Turns 'Avante <no-reply@example.com>' into 'no-reply@example.com'. */
function avante_smtp_address(string $value): string
{
    if (preg_match('/<([^>]+)>/', $value, $match)) {
        return trim($match[1]);
    }
    return trim($value);
}

function avante_render_text(string $label, array $payload): string
{
    $lines = [$label, ''];
    foreach ($payload as $key => $value) {
        $lines[] = avante_pretty_label((string) $key) . ': ' . ($value === '' ? '-' : (string) $value);
    }
    return implode("\n", $lines);
}

function avante_render_html(string $label, array $payload): string
{
    $rows = '';
    foreach ($payload as $key => $value) {
        $text = $value === '' ? '-' : (string) $value;
        $rows .= '<tr><th align="left" style="padding:6px 14px 6px 0;vertical-align:top;white-space:nowrap">'
            . avante_escape_html(avante_pretty_label((string) $key))
            . '</th><td style="padding:6px 0">'
            . str_replace("\n", '<br>', avante_escape_html($text))
            . '</td></tr>';
    }
    return '<table style="font-family:sans-serif;font-size:14px;border-collapse:collapse">' . $rows . '</table>';
}

function avante_build_message(
    string $from,
    array $to,
    array $cc,
    string $subject,
    array $meta,
    string $text,
    string $html
): string {
    $boundary = 'avante-' . bin2hex(random_bytes(12));
    $headers = [
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'From: ' . $from,
        'To: ' . implode(', ', $to),
    ];
    if ($cc) {
        $headers[] = 'Cc: ' . implode(', ', $cc);
    }
    if (!empty($meta['email'])) {
        $headers[] = 'Reply-To: ' . avante_header_value($meta['email']);
    }
    $headers[] = 'Subject: ' . $subject;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $body = [];
    $body[] = '--' . $boundary;
    $body[] = 'Content-Type: text/plain; charset=UTF-8';
    $body[] = 'Content-Transfer-Encoding: 8bit';
    $body[] = '';
    $body[] = $text;
    $body[] = '--' . $boundary;
    $body[] = 'Content-Type: text/html; charset=UTF-8';
    $body[] = 'Content-Transfer-Encoding: 8bit';
    $body[] = '';
    $body[] = $html;
    $body[] = '--' . $boundary . '--';

    return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $body);
}

/**
 * A minimal SMTP conversation: EHLO, then STARTTLS or implicit TLS, then
 * AUTH LOGIN/PLAIN, then one message.
 */
final class AvanteSmtp
{
    /** @var resource|null */
    private $socket = null;
    private array $capabilities = [];

    public function __construct(
        private string $host,
        private int $port,
        private string $user,
        private string $pass,
        private ?bool $forceSecure,
        private int $timeout
    ) {
    }

    /* Never throws: returns ['status' => 'sent'|'skipped'|'failed', 'error' => ?string]. */
    public function deliver(array $message): array
    {
        try {
            $this->send($message);
            return ['status' => 'sent', 'error' => null];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    public function send(array $message): void
    {
        try {
            $this->connect();
            $this->handshake();
            $this->authenticate();

            $this->command('MAIL FROM:<' . avante_smtp_address($message['from']) . '>', 'MAIL FROM', [250]);
            foreach ($message['recipients'] as $recipient) {
                $this->command('RCPT TO:<' . avante_smtp_address($recipient) . '>', 'RCPT TO', [250, 251]);
            }
            $this->command('DATA', 'DATA', [354]);
            $this->writeData($message['data']);
            $this->expect([250], 'message body');

            $this->quit();
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        $secure = $this->forceSecure ?? ($this->port === 465);
        $context = stream_context_create([
            'ssl' => [
                'SNI_enabled' => true,
                'peer_name' => $this->host,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            ($secure ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            throw new RuntimeException(sprintf(
                'could not connect to %s:%d (%s)',
                $this->host,
                $this->port,
                $errstr !== '' ? $errstr : 'error ' . $errno
            ));
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, $this->timeout);
        $this->expect([220], 'greeting');
    }

    private function handshake(): void
    {
        $this->capabilities = $this->capabilitiesFrom($this->say('EHLO ' . avante_smtp_helo_name(), 'EHLO'));

        $alreadySecure = ($this->forceSecure ?? ($this->port === 465));
        if ($alreadySecure) {
            return;
        }

        if (!isset($this->capabilities['STARTTLS'])) {
            if ($this->user !== '') {
                // The credentials would cross the network in the clear.
                error_log('[avante] SMTP server does not offer STARTTLS; sending credentials unencrypted');
            }
            return;
        }

        $this->command('STARTTLS', 'STARTTLS', [220]);
        $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (@stream_socket_enable_crypto($this->socket, true, $method) !== true) {
            throw new RuntimeException('could not start TLS with the mail server');
        }
        // Capabilities change after the handshake, so ask again.
        $this->capabilities = $this->capabilitiesFrom($this->say('EHLO ' . avante_smtp_helo_name(), 'EHLO'));
    }

    private function authenticate(): void
    {
        if ($this->user === '') {
            return;
        }

        $advertised = $this->capabilities['AUTH'] ?? null;
        if ($advertised === null) {
            throw new RuntimeException('the mail server does not offer authentication');
        }

        $mechanisms = array_map('strtoupper', preg_split('/\s+/', trim($advertised)) ?: []);

        if (in_array('LOGIN', $mechanisms, true)) {
            $this->command('AUTH LOGIN', 'AUTH LOGIN', [334]);
            $this->say(base64_encode($this->user), 'AUTH username', [334]);
            $this->say(base64_encode($this->pass), 'AUTH password', [235]);
            return;
        }

        if (in_array('PLAIN', $mechanisms, true)) {
            $token = base64_encode("\0" . $this->user . "\0" . $this->pass);
            $this->say('AUTH PLAIN ' . $token, 'AUTH PLAIN', [235]);
            return;
        }

        throw new RuntimeException('no supported authentication method (need LOGIN or PLAIN)');
    }

    private function capabilitiesFrom(array $response): array
    {
        $lines = $response[1] ?? [];
        // A multiline reply starts with the server's greeting, then lists what
        // it can do. A single-line reply has no greeting to skip.
        if (count($lines) > 1) {
            array_shift($lines);
        }

        $capabilities = [];
        foreach ($lines as $line) {
            $text = trim(substr($line, 4));
            if ($text === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $text) ?: [];
            $name = strtoupper((string) array_shift($parts));
            $capabilities[$name] = implode(' ', $parts);
        }
        return $capabilities;
    }

    private function writeData(string $data): void
    {
        $data = str_replace(["\r\n", "\r"], "\n", $data);
        // A line starting with a dot would otherwise end the message early.
        $data = (string) preg_replace('/^\./m', '..', $data);
        $data = str_replace("\n", "\r\n", $data);
        if (!str_ends_with($data, "\r\n")) {
            $data .= "\r\n";
        }
        fwrite($this->socket, $data . ".\r\n");
    }

    private function command(string $line, string $label, array $expect): array
    {
        return $this->say($line, $label, $expect);
    }

    /** Sends one line and returns the reply as [code, lines]. */
    private function say(string $line, string $label, array $expect = [250]): array
    {
        if (fwrite($this->socket, $line . "\r\n") === false) {
            throw new RuntimeException('lost the connection to the mail server');
        }

        $response = $this->readResponse();
        if ($expect && !in_array($response[0], $expect, true)) {
            throw new RuntimeException(sprintf(
                'mail server rejected %s (%d %s)',
                $label,
                $response[0],
                trim($response[1][0] ?? '')
            ));
        }
        return $response;
    }

    /** Reads a full SMTP reply, which may span several lines (250-... then 250 ...). */
    private function readResponse(): array
    {
        $lines = [];
        while (true) {
            $line = fgets($this->socket, 4096);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                throw new RuntimeException(
                    !empty($meta['timed_out'])
                        ? 'the mail server stopped responding'
                        : 'the mail server closed the connection'
                );
            }
            $lines[] = rtrim($line, "\r\n");
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return [(int) substr($lines[0] ?? '', 0, 3), $lines];
    }

    private function expect(array $codes, string $stage): void
    {
        $response = $this->readResponse();
        if (!in_array($response[0], $codes, true)) {
            throw new RuntimeException(sprintf(
                'mail server refused the %s (%d %s)',
                $stage,
                $response[0],
                trim($response[1][0] ?? '')
            ));
        }
    }

    private function quit(): void
    {
        @fwrite($this->socket, "QUIT\r\n");
        @fgets($this->socket, 512);
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }
}

function avante_smtp_helo_name(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '') {
        $name = parse_url('http://' . $host, PHP_URL_HOST);
        if (is_string($name) && $name !== '') {
            return $name;
        }
    }
    $name = gethostname();
    return is_string($name) && $name !== '' ? $name : 'localhost';
}

/**
 * Sends one submission notification. Never throws, so a mail outage can never
 * lose a submission that is already stored in the database.
 */
/*
 * Who a submission notification goes to: the addresses configured in config.php
 * plus any added on the admin page. Kept apart from the sending so it can be
 * checked without emailing anybody.
 */
function avante_submission_recipients(): array
{
    $to = avante_split_addresses((string) avante_config_get('mail.to', ''));
    $cc = avante_split_addresses((string) avante_config_get('mail.cc', ''));

    // The extras live in the database, so a lookup problem must never stop the
    // configured recipients from being emailed.
    try {
        foreach (avante_list_extra_recipients() as $row) {
            $address = trim((string) ($row['email'] ?? ''));
            if ($address === '') {
                continue;
            }
            foreach (array_merge($to, $cc) as $already) {
                if (strcasecmp($already, $address) === 0) {
                    continue 2;
                }
            }
            $cc[] = $address;
        }
    } catch (Throwable $e) {
        error_log('[avante] could not read the extra recipients: ' . $e->getMessage());
    }

    return ['to' => $to, 'cc' => $cc];
}

/*
 * $only posts the message to that single address instead of the configured
 * recipients. That is what the admin page's test button uses, so a test does not
 * also land on the team's addresses.
 */
function avante_send_submission_email(array $payload, string $subject, array $meta, int $id, ?string $only = null): array
{
    if (!avante_mail_configured()) {
        return ['status' => 'skipped', 'error' => 'SMTP not configured (see config.php)'];
    }

    $from = avante_header_value((string) avante_config_get('mail.from', ''));
    if ($only !== null && trim($only) !== '') {
        $to = [trim($only)];
        $cc = [];
    } else {
        $recipients = avante_submission_recipients();
        $to = $recipients['to'];
        $cc = $recipients['cc'];
    }
    if (!$to) {
        return ['status' => 'skipped', 'error' => 'No recipient address configured'];
    }

    $label = (string) ($meta['label'] ?? 'Form submission');
    $stamp = gmdate('Y-m-d\TH:i:s\Z');
    $text = avante_render_text($label, $payload) . "\n\n--\nSubmission #" . $id . ' received ' . $stamp;
    $html = avante_render_html($label, $payload)
        . '<p style="font-family:sans-serif;font-size:12px;color:#666">Submission #' . $id . '</p>';

    $smtp = new AvanteSmtp(
        avante_smtp_host(),
        (int) avante_config_get('smtp.port', 587),
        trim((string) avante_config_get('smtp.user', '')),
        (string) avante_config_get('smtp.pass', ''),
        avante_config_get('smtp.secure', null),
        (int) avante_config_get('smtp.timeout', 15)
    );

    return $smtp->deliver([
        'from' => $from,
        'recipients' => array_merge($to, $cc),
        'data' => avante_build_message($from, $to, $cc, $subject, $meta, $text, $html),
    ]);
}
