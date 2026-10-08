'use strict';

const crypto = require('node:crypto');
const express = require('express');

const { listSubmissions, countSubmissions } = require('./db');
const { FORMS } = require('./forms');
const { submissionsCsv, parsePayload } = require('./csv');

const PAGE_SIZE = 50;
const SESSION_TTL_MS = 12 * 60 * 60 * 1000;
const COOKIE_NAME = 'avante_admin';
const LOGIN_WINDOW_MS = 15 * 60 * 1000;
const LOGIN_MAX_ATTEMPTS = 10;

const router = express.Router();

/* ------------------------------------------------------------------ security */

const escapeHtml = (value) =>
  String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// Fail closed: with no ADMIN_PASSWORD set the page refuses to do anything at all.
const adminEnabled = () => Boolean(process.env.ADMIN_PASSWORD);

const sessionSecret = () => process.env.ADMIN_SESSION_SECRET || process.env.ADMIN_PASSWORD || '';

const sign = (value) => crypto.createHmac('sha256', sessionSecret()).update(value).digest('base64url');

function issueToken() {
  const expires = String(Date.now() + SESSION_TTL_MS);
  return `${expires}.${sign(expires)}`;
}

function tokenValid(token) {
  if (!token || typeof token !== 'string') return false;
  const [expires, mac] = token.split('.');
  if (!expires || !mac) return false;
  const expected = sign(expires);
  const given = Buffer.from(mac);
  const want = Buffer.from(expected);
  if (given.length !== want.length || !crypto.timingSafeEqual(given, want)) return false;
  return Number(expires) > Date.now();
}

// Hash both sides first so the comparison length does not leak the password length.
function passwordMatches(input) {
  const expected = process.env.ADMIN_PASSWORD || '';
  if (!expected) return false;
  const hash = (v) => crypto.createHash('sha256').update(String(v ?? '')).digest();
  return crypto.timingSafeEqual(hash(input), hash(expected));
}

function parseCookies(header) {
  const jar = {};
  for (const part of String(header || '').split(';')) {
    const i = part.indexOf('=');
    if (i > 0) jar[part.slice(0, i).trim()] = decodeURIComponent(part.slice(i + 1).trim());
  }
  return jar;
}

const isAuthenticated = (req) => adminEnabled() && tokenValid(parseCookies(req.headers.cookie)[COOKIE_NAME]);

function isSecureRequest(req) {
  return req.protocol === 'https' || String(req.headers['x-forwarded-proto'] || '').split(',')[0].trim() === 'https';
}

function setSessionCookie(req, res) {
  const bits = [
    `${COOKIE_NAME}=${issueToken()}`,
    'HttpOnly',
    'Path=/',
    'SameSite=Strict',
    `Max-Age=${Math.floor(SESSION_TTL_MS / 1000)}`,
  ];
  if (isSecureRequest(req)) bits.push('Secure');
  res.setHeader('Set-Cookie', bits.join('; '));
}

// Only accept POSTs that came from this same host (blocks cross-site login attempts).
function sameOrigin(req) {
  const source = req.headers.origin || req.headers.referer;
  if (!source) return true;
  try {
    return new URL(source).host === req.headers.host;
  } catch {
    return false;
  }
}

const loginAttempts = new Map();
function loginThrottled(ip) {
  const now = Date.now();
  const recent = (loginAttempts.get(ip) || []).filter((t) => now - t < LOGIN_WINDOW_MS);
  recent.push(now);
  loginAttempts.set(ip, recent);
  if (loginAttempts.size > 5000) loginAttempts.clear();
  return recent.length > LOGIN_MAX_ATTEMPTS;
}

router.use((req, res, next) => {
  res.setHeader('Cache-Control', 'no-store');
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('X-Frame-Options', 'DENY');
  res.setHeader('Referrer-Policy', 'same-origin');
  // No scripts are used anywhere on these pages, so none are allowed.
  res.setHeader(
    'Content-Security-Policy',
    "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
  );
  next();
});

/* ---------------------------------------------------------------------- views */

