<?php

/*
 * CSV export - the PHP counterpart of server/csv.js.
 */

// Spreadsheets treat a leading =, +, - or @ as the start of a formula, so a value
// someone typed into a form could execute when an export is opened in Excel.
// Prefixing with an apostrophe forces it to stay text.
const AVANTE_RISKY_START = '/^[=+\-@\t\r]/';

function avante_csv_value($value): string
{
    $text = $value === null ? '' : (string) $value;
    if (preg_match(AVANTE_RISKY_START, $text)) {
        $text = "'" . $text;
    }
    return '"' . str_replace('"', '""', $text) . '"';
}

function avante_to_csv(array $headers, array $rows): string
{
    $lines = [implode(',', array_map('avante_csv_value', $headers))];
    foreach ($rows as $row) {
        $lines[] = implode(',', array_map('avante_csv_value', $row));
    }
    return implode("\r\n", $lines) . "\r\n";
}

function avante_parse_payload($row): array
{
    $payload = is_array($row) ? ($row['payload'] ?? '') : '';
    $decoded = is_string($payload) ? json_decode($payload, true) : null;
    return is_array($decoded) ? $decoded : [];
}

/* Builds a CSV covering every field seen across the given submissions. */
function avante_submissions_csv(array $rows): string
{
    $keys = [];
    foreach ($rows as $row) {
        foreach (array_keys(avante_parse_payload($row)) as $key) {
            $keys[$key] = true;
        }
    }
    $keys = array_keys($keys);

    $headers = array_merge(['id', 'received', 'form', 'email_status', 'ip'], $keys);
    $data = [];
    foreach ($rows as $row) {
        $payload = avante_parse_payload($row);
        $line = [
            $row['id'] ?? '',
            $row['created_at'] ?? '',
            $row['form_type'] ?? '',
            $row['email_status'] ?? '',
            $row['ip'] ?? '',
        ];
        foreach ($keys as $key) {
            $line[] = $payload[$key] ?? '';
        }
        $data[] = $line;
    }

    return avante_to_csv($headers, $data);
}
