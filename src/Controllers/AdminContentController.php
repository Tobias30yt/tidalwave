<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Services/ProjectStore.php';
require_once dirname(__DIR__).'/Services/ForumStore.php';
final class AdminContentController {
    public static function dispatch(string $path,string $method,AdminStore $store,AuthStore $accounts,array $user,array $admin): bool {
        if(!in_array($path,['/admin/projects','/admin/projects/save','/admin/projects/delete','/admin/projects/file-delete','/admin/users','/admin/users/action'],true))return false;
        if($method==='POST') {
            if($path==='/admin/users/action'){
                $target=(string)($_POST['user_id']??'');$action=(string)($_POST['action']??'');
                if(!discord_id($target))throw new AuthError(400,'Invalid account ID.');
                if(hash_equals($admin['owner_id'],$target))throw new AuthError(403,'The administrator account cannot be deleted or blocked here.');
                if(!$accounts->user($target) && !$accounts->query('SELECT 1 FROM blocked_accounts WHERE id=?',[$target])->fetchColumn())throw new AuthError(404,'Account not found.');
                if(in_array($action,['block','delete'],true)){
                    require_once dirname(__DIR__).'/Services/MailStore.php';$mailConfig=require dirname(__DIR__,2).'/config/mail.php';$authConfig=require dirname(__DIR__,2).'/config/auth.php';
                    if($action==='delete'&&($_POST['confirm']??'')!=='delete')throw new AuthError(400,'Confirm account deletion.');
                    if($mailConfig['enabled'])(new MailStore($store,$mailConfig,$authConfig['app_key']))->disable($target);
                }
                if($action==='block')$accounts->revoke($target,true);
                elseif($action==='unblock')$accounts->revoke($target,false);
                elseif($action==='delete'){
                    if(($_POST['confirm']??'')!=='delete')throw new AuthError(400,'Confirm account deletion.');
                    new ForumStore($accounts);$accounts->query('DELETE FROM users WHERE id=?',[$target]);
                }else throw new AuthError(400,'Unknown user action.');
                $store->audit($user['id'],'user.'.$action,$target);$_SESSION['admin_notice']='Account updated.';header('Location: /admin/users',true,303);return true;
            }
            $projects=new ProjectStore($store);
            if($path==='/admin/projects/save'){$id=$projects->save($_POST,$_FILES,$user['id']);$_SESSION['admin_notice']='Project saved. Published projects and their downloads appear on the Projects page.';}
            elseif($path==='/admin/projects/delete'){
                if(($_POST['confirm']??'')!=='delete')throw new AuthError(400,'Confirm project deletion.');
                $projects->remove((string)($_POST['id']??''),$user['id']);$id='';$_SESSION['admin_notice']='Project deleted.';
            }elseif($path==='/admin/projects/file-delete'){
                if(($_POST['confirm']??'')!=='delete')throw new AuthError(400,'Confirm file deletion.');
                $projects->removeFile((string)($_POST['file']??''),$user['id']);$id=(string)($_POST['id']??'');$_SESSION['admin_notice']='File deleted.';
            }else throw new AuthError(404,'Page not found.');
            header('Location: /admin/projects'.($id?'?project='.rawurlencode($id):''),true,303);return true;
        }
        $notice=$_SESSION['admin_notice']??null;unset($_SESSION['admin_notice']);
        if($path==='/admin/users') {
            $page=max(1,min(100000,(int)($_GET['page']??1)));$search=is_string($_GET['q']??null)?mb_substr($_GET['q'],0,100):'';
            $sql="SELECT id,username,created_at,verified_at,blocked FROM users UNION ALL SELECT b.id,'Deleted account',NULL,NULL,1 FROM blocked_accounts b WHERE NOT EXISTS(SELECT 1 FROM users u WHERE u.id=b.id)";
            $where=' WHERE username LIKE ? OR id LIKE ?';$params=['%'.$search.'%','%'.$search.'%'];
            $users=$accounts->query('SELECT * FROM ('.$sql.')'.$where.' ORDER BY created_at DESC,id LIMIT 50 OFFSET '.(($page-1)*50),$params)->fetchAll(PDO::FETCH_ASSOC);
            $total=(int)$accounts->query('SELECT count(*) FROM ('.$sql.')'.$where,$params)->fetchColumn();
            PageController::render('/admin/users',compact('users','total','page','search','notice','user','admin'));return true;
        }
        if($path!=='/admin/projects')throw new AuthError(404,'Page not found.');
        $projects=new ProjectStore($store);$project=null;$projectFiles=[];
        if(is_string($_GET['project']??null) && $_GET['project']!=='new'){$project=$projects->get($_GET['project']);$projectFiles=$projects->files($project['id']);}
        $projectList=$store->query('SELECT id,name,published FROM projects ORDER BY updated_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        PageController::render('/admin/projects',compact('project','projectFiles','projectList','notice','user'));return true;
    }
}
