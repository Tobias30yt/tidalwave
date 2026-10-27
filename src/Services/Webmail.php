<?php
declare(strict_types=1);
final class Webmail {
    public readonly string $id;
    public function __construct(public readonly Stalwart $client,public readonly string $address,private bool $external){
        $session=$client->session();$this->id=$session['primaryAccounts']['urn:ietf:params:jmap:mail']??throw new AuthError(503,'Mailbox is not ready.');
    }
    public function call(string $method,array $args=[]):array{return $this->client->call($method,array_merge($args,['accountId'=>$this->id]));}
    public function folders():array{return $this->call('Mailbox/get')['list'];}
    public function folder(array $folders,string $role):string{foreach($folders as $f)if(($f['role']??null)===$role)return $f['id'];throw new AuthError(503,'Required mailbox folder is missing.');}
    public function list(string $folder,string $search,int $page):array{
        $filter=['inMailbox'=>$folder];if($search!=='')$filter['text']=mb_substr($search,0,200);
        $query=$this->call('Email/query',['filter'=>$filter,'sort'=>[['property'=>'receivedAt','isAscending'=>false]],'position'=>($page-1)*30,'limit'=>30,'calculateTotal'=>true]);
        $emails=$query['ids']?$this->call('Email/get',['ids'=>$query['ids'],'properties'=>['id','subject','from','to','receivedAt','preview','keywords','hasAttachment']])['list']:[];
        return [$emails,$query['total']??count($emails)];
    }
    public function email(string $id):array{
        if(!preg_match('/^[A-Za-z0-9_-]{1,200}$/D',$id))throw new AuthError(404,'Message not found.');
        return $this->call('Email/get',['ids'=>[$id],'fetchTextBodyValues'=>true,'fetchHTMLBodyValues'=>true,'maxBodyValueBytes'=>200000,'properties'=>['id','subject','from','to','cc','receivedAt','keywords','mailboxIds','textBody','htmlBody','bodyValues','attachments']])['list'][0]??throw new AuthError(404,'Message not found.');
    }
    public static function text(array $email):string{
        $parts=$email['textBody']?:$email['htmlBody'];$result=[];
        foreach($parts as $part){$value=$email['bodyValues'][$part['partId']]['value']??'';$result[]=empty($email['textBody'])?html_entity_decode(strip_tags($value),ENT_QUOTES|ENT_HTML5,'UTF-8'):$value;}
        return implode("\n",$result);
    }
    public function move(string $id,string $folder):void{$this->email($id);$this->call('Email/set',['update'=>[$id=>['mailboxIds'=>[$folder=>true]]]]);}
    public function read(string $id):void{$this->call('Email/set',['update'=>[$id=>['keywords/$seen'=>true]]]);}
    public function addresses(string $input,bool $required=false):array{
        $out=[];foreach(explode(',',$input) as $address){$address=trim($address);if($address==='')continue;if(!filter_var($address,FILTER_VALIDATE_EMAIL)||strlen($address)>254)throw new AuthError(400,'Enter comma-separated email addresses.');$out[]=['email'=>$address];}
        if(count($out)>20||($required&&!$out))throw new AuthError(400,'Use 1–20 recipients.');return $out;
    }
    public function compose(array $input,array $files,bool $send):string{
        $to=$this->addresses((string)($input['to']??''),$send);$cc=$this->addresses((string)($input['cc']??''));
        if($send&&!$this->external){$domain=substr(strrchr($this->address,'@'),1);foreach(array_merge($to,$cc)as$r)if(strcasecmp(substr(strrchr($r['email'],'@'),1),$domain)!==0)throw new AuthError(409,'External delivery is not enabled yet. You can save this message as a draft or send to another Tidalwave mailbox.');}
        $subject=trim((string)($input['subject']??''));$body=(string)($input['body']??'');
        if(strlen($subject)>300||str_contains($subject,"\r")||str_contains($subject,"\n")||strlen($body)>200000)throw new AuthError(400,'Subject or message is too long.');
        $folders=$this->folders();$draft=$this->folder($folders,'drafts');$attachments=[];$old=null;
        if(!empty($input['draft'])){$old=$this->email((string)$input['draft']);if(empty($old['keywords']['$draft']))throw new AuthError(400,'This message is not a draft.');foreach($old['attachments']??[] as $a)$attachments[]=array_intersect_key($a,array_flip(['blobId','type','name','disposition']));}
        if(isset($files['tmp_name'])&&is_array($files['tmp_name']))foreach($files['tmp_name'] as $i=>$tmp){
            if(($files['error'][$i]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;
            if(($files['error'][$i]??1)!==UPLOAD_ERR_OK||!is_uploaded_file($tmp)||filesize($tmp)>5242880)throw new AuthError(400,'Each attachment must be at most 5 MB.');
            if(count($attachments)>=5)throw new AuthError(400,'At most five attachments per message.');
            $name=mb_substr(preg_replace('~[\x00-\x1f\x7f]~u','_',str_replace(['\\','/'],'_',basename((string)$files['name'][$i]))),0,150);
            $upload=json_decode($this->client->request('/jmap/upload/'.rawurlencode($this->id).'/',file_get_contents($tmp),'application/octet-stream')[0],true,32,JSON_THROW_ON_ERROR);
            $attachments[]=['blobId'=>$upload['blobId'],'type'=>'application/octet-stream','name'=>$name?:'attachment','disposition'=>'attachment'];
        }
        $email=['mailboxIds'=>[$draft=>true],'keywords'=>['$draft'=>true],'from'=>[['email'=>$this->address]],'to'=>$to,'cc'=>$cc,'subject'=>$subject,'textBody'=>[['partId'=>'body','type'=>'text/plain']],'bodyValues'=>['body'=>['value'=>$body]],'attachments'=>$attachments];
        $created=$this->call('Email/set',['create'=>['draft'=>$email]]);$id=$created['created']['draft']['id'];
        if($old)$this->move($old['id'],$this->folder($folders,'trash'));
        if($send){
            $identities=$this->call('Identity/get')['list'];$identity=null;foreach($identities as $i)if(strcasecmp($i['email'],$this->address)===0)$identity=$i['id'];
            if(!$identity)throw new AuthError(503,'Sending identity is unavailable. The draft has been saved.');
            $sent=$this->folder($folders,'sent');
            $this->call('EmailSubmission/set',['create'=>['send'=>['emailId'=>$id,'identityId'=>$identity]],'onSuccessUpdateEmail'=>['#send'=>['mailboxIds'=>[$sent=>true],'keywords/$draft'=>null,'keywords/$seen'=>true]]]);
        }
        return $id;
    }
    public function download(string $emailId,string $blobId):array{
        $email=$this->email($emailId);$attachment=null;
        foreach($email['attachments']??[] as $a)if(hash_equals($a['blobId'],$blobId))$attachment=$a;
        if(!$attachment)throw new AuthError(404,'Attachment not found in this mailbox.');
        $data=$this->client->request('/jmap/download/'.rawurlencode($this->id).'/'.rawurlencode($blobId).'/attachment?type=application%2Foctet-stream')[0];
        return [$attachment['name']??'attachment',$data];
    }
}
