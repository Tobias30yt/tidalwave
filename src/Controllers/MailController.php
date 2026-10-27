<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Services/MailStore.php';
require_once dirname(__DIR__).'/Services/Webmail.php';
final class MailController {
    public static function dispatch(string $path):bool{
        if($path!=='/mail'&&!str_starts_with($path,'/mail/'))return false;
        header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
        try{
            $method=$_SERVER['REQUEST_METHOD']??'GET';if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');throw new AuthError(405,'Method not allowed.');}
            $auth=require dirname(__DIR__,2).'/config/auth.php';auth_configure($auth);$origin=parse_url($auth['origin']);
            $host=$origin['host'].(isset($origin['port'])?':'.$origin['port']:'');
            if(strcasecmp($_SERVER['HTTP_HOST']??'',$host)!==0||($origin['scheme']==='https'&&(empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off')))throw new AuthError(400,'Use the configured HTTPS website.');
            AuthSession::start($auth);$accounts=new AuthStore($auth['storage'],$auth['app_key']);$user=isset($_SESSION['user_id'])?$accounts->user($_SESSION['user_id']):null;
            if(!$user||$user['blocked']||!hash_equals($user['session_key'],$_SESSION['user_key']??''))throw new AuthError(401,'Sign in with your Discord account to open Tidalwave Mail.');
            $config=require dirname(__DIR__,2).'/config/mail.php';$store=new MailStore(new AdminStore(require dirname(__DIR__,2).'/config/admin.php'),$config,$auth['app_key']);
            $account=$store->get($user['id']);if(!$account||!$account['enabled'])throw new AuthError(403,'The administrator must enable your personal mailbox before you can use Tidalwave Mail.');
            if($method==='POST'){AuthSession::csrf($_POST['csrf']??null);$accounts->limit('mail-write:'.$user['id'],60,600);}
            $mail=new Webmail($store->client($account),$account['address'],$config['external_delivery']);
            $folders=$mail->folders();$folderIds=array_column($folders,'id');
            if($method==='POST'){
                if($path==='/mail/compose'){
                    $send=($_POST['action']??'')==='send';
                    if($send){$nonce=(string)($_POST['send_nonce']??'');if(!$nonce||!hash_equals($_SESSION['mail_send_nonce']??'',$nonce))throw new AuthError(409,'This send form was already used. Reopen the saved draft before retrying.');unset($_SESSION['mail_send_nonce']);$accounts->limit('mail-send:'.$user['id'],30,3600);}
                    $id=$mail->compose($_POST,$_FILES['attachments']??[],$send);$_SESSION['mail_notice']=$send?'Message submitted. Delivery to a remote mailbox is not guaranteed.':'Draft saved.';
                    header('Location: /mail?message='.rawurlencode($id),true,303);return true;
                }
                if($path==='/mail/move'){$target=(string)($_POST['folder']??'');if(!in_array($target,$folderIds,true))throw new AuthError(400,'Unknown folder.');$mail->move((string)($_POST['message']??''),$target);}
                elseif($path==='/mail/folder'){$name=trim((string)($_POST['name']??''));if(!$name||mb_strlen($name)>80)throw new AuthError(400,'Use a folder name of 1–80 characters.');$mail->call('Mailbox/set',['create'=>['folder'=>['name'=>$name]]]);}
                else throw new AuthError(404,'Unknown mail action.');
                header('Location: /mail',true,303);return true;
            }
            if($path==='/mail/download'){
                [$name,$data]=$mail->download((string)($_GET['message']??''),(string)($_GET['blob']??''));
                header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="attachment"; filename*=UTF-8\'\''.rawurlencode($name));header("Content-Security-Policy: sandbox; default-src 'none'");echo $data;return true;
            }
            if(!in_array($path,['/mail','/mail/compose'],true))throw new AuthError(404,'Page not found.');
            $selected=(string)($_GET['folder']??$mail->folder($folders,'inbox'));if(!in_array($selected,$folderIds,true))throw new AuthError(404,'Folder not found.');
            $search=(string)($_GET['q']??'');$page=max(1,min(10000,(int)($_GET['page']??1)));[$emails,$total]=$mail->list($selected,$search,$page);
            $message=isset($_GET['message'])?$mail->email((string)$_GET['message']):null;
            if($message&&empty($message['keywords']['$draft']))$mail->read($message['id']);
            $compose=$path==='/mail/compose';$draft=$compose&&isset($_GET['draft'])?$mail->email((string)$_GET['draft']):null;
            if($draft&&empty($draft['keywords']['$draft']))throw new AuthError(400,'Not a draft.');
            if($compose)$_SESSION['mail_send_nonce']=bin2hex(random_bytes(24));
            $notice=$_SESSION['mail_notice']??null;unset($_SESSION['mail_notice']);
            PageController::render('/mail',compact('account','config','folders','selected','search','page','emails','total','message','compose','draft','notice'));
        }catch(AuthError $e){http_response_code($e->status);$compose=$path==='/mail/compose'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST';PageController::render('/mail',['mailError'=>$e->getMessage(),'unsent'=>$compose?$_POST:null]);}
        return true;
    }
}
