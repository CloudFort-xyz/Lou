# Working on Lou

Read docs/SPEC.md first. House rules:

- Plain PHP 8.3+ and vanilla JS only. No frameworks, no Composer runtime dependencies, no Node or build tools.
  A single well-known library may be bundled in `vendor-bundled/` (e.g. PHPMailer) if agreed.
- `declare(strict_types=1);`, `final` classes, readonly properties, small classes in `src/` under `Lou\`.
- Nothing per-request except the stubs in `stubs/_lou/`. Everything else runs at build time or in the CLI.
- Templates escape by default (`$v->text`, `$v->t`, `$v->url`). Avoid `$v->raw`.
- Links between pages use page ids (`$v->url('contact')`), never hard-coded paths.
- User-facing strings come from `site/content/strings/<lang>.json`. Every page/string must exist in every configured language.
- Run `php tests/run.php` before committing. Add a test for every behaviour change.
