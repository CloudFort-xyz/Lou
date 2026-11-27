<?php
/** @var callable $e */
/** @var Lou\Admin\I18n $t */
/** @var callable $time */
// Profile: two-step login with an authenticator app.
?>
<section class="widget settings-section" id="two-step">
  <h2><?= $t('Two-step login') ?> <?php if ($totpOn): ?><span class="badge"><?= $t('on') ?></span><?php endif ?></h2>
  <?php if ($newRecovery): ?>
    <div class="alert alert-ok">
      <strong><?= $t('Save these recovery codes now. They are shown only once.') ?></strong>
      <?= $t('If you lose your phone, each code lets you log in once instead of the app’s code. Keep them somewhere safe, such as a password manager or on paper.') ?>
      <div class="snippet"><pre class="code" id="recovery-codes"><?= $e(implode("\n", $newRecovery)) ?></pre>
        <button class="btn btn-copy" type="button" data-copy="recovery-codes"><?= $t('Copy') ?></button></div>
    </div>
  <?php endif ?>

  <?php if ($totpOn): ?>
    <p><?= $t('After your password, you also type the 6-digit code from your authenticator app. Someone who learns your password still can’t log in.') ?></p>
    <p class="muted"><?= $t('On since {!time}. {n} recovery code left.|On since {!time}. {n} recovery codes left.', ['time' => $time($totpSince), 'n' => $recoveryLeft]) ?></p>
    <details class="fold-small"><summary><?= $t('Recovery codes or turn it off') ?></summary>
      <form method="post" action="<?= $self ?>?r=totp-recovery" class="actions">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="password" name="password" autocomplete="current-password" required aria-label="<?= $t('Current password') ?>" placeholder="<?= $t('Current password') ?>">
        <button class="btn" type="submit"><?= $t('Make new recovery codes') ?></button>
      </form>
      <?php if ($totpRequired): ?>
        <p class="muted"><?= $t('Two-step login is required on this site, so it can’t be turned off. If you get a new phone, ask an administrator to reset it.') ?></p>
      <?php else: ?>
        <form method="post" action="<?= $self ?>?r=totp-disable" class="actions" data-confirm="<?= $t('Turn off two-step login? Your password alone will be enough to log in.') ?>">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <input type="password" name="password" autocomplete="current-password" required aria-label="<?= $t('Current password') ?>" placeholder="<?= $t('Current password') ?>">
          <button class="btn btn-danger" type="submit"><?= $t('Turn off two-step login') ?></button>
        </form>
      <?php endif ?>
    </details>

  <?php elseif ($totpQr !== null): ?>
    <ol class="steps-plain">
      <li><?= $t('Open an authenticator app on your phone, for example Google Authenticator, Microsoft Authenticator or the one in your password manager, and add an account.') ?></li>
      <li><?= $t('Scan this QR code with it:') ?>
        <div class="qr-box"><?= $totpQr /* SVG built by Lou\Support\QrCode from fixed parts and escaped text */ ?></div>
        <details class="fold-small"><summary><?= $t('Can’t scan it?') ?></summary>
          <p><?= $t('Type this key into the app instead (time-based, 6 digits):') ?></p>
          <div class="snippet"><pre class="code wrap" id="totp-secret"><?= $e($totpSecret) ?></pre>
            <button class="btn btn-copy" type="button" data-copy="totp-secret"><?= $t('Copy') ?></button></div>
          <p class="muted"><?= $t('On the phone itself, this link opens the app directly:') ?> <a href="<?= $e($totpUri) ?>"><?= $t('Add to authenticator app') ?></a></p>
        </details></li>
      <li><?= $t('Type the 6-digit code the app shows:') ?>
        <?php if ($totpError): ?><p class="alert alert-error"><?= $e($totpError) ?></p><?php endif ?>
        <form method="post" action="<?= $self ?>?r=totp-enable#two-step" class="actions">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required aria-label="<?= $t('Code from the app') ?>" placeholder="123456" class="code-input">
          <button class="btn btn-primary" type="submit"><?= $t('Turn on two-step login') ?></button>
        </form></li>
    </ol>

  <?php else: ?>
    <?php if ($totpRequired): ?><p class="alert alert-error"><strong><?= $t('This site requires two-step login.') ?></strong> <?= $t('Set it up to continue: it takes a minute.') ?></p><?php endif ?>
    <p><?= $t('Protect your account with a second step: after your password, you type a 6-digit code from an app on your phone. Someone who learns your password still can’t log in.') ?></p>
    <form method="post" action="<?= $self ?>?r=totp-start" class="actions">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn<?= $totpRequired ? ' btn-primary' : '' ?>" type="submit"><?= $t('Set up two-step login') ?></button>
    </form>
  <?php endif ?>
  <details class="fold-small"><summary><?= $t('Technical details') ?></summary>
    <p class="muted"><?= $t('Standard time-based one-time passwords (TOTP, RFC 6238): HMAC-SHA1, 6 digits, 30-second steps, one step of clock drift allowed, and each code works only once. The key is stored in <code>var/users.json</code>; recovery codes are stored only as SHA-256 hashes. Wrong codes count towards the login limits.') ?></p>
  </details>
</section>
