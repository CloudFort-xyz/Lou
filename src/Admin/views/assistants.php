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
<p class="lead">Let an AI assistant such as Claude or ChatGPT change your site's layout, design, menus and content. Nothing it does goes live by itself: its changes wait for you on the <a href="<?= $self ?>?r=review">Review</a> page.</p>

<?php if ($newToken): ?>
  <div class="alert alert-ok">
    <strong>New token. Copy it now, it is shown only once.</strong> The connection steps below already contain it.
    <div class="copy-field">
      <input class="code" id="new-token" readonly value="<?= $e($newToken) ?>" aria-label="New token" data-select>
      <button class="btn btn-primary" type="button" data-copy="new-token">Copy token</button>
    </div>
  </div>
<?php endif ?>

<h2>1. Create a token</h2>
<p class="muted">A token is the assistant's password to this site. Create one per assistant (or per person), so you can switch them off one by one.</p>
<?php if ($tokens): ?>
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

<div class="fold-head"><h2>2. Connect your assistant</h2><button class="btn btn-link" type="button" data-fold-all="#clients">Expand all</button></div>
<?php if (!$newToken): ?>
  <p class="muted">Replace <code>lou_YOUR_TOKEN</code> below with your token. If you no longer have it, create a new one: the steps on that page will contain it.</p>
<?php endif ?>
<div id="clients">
<details class="fold" open><summary><h3>claude.ai, ChatGPT and other chat apps</h3></summary>
<p>In the app's settings, add a custom connector (no authentication) and paste this address. It contains the token, so keep it as secret as a password.</p>
<?= $snippet('snip-key-url', "$mcp?key=$tok", 'the connector address') ?>
</details>
<details class="fold"><summary><h3>Claude Code</h3> <small class="muted">command line</small></summary>
<p>Run this once in a terminal:</p>
<?= $snippet('snip-claude-code', "claude mcp add --transport http lou $mcp --header \"Authorization: Bearer $tok\"", 'the Claude Code command') ?>
</details>
<details class="fold"><summary><h3>Claude Desktop</h3> <small class="muted">config file, needs Node.js</small></summary>
<p>Add this to <code>claude_desktop_config.json</code> (Settings → Developer → Edit config), then restart Claude Desktop:</p>
<?= $snippet('snip-desktop', (string) json_encode(['mcpServers' => ['lou' => ['command' => 'npx', 'args' => ['-y', 'mcp-remote', $mcp, '--header', "Authorization: Bearer $tok"]]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'the Claude Desktop config') ?>
</details>
<details class="fold"><summary><h3>Microsoft Copilot</h3> <small class="muted">Microsoft 365, through Copilot Studio</small></summary>
<p>Copilot connects to the site through an agent you make in <a href="https://copilotstudio.microsoft.com" target="_blank" rel="noopener">Copilot Studio</a>. This needs a work account with Copilot Studio (a licence or a trial). The free Copilot app can't connect to sites like this one.</p>
<ol>
  <li>Create an agent (or open one), then go to <strong>Tools → Add a tool → New tool → Model Context Protocol</strong>.</li>
  <li>Give it a name (e.g. the site's name) and paste this as the <strong>server URL</strong>:</li>
</ol>
<?= $snippet('snip-copilot-url', $mcp, 'the server URL') ?>
<ol start="3">
  <li>Under <strong>Authentication</strong> choose <strong>API key</strong>, type <strong>Query</strong>, parameter name <code>key</code>. Create it, then create the connection and paste your token when asked:</li>
</ol>
<?= $snippet('snip-copilot-token', $tok, 'the token') ?>
<ol start="4">
  <li>Optionally, add this to the agent's <strong>instructions</strong>:</li>
</ol>
<?= $snippet('snip-copilot-instructions', 'You edit a website through the Lou tools. Always call describe_site first and follow its rules. Build a preview, share the preview link, and only submit for review when the user is happy with it.', 'the instructions') ?>
<ol start="5">
  <li>Publish the agent to Microsoft 365 Copilot or Teams, and use it from there.</li>
</ol>
</details>
<details class="fold"><summary><h3>GitHub Copilot in VS Code</h3> <small class="muted">config file</small></summary>
<p>Save this as <code>.vscode/mcp.json</code> (or add it to your user MCP settings), start the server and use Copilot Chat in <strong>Agent</strong> mode. VS Code asks for the token once and keeps it out of the file.</p>
<?= $snippet('snip-vscode', (string) json_encode(['inputs' => [['type' => 'promptString', 'id' => 'lou-token', 'description' => 'Lou token', 'password' => true]], 'servers' => ['lou' => ['type' => 'http', 'url' => $mcp, 'headers' => ['Authorization' => 'Bearer ${input:lou-token}']]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'the VS Code config') ?>
</details>
<details class="fold"><summary><h3>Technical details</h3> <small class="muted">bridge URL, plain HTTP, OpenAPI</small></summary>
<p>The bridge speaks MCP (streamable HTTP) at this URL. Send the token as <code>Authorization: Bearer …</code>, or as <code>?key=</code> for clients that only take a URL.</p>
<?= $snippet('snip-url', $mcp, 'the bridge URL') ?>
<p>Without MCP, every tool is also a plain JSON endpoint. OpenAPI description: <a href="<?= $e($mcp) ?>?openapi" target="_blank" rel="noopener"><code><?= $e($mcp) ?>?openapi</code></a>. Example:</p>
<?= $snippet('snip-curl', "curl -X POST '$mcp?tool=describe_site' -H 'Authorization: Bearer $tok' -H 'Content-Type: application/json' -d '{}'", 'the curl example') ?>
<p class="muted">Each assistant works in its own draft. When it submits, its changes go to staging, and they go live when someone publishes them.</p>
</details>
</div>