function shell(title, body) {
  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>${escapeHtml(title)}</title>
<style>
  :root{--navy:#1B2434;--ink:#3E4A58;--line:#E4DFD3;--indigo:#1425BE;--muted:#6B7686;}
  *{box-sizing:border-box;}
  body{margin:0;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:#F5F3EE;color:var(--ink);}
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
${body}
</body>
</html>
`;
}

function disabledPage() {
  return shell(
    'Submissions admin disabled',
    `<main><div class="notice">
      <strong>The submissions admin is switched off.</strong><br><br>
      Set <code>ADMIN_PASSWORD</code> in <code>.env</code> and restart the server to enable it.
      It stays disabled on purpose so the data is never exposed by accident.
    </div></main>`
  );
}

function loginPage({ error } = {}) {
  return shell(
    'Sign in — submissions',
    `<main>
      <form class="login" method="post" action="/admin/login">
        <h2>Submissions</h2>
        <p class="hint">Sign in to view and export form submissions.</p>
        ${error ? `<div class="error">${escapeHtml(error)}</div>` : ''}
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" autofocus required>
        <button class="btn wide" type="submit">Sign in</button>
      </form>
    </main>`
  );
}

const formLabel = (type) => (FORMS[type] ? FORMS[type].label : type);

function statusPill(status) {
  const cls = ['sent', 'skipped', 'failed', 'pending'].includes(status) ? status : 'pending';
  return `<span class="pill ${cls}">${escapeHtml(status)}</span>`;
}

function listPage({ formType, page }) {
  const total = countSubmissions(formType);
  const pages = Math.max(Math.ceil(total / PAGE_SIZE), 1);
  const current = Math.min(Math.max(page, 1), pages);
  const rows = listSubmissions({ formType, limit: PAGE_SIZE, offset: (current - 1) * PAGE_SIZE });

  const filterLink = (type, label) => {
    const on = (type || '') === (formType || '') ? ' class="on"' : '';
    return `<a href="/admin${type ? `?type=${encodeURIComponent(type)}` : ''}"${on}>${escapeHtml(label)}</a>`;
  };
  const query = formType ? `?type=${encodeURIComponent(formType)}` : '';

  const body =
    rows.length === 0
      ? `<div class="empty">No submissions yet.</div>`
      : `<table>
      <thead><tr>
        <th>#</th><th>Received (UTC)</th><th>Form</th><th>Name</th><th>Email</th><th>Ref</th><th>Email sent</th><th></th>
      </tr></thead>
      <tbody>
        ${rows
          .map((row) => {
            const payload = parsePayload(row);
            const fields = Object.entries(payload)
              .map(([k, v]) => `<dt>${escapeHtml(k.replace(/_/g, ' '))}</dt><dd>${escapeHtml(v === '' ? '—' : v)}</dd>`)
              .join('');
            const emailNote = row.email_error ? `<dt>email error</dt><dd>${escapeHtml(row.email_error)}</dd>` : '';
            return `<tr>
          <td class="num">${row.id}</td>
          <td class="num">${escapeHtml(row.created_at)}</td>
          <td>${escapeHtml(formLabel(row.form_type))}</td>
          <td>${escapeHtml(row.name || '—')}</td>
          <td>${escapeHtml(row.email || '—')}</td>
          <td>${escapeHtml(row.reference || '—')}</td>
          <td>${statusPill(row.email_status)}</td>
          <td><details><summary>view</summary><dl>${fields}${emailNote}</dl></details></td>
        </tr>`;
          })
          .join('\n')}
      </tbody>
    </table>`;

  return shell(
    'Submissions',
    `<header class="bar">
      <h1>Form submissions</h1>
      <div class="right">
        <a class="btn ghost" href="/admin/export.csv${query}">Export CSV</a>
        <form method="post" action="/admin/logout"><button class="btn ghost" type="submit">Sign out</button></form>
      </div>
    </header>
    <main>
      <div class="filters">
        ${filterLink('', `All (${countSubmissions()})`)}
        ${Object.keys(FORMS).map((type) => filterLink(type, `${formLabel(type)} (${countSubmissions(type)})`)).join('\n')}
      </div>
      ${body}
      <div class="pager">
        <span>${total} submission${total === 1 ? '' : 's'} · page ${current} of ${pages}</span>
        <span>
          ${current > 1 ? `<a href="/admin?page=${current - 1}${formType ? `&type=${encodeURIComponent(formType)}` : ''}">← newer</a>` : ''}
          ${current < pages ? `<a href="/admin?page=${current + 1}${formType ? `&type=${encodeURIComponent(formType)}` : ''}">older →</a>` : ''}
        </span>
      </div>
    </main>`
  );
}

/* --------------------------------------------------------------------- routes */

router.get('/', (req, res) => {
  if (!adminEnabled()) return res.status(503).send(disabledPage());
  if (!isAuthenticated(req)) return res.redirect('/admin/login');

  const formType = typeof req.query.type === 'string' && FORMS[req.query.type] ? req.query.type : null;
  const page = Number.parseInt(req.query.page, 10) || 1;
  res.send(listPage({ formType, page }));
});

router.get('/login', (req, res) => {
  if (!adminEnabled()) return res.status(503).send(disabledPage());
  if (isAuthenticated(req)) return res.redirect('/admin');
  res.send(loginPage());
});

router.post('/login', (req, res) => {
  if (!adminEnabled()) return res.status(503).send(disabledPage());
  if (!sameOrigin(req)) return res.status(403).send(shell('Forbidden', '<main><div class="notice">Cross-site request blocked.</div></main>'));

  const ip = (req.headers['x-forwarded-for'] || req.socket.remoteAddress || 'unknown').toString().split(',')[0].trim();
  if (loginThrottled(ip)) {
    return res.status(429).send(loginPage({ error: 'Too many attempts. Please wait 15 minutes and try again.' }));
  }

  if (!passwordMatches(req.body && req.body.password)) {
    return res.status(401).send(loginPage({ error: 'Incorrect password.' }));
  }

  setSessionCookie(req, res);
  res.redirect('/admin');
});

router.post('/logout', (req, res) => {
  res.setHeader('Set-Cookie', `${COOKIE_NAME}=; HttpOnly; Path=/; SameSite=Strict; Max-Age=0`);
  res.redirect('/admin/login');
});

router.get('/export.csv', (req, res) => {
  if (!adminEnabled()) return res.status(503).send(disabledPage());
  if (!isAuthenticated(req)) return res.redirect('/admin/login');

  const formType = typeof req.query.type === 'string' && FORMS[req.query.type] ? req.query.type : null;
  const rows = listSubmissions({ formType, limit: 5000, offset: 0 });
  const stamp = new Date().toISOString().slice(0, 10);

  res.setHeader('Content-Type', 'text/csv; charset=utf-8');
  res.setHeader('Content-Disposition', `attachment; filename="avante-submissions-${formType ? `${formType}-` : ''}${stamp}.csv"`);
  res.send(submissionsCsv(rows));
});

module.exports = { router };
