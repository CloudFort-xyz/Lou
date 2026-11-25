<?php
/**
 * After a site design upload: where the changes went (staging or the draft, and why), in plain words;
 * file paths and safety-check reasons under "Technical details".
 * @var callable $e
 */
use Lou\Content\ChangeList;

$n = count($written) + count($deleted);
$changes = $t->plain('{n} change|{n} changes', ['n' => $n]);
$labels = static fn (array $paths): array => array_map(fn ($p) => ChangeList::label((string) preg_replace('/: .*$/s', '', $p), null, $t->plain(...)), $paths);
?>
<div class="page-head"><h1><?= $t('Site design') ?></h1></div>
<section class="widget settings-section">
<?php if (!$n): ?>
  <div class="alert"><?= $skipped || $rejected
      ? $t('Nothing new in <strong>{name}</strong>: the design on the server already matches it, apart from the files below.', ['name' => $name])
      : $t('Nothing new in <strong>{name}</strong>: the design on the server already matches it.', ['name' => $name]) ?></div>
<?php elseif ($submitted): ?>
  <div class="alert alert-ok"><strong><?= $t('The new design is on staging, waiting for review') ?></strong> (<?= $t('{changes} from {name}', ['changes' => $changes, 'name' => $name]) ?>).
    <?= $ready ? $t('The preview is ready: check it, then publish.') : $t('Build the preview under Review, check it, then publish.') ?></div>
  <p><a class="btn btn-primary" href="<?= $self ?>?r=review#staging"><?= $t('Go to Review') ?></a></p>
<?php else: ?>
  <div class="alert<?= $held === 'problems' ? ' alert-error' : ' alert-ok' ?>"><strong><?= $t('The new design is in your draft') ?></strong> (<?= $t('{changes} from {name}', ['changes' => $changes, 'name' => $name]) ?>). <?= $t('Nothing is live yet.') ?>
    <?= match ($held) {
        'draft'     => $t('Your draft already had other changes, so it was not sent on by itself: submit everything together under Review.'),
        'conflicts' => $t('Someone else has changes to the same files waiting on staging: Review asks you what to do when you submit.'),
        'problems'  => $t('It cannot go to staging yet:'),
        default     => $t('Submit it under Review when you are ready.'),
    } ?>
    <?php if ($held === 'problems'): ?><ul><?php foreach ($problems as $p): ?><li class="pre"><?= $e($p) ?></li><?php endforeach ?></ul><?php endif ?></div>
  <p><a class="btn btn-primary" href="<?= $self ?>?r=review#draft"><?= $t('Go to Review') ?></a> <a class="btn" href="<?= $self ?>?r=pages"><?= $t('Preview in the editor') ?></a></p>
<?php endif ?>

<?php if ($written): ?>
  <h3><?= $t('Updated or new ({n})', ['n' => count($written)]) ?></h3>
  <ul><?php foreach ($labels($written) as $l): ?><li><?= $e($l) ?></li><?php endforeach ?></ul>
<?php endif ?>
<?php if ($deleted): ?>
  <h3><?= $t('Removed ({n})', ['n' => count($deleted)]) ?></h3>
  <p class="muted"><?= $t('No longer in the zip since the last upload.') ?></p>
  <ul><?php foreach ($labels($deleted) as $l): ?><li><?= $e($l) ?></li><?php endforeach ?></ul>
<?php endif ?>
<?php if ($skipped): ?>
  <h3><?= $t('Kept the server\'s version ({n})', ['n' => count($skipped)]) ?></h3>
  <p class="muted"><?= $t('These were changed on the server since the last upload (for example by an assistant), so the zip did not overwrite them. To take the zip\'s version anyway, change the file to match in the repository, or ask an assistant to.') ?></p>
  <ul><?php foreach ($labels($skipped) as $l): ?><li><?= $e($l) ?></li><?php endforeach ?></ul>
<?php endif ?>
<?php if ($rejected): ?>
  <h3><?= $t('Refused by the safety checks ({n})', ['n' => count($rejected)]) ?></h3>
  <p class="muted"><?= $t('Templates may only use what Lou allows. The reasons are under Technical details.') ?></p>
  <ul><?php foreach ($labels($rejected) as $l): ?><li><?= $e($l) ?></li><?php endforeach ?></ul>
<?php endif ?>

<details class="tech">
  <summary><?= $t('Technical details') ?></summary>
  <?php if ($first && $n): ?><p><?= $t('This is the first design upload on this site, so every template and asset that differs from the zip is proposed, including any changed on the server. Check the diffs in Review before publishing.') ?></p><?php endif ?>
  <?php foreach (['written' => $t->plain('Written'), 'deleted' => $t->plain('Deleted'), 'skipped' => $t->plain('Skipped (changed on the server)'), 'rejected' => $t->plain('Rejected')] as $key => $label):
      if (!$$key) continue; ?>
    <h4><?= $e($label) ?></h4>
    <ul><?php foreach ($$key as $f): ?><li><code><?= $e($f) ?></code></li><?php endforeach ?></ul>
  <?php endforeach ?>
  <p><a href="<?= $self ?>?r=updates#design"><?= $t('Upload another zip') ?></a></p>
</details>
</section>
