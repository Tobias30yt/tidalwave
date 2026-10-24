<?php
declare(strict_types=1);
final class ForumStore {
    public function __construct(public readonly AuthStore $accounts) {
        $accounts->db->exec('CREATE TABLE IF NOT EXISTS forum_threads(id INTEGER PRIMARY KEY AUTOINCREMENT,author_id TEXT REFERENCES users(id) ON DELETE SET NULL,
            title TEXT NOT NULL,body TEXT NOT NULL,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL,locked INTEGER NOT NULL DEFAULT 0,solved INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE IF NOT EXISTS forum_replies(id INTEGER PRIMARY KEY AUTOINCREMENT,thread_id INTEGER NOT NULL REFERENCES forum_threads(id) ON DELETE CASCADE,
            author_id TEXT REFERENCES users(id) ON DELETE SET NULL,body TEXT NOT NULL,created_at INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS forum_threads_updated ON forum_threads(updated_at DESC);
            CREATE INDEX IF NOT EXISTS forum_replies_thread ON forum_replies(thread_id,id);');
    }
    public static function text(mixed $input,int $minimum,int $maximum): string {
        if(!is_string($input))throw new AuthError(400,'Enter valid text.');
        $text=trim(str_replace("\r\n","\n",$input));
        if(mb_strlen($text)<$minimum || mb_strlen($text)>$maximum || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text))throw new AuthError(400,'Text must contain '.$minimum.'–'.$maximum.' characters.');
        return $text;
    }
    private function active(string $id): void { $user=$this->accounts->user($id);if(!$user || $user['blocked'])throw new AuthError(403,'This account cannot post in the forum.'); }
    public function thread(int $id): array {
        return $this->accounts->query('SELECT t.*,u.username FROM forum_threads t LEFT JOIN users u ON u.id=t.author_id WHERE t.id=?',[$id])->fetch(PDO::FETCH_ASSOC)?:throw new AuthError(404,'Question not found.');
    }
    public function create(string $author,mixed $title,mixed $body): int {
        $this->active($author);$title=self::text($title,5,160);$body=self::text($body,10,12000);
        $this->accounts->limit('forum.question:'.$author,5,3600);
        $this->accounts->query('INSERT INTO forum_threads(author_id,title,body,created_at,updated_at) VALUES(?,?,?,?,?)',[$author,$title,$body,time(),time()]);
        return (int)$this->accounts->db->lastInsertId();
    }
    public function reply(int $id,string $author,mixed $body): void {
        $this->active($author);$body=self::text($body,2,12000);$this->accounts->limit('forum.reply:'.$author,20,600);
        $this->accounts->db->exec('BEGIN IMMEDIATE');
        try {
            $thread=$this->thread($id);if($thread['locked'])throw new AuthError(403,'This question is locked.');
            $this->accounts->query('INSERT INTO forum_replies(thread_id,author_id,body,created_at) VALUES(?,?,?,?)',[$id,$author,$body,time()]);
            $this->accounts->query('UPDATE forum_threads SET updated_at=? WHERE id=?',[time(),$id]);$this->accounts->db->exec('COMMIT');
        }catch(Throwable $e){$this->accounts->db->exec('ROLLBACK');throw $e;}
    }
    public function moderate(int $id,string $actor,bool $admin,string $action,?int $reply=null): void {
        $this->active($actor);$thread=$this->thread($id);
        if($action==='delete_reply'){
            $row=$this->accounts->query('SELECT * FROM forum_replies WHERE id=? AND thread_id=?',[$reply,$id])->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new AuthError(404,'Reply not found.');
            if(!$admin && $row['author_id']!==$actor)throw new AuthError(403,'You can only delete your own reply.');
            $this->accounts->query('DELETE FROM forum_replies WHERE id=?',[$reply]);return;
        }
        if(!$admin && $thread['author_id']!==$actor)throw new AuthError(403,'Only the author or administrator can change this question.');
        if($action==='delete')$this->accounts->query('DELETE FROM forum_threads WHERE id=?',[$id]);
        elseif($action==='solve')$this->accounts->query('UPDATE forum_threads SET solved=1-solved WHERE id=?',[$id]);
        elseif($action==='lock' && $admin)$this->accounts->query('UPDATE forum_threads SET locked=1-locked WHERE id=?',[$id]);
        else throw new AuthError(403,'This action is not available.');
    }
    public function listing(string $search,int $page): array {
        $search=mb_substr($search,0,100);$pattern='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$search).'%';
        $where="WHERE t.title LIKE ? ESCAPE '\\'";
        $rows=$this->accounts->query('SELECT t.*,u.username,(SELECT count(*) FROM forum_replies r WHERE r.thread_id=t.id) AS replies FROM forum_threads t LEFT JOIN users u ON u.id=t.author_id '.$where.' ORDER BY t.updated_at DESC,t.id DESC LIMIT 20 OFFSET '.(($page-1)*20),[$pattern])->fetchAll(PDO::FETCH_ASSOC);
        $total=(int)$this->accounts->query('SELECT count(*) FROM forum_threads t '.$where,[$pattern])->fetchColumn();return compact('rows','total');
    }
}
