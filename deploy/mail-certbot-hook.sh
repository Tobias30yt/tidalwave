#!/bin/sh
set -eu
if [ "${RENEWED_LINEAGE:-}" = /etc/letsencrypt/live/tidlawaveenterprises.duckdns.org ]; then
    /usr/bin/python3 /opt/tidalwave-mail/mail-certificate.py
fi
