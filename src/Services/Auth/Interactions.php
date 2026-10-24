<?php
declare(strict_types=1);
final class DiscordInteractions {
    public function __construct(private AuthStore $store, private array $config) {}
    public static function validSignature(string $body, string $signature, string $timestamp, string $publicKey): bool {
        if (!preg_match('/^[a-f0-9]{128}$/iD',$signature) || !preg_match('/^[a-f0-9]{64}$/iD',$publicKey)
            || !preg_match('/^[0-9]{10,12}$/D',$timestamp) || abs(time()-(int)$timestamp)>300) return false;
        return sodium_crypto_sign_verify_detached(hex2bin($signature),$timestamp.$body,hex2bin($publicKey));
    }
    private function reply(string $message): array { return ['type'=>4,'data'=>['content'=>$message,'flags'=>64,'allowed_mentions'=>['parse'=>[]]]]; }
    public function handle(array $payload): array {
        if (($payload['application_id'] ?? null)!==$this->config['client_id']) throw new AuthError(401,'Invalid application.');
        if (($payload['type'] ?? null)===1) return ['type'=>1];
        if (($payload['type'] ?? null)!==2 || ($payload['data']['name'] ?? null)!=='verify') return $this->reply('This command is not supported.');
        if (($payload['guild_id'] ?? null)!==$this->config['guild_id']) return $this->reply('Use /verify in the TidalWave verification server.');
        $userId=$payload['member']['user']['id'] ?? null;
        if (!discord_id($userId) || ($payload['member']['user']['bot'] ?? false) || ($payload['member']['pending'] ?? false)) return $this->reply('Complete server membership screening before verifying.');
        if (!discord_id($payload['id'] ?? null)) throw new AuthError(400,'Invalid interaction.');
        $insert=$this->store->query('INSERT OR IGNORE INTO interactions(id,expires_at) VALUES(?,?)',[$payload['id'],time()+600]);
        if ($insert->rowCount()!==1) return $this->reply('This request was already processed. Refresh your TidalWave account page.');
        $code=null;
        foreach (($payload['data']['options'] ?? []) as $option) if (($option['name'] ?? null)==='code' && ($option['type'] ?? null)===3) $code=$option['value'] ?? null;
        if (!is_string($code) || strlen($code)>32) return $this->reply('Enter the one-time code shown on your TidalWave account page.');
        try { $verified=$this->store->verify($userId,$this->config['guild_id'],$code); }
        catch (AuthError $e) { return $this->reply($e->getMessage()); }
        return $this->reply($verified ? 'Your Discord account is verified with TidalWave. Return to the website and refresh your account page.' : 'That code is expired, already used, or belongs to a different Discord account. Generate a new code on the website while signed in as this account.');
    }
}
