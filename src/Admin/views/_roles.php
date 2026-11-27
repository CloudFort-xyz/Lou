<?php
/** @var Lou\Admin\I18n $t */
/** @var callable $roleName */
?>
<details class="fold"><summary><h2><?= $t('What can each role do?') ?></h2></summary>
<dl class="dl">
  <dt><?= $e($roleName('contributor')) ?></dt>
  <dd><?= $t('Edits pages and site details in their own draft and submits the changes for review. Can’t publish, and can’t see form submissions or newsletter subscribers, which hold personal data.') ?></dd>
  <dt><?= $e($roleName('editor')) ?></dt>
  <dd><?= $t('Everything a contributor can do, and also publishes changes (their own and other people’s), and sees form submissions and newsletter subscribers.') ?></dd>
  <dt><?= $e($roleName('admin')) ?></dt>
  <dd><?= $t('Everything, including users, assistants, settings, updates, backups, builds and redirects.') ?></dd>
</dl>
<p class="muted"><?= $t('AI assistants are not users: they connect with a token from the AI assistants tab and work in their own draft like a contributor, but can also change templates and design files. They never publish.') ?></p>
<details class="fold-small"><summary><?= $t('Technical details') ?></summary>
<ul class="muted">
  <li><?= $t('Users are stored in <code>var/users.json</code> (readable only by the web server). Passwords are stored as PHP <code>password_hash</code> hashes, never in plain text.') ?></li>
  <li><?= $t('The role is read from that file on every request, so a change or a removal applies at once, even to someone who is signed in.') ?></li>
  <li><?= $t('Invite and password links contain a random 256-bit token. Only its SHA-256 hash is stored, and the link stops working once it is used.') ?></li>
  <li><?= $t('Failed logins are limited to 10 an hour per IP address and 20 an hour per account. Accounts are never locked for longer than that.') ?></li>
  <li><?= $t('Every change on this page is written to the audit log. From the command line: <code>php lou user</code> with list, add, invite or remove.') ?></li>
</ul>
</details>
</details>
