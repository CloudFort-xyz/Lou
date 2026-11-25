<form method="post" action="<?= $self ?>?r=login" class="card login">
  <h1><?= $e($siteName) ?></h1>
  <?php if ($error): ?><p class="alert alert-error"><?= $e($error) ?></p><?php endif ?>
  <label>User name <input name="user" autocomplete="username" required autofocus></label>
  <label>Password <input name="password" type="password" autocomplete="current-password" required></label>
  <button class="btn btn-primary" type="submit">Log in</button>
</form>
