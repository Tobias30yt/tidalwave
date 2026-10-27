<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Services/Auth/bootstrap.php';require dirname(__DIR__).'/src/Services/AdminStore.php';require dirname(__DIR__).'/src/Services/MailStore.php';require dirname(__DIR__).'/src/Services/Webmail.php';
$root=dirname(__DIR__);$origin=$argv[1]??'http://127.0.0.1:8096';if(!preg_match('~^http://127\.0\.0\.1:[0-9]{4,5}$~D',$origin))throw new RuntimeException('Loopback required');
foreach(['auth','admin','mail']as$type)if(file_exists($root.'/config/'.$type.'.local.php'))throw new RuntimeException('Refusing existing test configuration');
$dir=__DIR__.'/output/mail-http-'.bin2hex(random_bytes(4));$auth=['enabled'=>true,'origin'=>$origin,'allow_local_http'=>true,'storage'=>$dir.'/auth','app_key'=>bin2hex(random_bytes(32)),'public_key'=>bin2hex(random_bytes(32)),'client_id'=>'111111111111111111','guild_id'=>'222222222222222222','client_secret'=>'fixture','bot_token'=>'fixture'];$admin=['owner_id'=>'333333333333333333','storage'=>$dir.'/admin'];$config=array_replace(require $root.'/config/mail.php',require '/var/www/tidalwave/config/mail.local.php');
foreach(['auth'=>$auth,'admin'=>$admin,'mail'=>$config]as$type=>$c){$f=fopen($root.'/config/'.$type.'.local.php','x');fwrite($f,'<?php return '.var_export($c,true).';');fclose($f);}
$cookie='';$checks=0;$created=[];$suffix=bin2hex(random_bytes(4));
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS '.$label."\n";}
function request(string $path,?array $post=null,array $headers=[],?string $raw=null):array{global $origin,$cookie;$ch=curl_init($origin.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIE=>$cookie,CURLOPT_TIMEOUT=>40,CURLOPT_HTTPHEADER=>$headers]);if($post!==null||$raw!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$raw??$post);$r=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$n=curl_getinfo($ch,CURLINFO_HEADER_SIZE);curl_close($ch);return[$status,substr($r,0,$n),substr($r,$n)];}
function identity(array $u):string{global $auth;$id=bin2hex(random_bytes(16));$body='';foreach(['user_id'=>$u['id'],'user_key'=>$u['session_key'],'started'=>time(),'last_seen'=>time(),'csrf'=>str_repeat('c',64)]as$k=>$v)$body.=$k.'|'.serialize($v);if(!is_dir($auth['storage'].'/sessions'))mkdir($auth['storage'].'/sessions',0700,true);file_put_contents($auth['storage'].'/sessions/sess_'.$id,$body);return'tw_session='.$id;}
try{
    $accounts=new AuthStore($auth['storage'],$auth['app_key']);$store=new AdminStore($admin);$mail=new MailStore($store,$config,$auth['app_key']);
    check(request('/mail')[0]===401,'anonymous mail denied');check(request('/admin/mail')[0]===401,'anonymous mail management denied');
    $a=$accounts->signIn(['id'=>$admin['owner_id'],'username'=>'owner']);$b=$accounts->signIn(['id'=>'444444444444444444','username'=>'tobais30']);$cookie=identity($b);
    check(request('/mail')[0]===403,'unapproved user denied');check(request('/admin/mail')[0]===403,'username impersonation denied');check(request('/admin/api')[0]===403,'API administration owner-only');
    foreach([$a,$b]as$i=>$u){$mail->save($u,'qa-http-'.$suffix.'-'.$i,10,true);$created[]=$u['id'];}
    $cookie=identity($a);check(request('/admin/mail')[0]===200,'owner mail administration');check(request('/admin/api')[0]===200,'owner API administration');
    check(request('/mail')[0]===200,'approved mailbox dashboard');check(request('/mail/folder',['csrf'=>'wrong','name'=>'injected'])[0]===403,'mail CSRF denied');
    $attachment=$dir.'/attachment.txt';file_put_contents($attachment,'Private attachment '.$suffix);
    $post=['csrf'=>str_repeat('c',64),'action'=>'draft','to'=>$mail->get($b['id'])['address'],'subject'=>'<script>attack</script>','body'=>'<img src=x onerror=alert(1)>','attachments[0]'=>new CURLFile($attachment,'text/plain','proof.txt')];
    [$status,$headers]=request('/mail/compose',$post);check($status===303,'multipart attachment draft saved');preg_match('/Location: (.+)\r?\n/i',$headers,$m);$link=trim($m[1]);
    [$status,$headers,$html]=request($link);check($status===200&&str_contains($html,'&lt;script&gt;')&&!str_contains($html,'<script>attack'),'email subject and body escaped');check(str_contains($headers,'no-store'),'mail response private');
    preg_match('~href="(/mail/download[^"]+)"~',$html,$m);$download=html_entity_decode($m[1]);[$status,$headers,$bytes]=request($download);check($status===200&&$bytes==='Private attachment '.$suffix,'owned attachment download');check(str_contains($headers,'attachment;')&&str_contains($headers,'sandbox'),'download forces safe attachment');
    $cookie=identity($b);check(request($link)[0]===404,'foreign message HTTP denied');check(request($download)[0]===404,'foreign attachment HTTP denied');
    $accounts->revoke($b['id'],true);check(request('/mail')[0]===401,'blocked account session denied');
    $cookie=identity($a);[$status,$headers,$yaml]=request('/admin/api/register',['csrf'=>str_repeat('c',64),'name'=>'Fixture Reports']);check($status===200&&str_contains($headers,'attachment;'),'API config download');preg_match('/tw_reports_[a-f0-9]{64}/',$yaml,$m);$key=$m[0];
    check(request('/api/reports/v1/events',null,['Content-Type: application/json'],'{"events":[]}')[0]===401,'unauthenticated moderation write denied');
    $event=json_encode(['events'=>[['sequence'=>1,'entity'=>'sanction','id'=>'uuid:BAN','data'=>['reason'=>'fixture']]]]);
    [$status,,$body]=request('/api/reports/v1/events',null,['Content-Type: application/json','Authorization: Bearer '.$key],$event);check($status===200&&json_decode($body,true)['acknowledged']===1,'authenticated moderation write');
    check(request('/admin/mail/delete',['csrf'=>str_repeat('c',64),'user_id'=>$a['id'],'confirm'=>'no'])[0]===400,'mail deletion requires exact confirmation');
    echo "$checks mail/API HTTP checks passed.\n";
}finally{foreach($created as$id){try{$mail->remove($id);}catch(Throwable){echo 'Fixture cleanup required '.$suffix."\n";}}foreach(['auth','admin','mail']as$type)unlink($root.'/config/'.$type.'.local.php');}
