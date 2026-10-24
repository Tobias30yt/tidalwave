<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Services/Auth/bootstrap.php';
require dirname(__DIR__).'/src/Services/AdminStore.php';
$checks=0;
function check(bool $condition,string $label): void { global $checks; if(!$condition) throw new RuntimeException($label); $checks++; }
function rejects(callable $fn,string $label): void { try { $fn(); } catch(AuthError) { check(true,$label); return; } throw new RuntimeException($label); }
$owner=['id'=>'123456789012345678','username'=>'tobais30','blocked'=>0];
$config=['owner_id'=>$owner['id'],'storage'=>__DIR__.'/output/admin-'.bin2hex(random_bytes(5)),'max_bots'=>4];
check(AdminStore::allowed($owner,$config),'Exact owner accepted');
check(!AdminStore::allowed($owner+[],['owner_id'=>'']),'Unset owner denied');
check(!AdminStore::allowed(array_replace($owner,['id'=>'987654321098765432']),$config),'Matching username does not grant access');
check(!AdminStore::allowed(array_replace($owner,['blocked'=>1]),$config),'Blocked owner denied');
check(!AdminStore::allowed(null,$config),'Anonymous denied');
check(AdminStore::environment("TOKEN=abc\r\n# comment\nX=hello world")==="TOKEN=abc\nX=hello world",'Environment normalized');
foreach(["TOKEN=a\nTOKEN=b","BAD KEY=x","PATH=/evil","LD_PRELOAD=evil","X=bad\0value"] as $bad) rejects(fn()=>AdminStore::environment($bad),'Invalid environment denied');
rejects(fn()=>AdminStore::fields(['name'=>'test','runtime'=>'node','start_command'=>'bash bad.sh']),'Wrong interpreter denied');
$store=new AdminStore($config);
$id=str_repeat('a',32);
$store->query('INSERT INTO bots(id,name,runtime,start_command,install_command,environment,source,updated_at,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$id,'Test','python','python bot.py','','TOKEN=keep',str_repeat('b',32),time(),time()]);
$store->save($id,['name'=>'Renamed','runtime'=>'python','start_command'=>'python bot.py','environment'=>''],null,$owner['id']);
check($store->bot($id)['environment']==='TOKEN=keep','Blank environment preserves secrets');
$store->enqueue($id,'deploy',$owner['id']);
rejects(fn()=>$store->enqueue($id,'stop',$owner['id']),'Concurrent bot jobs denied');
rejects(fn()=>$store->save($id,['name'=>'x','runtime'=>'node','start_command'=>'node a.js'],null,$owner['id']),'Busy bot edit denied');
rejects(fn()=>$store->enqueue($id,'shell',$owner['id']),'Unknown action denied');
check($store->query('SELECT count(*) FROM audit')->fetchColumn()===2,'Audit records changes');
foreach(['../escape.py','/absolute.py','dir/.env','dir/.git/config','dir\\escape.py'] as $name) {
    $path=$config['storage'].'/bad.zip'; $zip=new ZipArchive(); $zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE); $zip->addFromString($name,'test'); $zip->close();
    rejects(fn()=>AdminStore::validateZip($path),'Unsafe ZIP denied: '.$name);
}
$path=$config['storage'].'/link.zip';$zip=new ZipArchive();$zip->open($path,ZipArchive::CREATE);$zip->addFromString('link','/etc/passwd');$zip->setExternalAttributesName('link',ZipArchive::OPSYS_UNIX,0120777<<16);$zip->close();
rejects(fn()=>AdminStore::validateZip($path),'ZIP symlink denied');
echo "PASS: $checks admin authorization, environment, queue, audit, and upload checks.\n";
