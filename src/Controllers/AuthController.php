<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Services/Auth/bootstrap.php';
final class AuthController {
    public static function dispatch(string $path): bool {
        $routes=['/account','/auth/discord','/auth/discord/callback','/auth/logout','/auth/verify-code','/auth/delete','/discord/interactions'];
        $install=preg_match('~^/bots/([a-z0-9-]+)/install(/callback)?$~D',$path,$matches)===1;
        if (!in_array($path,$routes,true) && !$install) return false;
        header('Cache-Control: no-store, private'); header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        $config=require dirname(__DIR__,2).'/config/auth.php';
        $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
        $expected=in_array($path,['/account','/auth/discord/callback'],true) || ($install && !empty($matches[2])) ? 'GET' : 'POST';
        if ($method!==$expected) {
            http_response_code(405); header('Allow: '.$expected); echo 'Method not allowed'; return true;
        }
        if (!$config['enabled']) {
            if ($path==='/account') PageController::render('/account',['authEnabled'=>false]);
            else { http_response_code(503); echo 'Account service is not configured yet.'; }
            return true;
        }
        try {
            auth_configure($config);
            if ($path!=='/discord/interactions') {
                // Only the configured origin can host account cookies and callbacks.
                $origin=parse_url($config['origin']);
                $expectedHost=$origin['host'].(isset($origin['port']) ? ':'.$origin['port'] : '');
                if (strcasecmp($_SERVER['HTTP_HOST'] ?? '',$expectedHost)!==0) throw new AuthError(400,'Use the configured TidalWave website address to sign in.');
                if ($origin['scheme']==='https' && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off')) throw new AuthError(400,'Use HTTPS to access your account.');
            }
            if ($path==='/discord/interactions') {
                self::interaction($config); return true;
            }
            $store=new AuthStore($config['storage'],$config['app_key']);
            require_once dirname(__DIR__).'/Services/AdminStore.php';require_once dirname(__DIR__).'/Services/BotInstallations.php';
            $adminConfig=require dirname(__DIR__,2).'/config/admin.php';
            if(is_file($adminConfig['storage'].'/panel.sqlite'))$config=(new BotInstallations(new AdminStore($adminConfig),$config))->merge($config);
            unset($config['installations']['synatra']);
            AuthSession::start($config);
            $discord=new DiscordClient();
            $user=isset($_SESSION['user_id']) ? $store->user($_SESSION['user_id']) : null;
            if ($user && !hash_equals($user['session_key'],$_SESSION['user_key'] ?? '')) $user=null;
            if (isset($_SESSION['user_id']) && !$user) { $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32)); }
            if ($path==='/account') {
                $notice=$_SESSION['notice'] ?? null; unset($_SESSION['notice']);
                $challenge=$_SESSION['challenge'] ?? null;
                if ($challenge && $challenge['expires']<time()) { unset($_SESSION['challenge']); $challenge=null; }
                $verified=$user && $store->verified($user,$config);
                if ($verified) { unset($_SESSION['challenge']); $challenge=null; }
                PageController::render('/account',compact('config','user','notice','challenge','verified')+['authEnabled'=>true]);
                return true;
            }
            if ($method==='POST') AuthSession::csrf($_POST['csrf'] ?? null);
            if ($path==='/auth/discord') {
                $store->limit('login:'.($_SERVER['REMOTE_ADDR'] ?? 'unknown'),15,600);
                $state=AuthSession::state('login',null);
                self::redirect($discord->authorize($config,$config['origin'].'/auth/discord/callback',$state));
            }
            if ($path==='/auth/discord/callback') {
                AuthSession::consumeState($_GET['state'] ?? null,'login',null);
                self::callbackInput();
                $token=$discord->exchange($config,$config['origin'].'/auth/discord/callback',$_GET['code']);
                if (!is_string($token['access_token'] ?? null) || !in_array('identify',explode(' ',$token['scope'] ?? ''),true)) throw new AuthError(502,'Discord did not grant the requested sign-in permission.');
                $profile=$discord->request('GET','/users/@me','Bearer '.$token['access_token']);
                $user=$store->signIn($profile);
                // OAuth access/refresh tokens are request-local; never persist either.
                unset($token,$profile);
                AuthSession::login($user);
                self::redirect('/account');
            }
            if ($path==='/auth/logout') { AuthSession::logout(); self::redirect('/account'); }
            if (!$user) throw new AuthError(401,'Sign in with Discord before continuing.');
            if ($path==='/auth/delete') {
                if (($_POST['confirm'] ?? null)!=='delete') throw new AuthError(400,'Confirm account deletion before continuing.');
                $mailConfig=require dirname(__DIR__,2).'/config/mail.php';
                if($mailConfig['enabled']){
                    require_once dirname(__DIR__).'/Services/AdminStore.php';require_once dirname(__DIR__).'/Services/MailStore.php';
                    (new MailStore(new AdminStore(require dirname(__DIR__,2).'/config/admin.php'),$mailConfig,$config['app_key']))->disable($user['id']);
                }
                $store->query('DELETE FROM users WHERE id=?',[$user['id']]);
                AuthSession::logout(); self::redirect('/account');
            }
            if ($user['blocked']) throw new AuthError(403,'This account cannot access bot verification or installation.');
            if ($path==='/auth/verify-code') {
                $_SESSION['challenge']=['code'=>$store->challenge($user['id']),'expires'=>time()+600];
                self::redirect('/account');
            }
            if ($install) {
                $slug=$matches[1];
                $catalog=require dirname(__DIR__,2).'/config/bots.php';
                if(is_file($adminConfig['storage'].'/panel.sqlite'))$catalog=array_merge($catalog,(new AdminStore($adminConfig))->publicBots());
                if (!in_array($slug,array_column($catalog,'id'),true)) throw new AuthError(404,'This bot is not available.');
                $store->limit('install:'.$user['id'],10,600);
                $callback=!empty($matches[2]);
                if ($callback) {
                    AuthSession::consumeState($_GET['state'] ?? null,'install:'.$slug,$user['id']);
                    self::callbackInput();
                }
                $bot=(new InstallGate($store,$discord,$config))->check($user['id'],$slug);
                $redirect=$config['origin'].'/bots/'.$slug.'/install/callback';
                if (!$callback) self::redirect($discord->authorize($bot,$redirect,AuthSession::state('install:'.$slug,$user['id']),true));
                // Code exchange is the installation side effect. Never reach it without the gate.
                $result=$discord->exchange($bot,$redirect,$_GET['code']);
                $guild=$result['guild']['id'] ?? null;
                if (!discord_id($guild)) {
                    error_log('Discord installation returned no guild confirmation. Bot key: '.$slug);
                    throw new AuthError(502,'Discord did not return an installation confirmation. Check your server before retrying.');
                }
                $store->query('INSERT INTO installations(user_id,bot_id,guild_id,installed_at) VALUES(?,?,?,?) ON CONFLICT(user_id,bot_id,guild_id) DO UPDATE SET installed_at=excluded.installed_at',[$user['id'],$slug,$guild,time()]);
                unset($result);
                $_SESSION['notice']='Discord confirmed the bot installation. You can now return to your server.';
                self::redirect('/account');
            }
        } catch (AuthError $error) {
            if ($path==='/discord/interactions') {
                http_response_code($error->status); header('Content-Type: application/json'); echo json_encode(['error'=>$error->getMessage()]);
            } else {
                http_response_code($error->status);
                PageController::render('/account',['authEnabled'=>false,'authError'=>$error->getMessage()]);
            }
        }
        return true;
    }
    private static function callbackInput(): void {
        if (($_GET['iss'] ?? null)!=='https://discord.com') throw new AuthError(403,'The authorization response did not come from the expected provider.');
        if (isset($_GET['error'])) throw new AuthError(400,'Discord authorization was cancelled or declined. You can start again.');
        if (!is_string($_GET['code'] ?? null) || strlen($_GET['code'])<1 || strlen($_GET['code'])>2048) throw new AuthError(400,'The Discord authorization code is missing or invalid.');
    }
    private static function redirect(string $url): never { header('Location: '.$url,true,303); exit; }
    private static function interaction(array $config): void {
        $body=file_get_contents('php://input',false,null,0,65537);
        if ($body===false || strlen($body)>65536) throw new AuthError(413,'Interaction too large.');
        if (!DiscordInteractions::validSignature($body,$_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? '',$_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? '',$config['public_key'])) throw new AuthError(401,'Invalid request signature.');
        $payload=json_decode($body,true,32);
        if (!is_array($payload)) throw new AuthError(400,'Invalid JSON.');
        $store=new AuthStore($config['storage'],$config['app_key']);
        $response=(new DiscordInteractions($store,$config))->handle($payload);
        header('Content-Type: application/json; charset=UTF-8'); echo json_encode($response,JSON_THROW_ON_ERROR);
    }
}
