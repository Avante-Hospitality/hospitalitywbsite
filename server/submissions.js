'use strict';

/**
 * Read submissions back out of the database.
 *
 *   npm run submissions                          last 20, all forms
 *   npm run submissions -- --type listing        last 20 of one form
 *   npm run submissions -- --limit 200           last 200
 *   npm run submissions -- --csv out.csv         export everything to CSV
 *   npm run submissions -- --prune 730           delete anything older than 730 days
 */

const fs = require('node:fs');
const { listSubmissions, pruneOlderThan, dbPath } = require('./db');
const { FORMS } = require('./forms');

const args = process.argv.slice(2);
const hasFlag = (name) => args.includes(`--${name}`);
const valueOf = (name, fallback) => {
  const i = args.indexOf(`--${name}`);
  return i !== -1 && args[i + 1] !== undefined ? args[i + 1] : fallback;
};

if (hasFlag('prune')) {
  const days = Number(valueOf('prune', process.env.RETENTION_DAYS || 730));
  const removed = pruneOlderThan(days);
  console.log(`Deleted ${removed} submission(s) older than ${days} days.`);
  process.exit(0);
}

const formType = valueOf('type');
if (formType && !FORMS[formType]) {
  console.error(`Unknown --type "${formType}". Valid types: ${Object.keys(FORMS).join(', ')}`);
  process.exit(1);
}

const rows = listSubmissions({ formType, limit: Number(valueOf('limit', 20)) });

if (!rows.length) {
  console.log(`No submissions yet. Database: ${dbPath}`);
  process.exit(0);
}

const parsePayload = (row) => {
  try {
    return JSON.parse(row.payload);
  } catch {
    return {};
  }
};

if (hasFlag('csv')) {
  const out = valueOf('csv', 'submissions.csv');
  const payloadKeys = [...new Set(rows.flatMap((r) => Object.keys(parsePayload(r))))];
  const headers = ['id', 'received', 'form', 'email_status', ...payloadKeys];
  const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
  const lines = [headers.map(esc).join(',')];
  for (const row of rows) {
    const p = parsePayload(row);
    lines.push(
      [row.id, row.created_at, row.form_type, row.email_status, ...payloadKeys.map((k) => p[k] ?? '')]
        .map(esc)
        .join(',')
    );
  }
  fs.writeFileSync(out, lines.join('\r\n') + '\r\n', 'utf8');
  console.log(`Wrote ${rows.length} submission(s) to ${out}`);
  process.exit(0);
}

console.log(`${rows.length} submission(s) from ${dbPath}\n`);
for (const row of rows) {
  const payload = parsePayload(row);
  const title = row.name || row.email || '(no name)';
  console.log(`#${row.id}  ${row.created_at}  ${row.form_type}  [${row.email_status}]  ${title}`);
  for (const [k, v] of Object.entries(payload)) {
    if (v === '' || v === undefined) continue;
    if (k === 'accept_terms') {
      console.log(`      ${k.replace(/_/g, ' ')}: ${v === 'true' ? 'yes' : v}`);
      continue;
    }
    console.log(`      ${k.replace(/_/g, ' ')}: ${String(v).replace(/\n/g, '\n        ')}`);
  }
  if (row.email_error) console.log(`      email error: ${row.email_error}`);
  console.log('');
}
