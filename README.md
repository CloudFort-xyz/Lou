# Lou

A static-first CMS for small corporate sites, in plain PHP 8.3. There's no framework, no Composer dependencies and no Node.

- Every public page is pre-built static HTML.
- A few tiny PHP endpoints handle forms, the admin and the LLM bridge. These are coming in later milestones.
- Clients edit text and images. Layout and structure change through an LLM chat session, with a preview and an approval step before anything goes live.

See [docs/SPEC.md](docs/SPEC.md) for the full design.

## Status

This is milestone 1, the skeleton: a multilingual static builder, the template helper, automatic redirects, single-file backups, the CLI and tests.

## Quick start

```sh
php lou build           # build site/ into public/ (atomic swap)
php lou serve           # build a preview and serve it on http://localhost:8000
php lou backup          # single-file backup into var/backups/
php lou restore FILE    # restore a backup (the current state is backed up first)
php tests/run.php       # run the tests
```

On the server, point the domain's document root at `public/`. Nothing else in the repo should be web-accessible.

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
