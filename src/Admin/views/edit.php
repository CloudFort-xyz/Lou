<?php /** @var Lou\Content\Page $page */ ?>
<div class="editor" data-page="<?= $e($page->id) ?>" data-lang="<?= $e($page->lang) ?>" data-can-slug="<?= $role === 'admin' ? '1' : '' ?>">
  <aside class="panel">
    <p><a href="<?= $self ?>?r=pages">← <?= $t('Pages') ?></a></p>
    <div class="langs">
      <?php foreach ($site->languages() as $l): ?>
        <?php if ($site->page($page->id, $l)): ?>
          <a href="<?= $self ?>?r=edit&amp;page=<?= $e($page->id) ?>&amp;lang=<?= $e($l) ?>"<?= $l === $page->lang ? ' aria-current="page"' : '' ?>><?= $e(strtoupper($l)) ?></a>
        <?php else: ?>
          <span class="muted" title="<?= $t('No translation') ?>"><?= $e(strtoupper($l)) ?></span>
        <?php endif ?>
      <?php endforeach ?>
    </div>
    <details class="fold fold-panel" open><summary><h2><?= $t('Page settings') ?></h2></summary>
    <form id="meta-form" class="stack">
      <label><?= $t('Title') ?> <input name="title" maxlength="200" value="<?= $e($page->title) ?>"></label>
      <label><?= $t('Menu label') ?> <input name="menu_label" maxlength="80" value="<?= $e($page->data['menu_label'] ?? '') ?>"></label>
      <label><?= $t('Description') ?> <small class="muted"><?= $t('(for search engines)') ?></small>
        <textarea name="description" maxlength="500" rows="3"><?= $e($page->description) ?></textarea></label>
      <?php if ($role === 'admin' && !in_array($page->id, ['home', 'not-found'], true)): ?>
        <label><?= $t('URL slug') ?> <input name="slug" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="<?= $e($page->slug) ?>">
          <small class="muted"><?= $t('Changing it creates a redirect from the old URL on publish.') ?></small></label>
      <?php endif ?>
      <button class="btn" type="submit"><?= $t('Save settings') ?></button>
    </form>
    </details>
    <h2><?= $t('Your draft') ?></h2>
    <div class="row undo-row">
      <button class="btn" type="button" id="undo"<?= $history['undo'] === null ? ' disabled' : '' ?> data-label="<?= $e($history['undo'] ?? '') ?>">↶ <?= $t('Undo') ?></button>
      <button class="btn" type="button" id="redo"<?= $history['redo'] === null ? ' disabled' : '' ?> data-label="<?= $e($history['redo'] ?? '') ?>">↷ <?= $t('Redo') ?></button>
    </div>
    <p id="status" class="muted" role="status" aria-live="polite"><?= $drafted ? $t('Your changes are saved in your draft. Nobody else sees them until you submit.') : $t('No changes yet. Edits are saved to your own draft.') ?></p>
    <p><a class="btn btn-primary" href="<?= $self ?>?r=review"><?= $t('Review and submit') ?></a></p>
    <details class="fold fold-panel" id="share-box"><summary><h2><?= $t('Show it to someone') ?></h2></summary>
    <p><button class="btn btn-link" type="button" id="share"><?= $t('Create a shareable preview link') ?></button><br>
      <button class="btn btn-link" type="button" id="share-live"><?= $t('Create a live viewer link') ?></button>
      <small class="muted"><?= $t('The client sees your changes appear as you save them. Valid for a day.') ?></small></p>
    <div id="share-result" class="copy-field" hidden>
      <input class="code" id="share-url" readonly aria-label="<?= $t('Shareable link') ?>" data-select>
      <button class="btn" type="button" data-copy="share-url"><?= $t('Copy') ?></button>
    </div>
    <p id="share-note" class="muted" role="status" aria-live="polite"></p>
    </details>
    <details class="fold fold-panel"><summary><h2><?= $t('How to edit') ?></h2></summary>
    <p class="muted"><?= $t('Click any highlighted text in the preview to edit it, then click outside to save. Click an image to replace it or change its description.') ?></p>
    <p class="muted"><?= $t('Repeating parts (cards, columns, gallery images…) show a small toolbar when you point at them: duplicate, move or delete. Duplicate an image, then click the copy to upload a new file.') ?></p>
    <details class="muted">
      <summary><?= $t('Undo, autosave and keyboard shortcuts') ?></summary>
      <p><?= $t('Every change is saved to your draft right away. Undo and Redo step through your changes on this page (Ctrl+Z, Ctrl+Shift+Z), even after a reload.') ?></p>
      <p><?= $t('While you type, the text is also kept in this browser. If the page closes before it’s saved, you’re asked whether to restore it next time.') ?></p>
      <p><?= $t('Duplicate, move and delete change every language at once, so item 3 stays the same item in each language. Each language copies its own text.') ?></p>
    </details>
    </details>
  </aside>
  <iframe class="preview" id="preview" src="<?= $e($preview) ?>" title="<?= $t('Page preview') ?>"></iframe>
</div>
<dialog id="image-dialog">
  <form id="image-form" method="dialog" class="stack">
    <h2><?= $t('Image') ?></h2>
    <img id="image-current" alt="" class="thumb">
    <label><?= $t('Replace with') ?> <input type="file" name="file" accept="image/jpeg,image/png,image/webp,image/gif"></label>
    <label><?= $t('Description (alt text)') ?> <input name="alt" maxlength="300"></label>
    <p class="muted"><?= $t('Images are resized and converted to WebP automatically.') ?></p>
    <div class="row">
      <button class="btn btn-primary" type="submit" value="save"><?= $t('Save') ?></button>
      <button class="btn btn-link" type="button" id="image-cancel"><?= $t('Cancel') ?></button>
    </div>
  </form>
</dialog>
