<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Services/ReportsStore.php';
final class ReportsApiController {
    public static function dispatch(string $path):bool {
        if(!str_starts_with($path,'/api/reports/'))return false;
        header('Content-Type: application/json');header('Cache-Control: no-store');
        try {
            $auth=require dirname(__DIR__,2).'/config/auth.php';auth_configure($auth);
            $origin=parse_url($auth['origin']);
            $host=$origin['host'].(isset($origin['port'])?':'.$origin['port']:'');
            if(strcasecmp($_SERVER['HTTP_HOST']??'',$host)!==0 || ($origin['scheme']==='https' && (empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off')))throw new AuthError(400,'Use the HTTPS API origin.');
            if($path!=='/api/reports/v1/events')throw new AuthError(404,'Unknown endpoint.');
            if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){header('Allow: POST');throw new AuthError(405,'POST required.');}
            if(!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json'))throw new AuthError(415,'JSON required.');
            $accounts=new AuthStore($auth['storage'],$auth['app_key']);$accounts->limit('reports-ip:'.($_SERVER['REMOTE_ADDR']??''),180,60);
            $store=new ReportsStore(new AdminStore(require dirname(__DIR__,2).'/config/admin.php'));
            $client=$store->authenticate($_SERVER['HTTP_AUTHORIZATION']??'');$accounts->limit('reports-client:'.$client['id'],120,60);
            $body=file_get_contents('php://input',false,null,0,524289);
            if(strlen($body)>524288)throw new AuthError(413,'Batch exceeds 512 KB.');
            try{$payload=json_decode($body,true,32,JSON_THROW_ON_ERROR);}catch(JsonException){throw new AuthError(400,'Invalid JSON.');}
            if(!is_array($payload)||!is_array($payload['events']??null))throw new AuthError(400,'events array required.');
            echo json_encode(['acknowledged'=>$store->ingest($client,$payload['events'])]);
        }catch(AuthError $e){http_response_code($e->status);echo json_encode(['error'=>$e->getMessage()]);}
        return true;
    }
}
