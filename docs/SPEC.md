# Lou: specification

Status: draft v0.2 (2026-11-25)

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
├── vendor-bundled/       ← bundled single libraries (PHPMailer), no Composer needed
├── stubs/                ← files copied verbatim into every build
│   ├── .htaccess         ← security headers, caching, PHP lockdown (+ generated redirects)
│   └── _lou/             ← endpoint stubs: submit.php, admin/, mcp.php, preview.php
├── site/                 ← THE SITE (what clients and LLMs change)
│   ├── config.php        ← site settings, languages, forms (no secrets)
│   ├── menu.json         ← menu structure (page ids only)
│   ├── redirects.json    ← old URL → page id / URL, maintained automatically
│   ├── content/
│   │   ├── site/<lang>.json      ← global content per language: company info, footer
│   │   ├── strings/<lang>.json   ← UI strings ("Read more", form labels, errors)
│   │   └── pages/<page-id>/<lang>.json   ← one folder per page, one file per language
│   ├── templates/        ← plain PHP templates + partials
│   └── assets/           ← css, js, images, fonts
├── var/                  ← runtime data (gitignored)
│   ├── lou.sqlite        ← submissions, subscribers, rate limits, audit log, changesets
│   ├── staging/          ← staging copy of site/ for LLM changes
│   ├── previews/         ← preview builds, one per token
│   ├── builds/           ← build history + URL manifests, for rollback and redirects
│   └── backups/          ← single-file backups (scheduled + manual)
├── .env.php              ← secrets: SMTP, API keys, captcha keys (gitignored)
└── public/               ← BUILD OUTPUT = web root (gitignored)
```

On KeyHelp (or any panel) the domain's document root points at `lou/public`.
If a host can't change the document root, a fallback layout puts Lou in a
sibling directory and builds into `public_html`, with `output_dir` set in
config.

## 4. Content model

### Pages and stable ids

Each page is a **folder** named after its **stable page id**, with one JSON
file per language:

```
site/content/pages/about/en.json
site/content/pages/about/hu.json
```

```json
{
  "slug": "about-us",
  "title": "About us",
  "menu_label": "About",
  "description": "Meta description for search engines",
  "template": "page",
  "fields": {
    "hero": { "title": "…", "text": "…", "image": { "src": "/assets/img/x.webp", "alt": "…" } },
    "body": "<p>Rich text, sanitised HTML</p>"
  }
}
```

- The **page id** (folder name) never changes and never appears in URLs.
  The **slug** is per language and can change freely. That's what makes
  renaming and redirects safe (see 6.1).
- `home` is a reserved id that builds to the language root.
- Nested pages: `"parent": "services"` in the JSON. The URL becomes
  `/services-slug/child-slug/`, built from each language's slugs.
- **`fields` is the client-editable surface.** The inline editor can only
  change values inside `fields` (plus `title`, `menu_label` and
  `description`). It never touches templates, `template`, `slug` or
  structure.
- Field types are inferred from the template: plain text, rich text (a small
  allow-list of tags), image (`src` + `alt`) and link (`page` id or `href`,
  plus `label`).
- If a page lacks a language file, it isn't built in that language. The
  build lists it as a warning, and the language switcher hides it.

### Languages

```php
// site/config.php
'languages'        => ['hu', 'en'],
'default_language' => 'hu',
'prefix_default'   => false,   // hu at /, en at /en/
```

- URLs: `/rolunk/` (hu, default, no prefix) and `/en/about-us/` (en).
- Every page gets `<html lang>`, `hreflang` alternates (including
  `x-default`), and `sitemap.xml` lists the alternates.
- The language switcher links to the **same page** in the other language,
  not to the home page.
- No server-side language detection or redirect on `/`. It's static, and
  better for SEO and caching. An optional small JS hint ("This page is
  available in English") can be added later.

### Global content, menu and strings

- `site/content/site/<lang>.json`: company name, address, footer text,
  social links.
- `site/menu.json`: the **structure** only, as a list of page ids (nested
  for dropdowns). Labels come from each page's `menu_label` per language,
  so translating never touches the structure, and an LLM restructuring the
  menu never touches the translations.
- `site/content/strings/<lang>.json`: UI strings used by templates and
  forms (`$v->t('form.send')`).

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
  <a href="<?= $v->url('contact') ?>"><?= $v->t('cta.contact') ?></a>
</section>
```

- Output is **escaped by default**. `text()` escapes, `html()` outputs
  sanitised rich text, and `raw()` exists but is flagged in review.
