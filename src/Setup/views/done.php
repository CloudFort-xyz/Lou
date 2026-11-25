<?php /** @var callable $e */ ?>
<section class="card">
<?php if ($already): ?>
  <h1>Lou is installed</h1>
  <p>This site is already set up, so the installer is switched off.</p>
<?php else: ?>
  <h1>Done</h1>
  <p>The site was built and your admin account is ready.</p>
  <?php if ($warnings): ?>
    <div class="alert"><strong>Notes from the build</strong><ul><?php foreach ($warnings as $w): ?><li><?= $e($w) ?></li><?php endforeach ?></ul></div>
  <?php endif ?>
<?php endif ?>
  <p><a class="btn btn-primary" href="<?= $e($endpoint) ?>/admin.php">Open the admin</a> <a class="btn" href="/">View the site</a></p>
  <p class="muted">Next: set up the cron jobs for retries and nightly backups (see INSTALL.md), and copy backups off the server from time to time.</p>
</section>
