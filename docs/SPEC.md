# Lou: specification

Status: draft v0.1 (2026-11-25)

## 1. What Lou is

Lou is a static-first CMS for small corporate websites. It is built for
sites that are mostly content and have very little interaction.

- Every public page is **pre-built static HTML**. A page view runs no PHP and
  touches no database.
- A **handful of small PHP endpoints** cover the interactive bits: contact
  forms, newsletter sign-up, and (behind auth) the admin and the LLM bridge.
- **Clients edit content** (text, images) in a light inline editor.
- **Structure and design** (layout, templates, menus, styles) change through
  an **LLM chat session** of the client's choice. Every change lands in a
  staging copy with a preview link and goes live only after a human approves
  it.
- It runs on **plain shared PHP hosting** (PHP 8.3+, FTP/SSH). There's no Node,
  no build server, no framework, and no database server.

### Non-goals

- Running a generic application platform, a shop, user accounts for site
  visitors, or comments.
- Competing with WordPress's plugin ecosystem. Extensions are code, and an
  LLM writes most of them.
- Running per-request templating in production.

## 2. Principles

1. **Static by default.** Anything that can be computed at build time is.
2. **Small attack surface.** The web root holds static files and a few
   single-purpose endpoint files. Core code, content, config and data live
   outside the web root.
3. **No framework, no required dependencies.** It uses plain PHP 8.3 with a
   PSR-4 autoloader. Composer is optional and only for dev tooling.
4. **Files are the source of truth.** Content and templates are plain files
   that diff well, so Git is a natural (but optional) history layer.
5. **Humans approve, LLMs propose.** No LLM change reaches the live site
   without an explicit approval.

## 3. Layout on disk

```
lou/                      ← repository / install root (NOT web-accessible)
├── lou                   ← CLI: php lou build | serve | …
├── src/                  ← Lou core (namespace Lou\)
├── stubs/                ← files copied verbatim into every build
│   ├── .htaccess         ← security headers, caching, PHP lockdown
│   └── _lou/             ← endpoint stubs (form.php, subscribe.php …)
├── site/                 ← THE SITE (what clients and LLMs change)
│   ├── config.php        ← site settings (no secrets)
│   ├── content/
│   │   ├── site.json     ← global content: company info, menu, footer
│   │   └── pages/*.json  ← one file per page
│   ├── templates/        ← plain PHP templates + partials
│   └── assets/           ← css, js, images, fonts
├── var/                  ← runtime data (gitignored)
│   ├── lou.sqlite        ← form submissions, subscribers, audit log
│   ├── staging/          ← staging copy of site/ for LLM changes
│   └── builds/           ← build history for rollback
├── .env.php              ← secrets: SMTP, API keys (gitignored)
└── public/               ← BUILD OUTPUT = web root (gitignored)
```

On KeyHelp (or any panel) the domain's document root points at `lou/public`.
If a host can't change the document root, a fallback layout puts Lou in a
sibling directory and builds into `public_html`, with `output_dir` set in config.

## 4. Content model

### Pages

Each page is a JSON file in `site/content/pages/`. The file name is the
slug, and `home.json` builds to `/index.html`.

```json
{
  "title": "About us",
  "template": "page",
  "description": "Meta description for search engines",
  "menu": true,
  "fields": {
    "hero": { "title": "…", "text": "…", "image": { "src": "/assets/img/x.webp", "alt": "…" } },
    "body": "<p>Rich text, sanitised HTML</p>"
  }
}
```

- **`fields` is the client-editable surface.** The inline editor can only
  change values inside `fields`. It never touches templates, `template`, or
  structure.
- Field types are inferred from the template (see below): plain text, rich
  text (a small allow-list of tags), image (`src` + `alt`) and link
  (`href` + `label`).
- Nested slugs (for example `services/consulting`) map to
  `pages/services/consulting.json`.
- Multilingual support is planned (`pages/<lang>/…`) but out of scope for v0.1.

### Global content

`site/content/site.json` holds the company name, contact details, the
menu, the footer and social links. The menu belongs to the LLM and the
admin. Clients can edit labels but not the structure.

