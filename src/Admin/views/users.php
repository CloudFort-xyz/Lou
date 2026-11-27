<?php
/** @var callable $e */
/** @var Lou\Admin\I18n $t */
/** @var callable $roleName */
/** @var callable $time */
?>
<h1><?= $t('Users') ?></h1>
<p class="lead"><?= $t('The people who can sign in to this admin. Give everyone their own login, so you can see who changed what and remove one person without affecting the others.') ?></p>

<?php require __DIR__ . '/_new-link.php' ?>

<table class="table list">
  <thead><tr><th><?= $t('Name') ?></th><th><?= $t('Role') ?></th><th class="hide-sm"><?= $t('Last login') ?></th><th><span class="sr"><?= $t('Actions') ?></span></th></tr></thead>
  <tbody>
  <?php foreach ($users as $name => $u): $invited = $u['hash'] === ''; ?>
    <tr>
      <td><a href="<?= $self ?>?r=user&amp;name=<?= $e(rawurlencode($name)) ?>"><strong><?= $e($name) ?></strong></a><?= $name === $me ? ' <span class="muted">(' . $t('you') . ')</span>' : '' ?>
        <?php if (!empty($u['email'])): ?><br><small class="muted"><?= $e($u['email']) ?></small><?php endif ?></td>
      <td><?= $e($roleName($u['role'])) ?></td>
      <td class="hide-sm"><?php if ($invited): ?><span class="badge badge-warn"><?= $t('invited') ?></span>
        <?php elseif (!empty($u['last_login'])): ?><?= $time($u['last_login']) ?><?php else: ?><span class="muted"><?= $t('never') ?></span><?php endif ?></td>
      <td class="cell-actions"><a class="btn" href="<?= $self ?>?r=user&amp;name=<?= $e(rawurlencode($name)) ?>"><?= $t('Edit') ?></a></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>

<section class="widget settings-section">
  <h2><?= $t('Invite someone') ?></h2>
  <p class="muted"><?= $t('They get an email with a link to choose their own password, so nobody else ever knows it. The link works once, for 48 hours.') ?></p>
  <form method="post" action="<?= $self ?>?r=user-invite" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div class="cols">
      <label><?= $t('User name') ?> <input name="name" required pattern="[A-Za-z0-9._@\-]{2,64}" maxlength="64" autocomplete="off" placeholder="<?= $t('e.g. anna') ?>"></label>
      <label><?= $t('Email address') ?> <input name="email" type="email" required maxlength="254" autocomplete="off"></label>
    </div>
    <label><?= $t('Role') ?>
      <select name="role">
        <?php foreach ($roles as $r): ?><option value="<?= $e($r) ?>"<?= $r === 'editor' ? ' selected' : '' ?>><?= $e($roleName($r)) ?></option><?php endforeach ?>
      </select>
    </label>
    <p><button class="btn btn-primary" type="submit"><?= $t('Send invite') ?></button></p>
  </form>
</section>

<?php require __DIR__ . '/_roles.php' ?>
