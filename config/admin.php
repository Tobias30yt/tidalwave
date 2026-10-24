<?php
declare(strict_types=1);
$defaults=['owner_id'=>'','storage'=>dirname(__DIR__).'/storage/admin'];
$local=__DIR__.'/admin.local.php';
return is_file($local) ? array_replace($defaults,require $local) : $defaults;
