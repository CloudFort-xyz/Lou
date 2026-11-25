<div class="card">
  <h1><?= $t('Published') ?></h1>
  <p class="alert alert-ok"><?= $t('Built {n} page. The live site is updated.|Built {n} pages. The live site is updated.', ['n' => $result['pages']]) ?></p>
  <?php if (!empty($applied)): ?>
    <p><?= $t('Published {n} changed file from staging.|Published {n} changed files from staging.', ['n' => count($applied)]) ?>
      <?= $t('A backup was made first (<code>{file}</code>) and the old versions are in the {!history}.', ['file' => $safety, 'history' => '<a href="' . $self . '?r=history">' . $t('history') . '</a>']) ?><?php if ($git): ?> <?= $e($git) ?><?php endif ?></p>
  <?php endif ?>
  <?php if ($result['redirects']['moved']): ?>
    <h2><?= $t('New redirects') ?></h2>
    <ul><?php foreach ($result['redirects']['moved'] as $m): ?><li><code><?= $e($m['from']) ?></code> → <code><?= $e($m['to']) ?></code></li><?php endforeach ?></ul>
  <?php endif ?>
  <?php if ($result['warnings']): ?>
    <h2><?= $t('Warnings') ?></h2>
    <ul><?php foreach ($result['warnings'] as $w): ?><li><?= $e($w) ?></li><?php endforeach ?></ul>
  <?php endif ?>
  <p><a href="<?= $self ?>?r=dashboard"><?= $t('Back to the dashboard') ?></a></p>
</div>
