<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__).'/storage/logs/php-error.log');
error_reporting(E_ALL);
header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'none'; base-uri 'none'; form-action 'self' https://discord.com; frame-ancestors 'none'; object-src 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header('Cache-Control: no-cache');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') header('Strict-Transport-Security: max-age=31536000');
try {
    require dirname(__DIR__).'/src/Services/helpers.php';
    require dirname(__DIR__).'/src/Controllers/PageController.php';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!is_string($path) || strlen($path) > 2048 || preg_match('/[\x00-\x1f]/', $path)) {
        http_response_code(400); echo 'Bad request'; exit;
    }
    $path = rtrim($path, '/') ?: '/';
    require dirname(__DIR__).'/src/Controllers/AuthController.php';
    require dirname(__DIR__).'/src/Controllers/AdminController.php';
    require dirname(__DIR__).'/src/Controllers/ForumController.php';
    require dirname(__DIR__).'/src/Controllers/CatalogController.php';
    require dirname(__DIR__).'/src/Controllers/ReportsApiController.php';
    if (ReportsApiController::dispatch($path)) exit;
    require dirname(__DIR__).'/src/Controllers/MailController.php';
    if (MailController::dispatch($path)) exit;
    if (AdminController::dispatch($path)) exit;
    if (ForumController::dispatch($path)) exit;
    if (CatalogController::dispatch($path)) exit;
    if (AuthController::dispatch($path)) exit;
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        http_response_code(405); header('Allow: GET, HEAD'); echo 'Method not allowed'; exit;
    }
    PageController::render($path);
} catch (Throwable $error) {
    // Exception traces may contain OAuth codes or tokens passed as arguments.
    error_log('Unhandled '.get_class($error).' in '.basename($error->getFile()).':'.$error->getLine());
    http_response_code(500);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>TidalWave — temporarily unavailable</title><h1>We’ll be back soon.</h1><p>Please try again later.</p></html>';
}
