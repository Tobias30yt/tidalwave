<?php
declare(strict_types=1);
/** Every webmail call uses the individual mailbox credential, never the manager. */
final class Stalwart {
    public function __construct(private array $config,private string $username,private string $password) {
        if(!$config['enabled'])throw new AuthError(503,'Tidalwave Mail is not configured yet.');
        $url=parse_url($config['url']);
        if(($url['host']??'')!=='127.0.0.1'||($url['scheme']??'')!=='http'||($url['port']??0)!==18080)throw new AuthError(503,'Invalid private mail endpoint.');
    }
    public static function manager(array $config):self{return new self($config,$config['management_user'],$config['management_password']);}
    public function request(string $path,?string $body=null,string $type='application/json',int $limit=12582912):array {
        if(!str_starts_with($path,'/')||str_contains($path,'..')||str_contains($path,"\r")||str_contains($path,"\n"))throw new AuthError(400,'Invalid mail request.');
        $ch=curl_init($this->config['url'].$path);$response='';
        curl_setopt_array($ch,[CURLOPT_USERPWD=>$this->username.':'.$this->password,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>18,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP,CURLOPT_HTTPHEADER=>['Content-Type: '.$type],CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$response,$limit){if(strlen($response)+strlen($chunk)>$limit)return 0;$response.=$chunk;return strlen($chunk);}]);
        if($body!==null)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body]);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        if($ok===false||$status<200||$status>=300){error_log('Private mail HTTP status '.$status);throw new AuthError(502,'The mailbox service could not complete this request. Please retry.');}
        return [$response,$status];
    }
    public function session():array{return json_decode($this->request('/jmap/session')[0],true,64,JSON_THROW_ON_ERROR);}
    public function call(string $method,array $args,bool $management=false):array {
        $using=['urn:ietf:params:jmap:core'];
        if($management)$using[]='urn:stalwart:jmap';else{$using[]='urn:ietf:params:jmap:mail';$using[]='urn:ietf:params:jmap:submission';}
        $body=json_encode(['using'=>$using,'methodCalls'=>[[$method,(object)$args,'c1']]],JSON_THROW_ON_ERROR);
        $response=json_decode($this->request('/jmap/',$body)[0],true,64,JSON_THROW_ON_ERROR);
        $call=$response['methodResponses'][0]??[];$result=$call[1]??[];
        if(($call[0]??'')!==$method||isset($result['notCreated'])&&$result['notCreated']||isset($result['notUpdated'])&&$result['notUpdated']||isset($result['notDestroyed'])&&$result['notDestroyed']){
            error_log('Mail operation failed: '.$method.' '.preg_replace('/[^a-zA-Z]/','',(string)($result['type']??array_values($result['notCreated']??$result['notUpdated']??$result['notDestroyed']??[])[0]['type']??'unknown')));
            throw new AuthError(422,'Mail operation rejected. Check the address, available quota and selected item.');
        }
        return $result;
    }
}
