<?php
/** @var callable $e */
$who = static fn (string $owner): string => preg_replace('/^llm:/', '', preg_replace('/^user:/', '', $owner)) . (str_starts_with($owner, 'llm:') ? ' (assistant)' : '');
?>
<h1>Review and publish</h1>
<p class="muted">Edits go to each person's own draft first. Submitting a draft puts it on staging, and publishing staging makes it live.</p>

<h2>Your draft</h2>
<?php if (!$mine): ?>
  <p class="muted">You have no unsubmitted changes.</p>
<?php else: ?>
  <div class="card wide">
    <p><?= count($mine['files']) ?> changed file<?= count($mine['files']) === 1 ? '' : 's' ?>:
      <?php foreach ($mine['files'] as $f): ?><code><?= $e($f) ?></code> <?php endforeach ?></p>
    <?php if ($mine['conflicts']): ?><p class="alert alert-error">Someone else changed some of the same files on staging. You'll be asked what to do when you submit.</p><?php endif ?>
    <form method="post" action="<?= $self ?>?r=submit" class="stack">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <label>What did you change? <small class="muted">(optional, shown to the reviewer and kept in the history)</small>
        <textarea name="summary" rows="2" maxlength="2000"></textarea></label>
      <div class="actions">
        <button class="btn btn-primary" type="submit">Submit to staging</button>
      </div>
    </form>
    <form method="post" action="<?= $self ?>?r=revert" class="inline" data-confirm="Throw away all of your unsubmitted changes?">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn btn-link" type="submit">Discard my draft</button>
    </form>
  </div>
<?php endif ?>
<?php if ($drafts): ?>
  <p class="muted">Also working on drafts: <?php foreach ($drafts as $i => $d): ?><?= $i ? ', ' : '' ?><?= $e($who($d['owner'])) ?> (<?= (int) $d['files'] ?> files)<?php endforeach ?>.</p>
<?php endif ?>

<h2>Staging</h2>
<?php if (!$open): ?>
  <p class="muted">Nothing is waiting to be published.</p>
  <?php if ($unpublished): ?>
    <form method="post" action="<?= $self ?>?r=publish" class="inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn" type="submit">Rebuild the live site</button> <span class="muted">Redirect or other site changes are not live yet.</span>
    </form>
  <?php endif ?>
<?php else:
  $all = array_merge($files['added'], $files['modified'], $files['deleted']);
  $kind = fn ($p) => in_array($p, $files['added'], true) ? 'added' : (in_array($p, $files['deleted'], true) ? 'deleted' : 'changed');
?>
  <div class="card wide">
    <h3>Submitted</h3>
    <ul class="submissions">
      <?php foreach ($meta['submissions'] as $sub): ?>
        <li><strong><?= $e($who($sub['by'])) ?></strong>, <?= $e(substr(str_replace('T', ' ', $sub['at']), 0, 16)) ?> UTC
          <?php if ($sub['summary'] !== ''): ?><blockquote class="pre"><?= $e($sub['summary']) ?></blockquote><?php endif ?>
          <small class="muted"><?= $e(implode(', ', $sub['files'])) ?></small></li>
      <?php endforeach ?>
    </ul>

    <?php if ($problems): ?>
      <div class="alert alert-error"><strong>These problems must be fixed before publishing:</strong>
        <ul><?php foreach ($problems as $p): ?><li class="pre"><?= $e($p) ?></li><?php endforeach ?></ul></div>
    <?php endif ?>
    <?php if ($conflicts): ?>
      <div class="alert alert-error"><strong>Changed on the live site since staging was opened</strong> (for example by a restore):
        <ul><?php foreach ($conflicts as $c): ?><li><code><?= $e($c) ?></code></li><?php endforeach ?></ul>
        Publishing with “overwrite” replaces those with the staged version.</div>
    <?php endif ?>

    <h3>Preview</h3>
    <?php if ($preview && (int) $preview['rev'] === (int) $meta['rev']): ?>
      <p><a class="btn" href="<?= $e($preview['url']) ?>" target="_blank" rel="noopener">Open preview</a>
        <span class="muted"><?= (int) $preview['pages'] ?> pages. Anyone with the link can view it.</span></p>
    <?php else: ?>
      <p class="muted"><?= $preview ? 'The preview is older than the latest submission.' : 'No preview yet.' ?></p>
    <?php endif ?>
    <form method="post" action="<?= $self ?>?r=review-preview" class="inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn" type="submit"<?= $problems ? ' disabled' : '' ?>><?= $preview ? 'Rebuild preview' : 'Build preview' ?></button>
    </form>
    <?php if ($preview): ?>
      <?php if ($preview['redirects']['moved'] || $preview['redirects']['gone']): ?>
        <h3>URLs that will change</h3>
        <ul>
          <?php foreach ($preview['redirects']['moved'] as $m): ?><li><code><?= $e($m['from']) ?></code> → <code><?= $e($m['to']) ?></code> (301 redirect)</li><?php endforeach ?>
          <?php foreach ($preview['redirects']['gone'] as $g): ?><li><code><?= $e($g['from']) ?></code> removed, redirects to the home page</li><?php endforeach ?>
        </ul>
      <?php endif ?>
      <?php if ($preview['warnings']): ?>
        <h3>Warnings</h3><ul><?php foreach ($preview['warnings'] as $w): ?><li><?= $e($w) ?></li><?php endforeach ?></ul>
      <?php endif ?>
    <?php endif ?>

    <h3>Files (<?= count($all) ?>)</h3>
    <?php foreach ($diffs as $path => $diff): ?>
      <details class="diff"<?= count($diffs) <= 5 ? ' open' : '' ?>>
        <summary><code><?= $e($path) ?></code> <span class="badge<?= $kind($path) === 'deleted' ? ' badge-warn' : '' ?>"><?= $kind($path) ?></span></summary>
        <pre><?php foreach (explode("\n", rtrim($diff, "\n")) as $line):
          $c = match ($line[0] ?? ' ') { '+' => 'add', '-' => 'del', '@' => 'hunk', default => '' };
          ?><span class="<?= $c ?>"><?= $e($line) ?></span><?php endforeach ?></pre>
      </details>
    <?php endforeach ?>

    <h3>Decision</h3>
    <div class="actions">
      <form method="post" action="<?= $self ?>?r=publish" class="inline" data-confirm="Publish everything on staging to the live site?">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="rev" value="<?= $e($meta['rev']) ?>">
        <?php if ($conflicts): ?><label class="check"><input type="checkbox" name="force" value="1"> Overwrite</label><?php endif ?>
        <button class="btn btn-primary" type="submit"<?= $problems || !$all ? ' disabled' : '' ?>>Publish</button>
      </form>
      <form method="post" action="<?= $self ?>?r=reject" class="inline" data-confirm="Throw away everything on staging? Drafts are not affected.">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button class="btn btn-danger" type="submit">Reject all</button>
      </form>
    </div>
    <p class="muted">A backup is made before publishing, and the old versions are kept in the history.</p>
  </div>
<?php endif ?>
