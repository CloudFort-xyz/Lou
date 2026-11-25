<?php
/** @var callable $e */
$who = static fn (string $owner): string => preg_replace('/^(user|llm):/', '', $owner) . (str_starts_with($owner, 'llm:') ? ' (assistant)' : '');
?>
<p><a href="<?= $self ?>?r=history">← History</a></p>
<h1>Published <?= $e(substr(str_replace('T', ' ', $entry['at']), 0, 16)) ?> UTC</h1>
<p>by <?= $e($entry['by']) ?></p>
<?php foreach ($entry['submissions'] as $sub): ?>
  <blockquote class="pre"><?= $sub['summary'] !== '' ? $e($sub['summary']) : '(no summary)' ?> <small class="muted">— <?= $e($who($sub['by'])) ?></small></blockquote>
<?php endforeach ?>
<div class="actions">
  <form method="post" action="<?= $self ?>?r=history-restore" class="inline" data-confirm="Put the versions from before this publish into your draft?">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="id" value="<?= $e($entry['id']) ?>">
    <input type="hidden" name="side" value="before">
    <button class="btn btn-primary" type="submit">Undo this publish (into my draft)</button>
  </form>
  <form method="post" action="<?= $self ?>?r=history-restore" class="inline" data-confirm="Put the versions from this publish into your draft?">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="id" value="<?= $e($entry['id']) ?>">
    <input type="hidden" name="side" value="after">
    <button class="btn" type="submit">Restore this version (into my draft)</button>
  </form>
</div>
<?php foreach ($diffs as $path => $diff): ?>
  <details class="diff" open>
    <summary><code><?= $e($path) ?></code> <span class="badge"><?= $e($entry['files'][$path]) ?></span>
      <a href="<?= $self ?>?r=history&amp;file=<?= $e(rawurlencode($path)) ?>">file history</a></summary>
    <pre><?php foreach (explode("\n", rtrim($diff, "\n")) as $line):
      $c = match ($line[0] ?? ' ') { '+' => 'add', '-' => 'del', '@' => 'hunk', default => '' };
      ?><span class="<?= $c ?>"><?= $e($line) ?></span><?php endforeach ?></pre>
    <form method="post" action="<?= $self ?>?r=history-restore" class="inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="id" value="<?= $e($entry['id']) ?>">
      <input type="hidden" name="side" value="before">
      <input type="hidden" name="files[]" value="<?= $e($path) ?>">
      <button class="btn btn-link" type="submit">Undo just this file</button>
    </form>
  </details>
<?php endforeach ?>
