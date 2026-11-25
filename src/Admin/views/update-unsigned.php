<?php /** @var callable $e */ ?>
<div class="page-head"><h1>Install an unsigned release?</h1></div>

<section class="widget settings-section">
  <div class="alert alert-error">
    <strong>Security warning.</strong>
    <?= $signature === 'invalid'
        ? 'This zip has a signature, but it does not match Lou’s release key. The zip may have been changed after it was built.'
        : 'This zip is not signed with Lou’s release key, so nobody can vouch for where it came from.' ?>
    Installing it runs its PHP code on this server with full access to the site, its content, form submissions and passwords.
  </div>
  <p>Only continue if you built this zip yourself or got it straight from someone you trust, for example a test build of a branch.</p>
  <dl class="facts">
    <dt>Version in the zip</dt><dd><code><?= $e($version) ?></code> (installed: <?= $e($current) ?>)</dd>
    <dt>File</dt><dd><?= $e($name) ?></dd>
  </dl>
  <details>
    <summary>Technical details</summary>
    <p class="muted">SHA-256 of the zip: <code><?= $e($sha256) ?></code>. Compare it with the <code>.sha256</code> file next to the zip where you got it.</p>
    <p class="muted">Every file is still checked against the zip’s own <code>release.json</code>. Only the signature check is skipped. This screen appears because <code>.env.php</code> sets <code>'allow_unsigned_updates' => true</code>. Remove that line to switch it off again. The uploaded zip is kept for an hour, then deleted.</p>
  </details>
  <form method="post" action="<?= $self ?>?r=update-unsigned" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="token" value="<?= $e($token) ?>">
    <input type="hidden" name="allow_older" value="<?= $allowOlder ? '1' : '' ?>">
    <label>To confirm, type the version <code><?= $e($version) ?></code>
      <input name="confirm" required autocomplete="off" spellcheck="false"></label>
    <div class="row">
      <button class="btn btn-danger" type="submit">Install unsigned release</button>
      <a class="btn btn-link" href="<?= $self ?>?r=updates">Cancel</a>
    </div>
  </form>
</section>
