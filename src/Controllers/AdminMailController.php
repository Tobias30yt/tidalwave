<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Services/MailStore.php';
final class AdminMailController {
    public static function dispatch(string $path,string $method,AdminStore $store,AuthStore $accounts,array $user):bool{
        if(!in_array($path,['/admin/mail','/admin/mail/save','/admin/mail/disable','/admin/mail/delete'],true))return false;
        $auth=require dirname(__DIR__,2).'/config/auth.php';$config=require dirname(__DIR__,2).'/config/mail.php';$mail=new MailStore($store,$config,$auth['app_key']);
        if($method==='POST'){
            $target=(string)($_POST['user_id']??'');if(!discord_id($target))throw new AuthError(400,'Invalid Discord account.');
            if($path==='/admin/mail/save'){$account=$accounts->user($target);if(!$account)throw new AuthError(404,'Website user not found.');$mail->save($account,(string)($_POST['address']??''),(int)($_POST['quota']??0),isset($_POST['enabled']));}
            elseif($path==='/admin/mail/disable')$mail->disable($target);
            elseif($path==='/admin/mail/delete'){if(($_POST['confirm']??'')!=='DELETE MAILBOX')throw new AuthError(400,'Type DELETE MAILBOX to confirm permanent mailbox deletion.');$mail->remove($target);}
            else throw new AuthError(404,'Unknown action.');
            $store->audit($user['id'],'mail.'.basename($path),$target);$_SESSION['admin_notice']='Mailbox updated.';header('Location: /admin/mail',true,303);return true;
        }
        $mailboxes=$store->query('SELECT user_id,address,account_id,enabled,quota_mb FROM mail_accounts ORDER BY address')->fetchAll(PDO::FETCH_ASSOC);
        $users=$accounts->query('SELECT id,username,blocked FROM users ORDER BY username LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC);
        $notice=$_SESSION['admin_notice']??null;unset($_SESSION['admin_notice']);
        PageController::render('/admin/mail',compact('mailboxes','users','config','notice'));return true;
    }
}
