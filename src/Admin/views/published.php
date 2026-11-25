<?php /** @var callable $e */ $siteUrl = rtrim((string) $baseUrl, '/') . '/'; ?>
<div class="page-head"><h1>Published</h1></div>
<section class="card wide">
  <div class="alert alert-ok"><strong>The live site is updated.</strong>
    <?php if (!empty($applied)): ?><?= count($applied) ?> change<?= count($applied) === 1 ? ' is' : 's are' ?> now live.<?php endif ?></div>
  <p><a class="btn btn-primary" href="<?= $e($siteUrl) ?>" target="_blank" rel="noopener">View the live site</a> <a class="btn" href="<?= $self ?>?r=review">Back to Review</a></p>
  <?php if ($result['redirects']['moved']): ?>
    <h3>Web addresses that changed</h3>
    <p class="muted">The old addresses send visitors and search engines to the new ones.</p>
    <ul><?php foreach ($result['redirects']['moved'] as $m): ?><li><code><?= $e($m['from']) ?></code> → <code><?= $e($m['to']) ?></code></li><?php endforeach ?></ul>
  <?php endif ?>
  <?php if (!empty($applied)): ?><p class="muted">Changed your mind? The old versions are in the <a href="<?= $self ?>?r=history">history</a>, and a backup was made just before publishing.</p><?php endif ?>
  <details class="tech">
    <summary>Technical details</summary>
    <p>Built <?= (int) $result['pages'] ?> pages (build <code><?= $e($result['id']) ?></code>).<?php if ($safety): ?> Backup: <code><?= $e($safety) ?></code>.<?php endif ?><?php if ($git): ?> <?= $e($git) ?><?php endif ?></p>
    <?php if (!empty($applied)): ?><h4>Files</h4><ul><?php foreach ($applied as $f): ?><li><code><?= $e($f) ?></code></li><?php endforeach ?></ul><?php endif ?>
    <?php if ($result['warnings']): ?><h4>Warnings</h4><ul><?php foreach ($result['warnings'] as $w): ?><li><?= $e($w) ?></li><?php endforeach ?></ul><?php endif ?>
  </details>
</section>
