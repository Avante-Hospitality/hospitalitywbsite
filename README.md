# Avante Hospitality — website

Static marketing site for Avante Hospitality, built with [Eleventy (11ty)](https://www.11ty.dev/) 3.

The header, footer and navigation used to be copy-pasted into every page. They now live in one
place and are shared by all pages.

## Requirements

Node.js 18 or newer (developed on Node 26).

## Commands

```bash
npm install      # first time only
npm run serve    # dev server with live reload -> http://localhost:8080
npm run build    # write the finished site to _site/
npm run clean    # delete _site/
```

`_site/` is git-ignored — it is build output, not source.

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

| Pages                                              | Layout                        | Notes                          |
| -------------------------------------------------- | ----------------------------- | ------------------------------ |
| `avante-affiliate-landing`, `avante-property-owner-landing` | `layouts/avante-landing.njk`  | share one stylesheet            |
| `avante-become-affiliate-form`, `avante-property-affiliate-form` | `layouts/avante-form.njk`     | per-page CSS                    |
| `avante-channel-manager-listing`, `avante-loyalty-program` | `layouts/avante-tailwind.njk` | Tailwind via CDN, per-page CSS  |

Each has a `commentFile` / `styleFile` in its front matter pointing at its own fragment in
`src/_includes/avante/`. The `AVANTE TRAVEL` landing pages share
`src/_includes/avante/landing.css`.

## Deploying

The build produces `_site/`, and only that directory should be published.

Pushing to `main` triggers [.github/workflows/deploy.yml](.github/workflows/deploy.yml), which runs
`npm ci && npm run build` and publishes `_site/` to GitHub Pages. It can also be run manually from
the Actions tab.

**One-time setup:** in the repo, go to *Settings → Pages → Build and deployment* and set **Source**
to **GitHub Actions**. Until that is done the workflow fails at the `configure-pages` step with a
message saying Pages is not enabled.
