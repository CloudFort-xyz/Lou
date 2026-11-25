<h1>Pages</h1>
<?php foreach ($warnings as $w): ?><p class="alert"><?= $e($w) ?></p><?php endforeach ?>
<table class="table">
  <thead><tr><th>Page</th><?php foreach ($site->languages() as $l): ?><th><?= $e(strtoupper($l)) ?></th><?php endforeach ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><code><?= $e($r['id']) ?></code></td>
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
<p class="muted">New pages, layout and menu changes are made through the LLM bridge or by a developer.</p>
