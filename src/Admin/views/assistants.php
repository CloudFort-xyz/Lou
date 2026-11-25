<?php
/** @var callable $e */
$base = rtrim((string) ($baseUrl ?? ''), '/');
$mcp = $base . '/_lou/mcp.php';
$tok = $newToken ?? 'lou_YOUR_TOKEN';
?>
<h1>Assistants</h1>
<p class="muted">Assistants (Claude, ChatGPT, or anything that speaks MCP or plain HTTP) can change the layout, design, menu and content through the bridge. Each assistant works in its own draft. When it submits, its changes land on staging and go live once someone publishes them on the <a href="<?= $self ?>?r=review">Review</a> page.</p>

<?php if ($newToken): ?>
  <div class="alert alert-ok">
    <strong>New token. Copy it now, it is shown only once:</strong>
    <p><input class="code" readonly value="<?= $e($newToken) ?>" size="60" data-select></p>
  </div>
<?php endif ?>

<h2>Tokens</h2>
<?php if (!$tokens): ?><p class="muted">No tokens yet.</p><?php else: ?>
<table class="table">
  <thead><tr><th>Name</th><th>Created</th><th>Last used</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($tokens as $id => $t): ?>
    <tr><td><?= $e($t['name']) ?></td><td><?= $e(substr($t['created'], 0, 10)) ?></td>
      <td><?= $t['last_used'] ? $e(substr(str_replace('T', ' ', $t['last_used']), 0, 16)) . ' UTC' : '<span class="muted">never</span>' ?></td>
      <td><form method="post" action="<?= $self ?>?r=token-revoke" data-confirm="Revoke this token? The assistant using it loses access immediately.">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="id" value="<?= $e($id) ?>">
        <button class="btn btn-danger" type="submit">Revoke</button></form></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
<form method="post" action="<?= $self ?>?r=token-add" class="inline">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input name="name" placeholder="e.g. Claude – Bence" required maxlength="60">
  <button class="btn btn-primary" type="submit">Create token</button>
</form>

<h2>Connecting</h2>
<p>Bridge URL: <code><?= $e($mcp) ?></code></p>
<h3>Claude Code</h3>
<pre class="code"><?= $e("claude mcp add --transport http lou $mcp --header \"Authorization: Bearer $tok\"") ?></pre>
<h3>Claude Desktop (config file, needs Node.js)</h3>
<pre class="code"><?= $e(json_encode(['mcpServers' => ['lou' => ['command' => 'npx', 'args' => ['-y', 'mcp-remote', $mcp, '--header', "Authorization: Bearer $tok"]]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
<h3>claude.ai, ChatGPT and other apps that only take a URL</h3>
<p>Add a custom connector without authentication and use this URL. The token is part of the URL, so treat the whole URL as a password.</p>
<pre class="code"><?= $e("$mcp?key=$tok") ?></pre>
<h3>Plain HTTP (no MCP)</h3>
<p>OpenAPI description: <a href="<?= $e($mcp) ?>?openapi"><code><?= $e($mcp) ?>?openapi</code></a>. Example:</p>
<pre class="code"><?= $e("curl -X POST '$mcp?tool=describe_site' -H 'Authorization: Bearer $tok' -H 'Content-Type: application/json' -d '{}'") ?></pre>
