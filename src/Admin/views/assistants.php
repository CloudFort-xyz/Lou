<?php
/** @var callable $e */
/** @var Lou\Admin\I18n $t */
$mcp = $oauthUrls['mcp'];
$tok = $newToken ?? 'lou_YOUR_TOKEN';
// A snippet with a copy button. The text is copied exactly as shown (admin.js reads the element's textContent).
$snippet = static function (string $id, string $text, string $label) use ($e, $t): string {
    // one-liners wrap so the whole command is visible; multi-line JSON keeps its indentation and scrolls
    return '<div class="snippet"><pre class="code' . (str_contains($text, "\n") ? '' : ' wrap') . '" id="' . $id . '">' . $e($text) . '</pre>'
        . '<button class="btn btn-copy" type="button" data-copy="' . $id . '" aria-label="' . $t('Copy {what}', ['what' => $label]) . '">' . $t('Copy') . '</button></div>';
};
$json = static fn (array $a): string => (string) json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$instructions = 'You edit a website through the Lou tools. Always call describe_site first and follow its rules. Build a preview, share the preview link, and only submit for review when the user is happy with it.';
?>
<h1><?= $t('Users') ?></h1>
<?php require __DIR__ . '/_users-tabs.php' ?>
<p class="lead"><?= $t('Let an AI assistant such as Claude or ChatGPT change your site\'s layout, design, menus and content. Nothing it does goes live by itself: its changes wait for you on the {!review} page.', ['review' => '<a href="' . $self . '?r=review">' . $t('Review') . '</a>']) ?></p>

<?php if ($newToken): ?>
  <div class="alert alert-ok">
    <strong><?= $t('New token. Copy it now, it is shown only once.') ?></strong> <?= $t('The token steps below already contain it.') ?>
    <div class="copy-field">
      <input class="code" id="new-token" readonly value="<?= $e($newToken) ?>" aria-label="<?= $t('New token') ?>" data-select>
      <button class="btn btn-primary" type="button" data-copy="new-token"><?= $t('Copy token') ?></button>
    </div>
  </div>
<?php endif ?>

<h2><?= $t('Connected assistants') ?></h2>
<?php if ($grants || $tokens): ?>
<table class="table list">
  <thead><tr><th><?= $t('Name') ?></th><th><?= $t('How') ?></th><th class="hide-sm"><?= $t('Connected by') ?></th><th><?= $t('Last used') ?></th><th><span class="sr"><?= $t('Actions') ?></span></th></tr></thead>
  <tbody>
  <?php foreach ($grants as $g): ?>
    <tr><td><?= $e($g['name']) ?><?php if (!$g['active']): ?> <small class="muted">(<?= $t('paused: the person who approved it can no longer do so, or changed their password') ?>)</small><?php endif ?></td>
      <td><?= $t('Signed in') ?> <small class="muted"><?= $time($g['created_at'], true) ?></small></td>
      <td class="hide-sm"><?= $e($g['user']) ?></td>
      <td><?= $g['last_used'] ? $time($g['last_used']) : '<span class="muted">' . $t('never') . '</span>' ?></td>
      <td class="cell-actions"><form method="post" action="<?= $self ?>?r=oauth-revoke" data-confirm="<?= $t('End the connection of “{name}”? It loses access immediately and has to sign in again.', ['name' => $g['name']]) ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="id" value="<?= $e($g['id']) ?>">
        <button class="btn btn-danger" type="submit"><?= $t('Disconnect') ?></button></form></td></tr>
  <?php endforeach ?>
  <?php foreach ($tokens as $id => $tk): ?>
    <tr><td><?= $e($tk['name']) ?></td><td><?= $t('Token') ?> <small class="muted"><?= $time($tk['created'], true) ?></small></td>
      <td class="hide-sm"><?= isset($tk['by']) ? $e($tk['by']) : '<span class="muted">–</span>' ?></td>
      <td><?= $tk['last_used'] ? $time($tk['last_used']) : '<span class="muted">' . $t('never') . '</span>' ?></td>
      <td class="cell-actions"><form method="post" action="<?= $self ?>?r=token-revoke" data-confirm="<?= $t('Revoke “{name}”? The assistant using it loses access immediately.', ['name' => $tk['name']]) ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="id" value="<?= $e($id) ?>">
        <button class="btn btn-danger" type="submit"><?= $t('Revoke') ?></button></form></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php else: ?>
  <p class="muted"><?= $t('None yet. Connect one with the steps below.') ?></p>
