<?php /** @var callable $e */ ?>
<h1>Changes from assistants</h1>
<?php if (!$open): ?>
  <p class="muted">No changes are waiting. When an assistant edits the site through the bridge, its changes are staged here for you to review and approve. Set up assistants on the <a href="<?= $self ?>?r=assistants">Assistants</a> page.</p>
<?php else:
  $all = array_merge($files['added'], $files['modified'], $files['deleted']);
  $kind = fn ($p) => in_array($p, $files['added'], true) ? 'added' : (in_array($p, $files['deleted'], true) ? 'deleted' : 'changed');
?>
  <div class="card wide">
    <p>
      <?php if ($meta['status'] === 'review'): ?><span class="badge">Ready for review</span><?php else: ?><span class="badge badge-warn">Still being edited</span><?php endif ?>
      Started by <strong><?= $e(preg_replace('/^llm:/', '', $meta['by'])) ?></strong> on <?= $e(substr(str_replace('T', ' ', $meta['created']), 0, 16)) ?> UTC,
      last change <?= $e(substr(str_replace('T', ' ', $meta['updated']), 0, 16)) ?> UTC.
    </p>
    <?php if ($meta['summary'] !== ''): ?><blockquote class="pre"><?= $e($meta['summary']) ?></blockquote><?php endif ?>

    <?php if ($problems): ?>
      <div class="alert alert-error"><strong>These problems must be fixed before approving:</strong>
        <ul><?php foreach ($problems as $p): ?><li class="pre"><?= $e($p) ?></li><?php endforeach ?></ul></div>
    <?php endif ?>
    <?php if ($conflicts): ?>
      <div class="alert alert-error"><strong>Also edited in the admin since the assistant started:</strong>
        <ul><?php foreach ($conflicts as $c): ?><li><code><?= $e($c) ?></code></li><?php endforeach ?></ul>
        Approving with “overwrite” replaces those admin edits with the assistant’s version.</div>
    <?php endif ?>

    <h2>Preview</h2>
    <?php if ($preview && $preview['at'] >= $meta['updated']): ?>
      <p><a class="btn" href="<?= $e($preview['url']) ?>" target="_blank" rel="noopener">Open preview</a>
        <span class="muted"><?= (int) $preview['pages'] ?> pages. Anyone with the link can view it.</span></p>
    <?php else: ?>
      <p class="muted"><?= $preview ? 'The preview is older than the latest change.' : 'No preview yet.' ?></p>
    <?php endif ?>
    <form method="post" action="<?= $self ?>?r=changes-preview" class="inline">
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

    <h2>Files (<?= count($all) ?>)</h2>
    <?php if (!$all): ?><p class="muted">Nothing changed yet.</p><?php endif ?>
    <?php foreach ($diffs as $path => $diff): ?>
      <details class="diff"<?= count($diffs) <= 5 ? ' open' : '' ?>>
        <summary><code><?= $e($path) ?></code> <span class="badge<?= $kind($path) === 'deleted' ? ' badge-warn' : '' ?>"><?= $kind($path) ?></span></summary>
        <pre><?php foreach (explode("\n", rtrim($diff, "\n")) as $line):
          $c = match ($line[0] ?? ' ') { '+' => 'add', '-' => 'del', '@' => 'hunk', default => '' };
          ?><span class="<?= $c ?>"><?= $e($line) ?></span><?php endforeach ?></pre>
      </details>
    <?php endforeach ?>

    <h2>Decision</h2>
    <?php if ($unpublished): ?><p class="muted">Approving also publishes the content edits made in the admin that are not live yet.</p><?php endif ?>
    <div class="actions">
      <form method="post" action="<?= $self ?>?r=approve" class="inline" data-confirm="Apply these changes and publish the site?">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="id" value="<?= $e($meta['id']) ?>">
        <?php if ($conflicts): ?><label class="check"><input type="checkbox" name="force" value="1"> Overwrite the conflicting admin edits</label><?php endif ?>
        <button class="btn btn-primary" type="submit"<?= $problems || !$all ? ' disabled' : '' ?>>Approve and publish</button>
      </form>
      <form method="post" action="<?= $self ?>?r=reject" class="inline" data-confirm="Throw away all of these changes?">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button class="btn btn-danger" type="submit">Reject</button>
      </form>
    </div>
    <p class="muted">A backup is made before anything is applied.</p>
  </div>
<?php endif ?>
