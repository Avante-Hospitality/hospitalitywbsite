# Avante Hospitality — website

Static marketing site for Avante Hospitality, built with [Eleventy (11ty)](https://www.11ty.dev/) 3.

The header, footer and navigation used to be copy-pasted into every page. They now live in one
place and are shared by all pages.

## Requirements

Node.js 22.5 or newer — the form API uses Node's built-in `node:sqlite`, so there is no native
module to compile. (Developed on Node 26; Eleventy alone would run on Node 18.)

## Commands

```bash
npm install            # first time only
npm run dev            # everything on http://localhost:8000   <- use this
npm run build          # write the finished site to _site/
npm run clean          # delete _site/
npm run submissions    # show recent form submissions
```

`_site/` is git-ignored — it is build output, not source.

**Use `npm run dev`.** It runs two things side by side so there is a single URL:

- Eleventy rebuilds `_site/` whenever a template, partial or style changes (refresh to see it)
- the Node server serves `_site/` **and** handles `/api` and `/admin`

That combination matters. The Eleventy dev server on its own only serves static files, so the forms
and `/admin` do not exist on it — running just that and opening the pages is the usual way to hit a
confusing *"Cannot GET /admin"*.

The pieces are still available individually:

| Command | What it does |
| ------- | ------------ |
| `npm run dev` | Eleventy watch **+** Node server, together on `:8000` (recommended) |
| `npm run api` | Node server only — site, forms and admin; no template rebuilding |
| `npm run serve` | Eleventy dev server only, with live reload — **no** forms or admin |
| `npm start` | One-off build, then the Node server |

## Layout

```
src/                        page templates (this is what you edit)
  _data/site.json           nav links, footer links, copyright, logo path
  _includes/
    layouts/                page shells (see below)
    partials/header.njk     site header  -- edit ONCE, applies to every page
    partials/footer.njk     site footer  -- edit ONCE, applies to every page
    avante/                 shared fragments + per-page styles/comments
  *.njk                     one file per page
img/                        images  } copied to _site/ as-is
styles.css                  styles  }
eleventy.config.js          build config
server/                     form API (Express + SQLite)
  index.js                  routes, and serves _site/
  db.js                     schema and queries
  forms.js                  per-form fields and validation
  mailer.js                 SMTP sending
  submissions.js            CLI to read submissions back
xneelo/                     PHP form API for xneelo Web Hosting (see "Deploying to xneelo")
  avante-app/               application code — upload NEXT TO public_html
  public_html/              api/ + admin/, merged with the built site
data/                       submissions.sqlite — git-ignored, holds personal data
.env                        local secrets — git-ignored (copy .env.example)
```

Page URLs are pinned with a `permalink` in each template's front matter, so the site keeps
serving the same `*.html` paths as before — no links or bookmarks break.

## Editing common content

| I want to change…                    | Edit                                        |
| ------------------------------------ | ------------------------------------------- |
| Header, logo, "List your property"   | `src/_includes/partials/header.njk`         |
| Footer, copyright                    | `src/_includes/partials/footer.njk`         |
| Nav / footer links                   | `src/_data/site.json`                       |
| Global colours, typography           | `styles.css`                                |

The header highlights the current page automatically (each page sets `navKey` in its front
matter), and the footer omits a link to the page you are already on.

## Adding a page

Create `src/mypage.njk`:

```njk
---
layout: layouts/base.njk
title: "My Page — Avante Hospitality"
permalink: /mypage.html
navKey: home
---

<section class="page-hero">
  ...
</section>
```

Add it to `headerNav`/`footerNav` in `src/_data/site.json` if it belongs in the menus.

## The avante-* pages

These six pages are campaign landing/application pages with their own design, so they have
their own layouts rather than using the main site header/footer:

| Pages                                              | Layout                        | Notes                                    |
| -------------------------------------------------- | ----------------------------- | ---------------------------------------- |
| `avante-affiliate-landing`, `avante-property-owner-landing` | `layouts/avante-landing.njk`  | AVANTE TRAVEL branding, own stylesheet   |
| `avante-become-affiliate-form`, `avante-property-affiliate-form` | `layouts/avante-form.njk`     | AVANTE TRAVEL branding, per-page CSS     |
| `avante-channel-manager-listing`, `avante-loyalty-program` | `layouts/base-tailwind.njk`   | **main site header/footer**, per-page CSS |

`layouts/base-tailwind.njk` is the normal main-site chrome — the same header and footer as every
other page — plus Tailwind, for the pages that still rely on Tailwind's utility classes.

Each avante page sets a `styleFile` in its front matter pointing at its own style fragment in
`src/_includes/avante/`. The two `AVANTE TRAVEL` forms also set a `commentFile` for their head
notes, and the `AVANTE TRAVEL` landing pages share `src/_includes/avante/landing.css`.

## Forms and submissions

Four pages have forms. Each posts JSON to the API, which **saves the submission to the database first
and emails it second** — so a mail outage never loses a submission.

| Page                                   | Endpoint                                       |
| -------------------------------------- | ---------------------------------------------- |
| `avante-channel-manager-listing.html`  | `POST /api/submit.php?form=listing`             |
| `avante-loyalty-program.html`          | `POST /api/submit.php?form=loyalty`             |
| `avante-become-affiliate-form.html`    | `POST /api/submit.php?form=affiliate`           |
| `avante-property-affiliate-form.html`  | `POST /api/submit.php?form=property-affiliate`  |

The API lives in `server/` and also serves `_site/`, so the site and the API share one origin in
production. If you ever host the API on its own domain, set `API_BASE` in the page's script to that
URL (it is at the top of each form's script); leave it empty when the API serves the site itself.

There are two implementations of that API, and **both accept the same requests and return the same
JSON**:

- `server/` — Node, for local development and Node hosts.
- `xneelo/` — PHP, for xneelo Web Hosting, which cannot run Node. See
  [Deploying to xneelo](#deploying-to-xneelo-web-hosting-php).

Requests are validated against the field list in `server/forms.js` and throttled to 15 per IP per
15 minutes. A hidden `_gotcha` field can be added to any form to make bots fail silently.

### Email

Email goes out over SMTP using `.env` (copy `.env.example`). Recipients are configuration, not
page content:

- `MAIL_TO` — usually `info@avantehospitality.co.za`
- `MAIL_CC` — the additional recipients
- `SMTP_HOST` / `SMTP_PORT` / `SMTP_USER` / `SMTP_PASS` — any SMTP provider

**Without SMTP settings the forms still work** — submissions are saved and flagged
`email_status = skipped`. `GET /api/health` reports `email: configured` or `not-configured`.
The submitter's address is set as `Reply-To`, so hitting reply goes back to them.

### Reading submissions back

```bash
npm run submissions                      # last 20, all forms
npm run submissions -- --type listing    # last 20 of one form
npm run submissions -- --limit 200       # more
npm run submissions -- --csv subs.csv    # export everything to CSV
npm run submissions -- --prune 730       # delete anything older than 730 days
```

The store is `data/submissions.sqlite` — a normal SQLite file that opens in any SQLite tool. It
holds personal data, so it is git-ignored: never commit it, and treat any copy as sensitive.

### Viewing and exporting

There is a small admin page at **/admin** that lists submissions in a table with a filter per form,
paging, a per-row field breakdown, and an **Export CSV** button that respects the current filter.

- It is **disabled until `ADMIN_PASSWORD` is set** in `.env`. With no password it returns 503 rather
  than showing anything — deliberate, so the data can't be exposed by forgetting a setting.
- Signing in sets a signed, `HttpOnly`, `SameSite=Strict` session cookie (12 hours). Login attempts
  are throttled, and form posts from another site are rejected.
- The page runs **no JavaScript at all** (CSP blocks scripts) and every stored value is
  HTML-escaped, so a submission cannot inject script into whoever is reading it.
- The CSV export neutralises values starting with `=`, `+`, `-` or `@`, so a submission cannot turn
  into a formula when the file is opened in Excel.

Use a long unique password, change it before going public, and serve the API over HTTPS in
production — the session cookie is marked `Secure` automatically on HTTPS requests.

For cron jobs and scripting, `npm run submissions` needs no password because it reads the database
directly on the server.

### Local testing

```bash
cp .env.example .env      # add SMTP settings if you want to watch emails go out
npm run dev               # open http://localhost:8000
```

To see the emails without sending real mail, run a local mail catcher (such as Mailpit or
`npx maildev`) and point `SMTP_HOST=127.0.0.1`, `SMTP_PORT=1025` at it.

## Deploying

Two things ship: the static site (`_site/`) and the form API (`server/`). They can run together or
apart.

### Option A — one Node host (simplest; forms work as-is)

Deploy the repo to Render, Railway, Fly.io or a VPS with the start command:

```bash
npm ci && npm run build && node server/index.js
```

The API serves `_site/` itself, so there is a single origin and nothing to configure. Most hosts
provide `PORT`.

> **Attach a persistent disk/volume and set `DATA_DIR` to its mount path** (e.g. `DATA_DIR=/var/data`).
> Most of these hosts give you an ephemeral filesystem, so a SQLite file left in the app directory is
> **erased on every deploy or restart**. Back it up regularly.

### Option B — static site on GitHub Pages, API elsewhere

Pushing to `main` triggers [.github/workflows/deploy.yml](.github/workflows/deploy.yml), which runs
`npm ci && npm run build` and publishes `_site/` to Pages. **One-time setup:** *Settings → Pages →
Build and deployment* → Source = **GitHub Actions**. Until that is set the workflow fails at the
`configure-pages` step with a message saying Pages is not enabled.

GitHub Pages cannot run Node, so the form API has to be hosted separately (Option A's host, or any
Node host) and each form's `API_BASE` must point at it, with `CORS_ORIGIN` set to the Pages domain.
**Until the API is reachable, the four forms will show an error when submitted.**

### Either way

Do not commit `.env` or `data/` — both are git-ignored on purpose.

## Deploying to xneelo Web Hosting (PHP)

xneelo's Web Hosting is a managed **LAMP/PHP** platform. Node cannot run there, and their
[Acceptable Use Policy](https://xneelo.co.za/legal/acceptable-use-policy/) does not allow a
long-running server process on shared hosting. `xneelo/` therefore holds the same form API written
in **PHP**, so the whole site runs on the hosting package on its own.

The site is served from a folder inside `public_html` rather than from its root, which keeps its files
clear of the other domains in the account. A short block in `public_html/.htaccess` maps the domain
root onto that folder, so the address bar never shows the folder name. Every rule in that block is
limited to this site's own domain, because the other domains share that file — see the notes below.

### What goes where

Everything for the site lives in one folder, `public_html/avantehospitality`:

```
public_html/
  .htaccess              the lines in xneelo/HTACCESS-ADDITIONS.txt go here
  avantehospitality/     upload this whole folder
    index.html ...       the built site (from _site/)
    api/                 submit.php, health.php
    admin/index.php      the submissions admin (the footer's "Admin" link)
    avante-app/          the application: config.php, lib/, bin/
```

### Steps

1. Build the site: `npm run build`.
2. In the xneelo Control Panel open **Manage MySQL** and create a database and a user. Copy the
   exact names it shows you.
3. Fill in `avante-app/config.php` — the packaged zip already has it filled in. From a fresh clone,
   copy `config.example.php` to `config.php` first.
4. Upload using the panel's **File Manager**, or SFTP on port 22: put the whole `avantehospitality/`
   folder into your existing `public_html/`.
5. Add the lines from `xneelo/HTACCESS-ADDITIONS.txt` to `public_html/.htaccess`. If that file already
   exists, paste them at the **end** rather than replacing it — the last line stops Apache processing
   any further rules. Each line is limited to `avantehospitality.co.za`, so the other domains cannot
   be affected by them.
6. Pick a PHP version (8.2 or newer) with the panel's **PHP Config** tool.
7. Open `https://yourdomain.co.za/api/health.php`. You want
   `{"ok":true,"database":"ok","email":"configured",...}`.

There is no install step to forget: the tables are created the first time a form is submitted.

### Sending email

xneelo requires SMTP authentication. Use the mailbox settings from the Control
Panel — note the **outgoing** server is `smtp.<domain>`, which is not the same as
the incoming `mail.<domain>` server:

```php
'smtp' => ['host' => 'smtp.avantehospitality.co.za', 'port' => 465, 'user' => 'web@avantehospitality.co.za', 'pass' => '...'],
'mail' => ['from' => 'Avante Website <web@avantehospitality.co.za>', 'to' => 'info@avantehospitality.co.za'],
```

Port `465` uses TLS from the first byte and `587` uses STARTTLS; leave `secure` as `null` and the
port decides. The `from` address is the authenticated mailbox, because the mail server expects the
sender to match the account; the submitter is still set as `Reply-To`, so replying goes to them.
Without these settings the forms still work — submissions are saved and flagged `skipped`, exactly
like the Node version.

To prove delivery end to end, sign in at `/admin` → **Diagnostics** → **Send a test email**; it shows
the verdict of the SMTP conversation. From SSH you can also run:

```bash
php /usr/home/YOURUSER/avante-app/bin/selfcheck.php --email
```

### Retention (cron)

Submissions older than `retention_days` (default 730) are deleted by:

```bash
php /usr/home/YOURUSER/avante-app/bin/prune.php
```

Add it in the panel's **Cronjob Manager** — once a day is plenty (xneelo allows at most 10 jobs, and
no more often than every two hours).

### Notes

- `avante-app/config.php` holds your database and mailbox passwords. It is git-ignored, and it ships
  inside the site folder protected by `avante-app/.htaccess`, which denies all web access — a pattern
  xneelo documents. If you would rather keep it outside the web root entirely, upload `avante-app`
  next to `public_html` (or one level above it when the site is nested); the app looks in all three
  places.
- **The `.htaccess` block is the one thing added by hand**, and it is only needed for the folder
  mapping. Never replace an existing `public_html/.htaccess`: other sites in the account may depend on
  it. Append the block at the end.
- **A `public_html/.htaccess` is also read by the other domains in the account**, because they are
  served from folders inside `public_html`. That was verified under Apache rather than assumed, and it
  is why every rule in the block above names `avantehospitality.co.za`: without that condition the
  other domains get rewritten into this site's folder and return 500s. If an existing `.htaccess`
  there has rules with no domain condition, those rules are already being applied to the other
  domains — worth knowing, though nothing needs changing today.
- Other domains stay reachable on their own addresses, but no longer as
  `avantehospitality.co.za/<theirdomain>/`, because that path is rewritten into the site folder.
- The site answers as `avantehospitality.co.za` and `www.avantehospitality.co.za`. Other hostnames
  (the server's own name, for instance) will not serve the root, because the rules do not match them.
- `https://avantehospitality.co.za/avantehospitality/` redirects to the root, so the site has one
  address rather than two.
- If xneelo support will point the main domain's document root at the folder, the `.htaccess` block
  can be dropped altogether — that is the cleaner equivalent.
- Throttling, the honeypot, validation, CSV formula neutralising and the admin sign-in all behave as
  documented above. The throttle counters live in MySQL, so they survive between requests.
