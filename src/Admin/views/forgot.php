<?php
/** @var callable $e */
/** @var Lou\Admin\I18n $t */
?>
<div class="login-wrap">
  <p class="login-brand">Lou</p>
  <form method="post" action="<?= $self ?>?r=forgot" class="card login">
    <h1><?= $t('Forgot your password?') ?></h1>
    <?php if ($error): ?><p class="alert alert-error"><?= $e($error) ?></p><?php endif ?>
    <?php if ($sent): ?>
      <p class="alert alert-ok" role="status"><?= $t('If that account exists and has an email address, a link to set a new password is on its way. It works for one hour.') ?></p>
      <p class="muted"><?= $t('Nothing arrived? Check the spam folder, or ask an administrator to send you a new link from the Users page.') ?></p>
    <?php else: ?>
      <p class="muted"><?= $t('Enter your user name or email address. We will email you a link to choose a new password.') ?></p>
      <label><?= $t('User name or email address') ?> <input name="user" autocomplete="username" required autofocus maxlength="254"></label>
      <button class="btn btn-primary" type="submit"><?= $t('Send the link') ?></button>
    <?php endif ?>
    <p class="login-extra"><a href="<?= $self ?>?r=login"><?= $t('Back to log in') ?></a></p>
  </form>
</div>
