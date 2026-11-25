<?php /** @var callable $e */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($title) ?> · <?= $e($siteName) ?> · Lou</title>
<link rel="stylesheet" href="/_lou/admin.css">
</head>
<body data-csrf="<?= $e($csrf) ?>" data-self="<?= $e($self) ?>">
<?php if ($user): ?>
<header class="bar">
  <strong class="brand"><?= $e($siteName) ?></strong>
  <nav>
    <a href="<?= $self ?>?r=pages"<?= $route === 'pages' ? ' aria-current="page"' : '' ?>>Pages</a>
    <a href="<?= $self ?>?r=submissions"<?= str_starts_with($route, 'submission') ? ' aria-current="page"' : '' ?>>Submissions</a>
    <a href="<?= $self ?>?r=subscribers"<?= $route === 'subscribers' ? ' aria-current="page"' : '' ?>>Subscribers</a>
    <a href="<?= $self ?>?r=review"<?= $route === 'review' ? ' aria-current="page"' : '' ?>>Review<?php if ($staged): ?> <span class="badge"><?= (int) $staged ?> staged</span><?php endif ?><?php if ($drafted): ?> <span class="badge badge-warn">draft</span><?php endif ?></a>
    <a href="<?= $self ?>?r=history"<?= str_starts_with($route, 'history') ? ' aria-current="page"' : '' ?>>History</a>
    <?php if ($role === 'admin'): ?>
      <a href="<?= $self ?>?r=assistants"<?= $route === 'assistants' ? ' aria-current="page"' : '' ?>>Assistants</a>
      <a href="<?= $self ?>?r=redirects"<?= $route === 'redirects' ? ' aria-current="page"' : '' ?>>Redirects</a>
      <a href="<?= $self ?>?r=builds"<?= $route === 'builds' ? ' aria-current="page"' : '' ?>>Builds</a>
      <a href="<?= $self ?>?r=backups"<?= $route === 'backups' ? ' aria-current="page"' : '' ?>>Backups</a>
    <?php endif ?>
  </nav>
  <form method="post" action="<?= $self ?>?r=logout" class="inline">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <button class="btn btn-link" type="submit">Log out <?= $e($user) ?></button>
  </form>
</header>
<?php endif ?>
<main class="wrap">
<?= $content ?>
</main>
<script src="/_lou/admin.js" defer></script>
</body>
</html>
