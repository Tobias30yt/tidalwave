<section class="page-hero container"><span class="eyebrow">TIDALWAVE / YOUR ACCOUNT</span><h1>Your identity.<br>Your next wave<span>.</span></h1><p class="lead">Connect your Discord account, verify in our server, and unlock access to bot installation.</p></section>
<section class="container account-layout">
<div class="account-panel">
<?php if (!empty($authError)): ?>
    <div class="review-note" role="alert"><strong>We couldn’t complete that request.</strong><p><?= e($authError) ?></p></div>
    <a class="button secondary" href="/account">Return to your account <?= icon('arrow') ?></a>
<?php elseif (!$authEnabled): ?>
    <span class="badge">SETUP IN PROGRESS</span><h2>Discord sign-in is on its way.</h2><p>The account connection is not enabled yet. Bot installation stays locked until sign-in and server verification are ready.</p><a class="button secondary" href="/contact">Contact TidalWave <?= icon('arrow') ?></a>
<?php elseif (!$user): ?>
    <div class="account-symbol"><?= icon('bot') ?></div><h2>Start with Discord.</h2><p>Sign in to confirm which Discord account belongs to you. We request your account ID and username, not your email or messages.</p>
    <form method="post" action="/auth/discord"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><button class="button primary" type="submit">Continue with Discord <?= icon('diagonal') ?></button></form>
    <p class="account-fine">Signing in creates a TidalWave account. Server verification is a separate step. <a href="/legal/privacy">Privacy information</a></p>
<?php else: ?>
    <?php if ($notice): ?><div class="account-notice" role="status"><?= e($notice) ?></div><?php endif ?>
    <span class="eyebrow">SIGNED IN WITH DISCORD</span><h2 class="account-name">@<?= e($user['username']) ?></h2><p class="account-id">Discord ID <?= e($user['id']) ?></p>
    <?php $adminConfig=require dirname(__DIR__,2).'/config/admin.php'; if (AdminStore::allowed($user,$adminConfig)): ?><p><a class="button primary" href="/admin">Open bot control panel <?= icon('arrow') ?></a></p><?php endif ?>
    <?php if ($user['blocked']): ?><div class="review-note"><strong>Account access restricted</strong><p>Please contact TidalWave about verification or installation access.</p></div>
    <?php elseif ($verified): ?><div class="account-notice"><span class="status-dot"></span> Server verification complete</div><p>Your Discord identity is verified. We check your current server membership again before each installation.</p>
    <?php else: ?>
        <h3>Verify in our Discord server.</h3><p>Send <code>/verify</code> in the verification server using this Discord account. No code is needed: Discord securely identifies your account.</p>
        <?php if ($invite=safe_url($config['guild_invite_url'])): ?><a class="text-link" href="<?= e($invite) ?>">Open the verification server <?= icon('diagonal') ?></a><?php endif ?>
        <div class="verification-code"><span class="eyebrow">IN OUR DISCORD SERVER</span><code>/verify</code></div>
        <a class="button secondary" href="/account">Refresh verification <?= icon('arrow') ?></a>
    <?php endif ?>
    <div class="account-installations"><h3>Your bot access</h3><?php foreach ($bots as $bot): ?>
        <div class="account-bot"><div><strong><?= e($bot['name']) ?></strong><p><?= !empty($config['installations'][$bot['id']]['enabled']) ? 'Server installation' : 'Installation not yet available' ?></p></div>
        <?php if ($verified && !empty($config['installations'][$bot['id']]['enabled'])): ?><form method="post" action="/bots/<?= e($bot['id']) ?>/install"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><button class="button secondary" type="submit">Add to a server <?= icon('diagonal') ?></button></form><?php elseif (!$verified): ?><span class="unavailable">Verification required</span><?php endif ?></div>
    <?php endforeach ?></div>
    <div class="account-actions"><form method="post" action="/auth/logout"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><button class="button secondary" type="submit">Sign out</button></form>
    <details><summary>Delete my website account</summary><p>This removes your website profile, verification and installation records. It does not remove bots from Discord servers or revoke Discord-side authorizations. You can manage those in Discord.</p><form method="post" action="/auth/delete"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><label class="delete-confirm"><input type="checkbox" name="confirm" value="delete" required> Delete my TidalWave account and verification</label><button class="button secondary" type="submit">Delete account</button></form></details></div>
<?php endif ?>
</div>
<aside class="account-guide"><span class="eyebrow">THREE STEPS TO GET STARTED</span><ol><li><span>01</span><div><h3>Connect your account</h3><p>Sign in directly with Discord. Your password stays with Discord.</p></div></li><li><span>02</span><div><h3>Verify in our server</h3><p>Send /verify in our Discord server. Discord confirms the account that sent the command.</p></div></li><li><span>03</span><div><h3>Add an available bot</h3><p>Choose a server in Discord. You’ll need permission to add apps there.</p></div></li></ol><a class="text-link" href="/contact">Need a hand? <?= icon('arrow') ?></a></aside>
</section>
