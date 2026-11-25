<?php /** @var Lou\View $v */ $v->layout('layout') ?>
<section class="hero">
  <div class="hero__text">
    <h1<?= $v->editable('hero.title') ?>><?= $v->text('hero.title') ?></h1>
    <p<?= $v->editable('hero.text') ?>><?= $v->text('hero.text') ?></p>
    <a class="button" href="<?= $v->url('contact') ?>"><?= $v->e($v->site->page('contact', $v->lang())?->menuLabel) ?></a>
  </div>
  <?= $v->image('hero.image', class: 'hero__img', lazy: false) ?>
</section>
<section class="features">
  <?php foreach ($v->field('features', []) as $i => $f): ?>
    <article class="feature">
      <h2<?= $v->editable("features.$i.title") ?>><?= $v->text("features.$i.title") ?></h2>
      <p<?= $v->editable("features.$i.text") ?>><?= $v->text("features.$i.text") ?></p>
    </article>
  <?php endforeach ?>
</section>
