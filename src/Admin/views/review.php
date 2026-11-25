<?php /** @var callable $e */ ?>
<h1><?= $t('Review and publish') ?></h1>
<p class="muted"><?= $t('Edits go to each person\'s own draft first. Submitting a draft puts it on staging, and publishing staging makes it live.') ?></p>

<h2><?= $t('Your draft') ?></h2>
<?php if (!$mine): ?>
  <p class="muted"><?= $t('You have no unsubmitted changes.') ?></p>
<?php else: ?>
  <div class="card wide">
    <details class="fold fold-small"><summary><?= $t('{n} changed file|{n} changed files', ['n' => count($mine['files'])]) ?></summary>
      <p><?php foreach ($mine['files'] as $f): ?><code><?= $e($f) ?></code> <?php endforeach ?></p></details>
    <?php if ($mine['conflicts']): ?><p class="alert alert-error"><?= $t('Someone else changed some of the same files on staging. You\'ll be asked what to do when you submit.') ?></p><?php endif ?>
    <form method="post" action="<?= $self ?>?r=submit" class="stack">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <label><?= $t('What did you change?') ?> <small class="muted"><?= $t('(optional, shown to the reviewer and kept in the history)') ?></small>
        <textarea name="summary" rows="2" maxlength="2000"></textarea></label>
      <div class="actions">
        <button class="btn btn-primary" type="submit"><?= $t('Submit to staging') ?></button>
      </div>
    </form>
    <form method="post" action="<?= $self ?>?r=revert" class="inline" data-confirm="<?= $t('Throw away all of your unsubmitted changes?') ?>">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn btn-link" type="submit"><?= $t('Discard my draft') ?></button>
    </form>
  </div>
<?php endif ?>
<?php if ($drafts): ?>
  <p class="muted"><?= $t('Also working on drafts:') ?> <?php foreach ($drafts as $i => $d): ?><?= $i ? ', ' : '' ?><?= $e($who($d['owner'])) ?> (<?= $t('{n} file|{n} files', ['n' => $d['files']]) ?>)<?php endforeach ?>.</p>
<?php endif ?>

<h2><?= $t('Staging') ?></h2>
<?php if (!$open): ?>
  <p class="muted"><?= $t('Nothing is waiting to be published.') ?></p>
  <?php if ($unpublished): ?>
    <form method="post" action="<?= $self ?>?r=publish" class="inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn" type="submit"><?= $t('Rebuild the live site') ?></button> <span class="muted"><?= $t('Redirect or other site changes are not live yet.') ?></span>
    </form>
  <?php endif ?>
<?php else:
  $all = array_merge($files['added'], $files['modified'], $files['deleted']);
  $kind = fn ($p) => in_array($p, $files['added'], true) ? 'added' : (in_array($p, $files['deleted'], true) ? 'deleted' : 'changed');
