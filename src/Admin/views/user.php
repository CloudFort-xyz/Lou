<?php
/** @var callable $e */
/** @var Lou\Admin\I18n $t */
/** @var callable $roleName */
/** @var callable $time */
$self_ = $name === $me;
$invited = $record['hash'] === '';
$link = $record['link'] ?? null;
$hidden = '<input type="hidden" name="_csrf" value="' . $e($csrf) . '"><input type="hidden" name="name" value="' . $e($name) . '">';
?>
<p><a href="<?= $self ?>?r=users">← <?= $t('All users') ?></a></p>
<div class="page-head"><h1><?= $e($name) ?></h1><span class="muted"><?= $e($roleName($record['role'])) ?><?= $self_ ? ' · ' . $t('you') : '' ?></span></div>

<?php require __DIR__ . '/_new-link.php' ?>

<ul class="glance">
  <li><span><?= $invited ? $t('Invited, has not chosen a password yet') : (!empty($record['last_login']) ? $t('Last login {!time}', ['time' => $time($record['last_login'])]) : $t('Has not logged in yet')) ?></span></li>
  <?php if ($link && $link['expires'] >= $now): ?><li><span class="muted"><?= $t('An unused link expires {!time}', ['time' => $time($link['expires'])]) ?></span></li><?php endif ?>
</ul>

<section class="widget settings-section">
  <h2><?= $t('Role and email') ?></h2>
  <form method="post" action="<?= $self ?>?r=user-update" class="stack">
    <?= $hidden ?>
    <div class="cols">
      <label><?= $t('Role') ?>
        <select name="role"<?= $self_ || $lastAdmin ? ' disabled' : '' ?>>
          <?php foreach ($roles as $r): ?><option value="<?= $e($r) ?>"<?= $r === $record['role'] ? ' selected' : '' ?>><?= $e($roleName($r)) ?></option><?php endforeach ?>
        </select>
      </label>
      <label><?= $t('Email address') ?> <input name="email" type="email" maxlength="254" value="<?= $e($record['email'] ?? '') ?>" autocomplete="off"></label>
    </div>
    <?php if ($self_): ?><p class="muted"><?= $t('You can’t change your own role. Ask another administrator.') ?></p>
    <?php elseif ($lastAdmin): ?><p class="muted"><?= $t('This is the only administrator. Make someone else an administrator before changing this role.') ?></p><?php endif ?>
    <p class="muted"><?= $t('Invites and password links go to this address. A change applies right away, even while the person is signed in.') ?></p>
    <p><button class="btn btn-primary" type="submit"><?= $t('Save') ?></button></p>
  </form>
</section>

<section class="widget settings-section">
  <h2><?= $t('Password and sign-in') ?></h2>
  <form method="post" action="<?= $self ?>?r=user-link" class="actions">
    <?= $hidden ?>
    <button class="btn" type="submit"><?= $invited ? $t('Send a new invite') : $t('Send a password reset link') ?></button>
    <span class="muted"><?= $invited ? $t('The old invite link stops working.') : $t('They choose a new password with it. The current password keeps working until then.') ?></span>
  </form>
  <?php if (!$invited): ?>
  <form method="post" action="<?= $self ?>?r=user-signout" class="actions">
    <?= $hidden ?>
    <button class="btn" type="submit"><?= $t('Sign out everywhere') ?></button>
    <span class="muted"><?= $self_ ? $t('Ends your sessions on every other computer and phone. This browser stays signed in.') : $t('Ends every session of {name}, for example after a lost phone or laptop.', ['name' => $name]) ?></span>
  </form>
  <?php endif ?>
</section>

<?php if (!$self_ && !$lastAdmin): ?>
<section class="widget settings-section">
  <h2><?= $t('Remove user') ?></h2>
  <form method="post" action="<?= $self ?>?r=user-remove" class="actions" data-confirm="<?= $t('Remove {name}? They are signed out at once and can’t log in again.', ['name' => $name]) ?>">
    <?= $hidden ?>
    <button class="btn btn-danger" type="submit"><?= $t('Remove {name}', ['name' => $name]) ?></button>
    <span class="muted"><?= $t('Their past changes stay in the history.') ?></span>
  </form>
</section>
<?php endif ?>

<details class="fold"><summary><h2><?= $t('Recent activity') ?></h2> <small class="muted"><?= $t('from the audit log') ?></small></summary>
<?php if (!$activity): ?>
  <p class="muted"><?= $t('Nothing yet.') ?></p>
<?php else: ?>
  <table class="table list">
    <thead><tr><th><?= $t('When') ?></th><th><?= $t('What') ?></th><th class="hide-sm"><?= $t('Details') ?></th></tr></thead>
    <tbody>
    <?php foreach ($activity as $a): ?>
      <tr><td><?= $time($a['at']) ?></td><td><code><?= $e($a['action']) ?></code></td><td class="hide-sm"><small class="muted"><?= $e(mb_strimwidth((string) $a['detail'], 0, 120, '…')) ?></small></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>
<details class="fold-small"><summary><?= $t('Technical details') ?></summary>
<ul class="muted">
  <li><?= $t('Created {!time}', ['time' => $time($record['created'])]) ?></li>
  <li><?= $t('Session version {n}: it goes up when the password changes or on “Sign out everywhere”, and every session started before that ends on its next request.', ['n' => (int) ($record['sv'] ?? 0)]) ?></li>
  <?php if ($link): ?><li><?= $t('Pending {purpose} link made by {by}.', ['purpose' => $link['purpose'], 'by' => $link['by']]) ?></li><?php endif ?>
</ul>
</details>
</details>
