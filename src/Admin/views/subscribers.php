<h1>Subscribers</h1>
<?php if (!$rows): ?><p class="muted">No newsletter sign-ups synced to a provider yet.</p><?php else: ?>
<table class="table">
  <thead><tr><th>Email</th><th>Name</th><th>Provider</th><th>Status</th><th>Updated (UTC)</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= $e($r['email']) ?></td><td><?= $e($r['name']) ?></td>
      <td><?= $e($r['provider']) ?> <small class="muted"><?= $e($r['list']) ?></small></td>
      <td><span class="badge<?= $r['status'] === 'synced' ? '' : ' badge-warn' ?>" title="<?= $e($r['error']) ?>"><?= $e($r['status']) ?></span></td>
      <td><?= $e(substr(str_replace('T', ' ', $r['updated_at']), 0, 16)) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
