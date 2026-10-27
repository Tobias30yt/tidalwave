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
            '/admin/api' => ['admin-api','Reports API'],
            '/admin/mail' => ['admin-mail','Manage Mail'],
            '/mail' => ['mail','Tidalwave Mail'],
            '/admin/projects' => ['admin-projects','Manage Projects'],
            '/admin/users' => ['admin-users','Manage Accounts'],
            '/forum' => ['forum','Community Forum'],
            '/forum/thread' => ['forum-thread','Community Question'],
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
        $adminConfig=require dirname(__DIR__,2).'/config/admin.php';
        if(is_file($adminConfig['storage'].'/panel.sqlite')){
            require_once dirname(__DIR__).'/Services/AdminStore.php';require_once dirname(__DIR__).'/Services/ProjectStore.php';
            $catalog=new AdminStore($adminConfig);
            $bots=array_merge($bots,$catalog->publicBots());
            $projects=array_merge($projects,(new ProjectStore($catalog))->published());
        }
        $description = 'TidalWave is an independent development organization building software, Discord bots, and future developer tools.';
        extract($context, EXTR_SKIP);
        require dirname(__DIR__).'/Views/layout.php';
    }
}
