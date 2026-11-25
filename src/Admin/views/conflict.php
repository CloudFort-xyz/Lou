<?php /** @var callable $e */ ?>
<div class="card">
  <h1><?= $t('Someone else changed the same thing') ?></h1>
  <p><?= $t('Since you started editing, these files were changed on staging by someone else:') ?></p>
  <ul><?php foreach ($conflicts as $c): ?><li><code><?= $e($c) ?></code></li><?php endforeach ?></ul>
  <p><?= $t('You can take their version of these files (your other changes stay in your draft), or replace their version with yours.') ?></p>
  <div class="actions">
    <form method="post" action="<?= $self ?>?r=revert" class="inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <?php foreach ($conflicts as $c): ?><input type="hidden" name="files[]" value="<?= $e($c) ?>"><?php endforeach ?>
      <button class="btn btn-primary" type="submit"><?= $t('Use their version') ?></button>
    </form>
    <form method="post" action="<?= $self ?>?r=submit" class="inline" data-confirm="<?= $t('Replace their changes to these files with yours?') ?>">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="force" value="1">
      <input type="hidden" name="summary" value="<?= $e($summary) ?>">
      <button class="btn btn-danger" type="submit"><?= $t('Submit mine anyway') ?></button>
    </form>
  </div>
  <p><a href="<?= $self ?>?r=review"><?= $t('Back to review') ?></a></p>
</div>
