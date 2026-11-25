<?php /** @var callable $e */ use Lou\Content\ChangeList; ?>
<div class="card">
  <h1><?= $t('Someone else changed the same thing') ?></h1>
  <p><?= $t('Since you started editing, someone else submitted changes to the same things:') ?></p>
  <ul><?php foreach ($conflicts as $c): ?><li><?= $e(ChangeList::label($c, null, $t->plain(...))) ?> <small class="muted"><code><?= $e($c) ?></code></small></li><?php endforeach ?></ul>
  <p><?= $t('You can keep their version of these (your other changes stay in your draft), or replace theirs with yours.') ?></p>
  <div class="actions">
    <form method="post" action="<?= $self ?>?r=revert" class="inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <?php foreach ($conflicts as $c): ?><input type="hidden" name="files[]" value="<?= $e($c) ?>"><?php endforeach ?>
      <button class="btn btn-primary" type="submit"><?= $t('Keep their version') ?></button>
    </form>
    <form method="post" action="<?= $self ?>?r=submit" class="inline" data-confirm="<?= $t('Replace their changes to these files with yours?') ?>">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <input type="hidden" name="force" value="1">
      <input type="hidden" name="summary" value="<?= $e($summary) ?>">
      <button class="btn btn-danger" type="submit"><?= $t('Replace with mine') ?></button>
    </form>
  </div>
  <p><a href="<?= $self ?>?r=review"><?= $t('Back to review') ?></a></p>
</div>