<?php endif ?>

<div class="fold-head"><h2><?= $t('Connect an assistant') ?></h2><button class="btn btn-link" type="button" data-fold-all="#clients"><?= $t('Expand all') ?></button></div>
<p><?= $t('Add this address to your assistant. It sends you here to log in and approve it, so there is no password or token to copy.') ?></p>
<?= $snippet('snip-url', $mcp, $t->plain('the connector address')) ?>
<p class="muted"><?= $t('Editors and administrators can approve a connection. It ends when you disconnect it above, or when that person is removed, changes their password or signs out everywhere.') ?></p>
<div id="clients">
<details class="fold" open><summary><h3><?= $t('claude.ai, Claude Desktop, ChatGPT and other chat apps') ?></h3></summary>
<p><?= $t('In the app\'s settings, add a custom connector with the address above. Leave the OAuth client ID and secret empty: the app registers itself. Then click <strong>Connect</strong>, log in here and approve.') ?></p>
<p class="muted"><?= $t('In ChatGPT, custom connectors need developer mode (Settings → Apps → Advanced).') ?></p>
</details>
<details class="fold"><summary><h3>Microsoft Copilot</h3> <small class="muted"><?= $t('Microsoft 365, through Copilot Studio') ?></small></summary>
<p><?= $t('Copilot connects to the site through an agent you make in {!studio}. This needs a work account with Copilot Studio (a licence or a trial). The free Copilot app can’t connect to sites like this one.', ['studio' => '<a href="https://copilotstudio.microsoft.com" target="_blank" rel="noopener">Copilot Studio</a>']) ?></p>
<ol>
  <li><?= $t('Create an agent (or open one), then go to <strong>Tools → Add a tool → New tool → Model Context Protocol</strong>.') ?></li>
  <li><?= $t('Give it a name (e.g. the site’s name) and paste the address above as the <strong>server URL</strong>.') ?></li>
  <li><?= $t('Under <strong>Authentication</strong> choose <strong>OAuth 2.0</strong>, then <strong>Dynamic discovery</strong>. Create it, then create the connection: a window opens where you log in here and approve.') ?></li>
  <li><?= $t('Optionally, add this to the agent’s <strong>instructions</strong>:') ?></li>
</ol>
<?= $snippet('snip-copilot-instructions', $instructions, $t->plain('the instructions')) ?>
<ol start="5">
  <li><?= $t('Publish the agent to Microsoft 365 Copilot or Teams, and use it from there.') ?></li>
</ol>
<p class="muted"><?= $t('If Copilot Studio only offers <strong>Manual</strong>, make app credentials under {!creds} below and paste the values it lists.', ['creds' => '<a href="#app-credentials">' . $t('App credentials') . '</a>']) ?></p>
</details>
<details class="fold"><summary><h3>Claude Code</h3> <small class="muted"><?= $t('command line') ?></small></summary>
<p><?= $t('Run this once in a terminal, then type <code>/mcp</code> in Claude Code and choose <strong>Authenticate</strong>:') ?></p>
<?= $snippet('snip-claude-code', "claude mcp add --transport http lou $mcp", $t->plain('the Claude Code command')) ?>
</details>
<details class="fold"><summary><h3>GitHub Copilot in VS Code</h3> <small class="muted"><?= $t('config file') ?></small></summary>
<p><?= $t('Save this as <code>.vscode/mcp.json</code> (or add it to your user MCP settings), start the server, allow the sign-in and use Copilot Chat in <strong>Agent</strong> mode.') ?></p>
<?= $snippet('snip-vscode', $json(['servers' => ['lou' => ['type' => 'http', 'url' => $mcp]]]), $t->plain('the VS Code config')) ?>
</details>
<details class="fold"><summary><h3>Gemini CLI</h3> <small class="muted"><?= $t('command line') ?></small></summary>
<p><?= $t('Run this once in a terminal, then type <code>/mcp auth lou</code> in Gemini CLI:') ?></p>
<?= $snippet('snip-gemini', "gemini mcp add --transport http lou $mcp", $t->plain('the Gemini CLI command')) ?>
</details>
</div>

