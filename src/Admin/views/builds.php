<h1>Builds</h1>
<?php if ($live): ?><p>Live build: <code><?= $e($live['build']) ?></code>, published <?= $e(substr(str_replace('T', ' ', $live['at']), 0, 16)) ?> UTC.</p><?php endif ?>
<h2>Previous builds</h2>
<?php if (!$builds): ?><p class="muted">None yet.</p><?php else: ?>
<table class="table list">
  <tbody>
  <?php foreach ($builds as $b): ?>
    <tr><td><code><?= $e($b) ?></code></td><td>
      <form method="post" action="<?= $self ?>?r=rollback" data-confirm="Put this build back on the live site?">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="build" value="<?= $e($b) ?>">
        <button class="btn" type="submit">Roll back to this</button>
      </form></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
<h2>Shared preview links</h2>
<?php if (!$previews): ?><p class="muted">None.</p><?php else: ?>
<table class="table list">
  <tbody>
  <?php foreach ($previews as $token => $p): ?>
    <tr><td><code>/_lou/p/<?= $e(substr($token, 0, 6)) ?>…</code> by <?= $e($p['by'] ?? '?') ?></td>
      <td>expires <?= $e(gmdate('Y-m-d H:i', $p['expires'])) ?> UTC</td>
      <td><form method="post" action="<?= $self ?>?r=revoke">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="token" value="<?= $e($token) ?>">
        <button class="btn" type="submit">Revoke</button></form></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
