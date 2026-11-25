<!doctype html>
<html lang="<?= $e($uiLang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($title) ?> · Lou</title>
<link rel="stylesheet" href="<?= $e($endpoint) ?>/admin.css">
</head>
<body class="full" data-csrf="<?= $e($csrf) ?>" data-self="<?= $e($self) ?>"<?php if ($t->js()): ?> data-i18n="<?= $e(json_encode($t->js(), JSON_UNESCAPED_UNICODE)) ?>"<?php endif ?>>
<?= $content ?>
<script src="<?= $e($endpoint) ?>/admin.js" defer></script>
</body>
</html>
