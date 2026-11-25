<div class="page-head">
  <h1><?= $t('Backups') ?></h1>
  <form method="post" action="<?= $self ?>?r=backup" class="inline">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <button class="btn btn-primary" type="submit"><?= $t('Create backup now') ?></button>
  </form>
</div>
<p class="muted"><?= $t('Content, images, templates and the database in one .zip. Passwords and API keys are not included.') ?></p>
<?php
// Backup files end in -YYYYMMDD-HHMMSS.zip (UTC).
$madeAt = static fn (string $f): string => preg_match('/-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})\.zip$/', $f, $m)
    ? $time("$m[1]-$m[2]-$m[3]T$m[4]:$m[5]:$m[6]Z") : '';
?>
<?php if (!$files): ?><p class="muted"><?= $t('No backups yet. One is also made automatically before every publish and restore.') ?></p><?php else: ?>
<table class="table list">
  <thead><tr><th><?= $t('Created') ?></th><th><?= $t('File') ?></th><th><?= $t('Size') ?></th><th><span class="sr"><?= $t('Actions') ?></span></th></tr></thead>
  <tbody>
  <?php foreach ($files as $f): ?>
    <tr><td><?= $madeAt($f) ?></td><td><code><?= $e($f) ?></code><?php if (str_starts_with($f, 'before-restore-')): ?> <span class="badge badge-muted"><?= $t('before a restore') ?></span><?php endif ?></td>
      <td><?= $e(number_format($sizes[$f] / 1024, 0)) ?> KB</td>
      <td class="cell-actions"><a class="btn" href="<?= $self ?>?r=download&amp;file=<?= $e(rawurlencode($f)) ?>"><?= $t('Download') ?></a>
        <form method="post" action="<?= $self ?>?r=backup-delete" class="inline" data-confirm="<?= $t('Delete the backup {file} permanently? Download it first if you might need it.', ['file' => $f]) ?>">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="file" value="<?= $e($f) ?>">
          <button class="btn btn-danger" type="submit"><?= $t('Delete') ?></button></form></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
<details class="fold"><summary><h2><?= $t('Restore a backup') ?></h2> <small class="muted"><?= $t('replaces the current content') ?></small></summary>
<form method="post" action="<?= $self ?>?r=restore" enctype="multipart/form-data" class="stack" data-confirm="<?= $t('Replace the current content with this backup? (The current state is backed up first.)') ?>">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label><?= $t('Backup file') ?> <input type="file" name="backup" accept=".zip" required></label>
  <label><?= $t('Password') ?> <small class="muted"><?= $t('(only for encrypted backups)') ?></small> <input type="password" name="password" autocomplete="off"></label>
  <label class="check"><input type="checkbox" name="content_only" value="1"> <?= $t('Content only (keep the current submissions database)') ?></label>
  <button class="btn btn-danger" type="submit"><?= $t('Restore') ?></button>
</form>
</details>
