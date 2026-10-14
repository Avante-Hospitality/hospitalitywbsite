<?php

/*
 * Form submission handler - the PHP counterpart of the POST route in
 * server/index.js. Reached through /api/submissions/<form>.
 */

function avante_requested_form_type(): string
{
    if (isset($_GET['form']) && is_string($_GET['form'])) {
        return trim($_GET['form']);
    }

    // Fallback for a host that passes the path as PATH_INFO instead of
    // rewriting it into a query string.
    $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (preg_match('#/api/submissions/([A-Za-z0-9_-]+)/?$#', $uri, $match)) {
        return $match[1];
    }

    return '';
}

function avante_run_submit_endpoint(): void
{
    $origin = avante_cors_origin();
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        http_response_code(204);
        return;
    }
    if ($method !== 'POST') {
        avante_json_response(405, ['success' => false, 'error' => 'Use POST']);
        return;
    }

    $formType = avante_requested_form_type();
    $ip = avante_client_ip();

    // Crude per-IP throttle: enough to stop casual spam on a public form.
    if (avante_submit_rate_limited($ip)) {
        avante_json_response(429, [
            'success' => false,
            'error' => 'Too many submissions from this connection. Please try again later.',
        ]);
        return;
    }

    try {
        $body = avante_read_json_body();
    } catch (Throwable $e) {
        avante_json_response(413, ['success' => false, 'error' => 'That submission was too large.']);
        return;
    }

    // Honeypot: real people never fill this in. Report success so bots do not retry.
    if (isset($body['_gotcha']) && is_string($body['_gotcha']) && trim($body['_gotcha']) !== '') {
        avante_json_response(200, ['success' => true, 'id' => null]);
        return;
    }

    $result = avante_validate($formType, $body);
    if (!$result['ok']) {
        avante_json_response(400, [
            'success' => false,
            'error' => $result['error'],
            'fields' => $result['fields'],
        ]);
        return;
    }

    $payload = $result['payload'];
    $meta = avante_meta_for($formType, $payload);

    try {
        $id = avante_insert_submission([
            'form_type' => $formType,
            'email' => $meta['email'],
            'name' => $meta['name'],
            'reference' => $meta['reference'],
            'payload' => $payload,
            'ip' => $ip,
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400),
        ]);
    } catch (Throwable $e) {
        error_log('[avante] could not store submission: ' . $e->getMessage());
        avante_json_response(500, [
            'success' => false,
            'error' => 'Could not save your submission. Please email us directly instead.',
        ]);
        return;
    }

    // Store first, email second, so a mail outage never loses a submission.
    $email = avante_send_submission_email($payload, avante_build_subject($formType, $payload), $meta, $id);
    try {
        avante_mark_email_status($id, $email['status'], $email['error']);
    } catch (Throwable $e) {
        error_log('[avante] could not record email status for #' . $id . ': ' . $e->getMessage());
    }

    if ($email['status'] !== 'sent') {
        error_log(sprintf(
            '[avante] submission #%d stored, email %s: %s',
            $id,
            $email['status'],
            (string) ($email['error'] ?? '')
        ));
    }

    avante_json_response(200, ['success' => true, 'id' => $id]);
}
