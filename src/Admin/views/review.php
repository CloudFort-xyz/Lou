<?php
/**
 * Review: draft → staging → live. Plain-language change lists first (with Edit and Undo on each change),
 * the preview and the decision; file paths, build warnings and code diffs sit under "Technical details".
 * @var callable $e
 */
use Lou\Content\ChangeList;

$who = static fn (string $owner): string => preg_replace('/^(user|llm):/', '', $owner) . (str_starts_with($owner, 'llm:') ? ' (assistant)' : '');
$plural = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
$nMine = $mine ? count($mine['items']) : 0;
$nStaged = $open ? count($items) : 0;

// Who submitted each staged file (a file can be in several submissions).
$by = [];
foreach ($open ? $meta['submissions'] : [] as $sub) {
    foreach ($sub['files'] as $f) {
        $by[$f][$who($sub['by'])] = true;
    }
}

/** One change list, grouped. $where: 'draft' (Undo = revert in my draft) or 'staging' (Undo = take it off staging). */
$views ??= [];
$changes = static function (array $list, string $where) use ($e, $self, $csrf, $by, $views): void { ?>
  <?php foreach (ChangeList::grouped($list) as $group => $groupItems): ?>
    <h4 class="change-group"><?= $e(ChangeList::GROUPS[$group]) ?></h4>
    <ul class="changes">
    <?php foreach ($groupItems as $it): ?>
      <li class="change">
        <div class="change-head">
          <span class="change-label"><?= $e($it['label']) ?></span>
          <?php if ($it['kind'] !== 'changed'): ?><span class="badge<?= $it['kind'] === 'deleted' ? ' badge-warn' : '' ?>"><?= $it['kind'] === 'added' ? 'new' : 'removed' ?></span><?php endif ?>
          <?php if ($where === 'staging' && isset($by[$it['path']])): ?><small class="muted">by <?= $e(implode(', ', array_keys($by[$it['path']]))) ?></small><?php endif ?>
          <span class="change-actions">
            <?php if ($where === 'staging' && isset($views[$it['path']])): ?>
              <a href="<?= $e($views[$it['path']]) ?>" target="_blank" rel="noopener" title="Open this page in the preview">View</a>
            <?php endif ?>
            <?php if ($it['page'] && $it['kind'] !== 'deleted'): ?>
              <a href="<?= $self ?>?r=edit&amp;page=<?= $e(rawurlencode($it['page']['id'])) ?>&amp;lang=<?= $e($it['page']['lang']) ?>" title="Open this page in the editor. Your edit goes into your draft, on top of this change.">Edit</a>
            <?php endif ?>
            <form method="post" action="<?= $self ?>?r=<?= $where === 'draft' ? 'revert' : 'staging-revert' ?>" class="inline"
                  data-confirm="<?= $e($where === 'draft' ? "Undo your change to {$it['label']}?" : "Take the change to {$it['label']} off staging? It goes back to the live version.") ?>">
              <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
              <input type="hidden" name="files[]" value="<?= $e($it['path']) ?>">
              <button class="link" type="submit">Undo</button>
            </form>
          </span>
        </div>
        <?php if ($it['fields']): ?>
          <ul class="fields">
          <?php foreach ($it['fields'] as $f): ?>
            <li><span class="field-name"><?= $e($f['name']) ?></span>
              <span class="field-value"><?php if ($f['diff']):
                  foreach ($f['diff'] as [$op, $text]): ?><?= match ($op) { '-' => '<del>' . $e($text) . '</del>', '+' => '<ins>' . $e($text) . '</ins>', default => $e($text) } ?><?php endforeach;
                elseif ($f['old'] === null): ?><ins><?= $e($f['new']) ?></ins><?php
                elseif ($f['new'] === null): ?><del><?= $e($f['old']) ?></del> <small class="muted">(removed)</small><?php
                else: ?><del><?= $e($f['old']) ?></del> → <ins><?= $e($f['new']) ?></ins><?php endif ?></span></li>
          <?php endforeach ?>
          <?php if ($it['more']): ?><li class="muted">and <?= (int) $it['more'] ?> more (see Technical details)</li><?php endif ?>
          </ul>
        <?php elseif ($it['lines']): ?>
          <p class="change-note muted"><?= (int) $it['lines'][0] ?> line<?= $it['lines'][0] === 1 ? '' : 's' ?> added, <?= (int) $it['lines'][1] ?> removed</p>
        <?php endif ?>
      </li>
    <?php endforeach ?>
    </ul>
  <?php endforeach;
};

