<?php
declare(strict_types=1);
foreach (['Support','Store','Session','DiscordClient','InstallGate','Interactions'] as $authFile) require_once __DIR__.'/'.$authFile.'.php';
