<?php
$targets = [];
foreach ($site->allPages() as $p) {
    if (!$p->isSpecial()) {
        $targets[$p->id . '|' . $p->lang] = $site->path($p->id, $p->lang) . ' (' . $p->title . ')';
    }
}
asort($targets);
?>
<h1><?= $t('Redirects') ?></h1>
<p class="muted"><?= $t('Created automatically when pages are renamed, moved or deleted. Removed pages point to the home page until you choose a better target.') ?></p>
<?php if (!$entries): ?><p class="muted"><?= $t('No redirects yet.') ?></p><?php else: ?>
<table class="table list">
  <thead><tr><th><?= $t('From') ?></th><th><?= $t('To') ?></th><th><?= $t('Change') ?></th></tr></thead>
  <tbody>
  <?php foreach ($entries as $r): $current = is_array($r['to'] ?? null) ? $r['to']['page'] . '|' . $r['to']['lang'] : ''; ?>
    <tr>
      <td><code><?= $e($r['from']) ?></code><?php if (($r['auto'] ?? '') === 'gone'): ?> <span class="badge badge-warn"><?= $t('page removed') ?></span><?php endif ?></td>
      <td><?php
        if ((int) ($r['status'] ?? 301) === 410) { echo '410 Gone'; }
        elseif (is_array($r['to'] ?? null)) { echo $e($targets[$r['to']['page'] . '|' . $r['to']['lang']] ?? $r['to']['page']); }
        else { echo $e($r['to'] ?? ''); }
      ?></td>
      <td>
        <form method="post" action="<?= $self ?>?r=redirect" class="inline">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="from" value="<?= $e($r['from']) ?>">
          <select name="target" aria-label="<?= $t('New target for {from}', ['from' => $r['from']]) ?>"><?php foreach ($targets as $k => $label): ?><option value="<?= $e($k) ?>"<?= $k === $current ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach ?></select>
          <button class="btn" name="action" value="page" type="submit"><?= $t('Point here') ?></button>
          <button class="btn" name="action" value="410" type="submit">410 Gone</button>
          <button class="btn btn-link link-danger" name="action" value="delete" type="submit" data-confirm="<?= $t('Delete the redirect from {from}? Visitors to that address will get the not found page.', ['from' => $r['from']]) ?>"><?= $t('Delete') ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
<h2><?= $t('Add a redirect') ?></h2>
<form method="post" action="<?= $self ?>?r=redirect" class="stack">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label><?= $t('From path') ?> <input name="from" placeholder="/old-campaign/" required pattern="/.*"></label>
  <label><?= $t('To page') ?> <select name="target"><?php foreach ($targets as $k => $label): ?><option value="<?= $e($k) ?>"><?= $e($label) ?></option><?php endforeach ?></select></label>
  <label><?= $t('or external URL') ?> <input name="url" type="url" placeholder="https://…"></label>
  <button class="btn" name="action" value="page" type="submit"><?= $t('Add redirect') ?></button>
</form>
<p class="muted"><?= $t('Redirect changes go live with the next publish.') ?></p>
