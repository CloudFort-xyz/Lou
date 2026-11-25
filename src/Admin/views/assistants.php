<?php
/** @var callable $e */
$base = rtrim((string) ($baseUrl ?? ''), '/');
$mcp = $base . '/_lou/mcp.php';
$tok = $newToken ?? 'lou_YOUR_TOKEN';
// A snippet with a copy button. The text is copied exactly as shown (admin.js reads the element's textContent).
$snippet = static function (string $id, string $text, string $label) use ($e, $t): string {
    // one-liners wrap so the whole command is visible; multi-line JSON keeps its indentation and scrolls
    return '<div class="snippet"><pre class="code' . (str_contains($text, "\n") ? '' : ' wrap') . '" id="' . $id . '">' . $e($text) . '</pre>'
        . '<button class="btn btn-copy" type="button" data-copy="' . $id . '" aria-label="' . $t('Copy {what}', ['what' => $label]) . '">' . $t('Copy') . '</button></div>';
};
?>
<h1><?= $t('Assistants') ?></h1>
<p class="lead"><?= $t('Let an AI assistant such as Claude or ChatGPT change your site\'s layout, design, menus and content. Nothing it does goes live by itself: its changes wait for you on the {!review} page.', ['review' => '<a href="' . $self . '?r=review">' . $t('Review') . '</a>']) ?></p>

<?php if ($newToken): ?>
  <div class="alert alert-ok">
    <strong><?= $t('New token. Copy it now, it is shown only once.') ?></strong> <?= $t('The connection steps below already contain it.') ?>
    <div class="copy-field">
      <input class="code" id="new-token" readonly value="<?= $e($newToken) ?>" aria-label="<?= $t('New token') ?>" data-select>
      <button class="btn btn-primary" type="button" data-copy="new-token"><?= $t('Copy token') ?></button>
    </div>
  </div>
<?php endif ?>

<h2><?= $t('1. Create a token') ?></h2>
<p class="muted"><?= $t('A token is the assistant\'s password to this site. Create one per assistant (or per person), so you can switch them off one by one.') ?></p>
<?php if ($tokens): ?>
<table class="table list">
  <thead><tr><th><?= $t('Name') ?></th><th><?= $t('Created') ?></th><th><?= $t('Last used') ?></th><th><span class="sr"><?= $t('Actions') ?></span></th></tr></thead>
  <tbody>
  <?php foreach ($tokens as $id => $tk): ?>
    <tr><td><?= $e($tk['name']) ?></td><td><?= $time($tk['created'], true) ?></td>
      <td><?= $tk['last_used'] ? $time($tk['last_used']) : '<span class="muted">' . $t('never') . '</span>' ?></td>
      <td class="cell-actions"><form method="post" action="<?= $self ?>?r=token-revoke" data-confirm="<?= $t('Revoke “{name}”? The assistant using it loses access immediately.', ['name' => $tk['name']]) ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="id" value="<?= $e($id) ?>">
        <button class="btn btn-danger" type="submit"><?= $t('Revoke') ?></button></form></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
<form method="post" action="<?= $self ?>?r=token-add" class="actions">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input name="name" placeholder="<?= $t('e.g. Claude – Bence') ?>" required maxlength="60" aria-label="<?= $t('Token name') ?>">
  <button class="btn btn-primary" type="submit"><?= $t('Create token') ?></button>
</form>

<div class="fold-head"><h2><?= $t('2. Connect your assistant') ?></h2><button class="btn btn-link" type="button" data-fold-all="#clients"><?= $t('Expand all') ?></button></div>
<?php if (!$newToken): ?>
  <p class="muted"><?= $t('Replace <code>lou_YOUR_TOKEN</code> below with your token. If you no longer have it, create a new one: the steps on that page will contain it.') ?></p>
<?php endif ?>
<div id="clients">
<details class="fold" open><summary><h3><?= $t('claude.ai, ChatGPT and other chat apps') ?></h3></summary>
<p><?= $t('In the app\'s settings, add a custom connector (no authentication) and paste this address. It contains the token, so keep it as secret as a password.') ?></p>
<?= $snippet('snip-key-url', "$mcp?key=$tok", $t->plain('the connector address')) ?>
</details>
<details class="fold"><summary><h3>Claude Code</h3> <small class="muted"><?= $t('command line') ?></small></summary>
<p><?= $t('Run this once in a terminal:') ?></p>
<?= $snippet('snip-claude-code', "claude mcp add --transport http lou $mcp --header \"Authorization: Bearer $tok\"", $t->plain('the Claude Code command')) ?>
</details>
<details class="fold"><summary><h3>Claude Desktop</h3> <small class="muted"><?= $t('config file, needs Node.js') ?></small></summary>
<p><?= $t('Add this to <code>claude_desktop_config.json</code> (Settings → Developer → Edit config), then restart Claude Desktop:') ?></p>
<?= $snippet('snip-desktop', (string) json_encode(['mcpServers' => ['lou' => ['command' => 'npx', 'args' => ['-y', 'mcp-remote', $mcp, '--header', "Authorization: Bearer $tok"]]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $t->plain('the Claude Desktop config')) ?>
</details>
<details class="fold"><summary><h3><?= $t('Technical details') ?></h3> <small class="muted"><?= $t('bridge URL, plain HTTP, OpenAPI') ?></small></summary>
<p><?= $t('The bridge speaks MCP (streamable HTTP) at this URL. Send the token as <code>Authorization: Bearer …</code>, or as <code>?key=</code> for clients that only take a URL.') ?></p>
<?= $snippet('snip-url', $mcp, $t->plain('the bridge URL')) ?>
<p><?= $t('Without MCP, every tool is also a plain JSON endpoint. OpenAPI description:') ?> <a href="<?= $e($mcp) ?>?openapi" target="_blank" rel="noopener"><code><?= $e($mcp) ?>?openapi</code></a>. <?= $t('Example:') ?></p>
<?= $snippet('snip-curl', "curl -X POST '$mcp?tool=describe_site' -H 'Authorization: Bearer $tok' -H 'Content-Type: application/json' -d '{}'", $t->plain('the curl example')) ?>
<p class="muted"><?= $t('Each assistant works in its own draft. When it submits, its changes go to staging, and they go live when someone publishes them.') ?></p>
</details>
</div>
