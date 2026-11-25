<?php /** @var callable $e */ ?>
<div class="page-head"><h1><?= $t('Install an unsigned release?') ?></h1></div>

<section class="widget settings-section">
  <div class="alert alert-error">
    <strong><?= $t('Security warning.') ?></strong>
    <?= $signature === 'invalid'
        ? $t('This zip has a signature, but it does not match Lou’s release key. The zip may have been changed after it was built.')
        : $t('This zip is not signed with Lou’s release key, so nobody can vouch for where it came from.') ?>
    <?= $t('Installing it runs its PHP code on this server with full access to the site, its content, form submissions and passwords.') ?>
  </div>
  <p><?= $t('Only continue if you built this zip yourself or got it straight from someone you trust, for example a test build of a branch.') ?></p>
  <dl class="facts">
    <dt><?= $t('Version in the zip') ?></dt><dd><?= $t('{!version} (installed: {current})', ['version' => '<code>' . $e($version) . '</code>', 'current' => $current]) ?></dd>
    <dt><?= $t('File') ?></dt><dd><?= $e($name) ?></dd>
  </dl>
  <details>
    <summary><?= $t('Technical details') ?></summary>
    <p class="muted"><?= $t('SHA-256 of the zip: {!sha}. Compare it with the <code>.sha256</code> file next to the zip where you got it.', ['sha' => '<code>' . $e($sha256) . '</code>']) ?></p>
    <p class="muted"><?= $t('Every file is still checked against the zip’s own <code>release.json</code>. Only the signature check is skipped. This screen appears because <code>.env.php</code> sets {!setting}. Remove that line to switch it off again. The uploaded zip is kept for an hour, then deleted.', ['setting' => '<code>' . $e("'allow_unsigned_updates' => true") . '</code>']) ?></p>
  </details>
  <form method="post" action="<?= $self ?>?r=update-unsigned" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="token" value="<?= $e($token) ?>">
    <input type="hidden" name="allow_older" value="<?= $allowOlder ? '1' : '' ?>">
    <label><?= $t('To confirm, type the version {!version}', ['version' => '<code>' . $e($version) . '</code>']) ?>
      <input name="confirm" required autocomplete="off" spellcheck="false"></label>
    <div class="row">
      <button class="btn btn-danger" type="submit"><?= $t('Install unsigned release') ?></button>
      <a class="btn btn-link" href="<?= $self ?>?r=updates"><?= $t('Cancel') ?></a>
    </div>
  </form>
</section>
