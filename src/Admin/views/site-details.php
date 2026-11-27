<?php
/** @var callable $e */
/** @var Lou\Admin\I18n $t */
// Friendly names for the usual keys; anything else shows its key in plain words.
$names = [
    'company'       => $t->plain('Company name'),
    'tagline'       => $t->plain('Tagline'),
    'address'       => $t->plain('Address'),
    'email'         => $t->plain('Email address'),
    'careers_email' => $t->plain('Careers email address'),
    'phone'         => $t->plain('Phone number'),
    'phone_link'    => $t->plain('Phone number for dialling (digits only)'),
    'footer'        => $t->plain('Footer text'),
];
$label = static fn (string $key): string => $names[$key] ?? ucfirst(str_replace(['_', '.'], [' ', ' › '], $key));
$technical = static fn (string $key): bool => (bool) preg_match('/(_url|_link|^social\.)/', $key);
$field = static function (string $group, string $key, string $value) use ($e, $label, $names): string {
    $name = $e($group) . '[' . $e($key) . ']';
    $input = mb_strlen($value) > 80
        ? '<textarea name="' . $name . '" rows="3">' . $e($value) . '</textarea>'
        : '<input name="' . $name . '" value="' . $e($value) . '">';
    // keys without a friendly name show the key too, so people (and assistants) can tell which text it is
    $hint = isset($names[$key]) ? '' : ' <small class="muted"><code>' . $e($key) . '</code></small>';
    return '<label>' . $e($label($key)) . $hint . $input . '</label>';
};
$main = array_filter($details, fn ($k) => !$technical($k), ARRAY_FILTER_USE_KEY);
$links = array_diff_key($details, $main);
?>
<div class="page-head"><h1><?= $t('Site details') ?></h1></div>

<div class="alert"><strong><?= $t('These texts appear on every page.') ?></strong> <?= $t('They are shared parts of the site, such as the header, the footer and the contact details. A change here shows up on all pages at once after it is published.') ?></div>

<?php if (count($languages) > 1): ?>
<nav class="lang-switch" aria-label="<?= $t('Language') ?>">
  <?php foreach ($languages as $l): ?>
    <a href="<?= $self ?>?r=site-details&amp;lang=<?= $e($l) ?>"<?= $l === $lang ? ' aria-current="page"' : '' ?>><?= $e(strtoupper($l)) ?></a>
  <?php endforeach ?>
</nav>
<?php endif ?>

<form method="post" action="<?= $self ?>?r=site-details" class="widget settings-section stack">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="lang" value="<?= $e($lang) ?>">
  <h2><?= $t('Contact details and footer') ?></h2>
  <?php foreach ($main as $k => $v): ?><?= $field('details', $k, $v) ?><?php endforeach ?>
  <?php if ($links): ?>
  <details class="fold fold-small"><summary><?= $t('Links and technical values') ?></summary>
    <div class="stack">
    <?php foreach ($links as $k => $v): ?><?= $field('details', $k, $v) ?><?php endforeach ?>
    </div>
  </details>
  <?php endif ?>
  <?php if ($strings): ?>
  <details class="fold fold-small"><summary><?= $t('Labels and buttons') ?> <small class="muted"><?= $t('menu, buttons, form labels and messages') ?></small></summary>
    <div class="stack">
    <?php foreach ($strings as $k => $v): ?><?= $field('strings', $k, $v) ?><?php endforeach ?>
    </div>
  </details>
  <?php endif ?>
  <p class="muted"><?= $t('Saving puts the changes in your draft. Nothing changes on the live site until they are published on the Review page.') ?></p>
  <p><button class="btn btn-primary" type="submit"><?= $t('Save site details') ?></button></p>
</form>
