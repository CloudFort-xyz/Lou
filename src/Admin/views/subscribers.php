<h1><?= $t('Subscribers') ?></h1>
<?php if (!$rows): ?><p class="muted"><?= $t('No newsletter sign-ups synced to a provider yet.') ?></p><?php else: ?>
<table class="table list">
  <thead><tr><th><?= $t('Email') ?></th><th><?= $t('Name') ?></th><th><?= $t('Provider') ?></th><th><?= $t('Status') ?></th><th><?= $t('Updated') ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= $e($r['email']) ?></td><td><?= $e($r['name']) ?></td>
      <td><?= $e($r['provider']) ?> <small class="muted"><?= $e($r['list']) ?></small></td>
      <td><span class="badge<?= $r['status'] === 'synced' ? '' : ' badge-warn' ?>" title="<?= $e($r['error']) ?>"><?= $t($r['status']) ?></span></td>
      <td><?= $time($r['updated_at']) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
