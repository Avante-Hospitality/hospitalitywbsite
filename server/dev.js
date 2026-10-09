'use strict';

/**
 * Dev runner - one URL, no port confusion.
 *
 *   - Eleventy rebuilds _site/ whenever a template, partial or style changes.
 *   - The Node server serves _site/ and handles /api and /admin.
 *
 * Both are needed, because the Eleventy dev server alone cannot run the forms
 * or the admin page. Everything is reachable at http://localhost:PORT.
 */

const path = require('node:path');
const { spawn } = require('node:child_process');

const ROOT = path.join(__dirname, '..');
const ELEVENTY = path.join(ROOT, 'node_modules', '@11ty', 'eleventy', 'cmd.cjs');
const PORT = process.env.PORT || 8000;

const children = [];
let stopping = false;

function shutdown() {
  if (stopping) return;
  stopping = true;
  for (const child of children) {
    try {
      child.kill();
    } catch {
      /* already gone */
    }
  }
  setTimeout(() => process.exit(0), 150).unref();
}

function run(label, args, colour) {
  const child = spawn(process.execPath, args, { cwd: ROOT, stdio: ['ignore', 'pipe', 'pipe'] });
  const tag = `\u001b[${colour}m${label.padEnd(5)}\u001b[0m `;

  for (const stream of [child.stdout, child.stderr]) {
    let partial = '';
    stream.setEncoding('utf8');
    stream.on('data', (chunk) => {
      partial += chunk;
      const lines = partial.split('\n');
      partial = lines.pop();
      for (const line of lines) {
        if (line.trim()) process.stdout.write(tag + line.trimEnd() + '\n');
      }
    });
  }

  child.on('exit', (code, signal) => {
    if (stopping) return;
    process.stdout.write(tag + `exited (${signal || code})\n`);
    shutdown();
  });

  children.push(child);
  return child;
}

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);

console.log(`Starting dev: templates rebuild on save, everything served on http://localhost:${PORT}\n`);

run('11ty', [ELEVENTY, '--watch'], '35');

// Give the first build a moment so the server does not start against a stale _site.
setTimeout(() => {
  run('site', ['--watch-path=server', '--env-file-if-exists=.env', 'server/index.js'], '36');
}, 1500);
