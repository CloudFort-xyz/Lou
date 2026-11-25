<?php
/** @var callable $e */
// Sidebar menu: [route, label, icon, roles, matches(route) or null for exact].
$menu = [
    ['dashboard', 'Dashboard', 'home', 'editor'],
    ['pages', 'Pages', 'file', 'editor'],
    ['review', 'Review', 'check', 'editor', null, $staged],
    ['history', 'History', 'clock', 'editor', 'history'],
    '-',
    ['submissions', 'Submissions', 'inbox', 'editor', 'submission'],
    ['subscribers', 'Subscribers', 'mail', 'editor'],
    '-',
    ['assistants', 'Assistants', 'spark', 'admin'],
    ['redirects', 'Redirects', 'shuffle', 'admin'],
    ['builds', 'Builds', 'layers', 'admin'],
    ['backups', 'Backups', 'archive', 'admin'],
    ['updates', 'Updates', 'download', 'admin'],
    ['settings', 'Settings', 'gear', 'admin'],
];
// Small stroke icons (24×24 viewBox), drawn with currentColor so they follow the menu colours.
$icons = [
    'home'    => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
    'file'    => '<path d="M14 3H6v18h12V7z"/><path d="M14 3v4h4"/><path d="M9 12h6M9 16h6"/>',
    'check'   => '<path d="M4 12l5 5L20 6"/>',
    'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'inbox'   => '<path d="M3 13l3-8h12l3 8v6H3z"/><path d="M3 13h5l1 3h6l1-3h5"/>',
    'mail'    => '<rect x="3" y="5" width="18" height="14" rx="1"/><path d="M3 7l9 6 9-6"/>',
    'spark'   => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5L18 18M6 18l2.5-2.5M15.5 8.5L18 6"/>',
    'shuffle' => '<path d="M4 8h13l-3-3M20 16H7l3 3"/>',
    'layers'  => '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
    'archive' => '<rect x="3" y="4" width="18" height="4"/><path d="M5 8v12h14V8"/><path d="M10 12h4"/>',
    'gear'    => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
    'download' => '<path d="M12 4v11M7 10l5 5 5-5"/><path d="M4 19h16"/>',
    'ext'     => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v6H4V6h6"/>',
    'menu'    => '<path d="M4 6h16M4 12h16M4 18h16"/>',
];
$svg = static fn (string $name): string => '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $icons[$name] . '</svg>';
$siteUrl = rtrim((string) ($baseUrl ?? ''), '/') . '/';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($title) ?> · <?= $e($siteName) ?> · Lou</title>
<link rel="stylesheet" href="/_lou/admin.css">
</head>
<body class="<?= $user ? 'shell' : 'guest' ?>" data-csrf="<?= $e($csrf) ?>" data-self="<?= $e($self) ?>">
<?php if ($user): ?>
<header class="topbar">
  <button class="topbar-toggle" type="button" id="menu-toggle" aria-controls="sidebar" aria-expanded="false"><?= $svg('menu') ?><span class="sr">Menu</span></button>
  <a class="topbar-brand" href="<?= $self ?>?r=dashboard" title="Dashboard">Lou</a>
  <a class="topbar-site" href="<?= $e($siteUrl) ?>" target="_blank" rel="noopener"><?= $e($siteName) ?> <?= $svg('ext') ?><span class="sr">(opens the live site)</span></a>
  <?php if ($staged || $drafted): ?>
    <a class="topbar-status" href="<?= $self ?>?r=review"><?= $staged ? (int) $staged . ' staged' : 'Draft' ?><span class="hide-sm"><?= $staged ? ', waiting to publish' : ' not submitted' ?></span></a>
  <?php endif ?>
  <span class="topbar-spacer"></span>
  <span class="topbar-user"><span class="hide-sm">Signed in as </span><strong><?= $e($user) ?></strong> <span class="topbar-role"><?= $e($role) ?></span></span>
  <form method="post" action="<?= $self ?>?r=logout" class="inline">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <button class="topbar-btn" type="submit">Log out</button>
  </form>
</header>
<nav class="sidebar" id="sidebar" aria-label="Admin menu">
  <ul>
  <?php foreach ($menu as $item):
      if ($item === '-'): ?><li class="sep" role="separator"></li><?php continue; endif;
      [$r, $label, $icon, $need] = $item;
      if ($need === 'admin' && $role !== 'admin') continue;
      $prefix = $item[4] ?? null;
      $current = $prefix !== null ? str_starts_with($route, $prefix) : $route === $r;
      $count = (int) ($item[5] ?? 0); ?>
    <li><a href="<?= $self ?>?r=<?= $r ?>"<?= $current ? ' aria-current="page"' : '' ?>><?= $svg($icon) ?><span><?= $e($label) ?></span><?php if ($count): ?><span class="count" title="<?= $count ?> staged"><?= $count ?></span><?php endif ?></a></li>
  <?php endforeach ?>
  </ul>
</nav>
<?php endif ?>
<main class="<?= $user ? 'content' : 'wrap' ?>">
<?php if (!empty($user) && !empty($baseUrlMissing) && $route !== 'settings'): ?>
  <div class="notice notice-error"><strong>The site address is not set.</strong> The sitemap, search engine links and the bridge URL need it.
    <?php if ($role === 'admin'): ?><a href="<?= $self ?>?r=settings">Set it in Settings</a>.<?php else: ?>Ask an admin to set it under Settings.<?php endif ?></div>
<?php endif ?>
<?php if (!empty($user) && !empty($needsRebuild) && !in_array($route, ['rebuild', 'updates'], true)): ?>
  <div class="notice notice-warn"><strong>Lou was updated to <?= $e($louVersion) ?>.</strong> Rebuild the site once so it uses the new version.
    <?php if ($role === 'admin'): ?><form method="post" action="<?= $self ?>?r=rebuild" class="inline"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn" type="submit">Rebuild now</button></form>
    <?php else: ?>Ask an admin to rebuild it under Updates.<?php endif ?></div>
<?php endif ?>
<?= $content ?>
</main>
<script src="/_lou/admin.js" defer></script>
</body>
</html>
