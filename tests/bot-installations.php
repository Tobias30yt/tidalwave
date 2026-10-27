<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Services/Auth/bootstrap.php';require dirname(__DIR__).'/src/Services/AdminStore.php';require dirname(__DIR__).'/src/Services/BotInstallations.php';
$checks=0;
function check(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function denied(callable $f,int $status): void {try{$f();throw new RuntimeException('Expected denial');}catch(AuthError $e){check($e->status===$status,'Expected status '.$status);}}
class InstallationDiscord extends DiscordClient {
    public array $app=[];public bool $validSecret=true;
    public function request(string $method,string $path,?string $authorization=null,?array $body=null,bool $form=false):array {
        if($path==='/applications/@me')return $this->app;
        if($path==='/oauth2/token'){if(!$this->validSecret)throw new AuthError(502,'Invalid secret');return ['access_token'=>'synthetic'];}
        return [];
    }
}
$auth=['app_key'=>str_repeat('a',64),'origin'=>'https://website.example'];
$store=new AdminStore(['storage'=>__DIR__.'/output/invitations-'.bin2hex(random_bytes(5))]);$id=str_repeat('a',32);
$store->query('INSERT INTO bots(id,name,runtime,start_command,install_command,environment,source,updated_at,created_at,published_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,'Example','node','node index.js','','DISCORD_TOKEN=private-token','source',time(),time(),time()]);
$service=new BotInstallations($store,$auth);$discord=new InstallationDiscord();$client='333333333333333333';
$input=['enabled'=>'1','client_id'=>$client,'client_secret'=>'private-secret','permissions'=>'0'];
$discord->app=['id'=>$client,'bot_public'=>true,'bot_require_code_grant'=>false,'redirect_uris'=>[$auth['origin'].'/bots/'.$id.'/install/callback']];
denied(fn()=>$service->save($id,$input,'owner',$discord),400);
$discord->app['bot_require_code_grant']=true;$discord->app['redirect_uris']=[];denied(fn()=>$service->save($id,$input,'owner',$discord),400);
$discord->app['redirect_uris']=[$auth['origin'].'/bots/'.$id.'/install/callback'];$discord->validSecret=false;denied(fn()=>$service->save($id,$input,'owner',$discord),502);
$discord->validSecret=true;$service->save($id,$input,'owner',$discord);
$saved=$store->query('SELECT * FROM bot_installations')->fetch(PDO::FETCH_ASSOC);
check(!str_contains(json_encode($saved),'private-'),'Credentials encrypted in database');
check(!isset($service->settings($id)['secret']),'Settings do not expose encrypted secret');
check($service->credentials($id)['client_secret']==='private-secret','Credential decryption');
$service->save($id,array_replace($input,['client_secret'=>'']),'owner',$discord);check($service->credentials($id)['client_secret']==='private-secret','Blank secret preserves saved value');
$merged=$service->merge($auth+['installations'=>['synatra'=>['enabled'=>true]]]);check(isset($merged['installations'][$id])&&!isset($merged['installations']['synatra']),'Hosted catalog included and Synatra excluded');
$store->query('UPDATE bots SET published_at=NULL WHERE id=?',[$id]);check($service->credentials($id)===null,'Draft invitation unavailable');$store->query('UPDATE bots SET published_at=? WHERE id=?',[time(),$id]);
$service->save($id,[],'owner',$discord);check($service->credentials($id)===null,'Disabled invitation unavailable');
$store->query('UPDATE bots SET name=? WHERE id=?',['Synatra',$id]);denied(fn()=>$service->save($id,$input,'owner',$discord),403);
echo "$checks bot installation checks passed.\n";
