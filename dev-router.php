<?php
declare(strict_types=1);
// Development only: php -S 127.0.0.1:8080 -t public dev-router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (preg_match('~^/(?:favicon\.svg|assets/(?:css|js|images|icons)/[a-zA-Z0-9_./-]+\.(?:css|js|svg|png|webp|avif|jpg|jpeg|woff2))$~D', $path)) {
    $file = realpath(__DIR__.'/public'.$path);
    $root = realpath(__DIR__.'/public').DIRECTORY_SEPARATOR;
    if ($file && str_starts_with($file, $root) && is_file($file)) return false;
}
require __DIR__.'/public/index.php';
