<?php /** @var Lou\View $v */ $v->layout('layout') ?>
<section class="page-head">
  <h1<?= $v->editable('hero.title') ?>><?= $v->text('hero.title') ?></h1>
  <p class="lead"<?= $v->editable('hero.text') ?>><?= $v->text('hero.text') ?></p>
</section>
<form class="form"<?= $v->formAttrs('contact') ?>>
  <?= $v->formFields('contact') ?>
  <label><?= $v->t('form.name') ?> <input type="text" name="name" required maxlength="100" autocomplete="name"></label>
  <label><?= $v->t('form.email') ?> <input type="email" name="email" required maxlength="200" autocomplete="email"></label>
  <label><?= $v->t('form.phone') ?> <input type="tel" name="phone" maxlength="30" autocomplete="tel"></label>
  <label><?= $v->t('form.message') ?> <textarea name="message" required maxlength="5000" rows="6"></textarea></label>
  <label class="check"><input type="checkbox" name="consent" value="1" required> <?= $v->t('form.consent') ?></label>
  <?= $v->captcha('contact') ?>
  <button class="button" type="submit"><?= $v->t('form.send') ?></button>
  <p data-lou-status role="status" aria-live="polite"></p>
</form>
<address class="contact-details">
  <?= $v->global('company') ?><br>
  <?= $v->global('address') ?><br>
  <a href="mailto:<?= $v->global('email') ?>"><?= $v->global('email') ?></a><br>
  <?= $v->global('phone') ?>
</address>
