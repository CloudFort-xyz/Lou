# Deploying Lou on cPanel

This guide sets Lou up on cPanel shared hosting with **Git Version Control**, using SSH or the cPanel Terminal.

## How it fits together

```
~/repositories/lou/    cPanel's Git clone. Code only, never served, never edited by Lou.
        │  "Deploy HEAD Commit" runs .cpanel.yml → php lou deploy ~/lou
        ▼
~/lou/                 the live install
  src/ stubs/ vendor-bundled/ lou     replaced on every deploy
  site/                content, templates, assets: the server's copy is the truth
  var/                 database, builds, backups, drafts, staging
  .env.php             secrets
  public/              build output = the domain's document root
```

- **Git deploys code only.** `php lou deploy` copies the code folders into `~/lou`. It creates `site/` from the repository only on the first deploy. After that, the site belongs to the editor, the assistants and History, so a deploy never overwrites it. `var/` and `.env.php` are never touched.
- **Every build is atomic.** The new site is rendered next to `public/` and swapped in by renaming, so visitors never see a half-built site. The previous build goes to `var/builds/` for rollback.
- Git commit on publish stays off (`~/lou` isn't a Git checkout).

## 1. PHP

1. **MultiPHP Manager**: set the domain to **PHP 8.3** (`ea-php83`) or newer.
2. **Extensions**: you need `pdo_sqlite`, `gd` (with WebP), `curl`, `mbstring`, `zip`, `dom` and `openssl`. `intl` and `exif` are recommended. On EasyApache they're usually already on. On CloudLinux, enable them under **Select PHP Version**.
3. On the command line, `php` is often an older system PHP, so **always use the full path**. To save typing, add an alias:

   ```sh
   echo "alias php='/opt/cpanel/ea-php83/root/usr/bin/php'" >> ~/.bashrc && source ~/.bashrc
   php -v
   ```

   On CloudLinux the path is `/opt/alt/php83/usr/bin/php`. Cron jobs don't read `~/.bashrc`, so use the full path there.

## 2. Clone the repository

The repository is private, so cPanel needs a read-only deploy key.

1. In SSH or the Terminal:

   ```sh
   ssh-keygen -t ed25519 -f ~/.ssh/lou_deploy -N "" -C "cpanel deploy"
   cat >> ~/.ssh/config <<'EOF'
   Host github.com
     IdentityFile ~/.ssh/lou_deploy
     IdentitiesOnly yes
   EOF
   chmod 600 ~/.ssh/config
   cat ~/.ssh/lou_deploy.pub
   ```

2. On GitHub, open the repository and go to **Settings → Deploy keys → Add deploy key**. Paste the public key and leave write access off.
3. Run `ssh -T git@github.com` once to accept GitHub's host key.
4. In cPanel, open **Git Version Control → Create**:
   - Clone URL: `git@github.com:CloudFort-xyz/Lou.git`
   - Repository path: `repositories/lou`. Don't use `lou` or anything inside `public_html`.
   - Repository name: `Lou`

   To deploy a client branch, check it out under **Manage → Basic Information → Checked-Out Branch**.

### Installing somewhere else, or several sites

Some hosts give each domain its own folder, for example `~/domains/lou.example.com/` with the document root at `public/` inside it. That folder can be the install, as long as the clone stays elsewhere. List install folders in `~/.lou-target`, one per line:

```sh
echo ~/domains/lou.example.com >> ~/.lou-target
echo ~/domains/client-b.hu     >> ~/.lou-target
```

A deploy without a folder argument (which is what `.cpanel.yml` runs) installs into every folder listed, or into `~/lou` when the file is missing. Every site runs the same Lou code, and each keeps its own `site/`, `var/` and `.env.php`. If one site fails, the others still deploy, and the output names the one that failed. To add a site, create its domain with the document root `<folder>/public`, add the folder to the file, and deploy. The first deploy seeds the new site.

All sites listed move to a new Lou version together. If a site needs to stay on an older version, give it its own clone on a branch and run `php lou deploy <folder>` from that clone.

Everywhere this guide says `~/lou`, read your folder instead.

## 3. First deploy

In **Git Version Control → Manage → Pull or Deploy**, click **Deploy HEAD Commit**. You can also run it yourself:

```sh
cd ~/repositories/lou && php lou deploy ~/lou
```

This creates `~/lou`, seeds `site/` and builds `~/lou/public`. It prints a short check. Warnings at this point are expected (base_url, .env.php, mail).

## 4. Point the domain at `public/`

**Domains** (or **Addon Domains** on older cPanel): set the addon domain's document root to **`lou/public`**. If you create the domain after the first deploy, enter `lou/public` when you create it.

Nothing else under `~/lou` is reachable from the web.

> **Main domain instead?** Its document root is fixed to `public_html`, so replace that with a symlink once `~/lou/public` exists:
> `mv ~/public_html ~/public_html.old && ln -s ~/lou/public ~/public_html`.
> The atomic swap renames the folder *behind* the symlink, so the link keeps working. Don't do it the other way round, with `output_dir` pointing at a symlink.

Turn on HTTPS with **SSL/TLS Status → Run AutoSSL**.

## 5. Configure

```sh
cd ~/lou
cp .env.example.php .env.php && chmod 600 .env.php
nano .env.php            # API keys, captcha secrets (if any)
php lou check            # should end with "All required pieces are there"
php lou user add bence --role=admin
php lou build
```

Then open `https://client-domain.hu/_lou/admin.php` and set the site address under **Settings**. It's used for the sitemap, language links, preview links and the bridge URL. Until you set it, the admin shows a red banner, and `php lou check` reports it as FAIL.

On the same screen, under **Email**, choose **Send with SMTP** and enter the sender address and the SMTP login (cPanel → Email Accounts → Connect Devices shows the server, port and user). Then use **Send test email**. Until SMTP is set up, form emails only go to `var/mail.log`.

## 6. Cron jobs

In **Cron Jobs**, use the full PHP path:

```
# hourly: re-send form emails/newsletter sign-ups that failed (e.g. SMTP was down)
0 * * * *  /opt/cpanel/ea-php83/root/usr/bin/php ~/lou/lou retry >/dev/null
# nightly: single-file backup of site/ + database, keep 14
30 3 * * * /opt/cpanel/ea-php83/root/usr/bin/php ~/lou/lou backup --keep=14 >/dev/null
```

Copy backups off the server from time to time. **Admin → Backups** can download them.

## Updating Lou

Push to GitHub, then in cPanel open **Git Version Control → Manage → Pull or Deploy**. Click **Update from Remote**, reload the page, then click **Deploy HEAD Commit**. The code is replaced and the sites are rebuilt. Content isn't touched.

### Automatic deploys (optional)

cPanel doesn't notice pushes to GitHub. To have merges go live by themselves, add a cron job that checks every 5 minutes:

```
*/5 * * * * /opt/cpanel/ea-php83/root/usr/bin/php ~/repositories/lou/lou deploy --pull >> ~/lou-deploy.log 2>&1
```

`--pull` runs `git fetch` in the clone and exits silently when the branch has nothing new. When there are new commits, it fast-forwards, then deploys to every folder in `~/.lou-target` using the new code. A lock stops two runs from overlapping. It never runs `git reset`, so if the clone ever has local changes, the fast-forward fails and the error goes to the log. Deploying by hand from cPanel keeps working alongside it.

## Moving a site you built locally onto the server

Since `site/` on the server is the truth, a deploy won't push template or content changes into it. To replace the server's site with one you built locally:

```sh
php lou backup --out=client.zip               # locally
# upload client.zip, then on the server:
php lou restore client.zip --no-db            # the server's current state is backed up first
```

For layout changes after launch, use an assistant through the bridge, or the admin. Then publish.

## Troubleshooting

- **Deploy HEAD Commit is greyed out**: `.cpanel.yml` must be in the checked-out branch, and the clone must have no local changes. Never edit files in `~/repositories/lou`.
- **What did the deploy do?** cPanel writes a log per deploy to `~/.cpanel/logs/` (`vc_*_git_deploy.log`).
- **"No PHP 8.3+ binary found"**: run `ls /opt/cpanel/ /opt/alt/` to find your host's PHP path, then edit the list in `.cpanel.yml`.
- **Admin works on the CLI but not in the browser**: the domain's PHP (MultiPHP Manager) is a different version, or it's missing an extension. Set it to the same version that `php lou check` used.
- **500 error on every page**: the host doesn't allow a directive in `public/.htaccess`, usually `Options`. Remove it from `stubs/.htaccess` and redeploy. The error log is at `~/logs/` or under **Metrics → Errors**.
- **Rollback**: **Admin → Builds**, or restore a backup.
