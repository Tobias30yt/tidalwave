<?php
require dirname(__DIR__).'/src/Services/Auth/bootstrap.php';
require dirname(__DIR__).'/src/Services/AdminStore.php';
require dirname(__DIR__).'/src/Services/ProjectStore.php';
require dirname(__DIR__).'/src/Services/ForumStore.php';
$checks=0;
function check(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function denied(callable $f): void {try{$f();}catch(AuthError){check(true,'Denied');return;}throw new RuntimeException('Expected denial');}
foreach(['http://github.com/a/b','https://github.com.evil/a/b','https://github.com/a/../secret','https://user:pass@github.com/a/b','https://127.0.0.1/a/b','https://github.com/a/b?x=1','https://github.com/a/..'] as $url)denied(fn()=>GitHubProject::repository($url));
check(GitHubProject::repository('https://github.com/Tobias30yt/tidalwave.git')==='Tobias30yt/tidalwave','GitHub canonical repository');
$config=['client_id'=>'111111111111111111','guild_id'=>'222222222222222222','origin'=>'https://example.invalid','verification_lifetime'=>2592000];
$store=new AuthStore(__DIR__.'/output/community-'.bin2hex(random_bytes(5)),str_repeat('a',64));
$a='333333333333333333';$b='444444444444444444';$store->signIn(['id'=>$a,'username'=>'author']);$store->signIn(['id'=>$b,'username'=>'other']);
$handler=new DiscordInteractions($store,$config);
$payload=['id'=>'555555555555555555','application_id'=>$config['client_id'],'type'=>2,'guild_id'=>$config['guild_id'],'member'=>['user'=>['id'=>$a]],'data'=>['name'=>'verify']];
$reply=$handler->handle($payload);check($store->verified($store->user($a),$config),'Code-free verification');check($reply['data']['flags']===64,'Private command response');
$store->revoke($a);$handler->handle($payload);check(!$store->verified($store->user($a),$config),'Replay cannot reverify');
$wrong=$payload;$wrong['id']='666666666666666666';$wrong['guild_id']=$b;$handler->handle($wrong);check(!$store->verified($store->user($a),$config),'Wrong server cannot verify');
$store->revoke($a,true);$payload['id']='777777777777777777';$handler->handle($payload);check(!$store->verified($store->user($a),$config),'Blocked identity cannot verify');
$store->revoke($a,false);$forum=new ForumStore($store);
$thread=$forum->create($a,'Question about a bot','Here are enough details.');$forum->reply($thread,$b,'Useful answer');
denied(fn()=>$forum->moderate($thread,$b,false,'delete'));
denied(fn()=>$forum->moderate($thread,$a,false,'lock'));
$forum->moderate($thread,$a,true,'lock');denied(fn()=>$forum->reply($thread,$b,'Locked answer'));
$forum->moderate($thread,$a,false,'solve');check((int)$forum->thread($thread)['solved']===1,'Author can mark solved');
check($forum->listing('Question',1)['total']===1,'Forum search');check($forum->listing('%',1)['total']===0,'Search wildcards are literal');
$store->query('DELETE FROM users WHERE id=?',[$a]);check($forum->thread($thread)['author_id']===null,'Deleted authors anonymized');
echo "PASS: $checks GitHub URL, code-free verification, replay, forum authorization, and account-deletion checks.\n";
