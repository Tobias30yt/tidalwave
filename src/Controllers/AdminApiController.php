<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Services/ReportsStore.php';
final class AdminApiController {
    public static function dispatch(string $path,string $method,AdminStore $store,array $user):bool {
        if(!in_array($path,['/admin/api','/admin/api/register','/admin/api/disable'],true))return false;
        $reports=new ReportsStore($store);
        if($method==='POST'){
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
        $records=$store->query('SELECT r.*,c.name FROM reports_records r JOIN api_clients c ON c.id=r.client_id ORDER BY updated_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
        PageController::render('/admin/api',compact('clients','records'));return true;
    }
}
