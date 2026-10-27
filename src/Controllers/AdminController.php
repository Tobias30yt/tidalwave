<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Services/Auth/bootstrap.php';
require_once dirname(__DIR__).'/Services/AdminStore.php';
require_once __DIR__.'/AdminContentController.php';
require_once __DIR__.'/AdminApiController.php';
require_once __DIR__.'/AdminMailController.php';
final class AdminController {
    public static function dispatch(string $path): bool {
        if ($path!=='/admin' && !str_starts_with($path,'/admin/')) return false;
        header('Cache-Control: no-store, private'); header('Referrer-Policy: no-referrer'); header('X-Robots-Tag: noindex, nofollow');
        try {
            $method=$_SERVER['REQUEST_METHOD']??'GET';
            if (!in_array($method,['GET','POST'],true)) { header('Allow: GET, POST'); throw new AuthError(405,'Method not allowed.'); }
            $auth=require dirname(__DIR__,2).'/config/auth.php'; $admin=require dirname(__DIR__,2).'/config/admin.php';
            if (!$auth['enabled']) throw new AuthError(503,'Sign-in is not configured.');
            auth_configure($auth);
            $origin=parse_url($auth['origin']); $host=$origin['host'].(isset($origin['port'])?':'.$origin['port']:'');
            if (strcasecmp($_SERVER['HTTP_HOST']??'',$host)!==0 || ($origin['scheme']==='https' && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off'))) throw new AuthError(400,'Use the configured HTTPS website.');
            AuthSession::start($auth); $accounts=new AuthStore($auth['storage'],$auth['app_key']);
            $user=isset($_SESSION['user_id'])?$accounts->user($_SESSION['user_id']):null;
            if (!$user || !hash_equals($user['session_key'],$_SESSION['user_key']??'')) throw new AuthError(401,'Sign in with the administrator’s Discord account first.');
            if (!AdminStore::allowed($user,$admin)) throw new AuthError(403,'This panel is available only to the configured administrator.');
            $store=new AdminStore($admin);
            if($method==='POST') { AuthSession::csrf($_POST['csrf']??null);$accounts->limit('admin:'.$user['id'],60,600); }
            if(AdminApiController::dispatch($path,$method,$store,$user))return true;
            if(AdminMailController::dispatch($path,$method,$store,$accounts,$user))return true;
            if(AdminContentController::dispatch($path,$method,$store,$accounts,$user,$admin))return true;
            if ($method==='POST') {
                if ($path==='/admin/save') {
                    $id=$store->save(($_POST['id']??'')?:null,$_POST,$_FILES['source']??null,$user['id']);
                    $_SESSION['admin_notice']='Saved. Choose Deploy saved version to apply the source, commands, and environment.';
                } elseif ($path==='/admin/action') {
                    $id=(string)($_POST['id']??''); $action=(string)($_POST['action']??'');
                    if ($action==='delete' && ($_POST['confirm']??'')!=='delete') throw new AuthError(400,'Confirm permanent deletion of the bot and its container data.');
                    $store->enqueue($id,$action,$user['id']); $_SESSION['admin_notice']='Job queued. Refresh to see its progress.';
                } else throw new AuthError(404,'Page not found.');
                header('Location: /admin?bot='.$id,true,303); return true;
            }
            if ($path!=='/admin') throw new AuthError(404,'Page not found.');
            $managed=$store->query('SELECT id,name,runtime,status,revision FROM bots ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
            $selected=isset($_GET['bot']) && is_string($_GET['bot']) ? $_GET['bot'] : null;
            $bot=$selected && $selected!=='new' ? $store->query('SELECT * FROM bots WHERE id=?',[$selected])->fetch(PDO::FETCH_ASSOC) : null;
            $busy=$bot && $store->busy($bot['id']); $notice=$_SESSION['admin_notice']??null; unset($_SESSION['admin_notice']);
            $heartbeat=(int)$store->query('SELECT heartbeat FROM health WHERE id=1')->fetchColumn(); $workerOnline=$heartbeat>time()-45;
            PageController::render('/admin',compact('managed','bot','busy','notice','workerOnline','user'));
        } catch(AuthError $error) { http_response_code($error->status); PageController::render('/admin',['panelError'=>$error->getMessage()]); }
        return true;
    }
}
