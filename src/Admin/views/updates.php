<?php /** @var callable $e */ ?>
<div class="page-head"><h1>Updates</h1></div>

<section class="widget settings-section">
  <h2>Lou <?= $e($version) ?></h2>
  <?php if ($updated): ?>
    <div class="alert alert-ok">Updated from <?= $e($updated['from']) ?> to <?= $e($updated['to']) ?> on <?= $e(substr((string) $updated['at'], 0, 16)) ?> UTC<?= empty($updated['signed']) ? ' (the zip was not signed)' : '' ?>.</div>
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
        <p>You have the latest version (checked <?= $e(substr((string) $check['checked'], 0, 16)) ?> UTC).</p>
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
  <p class="muted">Deploy a client site's templates and assets without Git: upload a zip of its repository (GitHub: Code → Download ZIP) or any zip with a <code>site/</code> folder. Templates and assets follow the zip, except files changed on the server since the last deploy. Content, menus, settings and uploaded images are never touched. The changes go into your draft, so you review and publish them as usual.</p>
  <form method="post" action="<?= $self ?>?r=design-upload" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label>Site zip <input type="file" name="site" accept=".zip" required></label>
    <p><button class="btn" type="submit">Import into my draft</button></p>
  </form>

  <?php if ($needsBuild): ?>
    <h3>Rebuild</h3>
    <form method="post" action="<?= $self ?>?r=rebuild" class="inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn btn-primary" type="submit">Rebuild the site with <?= $e($version) ?></button>
    </form>
  <?php endif ?>
</section>
