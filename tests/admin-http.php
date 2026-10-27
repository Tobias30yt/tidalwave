<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Services/Auth/bootstrap.php';
require dirname(__DIR__).'/src/Services/AdminStore.php';
require dirname(__DIR__).'/src/Services/ReportsStore.php';
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
    // Bot records are unlimited; only deployed bots enter the public catalog.
    for($i=0;$i<5;$i++)expect(request('/admin/save',$data)[0]===303,'More than four bots allowed');
    expect(count($store->publicBots())===0,'Draft bots are private');
    $store->query('UPDATE bots SET published_at=?,description=?,status=? WHERE id=?',[time(),'A public test bot','running',$bot['id']]);
    $html=request('/bots')[2];expect(str_contains($html,'A public test bot') && !str_contains($html,'private-test-value'),'Hosted catalog publishes only public fields');
    // Projects support private drafts, source and release downloads, and removal.
    $projectData=['csrf'=>str_repeat('c',64),'name'=>'Sample Project','description'=>'<script>unsafe</script>','technologies'=>'Python, SQLite','version'=>'v1',
        'source'=>new CURLFile($zip,'application/zip','source.zip'),'release'=>new CURLFile($zip,'application/zip','release.zip')];
    expect(request('/admin/projects/save',$projectData)[0]===303,'Project uploads saved');
    $project=$store->query('SELECT * FROM projects')->fetch(PDO::FETCH_ASSOC);$files=$store->query('SELECT * FROM project_files')->fetchAll(PDO::FETCH_ASSOC);
    expect(count($files)===2 && request('/projects/download/'.$files[0]['id'])[0]===404,'Draft downloads blocked');
    $projectData=['csrf'=>str_repeat('c',64),'id'=>$project['id'],'name'=>'Sample Project','description'=>'<script>unsafe</script>','published'=>'1'];
    expect(request('/admin/projects/save',$projectData)[0]===303,'Project published');
    $html=request('/projects')[2];expect(str_contains($html,'Sample Project') && str_contains($html,'&lt;script&gt;'),'Public project HTML escaped');
    [$status,$headers,$binary]=request('/projects/download/'.$files[0]['id']);
    expect($status===200 && $binary===file_get_contents($zip) && str_contains($headers,'attachment;'),'Exact file attachment download');
    unset($projectData['published']);request('/admin/projects/save',$projectData);
    expect(request('/projects/download/'.$files[0]['id'])[0]===404,'Unpublishing revokes direct downloads');
    expect(request('/admin/projects/delete',['csrf'=>str_repeat('c',64),'id'=>$project['id'],'confirm'=>'delete'])[0]===303,'Project deletion');
    expect(!is_file($admin['storage'].'/project-files/'.$files[0]['id'].'.bin'),'Project files removed');
    // Community questions, replies, permissions, and account administration.
    $cookie=identity($other,$config);
    expect(request('/admin/users')[0]===403 && request('/admin/projects')[0]===403,'Owner-only new admin pages');
    expect(request('/forum/create',['csrf'=>'wrong','title'=>'A question','body'=>'Details here.'])[0]===403,'Forum CSRF denied');
    expect(request('/forum/create',['csrf'=>str_repeat('c',64),'title'=>'How do I run this bot?','body'=>'<script>alert(1)</script> These are the details.'])[0]===303,'Question created');
    $thread=(int)$accounts->query('SELECT id FROM forum_threads')->fetchColumn();
    expect(str_contains(request('/forum/thread/'.$thread)[2],'&lt;script&gt;'),'Forum content escaped');
    $cookie=identity($owner,$config);
    expect(request('/forum/reply',['csrf'=>str_repeat('c',64),'thread'=>$thread,'body'=>'Here is an answer.'])[0]===303,'Forum reply');
    expect(request('/forum/moderate',['csrf'=>str_repeat('c',64),'thread'=>$thread,'action'=>'lock'])[0]===303,'Administrator locks thread');
    $cookie=identity($other,$config);
    expect(request('/forum/reply',['csrf'=>str_repeat('c',64),'thread'=>$thread,'body'=>'Another answer.'])[0]===403,'Locked thread rejects replies');
    expect(request('/forum/moderate',['csrf'=>str_repeat('c',64),'thread'=>$thread,'action'=>'lock'])[0]===403,'Author cannot override administrator lock');
    $cookie=identity($owner,$config);
    expect(request('/admin/users/action',['csrf'=>str_repeat('c',64),'user_id'=>$owner['id'],'action'=>'block'])[0]===403,'Administrator self-block denied');
    $csrf=str_repeat('c',64);
    $grant=['csrf'=>$csrf,'user_id'=>$other['id'],'action'=>'permissions','page_admin'=>'1','api_access'=>'1'];
    expect(request('/admin/users/action',array_replace($grant,['csrf'=>'bad']))[0]===403,'Permission changes require CSRF');
    expect(request('/admin/users/action',array_replace($grant,['page_admin'=>'0']))[0]===400,'API requires page administrator');
    expect(request('/admin/users/action',$grant)[0]===303,'Owner grants API administration');
    $reports=new ReportsStore($store);$client=$reports->register('Test server');$client2=$reports->register('Second server');
    $cookie=identity($other,$config);
    expect(request('/admin/api')[0]===200 && request('/admin/projects')[0]===200,'Delegate can access assigned areas');
    expect(str_contains(request('/account')[2],'/admin/api'),'Delegate account links to API');
    foreach(['/admin','/admin/users','/admin/mail'] as $protected)expect(request($protected)[0]===403,'Delegate denied '.$protected);
    expect(request('/admin/users/action',$grant)[0]===403,'Delegate cannot grant privileges');
    expect(request('/admin/api/register',['csrf'=>$csrf,'name'=>'forbidden'])[0]===403,'Delegate cannot issue API credentials');
    expect(request('/admin/invitation',['csrf'=>$csrf,'id'=>$bot['id']])[0]===403,'Delegate cannot change bot invitations');
    $record=['csrf'=>$csrf,'client_id'=>$client['id'],'entity'=>'sanction','record_id'=>'player:MUTE','version'=>'new','data'=>'{"reason":"<script>unsafe</script>","expires_at":null}'];
    expect(request('/admin/api/record/save',array_replace($record,['csrf'=>'bad']))[0]===403,'Record CSRF');
    expect(request('/admin/api/record/save',array_replace($record,['data'=>'[]']))[0]===400,'Reject invalid record JSON');
    expect(request('/admin/api/record/save',$record)[0]===303,'Delegate creates record');
    expect(request('/admin/api/record/save',$record)[0]===409,'Create cannot overwrite existing record');
    expect(str_contains(request('/admin/api?q=player')[2],'&lt;script&gt;unsafe'),'Record editor escapes HTML');
    $row=$store->query('SELECT * FROM reports_records WHERE client_id=?',[$client['id']])->fetch(PDO::FETCH_ASSOC);
    $record['version']=ReportsStore::version($row);$record['data']='{"reason":"Edited"}';
    expect(request('/admin/api/record/save',$record)[0]===303,'Delegate edits record');
    expect(request('/admin/api/record/save',$record)[0]===409,'Stale edit rejected');
    expect((int)$store->query('SELECT sequence FROM api_clients WHERE id=?',[$client['id']])->fetchColumn()===0,'Website edits preserve plugin sync cursor');
    $reports->edit($client2['id'],'sanction','player:MUTE','{"reason":"Other server"}','new',$owner['id']);
    $row=$store->query('SELECT * FROM reports_records WHERE client_id=?',[$client['id']])->fetch(PDO::FETCH_ASSOC);$record['version']=ReportsStore::version($row);
    expect(request('/admin/api/record/delete',$record)[0]===400,'Deletion requires confirmation');
    expect(request('/admin/api/record/delete',$record+['confirm'=>'delete'])[0]===303,'Delegate deletes record');
    expect((int)$store->query('SELECT count(*) FROM reports_records')->fetchColumn()===1,'Deletion keeps other server records');
    $cookie=identity($owner,$config);
    expect(request('/admin/users/action',array_replace($grant,['api_access'=>'0']))[0]===303,'Owner revokes API access');
    $cookie=identity($other,$config);
    expect(request('/admin/api')[0]===403 && request('/admin/api/record/save',$record)[0]===403,'Revocation applies immediately to existing session');
    $cookie=identity($owner,$config);
    expect(request('/admin/users/action',$grant)[0]===303,'Regrant for deletion cascade check');
    expect(request('/admin/users/action',['csrf'=>str_repeat('c',64),'user_id'=>$other['id'],'action'=>'block'])[0]===303,'User blocked');
    $cookie=identity($other,$config);expect(request('/forum/create',['csrf'=>str_repeat('c',64),'title'=>'New question','body'=>'Blocked user text.'])[0]===401,'Blocked session cannot post');
    $cookie=identity($owner,$config);
    expect(request('/admin/users/action',['csrf'=>str_repeat('c',64),'user_id'=>$other['id'],'action'=>'delete','confirm'=>'delete'])[0]===303,'User deleted');
    expect($accounts->user($other['id'])===null && $accounts->query('SELECT author_id FROM forum_threads WHERE id=?',[$thread])->fetchColumn()===null,'Deletion anonymizes contributions');
    expect((bool)$accounts->query('SELECT 1 FROM blocked_accounts WHERE id=?',[$other['id']])->fetchColumn(),'Deletion preserves block');
    expect(!$accounts->query('SELECT 1 FROM account_permissions WHERE user_id=?',[$other['id']])->fetchColumn(),'Deleting account removes delegated privileges');
    expect(request('/admin/users/action',['csrf'=>str_repeat('c',64),'user_id'=>$other['id'],'action'=>'unblock'])[0]===303,'Deleted blocked identity can be unblocked');
    expect(request('/forum/moderate',['csrf'=>str_repeat('c',64),'thread'=>$thread,'action'=>'delete','confirm'=>'delete'])[0]===303,'Moderator removes question');
    expect(request('/forum/thread/'.$thread)[0]===404 && (int)$accounts->query('SELECT count(*) FROM forum_replies')->fetchColumn()===0,'Replies removed with question');
    $accounts->revoke($owner['id'],true);expect(request('/admin')[0]===401,'Blocking invalidates existing sessions');
    echo "PASS: $checks admin HTTP access, CSRF, multipart upload, secret handling, escaping, and queue checks.\n";
} finally { foreach(['auth','admin'] as $type)unlink($root.'/config/'.$type.'.local.php'); }
