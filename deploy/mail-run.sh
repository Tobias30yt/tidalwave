#!/bin/bash
set -euo pipefail
test "$(id -u)" = 0
test -f /opt/tidalwave-mail/etc/config.json
test -f /opt/tidalwave-mail/bootstrap-result.json
# Recreate only this service, preserving all bind-mounted data. No recovery credentials.
docker stop tidalwave-mail >/dev/null
docker rm tidalwave-mail >/dev/null
docker run -d --name tidalwave-mail --restart unless-stopped --memory 1536m --cpus 1 --pids-limit 256 \
 --log-opt max-size=10m --log-opt max-file=3 \
 -e STALWART_PUBLIC_URL=https://tidlawaveenterprises.duckdns.org \
 -p 127.0.0.1:18080:8080 -p 25:25 -p 465:465 -p 587:587 -p 993:993 \
 -v /opt/tidalwave-mail/etc:/etc/stalwart -v /opt/tidalwave-mail/data:/var/lib/stalwart \
 "$(cat /opt/tidalwave-mail/image.txt)" >/dev/null
echo 'Stalwart started with SMTP, TLS submission and IMAPS. Management remains loopback-only.'
