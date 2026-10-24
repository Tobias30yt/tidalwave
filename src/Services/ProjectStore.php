<?php
declare(strict_types=1);
final class GitHubProject {
    public static function repository(string $url): string {
        if(!preg_match('~^https://github\.com/([A-Za-z0-9][A-Za-z0-9-]{0,38})/([A-Za-z0-9_.-]{1,100})/?$~D',trim($url),$m)) throw new AuthError(400,'Enter a GitHub repository URL: https://github.com/owner/repository');
        $repo=preg_replace('/\.git$/','',$m[2]);
        if(in_array($repo,['','.','..'],true)) throw new AuthError(400,'Invalid GitHub repository.');
        return $m[1].'/'.$repo;
    }
    private static function request(string $path,bool $optional=false): ?array {
        $body='';$c=curl_init('https://api.github.com/repos/'.$path);
        curl_setopt_array($c,[CURLOPT_TIMEOUT=>7,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json','X-GitHub-Api-Version: 2022-11-28','User-Agent: TidalWave-Projects'],
            CURLOPT_WRITEFUNCTION=>static function($c,string $part) use (&$body): int { if(strlen($body)+strlen($part)>1048576)return 0;$body.=$part;return strlen($part); }]);
        $ok=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
        if($optional && $status===404)return null;
        if($ok===false || $status!==200) throw new AuthError(502,'GitHub could not be read. Use a public repository URL and try again later (HTTP '.$status.').');
        $data=json_decode($body,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new AuthError(502,'GitHub returned an invalid response.');
        return $data;
    }
    public static function import(string $url): array {
        $repo=self::repository($url);$parts=explode('/',$repo);$path=rawurlencode($parts[0]).'/'.rawurlencode($parts[1]);
        $data=self::request($path);$release=self::request($path.'/releases/latest',true);
        return ['repo'=>$repo,'name'=>(string)($data['name']??$parts[1]),'description'=>(string)($data['description']??''),
            'language'=>(string)($data['language']??''),'stars'=>(int)($data['stargazers_count']??0),
            'release_tag'=>(string)($release['tag_name']??''),'release_url'=>$release?'https://github.com/'.$repo.'/releases/tag/'.rawurlencode($release['tag_name']):''];
    }
}
final class ProjectStore {
    public function __construct(public readonly AdminStore $store) {
        $store->db->exec('PRAGMA foreign_keys=ON;
            CREATE TABLE IF NOT EXISTS projects (id TEXT PRIMARY KEY,name TEXT NOT NULL,description TEXT NOT NULL,technologies TEXT NOT NULL,
            github_url TEXT NOT NULL DEFAULT "",github_stars INTEGER NOT NULL DEFAULT 0,github_release_url TEXT NOT NULL DEFAULT "",github_release_tag TEXT NOT NULL DEFAULT "",
            synced_at INTEGER,published INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS project_files (id TEXT PRIMARY KEY,project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            kind TEXT NOT NULL,filename TEXT NOT NULL,version TEXT NOT NULL,size INTEGER NOT NULL,created_at INTEGER NOT NULL);');
    }
    public function get(string $id): array {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new AuthError(404,'Project not found.');
        return $this->store->query('SELECT * FROM projects WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC)?:throw new AuthError(404,'Project not found.');
    }
    private function upload(array $file,string $kind): ?array {
        if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;
        if($file['error']!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name'])>25*1024*1024)throw new AuthError(400,'Uploads must be valid files smaller than 25 MB each.');
        if($kind==='source')AdminStore::validateZip($file['tmp_name']);
        $name=basename(str_replace('\\','/',$file['name']));
        $name=preg_replace('/[^A-Za-z0-9._-]/','_',$name);
        if(!$name || strlen($name)>120)throw new AuthError(400,'Choose a filename of 1–120 characters.');
        $dir=$this->store->config['storage'].'/project-files';if(!is_dir($dir))mkdir($dir,0700,true);
        $id=bin2hex(random_bytes(16));$size=filesize($file['tmp_name']);
        if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$id.'.bin'))throw new RuntimeException('Project upload failed');
        return compact('id','name','size','kind');
    }
    public function save(array $input,array $files,string $actor): string {
        $id=(string)($input['id']??'');$old=$id?$this->get($id):null;
        $url=trim((string)($input['github_url']??''));$metadata=null;
        if($url){$repo=GitHubProject::repository($url);$url='https://github.com/'.$repo;if(!$old || $old['github_url']!==$url || isset($input['sync_github']))$metadata=GitHubProject::import($url);}
        $name=trim((string)($input['name']??''))?:($metadata['name']??'');$description=trim((string)($input['description']??''))?:($metadata['description']??'');
        $tech=trim((string)($input['technologies']??''))?:($metadata['language']??'');$version=trim((string)($input['version']??''));
        if($name==='' || mb_strlen($name)>100 || mb_strlen($description)>4000 || mb_strlen($tech)>250 || mb_strlen($version)>80)throw new AuthError(400,'Use a name (up to 100 characters), description (4000), technologies (250), and release version (80).');
        $uploaded=[];
        try {
            foreach(['source','release'] as $kind){$file=$this->upload($files[$kind]??[],$kind);if($file)$uploaded[]=$file;}
            if(!$old && !$url && !$uploaded)throw new AuthError(400,'Add a GitHub link, source ZIP, or release file.');
            $id=$id?:bin2hex(random_bytes(16));$this->store->db->exec('BEGIN IMMEDIATE');
            $this->store->query('INSERT INTO projects(id,name,description,technologies,github_url,published,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name,description=excluded.description,technologies=excluded.technologies,github_url=excluded.github_url,published=excluded.published,updated_at=excluded.updated_at',[$id,$name,$description,$tech,$url,isset($input['published'])?1:0,time(),time()]);
            if($metadata)$this->store->query('UPDATE projects SET github_stars=?,github_release_url=?,github_release_tag=?,synced_at=? WHERE id=?',[$metadata['stars'],$metadata['release_url'],$metadata['release_tag'],time(),$id]);
            elseif(!$url)$this->store->query('UPDATE projects SET github_stars=0,github_release_url="",github_release_tag="",synced_at=NULL WHERE id=?',[$id]);
            foreach($uploaded as $file)$this->store->query('INSERT INTO project_files(id,project_id,kind,filename,version,size,created_at) VALUES(?,?,?,?,?,?,?)',[$file['id'],$id,$file['kind'],$file['name'],$version,$file['size'],time()]);
            $this->store->audit($actor,'project.save',$id);$this->store->db->exec('COMMIT');return $id;
        }catch(Throwable $e){try{$this->store->db->exec('ROLLBACK');}catch(Throwable){}foreach($uploaded as $file)@unlink($this->store->config['storage'].'/project-files/'.$file['id'].'.bin');throw $e;}
    }
    public function files(string $id): array {return $this->store->query('SELECT * FROM project_files WHERE project_id=? ORDER BY created_at DESC,id DESC',[$id])->fetchAll(PDO::FETCH_ASSOC);}
    public function remove(string $id,string $actor): void {
        $this->get($id);$files=$this->files($id);
        $this->store->query('DELETE FROM projects WHERE id=?',[$id]);
        foreach($files as $file)@unlink($this->store->config['storage'].'/project-files/'.$file['id'].'.bin');
        $this->store->audit($actor,'project.delete',$id);
    }
    public function removeFile(string $id,string $actor): void {
        $file=$this->store->query('SELECT * FROM project_files WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if(!$file || !preg_match('/^[a-f0-9]{32}$/D',$id))throw new AuthError(404,'File not found.');
        $this->store->query('DELETE FROM project_files WHERE id=?',[$id]);@unlink($this->store->config['storage'].'/project-files/'.$id.'.bin');
        $this->store->audit($actor,'project.file.delete',$id);
    }
    public function published(): array {
        $rows=$this->store->query('SELECT * FROM projects WHERE published=1 ORDER BY updated_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row){$row['files']=$this->files($row['id']);$row['technologies']=array_filter(array_map('trim',explode(',',$row['technologies'])));$row['status']='Published';}
        return $rows;
    }
    public function download(string $id): never {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new AuthError(404,'Download not found.');
        $file=$this->store->query('SELECT f.* FROM project_files f JOIN projects p ON p.id=f.project_id WHERE f.id=? AND p.published=1',[$id])->fetch(PDO::FETCH_ASSOC);
        if(!$file)throw new AuthError(404,'Download not found.');
        $path=$this->store->config['storage'].'/project-files/'.$id.'.bin';
        if(!is_file($path))throw new AuthError(404,'Download not found.');
        header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.$file['filename'].'"');
        header('Content-Security-Policy: sandbox');header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');header('Content-Length: '.filesize($path));
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')readfile($path);exit;
    }
}