- **Links use page ids, never paths:** `$v->url('contact')` resolves to the
  current language's URL for that page. Renaming a slug therefore never
  breaks internal links, and the build fails loudly on a link to a page id
  that doesn't exist.
- `editable()` prints nothing in production builds. In edit and preview
  builds it adds `data-lou-field="hero.title"` so the inline editor knows
  what to edit. **Production HTML contains no CMS markers.**
- Templates run **only at build time**, never per request.

## 6. Build and deploy

`php lou build` (and the admin's "Publish" button, which calls the same code):

1. Loads config, menu, global content and all pages, then validates them
   (missing templates, unknown page ids in links or the menu, duplicate
   slugs).
2. Renders every page in every language to `<path>/index.html` (pretty
   URLs, no rewrites needed).
3. Copies `site/assets/`, then `stubs/` (`.htaccess`, `_lou/*.php`).
4. Generates the redirect rules (6.1), `sitemap.xml` (with hreflang
   alternates), `robots.txt` and a `404.html` per language.
5. Writes a **URL manifest** (`page id + language → URL`) into
   `var/builds/<id>/manifest.json`.
6. Writes everything into a fresh temp directory next to the output, then
   **swaps it in atomically** (rename). This prevents half-deployed states.
   The previous build is kept in `var/builds/` for one-click rollback.

Image processing (resizing and WebP via GD) happens at upload time in the
admin, not at build time, so builds stay fast.

Deploy options:
- **FTP/SSH upload** of the repo, then build on the server via the admin or
  SSH.
- **Git (optional):** pull on the server (KeyHelp supports deploy hooks or a
  cron `git pull`), then `php lou build`.

### 6.1 Renames and redirects

Redirects are derived automatically, so it doesn't matter whether a change
came from the editor, an LLM or a hand edit over FTP.

- Every build compares the new URL manifest with the previous live one.
- **Moved:** a page id and language whose URL changed (slug renamed, or the
  page moved under a new parent). Lou adds `old URL → page id` to
  `site/redirects.json` automatically. Because the target is a page id, a
  later rename just updates the target, so there are never redirect chains.
- **Gone:** a URL whose page id was deleted. The build (and the LLM approval
  screen) lists it and asks for a redirect target or **410 Gone**. Until
  someone answers, it defaults to a 301 to the language's home page.
- Manual entries (`/old-campaign/ → contact`, or to an external URL) can be
  added in the admin or by an LLM.
- **Output:** `RewriteRule`/`Redirect 301` lines generated into
  `public/.htaccess`, with a documented nginx `map` snippet as an
  alternative. For hosts without either, the old path also gets a tiny
  static HTML file with `<meta http-equiv="refresh">` and a canonical link.
- Redirect loops and redirects that shadow a live URL are build errors.

## 7. Runtime endpoint for the site (`public/_lou/submit.php`)

All visitor-facing interaction goes through **one endpoint**,
`/_lou/submit.php`. The admin (`/_lou/admin/`), the LLM bridge
(`/_lou/mcp.php`) and the preview passthrough (`/_lou/preview.php`) stay
separate, because they have different auth and very different risk
profiles.

Why one endpoint for the site:
- There's one place to harden: CSRF and origin checks, rate limiting,
  honeypot, captcha, size limits and logging are written once and apply to
  everything.
- A newsletter sign-up is really just a small form with a different action.
- There's less surface area, and a new form type is configuration, not a
  new PHP file.

Each stub is three lines that `require` Lou core from outside the web
root, so a misconfigured server can never leak core source.

### Forms = definitions + actions

Every form (contact, newsletter, a quote request…) is defined in
`site/config.php`. The HTML posts `form=<id>`, and the endpoint looks the
definition up. Unknown ids are rejected.

```php
'forms' => [
  'contact' => [
    'fields'  => ['name' => 'required|max:100', 'email' => 'required|email',
                  'message' => 'required|max:5000', 'consent' => 'accepted'],
    'captcha' => 'turnstile',            // or 'recaptcha', or null
    'actions' => [
      ['store'],                         // always first: saved even if email fails
      ['email', 'to' => 'info@client.hu', 'template' => 'contact'],
      ['webhook', 'url_env' => 'ZAPIER_CONTACT_URL'],
    ],
    'success' => ['page' => 'thank-you'],   // page id: redirect target without JS
  ],
  'newsletter' => [
    'fields'  => ['email' => 'required|email', 'consent' => 'accepted'],
    'captcha' => null,
    'actions' => [['store'], ['subscribe', 'provider' => 'mailerlite', 'list' => '…']],
  ],
],
```

- **Actions** run in order: `store` (SQLite), `email` (Mailer),
  `subscribe` (NewsletterProvider) and `webhook` (generic JSON POST). If an
  action after `store` fails, the submission is kept and marked for retry,
  and the admin shows the failure.
- **Spam protection** applies to every form: a honeypot field (bots get a
  fake success), a minimum fill time, a per-visitor plus per-form rate limit
  (SQLite, IPs stored only as keyed hashes), an `Origin`/`Referer` check,
  and **optional captcha** (Cloudflare Turnstile or Google reCAPTCHA v3),
  verified server-side.
- **Minimum fill time:** `forms.js` fetches a signed timestamp token
  (`GET /_lou/submit.php?token=<form>`) when the visitor first touches the
  form. Submissions that arrive too fast, or with a forged or foreign token,
  get a fake success. Visitors without JS send no token and are still
  accepted, unless the form sets `'require_token' => true`.
- Forms work **without JavaScript** (plain POST, then a redirect to the
  success page in the visitor's language). With JS they submit via `fetch`,
  get JSON back, and show inline messages. Error texts come from
  `strings/<lang>.json`.
- A static page can't carry a per-visitor CSRF token, so the protection is
  the origin check, the signed timestamp and SameSite behaviour. This is
  enough for anonymous submissions with no session.

### Pluggable integrations

Three small interfaces, each with a config-selected implementation:

- `Mailer`: `PhpMailerMailer` (v0.1, SMTP via a **bundled PHPMailer**, no
  Composer needed). Later: `SesMailer`, `SendGridMailer`, and others.
- `NewsletterProvider`: `MailchimpProvider`, `MailerLiteProvider`,
  `WebhookProvider` (generic JSON POST for Integrately, IFTTT, Zapier, Make).
- `Captcha`: `TurnstileCaptcha`, `RecaptchaCaptcha`, `NullCaptcha`.

All other HTTP calls use PHP's built-in curl. There's no SDK.

### Storage

There's one SQLite file in `var/` (PDO, WAL mode). Tables: `submissions`
(form id, language, data, action results), `subscribers` (a local copy plus
provider sync status), `rate_limits`, `audit_log` (who changed what, from
editor, LLM or CLI), `changesets` (LLM proposals) and `previews` (tokens,
expiry).

## 8. Admin and inline editor

- At `/_lou/admin.php`: log in with a user name and password. Users are
  managed with `php lou user add|remove|list` and stored as password hashes
  in `var/users.json`. Roles: **editor** (edit content, submit, publish,
  history, see submissions) and **admin** (also assistants/tokens,
  redirects, builds/rollback, backups, deleting submissions). Sessions are hardened: their own save path in
  `var/sessions`, strict mode, a `__Host-` cookie with Secure, HttpOnly and
  SameSite=Strict, a 2 h idle and 12 h absolute timeout, CSRF tokens on every
  POST, a same-origin check, and login rate limiting. TOTP can come later.
- **Previews are static builds at secret URLs** (`/_lou/p/<128-bit token>/`)
  inside the web root, so they need no PHP to view and work on any host.
  They're never indexed, survive publishing, and expire. The admin's editing
  preview has the editor script injected. Shareable preview links are clean
  copies for the client.
- **Inline editing:** the admin loads the editing preview (with
  `data-lou-field` markers) in an iframe. Clicking a marked element edits
  it in place: text is contenteditable, and images open an upload/replace
  dialog. Saving writes the JSON field into the person's **draft** (see
  8.1) and rebuilds their preview.

### 8.1 Drafts, staging, publishing and history

```
your draft ──submit──▶ staging (shared) ──publish──▶ site/ ──build──▶ public/
```

- **Drafts.** Every admin user and every assistant token has its own draft
  in `var/drafts/<owner>/`: a copy of what they see (staging if open,
  otherwise `site/`) plus a `base` snapshot. Edits only touch the draft, so
  nobody sees anyone else's unsubmitted work. Untouched files follow
  upstream automatically (rebase), so a draft always shows the latest
  staged and published state plus the person's own changes. A draft with no
  changes left is deleted.
- **Submit** copies only the files the draft changed into staging
  (`var/staging/`, opened from `site/` on first use) and records who
  submitted what, with an optional summary. If someone else submitted a
  change to the same file meanwhile, that's a **conflict**: the person can
  take their version (revert those files in the draft) or submit theirs
  anyway. Assistants get an error telling them to re-read and redo.
- **Staging** is the shared, reviewable next version of the site, with a
  stable preview link, diffs, the submitters' summaries, validation
  problems and the redirect report. **Anyone who can edit can publish or
  reject it** (decided 2026-11-25). Reject discards staging; drafts stay.
- **Publish** checks the staging revision (no blind publishing of
  something that changed during review), validates, takes a safety backup,
  records history, copies the changed files into `site/`, builds live,
  and optionally commits `site/` to Git (`'git' => ['commit' => true]`; run
  without a shell, never pushes). Files changed in `site/` since staging
  opened (a restore, a rollback edit) are conflicts that need "overwrite".
  `redirects.json` is always merged by `from`, because live builds add
  automatic redirects.
- **History** (like WordPress revisions, but for every file, templates
  included): each publish stores the old and new version of every file it
  changed in `var/history/<id>/{before,after}/` with who submitted and
  published it. The History page lists publishes, per-file history and
  diffs. "Undo this publish", "Undo just this file" and "Restore this
  version" put those versions into the person's draft, so they go live
  through the normal submit and publish. Configurable:
  `'history' => ['keep' => 200, 'days' => 0]` (last N publishes / max age,
  0 = no limit), or `'history' => false`.
- Later: one editor plus a live viewer (a viewer link that reloads as the
  editor saves) for showing the client changes in real time; collaborative
  editing after that.
- The editor has a language switcher. Edits apply to the language being
  viewed, and a "missing translations" list shows pages without a file for
  some language.
- Renaming a page (changing its slug) is allowed for admins, and the redirect is
  created automatically (6.1).
- Image upload checks the MIME type, re-encodes through GD (which strips
  EXIF and payloads), and generates responsive sizes and WebP.
- Other screens: form submissions, subscribers, pending LLM changesets
  (diff + preview + approve/reject), build history and rollback.

## 8.1 Backups

There's a **single-file backup and restore** of everything that makes up the site.

- **Create:** `php lou backup` or the admin's "Download backup" button
  produces one `lou-backup-<site>-<date>.zip` containing:
  - `site/` (content in all languages, templates, assets and uploaded
    images, menu, redirects, config)
  - `lou.sqlite`: a consistent snapshot taken with `VACUUM INTO`, so it's
    safe while the site is live (submissions, subscribers, audit log)
  - `manifest.json`: Lou version, date, file list with SHA-256 checksums,
    languages
- **Encoding:** file names are stored as UTF-8 with the zip UTF-8 flag set
  (PHP's `ZipArchive`/libzip does this automatically, verified with
  Hungarian accented names). File contents are binary-safe. Uploaded files
  also get ASCII-normalised names (`árvíztűrő.jpg` → `arvizturo.jpg`),
  so third-party unzip tools never matter.
- **Secrets** (`.env.php`) are **excluded by default**. They can be
  included only with a password, in which case the zip is AES-256 encrypted
  via `ZipArchive`.
- **Restore:** `php lou restore <file>` or an upload in the admin. Lou
  validates the manifest and checksums, **automatically backs up the current
  state first**, then restores into **staging** so you get a preview link and
  a redirect report before anything goes live. Approving it swaps it in and
  rebuilds, exactly like an LLM changeset. The database restore can be
  skipped (content only).
- **Scheduled:** an optional cron (`php lou backup --keep=14`) keeps the
  last N backups in `var/backups/` (outside the web root). Backups are also
  downloadable from the admin.
- Builds (`public/`) are not backed up, because they're always regenerated
  from the backup.

## 9. LLM bridge

Goal: **any** assistant can restructure the site, and nothing goes live
without approval.

### Flow

1. The assistant connects to the site's bridge endpoint (`/_lou/mcp.php`).
2. It reads the site: pages, templates, menu, styles.
3. It proposes changes. These are written to **its own draft** (a copy of
   `site/`, see 8.1), never to `site/` directly.
4. Lou builds staging into a **preview** at a secret, expiring URL
   (`/_lou/p/<random token>/`, a static build, see section 8). The link is **shareable**,
   so the client can see it without logging in. It expires after 7 days by
   default and can be revoked from the admin.
5. It submits the draft to **staging**. A human reviews the diff, the
   preview and the **redirect report** (see 6.1) in the admin, then clicks
   **Publish**.
6. Publish copies the staged files into `site/`, keeps history, logs the
   change, optionally commits to Git, and builds live. **Reject** discards
   staging.

### Protocols

- **MCP (primary).** `public/_lou/mcp.php` implements the Model Context
  Protocol's Streamable HTTP transport: JSON-RPC 2.0 over POST, with plain
  JSON responses and no SSE stream (GET answers 405). Supported protocol
  versions: 2025-06-18, 2025-03-26 and 2024-11-05. Methods: `initialize`,
  `ping`, `tools/list`, `tools/call`. Batches and notifications are accepted.
- **Tools:** `describe_site` (start here: content model, `$v` helpers,
  rules, pages, templates, forms, changeset state), `list_files`,
  `read_file`, `write_file`, `delete_file`, `upload_image` (re-encoded to
  WebP into staging), `build_preview` (returns the preview URL, warnings and
  the redirect report), `get_changes` (diffs, problems, conflicts),
  `submit_for_review` (with a summary for the reviewer), `discard_changes`.
  Tool failures are returned as results with `isError: true` so the model can
  fix them.
- **Auth:** per-assistant bearer tokens (`lou_` + 192 random bits), created
  under Admin → Assistants or with `php lou token add NAME`, stored as
  SHA-256 hashes in `var/tokens.json`, revocable. `?key=` is accepted for
  clients that can only take a URL. Browser `Origin` headers must match
  `base_url` (or `bridge.allowed_origins`). Failed auth (20 per 15 min per
  IP) and calls (300 per 10 min per token) are rate-limited. OAuth 2.1 can
  come later.
- **REST fallback.** `POST /_lou/mcp.php?tool=<name>` with a JSON object body
  returns `{"ok": true, "result": …}`. `GET /_lou/mcp.php?openapi` describes
  it (OpenAPI 3.1, generated from the same tool definitions).

### Drafts for assistants

- Each token works in its own draft, exactly like an admin user (8.1).
  `build_preview` builds that draft at a stable secret link,
  `submit_for_review` moves its changes to staging with a summary, and
  `discard_changes` deletes the draft. Publishing happens in the admin.

### Guardrails

- The bridge can only write inside `site/`: `templates/`, `content/`,
  `assets/`, `config.php`, `menu.json`, `redirects.json`. Paths must be
  ASCII, with no dotfiles and no `..`. PHP is only allowed in `templates/`
  and `config.php`. Text files only (php, json, css, js, svg, txt, md,
  html), images via `upload_image`. Max 512 KB per file, valid UTF-8.
- **Template PHP is checked against an allowlist** (tokenizer based, not a
  deny-list), because it runs at build time on the server. Allowed: `$v->…`
  calls, plain variables, loops and conditions, and a list of pure string,
  array, math and date functions. Rejected: any other function, `new`,
  static calls, include/require, eval, backticks, superglobals, closures,
  variable variables and functions, dynamic member names, method calls on
  anything but `$v`. Parse errors are reported.
- JSON must parse. SVG must not contain scripts, event handlers,
  `javascript:`, `foreignObject` or entities. `config.php` must return an
  array, and the whole staged site must load (`Site::load`) before a preview
  or approval.
- Every write is audit-logged (`source = 'llm'`, actor = token name).

## 10. Security model (summary)

- The public surface is static files plus `_lou/*.php`. `.htaccess` denies
  execution of any other PHP under the web root.
- `.htaccess` sets a strict CSP, HSTS, `X-Content-Type-Options`,
  `Referrer-Policy` and `Permissions-Policy`, and gives assets long cache
  lifetimes with hashed file names.
- Secrets live only in `.env.php` outside the web root.
- Every write path (editor, bridge, forms) is authenticated or
  rate-limited, validated and logged.
- Preview tokens are 128-bit random, expire, can be revoked, and are sent
  with `X-Robots-Tag: noindex`.

## 11. Requirements

- PHP 8.3+ with `pdo_sqlite`, `curl`, `gd`, `mbstring`, `openssl`.
- Apache with `.htaccess` (or the equivalent nginx rules, documented).
- Write access to `var/` and to the parent of `public/`, for the atomic swap.

## 12. Milestones

1. **Spec and skeleton** (this PR, then the skeleton): the builder,
   View/templates, multilingual routing, redirects, an example site in two
   languages, the CLI, smoke tests and CI.
2. **Forms and integrations:** contact form, SQLite, SMTP, newsletter
   adapters, captcha, and the first real client site.
3. **Admin and inline editor:** auth, field editing, image pipeline,
   submissions view, publish and rollback, backups (the CLI backup and
   restore can ship earlier, with the skeleton).
4. **LLM bridge:** MCP endpoint, staging, previews, approve/reject, optional
   Git commits, REST fallback.

## 13. Open questions

- Which languages does the first client need, and which one is the default?
- Should the default language live at `/` or at `/<lang>/` too?
- Translation workflow: should the admin show the languages side by side? Could an LLM
  draft missing translations into staging?
- Git commit identity and push target on the server (deploy key?).
