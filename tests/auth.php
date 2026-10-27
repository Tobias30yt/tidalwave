<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Services/Auth/bootstrap.php';
$checks=0;
function check(bool $condition,string $message): void { global $checks; $checks++; if (!$condition) throw new RuntimeException($message); }
function denied(callable $action,int $status): void {
    try { $action(); throw new RuntimeException('Expected denial '.$status); }
    catch (AuthError $e) { check($e->status===$status,'Unexpected status: '.$e->status); }
}
$directory=__DIR__.'/output/auth-'.bin2hex(random_bytes(5));
$config=['client_id'=>'111111111111111111','guild_id'=>'222222222222222222','verification_lifetime'=>2592000,
    'bot_token'=>'synthetic-token','installations'=>['example-bot'=>['enabled'=>true,'client_id'=>'333333333333333333',
    'client_secret'=>'synthetic-secret','bot_token'=>'synthetic-full-bot','permissions'=>'0']]];
$store=new AuthStore($directory,str_repeat('a',64));
$a='444444444444444444'; $b='555555555555555555';
$user=$store->signIn(['id'=>$a,'username'=>'<test-user>']);
$store->signIn(['id'=>$b,'username'=>'other-user']);
check(!$store->verified($user,$config),'New user must not be verified');
$code=$store->challenge($a);
$row=$store->query('SELECT * FROM challenges WHERE user_id=?',[$a])->fetch(PDO::FETCH_ASSOC);
check(!str_contains(json_encode($row),$code),'Plain code stored in database');
check(!$store->verify($b,$config['guild_id'],$code),'Another account used a code');
check($store->verify($a,$config['guild_id'],$code),'Correct account failed verification');
check(!$store->verify($a,$config['guild_id'],$code),'Code replay succeeded');
check($store->verified($store->user($a),$config),'Verified user not recognized');
$store->signIn(['id'=>$a,'username'=>'renamed']);
check($store->verified($store->user($a),$config),'Rename lost permanent identity');
$old=$store->challenge($b); $new=$store->challenge($b);
check(!$store->verify($b,$config['guild_id'],$old),'Replaced code accepted');
$store->query('UPDATE challenges SET expires_at=? WHERE user_id=?',[time()-1,$b]);
check(!$store->verify($b,$config['guild_id'],$new),'Expired code accepted');

$pair=sodium_crypto_sign_keypair(); $secret=sodium_crypto_sign_secretkey($pair); $public=bin2hex(sodium_crypto_sign_publickey($pair));
$body='{"type":1}'; $timestamp=(string)time();
$sig=bin2hex(sodium_crypto_sign_detached($timestamp.$body,$secret));
check(DiscordInteractions::validSignature($body,$sig,$timestamp,$public),'Valid signature failed');
check(!DiscordInteractions::validSignature($body.' ',$sig,$timestamp,$public),'Tampered body accepted');
check(!DiscordInteractions::validSignature($body,$sig,(string)(time()-301),$public),'Stale signature accepted');
check(!DiscordInteractions::validSignature($body,'invalid',$timestamp,$public),'Malformed signature accepted');
$interactions=new DiscordInteractions($store,$config);
check($interactions->handle(['application_id'=>$config['client_id'],'type'=>1])===['type'=>1],'PING failed');
denied(fn()=>$interactions->handle(['application_id'=>$b,'type'=>1]),401);
$new=$store->challenge($b);
$payload=['id'=>'666666666666666666','application_id'=>$config['client_id'],'type'=>2,'guild_id'=>$config['guild_id'],
    'member'=>['user'=>['id'=>$b]],'data'=>['name'=>'verify','options'=>[['name'=>'code','type'=>3,'value'=>$new]]]];
$bad=$payload; $bad['guild_id']=$a;
check(str_contains($interactions->handle($bad)['data']['content'],'verification server'),'Wrong guild accepted');
$bad=$payload; unset($bad['member']);
check(!$store->verified($store->user($b),$config),'Wrong context verified account');
$bad=$payload; $bad['member']['pending']=true;
$interactions->handle($bad);
check(!$store->verified($store->user($b),$config),'Screening bypass');
$reply=$interactions->handle($payload);
check($reply['data']['flags']===64 && $reply['data']['allowed_mentions']['parse']===[],'Reply must be private and non-mentioning');
check($store->verified($store->user($b),$config),'Signed command payload did not verify');
$store->revoke($b);
$interactions->handle($payload);
check(!$store->verified($store->user($b),$config),'Interaction replay restored revoked access');

