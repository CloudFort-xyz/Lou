<?php
/**
 * Review: draft → staging → live. Plain-language change lists first (with Edit and Undo on each change),
 * the preview and the decision; file paths, build warnings and code diffs sit under "Technical details".
 * @var callable $e
 * @var Lou\Admin\I18n $t
 */
use Lou\Content\ChangeList;

$changesN = static fn (int $n): string => $t->plain('{n} change|{n} changes', ['n' => $n]);
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
$changes = static function (array $list, string $where) use ($e, $t, $self, $csrf, $by): void { ?>
  <?php foreach (ChangeList::grouped($list) as $group => $groupItems): ?>
    <h4 class="change-group"><?= $t(ChangeList::GROUPS[$group]) ?></h4>
    <ul class="changes">
    <?php foreach ($groupItems as $it): ?>
      <li class="change">
        <div class="change-head">
          <span class="change-label"><?= $e($it['label']) ?></span>
          <?php if ($it['kind'] !== 'changed'): ?><span class="badge<?= $it['kind'] === 'deleted' ? ' badge-warn' : '' ?>"><?= $it['kind'] === 'added' ? $t('new') : $t('removed') ?></span><?php endif ?>
          <?php if ($where === 'staging' && isset($by[$it['path']])): ?><small class="muted"><?= $t('by {name}', ['name' => implode(', ', array_keys($by[$it['path']]))]) ?></small><?php endif ?>
          <span class="change-actions">
            <?php if ($it['page'] && $it['kind'] !== 'deleted'): ?>
              <a href="<?= $self ?>?r=edit&amp;page=<?= $e(rawurlencode($it['page']['id'])) ?>&amp;lang=<?= $e($it['page']['lang']) ?>" title="<?= $t('Open this page in the editor. Your edit goes into your draft, on top of this change.') ?>"><?= $t('Edit') ?></a>
            <?php endif ?>
            <form method="post" action="<?= $self ?>?r=<?= $where === 'draft' ? 'revert' : 'staging-revert' ?>" class="inline"
                  data-confirm="<?= $where === 'draft' ? $t('Undo your change to {label}?', ['label' => $it['label']]) : $t('Take the change to {label} off staging? It goes back to the live version.', ['label' => $it['label']]) ?>">
              <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
              <input type="hidden" name="files[]" value="<?= $e($it['path']) ?>">
              <button class="link" type="submit"><?= $t('Undo') ?></button>
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
                elseif ($f['new'] === null): ?><del><?= $e($f['old']) ?></del> <small class="muted"><?= $t('(removed)') ?></small><?php
                else: ?><del><?= $e($f['old']) ?></del> → <ins><?= $e($f['new']) ?></ins><?php endif ?></span></li>
          <?php endforeach ?>
          <?php if ($it['more']): ?><li class="muted"><?= $t('and {n} more (see Technical details)', ['n' => $it['more']]) ?></li><?php endif ?>
          </ul>
        <?php elseif ($it['lines']): ?>
          <p class="change-note muted"><?= $t('{added} added, {removed} removed', ['added' => $t->plain('{n} line|{n} lines', ['n' => $it['lines'][0]]), 'removed' => (int) $it['lines'][1]]) ?></p>
        <?php endif ?>
      </li>
    <?php endforeach ?>
    </ul>
  <?php endforeach;
};

/** Code diffs of the given files, folded. */
$diffList = static function (array $list, array $diffs) use ($e, $t): void {
    foreach ($list as $it):
        if (!isset($diffs[$it['path']])) continue; ?>
      <details class="diff">
        <summary><code><?= $e($it['path']) ?></code> <span class="badge<?= $it['kind'] === 'deleted' ? ' badge-warn' : '' ?>"><?= $t($it['kind']) ?></span></summary>
        <pre><?php foreach (explode("\n", rtrim($diffs[$it['path']], "\n")) as $line):
          $c = match ($line[0] ?? ' ') { '+' => 'add', '-' => 'del', '@' => 'hunk', default => '' };
          ?><span class="<?= $c ?>"><?= $e($line) ?></span><?php endforeach ?></pre>
      </details>
    <?php endforeach;
};
?>
<div class="page-head"><h1><?= $t('Review and publish') ?></h1></div>

