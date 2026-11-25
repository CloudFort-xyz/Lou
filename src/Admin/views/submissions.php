<h1>Submissions</h1>
<p class="filters">
  <a href="<?= $self ?>?r=submissions"<?= $form === '' ? ' aria-current="page"' : '' ?>>All</a>
  <?php foreach ($forms as $f): ?>
    <a href="<?= $self ?>?r=submissions&amp;form=<?= $e($f['form']) ?>"<?= $form === $f['form'] ? ' aria-current="page"' : '' ?>><?= $e($f['form']) ?> (<?= (int) $f['n'] ?>)</a>
  <?php endforeach ?>
  <a class="btn" href="<?= $self ?>?r=export<?= $form !== '' ? '&amp;form=' . $e($form) : '' ?>">Download CSV</a>
</p>
<?php if (!$rows): ?><p class="muted">No submissions yet.</p><?php else: ?>
<table class="table">
  <thead><tr><th>#</th><th>Date (UTC)</th><th>Form</th><th>From</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $d = json_decode($r['data'], true) ?: []; ?>
    <tr>
      <td><a href="<?= $self ?>?r=submission&amp;id=<?= (int) $r['id'] ?>"><?= (int) $r['id'] ?></a></td>
      <td><?= $e(substr(str_replace('T', ' ', $r['created_at']), 0, 16)) ?></td>
      <td><?= $e($r['form']) ?> <small class="muted"><?= $e($r['lang']) ?></small></td>
      <td><?= $e($d['name'] ?? '') ?> <small class="muted"><?= $e($d['email'] ?? '') ?></small></td>
      <td><span class="badge<?= $r['status'] === 'ok' ? '' : ' badge-warn' ?>"><?= $e($r['status']) ?></span></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
