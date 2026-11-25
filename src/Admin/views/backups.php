<div class="page-head">
  <h1>Backups</h1>
  <form method="post" action="<?= $self ?>?r=backup" class="inline">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <button class="btn btn-primary" type="submit">Create backup now</button>
  </form>
</div>
<p class="muted">Content, images, templates and the database in one .zip. Passwords and API keys are not included.</p>
<?php
// Backup files end in -YYYYMMDD-HHMMSS.zip (UTC).
$madeAt = static fn (string $f): string => preg_match('/-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})\.zip$/', $f, $m)
    ? $time("$m[1]-$m[2]-$m[3]T$m[4]:$m[5]:$m[6]Z") : '';
?>
<?php if (!$files): ?><p class="muted">No backups yet. One is also made automatically before every publish and restore.</p><?php else: ?>
<table class="table list">
  <thead><tr><th>Created</th><th>File</th><th>Size</th><th><span class="sr">Actions</span></th></tr></thead>
  <tbody>
  <?php foreach ($files as $f): ?>
    <tr><td><?= $madeAt($f) ?></td><td><code><?= $e($f) ?></code><?php if (str_starts_with($f, 'before-restore-')): ?> <span class="badge badge-muted">before a restore</span><?php endif ?></td>
      <td><?= $e(number_format($sizes[$f] / 1024, 0)) ?> KB</td>
      <td class="cell-actions"><a class="btn" href="<?= $self ?>?r=download&amp;file=<?= $e(rawurlencode($f)) ?>">Download</a></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
<h2>Restore</h2>
<form method="post" action="<?= $self ?>?r=restore" enctype="multipart/form-data" class="stack" data-confirm="Replace the current content with this backup? (The current state is backed up first.)">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label>Backup file <input type="file" name="backup" accept=".zip" required></label>
  <label>Password <small class="muted">(only for encrypted backups)</small> <input type="password" name="password" autocomplete="off"></label>
  <label class="check"><input type="checkbox" name="content_only" value="1"> Content only (keep the current submissions database)</label>
  <button class="btn btn-danger" type="submit">Restore</button>
</form>