<ol class="flow" aria-label="<?= $t('Where changes are') ?>">
  <li class="<?= $nMine ? 'is-current' : '' ?>"><strong><?= $t('1. Your draft') ?></strong>
    <span><?= $nMine ? $t('{changes} only you can see', ['changes' => $changesN($nMine)]) : $t('No unsubmitted changes') ?></span></li>
  <li class="<?= $nStaged ? 'is-current' : '' ?>"><strong><?= $t('2. Staging') ?></strong>
    <span><?= $nStaged ? $t('{changes} waiting for review', ['changes' => $changesN($nStaged)]) : $t('Nothing waiting') ?></span></li>
  <li class="<?= !$nMine && !$nStaged ? 'is-current' : '' ?>"><strong><?= $t('3. Live') ?></strong>
    <span><?= $live ? $t('Published {!time}', ['time' => $time((string) $live['at'])]) : $t('Not published yet') ?></span></li>
</ol>

<?php if (!$mine && !$open): ?>
  <section class="card wide review-empty">
    <p><strong><?= $t('Nothing to review.') ?></strong> <?= $t('Edit a page under {!pages}: your changes are saved to your own draft and show up here, ready to submit.', ['pages' => '<a href="' . $self . '?r=pages">' . $t('Pages') . '</a>']) ?></p>
    <?php if ($unpublished): ?>
      <form method="post" action="<?= $self ?>?r=publish" class="inline">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button class="btn" type="submit"><?= $t('Rebuild the live site') ?></button> <span class="muted"><?= $t('Redirects or other site changes are not live yet.') ?></span>
      </form>
    <?php endif ?>
  </section>
<?php endif ?>

<?php if ($mine): ?>
  <section class="card wide review-card" id="draft">
    <h2><?= $t('Your draft') ?></h2>
    <p class="muted"><?= $t('Only you see these until you submit them. Undo takes back one change.') ?></p>
    <?php if ($mine['conflicts']): ?><p class="alert alert-error"><?= $t('Someone else changed some of the same things on staging meanwhile. You\'ll be asked what to do when you submit.') ?></p><?php endif ?>
    <?php $changes($mine['items'], 'draft') ?>
    <form method="post" action="<?= $self ?>?r=submit" class="stack review-submit">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <label><?= $t('What did you change?') ?> <small class="muted"><?= $t('(optional, shown to the reviewer and kept in the history)') ?></small>
        <textarea name="summary" rows="2" maxlength="2000"></textarea></label>
      <div class="actions">
        <button class="btn btn-primary" type="submit"><?= $t('Submit to staging') ?></button>
        <span class="muted"><?= $t('A preview is built for the reviewer.') ?></span>
      </div>
    </form>
    <form method="post" action="<?= $self ?>?r=revert" class="inline" data-confirm="<?= $t('Throw away all of your unsubmitted changes?') ?>">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn btn-link link-danger" type="submit"><?= $t('Discard my whole draft') ?></button>
    </form>
    <details class="tech">
      <summary><?= $t('Technical details') ?></summary>
      <?php $diffList($mine['items'], $mine['diffs']) ?>
    </details>
  </section>
<?php endif ?>
<?php if ($drafts): ?>
  <p class="muted"><?= $t('Also working on drafts:') ?> <?php foreach ($drafts as $i => $d): ?><?= $i ? ', ' : '' ?><?= $e($who($d['owner'])) ?> (<?= $e($changesN((int) $d['files'])) ?>)<?php endforeach ?>.</p>
<?php endif ?>

