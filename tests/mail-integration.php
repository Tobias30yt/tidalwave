<?php
declare(strict_types=1);
// Run on the VPS against a staging checkout. Only creates/removes its own random fixtures.
require dirname(__DIR__).'/src/Services/Auth/bootstrap.php';
require dirname(__DIR__).'/src/Services/AdminStore.php';
require dirname(__DIR__).'/src/Services/MailStore.php';
require dirname(__DIR__).'/src/Services/Webmail.php';
$config=array_replace(require dirname(__DIR__).'/config/mail.php',require '/var/www/tidalwave/config/mail.local.php');
$root=sys_get_temp_dir().'/tw-mail-test-'.bin2hex(random_bytes(5));$store=new AdminStore(['storage'=>$root]);$mail=new MailStore($store,$config,bin2hex(random_bytes(32)));
$suffix=bin2hex(random_bytes(4));$users=[['id'=>'11111111111111111','blocked'=>0],['id'=>'22222222222222222','blocked'=>0]];$created=[];$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo 'PASS '.$label."\n";}
try{
    foreach($users as$i=>$user){$mail->save($user,'qa-'.$suffix.'-'.$i,10,true);$created[]=$user['id'];}
    $a=$mail->get($users[0]['id']);$b=$mail->get($users[1]['id']);
    $wa=new Webmail($mail->client($a),$a['address'],false);$wb=new Webmail($mail->client($b),$b['address'],false);
    check($wa->id!==$wb->id,'individual JMAP accounts');
    $wa->call('Mailbox/set',['create'=>['qa'=>['name'=>'QA folder']]]);check(count($wa->folders())>count($wb->folders()),'folder isolation');
    $draft=$wa->compose(['to'=>$b['address'],'subject'=>'Tidalwave integration '.$suffix,'body'=>'Private test message '.$suffix],[],false);
    check(Webmail::text($wa->email($draft))==='Private test message '.$suffix,'draft saved and read');
    try{$wb->email($draft);throw new RuntimeException('Cross-account read succeeded');}catch(AuthError $e){check($e->status===404,'cross-account message denied');}
    $sent=$wa->compose(['draft'=>$draft,'to'=>$b['address'],'subject'=>'Tidalwave integration '.$suffix,'body'=>'Private test message '.$suffix],[],true);
    $received=[];for($i=0;$i<15;$i++){[$received]=$wb->list($wb->folder($wb->folders(),'inbox'),$suffix,1);if($received)break;sleep(1);}
    check(count($received)===1,'actual local mail submission and delivery');
    check(isset($wa->email($sent)['mailboxIds'][$wa->folder($wa->folders(),'sent')]),'sent folder after submission');
    $receivedId=$received[0]['id'];$wb->move($receivedId,$wb->folder($wb->folders(),'trash'));check(isset($wb->email($receivedId)['mailboxIds'][$wb->folder($wb->folders(),'trash')]),'trash move');
    try{$wa->compose(['to'=>'nobody@example.com','subject'=>'blocked test','body'=>'must not send'],[],true);throw new RuntimeException('External send not blocked');}catch(AuthError $e){check($e->status===409,'external sending restricted pending verification');}
    $mail->disable($users[0]['id']);try{$mail->client($mail->get($users[0]['id']));throw new RuntimeException('Disabled access allowed');}catch(AuthError $e){check($e->status===403,'disabled website access');}
    try{$wa->client->session();throw new RuntimeException('Disabled credential allowed');}catch(AuthError $e){check(true,'disabled Stalwart credential rejected');}
    $mail->save($users[0],'qa-'.$suffix.'-0',20,true);check((int)$mail->get($users[0]['id'])['quota_mb']===20,'quota and re-enable');
    $manager=Stalwart::manager($config);try{$manager->call('Email/get',['accountId'=>$wb->id]);throw new RuntimeException('Manager read mail');}catch(AuthError $e){check(true,'management identity cannot read mail');}
    echo "Completed $checks mail integration checks.\n";
}finally{foreach($created as$id){try{$mail->remove($id);}catch(Throwable $e){echo 'Fixture cleanup required: '.$suffix.' '.$id."\n";}}}
