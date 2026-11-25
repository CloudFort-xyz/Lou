<?php
/** @var callable $e */
/** @var Lou\Admin\I18n $t */
/** @var Lou\Admin\Prefs $prefs */
$sel = static fn (string $a, string $b): string => $a === $b ? ' selected' : '';
// Time zones grouped by region (Europe/Budapest → Europe), for a shorter list to scroll.
$groups = [];
foreach ($timezones as $tz) {
    $groups[str_contains($tz, '/') ? strstr($tz, '/', true) : 'UTC'][] = $tz;
}
$sampleAt = (new DateTimeImmutable('@' . $sample))->setTimezone(new DateTimeZone($prefs->timezone ?: 'UTC'));
?>
<div class="page-head"><h1><?= $t('Profile') ?></h1><span class="muted"><?= $e($user) ?> · <?= $role === 'admin' ? $t('admin') : $t('editor') ?></span></div>

<section class="widget settings-section">
  <h2><?= $t('Preferences') ?></h2>
  <p class="muted"><?= $t('These apply only to you, on every device you sign in on.') ?></p>
  <form method="post" action="<?= $self ?>?r=profile" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div class="cols">
      <label><?= $t('Admin language') ?>
        <select name="lang">
          <option value=""<?= $sel($prefs->lang, '') ?>><?= $t('Site default ({language})', ['language' => $siteLanguage !== '' ? $languages[$siteLanguage] : $t->plain('your browser\'s language')]) ?></option>
          <?php foreach ($languages as $code => $name): ?><option value="<?= $e($code) ?>" lang="<?= $e($code) ?>"<?= $sel($prefs->lang, $code) ?>><?= $e($name) ?></option><?php endforeach ?>
        </select>
      </label>
      <label><?= $t('Time zone') ?>
        <select name="timezone">
          <option value=""<?= $sel($prefs->timezone, '') ?>><?= $t('This browser\'s time zone') ?></option>
          <?php foreach ($groups as $region => $list): ?>
            <optgroup label="<?= $e($region) ?>"><?php foreach ($list as $tz): ?><option value="<?= $e($tz) ?>"<?= $sel($prefs->timezone, $tz) ?>><?= $e(str_replace('_', ' ', $tz)) ?></option><?php endforeach ?></optgroup>
          <?php endforeach ?>
        </select>
      </label>
    </div>
    <p class="muted"><?= $t('With the browser\'s time zone, times follow whichever computer or phone you are using. Pick a zone to always see the same one, for example when you travel.') ?></p>
    <div class="cols">
      <label><?= $t('Date and time format') ?>
        <select name="date_format">
          <option value=""<?= $sel($prefs->dateFormat, '') ?>><?= $t('The language\'s usual format') ?></option>
          <?php foreach ($formats as $key => [$d, $h]): ?><option value="<?= $e($key) ?>"<?= $sel($prefs->dateFormat, $key) ?>><?= $e($sampleAt->format("$d $h")) ?></option><?php endforeach ?>
        </select>
      </label>
      <label><?= $t('Editor opens pages in') ?>
        <select name="edit_lang">
          <option value=""<?= $sel($prefs->editLang, '') ?>><?= $t('The site\'s default language ({language})', ['language' => strtoupper($defaultContentLanguage)]) ?></option>
          <?php foreach ($contentLanguages as $l): ?><option value="<?= $e($l) ?>"<?= $sel($prefs->editLang, $l) ?>><?= $e(strtoupper($l)) ?></option><?php endforeach ?>
        </select>
      </label>
    </div>
    <label><?= $t('After logging in, open') ?>
      <select name="start_page">
        <option value=""<?= $sel($prefs->startPage, '') ?>><?= $t('Dashboard') ?></option>
        <option value="pages"<?= $sel($prefs->startPage, 'pages') ?>><?= $t('Pages') ?></option>
        <option value="review"<?= $sel($prefs->startPage, 'review') ?>><?= $t('Review') ?></option>
      </select>
    </label>
    <p><button class="btn btn-primary" type="submit"><?= $t('Save preferences') ?></button></p>
  </form>
</section>

<section class="widget settings-section" id="password">
  <h2><?= $t('Change password') ?></h2>
  <form method="post" action="<?= $self ?>?r=password#password" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="text" name="user" value="<?= $e($user) ?>" autocomplete="username" hidden>
    <label><?= $t('Current password') ?> <input type="password" name="current_password" autocomplete="current-password" required></label>
    <div class="cols">
      <label><?= $t('New password') ?> <input type="password" name="new_password" autocomplete="new-password" minlength="10" required></label>
      <label><?= $t('New password again') ?> <input type="password" name="new_password2" autocomplete="new-password" minlength="10" required></label>
    </div>
    <p class="muted"><?= $t('At least 10 characters. A few random words are easy to remember and hard to guess.') ?></p>
    <p><button class="btn" type="submit"><?= $t('Change password') ?></button></p>
  </form>
</section>
