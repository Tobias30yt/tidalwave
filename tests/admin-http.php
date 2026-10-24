<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Services/Auth/bootstrap.php';
require dirname(__DIR__).'/src/Services/AdminStore.php';
$root=dirname(__DIR__);$origin=$argv[1]??'http://127.0.0.1:8094';
if(!preg_match('~^http://127\.0\.0\.1:[0-9]{4,5}$~D',$origin)) throw new RuntimeException('Localhost test server required');
foreach(['auth','admin'] as $type) if(file_exists($root.'/config/'.$type.'.local.php')) throw new RuntimeException('Refusing existing local configuration');
$dir=__DIR__.'/output/admin-http-'.bin2hex(random_bytes(5));
$config=['enabled'=>true,'origin'=>$origin,'allow_local_http'=>true,'storage'=>$dir.'/auth','app_key'=>bin2hex(random_bytes(32)),'public_key'=>bin2hex(random_bytes(32)),'client_id'=>'111111111111111111','guild_id'=>'222222222222222222','client_secret'=>'synthetic','bot_token'=>'synthetic'];
$admin=['owner_id'=>'333333333333333333','storage'=>$dir.'/admin','max_bots'=>4];
foreach(['auth'=>$config,'admin'=>$admin] as $type=>$data) { $f=fopen($root.'/config/'.$type.'.local.php','x');fwrite($f,'<?php return '.var_export($data,true).';');fclose($f); }
$cookie='';$checks=0;
function expect(bool $ok,string $why): void { global $checks;if(!$ok)throw new RuntimeException($why);$checks++; }
function request(string $path,?array $data=null): array {
    global $origin,$cookie;
    $c=curl_init($origin.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIE=>$cookie,CURLOPT_TIMEOUT=>15]);
    if($data!==null)curl_setopt($c,CURLOPT_POSTFIELDS,$data);
    $raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$size=curl_getinfo($c,CURLINFO_HEADER_SIZE);curl_close($c);
    return [$status,substr($raw,0,$size),substr($raw,$size)];
}
function identity(array $user,array $config): string {
    $id=bin2hex(random_bytes(16));$values=['user_id'=>$user['id'],'user_key'=>$user['session_key'],'started'=>time(),'last_seen'=>time(),'csrf'=>str_repeat('c',64)];
    $body='';foreach($values as $key=>$value)$body.=$key.'|'.serialize($value);
    if(!is_dir($config['storage'].'/sessions'))mkdir($config['storage'].'/sessions',0700,true);
    file_put_contents($config['storage'].'/sessions/sess_'.$id,$body);return 'tw_session='.$id;
}
try {
    $accounts=new AuthStore($config['storage'],$config['app_key']);$store=new AdminStore($admin);
    expect(request('/admin')[0]===401,'Anonymous panel denial');
    expect(request('/admin/save',['csrf'=>str_repeat('c',64)])[0]===401,'Anonymous mutation denial');
    $other=$accounts->signIn(['id'=>'444444444444444444','username'=>'tobais30']);$cookie=identity($other,$config);
    expect(request('/admin')[0]===403,'Username impersonation denied');
    expect(request('/admin/action',['csrf'=>str_repeat('c',64),'action'=>'deploy'])[0]===403,'Other user mutation denied');
    $owner=$accounts->signIn(['id'=>$admin['owner_id'],'username'=>'renamed-owner']);$cookie=identity($owner,$config);
    [$status,$headers,$html]=request('/admin');expect($status===200 && str_contains($html,'Create your next bot'),'Owner by stable ID');
    expect(str_contains($headers,'no-store'),'Panel cannot be cached');
    expect(str_contains(request('/account')[2],'/admin'),'Owner navigation');
    expect(request('/admin/save',['csrf'=>'wrong'])[0]===403,'CSRF denial');
    $zip=$dir.'/source.zip';$z=new ZipArchive();$z->open($zip,ZipArchive::CREATE);$z->addFromString('bot.py','print("hello")');$z->close();
    $data=['csrf'=>str_repeat('c',64),'name'=>'<b>Example</b>','runtime'=>'python','start_command'=>'python bot.py','install_command'=>'pip install requests','environment'=>'TOKEN=private-test-value','source'=>new CURLFile($zip,'application/zip','source.zip')];
    expect(request('/admin/save',$data)[0]===303,'Multipart upload saves bot');
    $bot=$store->query('SELECT * FROM bots')->fetch(PDO::FETCH_ASSOC);
    expect($bot['environment']==='TOKEN=private-test-value' && is_file($admin['storage'].'/uploads/'.$bot['source'].'.zip'),'Source and environment saved privately');
    $store->query('UPDATE bots SET logs=? WHERE id=?',['<script>alert(1)</script>',$bot['id']]);
    $html=request('/admin?bot='.$bot['id'])[2];
    expect(!str_contains($html,'private-test-value') && str_contains($html,'TOKEN'),'Secret values never rendered');
    expect(str_contains($html,'&lt;script&gt;') && str_contains($html,'&lt;b&gt;Example'),'Names and logs escaped');
    expect(request('/admin/action',['csrf'=>str_repeat('c',64),'id'=>$bot['id'],'action'=>'deploy'])[0]===303,'Deployment queued');
    expect($store->busy($bot['id']),'Queue persisted');
    expect(request('/admin/action',['csrf'=>str_repeat('c',64),'id'=>$bot['id'],'action'=>'stop'])[0]===409,'Duplicate action denied');
    $accounts->revoke($owner['id'],true);expect(request('/admin')[0]===403,'Blocked owner denied');
    echo "PASS: $checks admin HTTP access, CSRF, multipart upload, secret handling, escaping, and queue checks.\n";
} finally { foreach(['auth','admin'] as $type)unlink($root.'/config/'.$type.'.local.php'); }
