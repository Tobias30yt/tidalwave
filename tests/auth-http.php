<?php
declare(strict_types=1);
// Dedicated localhost fixture only. Refuses to overwrite a real configuration.
require dirname(__DIR__).'/src/Services/Auth/bootstrap.php';
$origin=$argv[1] ?? 'http://127.0.0.1:8082';
if (!preg_match('~^http://127\.0\.0\.1:[0-9]{4,5}$~D',$origin)) throw new RuntimeException('Use a dedicated localhost HTTP preview.');
$root=dirname(__DIR__); $local=$root.'/config/auth.local.php';
$handle=@fopen($local,'x');
if (!$handle) throw new RuntimeException('Refusing to overwrite an existing auth.local.php.');
$dir=__DIR__.'/output/http-'.bin2hex(random_bytes(5));
$pair=sodium_crypto_sign_keypair(); $secret=sodium_crypto_sign_secretkey($pair);
$config=['enabled'=>true,'origin'=>$origin,'allow_local_http'=>true,'storage'=>$dir,
    'app_key'=>bin2hex(random_bytes(32)),'public_key'=>bin2hex(sodium_crypto_sign_publickey($pair)),
    'client_id'=>'111111111111111111','guild_id'=>'222222222222222222',
    'client_secret'=>'synthetic-no-network','bot_token'=>'synthetic-no-network'];
