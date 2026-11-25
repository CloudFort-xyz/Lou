<?php /** @var callable $e */ ?>
<div class="page-head"><h1>Site design imported</h1></div>
<section class="widget settings-section">
<?php if ($written || $deleted): ?>
  <div class="alert alert-ok"><strong><?= count($written) + count($deleted) ?> changes are in your draft.</strong> Nothing is live yet: submit them under Review, check the preview, then publish.</div>
  <?php if ($first): ?><p class="muted">This is the first design deploy on this site, so every template and asset that differs from the zip is proposed, including any changed on the server. Check the diffs in Review before publishing.</p><?php endif ?>
  <p><a class="btn btn-primary" href="<?= $self ?>?r=review">Go to Review</a> <a class="btn" href="<?= $self ?>?r=pages">Preview in the editor</a></p>
<?php else: ?>
  <div class="alert">Nothing to import: the templates and assets on the server already match the zip<?= $skipped || $rejected ? ', apart from the files below' : '' ?>.</div>
<?php endif ?>
<?php foreach (['written' => 'Updated or added', 'deleted' => 'Deleted (removed from the zip since the last deploy)',
                'skipped' => 'Skipped: changed on the server since the last deploy, so the server’s version stays',
                'rejected' => 'Rejected by the safety checks'] as $key => $label):
    if (!$$key) continue; ?>
  <h3><?= $e($label) ?> (<?= count($$key) ?>)</h3>
  <ul><?php foreach ($$key as $f): ?><li><code><?= $e($f) ?></code></li><?php endforeach ?></ul>
<?php endforeach ?>
<?php if ($skipped): ?><p class="muted">To take the zip’s version of a skipped file anyway, change it to match in the repository, or edit it on the server through an assistant.</p><?php endif ?>
</section>
