<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · TidalWave</title>
    <meta name="description" content="<?= e($description) ?>">
    <meta name="theme-color" content="#060B16">
    <?php if (str_starts_with($path, '/legal') || $view === '404' || $path === '/account'): ?><meta name="robots" content="noindex, follow"><?php endif ?>
    <meta property="og:title" content="<?= e($title) ?> · TidalWave"><meta property="og:description" content="<?= e($description) ?>"><meta property="og:type" content="website"><meta property="og:site_name" content="TidalWave">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/site.css?v=<?= e($app['asset_version']) ?>">
    <?php if(str_starts_with($view,'admin') || str_starts_with($view,'forum')): ?><link rel="stylesheet" href="/assets/css/admin.css?v=<?= e($app['asset_version']) ?>"><?php endif ?>
    <script src="/assets/js/site.js?v=<?= e($app['asset_version']) ?>" defer></script>
</head>
<body class="page-<?= e($view) ?>">
<a class="skip-link" href="#main">Skip to content</a>
<?php component('header', compact('path')); ?>
<main id="main" tabindex="-1"><?php require __DIR__.'/'.$view.'.php'; ?></main>
<?php component('footer', compact('app')); ?>
<div class="toast" role="status" aria-live="polite" aria-atomic="true" id="status-message"></div>
</body></html>
