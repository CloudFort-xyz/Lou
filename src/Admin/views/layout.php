<?php
/** @var callable $e */
/** @var Lou\Admin\I18n $t */
// Sidebar menu: [route, label, icon, roles, matches(route) or null for exact].
$menu = [
    ['dashboard', $t->plain('Dashboard'), 'home', 'editor'],
    ['pages', $t->plain('Pages'), 'file', 'editor'],
    ['site-details', $t->plain('Site details'), 'layout', 'editor'],
    ['review', $t->plain('Review'), 'check', 'editor', null, $staged],
    ['history', $t->plain('History'), 'clock', 'editor', 'history'],
    '-',
    ['submissions', $t->plain('Submissions'), 'inbox', 'editor', 'submission'],
    ['subscribers', $t->plain('Subscribers'), 'mail', 'editor'],
    '-',
    ['assistants', $t->plain('Assistants'), 'spark', 'admin'],
    ['redirects', $t->plain('Redirects'), 'shuffle', 'admin'],
    ['builds', $t->plain('Builds'), 'layers', 'admin'],
    ['backups', $t->plain('Backups'), 'archive', 'admin'],
    ['updates', $t->plain('Updates'), 'download', 'admin'],
    ['settings', $t->plain('Settings'), 'gear', 'admin'],
    '-',
    ['profile', $t->plain('Profile'), 'user', 'editor'],
];
// Small stroke icons (24×24 viewBox), drawn with currentColor so they follow the menu colours.
$icons = [
    'home'    => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
    'layout'  => '<rect x="3" y="4" width="18" height="16" rx="1"/><path d="M3 8h18M3 16h18"/>',
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
    'user'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
];
$svg = static fn (string $name): string => '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $icons[$name] . '</svg>';
$siteUrl = rtrim((string) ($baseUrl ?? ''), '/') . '/';
?>
<!doctype html>
<html lang="<?= $e($uiLang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($title) ?> · <?= $e($siteName) ?> · Lou</title>
<link rel="stylesheet" href="<?= $e($endpoint) ?>/admin.css">
</head>
<body class="<?= $user ? 'shell' : 'guest' ?>" data-csrf="<?= $e($csrf) ?>" data-self="<?= $e($self) ?>" data-date="<?= $e($datePatterns[0]) ?>" data-time="<?= $e($datePatterns[1]) ?>"<?php if ($t->js()): ?> data-i18n="<?= $e(json_encode($t->js(), JSON_UNESCAPED_UNICODE)) ?>"<?php endif ?>>
<?php if ($user): ?>
<header class="topbar">
  <button class="topbar-toggle" type="button" id="menu-toggle" aria-controls="sidebar" aria-expanded="false"><?= $svg('menu') ?><span class="sr"><?= $t('Menu') ?></span></button>
  <a class="topbar-brand" href="<?= $self ?>?r=dashboard" title="<?= $t('Dashboard') ?>">Lou</a>
  <a class="topbar-site" href="<?= $e($siteUrl) ?>" target="_blank" rel="noopener"><?= $e($siteName) ?> <?= $svg('ext') ?><span class="sr"><?= $t('(opens the live site)') ?></span></a>
  <?php if ($staged || $drafted): ?>
    <a class="topbar-status" href="<?= $self ?>?r=review"><?= $staged ? $t('{n} staged', ['n' => $staged]) : $t('Draft') ?><span class="hide-sm"><?= $staged ? ',&nbsp;' . $t('waiting to publish') : '&nbsp;' . $t('not submitted') ?></span></a>
  <?php endif ?>
  <span class="topbar-spacer"></span>
  <a class="topbar-user" href="<?= $self ?>?r=profile" title="<?= $t('Your profile and preferences') ?>"><span class="hide-sm"><?= $t('Signed in as') ?> </span><strong><?= $e($user) ?></strong> <span class="topbar-role"><?= $role === 'admin' ? $t('admin') : $t('editor') ?></span></a>
  <form method="post" action="<?= $self ?>?r=logout" class="inline">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <button class="topbar-btn" type="submit"><?= $t('Log out') ?></button>
  </form>
</header>
<nav class="sidebar" id="sidebar" aria-label="<?= $t('Admin menu') ?>">
  <ul>
  <?php foreach ($menu as $item):
      if ($item === '-'): ?><li class="sep" role="separator"></li><?php continue; endif;
      [$r, $label, $icon, $need] = $item;
      if ($need === 'admin' && $role !== 'admin') continue;
      $prefix = $item[4] ?? null;
      $current = $prefix !== null ? str_starts_with($route, $prefix) : $route === $r;
      $count = (int) ($item[5] ?? 0); ?>
    <li><a href="<?= $self ?>?r=<?= $r ?>"<?= $current ? ' aria-current="page"' : '' ?>><?= $svg($icon) ?><span><?= $e($label) ?></span><?php if ($count): ?><span class="count" title="<?= $t('{n} staged', ['n' => $count]) ?>"><?= $count ?></span><?php endif ?></a></li>
  <?php endforeach ?>
  </ul>
</nav>
<?php endif ?>
<main class="<?= $user ? 'content' : 'wrap' ?>">
<?php if (!empty($user) && !empty($baseUrlMissing) && $route !== 'settings'): ?>
  <div class="notice notice-error"><strong><?= $t('The site address is not set.') ?></strong> <?= $t('The sitemap, search engine links and the bridge URL need it.') ?>
    <?php if ($role === 'admin'): ?><a href="<?= $self ?>?r=settings"><?= $t('Set it in Settings') ?></a>.<?php else: ?><?= $t('Ask an admin to set it under Settings.') ?><?php endif ?></div>
<?php endif ?>
<?php if (!empty($user) && !empty($needsRebuild) && !in_array($route, ['rebuild', 'updates'], true)): ?>
  <div class="notice notice-warn"><strong><?= $t('Lou was updated to {version}.', ['version' => $louVersion]) ?></strong> <?= $t('Rebuild the site once so it uses the new version.') ?>
    <?php if ($role === 'admin'): ?><form method="post" action="<?= $self ?>?r=rebuild" class="inline"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn" type="submit"><?= $t('Rebuild now') ?></button></form>
    <?php else: ?><?= $t('Ask an admin to rebuild it under Updates.') ?><?php endif ?></div>
<?php endif ?>
<?php if (!empty($flash)): ?><div class="notice notice-ok" role="status"><?= $e($flash) ?></div><?php endif ?>
<?= $content ?>
</main>
<script src="<?= $e($endpoint) ?>/admin.js" defer></script>
</body>
</html>
