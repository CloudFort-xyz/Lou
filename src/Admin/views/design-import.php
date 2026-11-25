<?php
/**
 * After a site design upload: where the changes went (staging or the draft, and why), in plain words;
 * file paths and safety-check reasons under "Technical details".
 * @var callable $e
 */
use Lou\Content\ChangeList;

$n = count($written) + count($deleted);
$changes = $n . ' change' . ($n === 1 ? '' : 's');
$labels = static fn (array $paths): array => array_map(fn ($p) => ChangeList::label((string) preg_replace('/: .*$/s', '', $p)), $paths);
?>
<div class="page-head"><h1>Site design</h1></div>
<section class="widget settings-section">
<?php if (!$n): ?>
  <div class="alert">Nothing new in <strong><?= $e($name) ?></strong>: the design on the server already matches it<?= $skipped || $rejected ? ', apart from the files below' : '' ?>.</div>
<?php elseif ($submitted): ?>
  <div class="alert alert-ok"><strong>The new design is on staging, waiting for review</strong> (<?= $e($changes) ?> from <?= $e($name) ?>).
    <?= $ready ? 'The preview is ready: check it, then publish.' : 'Build the preview under Review, check it, then publish.' ?></div>
  <p><a class="btn btn-primary" href="<?= $self ?>?r=review#staging">Go to Review</a></p>
<?php else: ?>
  <div class="alert<?= $held === 'problems' ? ' alert-error' : ' alert-ok' ?>"><strong>The new design is in your draft</strong> (<?= $e($changes) ?> from <?= $e($name) ?>). Nothing is live yet.
    <?= match ($held) {
        'draft'     => 'Your draft already had other changes, so it was not sent on by itself: submit everything together under Review.',
        'conflicts' => 'Someone else has changes to the same files waiting on staging: Review asks you what to do when you submit.',
        'problems'  => 'It cannot go to staging yet:',
        default     => 'Submit it under Review when you are ready.',
    } ?>
    <?php if ($held === 'problems'): ?><ul><?php foreach ($problems as $p): ?><li class="pre"><?= $e($p) ?></li><?php endforeach ?></ul><?php endif ?></div>
  <p><a class="btn btn-primary" href="<?= $self ?>?r=review#draft">Go to Review</a> <a class="btn" href="<?= $self ?>?r=pages">Preview in the editor</a></p>
<?php endif ?>

<?php if ($written): ?>
  <h3>Updated or new (<?= count($written) ?>)</h3>
  <ul><?php foreach ($labels($written) as $l): ?><li><?= $e($l) ?></li><?php endforeach ?></ul>
<?php endif ?>
<?php if ($deleted): ?>
  <h3>Removed (<?= count($deleted) ?>)</h3>
  <p class="muted">No longer in the zip since the last upload.</p>
  <ul><?php foreach ($labels($deleted) as $l): ?><li><?= $e($l) ?></li><?php endforeach ?></ul>
<?php endif ?>
<?php if ($skipped): ?>
  <h3>Kept the server's version (<?= count($skipped) ?>)</h3>
  <p class="muted">These were changed on the server since the last upload (for example by an assistant), so the zip did not overwrite them. To take the zip's version anyway, change the file to match in the repository, or ask an assistant to.</p>
  <ul><?php foreach ($labels($skipped) as $l): ?><li><?= $e($l) ?></li><?php endforeach ?></ul>
<?php endif ?>
<?php if ($rejected): ?>
  <h3>Refused by the safety checks (<?= count($rejected) ?>)</h3>
  <p class="muted">Templates may only use what Lou allows. The reasons are under Technical details.</p>
  <ul><?php foreach ($labels($rejected) as $l): ?><li><?= $e($l) ?></li><?php endforeach ?></ul>
<?php endif ?>

<details class="tech">
  <summary>Technical details</summary>
  <?php if ($first && $n): ?><p>This is the first design upload on this site, so every template and asset that differs from the zip is proposed, including any changed on the server. Check the diffs in Review before publishing.</p><?php endif ?>
  <?php foreach (['written' => 'Written', 'deleted' => 'Deleted', 'skipped' => 'Skipped (changed on the server)', 'rejected' => 'Rejected'] as $key => $label):
      if (!$$key) continue; ?>
    <h4><?= $e($label) ?></h4>
    <ul><?php foreach ($$key as $f): ?><li><code><?= $e($f) ?></code></li><?php endforeach ?></ul>
  <?php endforeach ?>
  <p><a href="<?= $self ?>?r=updates#design">Upload another zip</a></p>
</details>
</section>
