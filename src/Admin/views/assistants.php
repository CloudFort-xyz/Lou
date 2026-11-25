<?php
/** @var callable $e */
$base = rtrim((string) ($baseUrl ?? ''), '/');
$mcp = $base . '/_lou/mcp.php';
$tok = $newToken ?? 'lou_YOUR_TOKEN';
// A snippet with a copy button. The text is copied exactly as shown (admin.js reads the element's textContent).
$snippet = static function (string $id, string $text, string $label) use ($e): string {
    // one-liners wrap so the whole command is visible; multi-line JSON keeps its indentation and scrolls
    return '<div class="snippet"><pre class="code' . (str_contains($text, "\n") ? '' : ' wrap') . '" id="' . $id . '">' . $e($text) . '</pre>'
        . '<button class="btn btn-copy" type="button" data-copy="' . $id . '" aria-label="Copy ' . $e($label) . '">Copy</button></div>';
};
?>
<h1>Assistants</h1>
<p class="muted">Assistants (Claude, ChatGPT, or anything that speaks MCP or plain HTTP) can change the layout, design, menu and content through the bridge. Each assistant works in its own draft. When it submits, its changes land on staging and go live once someone publishes them on the <a href="<?= $self ?>?r=review">Review</a> page.</p>

<?php if ($newToken): ?>
  <div class="alert alert-ok">
    <strong>New token. Copy it now, it is shown only once.</strong> The snippets below already contain it.
    <div class="copy-field">
      <input class="code" id="new-token" readonly value="<?= $e($newToken) ?>" aria-label="New token" data-select>
      <button class="btn btn-primary" type="button" data-copy="new-token">Copy token</button>
    </div>
  </div>
<?php endif ?>

<h2>Tokens</h2>
<?php if (!$tokens): ?><p class="muted">No tokens yet. Create one for each assistant (or each person using one), so you can revoke them one by one.</p><?php else: ?>
<table class="table list">
  <thead><tr><th>Name</th><th>Created</th><th>Last used</th><th><span class="sr">Actions</span></th></tr></thead>
  <tbody>
  <?php foreach ($tokens as $id => $t): ?>
    <tr><td><?= $e($t['name']) ?></td><td><?= $time($t['created'], true) ?></td>
      <td><?= $t['last_used'] ? $time($t['last_used']) : '<span class="muted">never</span>' ?></td>
      <td class="cell-actions"><form method="post" action="<?= $self ?>?r=token-revoke" data-confirm="Revoke “<?= $e($t['name']) ?>”? The assistant using it loses access immediately.">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="id" value="<?= $e($id) ?>">
        <button class="btn btn-danger" type="submit">Revoke</button></form></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
<form method="post" action="<?= $self ?>?r=token-add" class="actions">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input name="name" placeholder="e.g. Claude – Bence" required maxlength="60" aria-label="Token name">
  <button class="btn btn-primary" type="submit">Create token</button>
</form>

<h2>Connecting</h2>
<?php if (!$newToken): ?>
  <p class="notice">The snippets use <code>lou_YOUR_TOKEN</code> as a placeholder: replace it with your token. Tokens are shown only once, so create a new one if you don't have it, and the snippets on that page will contain it.</p>
<?php endif ?>
<p>Bridge URL:</p>
<?= $snippet('snip-url', $mcp, 'the bridge URL') ?>
<h3>Claude Code</h3>
<?= $snippet('snip-claude-code', "claude mcp add --transport http lou $mcp --header \"Authorization: Bearer $tok\"", 'the Claude Code command') ?>
<h3>Claude Desktop (config file, needs Node.js)</h3>
<?= $snippet('snip-desktop', (string) json_encode(['mcpServers' => ['lou' => ['command' => 'npx', 'args' => ['-y', 'mcp-remote', $mcp, '--header', "Authorization: Bearer $tok"]]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'the Claude Desktop config') ?>
<h3>claude.ai, ChatGPT and other apps that only take a URL</h3>
<p>Add a custom connector without authentication and use this URL. The token is part of the URL, so treat the whole URL as a password.</p>
<?= $snippet('snip-key-url', "$mcp?key=$tok", 'the connector URL') ?>
<h3>Plain HTTP (no MCP)</h3>
<p>OpenAPI description: <a href="<?= $e($mcp) ?>?openapi" target="_blank" rel="noopener"><code><?= $e($mcp) ?>?openapi</code></a>. Example:</p>
<?= $snippet('snip-curl', "curl -X POST '$mcp?tool=describe_site' -H 'Authorization: Bearer $tok' -H 'Content-Type: application/json' -d '{}'", 'the curl example') ?>
