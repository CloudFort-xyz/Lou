<?php /** @var callable $e */ ?>
<div class="page-head">
  <h1><?= $t('Dashboard') ?></h1>
</div>

<div class="widgets">
  <section class="widget">
    <h2><?= $t('At a glance') ?></h2>
    <ul class="glance">
      <li><a href="<?= $self ?>?r=pages"><?= $t('<strong>{n}</strong> page|<strong>{n}</strong> pages', ['n' => $pageCount]) ?></a>
        <small class="muted"><?= $t('in {languages}', ['languages' => implode(', ', array_map('strtoupper', $site->languages()))]) ?></small></li>
      <?php if ($canPublish): ?>
      <li><a href="<?= $self ?>?r=submissions"><?= $t('<strong>{n}</strong> form submission|<strong>{n}</strong> form submissions', ['n' => $submissions]) ?></a>
        <small class="muted"><?= $t('{n} in the last 7 days', ['n' => $recentCount]) ?></small></li>
      <li><a href="<?= $self ?>?r=subscribers"><?= $t('<strong>{n}</strong> newsletter subscriber|<strong>{n}</strong> newsletter subscribers', ['n' => $subscribers]) ?></a></li>
      <?php endif ?>
    </ul>
    <p class="muted"><?= $live ? $t('Live build published {!time}.', ['time' => $time($live['at'])]) : $t('The live site has not been published from the admin yet.') ?></p>
  </section>

  <section class="widget">
    <h2><?= $t('Publishing') ?></h2>
    <ol class="steps">
      <li class="<?= $drafted ? 'is-active' : '' ?>"><strong><?= $t('Your draft') ?></strong>
        <span><?= $drafted ? $t('{n} changed file, not submitted yet|{n} changed files, not submitted yet', ['n' => $drafted]) : $t('No unsubmitted changes') ?></span></li>
      <li class="<?= $stagingOpen ? 'is-active' : '' ?>"><strong><?= $t('Staging') ?></strong>
        <span><?= $stagingOpen ? $t('{files} from {subs}, waiting for review', ['files' => $t->plain('{n} file|{n} files', ['n' => $staged]), 'subs' => $t->plain('{n} submission|{n} submissions', ['n' => $stagingSubs])]) : $t('Nothing waiting to be published') ?></span></li>
      <li class="<?= $unpublished && !$stagingOpen ? 'is-active' : '' ?>"><strong><?= $t('Live') ?></strong>
        <span><?= $unpublished && !$stagingOpen ? $t('Site changes (such as redirects) are not live yet') : $t('Up to date') ?></span></li>
    </ol>
    <p class="widget-actions">
      <a class="btn<?= $drafted || $stagingOpen || $unpublished ? ' btn-primary' : '' ?>" href="<?= $self ?>?r=review"><?= $canPublish ? $t('Review and publish') : $t('Review and submit') ?></a>
      <a class="btn" href="<?= $self ?>?r=edit"><?= $t('Edit the home page') ?></a>
    </p>
  </section>

  <?php if ($canPublish): ?>
  <section class="widget">
    <h2><?= $t('Latest submissions') ?></h2>
    <?php if (!$latest): ?>
      <p class="muted"><?= $t('No form submissions yet.') ?></p>
    <?php else: ?>
      <ul class="feed">
        <?php foreach ($latest as $r): $d = json_decode($r['data'], true) ?: []; ?>
          <li><a href="<?= $self ?>?r=submission&amp;id=<?= (int) $r['id'] ?>"><?= $e(($d['name'] ?? '') !== '' ? $d['name'] : ($d['email'] ?? '#' . $r['id'])) ?></a>
            <small class="muted"><?= $e($r['form']) ?> · <?= $time($r['created_at']) ?></small>
            <?php if ($r['status'] !== 'ok'): ?><span class="badge badge-warn"><?= $t($r['status']) ?></span><?php endif ?></li>
        <?php endforeach ?>
      </ul>
      <p><a href="<?= $self ?>?r=submissions"><?= $t('All submissions') ?></a></p>
    <?php endif ?>
  </section>
  <?php endif ?>

  <section class="widget">
    <h2><?= $t('Recently published') ?></h2>
    <?php if (!$history): ?>
      <p class="muted"><?= $t('Nothing published from the admin yet.') ?></p>
    <?php else: ?>
      <ul class="feed">
        <?php foreach ($history as $h): $first = $h['submissions'][0] ?? null; ?>
          <li><a href="<?= $self ?>?r=history-entry&amp;id=<?= $e($h['id']) ?>"><?= $first && $first['summary'] !== '' ? $e(mb_strimwidth($first['summary'], 0, 80, '…')) : $t('{n} changed file|{n} changed files', ['n' => count($h['files'])]) ?></a>
            <small class="muted"><?= $t('{!time} by {name}', ['time' => $time($h['at']), 'name' => $h['by']]) ?><?= $first && $who($first['by']) !== $h['by'] ? $t(', from {name}', ['name' => $who($first['by'])]) : '' ?></small></li>
        <?php endforeach ?>
      </ul>
      <p><a href="<?= $self ?>?r=history"><?= $t('Full history') ?></a></p>
    <?php endif ?>
  </section>
</div>
<p class="muted"><?= $t('Text and images are edited in the page editor. New pages, layout, design and menu changes are made by an assistant through the bridge.') ?><?= $role === 'admin' ? ' <a href="' . $self . '?r=assistants">' . $t('Set up an assistant') . '</a>' : '' ?></p>
