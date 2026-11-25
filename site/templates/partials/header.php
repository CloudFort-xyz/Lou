<?php /** @var Lou\View $v */ ?>
<header class="site-header">
  <a class="logo" href="<?= $v->url('home') ?>"><?= $v->global('company') ?></a>
  <nav aria-label="<?= $v->t('nav.label') ?>">
    <ul class="menu">
      <?php foreach ($v->menu() as $item): ?>
        <li><a href="<?= $item['url'] ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?>><?= $item['label'] ?></a></li>
      <?php endforeach ?>
    </ul>
  </nav>
  <?php $alts = $v->alternates(); if (count($alts) > 1): ?>
  <ul class="langs" aria-label="<?= $v->t('nav.languages') ?>">
    <?php foreach ($alts as $a): ?>
      <li><a href="<?= $a['url'] ?>" hreflang="<?= $a['lang'] ?>" lang="<?= $a['lang'] ?>"<?= $a['current'] ? ' aria-current="true"' : '' ?>><?= strtoupper($a['lang']) ?></a></li>
    <?php endforeach ?>
  </ul>
  <?php endif ?>
</header>
