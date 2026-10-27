<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Services/Auth/bootstrap.php';require dirname(__DIR__).'/src/Services/AdminStore.php';require dirname(__DIR__).'/src/Services/ReportsStore.php';
$s=new AdminStore(['storage'=>__DIR__.'/output/reports-'.bin2hex(random_bytes(5))]);$r=new ReportsStore($s);$checks=0;
function expect(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$a=$r->register('Reports A');$b=$r->register('Reports B');$ca=$r->authenticate('Bearer '.$a['key']);$cb=$r->authenticate('Bearer '.$b['key']);
expect(!str_contains(json_encode($s->query('SELECT * FROM api_clients')->fetchAll()),$a['key']),'Keys hashed');
$events=[['sequence'=>1,'entity'=>'sanction','id'=>'uuid:MUTE','data'=>['reason'=>'<script>private</script>','expires_at'=>time()+500]]];
expect($r->ingest($ca,$events)===1,'First event');expect($r->ingest($ca,$events)===1,'Idempotent retry');expect((int)$s->query('SELECT count(*) FROM reports_records')->fetchColumn()===1,'Retry does not duplicate');
$events[0]['data']['reason']='Separate server';$r->ingest($cb,$events);expect((int)$s->query('SELECT count(*) FROM reports_records')->fetchColumn()===2,'Server isolation');
$r->ingest($ca,[['sequence'=>2,'entity'=>'sanction','id'=>'uuid:MUTE','data'=>null]]);expect($s->query('SELECT client_id FROM reports_records')->fetchColumn()===$b['id'],'Scoped deletion');
try{$r->ingest($cb,[['sequence'=>2,'entity'=>'report','id'=>'1','data'=>['reason'=>'valid']],['sequence'=>3,'entity'=>'bad','id'=>'2','data'=>null]]);throw new RuntimeException('Invalid batch accepted');}catch(AuthError $e){expect((int)$s->query('SELECT sequence FROM api_clients WHERE id=?',[$b['id']])->fetchColumn()===1,'Batch rollback');}
$r->register('Reports A',$a['id']);try{$r->authenticate('Bearer '.$a['key']);throw new RuntimeException('Old key accepted');}catch(AuthError $e){expect($e->status===401,'Rotation revokes old key');}
$s->query('UPDATE api_clients SET enabled=0 WHERE id=?',[$b['id']]);try{$r->authenticate('Bearer '.$b['key']);throw new RuntimeException('Disabled key accepted');}catch(AuthError $e){expect($e->status===401,'Disable revokes key');}
echo "$checks Reports API checks passed.\n";