class FakeDiscord extends DiscordClient {
    public array $calls=[]; public bool $isMember=true; public bool $grant=true; public bool $unavailable=false;
    public function request(string $method,string $path,?string $authorization=null,?array $body=null,bool $form=false): array {
        $this->calls[]=$path;
        if ($this->unavailable) throw new AuthError(503,'Unavailable');
        if (str_starts_with($path,'/guilds/')) {
            if (!$this->isMember) throw new AuthError(404,'Left guild');
            return ['user'=>['id'=>'444444444444444444'],'pending'=>false];
        }
        if ($path==='/applications/@me') return ['id'=>'333333333333333333','bot_require_code_grant'=>$this->grant];
        throw new RuntimeException('Unexpected remote request');
    }
}
$fake=new FakeDiscord(); $gate=new InstallGate($store,$fake,$config);
denied(fn()=>$gate->check($b,'example-bot'),403);
check($fake->calls===[],'Unverified request reached Discord');
denied(fn()=>$gate->check($a,'unknown'),409);
$gate->check($a,'example-bot');
check(count($fake->calls)===2,'Gate did not check membership and application');
$fake->grant=false; denied(fn()=>$gate->check($a,'example-bot'),503); $fake->grant=true;
$fake->isMember=false; denied(fn()=>$gate->check($a,'example-bot'),403); $fake->isMember=true;
$fake->unavailable=true; denied(fn()=>$gate->check($a,'example-bot'),503); $fake->unavailable=false;
$store->query('UPDATE users SET verified_at=? WHERE id=?',[time()-2592001,$a]);
denied(fn()=>$gate->check($a,'example-bot'),403);
$oldSessionKey=$store->user($b)['session_key'];
$store->query('DELETE FROM users WHERE id=?',[$b]);
$recreated=$store->signIn(['id'=>$b,'username'=>'other-user']);
check($oldSessionKey!==$recreated['session_key'],'Deleted account restored old sessions');
$store->revoke($a,true);
denied(fn()=>$store->signIn(['id'=>$a,'username'=>'blocked']),403);
$store->query('DELETE FROM users WHERE id=?',[$a]);
denied(fn()=>$store->signIn(['id'=>$a,'username'=>'recreated']),403);
$store->revoke($a,false);
check(!$store->verified($store->signIn(['id'=>$a,'username'=>'unblocked']),$config),'Unblocked account skipped re-verification');
$store->limit('test-rate',1,600); denied(fn()=>$store->limit('test-rate',1,600),429);

$_SESSION=['csrf'=>str_repeat('b',64)];
AuthSession::csrf(str_repeat('b',64)); denied(fn()=>AuthSession::csrf('wrong'),403);
$state=AuthSession::state('install:example-bot',$a);
denied(fn()=>AuthSession::consumeState($state,'install:example-bot',$b),403);
$state=AuthSession::state('login',null);
AuthSession::consumeState($state,'login',null);
denied(fn()=>AuthSession::consumeState($state,'login',null),403);
$state=AuthSession::state('login',null); $_SESSION['oauth']['expires']=time()-1;
denied(fn()=>AuthSession::consumeState($state,'login',null),403);
$state=AuthSession::state('install:example-bot',$a);
denied(fn()=>AuthSession::consumeState($state,'login',null),403);
$store->cleanup();
AuthSession::start(['storage'=>$directory,'origin'=>'http://127.0.0.1']);
$oldId=session_id();
AuthSession::login($store->user($a));
check(session_id()!==$oldId,'Login must rotate the session ID');
check($_SESSION['user_key']===$store->user($a)['session_key'],'Session must bind account generation');
$_SESSION['last_seen']=time()-3601;
session_write_close();
AuthSession::start(['storage'=>$directory,'origin'=>'http://127.0.0.1']);
check(!isset($_SESSION['user_id']),'Idle session remained authenticated');
AuthSession::logout();
echo "PASS: $checks authentication, signature, identity-binding, expiry, replay, installation-gate and session-state checks.\n";
