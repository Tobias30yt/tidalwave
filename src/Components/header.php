<header class="site-header"><div class="container nav-wrap">
<a href="/" class="brand" aria-label="TidalWave home"><img src="/favicon.svg" width="36" height="36" alt="">TidalWave<span class="brand-dot">.</span></a>
<button class="menu-toggle" type="button" aria-expanded="false" aria-controls="main-nav" hidden><span>Menu</span><svg viewBox="0 0 24 24" class="icon" aria-hidden="true"><path d="M4 8h16M4 16h16" fill="none" stroke="currentColor" stroke-width="1.5"/></svg></button>
<nav id="main-nav" aria-label="Main navigation">
<?php foreach (['/' => 'Home', '/about' => 'About', '/bots' => 'Discord Bots', '/projects' => 'Projects', '/forum'=>'Forum', '/api'=>'API', '/mail'=>'Mail', '/contact' => 'Contact'] as $href => $label): ?>
<a href="<?= $href ?>" <?= $path === $href ? 'aria-current="page"' : '' ?> class="<?= $href === '/contact' ? 'nav-contact' : '' ?>"><?= $label ?><?= $href === '/contact' ? icon('diagonal') : '' ?></a>
<?php endforeach ?>
<a href="/account" <?= $path === '/account' ? 'aria-current="page"' : '' ?> class="nav-account">Account <?= icon('bot') ?></a>
</nav></div></header>