<details class="fold" id="tokens"<?= $newToken ? ' open' : '' ?>><summary><h2><?= $t('Connect with a token instead') ?></h2> <small class="muted"><?= $t('for apps that can’t sign in') ?></small></summary>
<p class="muted"><?= $t('A token is the assistant\'s password to this site. Create one per assistant (or per person), so you can switch them off one by one.') ?></p>
<form method="post" action="<?= $self ?>?r=token-add" class="actions">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input name="name" placeholder="<?= $t('e.g. Claude – Bence') ?>" required maxlength="60" aria-label="<?= $t('Token name') ?>">
  <button class="btn btn-primary" type="submit"><?= $t('Create token') ?></button>
</form>
<?php if (!$newToken): ?>
  <p class="muted"><?= $t('Replace <code>lou_YOUR_TOKEN</code> below with your token. If you no longer have it, create a new one: the steps on that page will contain it.') ?></p>
<?php endif ?>
<h3><?= $t('Chat apps') ?></h3>
<p><?= $t('In the app\'s settings, add a custom connector (no authentication) and paste this address. It contains the token, so keep it as secret as a password.') ?></p>
<?= $snippet('snip-key-url', "$mcp?key=$tok", $t->plain('the connector address')) ?>
<h3>Microsoft Copilot</h3>
<p><?= $t('Under <strong>Authentication</strong> choose <strong>API key</strong>, type <strong>Query</strong>, parameter name <code>key</code>. Create it, then create the connection and paste your token when asked:') ?></p>
<?= $snippet('snip-copilot-token', $tok, $t->plain('the token')) ?>
<h3>Claude Code</h3>
<?= $snippet('snip-claude-code-token', "claude mcp add --transport http lou $mcp --header \"Authorization: Bearer $tok\"", $t->plain('the Claude Code command')) ?>
<h3>GitHub Copilot in VS Code</h3>
<p><?= $t('VS Code asks for the token once and keeps it out of the file.') ?></p>
<?= $snippet('snip-vscode-token', $json(['inputs' => [['type' => 'promptString', 'id' => 'lou-token', 'description' => 'Lou token', 'password' => true]], 'servers' => ['lou' => ['type' => 'http', 'url' => $mcp, 'headers' => ['Authorization' => 'Bearer ${input:lou-token}']]]]), $t->plain('the VS Code config')) ?>
<h3><?= $t('Plain HTTP') ?></h3>
<p><?= $t('Without MCP, every tool is also a plain JSON endpoint. OpenAPI description:') ?> <a href="<?= $e($mcp) ?>?openapi" target="_blank" rel="noopener"><code><?= $e($mcp) ?>?openapi</code></a>. <?= $t('Example:') ?></p>
<?= $snippet('snip-curl', "curl -X POST '$mcp?tool=describe_site' -H 'Authorization: Bearer $tok' -H 'Content-Type: application/json' -d '{}'", $t->plain('the curl example')) ?>
</details>

<details class="fold" id="app-credentials"<?= $newClient ? ' open' : '' ?>><summary><h2><?= $t('App credentials') ?></h2> <small class="muted"><?= $t('client ID and secret, for apps that ask for them') ?></small></summary>
<p class="muted"><?= $t('Most apps register themselves. Some, like Copilot Studio in <strong>Manual</strong> mode, ask for a client ID and secret instead: create them here and paste them into the app with the addresses below.') ?></p>
<?php if ($newClient): ?>
  <div class="alert alert-ok">
    <strong><?= $t('New app credentials for “{name}”. Copy the secret now, it is shown only once.', ['name' => $newClient['name']]) ?></strong>
    <p><?= $t('Client ID') ?></p>
    <?= $snippet('snip-client-id', $newClient['id'], $t->plain('Client ID')) ?>
    <p><?= $t('Client secret') ?></p>
    <?= $snippet('snip-client-secret', (string) $newClient['secret'], $t->plain('Client secret')) ?>
  </div>
