<?php /** @var Lou\View $v */ $v->layout('layout') ?>
<section class="page-head">
  <h1<?= $v->editable('hero.title') ?>><?= $v->text('hero.title') ?></h1>
  <p class="lead"<?= $v->editable('hero.text') ?>><?= $v->text('hero.text') ?></p>
</section>
<!-- The submit endpoint arrives in milestone 2. The form already works without JS (plain POST). -->
<form class="form" method="post" action="/_lou/submit.php">
  <input type="hidden" name="form" value="contact">
  <input type="hidden" name="lang" value="<?= $v->lang() ?>">
  <div class="hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
  <label><?= $v->t('form.name') ?> <input type="text" name="name" required maxlength="100" autocomplete="name"></label>
  <label><?= $v->t('form.email') ?> <input type="email" name="email" required autocomplete="email"></label>
  <label><?= $v->t('form.message') ?> <textarea name="message" required maxlength="5000" rows="6"></textarea></label>
  <label class="check"><input type="checkbox" name="consent" value="1" required> <?= $v->t('form.consent') ?></label>
  <button class="button" type="submit"><?= $v->t('form.send') ?></button>
</form>
<address class="contact-details">
  <?= $v->global('company') ?><br>
  <?= $v->global('address') ?><br>
  <a href="mailto:<?= $v->global('email') ?>"><?= $v->global('email') ?></a><br>
  <?= $v->global('phone') ?>
</address>
