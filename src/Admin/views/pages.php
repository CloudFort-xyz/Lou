<?php
/** @var callable $e */
$default = $site->defaultLanguage();
$live = rtrim((string) ($baseUrl ?? ''), '/');
?>
<div class="page-head">
  <h1><?= $t('Pages') ?></h1>
  <span class="muted"><?= $t('{pages} in {languages}', ['pages' => $t->plain('{n} page|{n} pages', ['n' => count($rows)]), 'languages' => $t->plain('{n} language|{n} languages', ['n' => count($site->languages())])]) ?></span>
</div>
<?php foreach ($warnings as $w): ?><p class="notice notice-warn"><?= $e($w) ?></p><?php endforeach ?>
<table class="table list">
  <thead><tr><th><?= $t('Title') ?></th><?php foreach ($site->languages() as $l): ?><th><?= $e(strtoupper($l)) ?></th><?php endforeach ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $main = $r['translations'][$default] ?? reset($r['translations']); ?>
    <tr>
      <td>
        <a class="row-title" href="<?= $self ?>?r=edit&amp;page=<?= $e($main->id) ?>&amp;lang=<?= $e($main->lang) ?>"><?= $e($main->title) ?></a>
        <?php if ($main->id === 'home'): ?><span class="badge badge-muted"><?= $t('Home page') ?></span><?php elseif ($main->isSpecial()): ?><span class="badge badge-muted"><?= $t('Not found page') ?></span><?php endif ?>
        <div class="row-actions">
          <a href="<?= $self ?>?r=edit&amp;page=<?= $e($main->id) ?>&amp;lang=<?= $e($main->lang) ?>"><?= $t('Edit') ?></a>
          <?php if (!$main->isSpecial()): ?><a href="<?= $e($live . $site->path($main->id, $main->lang)) ?>" target="_blank" rel="noopener"><?= $t('View live') ?></a><?php endif ?>
          <?php if (!empty($r['repeatable'])): ?>
            <form class="inline" method="post" action="<?= $self ?>?r=page-duplicate"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="page" value="<?= $e($r['id']) ?>"><button class="link" type="submit"><?= $t('Duplicate') ?></button></form>
            <form class="inline" method="post" action="<?= $self ?>?r=page-delete" data-confirm="<?= $t('Delete “{title}” in every language? It disappears from the site when your changes are published.', ['title' => $main->title]) ?>"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="page" value="<?= $e($r['id']) ?>"><button class="link link-danger" type="submit"><?= $t('Delete') ?></button></form>
          <?php endif ?>
          <span class="muted">id: <code><?= $e($r['id']) ?></code></span>
        </div>
      </td>
      <?php foreach ($site->languages() as $l): ?>
        <td>
          <?php if (isset($r['translations'][$l])): $p = $r['translations'][$l]; ?>
            <a href="<?= $self ?>?r=edit&amp;page=<?= $e($p->id) ?>&amp;lang=<?= $e($l) ?>"><?= $e($p->title) ?></a>
            <small class="muted"><?= $e($site->path($p->id, $l)) ?></small>
          <?php else: ?>
            <span class="badge badge-warn"><?= $t('missing translation') ?></span>
          <?php endif ?>
        </td>
      <?php endforeach ?>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<p class="muted"><?= $t('Pages marked repeatable in the site config (such as job ads) can be duplicated and deleted here. Other new pages, layout and menu changes are made through the assistant bridge or by a developer.') ?></p>
