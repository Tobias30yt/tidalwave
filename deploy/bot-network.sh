#!/bin/sh
set -eu
docker network inspect tidalwave-bots >/dev/null 2>&1 || docker network create --driver bridge --subnet 172.29.80.0/24 --opt com.docker.network.bridge.name=twbots0 tidalwave-bots
# Dedicated bridge only: preserve every existing host firewall rule.
iptables -N TW-BOT-EGRESS 2>/dev/null || true
iptables -F TW-BOT-EGRESS
iptables -A TW-BOT-EGRESS -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
for subnet in 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 169.254.0.0/16 127.0.0.0/8 100.64.0.0/10; do
    iptables -A TW-BOT-EGRESS -d "$subnet" -j REJECT
done
iptables -A TW-BOT-EGRESS -j RETURN
iptables -C DOCKER-USER -i twbots0 -j TW-BOT-EGRESS 2>/dev/null || iptables -I DOCKER-USER 1 -i twbots0 -j TW-BOT-EGRESS
iptables -C INPUT -i twbots0 -j REJECT 2>/dev/null || iptables -I INPUT 1 -i twbots0 -j REJECT
