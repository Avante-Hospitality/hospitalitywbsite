'use strict';

const nodemailer = require('nodemailer');

const config = {
  host: process.env.SMTP_HOST,
  port: Number(process.env.SMTP_PORT || 587),
  user: process.env.SMTP_USER,
  pass: process.env.SMTP_PASS,
  from: process.env.MAIL_FROM,
  to: process.env.MAIL_TO,
  cc: process.env.MAIL_CC,
};

function isConfigured() {
  return Boolean(config.host && config.from && config.to);
}

let transport = null;
function getTransport() {
  if (!transport) {
    transport = nodemailer.createTransport({
      host: config.host,
      port: config.port,
      secure: config.port === 465,
      auth: config.user ? { user: config.user, pass: config.pass } : undefined,
    });
  }
  return transport;
}

// Anything placed in a mail header must not contain CR/LF, or a submitter could
// inject extra headers (e.g. a hidden Bcc) through their own email address.
const oneLine = (v) => String(v ?? '').replace(/[\r\n]+/g, ' ').trim();

const escapeHtml = (v) =>
  String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const prettyLabel = (key) =>
  key.replace(/_/g, ' ').replace(/\b[a-z]/g, (c) => c.toUpperCase());

function renderText(label, payload) {
  const lines = Object.entries(payload).map(([k, v]) => `${prettyLabel(k)}: ${v || '-'}`);
  return [label, '', ...lines].join('\n');
}

function renderHtml(label, payload) {
  const rows = Object.entries(payload)
    .map(
      ([k, v]) =>
        `<tr><th align="left" style="padding:6px 14px 6px 0;vertical-align:top;white-space:nowrap">${escapeHtml(
          prettyLabel(k)
        )}</th><td style="padding:6px 0">${escapeHtml(v || '-').replace(/\n/g, '<br>')}</td></tr>`
    )
    .join('');
  return `<table style="font-family:sans-serif;font-size:14px;border-collapse:collapse">${rows}</table>`;
}

/**
 * Never throws: returns { status: 'sent' | 'skipped' | 'failed', error? }
 * so the caller can record the outcome without losing the submission.
 */
async function sendSubmissionEmail({ payload, subject, meta, id }) {
  if (!isConfigured()) {
    return { status: 'skipped', error: 'SMTP not configured (see .env.example)' };
  }
  try {
    await getTransport().sendMail({
      from: config.from,
      to: config.to,
      cc: config.cc || undefined,
      replyTo: meta.email ? oneLine(meta.email) : undefined,
      subject: oneLine(subject),
      text: `${renderText(meta.label, payload)}\n\n--\nSubmission #${id} received ${new Date().toISOString()}`,
      html: `${renderHtml(meta.label, payload)}<p style="font-family:sans-serif;font-size:12px;color:#666">Submission #${id}</p>`,
    });
    return { status: 'sent' };
  } catch (err) {
    return { status: 'failed', error: err.message };
  }
}

module.exports = { isConfigured, sendSubmissionEmail };
