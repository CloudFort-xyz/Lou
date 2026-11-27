<?php
/** @var callable $e */
/** @var Lou\Admin\I18n $t */
?>
<div class="login-wrap">
  <p class="login-brand">Lou</p>
  <form method="post" action="<?= $self ?>?r=login-code" class="card login">
    <h1><?= $t('Two-step login') ?></h1>
    <?php if ($error): ?><p class="alert alert-error"><?= $e($error) ?></p><?php endif ?>
    <p class="muted"><?= $t('Open your authenticator app and type the 6-digit code it shows for this site.') ?></p>
    <label><?= $t('Code') ?> <input name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus maxlength="20" class="code-input"></label>
    <button class="btn btn-primary" type="submit"><?= $t('Log in') ?></button>
    <p class="login-extra muted"><?= $t('Lost your phone? Type one of your recovery codes instead, or ask an administrator to reset two-step login for you.') ?></p>
  </form>
</div>
