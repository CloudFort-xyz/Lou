<?php
/** @var callable $e */
/** @var Lou\Admin\I18n $t */
// Shown for a moment after the consent screen: the Refresh header sends the browser back to the app.
?>
<div class="card">
  <p class="<?= $approved ? 'alert alert-ok' : 'alert' ?>"><?= $approved
      ? $t('{app} is connected. Taking you back…', ['app' => $client['name']])
      : $t('Cancelled. {app} was not connected.', ['app' => $client['name']]) ?></p>
  <p><a class="btn btn-primary" href="<?= $e($url) ?>"><?= $t('Continue to {host}', ['host' => $host]) ?></a></p>
</div>
