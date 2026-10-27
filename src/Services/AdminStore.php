<?php
declare(strict_types=1);
final class AdminStore {
    public readonly PDO $db;
    public function __construct(public readonly array $config) {
        umask(0077);
        if (!is_dir($config['storage'])) mkdir($config['storage'],0700,true);
        if (!is_dir($config['storage'].'/uploads')) mkdir($config['storage'].'/uploads',0700,true);
        $this->db=new PDO('sqlite:'.$config['storage'].'/panel.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;
            CREATE TABLE IF NOT EXISTS bots (
                id TEXT PRIMARY KEY,name TEXT NOT NULL,runtime TEXT NOT NULL,start_command TEXT NOT NULL,
                install_command TEXT NOT NULL,environment TEXT NOT NULL,source TEXT NOT NULL,
                revision INTEGER NOT NULL DEFAULT 1,status TEXT NOT NULL DEFAULT "draft",logs TEXT NOT NULL DEFAULT "",
                updated_at INTEGER NOT NULL,created_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,bot_id TEXT NOT NULL,action TEXT NOT NULL,
                state TEXT NOT NULL DEFAULT "queued",created_at INTEGER NOT NULL,finished_at INTEGER);
            CREATE TABLE IF NOT EXISTS audit (id INTEGER PRIMARY KEY AUTOINCREMENT,actor TEXT NOT NULL,action TEXT NOT NULL,bot_id TEXT NOT NULL,created_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS health (id INTEGER PRIMARY KEY CHECK(id=1),heartbeat INTEGER NOT NULL);');
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $columns=array_column($this->query('PRAGMA table_info(bots)')->fetchAll(PDO::FETCH_ASSOC),'name');
            if(!in_array('description',$columns,true)) $this->db->exec("ALTER TABLE bots ADD COLUMN description TEXT NOT NULL DEFAULT ''");
            if(!in_array('published_at',$columns,true)) {
                $this->db->exec('ALTER TABLE bots ADD COLUMN published_at INTEGER');
                $this->db->exec("UPDATE bots SET published_at=updated_at WHERE status='running' OR EXISTS(SELECT 1 FROM jobs WHERE jobs.bot_id=bots.id AND action='deploy' AND state='done')");
            }
            $this->db->exec('COMMIT');
        } catch(Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function query(string $sql,array $args=[]): PDOStatement { $s=$this->db->prepare($sql); $s->execute($args); return $s; }
    public static function allowed(?array $user,array $config): bool {
        return $user && !$user['blocked'] && discord_id($config['owner_id']??null) && hash_equals($config['owner_id'],$user['id']);
    }
    public function bot(string $id): array {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new AuthError(404,'Bot not found.');
        return $this->query('SELECT * FROM bots WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC) ?: throw new AuthError(404,'Bot not found.');
    }
    public static function access(?array $user,array $config,string $area): bool {
        if(self::allowed($user,$config))return true;
        if(!$user || $user['blocked'] || empty($user['page_admin']))return false;
        return $area==='projects' || ($area==='api' && !empty($user['api_access']));
    }
    public function busy(string $id): bool { return (bool)$this->query("SELECT 1 FROM jobs WHERE bot_id=? AND state IN ('queued','running')",[$id])->fetchColumn(); }
    public static function environment(string $text): string {
        if (strlen($text)>16384 || str_contains($text,"\0")) throw new AuthError(400,'The environment is limited to 16 KB.');
        $result=[];
        foreach (explode("\n",str_replace("\r\n","\n",$text)) as $line) {
            if (trim($line)==='' || str_starts_with(ltrim($line),'#')) continue;
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/D',$line,$m) || isset($result[$m[1]])) throw new AuthError(400,'Use one unique KEY=value per line in .env.');
            if (in_array($m[1],['PATH','HOME','LD_PRELOAD','LD_LIBRARY_PATH','PYTHONPATH','PYTHONHOME','NODE_OPTIONS','JAVA_TOOL_OPTIONS','_JAVA_OPTIONS'],true)) throw new AuthError(400,'This environment key is reserved by the runtime: '.$m[1]);
            if (preg_match('/[\x00-\x1f\x7f]/',$m[2])) throw new AuthError(400,'Environment values must use a single line.');
            $result[$m[1]]=$m[2];
        }
        return implode("\n",array_map(fn($k,$v)=>$k.'='.$v,array_keys($result),$result));
    }
    public static function fields(array $input): array {
        $name=trim((string)($input['name']??'')); $runtime=(string)($input['runtime']??'');
        $start=trim((string)($input['start_command']??'')); $install=trim(str_replace("\r\n","\n",(string)($input['install_command']??'')));
        if ($name==='' || mb_strlen($name)>80 || preg_match('/[\x00-\x1f]/',$name)) throw new AuthError(400,'Choose a bot name of 1–80 characters.');
        $prefix=['node'=>'node','python'=>'python(?:3)?','java'=>'java'][$runtime]??null;
        if (!$prefix || strlen($start)>1000 || !preg_match('/^'.$prefix.'\s+[^\r\n]+$/D',$start) || preg_match('/[\x00-\x1f]/',$start)) throw new AuthError(400,'Start with node, python/python3, or java, followed by the entry file and arguments.');
        if (strlen($install)>8000 || preg_match('/[\x00-\x08\x0b-\x1f\x7f]/',$install)) throw new AuthError(400,'Installation commands must be plain text, at most 8 KB.');
        return [$name,$runtime,$start,$install];
    }
    public static function validateZip(string $path): void {
        if (filesize($path)>25*1024*1024) throw new AuthError(413,'ZIP uploads are limited to 25 MB.');
        $zip=new ZipArchive();
        if ($zip->open($path)!==true) throw new AuthError(400,'Upload a valid ZIP archive.');
        try {
            if ($zip->numFiles<1 || $zip->numFiles>2000) throw new AuthError(400,'ZIP must contain 1–2000 entries.');
            $size=0; $seen=[];
            for($i=0;$i<$zip->numFiles;$i++) {
                $entry=$zip->statIndex($i); $name=$entry['name']; $parts=explode('/',rtrim($name,'/'));
                $zip->getExternalAttributesIndex($i,$opsys,$attributes); $type=($attributes>>16)&0170000;
                if ($name==='' || strlen($name)>240 || str_starts_with($name,'/') || str_contains($name,'\\') || str_contains($name,':') || preg_match('/[\x00-\x1f\x7f]/',$name)
                    || array_intersect($parts,['..','.','','.git','.env']) || isset($seen[$name]) || !in_array($type,[0,0100000,0040000],true)) throw new AuthError(400,'ZIP contains an unsafe path, link, .git folder, or .env file. Use the environment editor for secrets.');
                if (($entry['encryption_method']??0)!==0) throw new AuthError(400,'Encrypted ZIP archives are not supported.');
                $seen[$name]=true; $size+=$entry['size'];
                if ($size>100*1024*1024) throw new AuthError(413,'Extracted source must be smaller than 100 MB.');
            }
        } finally { $zip->close(); }
    }
    public function save(?string $id,array $input,?array $upload,string $actor): string {
        [$name,$runtime,$start,$install]=self::fields($input); $newSource=null;
        if ($upload && ($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
            if ($upload['error']!==UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) throw new AuthError(400,'Upload failed. Select a ZIP smaller than 25 MB.');
            self::validateZip($upload['tmp_name']); $newSource=bin2hex(random_bytes(16));
            if (!move_uploaded_file($upload['tmp_name'],$this->config['storage'].'/uploads/'.$newSource.'.zip')) throw new RuntimeException('Upload storage failed');
        }
        try {
            $this->db->exec('BEGIN IMMEDIATE'); $old=$id ? $this->bot($id) : null;
            if ($id && $this->busy($id)) throw new AuthError(409,'Wait for the current job before editing this bot.');
            $source=$newSource??($old['source']??null);
            if (!$source) throw new AuthError(400,'Upload the bot source as a ZIP.');
            $env=isset($input['clear_environment']) ? '' : (($input['environment']??'')!=='' ? self::environment($input['environment']) : ($old['environment']??''));
            $id??=bin2hex(random_bytes(16));
            if ($old) $this->query('UPDATE bots SET name=?,runtime=?,start_command=?,install_command=?,environment=?,source=?,revision=revision+1,updated_at=? WHERE id=?',[$name,$runtime,$start,$install,$env,$source,time(),$id]);
            else $this->query('INSERT INTO bots(id,name,runtime,start_command,install_command,environment,source,updated_at,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$name,$runtime,$start,$install,$env,$source,time(),time()]);
            $description=trim((string)($input['description']??($old['description']??'')));
            if(mb_strlen($description)>1200) throw new AuthError(400,'The public description is limited to 1200 characters.');
            $this->query('UPDATE bots SET description=? WHERE id=?',[$description,$id]);
            $this->audit($actor,$old?'save':'create',$id); $this->db->exec('COMMIT'); return $id;
        } catch(Throwable $e) {
            try { $this->db->exec('ROLLBACK'); } catch(Throwable) {}
            if ($newSource) @unlink($this->config['storage'].'/uploads/'.$newSource.'.zip');
            throw $e;
        }
    }
    public function enqueue(string $id,string $action,string $actor): void {
        if (!in_array($action,['deploy','start','stop','restart','delete'],true)) throw new AuthError(400,'Unknown bot action.');
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $this->bot($id);
            if ($this->busy($id)) throw new AuthError(409,'This bot already has a queued or running job.');
            $this->query('INSERT INTO jobs(bot_id,action,created_at) VALUES(?,?,?)',[$id,$action,time()]);
            $this->audit($actor,$action,$id); $this->db->exec('COMMIT');
        } catch(Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function audit(string $actor,string $action,string $id): void { $this->query('INSERT INTO audit(actor,action,bot_id,created_at) VALUES(?,?,?,?)',[$actor,$action,$id,time()]); }
    public function publicBots(): array {
        $rows=$this->query('SELECT id,name,description,runtime,status FROM bots WHERE published_at IS NOT NULL ORDER BY published_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($b)=>['id'=>$b['id'],'name'=>$b['name'],'description'=>$b['description']?:'A Discord bot hosted by TidalWave.',
            'icon'=>'/favicon.svg','features'=>[ucfirst($b['runtime']),'Hosted on TidalWave'],'status'=>$b['status']==='running'?'Running':'Hosted',
            'documentation_url'=>null,'privacy_url'=>'/legal/privacy','terms_url'=>'/legal/terms','hosted'=>true],$rows);
    }
}
