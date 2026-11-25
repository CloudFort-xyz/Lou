<div class="card">
  <p class="<?= !empty($error) ? 'alert alert-error' : 'alert' ?>"><?= $e($message) ?></p>
  <p><a href="<?= $self ?>?r=dashboard"><?= $t('Back to the dashboard') ?></a></p>
</div>
