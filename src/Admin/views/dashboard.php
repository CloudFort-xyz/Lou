<?php
/** @var callable $e */
$who = static fn (string $owner): string => preg_replace('/^(user|llm):/', '', $owner) . (str_starts_with($owner, 'llm:') ? ' (assistant)' : '');
$when = static fn (string $iso): string => substr(str_replace('T', ' ', $iso), 0, 16);
?>
<div class="page-head">
  <h1>Dashboard</h1>
</div>

<div class="widgets">
  <section class="widget">
    <h2>At a glance</h2>
    <ul class="glance">
      <li><a href="<?= $self ?>?r=pages"><strong><?= (int) $pageCount ?></strong> page<?= $pageCount === 1 ? '' : 's' ?></a>
        <small class="muted">in <?= $e(implode(', ', array_map('strtoupper', $site->languages()))) ?></small></li>
      <li><a href="<?= $self ?>?r=submissions"><strong><?= (int) $submissions ?></strong> form submission<?= $submissions === 1 ? '' : 's' ?></a>
        <small class="muted"><?= (int) $recentCount ?> in the last 7 days</small></li>
      <li><a href="<?= $self ?>?r=subscribers"><strong><?= (int) $subscribers ?></strong> newsletter subscriber<?= $subscribers === 1 ? '' : 's' ?></a></li>
    </ul>
    <p class="muted"><?php if ($live): ?>Live build published <?= $e($when($live['at'])) ?> UTC.<?php else: ?>The live site has not been published from the admin yet.<?php endif ?></p>
  </section>

  <section class="widget">
    <h2>Publishing</h2>
    <ol class="steps">
      <li class="<?= $drafted ? 'is-active' : '' ?>"><strong>Your draft</strong>
        <span><?= $drafted ? (int) $drafted . ' changed file' . ($drafted === 1 ? '' : 's') . ', not submitted yet' : 'No unsubmitted changes' ?></span></li>
      <li class="<?= $stagingOpen ? 'is-active' : '' ?>"><strong>Staging</strong>
        <span><?= $stagingOpen ? (int) $staged . ' file' . ($staged === 1 ? '' : 's') . ' from ' . (int) $stagingSubs . ' submission' . ($stagingSubs === 1 ? '' : 's') . ', waiting for review' : 'Nothing waiting to be published' ?></span></li>
      <li class="<?= $unpublished && !$stagingOpen ? 'is-active' : '' ?>"><strong>Live</strong>
        <span><?= $unpublished && !$stagingOpen ? 'Site changes (such as redirects) are not live yet' : 'Up to date' ?></span></li>
    </ol>
    <p class="widget-actions">
      <a class="btn<?= $drafted || $stagingOpen || $unpublished ? ' btn-primary' : '' ?>" href="<?= $self ?>?r=review">Review and publish</a>
      <a class="btn" href="<?= $self ?>?r=edit">Edit the home page</a>
    </p>
  </section>

  <section class="widget">
    <h2>Latest submissions</h2>
    <?php if (!$latest): ?>
      <p class="muted">No form submissions yet.</p>
    <?php else: ?>
      <ul class="feed">
        <?php foreach ($latest as $r): $d = json_decode($r['data'], true) ?: []; ?>
          <li><a href="<?= $self ?>?r=submission&amp;id=<?= (int) $r['id'] ?>"><?= $e(($d['name'] ?? '') !== '' ? $d['name'] : ($d['email'] ?? '#' . $r['id'])) ?></a>
            <small class="muted"><?= $e($r['form']) ?> · <?= $e($when($r['created_at'])) ?></small>
            <?php if ($r['status'] !== 'ok'): ?><span class="badge badge-warn"><?= $e($r['status']) ?></span><?php endif ?></li>
        <?php endforeach ?>
      </ul>
      <p><a href="<?= $self ?>?r=submissions">All submissions</a></p>
    <?php endif ?>
  </section>

  <section class="widget">
    <h2>Recently published</h2>
    <?php if (!$history): ?>
      <p class="muted">Nothing published from the admin yet.</p>
    <?php else: ?>
      <ul class="feed">
        <?php foreach ($history as $h): $first = $h['submissions'][0] ?? null; ?>
          <li><a href="<?= $self ?>?r=history-entry&amp;id=<?= $e($h['id']) ?>"><?= $first && $first['summary'] !== '' ? $e(mb_strimwidth($first['summary'], 0, 80, '…')) : count($h['files']) . ' changed file' . (count($h['files']) === 1 ? '' : 's') ?></a>
            <small class="muted"><?= $e($when($h['at'])) ?> by <?= $e($h['by']) ?><?= $first && $who($first['by']) !== $h['by'] ? ', from ' . $e($who($first['by'])) : '' ?></small></li>
        <?php endforeach ?>
      </ul>
      <p><a href="<?= $self ?>?r=history">Full history</a></p>
    <?php endif ?>
  </section>
</div>
<p class="muted">Text and images are edited in the page editor. New pages, layout, design and menu changes are made by an assistant through the bridge<?= $role === 'admin' ? ' (see <a href="' . $self . '?r=assistants">Assistants</a>)' : '' ?>.</p>
