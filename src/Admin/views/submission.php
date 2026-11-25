<?php $d = json_decode($row['data'], true) ?: []; $results = json_decode($row['results'], true) ?: []; ?>
<p><a href="<?= $self ?>?r=submissions">← Submissions</a></p>
<h1>Submission #<?= (int) $row['id'] ?></h1>
<dl class="dl">
  <dt>Form</dt><dd><?= $e($row['form']) ?> (<?= $e($row['lang']) ?>)</dd>
  <dt>Date (UTC)</dt><dd><?= $e($row['created_at']) ?></dd>
  <?php foreach ($d as $k => $v): ?>
    <dt><?= $e($k) ?></dt><dd class="pre"><?= $e($v) ?></dd>
  <?php endforeach ?>
</dl>
<?php if ($results): ?>
<h2>Actions</h2>
<ul><?php foreach ($results as $r): ?><li><?= $e($r['action']) ?>: <?= $r['ok'] ? 'ok' : 'failed: ' . $e($r['error'] ?? '') ?></li><?php endforeach ?></ul>
<?php if ($row['status'] === 'partial'): ?><p class="muted">Failed actions can be re-run with <code>php lou retry</code>.</p><?php endif ?>
<?php endif ?>
<?php if ($role === 'admin'): ?>
<form method="post" action="<?= $self ?>?r=delete-submission" data-confirm="Delete this submission permanently?">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
  <button class="btn btn-danger" type="submit">Delete</button>
</form>
<?php endif ?>