?>
  <div class="card wide">
    <h3><?= $t('Submitted') ?></h3>
    <ul class="submissions">
      <?php foreach ($meta['submissions'] as $sub): ?>
        <li><strong><?= $e($who($sub['by'])) ?></strong>, <?= $time($sub['at']) ?>
          <?php if ($sub['summary'] !== ''): ?><blockquote class="pre"><?= $e($sub['summary']) ?></blockquote><?php endif ?>
          <details class="fold fold-small"><summary><?= $t('{n} file|{n} files', ['n' => count($sub['files'])]) ?></summary><small class="muted"><?= $e(implode(', ', $sub['files'])) ?></small></details></li>
      <?php endforeach ?>
    </ul>

    <?php if ($problems): ?>
      <div class="alert alert-error"><strong><?= $t('These problems must be fixed before publishing:') ?></strong>
        <ul><?php foreach ($problems as $p): ?><li class="pre"><?= $e($p) ?></li><?php endforeach ?></ul></div>
    <?php endif ?>
    <?php if ($conflicts): ?>
      <div class="alert alert-error"><strong><?= $t('Changed on the live site since staging was opened') ?></strong> <?= $t('(for example by a restore):') ?>
        <ul><?php foreach ($conflicts as $c): ?><li><code><?= $e($c) ?></code></li><?php endforeach ?></ul>
        <?= $t('Publishing with “overwrite” replaces those with the staged version.') ?></div>
    <?php endif ?>

    <h3><?= $t('Preview') ?></h3>
    <?php if ($preview && (int) $preview['rev'] === (int) $meta['rev']): ?>
      <p><a class="btn" href="<?= $e($preview['url']) ?>" target="_blank" rel="noopener"><?= $t('Open preview') ?></a>
        <span class="muted"><?= $t('{n} pages. Anyone with the link can view it.', ['n' => $preview['pages']]) ?></span></p>
    <?php else: ?>
      <p class="muted"><?= $preview ? $t('The preview is older than the latest submission.') : $t('No preview yet.') ?></p>
    <?php endif ?>
    <form method="post" action="<?= $self ?>?r=review-preview" class="inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn" type="submit"<?= $problems ? ' disabled' : '' ?>><?= $preview ? $t('Rebuild preview') : $t('Build preview') ?></button>
    </form>
    <?php if ($preview): ?>
      <?php if ($preview['redirects']['moved'] || $preview['redirects']['gone']): ?>
        <h3><?= $t('URLs that will change') ?></h3>
        <ul>
          <?php foreach ($preview['redirects']['moved'] as $m): ?><li><code><?= $e($m['from']) ?></code> → <code><?= $e($m['to']) ?></code> <?= $t('(301 redirect)') ?></li><?php endforeach ?>
          <?php foreach ($preview['redirects']['gone'] as $g): ?><li><code><?= $e($g['from']) ?></code> <?= $t('removed, redirects to the home page') ?></li><?php endforeach ?>
        </ul>
      <?php endif ?>
      <?php if ($preview['warnings']): ?>
        <h3><?= $t('Warnings') ?></h3><ul><?php foreach ($preview['warnings'] as $w): ?><li><?= $e($w) ?></li><?php endforeach ?></ul>
      <?php endif ?>
    <?php endif ?>

    <details class="fold" id="changed-files"><summary><h3><?= $t('Changed files ({n})', ['n' => count($all)]) ?></h3> <small class="muted"><?= $t('technical: every change, line by line') ?></small></summary>
    <?php if (count($diffs) > 1): ?><p class="fold-tools"><button class="btn btn-link" type="button" data-fold-all="#staged-diffs"><?= count($diffs) <= 5 ? $t('Collapse all') : $t('Expand all') ?></button></p><?php endif ?>
    <div id="staged-diffs">
    <?php foreach ($diffs as $path => $diff): ?>
      <details class="diff"<?= count($diffs) <= 5 ? ' open' : '' ?>>
        <summary><code><?= $e($path) ?></code> <span class="badge<?= $kind($path) === 'deleted' ? ' badge-warn' : '' ?>"><?= $t($kind($path)) ?></span></summary>
        <pre><?php foreach (explode("\n", rtrim($diff, "\n")) as $line):
          $c = match ($line[0] ?? ' ') { '+' => 'add', '-' => 'del', '@' => 'hunk', default => '' };
          ?><span class="<?= $c ?>"><?= $e($line) ?></span><?php endforeach ?></pre>
      </details>
    <?php endforeach ?>
    </div>
    </details>

    <h3><?= $t('Decision') ?></h3>
    <div class="actions">
      <form method="post" action="<?= $self ?>?r=publish" class="inline" data-confirm="<?= $t('Publish everything on staging to the live site?') ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="rev" value="<?= $e($meta['rev']) ?>">
        <?php if ($conflicts): ?><label class="check"><input type="checkbox" name="force" value="1"> <?= $t('Overwrite') ?></label><?php endif ?>
        <button class="btn btn-primary" type="submit"<?= $problems || !$all ? ' disabled' : '' ?>><?= $t('Publish') ?></button>
      </form>
      <form method="post" action="<?= $self ?>?r=reject" class="inline" data-confirm="<?= $t('Throw away everything on staging? Drafts are not affected.') ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button class="btn btn-danger" type="submit"><?= $t('Reject all') ?></button>
      </form>
    </div>
    <p class="muted"><?= $t('A backup is made before publishing, and the old versions are kept in the history.') ?></p>
  </div>
<?php endif ?>