<?php if ($open): ?>
  <section class="card wide review-card" id="staging">
    <h2><?= $t('Waiting for review') ?></h2>
    <?php if ($meta['submissions']): ?>
      <ul class="notes">
        <?php foreach ($meta['submissions'] as $sub): ?>
          <li><strong><?= $e($who($sub['by'])) ?></strong> <small class="muted"><?= $time($sub['at']) ?></small>
            <?php if ($sub['summary'] !== ''): ?><span class="note pre"><?= $e($sub['summary']) ?></span><?php endif ?></li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>

    <?php if ($problems): ?>
      <div class="alert alert-error"><strong><?= $t('This can\'t be published yet.') ?></strong> <?= $t('Undo the change that causes it, or ask whoever made it to fix it:') ?>
        <ul><?php foreach ($problems as $p): ?><li class="pre"><?= $e($p) ?></li><?php endforeach ?></ul></div>
    <?php endif ?>
    <?php if ($conflicts): ?>
      <div class="alert alert-error"><strong><?= $t('Some of this was also changed on the live site since') ?></strong> <?= $t('(for example by restoring a backup):') ?>
        <?= $e(implode(', ', array_map(fn ($c) => ChangeList::label($c, null, $t->plain(...)), $conflicts))) ?>.
        <?= $t('Publishing with “Overwrite” replaces the live version with the one here.') ?></div>
    <?php endif ?>

    <div class="preview-box">
      <?php if ($preview && (int) $preview['rev'] === (int) $meta['rev']): ?>
        <a class="btn btn-primary" href="<?= $e($preview['url']) ?>" target="_blank" rel="noopener"><?= $t('Open preview') ?></a>
        <span class="muted"><?= $t('The whole site with these changes, {n} pages. Anyone with the link can view it.', ['n' => $preview['pages']]) ?></span>
        <form method="post" action="<?= $self ?>?r=review-preview" class="inline">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <button class="btn btn-link" type="submit"><?= $t('Rebuild') ?></button>
        </form>
      <?php else: ?>
        <form method="post" action="<?= $self ?>?r=review-preview" class="inline">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <button class="btn btn-primary" type="submit"<?= $problems ? ' disabled' : '' ?>><?= $preview ? $t('Update the preview') : $t('Build a preview') ?></button>
        </form>
        <span class="muted"><?= $problems ? $t('Fix the problems above first.') : ($preview ? $t('The preview is older than the latest changes.') : $t('See the whole site with these changes before publishing.')) ?></span>
      <?php endif ?>
    </div>
    <?php if ($preview && ($preview['redirects']['moved'] || $preview['redirects']['gone'])): ?>
      <div class="alert"><strong><?= $t('Web addresses that change') ?></strong> <?= $t('(visitors and search engines using the old address are sent on automatically):') ?>
        <ul>
          <?php foreach ($preview['redirects']['moved'] as $m): ?><li><code><?= $e($m['from']) ?></code> → <code><?= $e($m['to']) ?></code></li><?php endforeach ?>
          <?php foreach ($preview['redirects']['gone'] as $g): ?><li><?= $t('<code>{from}</code> is removed and sends visitors to the home page', ['from' => $g['from']]) ?></li><?php endforeach ?>
        </ul></div>
    <?php endif ?>

    <h3><?= $t('What changes') ?></h3>
    <?php $changes($items, 'staging') ?>

    <div class="decision">
      <form method="post" action="<?= $self ?>?r=publish" class="inline" data-confirm="<?= $t('Publish {changes} to the live site?', ['changes' => $changesN($nStaged)]) ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="rev" value="<?= $e($meta['rev']) ?>">
        <?php if ($conflicts): ?><label class="check"><input type="checkbox" name="force" value="1"> <?= $t('Overwrite') ?></label><?php endif ?>
        <button class="btn btn-primary" type="submit"<?= $problems || !$items ? ' disabled' : '' ?>><?= $t('Publish {changes}', ['changes' => $changesN($nStaged)]) ?></button>
      </form>
      <form method="post" action="<?= $self ?>?r=reject" class="inline" data-confirm="<?= $t('Throw away everything on staging? Drafts are not affected.') ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button class="btn btn-danger" type="submit"><?= $t('Reject all') ?></button>
      </form>
      <span class="muted"><?= $t('A backup is made before publishing, and the old versions stay in the {!history}.', ['history' => '<a href="' . $self . '?r=history">' . $t('history') . '</a>']) ?></span>
    </div>

    <details class="tech">
      <summary><?= $t('Technical details') ?></summary>
      <?php if ($preview && $preview['warnings']): ?>
        <h4><?= $t('Build warnings') ?></h4><ul><?php foreach ($preview['warnings'] as $w): ?><li><?= $e($w) ?></li><?php endforeach ?></ul>
      <?php endif ?>
      <h4><?= $t('Changed files ({n})', ['n' => count($items)]) ?></h4>
      <?php $diffList($items, $diffs) ?>
    </details>
  </section>
<?php endif ?>