### Why JSON and not Markdown

These are corporate pages made of structured sections (hero, features,
CTA), not long-form articles. JSON fields map 1:1 to editable regions, are
trivial for an LLM to edit safely, and need no parser. If a blog or news
section is added later, it gets Markdown entries with a bundled
single-file parser.

## 5. Templates

Templates are plain PHP files that receive a `View` helper as `$v`.

```php
<?php $v->layout('layout') ?>
<section class="hero">
  <h1 <?= $v->editable('hero.title') ?>><?= $v->text('hero.title') ?></h1>
  <?= $v->image('hero.image', class: 'hero__img') ?>
</section>
```

- Output is **escaped by default**. `text()` escapes, `html()` outputs
  sanitised rich text, and `raw()` exists but is flagged in review.
- `editable()` prints nothing in production builds. In edit and preview
  builds it adds `data-lou-field="hero.title"` so the inline editor knows
  what to edit. **Production HTML contains no CMS markers.**
- Templates run **only at build time**, never per request.

## 6. Build and deploy

`php lou build` (and the admin's "Publish" button, which calls the same code):

1. Loads config, `site.json` and all pages, then validates them.
2. Renders every page to `<slug>/index.html` (pretty URLs, no rewrites
   needed).
3. Copies `site/assets/`, then `stubs/` (`.htaccess`, `_lou/*.php`).
4. Generates `sitemap.xml` and `robots.txt`.
5. Writes everything into a fresh temp directory next to the output, then
   **swaps it in atomically** (rename). This prevents half-deployed states. The
   previous build is kept in `var/builds/` for one-click rollback.

Image processing (resizing and WebP via GD) happens at upload time in the
admin, not at build time, so builds stay fast.

Deploy options:
- **FTP/SSH upload** of the repo, then build on the server via the admin or
  SSH.
- **Git (optional):** pull on the server (KeyHelp supports deploy hooks or a
  cron `git pull`), then `php lou build`.

## 7. Runtime endpoints (`public/_lou/`)

Each endpoint is a tiny stub that `require`s Lou core from outside the
web root, so a misconfigured server can never leak core source.

| Endpoint | Purpose |
|---|---|
| `form.php` | Contact forms: validate, store in SQLite, send email |
| `subscribe.php` | Newsletter sign-up, forwarded to a provider adapter |
| `admin/` | Inline editor + publish/approve UI (session auth) |
| `mcp.php` | LLM bridge (token/OAuth auth), see section 9 |

### Forms

- Forms are **defined in config** (`site/config.php` → `forms`): fields,
  validation rules, recipients, which mailer to use, and whether captcha is
  on.
- Spam protection works in layers: a honeypot field, a minimum fill time,
  per-IP rate limiting (SQLite), and **optional captcha** (Cloudflare
  Turnstile or Google reCAPTCHA v3), verified server-side.
- Forms work **without JavaScript** (plain POST with a redirect to a
  thank-you page). With JS they submit via `fetch` and show inline messages.
- Every submission is stored in SQLite, even if mailing fails. It's
  viewable and exportable (CSV) in the admin.

### Pluggable integrations

Three small interfaces, each with a config-selected implementation:

- `Mailer`: `SmtpMailer` (v0.1, a minimal built-in SMTP client with
  STARTTLS and auth). Later: `SesMailer`, `SendGridMailer`, and others.
- `NewsletterProvider`: `MailchimpProvider`, `MailerLiteProvider`,
  `WebhookProvider` (generic JSON POST for Integrately, IFTTT, Zapier, Make).
- `Captcha`: `TurnstileCaptcha`, `RecaptchaCaptcha`, `NullCaptcha`.

All HTTP calls use PHP's built-in curl. There's no SDK.

### Storage

There's one SQLite file in `var/` (PDO, WAL mode). Tables: `submissions`,
`subscribers` (a local copy plus provider sync status), `rate_limits`,
`audit_log` (who changed what, from editor, LLM or CLI), and `changesets`
(LLM proposals).

## 8. Admin and inline editor

- At `/_lou/admin/`: log in with a username and password (bcrypt hash in
  `.env.php`), plus an optional TOTP later. Sessions are hardened (secure,
  httponly, SameSite=Strict cookies, CSRF tokens).
- **Inline editing:** the admin loads a preview build (with
  `data-lou-field` markers) in an iframe. Clicking a marked element edits
  it in place: text is contenteditable, and images open an upload/replace
  dialog. Saving writes the JSON field and rebuilds the affected page.
- Image upload checks the MIME type, re-encodes through GD (which strips
  EXIF and payloads), and generates responsive sizes and WebP.
- Other screens: form submissions, subscribers, pending LLM changesets
  (diff + preview + approve/reject), build history and rollback.

## 9. LLM bridge

Goal: **any** assistant can restructure the site, and nothing goes live
without approval.

### Flow

1. The assistant connects to the site's bridge endpoint.
2. It reads the site: pages, templates, menu, styles.
3. It proposes changes. These are written to **`var/staging/`** (a copy of
   `site/`), never to `site/` directly.
4. Lou builds staging into a **preview** with a secret, expiring URL.
5. The assistant shares the preview link. A human reviews the diff and the
   preview in the admin, then clicks **Approve**.
6. Approve copies staging over `site/`, logs the change, optionally commits
   to Git, and builds live. **Reject** discards staging.

### Protocols

- **MCP (primary).** `public/_lou/mcp.php` implements the Model Context
  Protocol's Streamable HTTP transport (JSON-RPC over HTTPS). Tools:
  `list_files`, `read_file`, `write_file`, `delete_file` (all scoped
  to staging and to `site/` paths only), `build_preview`, `get_preview_url`,
  `describe_site` (conventions, field helpers, design tokens).
- **Auth:** per-assistant bearer tokens created in the admin, revocable.
  OAuth 2.1 can come later for assistants that require it (for example
  claude.ai custom connectors).
- **REST fallback.** The same operations as a small JSON API with an OpenAPI
  description, for assistants or automations without MCP.
- **Manual fallback.** Export staging as a zip, or paste a patch, for
  assistants with neither.

### Guardrails

- The bridge can only touch `site/` (templates, content, assets). It never
  touches `src/`, `stubs/`, `.env.php`, or `var/` outside staging.
- PHP in templates is linted (`php -l`) and checked against a deny-list
  (`exec`, `system`, `eval`, file and network functions, superglobals),
  because template PHP runs at build time on the server.
- Size and rate limits apply to writes, and every call is audit-logged.

## 10. Security model (summary)

- The public surface is static files plus `_lou/*.php`. `.htaccess` denies
  execution of any other PHP under the web root.
- `.htaccess` sets a strict CSP, HSTS, `X-Content-Type-Options`,
  `Referrer-Policy` and `Permissions-Policy`, and gives assets long cache
  lifetimes with hashed file names.
- Secrets live only in `.env.php` outside the web root.
- Every write path (editor, bridge, forms) is authenticated or
  rate-limited, validated and logged.

## 11. Requirements

- PHP 8.3+ with `pdo_sqlite`, `curl`, `gd`, `mbstring`, `openssl`.
- Apache with `.htaccess` (or the equivalent nginx rules, documented).
- Write access to `var/` and to the parent of `public/`, for the atomic swap.

## 12. Milestones

1. **Spec and skeleton** (this PR): the builder, View/templates, an example
   site, the CLI, smoke tests and CI.
2. **Forms and integrations:** contact form, SQLite, SMTP, newsletter
   adapters, captcha, and the first real client site.
3. **Admin and inline editor:** auth, field editing, image pipeline,
   submissions view, publish and rollback.
4. **LLM bridge:** MCP endpoint, staging, previews, approve/reject, optional
   Git commits, REST fallback.

## 13. Open questions

- Multilingual sites: are they needed for the first client?
- Should a preview be publicly shareable (secret link), or admin-only?
- Git commit identity and push target on the server (deploy key?).
