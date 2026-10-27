<?php
declare(strict_types=1);
final class ReportsStore {
    public function __construct(public readonly AdminStore $store) {
        $store->db->exec('CREATE TABLE IF NOT EXISTS api_clients(id TEXT PRIMARY KEY,name TEXT NOT NULL,key_hash TEXT NOT NULL,enabled INTEGER NOT NULL DEFAULT 1,sequence INTEGER NOT NULL DEFAULT 0,last_seen INTEGER);
        CREATE TABLE IF NOT EXISTS reports_records(client_id TEXT NOT NULL,entity TEXT NOT NULL,record_id TEXT NOT NULL,data TEXT NOT NULL,sequence INTEGER NOT NULL,updated_at INTEGER NOT NULL,PRIMARY KEY(client_id,entity,record_id));');
    }
    public function register(string $name,?string $id=null): array {
        if(strlen($name)<1 || strlen($name)>80)throw new AuthError(400,'Use a server name of 1–80 characters.');
        $id=$id?:bin2hex(random_bytes(16));$key='tw_reports_'.bin2hex(random_bytes(32));
        $this->store->query('INSERT INTO api_clients(id,name,key_hash) VALUES(?,?,?) ON CONFLICT(id) DO UPDATE SET key_hash=excluded.key_hash,enabled=1',[$id,$name,hash('sha256',$key)]);
        return ['id'=>$id,'key'=>$key];
    }
    public function authenticate(string $authorization): array {
        if(!preg_match('/^Bearer (tw_reports_[a-f0-9]{64})$/D',$authorization,$m))throw new AuthError(401,'A registered server credential is required.');
        return $this->store->query('SELECT * FROM api_clients WHERE key_hash=? AND enabled=1',[hash('sha256',$m[1])])->fetch(PDO::FETCH_ASSOC)?:throw new AuthError(401,'Invalid or disabled server credential.');
    }
    // Website edits affect the stored replica, not the plugin's local SQLite database.
    public function edit(string $client,string $entity,string $id,?string $json,string $expected,string $actor): void {
        if(!in_array($entity,['sanction','report'],true)||!preg_match('/^[a-zA-Z0-9:_-]{1,100}$/D',$id))throw new AuthError(400,'Choose sanction/report and a valid record ID (letters, digits, colon, underscore, hyphen).');
        $encoded=null;
        if($json!==null){
            if(strlen($json)>24000)throw new AuthError(413,'Record data is limited to 24 KB.');
            try{$data=json_decode($json,false,32,JSON_THROW_ON_ERROR);}catch(JsonException){throw new AuthError(400,'Enter a valid JSON object.');}
            if(!$data instanceof stdClass || !get_object_vars($data))throw new AuthError(400,'Enter a nonempty JSON object.');
            $encoded=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        }
        $this->store->db->exec('BEGIN IMMEDIATE');
        try{
            if(!$this->store->query('SELECT 1 FROM api_clients WHERE id=?',[$client])->fetchColumn())throw new AuthError(404,'Reports server not found.');
            $record=$this->store->query('SELECT * FROM reports_records WHERE client_id=? AND entity=? AND record_id=?',[$client,$entity,$id])->fetch(PDO::FETCH_ASSOC);
            $version=$record?self::version($record):'new';
            if(!hash_equals($version,$expected))throw new AuthError(409,'This record changed or was removed. Reload the Reports API page before saving.');
            if($encoded===null){
                if(!$record)throw new AuthError(404,'Record not found.');
                $this->store->query('DELETE FROM reports_records WHERE client_id=? AND entity=? AND record_id=?',[$client,$entity,$id]);
            }else{
                $this->store->query('INSERT INTO reports_records VALUES(?,?,?,?,?,?) ON CONFLICT(client_id,entity,record_id) DO UPDATE SET data=excluded.data,updated_at=excluded.updated_at',[$client,$entity,$id,$encoded,$record['sequence']??0,time()]);
            }
            $this->store->audit($actor,'reports.'.($encoded===null?'delete':($record?'update':'create')),$client.'/'.$entity.'/'.$id);
            $this->store->db->exec('COMMIT');
        }catch(Throwable $e){$this->store->db->exec('ROLLBACK');throw $e;}
    }
    public static function version(array $record): string {
        return hash('sha256',$record['data'].':'.$record['sequence'].':'.$record['updated_at']);
    }
    public function ingest(array $client,array $events): int {
        if(!array_is_list($events)||count($events)>100)throw new AuthError(400,'Send at most 100 ordered events.');
        $this->store->db->exec('BEGIN IMMEDIATE');
        try {
            $current=$this->store->query('SELECT sequence,enabled FROM api_clients WHERE id=?',[$client['id']])->fetch(PDO::FETCH_ASSOC);
            if(!$current || !$current['enabled'])throw new AuthError(401,'Server disabled.');
            $sequence=(int)$current['sequence'];
            $previous=0;
            foreach($events as $event){
                if(!is_array($event)||!is_int($event['sequence']??null)||$event['sequence']<1)throw new AuthError(400,'Invalid event sequence.');
                if($event['sequence']<=$previous)throw new AuthError(400,'Events must be in ascending sequence order.');$previous=$event['sequence'];
                if($event['sequence']<=$sequence)continue;
                $entity=$event['entity']??'';$id=$event['id']??'';$data=$event['data']??null;
                if(!in_array($entity,['sanction','report'],true)||!is_string($id)||!preg_match('/^[a-zA-Z0-9:_-]{1,100}$/D',$id))throw new AuthError(400,'Invalid record.');
                if($data!==null){
                    if(!is_array($data)||array_is_list($data))throw new AuthError(400,'Invalid record data.');
                    $encoded=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
                    if(strlen($encoded)>24000)throw new AuthError(413,'Record too large.');
                    $this->store->query('INSERT INTO reports_records VALUES(?,?,?,?,?,?) ON CONFLICT(client_id,entity,record_id) DO UPDATE SET data=excluded.data,sequence=excluded.sequence,updated_at=excluded.updated_at',[$client['id'],$entity,$id,$encoded,$event['sequence'],time()]);
                }else $this->store->query('DELETE FROM reports_records WHERE client_id=? AND entity=? AND record_id=?',[$client['id'],$entity,$id]);
                $sequence=$event['sequence'];
            }
            $this->store->query('UPDATE api_clients SET sequence=?,last_seen=? WHERE id=?',[$sequence,time(),$client['id']]);
            $this->store->db->exec('COMMIT');return $sequence;
        }catch(Throwable $e){$this->store->db->exec('ROLLBACK');throw $e;}
    }
}
