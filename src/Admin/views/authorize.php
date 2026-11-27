<?php
/** @var callable $e */
/** @var Lou\Admin\I18n $t */
// The OAuth consent screen: an assistant (Claude, ChatGPT, Copilot…) asks to work on the site as this person.
$c = $request['client'];
$back = parse_url($request['redirect_uri']);
$host = (string) ($back['host'] ?? ($back['scheme'] ?? ''));
$fields = ['client_id', 'redirect_uri', 'state', 'response_type', 'code_challenge', 'code_challenge_method', 'resource'];
?>
<div class="card consent">
  <h1><?= $t('Connect {app} to {site}?', ['app' => $c['name'], 'site' => $siteName]) ?></h1>
  <p class="lead"><?= $t('{app} wants to work on this site for you. It will be able to:', ['app' => $c['name']]) ?></p>
  <ul>
    <li><?= $t('read the site’s pages, texts, templates and design') ?></li>
    <li><?= $t('make changes in its own draft and show you previews') ?></li>
    <li><?= $t('send its changes to Review, where someone who can publish approves them') ?></li>
  </ul>
  <p><?= $t('It can’t publish anything by itself, see form submissions or subscribers, or change users and settings.') ?></p>
  <?php if ($request['first_use']): ?>
    <p class="alert notice-warn"><?= $t('This is the first sign-in with these app credentials. Lou will remember this return address and accept no other:') ?> <code><?= $e($request['redirect_uri']) ?></code></p>
  <?php elseif ($c['dynamic']): ?>
    <p class="muted"><?= $t('The app registered itself under this name. Only approve if you started connecting it yourself just now.') ?></p>
  <?php endif ?>
  <p><?= $t('After you approve, you go back to {!host}.', ['host' => '<strong>' . $e($host) . '</strong>']) ?></p>
  <form method="post" action="<?= $self ?>?r=authorize" class="actions">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <?php foreach ($fields as $f): ?>
      <input type="hidden" name="<?= $f ?>" value="<?= $e(is_string($query[$f] ?? null) ? $query[$f] : '') ?>">
    <?php endforeach ?>
    <button class="btn btn-primary" type="submit" name="decision" value="approve"><?= $t('Approve') ?></button>
    <button class="btn" type="submit" name="decision" value="deny"><?= $t('Cancel') ?></button>
  </form>
  <details class="fold fold-small"><summary><?= $t('Technical details') ?></summary>
    <p class="muted"><?= $t('Signed in as {user}. The assistant will appear as “{name}” in drafts, Review and History, and is listed under Users → AI assistants, where an administrator can end the connection.', ['user' => $user, 'name' => mb_substr((string) $c['name'], 0, 40) . ' – ' . $user]) ?></p>
    <p class="muted"><?= $t('Client ID') ?>: <code><?= $e($c['id']) ?></code><br>
      <?= $t('Return address') ?>: <code><?= $e($request['redirect_uri']) ?></code><br>
      <?= $t('Registered') ?>: <?= $time($c['created_at']) ?></p>
  </details>
</div>
