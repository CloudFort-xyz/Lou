<?php /** @var Lou\View $v */ ?>
<!doctype html>
<html lang="<?= $v->lang() ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $v->title() ?></title>
<meta name="description" content="<?= $v->description() ?>">
<?= $v->seoLinks() ?>
<link rel="stylesheet" href="<?= $v->asset('css/site.css') ?>">
</head>
<body>
<a class="skip" href="#main"><?= $v->t('skip') ?></a>
<?= $v->partial('header') ?>
<main id="main">
<?= $v->content() ?>
</main>
<?= $v->partial('footer') ?>
<?= $v->scripts() ?>
</body>
</html>
