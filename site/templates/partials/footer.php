<?php /** @var Lou\View $v */ ?>
<footer class="site-footer">
  <p><?= $v->t('copyright', ['year' => date('Y'), 'company' => $v->site->globals($v->lang())['company'] ?? '']) ?> <?= $v->global('footer') ?></p>
</footer>
