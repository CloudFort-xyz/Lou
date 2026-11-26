<p><a href="<?= $self ?>?r=history">← <?= $t('History') ?></a></p>
<h1><?= $t('Published {!time}', ['time' => $time($entry['at'])]) ?></h1>
<p><?= $t('by {name}', ['name' => $entry['by']]) ?></p>
<?php foreach ($entry['submissions'] as $sub): ?>
  <blockquote class="pre"><?= $sub['summary'] !== '' ? $e($sub['summary']) : $t('(no summary)') ?> <small class="muted">— <?= $e($who($sub['by'])) ?></small></blockquote>
<?php endforeach ?>
<div class="actions">
  <form method="post" action="<?= $self ?>?r=history-restore" class="inline" data-confirm="<?= $t('Put the versions from before this publish into your draft?') ?>">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="id" value="<?= $e($entry['id']) ?>">
    <input type="hidden" name="side" value="before">
    <button class="btn btn-primary" type="submit"><?= $t('Undo this publish (into my draft)') ?></button>
  </form>
  <form method="post" action="<?= $self ?>?r=history-restore" class="inline" data-confirm="<?= $t('Put the versions from this publish into your draft?') ?>">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="id" value="<?= $e($entry['id']) ?>">
    <input type="hidden" name="side" value="after">
    <button class="btn" type="submit"><?= $t('Restore this version (into my draft)') ?></button>
  </form>
</div>
<?php if (count($diffs) > 1): ?><div class="fold-head"><h2><?= $t('{n} changed file|{n} changed files', ['n' => count($diffs)]) ?></h2><button class="btn btn-link" type="button" data-fold-all="#entry-diffs"><?= $t('Collapse all') ?></button></div><?php endif ?>
<div id="entry-diffs">
<?php foreach ($diffs as $path => $diff): ?>
  <details class="diff" open>
    <summary><code><?= $e($path) ?></code> <span class="badge"><?= $t($entry['files'][$path]) ?></span>
      <a href="<?= $self ?>?r=history&amp;file=<?= $e(rawurlencode($path)) ?>"><?= $t('file history') ?></a></summary>
    <pre class="diff-lines"><?php foreach (explode("\n", rtrim($diff, "\n")) as $line):
      $c = match ($line[0] ?? ' ') { '+' => 'add', '-' => 'del', '@' => 'hunk', default => '' };
      ?><span class="<?= $c ?>"><?= $e($line) ?></span><?php endforeach ?></pre>
    <form method="post" action="<?= $self ?>?r=history-restore" class="inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="id" value="<?= $e($entry['id']) ?>">
      <input type="hidden" name="side" value="before">
      <input type="hidden" name="files[]" value="<?= $e($path) ?>">
      <button class="btn btn-link" type="submit"><?= $t('Undo just this file') ?></button>
    </form>
  </details>
<?php endforeach ?>
</div>
