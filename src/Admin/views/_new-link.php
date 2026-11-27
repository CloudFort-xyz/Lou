<?php
/** @var callable $e */
/** @var Lou\Admin\I18n $t */
// A fresh invite or password link, shown once after it is made.
if (!empty($newLink)): ?>
  <div class="alert alert-ok" role="status">
    <strong><?= $newLink['emailed'] !== null
        ? ($newLink['purpose'] === 'invite' ? $t('Invite sent to {email}.', ['email' => $newLink['emailed']]) : $t('Password link sent to {email}.', ['email' => $newLink['emailed']]))
        : $t('Send this link to {name} yourself.', ['name' => $newLink['name']]) ?></strong>
    <?= $newLink['emailed'] !== null ? $t('If it doesn’t arrive, you can send this link another way. It is shown only once.')
        : $t('No email was sent: the user has no email address, or email isn’t set up under Settings. The link is shown only once.') ?>
    <div class="copy-field">
      <input class="code" id="new-link" readonly value="<?= $e($newLink['url']) ?>" aria-label="<?= $t('Link') ?>" data-select>
      <button class="btn btn-primary" type="button" data-copy="new-link"><?= $t('Copy link') ?></button>
    </div>
  </div>
<?php endif;
