<?php
declare(strict_types=1);
final class BotInstallations {
    public function __construct(private AdminStore $store,private array $auth){
        $store->db->exec('CREATE TABLE IF NOT EXISTS bot_installations(bot_id TEXT PRIMARY KEY,client_id TEXT NOT NULL,secret TEXT NOT NULL,permissions TEXT NOT NULL,enabled INTEGER NOT NULL DEFAULT 0)');
        // The runner uses a separate SQLite connection; a trigger also works when FK checks are off.
        $store->db->exec('CREATE TRIGGER IF NOT EXISTS delete_bot_installation AFTER DELETE ON bots BEGIN DELETE FROM bot_installations WHERE bot_id=OLD.id; END');
    }
    private function key(): string { return hash_hkdf('sha256',$this->auth['app_key'],SODIUM_CRYPTO_SECRETBOX_KEYBYTES,'tidalwave-bot-installations'); }
    public function settings(string $id): ?array {
        return $this->store->query('SELECT bot_id,client_id,permissions,enabled FROM bot_installations WHERE bot_id=?',[$id])->fetch(PDO::FETCH_ASSOC)?:null;
    }
    public function credentials(string $id): ?array {
        $row=$this->store->query('SELECT i.* FROM bot_installations i JOIN bots b ON b.id=i.bot_id WHERE i.bot_id=? AND i.enabled=1 AND b.published_at IS NOT NULL AND lower(trim(b.name))<>?',[$id,'synatra'])->fetch(PDO::FETCH_ASSOC);
        if(!$row)return null;
        $raw=base64_decode($row['secret'],true);
        if(!$raw || strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new AuthError(503,'Bot invitation credentials are unavailable.');
        $plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$this->key());
        if($plain===false)throw new AuthError(503,'Bot invitation credentials are unavailable.');
        return json_decode($plain,true,8,JSON_THROW_ON_ERROR)+['client_id'=>$row['client_id'],'permissions'=>$row['permissions'],'enabled'=>true];
    }
    public function save(string $id,array $input,string $actor,DiscordClient $discord): void {
        $bot=$this->store->bot($id);
        if(strtolower(trim($bot['name']))==='synatra')throw new AuthError(403,'Synatra installation is excluded.');
        $old=$this->store->query('SELECT * FROM bot_installations WHERE bot_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if(($input['enabled']??'')!=='1'){
            $this->store->query('UPDATE bot_installations SET enabled=0 WHERE bot_id=?',[$id]);$this->store->audit($actor,'invitation.disable',$id);return;
        }
        $client=trim((string)($input['client_id']??''));$permissions=trim((string)($input['permissions']??'0'));
        if(!discord_id($client)||!preg_match('/^[0-9]{1,20}$/D',$permissions))throw new AuthError(400,'Enter a valid Discord application ID and permissions integer.');
        $previous=[];
        if($old && $old['client_id']===$client){
            $raw=base64_decode($old['secret'],true);
            if($raw && strlen($raw)>SODIUM_CRYPTO_SECRETBOX_NONCEBYTES){$plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$this->key());if($plain!==false)$previous=json_decode($plain,true);}
        }
        $env=[];foreach(explode("\n",$bot['environment']) as $line){$parts=explode('=',$line,2);if(count($parts)===2)$env[$parts[0]]=$parts[1];}
        $secret=trim((string)($input['client_secret']??''))?:($previous['client_secret']??'');
        $token=trim((string)($input['bot_token']??''))?:($env['DISCORD_TOKEN']??$env['BOT_TOKEN']??$previous['bot_token']??'');
        if($secret===''||$token===''||strlen($secret)>512||strlen($token)>512||preg_match('/[\s\x00-\x1f]/',$secret.$token))throw new AuthError(400,'Enter the OAuth2 client secret and a bot token (or save DISCORD_TOKEN in the bot environment).');
        $credentials=['client_id'=>$client,'client_secret'=>$secret,'bot_token'=>$token,'permissions'=>$permissions];
        $app=$discord->request('GET','/applications/@me','Bot '.$token);
        if(($app['id']??null)!==$client || empty($app['bot_public']) || empty($app['bot_require_code_grant']))throw new AuthError(400,'In the Discord Developer Portal, enable Public Bot and Requires OAuth2 Code Grant for this application.');
        $redirect=$this->auth['origin'].'/bots/'.$id.'/install/callback';
        if(!in_array($redirect,$app['redirect_uris']??[],true))throw new AuthError(400,'Add the exact callback URL shown below to OAuth2 Redirects in the Discord Developer Portal.');
        // Verify the client secret without issuing a user authorization or installing a bot.
        $discord->request('POST','/oauth2/token',null,['grant_type'=>'client_credentials','client_id'=>$client,'client_secret'=>$secret,'scope'=>'applications.commands.update'],true);
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encrypted=base64_encode($nonce.sodium_crypto_secretbox(json_encode(['client_secret'=>$secret,'bot_token'=>$token],JSON_THROW_ON_ERROR),$nonce,$this->key()));
        $this->store->query('INSERT INTO bot_installations VALUES(?,?,?,?,1) ON CONFLICT(bot_id) DO UPDATE SET client_id=excluded.client_id,secret=excluded.secret,permissions=excluded.permissions,enabled=1',[$id,$client,$encrypted,$permissions]);
        $this->store->audit($actor,'invitation.enable',$id);
    }
    public function merge(array $config): array {
        foreach($this->store->query('SELECT bot_id FROM bot_installations WHERE enabled=1')->fetchAll(PDO::FETCH_COLUMN) as $id){$credentials=$this->credentials($id);if($credentials)$config['installations'][$id]=$credentials;}
        unset($config['installations']['synatra']);return $config;
    }
}
