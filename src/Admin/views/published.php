<?php /** @var callable $e */ $siteUrl = rtrim((string) $baseUrl, '/') . '/'; ?>
<div class="page-head"><h1><?= $t('Published') ?></h1></div>
<section class="card wide">
  <div class="alert alert-ok"><strong><?= $t('The live site is updated.') ?></strong>
    <?php if (!empty($applied)): ?><?= $t('{n} change is now live.|{n} changes are now live.', ['n' => count($applied)]) ?><?php endif ?></div>
  <p><a class="btn btn-primary" href="<?= $e($siteUrl) ?>" target="_blank" rel="noopener"><?= $t('View the live site') ?></a> <a class="btn" href="<?= $self ?>?r=review"><?= $t('Back to Review') ?></a></p>
  <?php if ($result['redirects']['moved']): ?>
    <h3><?= $t('Web addresses that changed') ?></h3>
    <p class="muted"><?= $t('The old addresses send visitors and search engines to the new ones.') ?></p>
    <ul><?php foreach ($result['redirects']['moved'] as $m): ?><li><code><?= $e($m['from']) ?></code> → <code><?= $e($m['to']) ?></code></li><?php endforeach ?></ul>
  <?php endif ?>
  <?php if (!empty($applied)): ?><p class="muted"><?= $t('Changed your mind? The old versions are in the {!history}, and a backup was made just before publishing.', ['history' => '<a href="' . $self . '?r=history">' . $t('history') . '</a>']) ?></p><?php endif ?>
  <details class="tech">
    <summary><?= $t('Technical details') ?></summary>
    <p><?= $t('Built {n} pages (build <code>{build}</code>).', ['n' => $result['pages'], 'build' => $result['id']]) ?><?php if ($safety): ?> <?= $t('Backup: <code>{file}</code>.', ['file' => $safety]) ?><?php endif ?><?php if ($git): ?> <?= $e($git) ?><?php endif ?></p>
    <?php if (!empty($applied)): ?><h4><?= $t('Files') ?></h4><ul><?php foreach ($applied as $f): ?><li><code><?= $e($f) ?></code></li><?php endforeach ?></ul><?php endif ?>
    <?php if ($result['warnings']): ?><h4><?= $t('Warnings') ?></h4><ul><?php foreach ($result['warnings'] as $w): ?><li><?= $e($w) ?></li><?php endforeach ?></ul><?php endif ?>
  </details>
</section>