/** Code diffs of the given files, folded. */
$diffList = static function (array $list, array $diffs) use ($e): void {
    foreach ($list as $it):
        if (!isset($diffs[$it['path']])) continue; ?>
      <details class="diff">
        <summary><code><?= $e($it['path']) ?></code> <span class="badge<?= $it['kind'] === 'deleted' ? ' badge-warn' : '' ?>"><?= $e($it['kind']) ?></span></summary>
        <pre><?php foreach (explode("\n", rtrim($diffs[$it['path']], "\n")) as $line):
          $c = match ($line[0] ?? ' ') { '+' => 'add', '-' => 'del', '@' => 'hunk', default => '' };
          ?><span class="<?= $c ?>"><?= $e($line) ?></span><?php endforeach ?></pre>
      </details>
    <?php endforeach;
};
?>
<div class="page-head"><h1>Review and publish</h1></div>

<ol class="flow" aria-label="Where changes are">
  <li class="<?= $nMine ? 'is-current' : '' ?>"><strong>1. Your draft</strong>
    <span><?= $nMine ? $plural($nMine, 'change', 'changes') . ' only you can see' : 'No unsubmitted changes' ?></span></li>
  <li class="<?= $nStaged ? 'is-current' : '' ?>"><strong>2. Staging</strong>
    <span><?= $nStaged ? $plural($nStaged, 'change', 'changes') . ' waiting for review' : 'Nothing waiting' ?></span></li>
  <li class="<?= !$nMine && !$nStaged ? 'is-current' : '' ?>"><strong>3. Live</strong>
    <span><?= $live ? 'Published ' . $time((string) $live['at']) : 'Not published yet' ?></span></li>
</ol>

<?php if (!$mine && !$open): ?>
  <section class="card wide review-empty">
    <p><strong>Nothing to review.</strong> Edit a page under <a href="<?= $self ?>?r=pages">Pages</a>: your changes are saved to your own draft and show up here, ready to submit.</p>
    <?php if ($unpublished): ?>
      <form method="post" action="<?= $self ?>?r=publish" class="inline">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button class="btn" type="submit">Rebuild the live site</button> <span class="muted">Redirects or other site changes are not live yet.</span>
      </form>
    <?php endif ?>
  </section>
<?php endif ?>

<?php if ($mine): ?>
  <section class="card wide review-card" id="draft">
    <h2>Your draft</h2>
    <p class="muted">Only you see these until you submit them. Undo takes back one change.</p>
    <?php if ($mine['conflicts']): ?><p class="alert alert-error">Someone else changed some of the same things on staging meanwhile. You'll be asked what to do when you submit.</p><?php endif ?>
    <?php $changes($mine['items'], 'draft') ?>
    <form method="post" action="<?= $self ?>?r=submit" class="stack review-submit">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <label>What did you change? <small class="muted">(optional, shown to the reviewer and kept in the history)</small>
        <textarea name="summary" rows="2" maxlength="2000"></textarea></label>
      <div class="actions">
        <button class="btn btn-primary" type="submit">Submit to staging</button>
        <span class="muted">A preview is built for the reviewer.</span>
      </div>
    </form>
    <form method="post" action="<?= $self ?>?r=revert" class="inline" data-confirm="Throw away all of your unsubmitted changes?">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn btn-link link-danger" type="submit">Discard my whole draft</button>
    </form>
    <details class="tech">
      <summary>Technical details</summary>
      <?php $diffList($mine['items'], $mine['diffs']) ?>
    </details>
  </section>
<?php endif ?>
<?php if ($drafts): ?>
  <p class="muted">Also working on drafts: <?php foreach ($drafts as $i => $d): ?><?= $i ? ', ' : '' ?><?= $e($who($d['owner'])) ?> (<?= $plural((int) $d['files'], 'change', 'changes') ?>)<?php endforeach ?>.</p>
<?php endif ?>

