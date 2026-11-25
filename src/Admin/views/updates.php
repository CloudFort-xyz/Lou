<?php /** @var callable $e */ ?>
<div class="page-head"><h1><?= $t('Updates') ?></h1></div>

<section class="widget settings-section">
  <h2>Lou <?= $e($version) ?></h2>
  <?php if ($updated): ?>
    <div class="alert alert-ok"><?= $t('Updated from {from} to {to} on {!time}.', ['from' => $updated['from'], 'to' => $updated['to'], 'time' => $time((string) $updated['at'])]) ?><?= empty($updated['signed']) ? ' ' . $t('The zip was not signed.') : '' ?></div>
  <?php endif ?>
  <?php if ($blocked): ?>
    <p><?= $e($blocked) ?></p>
  <?php else: ?>
    <p class="muted"><?= $t('Updating changes Lou itself only. Your content, images, design, form submissions, settings and passwords stay as they are, and the site keeps running.') ?></p>
    <details class="fold fold-small"><summary><?= $t('Technical details') ?></summary>
      <p class="muted"><?= $t('The update replaces Lou\'s code, then the site is rebuilt with it.') ?> <?= $signed ? $t('Every update zip is checked against Lou’s release signature.') : $t('This install does not check release signatures yet (no release key), only that the files match the zip’s own list.') ?></p>
    </details>

    <?php if ($checkError): ?><div class="alert alert-error"><?= $e($checkError) ?></div><?php endif ?>
    <?php if ($check): ?>
      <?php if (version_compare((string) $check['version'], $version, '>')): ?>
        <div class="alert"><strong><?= $t('Lou {version} is available.', ['version' => $check['version']]) ?></strong> <a href="<?= $e($check['page']) ?>" target="_blank" rel="noopener"><?= $t('What’s new') ?></a></div>
        <form method="post" action="<?= $self ?>?r=update" class="inline" data-confirm="<?= $t('Update Lou to {version}? A backup first is a good idea.', ['version' => $check['version']]) ?>">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <button class="btn btn-primary" type="submit"><?= $t('Update to {version}', ['version' => $check['version']]) ?></button>
        </form>
      <?php else: ?>
        <p><?= $t('You have the latest version (checked {!time}).', ['time' => $time((string) $check['checked'])]) ?></p>
      <?php endif ?>
    <?php endif ?>
    <form method="post" action="<?= $self ?>?r=update-check" class="inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn" type="submit"><?= $t('Check for updates') ?></button>
    </form>

    <details class="fold"><summary><h3><?= $t('Upload a release') ?></h3> <small class="muted"><?= $t('for servers that can\'t reach GitHub') ?></small></summary>
    <p class="muted"><?= $t('For servers that can\'t reach GitHub: download <code>lou-&lt;version&gt;.zip</code> from the releases page and upload it here (up to {max}). Unpacking the zip over the install with FTP works too, then rebuild here.', ['max' => $maxUpload]) ?></p>
    <form method="post" action="<?= $self ?>?r=update-upload" enctype="multipart/form-data" class="stack">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <label><?= $t('Release zip') ?> <input type="file" name="release" accept=".zip" required></label>
      <label class="check"><input type="checkbox" name="allow_older" value="1"> <?= $t('Allow an older version (going back after a bad update)') ?></label>
      <?php if ($unsignedAllowed): ?><p class="muted"><?= $t('Unsigned zips (test builds) are allowed on this install by <code>.env.php</code>. You’ll see a security warning and confirm before one is installed.') ?></p><?php endif ?>
      <p><button class="btn" type="submit"><?= $t('Upload and update') ?></button></p>
    </form>
    </details>
  <?php endif ?>

  <details class="fold" id="design"><summary><h2><?= $t('Design update from a zip') ?></h2> <small class="muted"><?= $t('templates, styles and design images only') ?></small></summary>
  <p><?= $t('<strong>Design only:</strong> the site\'s templates, styles, scripts and design images are updated from the zip. Page texts, menus, settings and uploaded photos on this server are never changed, even if the zip contains them. Nothing goes live until you have checked a preview and published it.') ?></p>
  <form method="post" action="<?= $self ?>?r=design-upload" enctype="multipart/form-data" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label><?= $t('Site zip') ?> <input type="file" name="site" accept=".zip" required></label>
    <label class="check"><input type="checkbox" name="review" value="1" checked> <?= $t('Send it to Review right away, with a preview') ?></label>
    <p><button class="btn btn-primary" type="submit"><?= $t('Upload the design') ?></button></p>
  </form>
  <details class="tech">
    <summary><?= $t('How it works') ?></summary>
    <p><?= $t('Use a zip of the client site\'s repository (GitHub: Code → Download ZIP) or any zip with a <code>site/</code> folder, up to {max}.', ['max' => $maxUpload]) ?>
      <?= $t('Files under <code>templates/</code> and <code>assets/</code> follow the zip, except files changed on the server since the last deploy: those keep the server\'s version and are listed after the upload.') ?>
      <?= $t('Content, <code>menu.json</code>, <code>config.php</code> and editor uploads are never touched. Templates go through the same safety checks as assistants\' templates.') ?></p>
    <p><?= $t('The changes land in your draft. With “Send it to Review” ticked and nothing else in your draft, they are submitted to staging at once and the preview is built; otherwise submit them under Review yourself.') ?></p>
  </details>
  </details>

  <?php if ($needsBuild): ?>
    <h3><?= $t('Rebuild') ?></h3>
    <form method="post" action="<?= $self ?>?r=rebuild" class="inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn btn-primary" type="submit"><?= $t('Rebuild the site with {version}', ['version' => $version]) ?></button>
    </form>
  <?php endif ?>
</section>
