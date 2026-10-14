<?php

/*
 * Admin page markup - the PHP counterpart of the views in server/admin.js.
 */

const AVANTE_ADMIN_PAGE_SIZE = 50;

function avante_admin_shell(string $title, string $body): string
{
    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . avante_escape_html($title) . '</title>
<style>
  :root{--navy:#1B2434;--ink:#3E4A58;--line:#E4DFD3;--indigo:#1425BE;--muted:#6B7686;}
  *{box-sizing:border-box;}
  body{margin:0;font-family:system-ui,-apple-system,\'Segoe UI\',sans-serif;background:#F5F3EE;color:var(--ink);}
  header.bar{background:var(--navy);color:#fff;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;}
  header.bar h1{font-size:16px;margin:0;font-weight:600;}
  header.bar .right{display:flex;gap:10px;align-items:center;}
  main{padding:20px;max-width:1240px;margin:0 auto;}
  .filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px;}
  .filters a{padding:6px 12px;border:1px solid var(--line);border-radius:999px;text-decoration:none;color:var(--ink);background:#fff;font-size:13px;}
  .filters a.on{background:var(--navy);color:#fff;border-color:var(--navy);}
  .spacer{flex:1;}
  .btn{display:inline-block;padding:8px 14px;background:var(--indigo);color:#fff;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;border:0;cursor:pointer;font-family:inherit;}
  .btn.ghost{background:transparent;color:#fff;border:1px solid rgba(255,255,255,.45);}
  table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--line);border-radius:10px;overflow:hidden;font-size:14px;}
  th,td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--line);vertical-align:top;}
  th{background:#EFEBE3;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);}
  tr:last-child td{border-bottom:0;}
  td.num{color:var(--muted);font-variant-numeric:tabular-nums;}
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;}
  .pill.sent{background:#DFF3E4;color:#1C6B36;}
  .pill.skipped{background:#FDECC8;color:#8A5A00;}
  .pill.failed{background:#FBDCDC;color:#8C1D1D;}
  .pill.pending{background:#E7E7E7;color:#555;}
  details summary{cursor:pointer;color:var(--indigo);font-weight:600;font-size:13px;}
  dl{margin:10px 0 0;display:grid;grid-template-columns:minmax(120px,180px) 1fr;gap:3px 14px;font-size:13px;}
  dt{color:var(--muted);}
  dd{margin:0;white-space:pre-wrap;word-break:break-word;}
  .empty{background:#fff;border:1px solid var(--line);border-radius:10px;padding:40px;text-align:center;color:var(--muted);}
  .pager{display:flex;gap:10px;justify-content:space-between;align-items:center;margin-top:16px;font-size:13px;}
  .pager a{color:var(--indigo);}
  form.login{background:#fff;border:1px solid var(--line);border-radius:12px;padding:28px;max-width:400px;margin:56px auto;}
  form.login h2{margin:0 0 6px;font-size:18px;}
  form.login p.hint{margin:0 0 20px;font-size:13px;color:var(--muted);}
  label{display:block;font-size:13px;font-weight:600;margin-bottom:6px;}
  input[type=password]{width:100%;padding:11px 12px;border:1px solid #CCC;border-radius:8px;font-size:15px;font-family:inherit;}
  .btn.wide{width:100%;margin-top:16px;padding:11px;font-size:14px;}
  .error{color:#8C1D1D;background:#FBDCDC;border-radius:8px;padding:10px 12px;font-size:13px;margin-bottom:16px;}
  .notice{background:#FDECC8;color:#8A5A00;border-radius:10px;padding:14px 16px;font-size:14px;max-width:640px;margin:40px auto;}
  .notice code{background:rgba(0,0,0,.06);padding:1px 5px;border-radius:4px;}
</style>
</head>
<body>
' . $body . '
</body>
</html>
';
}

function avante_admin_disabled_page(): string
{
    return avante_admin_shell(
        'Submissions admin disabled',
        '<main><div class="notice">
      <strong>The submissions admin is switched off.</strong><br><br>
      Set <code>admin.password</code> in <code>avante-app/config.php</code> to turn it on.
      It stays off on purpose so the data is never exposed by accident.
    </div></main>'
    );
}

function avante_admin_login_page(?string $error): string
{
    return avante_admin_shell(
        'Sign in - submissions',
        '<main>
      <form class="login" method="post" action="index.php?action=login">
        <h2>Submissions</h2>
        <p class="hint">Sign in to view and export form submissions.</p>
        ' . ($error !== null ? '<div class="error">' . avante_escape_html($error) . '</div>' : '') . '
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" autofocus required>
        <button class="btn wide" type="submit">Sign in</button>
      </form>
    </main>'
    );
}

function avante_admin_forbidden_page(): string
{
    return avante_admin_shell(
        'Forbidden',
        '<main><div class="notice">Cross-site request blocked.</div></main>'
    );
}

function avante_admin_status_pill(string $status): string
{    $class = in_array($status, ['sent', 'skipped', 'failed', 'pending'], true) ? $status : 'pending';
    return '<span class="pill ' . $class . '">' . avante_escape_html($status) . '</span>';
}

function avante_admin_list_page(?string $formType, int $page): string
{
    $total = avante_count_submissions($formType);
    $pages = max((int) ceil($total / AVANTE_ADMIN_PAGE_SIZE), 1);
    $current = min(max($page, 1), $pages);
    $rows = avante_list_submissions($formType, AVANTE_ADMIN_PAGE_SIZE, ($current - 1) * AVANTE_ADMIN_PAGE_SIZE);

    $filterLink = function (?string $type, string $label) use ($formType) {
        $on = ($type ?? '') === ($formType ?? '') ? ' class="on"' : '';
        $href = avante_admin_url($type !== null && $type !== '' ? 'type=' . rawurlencode($type) : '');
        return '<a href="' . avante_escape_html($href) . '"' . $on . '>' . avante_escape_html($label) . '</a>';
    };

    $query = $formType !== null ? '&type=' . rawurlencode($formType) : '';
    $listQuery = $formType !== null ? '?type=' . rawurlencode($formType) : '';

    if (!$rows) {
        $body = '<div class="empty">No submissions yet.</div>';
    } else {
        $body = '<table>
      <thead><tr>
        <th>#</th><th>Received (UTC)</th><th>Form</th><th>Name</th><th>Email</th><th>Ref</th><th>Email sent</th><th></th>
      </tr></thead>
      <tbody>';

        foreach ($rows as $row) {
            $payload = avante_parse_payload($row);
            $fields = '';
            foreach ($payload as $key => $value) {
                $fields .= '<dt>' . avante_escape_html(str_replace('_', ' ', (string) $key)) . '</dt>'
                    . '<dd>' . avante_escape_html($value === '' ? '-' : $value) . '</dd>';
            }
            $emailNote = !empty($row['email_error'])
                ? '<dt>email error</dt><dd>' . avante_escape_html($row['email_error']) . '</dd>'
                : '';

            $body .= '
        <tr>
          <td class="num">' . avante_escape_html($row['id'] ?? '') . '</td>
          <td class="num">' . avante_escape_html($row['created_at'] ?? '') . '</td>
          <td>' . avante_escape_html(avante_form_label((string) ($row['form_type'] ?? ''))) . '</td>
          <td>' . avante_escape_html(($row['name'] ?? '') !== '' ? $row['name'] : '-') . '</td>
          <td>' . avante_escape_html(($row['email'] ?? '') !== '' ? $row['email'] : '-') . '</td>
          <td>' . avante_escape_html(($row['reference'] ?? '') !== '' ? $row['reference'] : '-') . '</td>
          <td>' . avante_admin_status_pill((string) ($row['email_status'] ?? 'pending')) . '</td>
          <td><details><summary>view</summary><dl>' . $fields . $emailNote . '</dl></details></td>
        </tr>';
        }

        $body .= '
      </tbody>
    </table>';
    }

    $filters = $filterLink(null, 'All (' . avante_count_submissions() . ')');
    foreach (array_keys(avante_forms()) as $type) {
        $filters .= "\n        " . $filterLink($type, avante_form_label($type) . ' (' . avante_count_submissions($type) . ')');
    }

    $pager = '<span>' . $total . ' submission' . ($total === 1 ? '' : 's')
        . ' &middot; page ' . $current . ' of ' . $pages . '</span><span>';
    if ($current > 1) {
        $pager .= '<a href="' . avante_escape_html(avante_admin_url('page=' . ($current - 1) . $query)) . '">&larr; newer</a>';
    }
    if ($current < $pages) {
        $pager .= ' <a href="' . avante_escape_html(avante_admin_url('page=' . ($current + 1) . $query)) . '">older &rarr;</a>';
    }
    $pager .= '</span>';

    return avante_admin_shell(
        'Submissions',
        '<header class="bar">
      <h1>Form submissions</h1>
      <div class="right">
        <a class="btn ghost" href="' . avante_escape_html(avante_admin_url('action=diagnostics')) . '">Diagnostics</a>
        <a class="btn ghost" href="' . avante_escape_html(avante_admin_url('action=export' . $query)) . '">Export CSV</a>
        <form method="post" action="index.php?action=logout"><button class="btn ghost" type="submit">Sign out</button></form>
      </div>
    </header>
    <main>
      <div class="filters">
        ' . $filters . '
      </div>
      ' . $body . '
      <div class="pager">
        ' . $pager . '
      </div>
    </main>'
    );
}

function avante_admin_diagnostics_page(array $report, ?array $test): string
{
    $rows = '';
    foreach ($report as $label => $value) {
        $rows .= '<tr><th style="white-space:nowrap;width:220px">' . avante_escape_html((string) $label)
            . '</th><td>' . avante_escape_html((string) $value) . '</td></tr>';
    }

    $testBox = '';
    if ($test !== null) {
        $testBox = '<div class="notice" style="margin:16px 0 0;max-width:none">Test email: '
            . avante_admin_status_pill((string) $test['status'])
            . ($test['error'] !== null ? ' ' . avante_escape_html((string) $test['error']) : '')
            . '</div>';
    }

    $recipient = (string) avante_config_get('mail.to', '');

    return avante_admin_shell(
        'Diagnostics',
        '<header class="bar">
      <h1>Site diagnostics</h1>
      <div class="right">
        <a class="btn ghost" href="' . avante_escape_html(avante_admin_url()) . '">Submissions</a>
        <form method="post" action="index.php?action=logout"><button class="btn ghost" type="submit">Sign out</button></form>
      </div>
    </header>
    <main>
      <table><tbody>' . $rows . '</tbody></table>
      <form method="post" action="index.php?action=diagnostics" style="margin-top:16px">
        <input type="hidden" name="do" value="test-email">
        <button class="btn" type="submit">Send a test email to ' . avante_escape_html($recipient) . '</button>
      </form>
      ' . $testBox . '
    </main>'
    );
}
