<?php /** @var Lou\View $v */ ?>
<footer class="site-footer">
  <form class="newsletter"<?= $v->formAttrs('newsletter') ?>>
    <?= $v->formFields('newsletter') ?>
    <label><?= $v->t('newsletter.title') ?> <input type="email" name="email" required maxlength="200" autocomplete="email" placeholder="<?= $v->t('form.email') ?>"></label>
    <label class="check"><input type="checkbox" name="consent" value="1" required> <?= $v->t('form.consent') ?></label>
    <?= $v->captcha('newsletter') ?>
    <button class="button" type="submit"><?= $v->t('newsletter.subscribe') ?></button>
    <p data-status role="status" aria-live="polite"></p>
  </form>
  <p><?= $v->t('copyright', ['year' => date('Y'), 'company' => $v->site->globals($v->lang())['company'] ?? '']) ?> <?= $v->global('footer') ?></p>
</footer>
