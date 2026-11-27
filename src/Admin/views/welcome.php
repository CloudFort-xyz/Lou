<?php
/** @var callable $e */
/** @var Lou\Admin\I18n $t */
?>
<div class="login-wrap">
  <p class="login-brand">Lou</p>
  <?php if ($name === null): ?>
    <div class="card login">
      <h1><?= $t('This link no longer works') ?></h1>
      <p><?= $t('This link has expired or was already used. Ask an administrator for a new one.') ?></p>
      <p class="login-extra"><a href="<?= $self ?>?r=forgot"><?= $t('Forgot your password?') ?></a> · <a href="<?= $self ?>?r=login"><?= $t('Back to log in') ?></a></p>
    </div>
  <?php else: ?>
    <form method="post" action="<?= $self ?>?r=welcome" class="card login">
      <h1><?= $purpose === 'invite' ? $t('Welcome to {site}', ['site' => $siteName]) : $t('Choose a new password') ?></h1>
      <?php if ($error): ?><p class="alert alert-error"><?= $e($error) ?></p><?php endif ?>
      <p class="muted"><?= $t('Your user name is {!name}. Choose a password of at least 10 characters. A few unrelated words are easy to remember and hard to guess.', ['name' => '<strong>' . $e($name) . '</strong>']) ?></p>
      <input type="hidden" name="t" value="<?= $e($token) ?>">
      <input type="text" name="user" value="<?= $e($name) ?>" autocomplete="username" hidden>
      <label><?= $t('New password') ?> <input name="password" type="password" autocomplete="new-password" minlength="10" required autofocus></label>
      <label><?= $t('New password again') ?> <input name="password2" type="password" autocomplete="new-password" minlength="10" required></label>
      <button class="btn btn-primary" type="submit"><?= $t('Save password and sign in') ?></button>
    </form>
  <?php endif ?>
</div>
