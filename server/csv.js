'use strict';

// Spreadsheets treat a leading =, +, - or @ as the start of a formula, so a value
// someone typed into a form could execute when an export is opened in Excel.
// Prefixing with an apostrophe forces it to stay text.
const RISKY_START = /^[=+\-@\t\r]/;

function csvValue(value) {
  let s = value === null || value === undefined ? '' : String(value);
  if (RISKY_START.test(s)) s = `'${s}`;
  return `"${s.replace(/"/g, '""')}"`;
}

function toCsv(headers, rows) {
  const lines = [headers.map(csvValue).join(',')];
  for (const row of rows) lines.push(row.map(csvValue).join(','));
  return `${lines.join('\r\n')}\r\n`;
}

function parsePayload(row) {
  try {
    return JSON.parse(row.payload) || {};
  } catch {
    return {};
  }
}

/** Build a CSV covering every field seen across the given submissions. */
function submissionsCsv(rows) {
  const keys = [...new Set(rows.flatMap((r) => Object.keys(parsePayload(r))))];
  const headers = ['id', 'received', 'form', 'email_status', 'ip', ...keys];
  const data = rows.map((row) => {
    const payload = parsePayload(row);
    return [row.id, row.created_at, row.form_type, row.email_status, row.ip || '', ...keys.map((k) => payload[k] ?? '')];
  });
  return toCsv(headers, data);
}

module.exports = { csvValue, toCsv, parsePayload, submissionsCsv };
