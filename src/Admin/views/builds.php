<?php
/** @var callable $e */
$base = rtrim((string) ($baseUrl ?? ''), '/');
// Build folders are named before-YYYYMMDD-HHMMSS-xxxxxx (UTC): show the date, keep the name for reference.
$builtAt = static function (string $name) use ($time): string {
    return preg_match('/^before-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})-/', $name, $m)
        ? $time("$m[1]-$m[2]-$m[3]T$m[4]:$m[5]:$m[6]Z") : '';
};
?>
<h1><?= $t('Builds') ?></h1>
<?php if ($live): ?><p><?= $t('The live site was published {!time}.', ['time' => $time($live['at'])]) ?> <small class="muted"><?= $t('Build') ?> <code><?= $e($live['build']) ?></code></small></p><?php endif ?>
<h2><?= $t('Previous builds') ?></h2>
<p class="muted"><?= $t('The live site as it was before each publish. Rolling back only swaps the live files: your content is unchanged, and the next publish builds from it again.') ?></p>
<?php if (!$builds): ?><p class="muted"><?= $t('None yet. One is kept each time the site is published.') ?></p><?php else: ?>
<table class="table list">
  <thead><tr><th><?= $t('Live until') ?></th><th><span class="sr"><?= $t('Actions') ?></span></th></tr></thead>
  <tbody>
  <?php foreach ($builds as $b): ?>
    <tr><td><?= $builtAt($b) ?> <small class="muted"><?= $t('build') ?> <code><?= $e(substr($b, 7)) ?></code></small></td><td class="cell-actions">
      <form method="post" action="<?= $self ?>?r=rollback" data-confirm="<?= $t('Put this build back on the live site?') ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="build" value="<?= $e($b) ?>">
        <button class="btn" type="submit"><?= $t('Roll back to this') ?></button>
      </form></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
<h2><?= $t('Shared preview links') ?></h2>
<?php if (!$previews): ?><p class="muted"><?= $t('None. Create one from the page editor to show your draft to someone.') ?></p><?php else: ?>
<table class="table list">
  <thead><tr><th><?= $t('Link') ?></th><th><?= $t('Kind') ?></th><th><?= $t('Expires') ?></th><th><span class="sr"><?= $t('Actions') ?></span></th></tr></thead>
  <tbody>
  <?php foreach ($previews as $token => $p): $id = 'preview-' . substr($token, 0, 8); ?>
    <tr><td><code id="<?= $e($id) ?>" class="sr"><?= $e($base . $endpoint . '/p/' . $token . '/') ?></code><code><?= $e($endpoint) ?>/p/<?= $e(substr($token, 0, 6)) ?>…</code> <small class="muted"><?= $t('by {name}', ['name' => $p['by'] ?? '?']) ?></small></td>
      <td><?= $p['kind'] === 'live' ? $t('Live viewer') : $t('Preview') ?></td>
      <td><?= $time((int) $p['expires']) ?></td>
      <td class="cell-actions"><button class="btn" type="button" data-copy="<?= $e($id) ?>"><?= $t('Copy link') ?></button>
        <form method="post" action="<?= $self ?>?r=revoke" class="inline" data-confirm="<?= $t('Revoke this link? Anyone who has it can no longer open it.') ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="token" value="<?= $e($token) ?>">
        <button class="btn btn-danger" type="submit"><?= $t('Revoke') ?></button></form></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
