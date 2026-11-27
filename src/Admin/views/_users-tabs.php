<?php
/** @var Lou\Admin\I18n $t */
// People and AI assistants: both can change the site, so they share the Users section.
$tab = $route === 'assistants' ? 'assistants' : 'users';
?>
<nav class="tabs" aria-label="<?= $t('Users') ?>">
  <a href="<?= $self ?>?r=users"<?= $tab === 'users' ? ' aria-current="page"' : '' ?>><?= $t('People') ?></a>
  <a href="<?= $self ?>?r=assistants"<?= $tab === 'assistants' ? ' aria-current="page"' : '' ?>><?= $t('AI assistants') ?></a>
</nav>
