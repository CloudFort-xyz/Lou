<?php
/** @var callable $e */
$base = rtrim((string) ($baseUrl ?? ''), '/');
// Build folders are named before-YYYYMMDD-HHMMSS-xxxxxx (UTC): show the date, keep the name for reference.
$builtAt = static function (string $name) use ($time): string {
    return preg_match('/^before-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})-/', $name, $m)
        ? $time("$m[1]-$m[2]-$m[3]T$m[4]:$m[5]:$m[6]Z") : '';
};
?>
<h1>Builds</h1>
<?php if ($live): ?><p>Live build: <code><?= $e($live['build']) ?></code>, published <?= $time($live['at']) ?>.</p><?php endif ?>
<h2>Previous builds</h2>
<p class="muted">The live site as it was before each publish. Rolling back only swaps the live files: your content is unchanged, and the next publish builds from it again.</p>
<?php if (!$builds): ?><p class="muted">None yet. One is kept each time the site is published.</p><?php else: ?>
<table class="table list">
  <thead><tr><th>Replaced at</th><th>Build</th><th><span class="sr">Actions</span></th></tr></thead>
  <tbody>
  <?php foreach ($builds as $b): ?>
    <tr><td><?= $builtAt($b) ?></td><td><code><?= $e($b) ?></code></td><td class="cell-actions">
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
<?php if (!$previews): ?><p class="muted">None. Create one from the page editor to show your draft to someone.</p><?php else: ?>
<table class="table list">
  <thead><tr><th>Link</th><th>Kind</th><th>Expires</th><th><span class="sr">Actions</span></th></tr></thead>
  <tbody>
  <?php foreach ($previews as $token => $p): $id = 'preview-' . substr($token, 0, 8); ?>
    <tr><td><code id="<?= $e($id) ?>" class="sr"><?= $e($base . '/_lou/p/' . $token . '/') ?></code><code>/_lou/p/<?= $e(substr($token, 0, 6)) ?>…</code> <small class="muted">by <?= $e($p['by'] ?? '?') ?></small></td>
      <td><?= $p['kind'] === 'live' ? 'Live viewer' : 'Preview' ?></td>
      <td><?= $time((int) $p['expires']) ?></td>
      <td class="cell-actions"><button class="btn" type="button" data-copy="<?= $e($id) ?>">Copy link</button>
        <form method="post" action="<?= $self ?>?r=revoke" class="inline" data-confirm="Revoke this link? Anyone who has it can no longer open it.">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="token" value="<?= $e($token) ?>">
        <button class="btn btn-danger" type="submit">Revoke</button></form></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
