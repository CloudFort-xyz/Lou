<div class="login-wrap">
  <p class="login-brand">Lou</p>
  <form method="post" action="<?= $self ?>?r=login" class="card login">
    <h1><?= $e($siteName) ?></h1>
    <?php if ($error): ?><p class="alert alert-error"><?= $e($error) ?></p><?php endif ?>
    <label><?= $t('User name') ?> <input name="user" autocomplete="username" required autofocus></label>
    <label><?= $t('Password') ?> <input name="password" type="password" autocomplete="current-password" required></label>
    <button class="btn btn-primary" type="submit"><?= $t('Log in') ?></button>
    <p class="login-extra"><a href="<?= $self ?>?r=forgot"><?= $t('Forgot your password?') ?></a></p>
  </form>
</div>
