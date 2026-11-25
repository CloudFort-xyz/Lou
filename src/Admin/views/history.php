<?php
/** @var callable $e */
$who = static fn (string $owner): string => preg_replace('/^(user|llm):/', '', $owner) . (str_starts_with($owner, 'llm:') ? ' (assistant)' : '');
?>
<h1>History<?= $file !== '' ? ': <code>' . $e($file) . '</code>' : '' ?></h1>
<?php if (!$enabled): ?><p class="alert alert-error">History is turned off in site/config.php ('history' => false).</p><?php endif ?>
<?php if ($file !== ''): ?><p><a href="<?= $self ?>?r=history">← All changes</a></p><?php endif ?>
<p class="muted">Every publish keeps the old and new version of each file it changed: content, templates, styles, menu and settings. Restoring puts the old version into your draft, so it goes live the usual way.</p>
<?php if (!$entries): ?><p class="muted">No history yet.</p><?php else: ?>
<table class="table list">
  <thead><tr><th>Published</th><th>Changes</th><th>Files</th></tr></thead>
  <tbody>
  <?php foreach ($entries as $en): ?>
    <tr>
      <td><a href="<?= $self ?>?r=history-entry&amp;id=<?= $e($en['id']) ?>"><?= $time($en['at']) ?></a><small class="muted">by <?= $e($en['by']) ?></small></td>
      <td><?php foreach ($en['submissions'] as $sub): ?><div><?= $sub['summary'] !== '' ? $e(mb_strimwidth($sub['summary'], 0, 120, '…')) : '<span class="muted">(no summary)</span>' ?> <small class="muted"><?= $e($who($sub['by'])) ?></small></div><?php endforeach ?></td>
      <td><?php foreach ($en['files'] as $path => $kind): ?><a href="<?= $self ?>?r=history&amp;file=<?= $e(rawurlencode($path)) ?>"><code><?= $e($path) ?></code></a><?= $kind !== 'modified' ? ' <small class="muted">' . $e($kind) . '</small>' : '' ?><br><?php endforeach ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
