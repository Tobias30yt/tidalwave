#!/bin/bash
set -euo pipefail
# Run as root. Installs a NEW isolated Stalwart instance; never overwrites one.
test "$(id -u)" = 0
test ! -e /opt/tidalwave-mail
domain=${1:-tidlawaveenterprises.duckdns.org}
[[ "$domain" =~ ^[a-z0-9.-]+$ ]]
backup="/var/backups/tidalwave/mail-$(date -u +%Y%m%dT%H%M%SZ)"
install -d -m 0700 "$backup"
cp -a /etc/nginx/sites-available/tidalwave "$backup/nginx"
cp -a /etc/php/8.3/fpm/pool.d/tidalwave.conf "$backup/php-pool"
tar --exclude=.git --exclude=storage -czf "$backup/website.tar.gz" -C /var/www tidalwave
python3 - "$backup" <<'PY'
import sqlite3,sys,os,pwd,uuid
from pathlib import Path
for source in Path('/var/www/tidalwave/storage').rglob('*.sqlite'):
    tmp=source.parent/('.backup-'+uuid.uuid4().hex)
    os.seteuid(pwd.getpwnam('tidalwave').pw_uid)
    try:
        a=sqlite3.connect(source);b=sqlite3.connect(tmp);a.backup(b);b.close();a.close()
    finally: os.seteuid(0)
    dest=Path(sys.argv[1])/(source.parent.name+'-'+source.name)
    tmp.rename(dest);dest.chmod(0o600);os.chown(dest,0,0)
PY
install -d -m 0700 /opt/tidalwave-mail /opt/tidalwave-mail/etc /opt/tidalwave-mail/data
chown 2000:2000 /opt/tidalwave-mail/etc /opt/tidalwave-mail/data
python3 - <<'PY'
import secrets,pathlib
p=pathlib.Path('/opt/tidalwave-mail/bootstrap.env')
p.write_text('STALWART_RECOVERY_ADMIN=admin:'+secrets.token_urlsafe(32)+'\n');p.chmod(0o600)
PY
docker pull stalwartlabs/stalwart:v1.0.0
docker image inspect stalwartlabs/stalwart:v1.0.0 --format '{{index .RepoDigests 0}}' > /opt/tidalwave-mail/image.txt
docker run -d --name tidalwave-mail --restart unless-stopped --memory 1536m --cpus 1 --pids-limit 256 \
 --log-opt max-size=10m --log-opt max-file=3 \
 --env-file /opt/tidalwave-mail/bootstrap.env \
 -e STALWART_PUBLIC_URL="https://$domain" \
 -p 127.0.0.1:18080:8080 \
 -v /opt/tidalwave-mail/etc:/etc/stalwart -v /opt/tidalwave-mail/data:/var/lib/stalwart \
 "$(cat /opt/tidalwave-mail/image.txt)" >/dev/null
echo "Backup: $backup"
echo 'Stalwart bootstrap listener is private at 127.0.0.1:18080. Complete mail-bootstrap.py next.'
