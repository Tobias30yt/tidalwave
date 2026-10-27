<?php
declare(strict_types=1);
require_once __DIR__.'/Stalwart.php';
final class MailStore {
    public function __construct(public readonly AdminStore $store,public readonly array $config,private string $key){
        $store->db->exec('CREATE TABLE IF NOT EXISTS mail_accounts(user_id TEXT PRIMARY KEY,address TEXT NOT NULL UNIQUE,account_id TEXT NOT NULL UNIQUE,secret TEXT NOT NULL,enabled INTEGER NOT NULL DEFAULT 0,quota_mb INTEGER NOT NULL,created_at INTEGER NOT NULL);');
    }
    private function encrypt(string $value):string{$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);return base64_encode($nonce.sodium_crypto_secretbox($value,$nonce,hash_hkdf('sha256',hex2bin($this->key),32,'tidalwave-mail')));}
    private function decrypt(string $value):string{$v=base64_decode($value,true);if($v===false||strlen($v)<40)throw new AuthError(503,'Mailbox credential unavailable.');$p=sodium_crypto_secretbox_open(substr($v,24),substr($v,0,24),hash_hkdf('sha256',hex2bin($this->key),32,'tidalwave-mail'));return $p===false?throw new AuthError(503,'Mailbox credential unavailable.'):$p;}
    public function get(string $id):?array{return $this->store->query('SELECT * FROM mail_accounts WHERE user_id=?',[$id])->fetch(PDO::FETCH_ASSOC)?:null;}
    public function client(array $account):Stalwart{if(!$account['enabled'])throw new AuthError(403,'Mail access has not been enabled for this account.');return new Stalwart($this->config,$account['address'],$this->decrypt($account['secret']));}
    public function save(array $user,string $local,int $quota,bool $enabled):void{
        if($user['blocked'])throw new AuthError(403,'Unblock this user before enabling mail.');
        $local=strtolower(trim($local));if(!preg_match('/^[a-z0-9][a-z0-9._-]{0,47}$/D',$local)||in_array($local,['admin','tidalwave-management','postmaster','abuse'],true))throw new AuthError(400,'Choose a valid, non-reserved mailbox name.');
        if($quota<10||$quota>5120)throw new AuthError(400,'Quota must be 10–5120 MB.');
        $account=$this->get($user['id']);$address=$local.'@'.$this->config['domain'];
        if($this->store->query('SELECT 1 FROM mail_accounts WHERE address=? AND user_id<>?',[$address,$user['id']])->fetchColumn())throw new AuthError(409,'Address already assigned.');
        $manager=Stalwart::manager($this->config);$password=$account?$this->decrypt($account['secret']):bin2hex(random_bytes(32));
        $fields=['name'=>$local,'quotas'=>['maxDiskQuota'=>$quota*1048576],'credentials'=>$enabled?(object)['0'=>['@type'=>'Password','secret'=>$password]]:(object)[],'permissions'=>$enabled?['@type'=>'Inherit']:['@type'=>'Replace','enabledPermissions'=>(object)[],'disabledPermissions'=>(object)[]]];
        if($account){
            // Revoke website access before contacting the service; failures fail closed.
            $this->store->query('UPDATE mail_accounts SET enabled=0 WHERE user_id=?',[$user['id']]);
            $manager->call('x:Account/set',['update'=>[$account['account_id']=>$fields]],true);$accountId=$account['account_id'];
        }else{
            $result=$manager->call('x:Account/set',['create'=>['new'=>array_merge($fields,['@type'=>'User','domainId'=>$this->config['domain_id'],'roles'=>['@type'=>'User'],'description'=>'Tidalwave Discord '.$user['id']])]],true);$accountId=$result['created']['new']['id'];
        }
        $this->store->query('INSERT INTO mail_accounts VALUES(?,?,?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET address=excluded.address,secret=excluded.secret,enabled=excluded.enabled,quota_mb=excluded.quota_mb',[$user['id'],$address,$accountId,$this->encrypt($password),(int)$enabled,$quota,time()]);
    }
    public function disable(string $id):void{
        $account=$this->get($id);if(!$account)return;
        $this->store->query('UPDATE mail_accounts SET enabled=0 WHERE user_id=?',[$id]);
        Stalwart::manager($this->config)->call('x:Account/set',['update'=>[$account['account_id']=>['credentials'=>(object)[],'permissions'=>['@type'=>'Replace','enabledPermissions'=>(object)[],'disabledPermissions'=>(object)[]]]]],true);
    }
    public function remove(string $id):void{
        $account=$this->get($id);if(!$account)throw new AuthError(404,'Mailbox not found.');
        $this->store->query('UPDATE mail_accounts SET enabled=0 WHERE user_id=?',[$id]);
        Stalwart::manager($this->config)->call('x:Account/set',['destroy'=>[$account['account_id']]],true);
        $this->store->query('DELETE FROM mail_accounts WHERE user_id=?',[$id]);
    }
}
