'use strict';

const path = require('node:path');
const express = require('express');

const { insertSubmission, markEmailStatus } = require('./db');
const { FORMS, validate, buildSubject, metaFor } = require('./forms');
const mailer = require('./mailer');

const PORT = Number(process.env.PORT || 3000);
const app = express();

app.disable('x-powered-by');
app.use(express.json({ limit: '32kb' }));

// Only needed when the static site is hosted somewhere other than this server.
app.use((req, res, next) => {
  res.setHeader('Access-Control-Allow-Origin', process.env.CORS_ORIGIN || '*');
  res.setHeader('Vary', 'Origin');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  if (req.method === 'OPTIONS') return res.sendStatus(204);
  return next();
});

// Crude per-IP throttle: enough to stop casual spam on a public form.
const WINDOW_MS = 15 * 60 * 1000;
const MAX_PER_WINDOW = 15;
const hits = new Map();

function isRateLimited(ip) {
  const now = Date.now();
  const recent = (hits.get(ip) || []).filter((t) => now - t < WINDOW_MS);
  recent.push(now);
  hits.set(ip, recent);
  if (hits.size > 5000) hits.clear();
  return recent.length > MAX_PER_WINDOW;
}

app.get('/api/health', (req, res) => {
  res.json({
    ok: true,
    email: mailer.isConfigured() ? 'configured' : 'not-configured',
    forms: Object.keys(FORMS),
  });
});

app.post('/api/submissions/:formType', async (req, res) => {
  const forwarded = req.headers['x-forwarded-for'];
  const ip = (typeof forwarded === 'string' ? forwarded.split(',')[0] : req.socket.remoteAddress) || 'unknown';

  if (isRateLimited(ip.trim())) {
    return res.status(429).json({ success: false, error: 'Too many submissions from this connection. Please try again later.' });
  }

  const { formType } = req.params;
  const body = req.body || {};

  // Honeypot: real people never fill this in. Report success so bots do not retry.
  if (typeof body._gotcha === 'string' && body._gotcha.trim()) {
    return res.json({ success: true, id: null });
  }

  const result = validate(formType, body);
  if (!result.ok) {
    return res.status(400).json({ success: false, error: result.error, fields: result.fields });
  }

  const meta = metaFor(formType, result.payload);

  let id;
  try {
    id = insertSubmission({
      formType,
      email: meta.email,
      name: meta.name,
      reference: meta.reference,
      payload: result.payload,
      ip,
      userAgent: String(req.headers['user-agent'] || '').slice(0, 400),
    });
  } catch (err) {
    console.error('[api] could not store submission:', err.message);
    return res.status(500).json({ success: false, error: 'Could not save your submission. Please email us directly instead.' });
  }

  // Store first, email second, so a mail outage never loses a submission.
  const emailResult = await mailer.sendSubmissionEmail({
    payload: result.payload,
    subject: buildSubject(formType, result.payload),
    meta,
    id,
  });
  markEmailStatus(id, emailResult.status, emailResult.error);

  if (emailResult.status !== 'sent') {
    console.warn(`[api] submission #${id} stored, email ${emailResult.status}: ${emailResult.error || ''}`);
  }

  return res.json({ success: true, id });
});

const siteDir = path.join(__dirname, '..', '_site');
app.use(express.static(siteDir, { extensions: ['html'] }));
app.use((req, res) => res.status(404).send('Not found'));

app.listen(PORT, () => {
  console.log(`Avante site + form API listening on http://localhost:${PORT}`);
  console.log(`  serving ${siteDir} (run "npm run build" first)`);
  console.log(
    `  email: ${
      mailer.isConfigured()
        ? 'SMTP configured'
        : 'SMTP NOT configured - submissions are still saved, but not emailed (see .env.example)'
    }`
  );
});
