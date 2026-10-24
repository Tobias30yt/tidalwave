<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Services/ProjectStore.php';
final class CatalogController {
    public static function dispatch(string $path): bool {
        if(!str_starts_with($path,'/projects/download/'))return false;
        try {
            if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){header('Allow: GET, HEAD');throw new AuthError(405,'Method not allowed.');}
            $config=require dirname(__DIR__,2).'/config/admin.php';
            if(!is_file($config['storage'].'/panel.sqlite'))throw new AuthError(404,'Download not found.');
            (new ProjectStore(new AdminStore($config)))->download(substr($path,19));
        }catch(AuthError $error){http_response_code($error->status);echo e($error->getMessage());}
        return true;
    }
}
