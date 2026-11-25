<div class="card">
  <p class="<?= !empty($error) ? 'alert alert-error' : 'alert' ?>"><?= $e($message) ?></p>
  <p><a href="<?= $self ?>?r=dashboard">Back to the dashboard</a></p>
</div>
