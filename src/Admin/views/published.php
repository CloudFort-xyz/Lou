<div class="card">
  <h1>Published</h1>
  <p class="alert alert-ok">Built <?= (int) $result['pages'] ?> pages. The live site is updated.</p>
  <?php if ($result['redirects']['moved']): ?>
    <h2>New redirects</h2>
    <ul><?php foreach ($result['redirects']['moved'] as $m): ?><li><code><?= $e($m['from']) ?></code> → <code><?= $e($m['to']) ?></code></li><?php endforeach ?></ul>
  <?php endif ?>
  <?php if ($result['warnings']): ?>
    <h2>Warnings</h2>
    <ul><?php foreach ($result['warnings'] as $w): ?><li><?= $e($w) ?></li><?php endforeach ?></ul>
  <?php endif ?>
  <p><a href="<?= $self ?>">Back to pages</a></p>
</div>
