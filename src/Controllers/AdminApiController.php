<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Services/ReportsStore.php';
final class AdminApiController {
    public static function dispatch(string $path,string $method,AdminStore $store,array $user):bool {
        if(!in_array($path,['/admin/api','/admin/api/register','/admin/api/disable','/admin/api/record/save','/admin/api/record/delete'],true))return false;
        $reports=new ReportsStore($store);
        if($method==='POST'){
            if(in_array($path,['/admin/api/record/save','/admin/api/record/delete'],true)){
                $delete=$path==='/admin/api/record/delete';
                if($delete && ($_POST['confirm']??'')!=='delete')throw new AuthError(400,'Confirm deletion of this database record.');
                $reports->edit((string)($_POST['client_id']??''),(string)($_POST['entity']??''),(string)($_POST['record_id']??''),$delete?null:(string)($_POST['data']??''),(string)($_POST['version']??''),$user['id']);
                $_SESSION['admin_notice']=$delete?'Database record deleted.':'Database record saved.';
                header('Location: /admin/api',true,303);return true;
            }
            // Only the owner can issue/rotate credentials or disable Minecraft servers.
            if(!AdminStore::allowed($user,$store->config))throw new AuthError(403,'Only the owner can manage server credentials.');
            if($path==='/admin/api/register'){
                $id=(string)($_POST['id']??'');
                if($id!==''&&!$store->query('SELECT 1 FROM api_clients WHERE id=?',[$id])->fetchColumn())throw new AuthError(404,'Server not found.');
                $credential=$reports->register(trim((string)($_POST['name']??'Reports')),$id?:null);
                $store->audit($user['id'],'api.credential',$credential['id']);
                $auth=require dirname(__DIR__,2).'/config/auth.php';
                header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="api.yml"');
                echo 'enabled: true'."\nendpoint: '".$auth['origin']."/api/reports/v1/events'\nkey: '".$credential['key']."'\n";return true;
            }
            if($path!=='/admin/api/disable')throw new AuthError(404,'Unknown action.');
            $id=(string)($_POST['id']??'');$store->query('UPDATE api_clients SET enabled=0 WHERE id=?',[$id]);$store->audit($user['id'],'api.disable',$id);
            header('Location: /admin/api',true,303);return true;
        }
        $clients=$store->query('SELECT id,name,enabled,sequence,last_seen FROM api_clients ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        if($path!=='/admin/api')throw new AuthError(405,'Use POST for this action.');
        $search=is_string($_GET['q']??null)?mb_substr($_GET['q'],0,100):'';
        $page=max(1,min(100000,(int)($_GET['page']??1)));
        $params=['%'.$search.'%','%'.$search.'%'];
        $filter=' WHERE r.record_id LIKE ? OR r.data LIKE ?';
        $records=$store->query('SELECT r.*,c.name FROM reports_records r JOIN api_clients c ON c.id=r.client_id'.$filter.' ORDER BY updated_at DESC,r.client_id,r.entity,r.record_id LIMIT 30 OFFSET '.(($page-1)*30),$params)->fetchAll(PDO::FETCH_ASSOC);
        $total=(int)$store->query('SELECT count(*) FROM reports_records r'.$filter,$params)->fetchColumn();
        $notice=$_SESSION['admin_notice']??null;unset($_SESSION['admin_notice']);$isOwner=AdminStore::allowed($user,$store->config);
        PageController::render('/admin/api',compact('clients','records','search','page','total','notice','isOwner','user'));return true;
    }
}