fwrite($handle,"<?php\nreturn ".var_export($config,true).";\n"); fclose($handle);
$checks=0; $cookie='';
function expect_http(bool $ok,string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
function send_http(string $path,string $method='GET',?string $body=null,array $extra=[]): array {
    global $origin,$cookie;
    $curl=curl_init($origin.$path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>10,CURLOPT_COOKIE=>$cookie,
        CURLOPT_HTTPHEADER=>$extra ?: ['Content-Type: application/x-www-form-urlencoded']]);
    if ($body!==null) curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
    $raw=curl_exec($curl); if ($raw===false) throw new RuntimeException('Preview not reachable');
    $size=curl_getinfo($curl,CURLINFO_HEADER_SIZE); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    $headers=substr($raw,0,$size); $body=substr($raw,$size); curl_close($curl);
    if (preg_match('/Set-Cookie: (tw_session=[^;]*)/i',$headers,$m)) $cookie=$m[1];
    return [$status,$headers,$body];
}
function signed_http(array $payload,string $secret,int $offset=0,bool $tamper=false): array {
    $raw=json_encode($payload,JSON_THROW_ON_ERROR); $stamp=(string)(time()+$offset);
    $sig=bin2hex(sodium_crypto_sign_detached($stamp.$raw,$secret));
    return send_http('/discord/interactions','POST',$raw.($tamper ? ' ' : ''),[
        'Content-Type: application/json','X-Signature-Ed25519: '.$sig,'X-Signature-Timestamp: '.$stamp]);
}
function seeded_cookie(array $user,array $config): string {
    $id=bin2hex(random_bytes(16)); $session=['user_id'=>$user['id'],'user_key'=>$user['session_key'],
        'started'=>time(),'last_seen'=>time(),'csrf'=>str_repeat('c',64)];
    $value=''; foreach ($session as $key=>$item) $value.=$key.'|'.serialize($item);
    if (!is_dir($config['storage'].'/sessions')) mkdir($config['storage'].'/sessions',0700,true);
    file_put_contents($config['storage'].'/sessions/sess_'.$id,$value);
    return 'tw_session='.$id;
}
try {
    [$status,$headers,$body]=send_http('/account');
    expect_http($status===200 && str_contains($body,'Continue with Discord'),'Configured sign-in page');
    expect_http(str_contains(strtolower($headers),'httponly') && str_contains(strtolower($headers),'samesite=lax'),'Cookie flags');
    expect_http(str_contains(strtolower($headers),'no-store'),'No-store missing');
    preg_match('/name="csrf" value="([a-f0-9]+)"/',$body,$m); $csrf=$m[1];
    expect_http(send_http('/auth/discord')[0]===405,'Login GET should be denied');
    expect_http(send_http('/auth/discord','POST','csrf=wrong')[0]===403,'CSRF should be denied');
    [$status,$headers]=send_http('/auth/discord','POST',http_build_query(['csrf'=>$csrf]));
    expect_http($status===303 && str_contains($headers,'https://discord.com/oauth2/authorize?'),'OAuth redirect');
    preg_match('/Location: ([^\r\n]+)/i',$headers,$m); parse_str(parse_url($m[1],PHP_URL_QUERY),$params);
    expect_http($params['scope']==='identify' && strlen($params['state'])===64,'Scope and state');
    expect_http(send_http('/auth/discord/callback?'.http_build_query(['state'=>$params['state'],'code'=>'fake','iss'=>'https://evil.example']))[0]===403,'Wrong issuer');
    expect_http(send_http('/auth/discord/callback?'.http_build_query(['state'=>$params['state'],'code'=>'fake','iss'=>'https://discord.com']))[0]===403,'Consumed state replay');
    expect_http(send_http('/bots/synatra/install','POST','csrf='.$csrf)[0]===401,'Anonymous install');
    $store=new AuthStore($dir,$config['app_key']);
    $user=$store->signIn(['id'=>'444444444444444444','username'=>'qa-user']);
    $cookie=seeded_cookie($user,$config);
    expect_http(send_http('/bots/synatra/install','POST','csrf='.str_repeat('c',64))[0]===403,'Unverified install');
    expect_http(send_http('/auth/verify-code','POST','csrf='.str_repeat('c',64))[0]===303,'Code generation');
    [$status,,$body]=send_http('/account');
    preg_match('/<code>([A-F0-9-]{19})<\/code>/',$body,$m); $code=$m[1] ?? '';
    expect_http(strlen($code)===19,'Code display');
    $ping=['application_id'=>$config['client_id'],'type'=>1];
    expect_http(send_http('/discord/interactions','POST','{}',['Content-Type: application/json'])[0]===401,'Unsigned request');
    expect_http(signed_http($ping,$secret,0,true)[0]===401,'Tampered body');
    expect_http(signed_http($ping,$secret,-301)[0]===401,'Old signature');
    $pingResponse=signed_http($ping,$secret);
    expect_http($pingResponse[0]===200 && json_decode($pingResponse[2],true)===['type'=>1],'Signed PING');
    $payload=['application_id'=>$config['client_id'],'id'=>'666666666666666666','type'=>2,
        'guild_id'=>$config['guild_id'],'member'=>['user'=>['id'=>$user['id']]],
        'data'=>['name'=>'verify','options'=>[['name'=>'code','type'=>3,'value'=>$code]]]];
    $response=signed_http($payload,$secret);
    expect_http($response[0]===200 && json_decode($response[2],true)['data']['flags']===64,'Ephemeral verification response');
    expect_http(str_contains(send_http('/account')[2],'Server verification complete'),'Verified UI');
    expect_http(send_http('/bots/synatra/install','POST','csrf='.str_repeat('c',64))[0]===409,'Disabled full bot');
    $store->revoke($user['id']); signed_http($payload,$secret);
    expect_http(!$store->user($user['id'])['verified_at'],'Replay must not restore verification');
    expect_http(send_http('/auth/delete','POST','csrf='.str_repeat('c',64))[0]===400,'Deletion requires confirmation');
    expect_http(send_http('/auth/delete','POST','csrf='.str_repeat('c',64).'&confirm=delete')[0]===303,'Delete account');
    expect_http($store->user($user['id'])===null,'Account data removed');
    expect_http(str_contains(send_http('/account')[2],'Continue with Discord'),'Session cleared after deletion');
    echo "PASS: $checks HTTP checks for OAuth state/issuer, CSRF, session cookies, signed Discord webhooks, verification, installation denial and deletion.\n";
} finally {
    // Remove only the exclusive test config created above. Keep outputs under the ignored test directory.
    if (is_file($local)) unlink($local);
}