<?php endif ?>
<p><?= $t('Authorization URL') ?></p>
<?= $snippet('snip-oauth-authorize', $oauthUrls['authorize'], $t->plain('Authorization URL')) ?>
<p><?= $t('Token URL and refresh URL') ?></p>
<?= $snippet('snip-oauth-token', $oauthUrls['token'], $t->plain('Token URL and refresh URL')) ?>
<p><?= $t('Scope') ?></p>
<?= $snippet('snip-oauth-scope', Lou\Bridge\OAuth::SCOPE, $t->plain('Scope')) ?>
<p class="muted"><?= $t('The app shows a redirect (callback) URL once it is set up. Paste it next to the credentials below. If you leave it empty, the first approved sign-in records it.') ?></p>
<?php if ($clients): ?>
<table class="table list">
  <thead><tr><th><?= $t('Name') ?></th><th class="hide-sm"><?= $t('Client ID') ?></th><th><?= $t('Redirect URL') ?></th><th><span class="sr"><?= $t('Actions') ?></span></th></tr></thead>
  <tbody>
  <?php foreach ($clients as $c): ?>
    <tr><td><?= $e($c['name']) ?></td><td class="hide-sm"><code><?= $e($c['id']) ?></code></td>
      <td><form method="post" action="<?= $self ?>?r=oauth-client-redirect" class="actions">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="id" value="<?= $e($c['id']) ?>">
        <input name="redirect_uri" value="<?= $e($c['redirect_uris'][0] ?? '') ?>" placeholder="https://…" aria-label="<?= $t('Redirect URL') ?>">
        <button class="btn" type="submit"><?= $t('Save') ?></button></form></td>
      <td class="cell-actions"><form method="post" action="<?= $self ?>?r=oauth-client-delete" data-confirm="<?= $t('Delete the app credentials “{name}”? Assistants connected with them lose access immediately.', ['name' => $c['name']]) ?>">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="id" value="<?= $e($c['id']) ?>">
        <button class="btn btn-danger" type="submit"><?= $t('Delete') ?></button></form></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
<form method="post" action="<?= $self ?>?r=oauth-client-add" class="actions">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input name="name" placeholder="<?= $t('e.g. Copilot Studio – Kner agent') ?>" required maxlength="60" aria-label="<?= $t('App name') ?>">
  <input name="redirect_uri" placeholder="<?= $t('Redirect URL (optional)') ?>" aria-label="<?= $t('Redirect URL') ?>">
  <button class="btn btn-primary" type="submit"><?= $t('Create app credentials') ?></button>
</form>
</details>

<details class="fold"><summary><h2><?= $t('Technical details') ?></h2> <small class="muted"><?= $t('MCP, OAuth, tokens') ?></small></summary>
<p><?= $t('The bridge speaks MCP (streamable HTTP) at this URL. Send the token as <code>Authorization: Bearer …</code>, or as <code>?key=</code> for clients that only take a URL.') ?></p>
<p><?= $t('Signing in is OAuth 2.1 with Lou as its own authorization server: authorization code with PKCE (S256), dynamic client registration (RFC 7591), and discovery through protected resource metadata (RFC 9728) and authorization server metadata (RFC 8414) at these addresses:') ?></p>
<ul>
  <li><a href="<?= $e($oauthUrls['resource_metadata']) ?>" target="_blank" rel="noopener"><code><?= $e($oauthUrls['resource_metadata']) ?></code></a></li>
  <li><code><?= $e($oauthUrls['issuer']) ?>/.well-known/oauth-authorization-server</code></li>
</ul>
<p><?= $t('Access tokens last an hour and refresh tokens 90 days; refresh tokens change on every use. Only SHA-256 hashes of secrets, codes and tokens are stored.') ?></p>
<p class="muted"><?= $t('Each assistant works in its own draft. When it submits, its changes go to staging, and they go live when someone publishes them.') ?></p>
</details>
