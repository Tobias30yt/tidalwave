<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Services/ForumStore.php';
final class ForumController {
    public static function dispatch(string $path): bool {
        if($path!=='/forum' && !str_starts_with($path,'/forum/'))return false;
        header('Cache-Control: no-store, private');
        try {
            $method=$_SERVER['REQUEST_METHOD']??'GET';
            if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');throw new AuthError(405,'Method not allowed.');}
            $auth=require dirname(__DIR__,2).'/config/auth.php';$admin=require dirname(__DIR__,2).'/config/admin.php';
            $page=max(1,min(100000,(int)($_GET['page']??1)));$search=is_string($_GET['q']??null)?mb_substr($_GET['q'],0,100):'';
            $user=null;$isAdmin=false;$rows=[];$total=0;$forumEnabled=(bool)$auth['enabled'];
            if(!$forumEnabled){if($method==='POST')throw new AuthError(503,'Forum sign-in is not configured.');PageController::render('/forum',compact('rows','total','page','search','user','isAdmin','forumEnabled'));return true;}
            auth_configure($auth);$accounts=new AuthStore($auth['storage'],$auth['app_key']);$forum=new ForumStore($accounts);
            if(isset($_COOKIE['tw_session']) || $method==='POST') {
                $origin=parse_url($auth['origin']);$host=$origin['host'].(isset($origin['port'])?':'.$origin['port']:'');
                if(strcasecmp($_SERVER['HTTP_HOST']??'',$host)!==0 || ($origin['scheme']==='https' && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off')))throw new AuthError(400,'Use the configured HTTPS website.');
                AuthSession::start($auth);$user=isset($_SESSION['user_id'])?$accounts->user($_SESSION['user_id']):null;
                if($user && (!hash_equals($user['session_key'],$_SESSION['user_key']??'') || $user['blocked']))$user=null;
                $isAdmin=AdminStore::allowed($user,$admin);
            }
            if($method==='POST') {
                if(!$user)throw new AuthError(401,'Sign in with Discord to participate.');AuthSession::csrf($_POST['csrf']??null);
                if($path==='/forum/create')$id=$forum->create($user['id'],$_POST['title']??null,$_POST['body']??null);
                elseif($path==='/forum/reply'){$id=(int)($_POST['thread']??0);$forum->reply($id,$user['id'],$_POST['body']??null);}
                elseif($path==='/forum/moderate'){
                    $id=(int)($_POST['thread']??0);$action=(string)($_POST['action']??'');
                    if(in_array($action,['delete','delete_reply'],true) && ($_POST['confirm']??'')!=='delete')throw new AuthError(400,'Confirm deletion.');
                    $forum->moderate($id,$user['id'],$isAdmin,$action,isset($_POST['reply'])?(int)$_POST['reply']:null);
                    if($isAdmin){$panel=new AdminStore($admin);$panel->audit($user['id'],'forum.'.$action,(string)$id);}
                    if($action==='delete'){header('Location: /forum',true,303);return true;}
                }else throw new AuthError(404,'Page not found.');
                header('Location: /forum/thread/'.$id,true,303);return true;
            }
            if(preg_match('~^/forum/thread/([1-9][0-9]{0,9})$~D',$path,$match)){
                $thread=$forum->thread((int)$match[1]);
                $replies=$accounts->query('SELECT r.*,u.username FROM forum_replies r LEFT JOIN users u ON u.id=r.author_id WHERE r.thread_id=? ORDER BY r.id LIMIT 30 OFFSET '.(($page-1)*30),[$thread['id']])->fetchAll(PDO::FETCH_ASSOC);
                $total=(int)$accounts->query('SELECT count(*) FROM forum_replies WHERE thread_id=?',[$thread['id']])->fetchColumn();
                PageController::render('/forum/thread',compact('thread','replies','total','page','user','isAdmin'));return true;
            }
            if($path!=='/forum')throw new AuthError(404,'Page not found.');
            extract($forum->listing($search,$page));PageController::render('/forum',compact('rows','total','page','search','user','isAdmin','forumEnabled'));
        }catch(AuthError $error){http_response_code($error->status);PageController::render('/forum',['forumError'=>$error->getMessage()]);}
        return true;
    }
}
