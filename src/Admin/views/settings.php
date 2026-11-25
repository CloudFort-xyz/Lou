<?php
/** @var callable $e */
$value = $configured ? $current : ($suggested ?: $current);
$sel = static fn (string $a, string $b): string => $a === $b ? ' selected' : '';
?>
<div class="page-head"><h1>Settings</h1></div>

<section class="widget settings-section">
  <h2>Site address</h2>
  <?php if ($saved): ?><div class="alert alert-ok">Saved. The live site was rebuilt with the new address.</div><?php endif ?>
  <?php if (!$configured): ?>
    <div class="alert alert-error"><strong>The site address is not set yet</strong><?= $current !== '' ? ' (it is still the example, <code>' . $e($current) . '</code>)' : '' ?>. Until it is, the sitemap and search engine links are wrong.</div>
  <?php endif ?>
  <form method="post" action="<?= $self ?>?r=settings" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label>Site address
      <input type="url" name="base_url" value="<?= $e($value) ?>" required placeholder="https://www.example.hu" pattern="https?://[^/?#]+/?">
    </label>
    <p class="muted">The address visitors type to reach the site, such as https://www.example.hu. Saving rebuilds the live site.</p>
    <details class="fold fold-small"><summary>Technical details</summary>
      <p class="muted">Used for the sitemap, canonical and hreflang links, preview and share links, and the assistant bridge URL. No path. It overrides <code>base_url</code> in <code>site/config.php</code>.</p>
    </details>
    <p><button class="btn btn-primary" type="submit">Save address</button></p>
  </form>
</section>

<section class="widget settings-section" id="email">
  <h2>Email</h2>
  <?php if ($mailSaved): ?><div class="alert alert-ok">Email settings saved. Send a test email below to check them.</div><?php endif ?>
  <?php if ($mail['transport'] === 'log'): ?>
    <div class="alert alert-error"><strong>Emails are not being sent.</strong> Form emails only go to <code>var/mail.log</code>. Choose “Send with SMTP” to deliver them.</div>
  <?php elseif ($smtp['host'] === ''): ?>
    <div class="alert alert-error"><strong>No SMTP server is set.</strong> Form emails cannot be delivered until it is.</div>
  <?php endif ?>
  <form method="post" action="<?= $self ?>?r=mail-settings" class="stack" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label>Sending
      <select name="transport">
        <option value="smtp"<?= $sel($mail['transport'], 'smtp') ?>>Send with SMTP</option>
        <option value="log"<?= $sel($mail['transport'], 'log') ?>>Don’t send, write to a log file (for testing)</option>
      </select>
    </label>
    <div class="cols">
      <label>Sender address <input type="email" name="from" value="<?= $e($mail['from']) ?>" required maxlength="200" placeholder="web@example.hu"></label>
      <label>Sender name <input name="from_name" value="<?= $e($mail['from_name']) ?>" maxlength="100" placeholder="<?= $e($siteName) ?>"></label>
    </div>
    <p class="muted">Use an address on your own domain that the SMTP account is allowed to send from, or mail may land in spam. Visitors' addresses are set as Reply-To.</p>

    <details class="fold"<?= $smtp['host'] === '' || $mail['transport'] === 'log' ? ' open' : '' ?>><summary><h3>SMTP server</h3><?php if ($smtp['host'] !== ''): ?> <small class="muted"><?= $e($smtp['host']) ?>:<?= (int) $smtp['port'] ?></small><?php endif ?></summary>
    <?php if ($smtpSource === 'env'): ?><p class="muted">Currently read from <code>.env.php</code>. Saving here stores the values in the admin instead, and they take over.</p><?php endif ?>
    <div class="cols">
      <label>Server <input name="host" value="<?= $e($smtp['host']) ?>" maxlength="253" placeholder="mail.example.hu" autocapitalize="off" spellcheck="false"></label>
      <label>Encryption
        <select name="secure" id="smtp-secure">
          <option value="tls"<?= $sel($smtp['secure'], 'tls') ?>>STARTTLS (usually port 587)</option>
          <option value="ssl"<?= $sel($smtp['secure'], 'ssl') ?>>SSL/TLS (usually port 465)</option>
          <option value=""<?= $sel($smtp['secure'], '') ?>>None (not recommended)</option>
        </select>
      </label>
      <label>Port <input type="number" name="port" id="smtp-port" value="<?= (int) $smtp['port'] ?>" min="1" max="65535" required></label>
    </div>
    <div class="cols">
      <label>User name <input name="user" value="<?= $e($smtp['user']) ?>" maxlength="200" autocomplete="off" autocapitalize="off" spellcheck="false"></label>
      <label>Password
        <input type="password" name="pass" maxlength="500" autocomplete="new-password" placeholder="<?= $smtp['hasPass'] ? 'Saved. Leave empty to keep it' : '' ?>">
      </label>
    </div>
    <?php if ($smtp['hasPass']): ?><label class="check"><input type="checkbox" name="clear_pass" value="1"> Remove the saved password</label><?php endif ?>
    <p class="muted">The password is never shown again and is not included in backups.</p>
    <details class="fold fold-small"><summary>Where is it stored?</summary>
      <p class="muted">Outside the web root in <code>var/secrets.json</code>, readable only by the site's own user (mode 0600).</p>
    </details>
    </details>
    <p><button class="btn btn-primary" type="submit">Save email settings</button></p>
  </form>

  <h3>Send a test email</h3>
  <?php if ($testResult): ?><div class="alert <?= $testResult['ok'] ? 'alert-ok' : 'alert-error' ?>"><?= $testResult['ok'] ? '' : '<strong>The test email failed.</strong> ' ?><?= $e($testResult['message']) ?></div><?php endif ?>
  <form method="post" action="<?= $self ?>?r=mail-test#email" class="actions">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="email" name="to" value="<?= $e($testTo ?? '') ?>" required placeholder="you@example.hu" aria-label="Send the test email to">
    <button class="btn" type="submit">Send test email</button>
  </form>
  <p class="muted">Uses the saved settings, so save your changes first.</p>
</section>
