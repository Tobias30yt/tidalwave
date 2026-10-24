<?php
declare(strict_types=1);
final class PageController {
    public static function render(string $path, array $context = []): void {
        $routes = [
            '/' => ['home', 'Building the next wave of digital experiences'],
            '/about' => ['about', 'About'], '/bots' => ['bots', 'Discord Bots'],
            '/projects' => ['projects', 'Projects'], '/api' => ['api', 'TidalWave API'],
            '/contact' => ['contact', 'Contact'], '/legal' => ['legal', 'Legal Center'],
            '/account' => ['account', 'Your Account'],
            '/admin' => ['admin', 'Bot Control'],
            '/legal/privacy' => ['placeholder', 'TidalWave Privacy Policy'],
            '/legal/terms' => ['placeholder', 'TidalWave Terms of Service'],
            '/legal/notice' => ['placeholder', 'Legal Notice / Impressum'],
            '/legal/synatra/privacy' => ['synatra-legal', 'Synatra Privacy Policy'],
            '/legal/synatra/terms' => ['synatra-legal', 'Synatra Terms of Service'],
        ];
        [$view, $title] = $routes[$path] ?? ['404', 'Page not found'];
        if ($view === '404') http_response_code(404);
        $app = require dirname(__DIR__, 2).'/config/app.php';
        $bots = require dirname(__DIR__, 2).'/config/bots.php';
        $projects = require dirname(__DIR__, 2).'/config/projects.php';
        $description = 'TidalWave is an independent development organization building software, Discord bots, and future developer tools.';
        extract($context, EXTR_SKIP);
        require dirname(__DIR__).'/Views/layout.php';
    }
}
