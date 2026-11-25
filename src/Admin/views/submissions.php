<div class="page-head">
  <h1><?= $t('Submissions') ?></h1>
  <a class="btn" href="<?= $self ?>?r=export<?= $form !== '' ? '&amp;form=' . $e($form) : '' ?>"><?= $t('Download CSV') ?></a>
</div>
<p class="filters">
  <a href="<?= $self ?>?r=submissions"<?= $form === '' ? ' aria-current="page"' : '' ?>><?= $t('All') ?></a>
  <?php foreach ($forms as $f): ?>
    <a href="<?= $self ?>?r=submissions&amp;form=<?= $e($f['form']) ?>"<?= $form === $f['form'] ? ' aria-current="page"' : '' ?>><?= $e($f['form']) ?> (<?= (int) $f['n'] ?>)</a>
  <?php endforeach ?>
</p>
<?php if (!$rows): ?><p class="muted"><?= $t('No submissions yet.') ?></p><?php else: ?>
<table class="table list">
  <thead><tr><th>#</th><th><?= $t('Date') ?></th><th><?= $t('Form') ?></th><th><?= $t('From') ?></th><th><?= $t('Status') ?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $d = json_decode($r['data'], true) ?: []; ?>
    <tr>
      <td><a href="<?= $self ?>?r=submission&amp;id=<?= (int) $r['id'] ?>"><?= (int) $r['id'] ?></a></td>
      <td><?= $time($r['created_at']) ?></td>
      <td><?= $e($r['form']) ?> <small class="muted"><?= $e($r['lang']) ?></small></td>
      <td><?= $e($d['name'] ?? '') ?> <small class="muted"><?= $e($d['email'] ?? '') ?></small></td>
      <td><span class="badge<?= $r['status'] === 'ok' ? '' : ' badge-warn' ?>"><?= $t($r['status']) ?></span></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
