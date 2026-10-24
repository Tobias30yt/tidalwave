<?php
declare(strict_types=1);
class DiscordClient {
    public function request(string $method, string $path, ?string $authorization = null, ?array $body = null, bool $form = false): array {
        if (!str_starts_with($path, '/') || str_contains($path, '..')) throw new LogicException('Invalid Discord API path');
        $curl = curl_init('https://discord.com/api/v10'.$path);
        $headers = ['Accept: application/json', 'User-Agent: TidalWave-Verification/1.0'];
        if ($authorization !== null) $headers[]='Authorization: '.$authorization;
        if ($body !== null) $headers[]='Content-Type: '.($form ? 'application/x-www-form-urlencoded' : 'application/json');
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>$headers, CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>10,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
        if ($body !== null) curl_setopt($curl,CURLOPT_POSTFIELDS,$form ? http_build_query($body) : json_encode($body,JSON_THROW_ON_ERROR));
        $raw=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
        // Never include provider bodies, OAuth codes, or credentials in exceptions/logs.
        if ($raw===false || $status>=500 || $status===429) throw new AuthError(503,'Discord is temporarily unavailable. Please try again later.');
        if ($status===404) throw new AuthError(404,'The Discord resource is not available.');
        if ($status<200 || $status>=300) throw new AuthError(502,'Discord could not complete this request. Start again or contact TidalWave.');
        if ($status===204) return [];
        $decoded=json_decode($raw,true);
        if (!is_array($decoded)) throw new AuthError(502,'Discord returned an unexpected response.');
        return $decoded;
    }
    public function authorize(array $credentials, string $redirect, string $state, bool $install = false): string {
        $params=['client_id'=>$credentials['client_id'],'redirect_uri'=>$redirect,'response_type'=>'code',
            'scope'=>$install ? 'bot applications.commands' : 'identify','state'=>$state,'prompt'=>'consent'];
        if ($install) { $params['permissions']=$credentials['permissions']; $params['integration_type']='0'; }
        return 'https://discord.com/oauth2/authorize?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
    }
    public function exchange(array $credentials, string $redirect, string $code): array {
        return $this->request('POST','/oauth2/token',null,['client_id'=>$credentials['client_id'],
            'client_secret'=>$credentials['client_secret'],'grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$redirect],true);
    }
    public function member(array $config, string $id): void {
        try { $member=$this->request('GET','/guilds/'.$config['guild_id'].'/members/'.$id,'Bot '.$config['bot_token']); }
        catch (AuthError $e) { if ($e->status===404) throw new AuthError(403,'Join the verification server before installing a bot.'); throw $e; }
        if (($member['user']['id'] ?? null)!==$id || ($member['pending'] ?? false)) throw new AuthError(403,'Complete the verification server’s membership screening before installing a bot.');
    }
    public function requireCodeGrant(array $bot): void {
        $app=$this->request('GET','/applications/@me','Bot '.$bot['bot_token']);
        if (($app['id'] ?? null)!==$bot['client_id'] || ($app['bot_require_code_grant'] ?? false)!==true) {
            throw new AuthError(503,'Installation is not ready for this bot. Please contact TidalWave.');
        }
    }
}
