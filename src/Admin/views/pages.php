<?php
/** @var callable $e */
$default = $site->defaultLanguage();
$live = rtrim((string) ($baseUrl ?? ''), '/');
?>
<div class="page-head">
  <h1>Pages</h1>
  <span class="muted"><?= count($rows) ?> page<?= count($rows) === 1 ? '' : 's' ?> in <?= count($site->languages()) ?> language<?= count($site->languages()) === 1 ? '' : 's' ?></span>
</div>
<?php foreach ($warnings as $w): ?><p class="notice notice-warn"><?= $e($w) ?></p><?php endforeach ?>
<table class="table list">
  <thead><tr><th>Title</th><?php foreach ($site->languages() as $l): ?><th><?= $e(strtoupper($l)) ?></th><?php endforeach ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $main = $r['translations'][$default] ?? reset($r['translations']); ?>
    <tr>
      <td>
        <a class="row-title" href="<?= $self ?>?r=edit&amp;page=<?= $e($main->id) ?>&amp;lang=<?= $e($main->lang) ?>"><?= $e($main->title) ?></a>
        <?php if ($main->id === 'home'): ?><span class="badge badge-muted">Home page</span><?php elseif ($main->isSpecial()): ?><span class="badge badge-muted">Not found page</span><?php endif ?>
        <div class="row-actions">
          <a href="<?= $self ?>?r=edit&amp;page=<?= $e($main->id) ?>&amp;lang=<?= $e($main->lang) ?>">Edit</a>
          <?php if (!$main->isSpecial()): ?><a href="<?= $e($live . $site->path($main->id, $main->lang)) ?>" target="_blank" rel="noopener">View live</a><?php endif ?>
          <span class="muted">id: <code><?= $e($r['id']) ?></code></span>
        </div>
      </td>
      <?php foreach ($site->languages() as $l): ?>
        <td>
          <?php if (isset($r['translations'][$l])): $p = $r['translations'][$l]; ?>
            <a href="<?= $self ?>?r=edit&amp;page=<?= $e($p->id) ?>&amp;lang=<?= $e($l) ?>"><?= $e($p->title) ?></a>
            <small class="muted"><?= $e($site->path($p->id, $l)) ?></small>
          <?php else: ?>
            <span class="badge badge-warn">missing translation</span>
          <?php endif ?>
        </td>
      <?php endforeach ?>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<p class="muted">New pages, layout and menu changes are made through the assistant bridge or by a developer.</p>
