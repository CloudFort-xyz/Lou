<?php /** @var callable $e */ ?>
<div class="page-head"><h1>Updates</h1></div>

<section class="widget settings-section">
  <h2>Lou <?= $e($version) ?></h2>
  <?php if ($updated): ?>
    <div class="alert alert-ok">Updated from <?= $e($updated['from']) ?> to <?= $e($updated['to']) ?> on <?= $time((string) $updated['at']) ?><?= empty($updated['signed']) ? ' (the zip was not signed)' : '' ?>.</div>
  <?php endif ?>
  <?php if ($blocked): ?>
    <p><?= $e($blocked) ?></p>
  <?php else: ?>
    <p class="muted">Updating replaces Lou's code only. Content, images, templates, submissions, settings and passwords stay as they are, and the live site keeps running until the rebuild.
      <?= $signed ? 'Every update zip is checked against Lou’s release signature.' : 'This install does not check release signatures yet (no release key), only that the files match the zip’s own list.' ?></p>

    <?php if ($checkError): ?><div class="alert alert-error"><?= $e($checkError) ?></div><?php endif ?>
    <?php if ($check): ?>
      <?php if (version_compare((string) $check['version'], $version, '>')): ?>
        <div class="alert"><strong>Lou <?= $e($check['version']) ?> is available.</strong> <a href="<?= $e($check['page']) ?>" target="_blank" rel="noopener">What’s new</a></div>
        <form method="post" action="<?= $self ?>?r=update" class="inline" data-confirm="Update Lou to <?= $e($check['version']) ?>? A backup first is a good idea.">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <button class="btn btn-primary" type="submit">Update to <?= $e($check['version']) ?></button>
        </form>
      <?php else: ?>
        <p>You have the latest version (checked <?= $time((string) $check['checked']) ?>).</p>
      <?php endif ?>
    <?php endif ?>
    <form method="post" action="<?= $self ?>?r=update-check" class="inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn" type="submit">Check for updates</button>
    </form>

    <h3>Upload a release</h3>
    <p class="muted">For servers that can't reach GitHub: download <code>lou-&lt;version&gt;.zip</code> from the releases page and upload it here (up to <?= $e($maxUpload) ?>). Unpacking the zip over the install with FTP works too, then rebuild here.</p>
    <form method="post" action="<?= $self ?>?r=update-upload" enctype="multipart/form-data" class="stack">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <label>Release zip <input type="file" name="release" accept=".zip" required></label>
      <label class="check"><input type="checkbox" name="allow_older" value="1"> Allow an older version (going back after a bad update)</label>
      <p><button class="btn" type="submit">Upload and update</button></p>
    </form>
  <?php endif ?>

  <h2 id="design">Site design from a zip</h2>
  <p>Upload a new version of the site's look: its templates, styles, scripts and design images. Texts, menus, settings and uploaded photos stay as they are. Nothing goes live until you have checked a preview and published it.</p>
  <form method="post" action="<?= $self ?>?r=design-upload" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label>Site zip <input type="file" name="site" accept=".zip" required></label>
    <label class="check"><input type="checkbox" name="review" value="1" checked> Send it to Review right away, with a preview</label>
    <p><button class="btn btn-primary" type="submit">Upload the design</button></p>
  </form>
  <details class="tech">
    <summary>How it works</summary>
    <p>Use a zip of the client site's repository (GitHub: Code → Download ZIP) or any zip with a <code>site/</code> folder, up to <?= $e($maxUpload) ?>.
      Files under <code>templates/</code> and <code>assets/</code> follow the zip, except files changed on the server since the last deploy: those keep the server's version and are listed after the upload.
      Content, <code>menu.json</code>, <code>config.php</code> and editor uploads are never touched. Templates go through the same safety checks as assistants' templates.</p>
    <p>The changes land in your draft. With “Send it to Review” ticked and nothing else in your draft, they are submitted to staging at once and the preview is built; otherwise submit them under Review yourself.</p>
  </details>

  <?php if ($needsBuild): ?>
    <h3>Rebuild</h3>
    <form method="post" action="<?= $self ?>?r=rebuild" class="inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn btn-primary" type="submit">Rebuild the site with <?= $e($version) ?></button>
    </form>
  <?php endif ?>
</section>
