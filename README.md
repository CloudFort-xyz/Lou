# Lou

A static-first CMS for small corporate sites, in plain PHP 8.3. There's no framework, no Composer dependencies and no Node.

- Every public page is pre-built static HTML.
- A few tiny PHP endpoints handle forms, the admin and the LLM bridge.
- Clients edit text and images. Layout and structure change through an LLM chat session, with a preview and an approval step before anything goes live.

See [docs/SPEC.md](docs/SPEC.md) for the full design.

## Status

- Milestone 1 (skeleton): a multilingual static builder, the template helper, automatic redirects, single-file backups and the CLI.
- Milestone 3 (admin): `/_lou/admin.php` with login, click-to-edit text, rich text and images in a live preview, page settings, publish, shareable preview links, submissions/subscribers, redirects, builds and rollback, and backups.
- Milestone 2 (forms): one submit endpoint for all forms, SQLite storage, SMTP via bundled PHPMailer, Mailchimp/MailerLite/webhook newsletter sign-ups, webhooks, layered spam protection with optional Turnstile/reCAPTCHA, and progressive-enhancement JS.
- Milestone 4 (LLM bridge): an MCP server at `/_lou/mcp.php` (plus a REST/OpenAPI fallback) that lets any assistant edit a staged copy of `site/`, build a private preview and submit it. Its changes land on staging for review. Tokens and connection snippets live under **Assistants**.

## Install

Download `lou-<version>.zip` from the [releases](https://github.com/CloudFort-xyz/Lou/releases), upload the `lou/` folder next to your web root, point the domain at `lou/public` (or copy `install.php` into a fixed `public_html`) and open the site. The web installer checks the server, sets up the site, your admin account and email, and builds. Updates come from **Admin → Updates** or `php lou update`, verified against the release signature. No Git, SSH or Composer is needed. See [docs/install.md](docs/install.md).

Git-based deploys (`php lou deploy`, cPanel's `.cpanel.yml`) remain available: see [docs/deploy-cpanel.md](docs/deploy-cpanel.md).

## Quick start (from a checkout)

```sh
php lou build           # build site/ into public/ (atomic swap)
php lou user add NAME --role=admin   # create an admin user, then open /_lou/admin.php
php lou serve           # build a preview and serve it on http://localhost:8000
php lou check           # check PHP version, extensions, folders and config
php lou update          # update Lou from the latest release (installs from a zip)
php lou release         # build lou-<version>.zip into dist/
php lou deploy ~/lou    # copy the code from this checkout into an install and build (cPanel)
php lou backup          # single-file backup into var/backups/
php lou restore FILE    # restore a backup (the current state is backed up first)
php lou submissions     # export form submissions as CSV
php lou retry           # re-run failed email/newsletter/webhook actions
php lou token add NAME  # bridge token for an assistant (or create one under Admin → Assistants)
php tests/run.php       # run the tests
```

On the server, point the domain's document root at `public/`. Nothing else in the repo should be web-accessible.

`php lou check` lists missing PHP pieces (version, extensions, writable folders, config). For cPanel with Git Version Control, `.cpanel.yml` runs `php lou deploy ~/lou`, which copies the code into the live install, seeds `site/` on the first deploy only, and builds. See [docs/deploy-cpanel.md](docs/deploy-cpanel.md).

Email (sender, SMTP server and password) is set by an admin under **Settings → Email**, which also sends a test email. The SMTP password is kept in `var/secrets.json` (outside the web root, mode 0600) and is never shown again. Other secrets (API keys, captcha secrets) go in `.env.php` next to `site/`. See `.env.example.php`. SMTP in `.env.php` still works, and the admin values take over once saved.

## Layout

```
lou            CLI
src/           Lou core (namespace Lou\)
stubs/         copied into every build (.htaccess, /_lou/ endpoints)
site/          the site: config, menu, redirects, content/, templates/, assets/
var/           runtime data, gitignored (database, builds, backups, staging)
public/        build output = web root, gitignored
```

## Content in two lines

- `site/content/pages/<page-id>/<lang>.json` is one file per page per language. The page id never changes, and the `slug` is per language.
- Templates are plain PHP using `$v`: `$v->text('hero.title')`, `$v->url('contact')`, `$v->t('form.send')`, `$v->editable('hero.title')`.
- Forms: define them under `forms` in `site/config.php`, then use `<form<?= $v->formAttrs('contact') ?>><?= $v->formFields('contact') ?> … <?= $v->captcha('contact') ?></form>`.

## Drafts, staging and history

Everyone (each admin user and each assistant) edits their own **draft**. **Submit** moves a draft's changes to **staging**, and anyone who can edit can **publish** staging under **Review**. Two people changing the same file is flagged as a conflict. Every publish keeps the old and new version of each changed file, templates included, under **History**. From there you can undo a publish or a single file, which puts the old version into your draft. Limit it with `'history' => ['keep' => 200, 'days' => 0]`, or turn it off with `'history' => false`.

To show a client your edits as you make them, use **Create a live viewer link** in the editor. The link reloads by itself whenever you save, using plain static polling with no extra server.

## Connecting an assistant

Create a token under **Admin → Assistants**. The page shows ready-to-paste snippets. For Claude Code:

```sh
claude mcp add --transport http lou https://example.com/_lou/mcp.php --header "Authorization: Bearer lou_…"
```

Apps that only accept a URL (claude.ai or ChatGPT custom connectors) can use `https://example.com/_lou/mcp.php?key=lou_…` with no authentication set. The assistant only ever writes to its own draft. Templates it writes are checked against an allowlist, because template PHP runs on the server at build time. Nothing goes live until someone publishes it.

Set `'git' => ['commit' => true]` in `site/config.php` to commit `site/` after each publish, if the install is a Git checkout. Lou never pushes.
