'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { DatabaseSync } = require('node:sqlite');

// node:sqlite is the SQLite that ships with Node itself, so there is no native
// module to compile. The file it writes is a normal .sqlite database.
const dataDir = path.resolve(process.env.DATA_DIR || path.join(__dirname, '..', 'data'));
fs.mkdirSync(dataDir, { recursive: true });

const dbPath = path.join(dataDir, 'submissions.sqlite');
const db = new DatabaseSync(dbPath);

db.exec('PRAGMA journal_mode = WAL');
db.exec('PRAGMA foreign_keys = ON');
db.exec(`
  CREATE TABLE IF NOT EXISTS submissions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    form_type    TEXT NOT NULL,
    email        TEXT,
    name         TEXT,
    reference    TEXT,
    email_status TEXT NOT NULL DEFAULT 'pending',
    email_error  TEXT,
    payload      TEXT NOT NULL,
    ip           TEXT,
    user_agent   TEXT
  );
  CREATE INDEX IF NOT EXISTS submissions_form_created
    ON submissions (form_type, created_at DESC);
`);

const insertStmt = db.prepare(`
  INSERT INTO submissions (form_type, email, name, reference, payload, ip, user_agent)
  VALUES (?, ?, ?, ?, ?, ?, ?)
`);
const markStmt = db.prepare('UPDATE submissions SET email_status = ?, email_error = ? WHERE id = ?');

function insertSubmission(row) {
  const info = insertStmt.run(
    row.formType,
    row.email ?? null,
    row.name ?? null,
    row.reference ?? null,
    JSON.stringify(row.payload),
    row.ip ?? null,
    row.userAgent ?? null
  );
  return Number(info.lastInsertRowid);
}

function markEmailStatus(id, status, error) {
  markStmt.run(status, error ? String(error).slice(0, 500) : null, id);
}

function listSubmissions({ formType, limit = 20 } = {}) {
  const capped = Math.min(Math.max(Number(limit) || 20, 1), 5000);
  return formType
    ? db.prepare(
        'SELECT * FROM submissions WHERE form_type = ? ORDER BY created_at DESC, id DESC LIMIT ?'
      ).all(formType, capped)
    : db.prepare('SELECT * FROM submissions ORDER BY created_at DESC, id DESC LIMIT ?').all(capped);
}

function pruneOlderThan(days) {
  const info = db
    .prepare("DELETE FROM submissions WHERE created_at < datetime('now', ?)")
    .run(`-${Number(days)} days`);
  return Number(info.changes);
}

module.exports = {
  db,
  dbPath,
  dataDir,
  insertSubmission,
  markEmailStatus,
  listSubmissions,
  pruneOlderThan,
};
