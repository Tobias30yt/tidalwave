<?php
$navigationAuth=require dirname(__DIR__,2).'/config/auth.php';
$navigationConfig=require dirname(__DIR__,2).'/config/admin.php';
$navigationAccounts=new AuthStore($navigationAuth['storage'],$navigationAuth['app_key']);
$navigationUser=isset($_SESSION['user_id'])?$navigationAccounts->user($_SESSION['user_id']):null;
if($navigationUser&&!hash_equals($navigationUser['session_key'],$_SESSION['user_key']??''))$navigationUser=null;
?>
<nav class="container admin-tabs" aria-label="Administration">
<?php foreach(['bots'=>['/admin','Bots'],'projects'=>['/admin/projects','Projects'],'users'=>['/admin/users','Users'],'api'=>['/admin/api','Reports API'],'mail'=>['/admin/mail','Mail']] as $area=>[$url,$label]): ?>
<?php if(AdminStore::access($navigationUser,$navigationConfig,$area)): ?><a href="<?= $url ?>" <?= $path===$url?'aria-current="page"':'' ?>><?= $label ?></a><?php endif ?>
<?php endforeach ?><a href="/account">Your account</a><a href="/forum">Forum ↗</a></nav>
