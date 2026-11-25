<h1>Backups</h1>
<form method="post" action="<?= $self ?>?r=backup">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <button class="btn btn-primary" type="submit">Create backup now</button>
  <span class="muted">Content, images, templates and the database in one .zip. Passwords and API keys are not included.</span>
</form>
<?php if ($files): ?>
<table class="table">
  <tbody>
  <?php foreach ($files as $f): ?>
    <tr><td><?= $e($f) ?></td><td><?= $e(number_format($sizes[$f] / 1024, 0)) ?> KB</td>
      <td><a class="btn" href="<?= $self ?>?r=download&amp;file=<?= $e(rawurlencode($f)) ?>">Download</a></td></tr>
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
