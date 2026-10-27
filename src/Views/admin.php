<section class="page-hero container admin-hero"><span class="eyebrow">TIDALWAVE / CONTROL ROOM</span><h1>Your bots.<br>Your control<span>.</span></h1><p class="lead">Upload, configure, and run your Discord bots in one place.</p></section>
<?php if(empty($panelError))component('admin-nav',compact('path')); ?>
<section class="container admin-shell">
<?php if (!empty($panelError)): ?>
    <div class="account-panel"><h2>Administrator access</h2><p role="alert"><?= e($panelError) ?></p><a class="button primary" href="/account">Your Discord account</a> <a class="button secondary" href="/admin">Back to panel</a></div>
<?php else: ?>
<aside class="admin-sidebar"><span class="eyebrow">@<?= e($user['username']) ?></span><h2>Bot fleet <span class="admin-count"><?= count($managed) ?> bots</span></h2><p class="admin-worker <?= $workerOnline?'is-online':'' ?>"><?= $workerOnline?'● Runner connected':'○ Runner unavailable — jobs will wait' ?></p><p class="admin-help">No fixed bot limit. Available server resources determine how many can run together.</p>
<a class="button primary" href="/admin?bot=new">+ Create bot</a>
<nav aria-label="Managed bots" class="admin-bot-list"><?php foreach ($managed as $item): ?><a href="/admin?bot=<?= e($item['id']) ?>" <?= ($bot['id']??'')===$item['id']?'aria-current="page"':'' ?>><strong><?= e($item['name']) ?></strong><span><?= e(strtoupper($item['runtime'])) ?> · <?= e($item['status']) ?></span></a><?php endforeach ?></nav><a class="text-link" href="/account">← Your account</a></aside>
<div class="admin-workspace">
<?php if ($notice): ?><div class="account-notice" role="status"><?= e($notice) ?></div><?php endif ?>
<div class="admin-section-heading"><div><span class="eyebrow"><?= $bot?'BOT SETTINGS':'NEW DEPLOYMENT' ?></span><h2><?= $bot?e($bot['name']):'Create your next bot' ?></h2></div><?php if($bot): ?><a class="button secondary" href="/admin?bot=<?= e($bot['id']) ?>">Refresh status</a><?php endif ?></div>
<?php if($busy): ?><p class="account-notice" role="status">A job is in progress. Settings unlock when it finishes. Refresh to see updated logs.</p><?php endif ?>
<form method="post" action="/admin/save" enctype="multipart/form-data" class="admin-form">
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="id" value="<?= e($bot['id']??'') ?>">
<fieldset <?= $busy?'disabled':'' ?>><legend>01 / Source & runtime</legend><div class="admin-field-grid">
<label>Bot name<input name="name" maxlength="80" required placeholder="My Discord bot" value="<?= e($bot['name']??'') ?>"></label>
<label>Runtime<select name="runtime"><?php foreach(['node'=>'Node.js 22','python'=>'Python 3','java'=>'Java 17'] as $key=>$label): ?><option value="<?= $key ?>" <?= ($bot['runtime']??'node')===$key?'selected':'' ?>><?= $label ?></option><?php endforeach ?></select></label></div>
<label>Public description<textarea name="description" rows="3" maxlength="1200" placeholder="What does your bot do?"><?= e($bot['description']??'') ?></textarea><small>After its first successful deployment, the bot name and description appear on the public Bots page. Source, commands, and secrets stay private.</small></label>
<label class="admin-upload">Source archive<input type="file" name="source" accept=".zip,application/zip" <?= $bot?'':'required' ?>><small>ZIP up to 25 MB, 100 MB extracted. Include source files; leave out .git, .env, node_modules, and virtual environments. A single enclosing folder is removed automatically. <?= $bot?'Leave empty to keep the current source.':'' ?></small></label>
<label>Start command<input name="start_command" maxlength="1000" required placeholder="node index.js" value="<?= e($bot['start_command']??'') ?>"><small>Examples: node index.js · python bot.py · java -jar bot.jar. Arguments are supported; shell operators are not.</small></label>
</fieldset><fieldset <?= $busy?'disabled':'' ?>><legend>02 / First-time installation</legend>
<label>Installation commands<textarea name="install_command" rows="5" maxlength="8000" spellcheck="false" placeholder="npm ci&#10;# or: pip install -r requirements.txt&#10;# or: sudo apt-get update &amp;&amp; sudo apt-get install -y ffmpeg"><?= e($bot['install_command']??'') ?></textarea></label>
<p class="admin-help">Runs once per source or installation-command change, inside this bot’s container. Normal starts and restarts reuse the installation. Setup has a 15-minute limit; use -y for apt packages. Python’s pip uses a prepared virtual environment.</p>
</fieldset><fieldset <?= $busy?'disabled':'' ?>><legend>03 / Environment</legend>
<label>.env values<textarea name="environment" rows="5" maxlength="16384" spellcheck="false" autocomplete="off" placeholder="DISCORD_TOKEN=your_bot_token&#10;GUILD_ID=your_server_id"></textarea></label>
<p class="admin-help">One KEY=value per line, without surrounding quotes. Saved values are kept private and injected at startup, including an /app/.env file. <?= $bot?'Leave this empty to preserve saved values; entering values replaces the entire environment.':'' ?></p>
<?php if($bot): ?><p class="admin-help">Saved keys: <?= e(implode(', ',array_map(fn($line)=>explode('=',$line,2)[0],array_filter(explode("\n",$bot['environment']))))) ?: 'none' ?></p><label class="delete-confirm"><input type="checkbox" name="clear_environment" value="1"> Clear all saved environment values</label><?php endif ?>
</fieldset><div class="admin-save"><button class="button primary" type="submit" <?= $busy?'disabled':'' ?>><?= $bot?'Save changes':'Create bot' ?></button><span>Save first, then deploy.</span></div>
</form>
<?php if($bot): ?>
<?php if(strtolower(trim($bot['name']))!=='synatra'): ?>
<section class="admin-runtime"><h2>Discord server installation</h2><p>Allow verified website accounts to add this bot to their Discord servers.</p>
<ol><li>Open this application in the <a href="https://discord.com/developers/applications" target="_blank" rel="noopener noreferrer">Discord Developer Portal</a>.</li><li>Under Bot, enable <strong>Public Bot</strong> and <strong>Requires OAuth2 Code Grant</strong>.</li><li>Under OAuth2, add this exact redirect URL: <code><?= e($invitationCallback) ?></code></li><li>Copy the OAuth2 Client Secret into the private field below. The existing DISCORD_TOKEN environment value is reused.</li></ol>
<form class="admin-form" method="post" action="/admin/invitation"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="id" value="<?= e($bot['id']) ?>">
<label class="delete-confirm"><input type="checkbox" name="enabled" value="1" <?= !empty($invitation['enabled'])?'checked':'' ?>> Enable verified-account installation</label>
<div class="admin-field-grid"><label>Application ID<input name="client_id" inputmode="numeric" value="<?= e($invitation['client_id']??'') ?>" maxlength="20"></label><label>Bot permissions integer<input name="permissions" inputmode="numeric" value="<?= e($invitation['permissions']??'0') ?>" maxlength="20"><small>Use the permissions needed by this bot. 0 requests no additional permissions.</small></label></div>
<label>OAuth2 Client Secret<input type="password" name="client_secret" maxlength="512" autocomplete="new-password"><small>Leave empty to preserve a saved secret. Credentials are encrypted and never displayed.</small></label>
<label>Bot token (optional when DISCORD_TOKEN is saved)<input type="password" name="bot_token" maxlength="512" autocomplete="new-password"></label>
<button class="button primary">Validate &amp; save installation</button></form><p class="admin-help">Status: <?= !empty($invitation['enabled'])?'Enabled':'Not enabled' ?>. The bot must have been deployed before it can be installed.</p></section>
<?php endif ?>
<section class="admin-runtime"><div class="admin-section-heading"><div><span class="eyebrow">RUNTIME / REVISION <?= (int)$bot['revision'] ?></span><h2><?= e(ucfirst($bot['status'])) ?></h2></div><span class="badge">512 MB · 1 CPU</span></div>
<form method="post" action="/admin/action" class="button-row"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="id" value="<?= e($bot['id']) ?>"><?php foreach(['deploy'=>'Deploy saved version','start'=>'Start','stop'=>'Stop','restart'=>'Restart'] as $action=>$label): ?><button type="submit" name="action" value="<?= $action ?>" class="button <?= $action==='deploy'?'primary':'secondary' ?>" <?= $busy?'disabled':'' ?>><?= $label ?></button><?php endforeach ?></form>
<p class="admin-help">Deploy applies saved settings and replaces the previous container. Container files survive stops and restarts; deployment replaces them with the uploaded source. Keep a copy of persistent bot data before deploying.</p>
<h3>Installation & runtime logs</h3><pre class="admin-logs" tabindex="0"><?= e($bot['logs']?:'No logs yet. Deploy this bot to begin.') ?></pre>
<details class="admin-delete"><summary>Delete this bot</summary><form method="post" action="/admin/action"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="id" value="<?= e($bot['id']) ?>"><input type="hidden" name="action" value="delete"><label class="delete-confirm"><input type="checkbox" name="confirm" value="delete" required> Permanently remove this bot, its saved secrets, and container data</label><button class="button secondary" <?= $busy?'disabled':'' ?>>Delete bot</button></form></details>
</section><?php endif ?>
<aside class="admin-help admin-portal">Create the Discord application and obtain its bot token in the <a href="https://discord.com/developers/applications" target="_blank" rel="noopener noreferrer">Discord Developer Portal</a>, then add that token here. This panel creates and hosts your bot deployment.</aside>
</div><?php endif ?></section>
