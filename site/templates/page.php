<?php /** @var Lou\View $v */ $v->layout('layout') ?>
<section class="page-head">
  <h1<?= $v->editable('hero.title') ?>><?= $v->text('hero.title') ?></h1>
  <?php if ($v->has('hero.text')): ?>
    <p class="lead"<?= $v->editable('hero.text') ?>><?= $v->text('hero.text') ?></p>
  <?php endif ?>
</section>
<?php if ($v->has('body')): ?>
<div class="prose"<?= $v->editable('body') ?>><?= $v->html('body') ?></div>
<?php endif ?>
