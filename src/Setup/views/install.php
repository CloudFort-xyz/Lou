<?php
/** @var callable $e */
$v = static fn (string $k, string $default = ''): string => $old[$k] ?? $default;
$sel = static fn (string $a, string $b): string => $a === $b ? ' selected' : '';
$failed = !\Lou\Setup\Requirements::passed($checks);
$marks = ['ok' => 'ok', 'warn' => 'warn', 'fail' => 'FAIL'];
$starter = $v('starter', (string) array_key_first($starters));
?>
<h1>Install Lou</h1>
<?php if ($error !== null): ?><div class="alert alert-error"><?= nl2br($e($error)) ?></div><?php endif ?>

<section class="card">
  <h2>This server</h2>
  <?php if ($failed): ?><div class="alert alert-error"><strong>Something required is missing.</strong> Fix the lines marked FAIL (usually in the hosting panel's PHP settings), then reload this page.</div><?php endif ?>
  <ul class="checks<?= $failed ? '' : ' compact' ?>">
  <?php foreach ($checks as $c): ?>
    <li class="<?= $e($c['level']) ?>"><b><?= $marks[$c['level']] ?></b><span><?= $e($c['label']) ?><?= $c['detail'] !== '' ? ' <span class="muted">' . $e($c['detail']) . '</span>' : '' ?></span></li>
  <?php endforeach ?>
  </ul>
  <?php if (!$failed): ?><p class="muted">PHP <?= $e(PHP_VERSION) ?> has everything Lou needs.</p><?php endif ?>
  <p class="muted">Lou's files: <code><?= $e($root) ?></code><br>
  <?php if ($intoDocroot): ?>The site will be built into this web folder: <code><?= $e($docroot) ?></code>. Lou only replaces the files it writes there, anything else in it stays.
  <?php else: ?>The site will be built into <code><?= $e($root) ?>/public</code>, the domain's document root.<?php endif ?></p>
</section>

<form method="post" enctype="multipart/form-data" class="stack" autocomplete="off">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

  <section class="card stack">
    <h2>Setup key</h2>
    <p>To prove this is your server, open <code><?= $e($keyFile) ?></code> with FTP or your hosting panel's file manager and copy the key from it.</p>
    <label>Setup key <input name="key" required autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX-XXXX-XXXX" maxlength="40"></label>
  </section>

  <section class="card stack">
    <h2>Site</h2>
    <label>Site address
      <input type="url" name="base_url" value="<?= $e($v('base_url', $suggested)) ?>" required placeholder="https://www.example.hu">
    </label>
    <p class="muted">The public address, without a path. You can change it later under Settings.</p>
    <fieldset class="stack">
      <legend>Start from</legend>
      <?php foreach ($starters as $id => $name): ?>
        <label class="check"><input type="radio" name="starter" value="<?= $e($id) ?>"<?= $starter === $id ? ' checked' : '' ?>> <?= $e($name) ?><?= $id !== 'existing' ? ' <span class="muted">(starter site)</span>' : '' ?></label>
      <?php endforeach ?>
      <?php if (!isset($starters['existing'])): ?>
        <label class="check"><input type="radio" name="starter" value="upload"<?= $starter === 'upload' ? ' checked' : '' ?>> Upload a site .zip</label>
        <label>Site .zip <small class="muted">a Lou backup, or a zip with a <code>site/</code> folder, such as GitHub's “Download ZIP” of a client repository (up to <?= $e($maxUpload) ?>)</small>
          <input type="file" name="site_zip" accept=".zip,application/zip"></label>
        <label>Backup password <small class="muted">(only for encrypted backups)</small> <input type="password" name="backup_password" autocomplete="off"></label>
      <?php endif ?>
    </fieldset>
  </section>

  <section class="card stack">
    <h2>Your admin account</h2>
    <label>User name <input name="admin_user" value="<?= $e($v('admin_user')) ?>" required minlength="2" maxlength="64" autocomplete="username" autocapitalize="off" spellcheck="false"></label>
    <div class="cols">
      <label>Password <input type="password" name="admin_password" required minlength="10" autocomplete="new-password"></label>
      <label>Password again <input type="password" name="admin_password2" required minlength="10" autocomplete="new-password"></label>
    </div>
    <p class="muted">At least 10 characters. More users can be added later with <code>php lou user add</code>.</p>
  </section>

  <section class="card stack">
    <h2>Email</h2>
    <p class="muted">Where form emails come from. Everything here can be changed later under Settings → Email, which also sends a test email.</p>
    <label>Sending
      <select name="mail_transport">
        <option value="log"<?= $sel($v('mail_transport', 'log'), 'log') ?>>Not yet: write emails to a log file</option>
        <option value="smtp"<?= $sel($v('mail_transport', 'log'), 'smtp') ?>>Send with SMTP</option>
      </select>
    </label>
    <div class="cols">
      <label>Sender address <input type="email" name="mail_from" value="<?= $e($v('mail_from')) ?>" required maxlength="200" placeholder="web@example.hu"></label>
      <label>Sender name <input name="mail_from_name" value="<?= $e($v('mail_from_name')) ?>" maxlength="100"></label>
    </div>
    <div class="cols">
      <label>SMTP server <input name="mail_host" value="<?= $e($v('mail_host')) ?>" maxlength="253" placeholder="mail.example.hu" autocapitalize="off" spellcheck="false"></label>
      <label>Encryption
        <select name="mail_secure">
          <option value="tls"<?= $sel($v('mail_secure', 'tls'), 'tls') ?>>STARTTLS (port 587)</option>
          <option value="ssl"<?= $sel($v('mail_secure', 'tls'), 'ssl') ?>>SSL/TLS (port 465)</option>
          <option value=""<?= $sel($v('mail_secure', 'tls'), '') ?>>None</option>
        </select>
      </label>
      <label>Port <input type="number" name="mail_port" value="<?= $e($v('mail_port', '587')) ?>" min="1" max="65535"></label>
    </div>
    <div class="cols">
      <label>SMTP user <input name="mail_user" value="<?= $e($v('mail_user')) ?>" maxlength="200" autocapitalize="off" spellcheck="false"></label>
      <label>SMTP password <input type="password" name="mail_pass" autocomplete="off"></label>
    </div>
  </section>

  <p><button class="btn btn-primary" type="submit"<?= $failed ? ' disabled' : '' ?>>Install and build the site</button></p>
</form>
