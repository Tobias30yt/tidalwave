<?php
declare(strict_types=1);
$defaults=['enabled'=>false,'url'=>'http://127.0.0.1:18080','domain'=>'tidlawaveenterprises.duckdns.org','domain_id'=>'','management_user'=>'','management_password'=>'','external_delivery'=>false];
return is_file(__DIR__.'/mail.local.php')?array_replace($defaults,require __DIR__.'/mail.local.php'):$defaults;
