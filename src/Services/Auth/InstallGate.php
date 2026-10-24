<?php
declare(strict_types=1);
final class InstallGate {
    public function __construct(private AuthStore $store, private DiscordClient $discord, private array $config) {}
    public function check(string $userId, string $slug): array {
        $user=$this->store->user($userId);
        if (!$user || !$this->store->verified($user,$this->config)) throw new AuthError(403,'Verify your Discord account in our server before installing bots.');
        $bot=$this->config['installations'][$slug] ?? null;
        if (!$bot || !($bot['enabled'] ?? false)) throw new AuthError(409,'This bot is not available for installation yet.');
        if (!discord_id($bot['client_id'] ?? null) || empty($bot['client_secret']) || empty($bot['bot_token'])
            || !is_string($bot['permissions'] ?? null) || !preg_match('/^[0-9]{1,20}$/D',$bot['permissions'])) throw new AuthError(503,'Installation setup is incomplete.');
        // Check fresh membership and Discord-side enforcement on BOTH start and callback.
        $this->discord->member($this->config,$userId);
        $this->discord->requireCodeGrant($bot);
        $current=$this->store->user($userId);
        if (!$current || !$this->store->verified($current,$this->config)) throw new AuthError(403,'Your verification changed. Refresh your account before continuing.');
        return $bot;
    }
}
