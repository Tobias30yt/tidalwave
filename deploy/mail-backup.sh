#!/bin/bash
set -euo pipefail
test "$(id -u)" = 0
backup="/var/backups/tidalwave/mail-state-$(date -u +%Y%m%dT%H%M%SZ)"
install -d -m 0700 "$backup"
docker stop tidalwave-mail >/dev/null
trap 'docker start tidalwave-mail >/dev/null' EXIT
tar -czf "$backup/stalwart.tar.gz" -C /opt tidalwave-mail
cp -a /var/www/tidalwave/config/mail.local.php "$backup/mail.local.php"
# Preserve the encryption key in the existing auth config, without modifying it.
cp -a /var/www/tidalwave/config/auth.local.php "$backup/auth.local.php"
python3 - "$backup" <<'PY'
import sqlite3,sys,os,pwd,uuid
from pathlib import Path
source=Path('/var/www/tidalwave/storage/admin/panel.sqlite')
tmp=source.parent/('.backup-'+uuid.uuid4().hex)
os.seteuid(pwd.getpwnam('tidalwave').pw_uid)
try:
    a=sqlite3.connect(source);b=sqlite3.connect(tmp);a.backup(b);b.close();a.close()
finally:os.seteuid(0)
dest=Path(sys.argv[1])/'panel.sqlite';tmp.rename(dest);dest.chmod(0o600);os.chown(dest,0,0)
PY
echo "Private backup created: $backup"
