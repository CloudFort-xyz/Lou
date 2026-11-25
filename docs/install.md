# Installing Lou without Git

This is the main way to install Lou. You need PHP 8.3+ hosting and a way to upload files (FTP or the hosting panel's file manager). Git, SSH and Composer are not needed.

## 1. Download

Download `lou-<version>.zip` from the [releases page](https://github.com/CloudFort-xyz/Lou/releases). It contains one folder, `lou/`:

```
lou/
  lou, src/, stubs/, vendor-bundled/   Lou's code
  starters/example/                    a starter site
  public/install.php                   the installer
```

The zip never contains `site/`, `var/` or `.env.php`, so you can always unpack a newer one over an install safely.

## 2. Upload

Choose one of these layouts.

**A. The domain's document root can be any folder** (an addon domain or subdomain, KeyHelp, most panels). This is the best option.

1. Upload `lou/` to your home folder (for example `~/lou`), **not** inside `public_html`.
2. Set the domain's document root to `lou/public`.
3. Open `https://your-domain/`. It forwards you to the installer.

**B. The document root is fixed** (the main domain on cPanel is always `public_html`).

1. Upload `lou/` to your home folder, next to `public_html`, so you have `~/lou` and `~/public_html`.
2. Copy `lou/public/install.php` into `public_html`.
3. Open `https://your-domain/install.php`.

Lou then builds the site straight into `public_html`. It only replaces the files and folders it wrote there itself. Everything else stays, such as `cgi-bin`, `.well-known` and other domains' folders. If an old file is in the way (a default `index.html`, say), Lou moves it to `lou/var/replaced/` instead of deleting it. The PHP version lines that cPanel writes into `.htaccess` are kept on every build.

Lou refuses to run with its folder *inside* the web root, because its code, database and backups would then be downloadable.

## 3. Run the installer

The installer page first checks the server: PHP version, extensions and writable folders (the same checks as `php lou check`). If anything says FAIL, fix it in the hosting panel and reload. On cPanel that usually means **MultiPHP Manager** for the PHP version and **Select PHP Version → Extensions** for extensions.

Then fill in:

- **Setup key.** Anyone could open the installer before you, so it asks for a key that only someone with access to the files can read. Open `lou/var/install-key.txt` with FTP or the file manager and copy it.
- **Site address**, such as `https://www.example.hu`.
- **Start from**: the example starter site, or **Upload a site .zip**. That can be either of these:
  - a Lou backup (from **Admin → Backups** or `php lou backup`), which moves a site together with its form submissions;
  - any zip with a `site/` folder in it, for example GitHub's **Code → Download ZIP** of a client site repository.
- **Your admin account.**
- **Email**: keep "write emails to a log file" for now, or enter the SMTP login. You can change it later under **Settings → Email**, which also has a test button.

**Install** builds the site and creates your account. After that the installer is switched off. In layout A the build replaces `public/`, which removes the installer. In layout B the installer deletes itself.

## 4. Afterwards

- Log in at `https://your-domain/_app/admin.php`.
- Add cron jobs if the host has them (use the full PHP path, see `docs/deploy-cpanel.md`):

  ```
  0 * * * *  php ~/lou/lou retry >/dev/null
  30 3 * * * php ~/lou/lou backup --keep=14 >/dev/null
  ```
- API keys and captcha secrets go in `lou/.env.php` (copy `.env.example.php`).

## Updating

**Admin → Updates** (admins only):

- **Check for updates** asks GitHub for the latest release. Lou never checks by itself.
- **Update to x.y.z** downloads the zip, verifies it, swaps in the new code and asks for one rebuild.
- **Upload a release** does the same with a zip you downloaded yourself, for servers that can't reach GitHub. It can also go back to an older version.

You can also simply unpack the new zip over the install with FTP. The admin then shows "Lou was updated, rebuild now".

On the command line: `php lou update` (latest release), `php lou update --check`, or `php lou update --zip=lou-0.3.0.zip`.

An update only replaces Lou's code (`src/`, `stubs/`, `vendor-bundled/`, `starters/`, `lou`). Content, templates, images, the database, settings and secrets are never touched. The live site keeps working until the rebuild.

**Verification.** Each release zip lists the SHA-256 of every file in `release.json`, and the release workflow signs that list with an Ed25519 key. An install that ships the public key (`src/Setup/release-key.pub`) refuses zips that are unsigned, signed with another key, or have a changed, missing or extra file. Signature checks need the PHP `sodium` extension, which is in PHP by default.

An install that is a Git checkout, or that `php lou deploy` copies code into from a clone (it leaves `var/deployed-from.json`), updates from Git, and the Updates screen says so.

## Deploying a client site's design from a zip

If a client site lives in its own repository but the server can't pull it, open **Admin → Updates → Site design from a zip** and upload the repository as a zip (GitHub: **Code → Download ZIP**), or any zip with a `site/` folder.

- Templates and assets follow the zip. This is the same rule as `php lou deploy --site`: a file changed on the server since the last deploy (by an assistant, say) keeps the server's version and is listed as skipped.
- Content, the menu, `config.php` and uploaded images (`assets/uploads/`) are never touched.
- Templates go through the same safety check as assistants' templates.
- Nothing goes live directly. The changes land in your draft, and you submit, preview and publish them under **Review** as usual. Once published, they become the baseline for the next deploy.
- The first time (on a site that started from a starter), every file that differs from the zip is proposed, so check the diffs in Review. A site installed from a client zip already has its baseline.

## For maintainers: publishing a release

1. Once per project, run `php lou release-key`. It writes `src/Setup/release-key.pub` (commit it) and prints a secret key. Add that secret as the repository's Actions secret `LOU_SIGNING_KEY`. Keep no other copy, and never commit it.
2. Tag and push: `git tag v0.2.0 && git push origin v0.2.0`. The **Release** workflow runs the tests, builds `lou-0.2.0.zip` with the version taken from the tag, signs it and attaches it, with a `.sha256` file, to a new GitHub release.

**Unsigned test builds.** An install refuses zips that aren't signed with Lou's release key. To allow them, for example for a zip built locally with `php lou release --unsigned`, add `'allow_unsigned_updates' => true` to `.env.php`. That file is only reachable over FTP or SSH, so a stolen admin login can't turn it on. Uploading such a zip under **Upload a release** then shows a security warning with the zip's SHA-256, and installs only after you type its version. Every file is still checked against the zip's own list. Remove the line to switch it off again.

**Pre-releases.** Tag with a suffix, e.g. `git tag v0.2.0-rc.1 && git push origin v0.2.0-rc.1`. The same workflow publishes it as a GitHub *pre-release*. **Check for updates** ignores pre-releases, so only installs where someone uploads that zip under **Admin → Updates → Upload a release** get it. To test a branch without publishing anything, open **Actions → Release → Run workflow**, pick the branch, and download the signed zip from the run's artifacts (kept 14 days; the version defaults to `<current>-dev.<run number>`).

Run `php lou release --version=0.2.0` to build the zip locally into `dist/`. Once the public key is committed, building a release without the matching `LOU_SIGNING_KEY` fails, so you can't publish a zip that installs would refuse.
