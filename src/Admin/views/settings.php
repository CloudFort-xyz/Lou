<?php
/** @var callable $e */
$value = $configured ? $current : ($suggested ?: $current);
?>
<h1>Settings</h1>
<?php if ($saved): ?><div class="alert alert-ok">Saved. The live site was rebuilt with the new address.</div><?php endif ?>
<?php if (!$configured): ?>
  <div class="alert alert-error"><strong>The site address is not set yet</strong><?= $current !== '' ? ' (it is still the example, <code>' . $e($current) . '</code>)' : '' ?>. Until it is, the sitemap and search engine links are wrong.</div>
<?php endif ?>
<form method="post" action="<?= $self ?>?r=settings" class="stack">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label>Site address
    <input type="url" name="base_url" value="<?= $e($value) ?>" required placeholder="https://www.example.hu" pattern="https?://[^/?#]+/?">
  </label>
  <p class="muted">The public address of this site, without a path. It is used for the sitemap, canonical and language links, preview and share links, and the assistant bridge URL. Saving rebuilds the live site. It overrides <code>base_url</code> in <code>site/config.php</code>.</p>
  <p><button class="btn btn-primary" type="submit">Save</button></p>
</form>