<?php if ($open): ?>
  <section class="card wide review-card" id="staging">
    <h2>Waiting for review</h2>
    <?php if ($meta['submissions']): ?>
      <ul class="notes">
        <?php foreach ($meta['submissions'] as $sub): ?>
          <li><strong><?= $e($who($sub['by'])) ?></strong> <small class="muted"><?= $time($sub['at']) ?></small>
            <?php if ($sub['summary'] !== ''): ?><span class="note pre"><?= $e($sub['summary']) ?></span><?php endif ?></li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>

    <?php if ($problems): ?>
      <div class="alert alert-error"><strong>This can't be published yet.</strong> Undo the change that causes it, or ask whoever made it to fix it:
        <ul><?php foreach ($problems as $p): ?><li class="pre"><?= $e($p) ?></li><?php endforeach ?></ul></div>
    <?php endif ?>
    <?php if ($conflicts): ?>
      <div class="alert alert-error"><strong>Some of this was also changed on the live site since</strong> (for example by restoring a backup):
        <?= $e(implode(', ', array_map(fn ($c) => ChangeList::label($c), $conflicts))) ?>.
        Publishing with “Overwrite” replaces the live version with the one here.</div>
    <?php endif ?>

    <div class="preview-box">
      <?php if ($preview && (int) $preview['rev'] === (int) $meta['rev']): ?>
        <a class="btn btn-primary" href="<?= $e($preview['url']) ?>" target="_blank" rel="noopener">Open preview</a>
        <span class="muted">The whole site with these changes, <?= (int) $preview['pages'] ?> pages. Anyone with the link can view it.</span>
        <form method="post" action="<?= $self ?>?r=review-preview" class="inline">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <button class="btn btn-link" type="submit">Rebuild</button>
        </form>
        <?php if ($views): ?>
          <p class="preview-pages">Changed pages:
            <?php $i = 0; foreach ($items as $it): if (!isset($views[$it['path']])) continue; ?><?= $i++ ? ' · ' : '' ?><a href="<?= $e($views[$it['path']]) ?>" target="_blank" rel="noopener"><?= $e($it['label']) ?></a><?php endforeach ?></p>
        <?php endif ?>
      <?php else: ?>
        <form method="post" action="<?= $self ?>?r=review-preview" class="inline">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <button class="btn btn-primary" type="submit"<?= $problems ? ' disabled' : '' ?>><?= $preview ? 'Update the preview' : 'Build a preview' ?></button>
        </form>
        <span class="muted"><?= $problems ? 'Fix the problems above first.' : ($preview ? 'The preview is older than the latest changes.' : 'See the whole site with these changes before publishing.') ?></span>
      <?php endif ?>
    </div>
    <?php if ($preview && ($preview['redirects']['moved'] || $preview['redirects']['gone'])): ?>
      <div class="alert"><strong>Web addresses that change</strong> (visitors and search engines using the old address are sent on automatically):
        <ul>
          <?php foreach ($preview['redirects']['moved'] as $m): ?><li><code><?= $e($m['from']) ?></code> → <code><?= $e($m['to']) ?></code></li><?php endforeach ?>
          <?php foreach ($preview['redirects']['gone'] as $g): ?><li><code><?= $e($g['from']) ?></code> is removed and sends visitors to the home page</li><?php endforeach ?>
        </ul></div>
    <?php endif ?>

    <h3>What changes</h3>
    <?php $changes($items, 'staging') ?>

    <div class="decision">
      <form method="post" action="<?= $self ?>?r=publish" class="inline" data-confirm="Publish <?= $e($plural($nStaged, 'change', 'changes')) ?> to the live site?">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="rev" value="<?= $e($meta['rev']) ?>">
        <?php if ($conflicts): ?><label class="check"><input type="checkbox" name="force" value="1"> Overwrite</label><?php endif ?>
        <button class="btn btn-primary" type="submit"<?= $problems || !$items ? ' disabled' : '' ?>>Publish <?= $e($plural($nStaged, 'change', 'changes')) ?></button>
      </form>
      <form method="post" action="<?= $self ?>?r=reject" class="inline" data-confirm="Throw away everything on staging? Drafts are not affected.">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button class="btn btn-danger" type="submit">Reject all</button>
      </form>
      <span class="muted">A backup is made before publishing, and the old versions stay in the <a href="<?= $self ?>?r=history">history</a>.</span>
    </div>

    <details class="tech">
      <summary>Technical details</summary>
      <?php if ($preview && $preview['warnings']): ?>
        <h4>Build warnings</h4><ul><?php foreach ($preview['warnings'] as $w): ?><li><?= $e($w) ?></li><?php endforeach ?></ul>
      <?php endif ?>
      <h4>Changed files (<?= count($items) ?>)</h4>
      <?php $diffList($items, $diffs) ?>
    </details>
  </section>
<?php endif ?>
