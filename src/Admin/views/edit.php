<?php /** @var Lou\Content\Page $page */ ?>
<div class="editor" data-page="<?= $e($page->id) ?>" data-lang="<?= $e($page->lang) ?>" data-can-slug="<?= $role === 'admin' ? '1' : '' ?>">
  <aside class="panel">
    <p><a href="<?= $self ?>">← Pages</a></p>
    <div class="langs">
      <?php foreach ($site->languages() as $l): ?>
        <?php if ($site->page($page->id, $l)): ?>
          <a href="<?= $self ?>?r=edit&amp;page=<?= $e($page->id) ?>&amp;lang=<?= $e($l) ?>"<?= $l === $page->lang ? ' aria-current="page"' : '' ?>><?= $e(strtoupper($l)) ?></a>
        <?php else: ?>
          <span class="muted" title="No translation"><?= $e(strtoupper($l)) ?></span>
        <?php endif ?>
      <?php endforeach ?>
    </div>
    <h2>Page settings</h2>
    <form id="meta-form" class="stack">
      <label>Title <input name="title" maxlength="200" value="<?= $e($page->title) ?>"></label>
      <label>Menu label <input name="menu_label" maxlength="80" value="<?= $e($page->data['menu_label'] ?? '') ?>"></label>
      <label>Description <small class="muted">(for search engines)</small>
        <textarea name="description" maxlength="500" rows="3"><?= $e($page->description) ?></textarea></label>
      <?php if ($role === 'admin' && !in_array($page->id, ['home', 'not-found'], true)): ?>
        <label>URL slug <input name="slug" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="<?= $e($page->slug) ?>">
          <small class="muted">Changing it creates a redirect from the old URL on publish.</small></label>
      <?php endif ?>
      <button class="btn" type="submit">Save settings</button>
    </form>
    <h2>Your draft</h2>
    <p id="status" class="muted" role="status" aria-live="polite"><?= $drafted ? 'Your changes are saved in your draft. Nobody else sees them until you submit.' : 'No changes yet. Edits are saved to your own draft.' ?></p>
    <p><a class="btn btn-primary" href="<?= $self ?>?r=review">Review and submit</a></p>
    <p><button class="btn btn-link" type="button" id="share">Create a shareable preview link</button><br>
      <button class="btn btn-link" type="button" id="share-live">Create a live viewer link</button>
      <small class="muted">The client sees your changes appear as you save them. Valid for a day.</small></p>
    <p id="share-result" class="muted"></p>
    <h2>How to edit</h2>
    <p class="muted">Click any highlighted text in the preview to edit it, then click outside to save. Click an image to replace it or change its description.</p>
  </aside>
  <iframe class="preview" id="preview" src="<?= $e($preview) ?>" title="Page preview"></iframe>
</div>
<dialog id="image-dialog">
  <form id="image-form" method="dialog" class="stack">
    <h2>Image</h2>
    <img id="image-current" alt="" class="thumb">
    <label>Replace with <input type="file" name="file" accept="image/jpeg,image/png,image/webp,image/gif"></label>
    <label>Description (alt text) <input name="alt" maxlength="300"></label>
    <p class="muted">Images are resized and converted to WebP automatically.</p>
    <div class="row">
      <button class="btn btn-primary" type="submit" value="save">Save</button>
      <button class="btn btn-link" type="button" id="image-cancel">Cancel</button>
    </div>
  </form>
</dialog>
