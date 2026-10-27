# Tidalwave Mail — installation and operating status

## Verified on 27 October 2026

Stalwart 1.0.0 runs in `tidalwave-mail`, pinned to image digest
`sha256:baafeacd6cbde77acdd94af49972d41c6709e54a376df48e18a9fe3f445eb3d5`.
It shares the Ubuntu ARM64 VPS with Nginx/PHP, with a 1 CPU / 1536 MB container limit.
Management and JMAP for the website use `127.0.0.1:18080`, never a public management proxy.
Persistent data and recovery credentials are under root-only `/opt/tidalwave-mail`.
The website uses a separate `config/mail.local.php`; `config/auth.local.php` is untouched.

Passed against the real server: separate JMAP identities, folder isolation, saved drafts,
internal submission and delivery, Sent and Trash, search, attachment upload/download,
foreign-message and foreign-attachment rejection, mailbox disable/re-enable, quota changes,
and denial of another mailbox to the management identity. Website tests cover anonymous
access, owner-only administration, CSRF, escaped message content and destructive confirmation.

An actual message from the administrator's computer was accepted over public TCP 25 with
STARTTLS/TLS 1.3 and a trusted Let's Encrypt certificate. Unauthenticated relay to an
unrelated domain was rejected (`550 5.7.1`). Ports 465, 587 and 993 were still unreachable
externally at that check, although the services run locally and IMAPS certificate validation passes.
Those additional ingress ports are only required for external native mail clients; `/mail`
uses the existing HTTPS website.

One explicitly authorized external test message was submitted to the owner's Outlook address.
It is NOT confirmed delivered. Stalwart reports `TemporaryFailure / connectionError /
Connection timed out` to Outlook's MX. Independent outbound TCP-25 probes to Google and
mailbox.org also timed out. External compose remains disabled in the web UI to prevent
misleading send-success claims while provider/DNS setup is incomplete. Local mail works.

## DNS and provider restrictions

The active hostname is **tidlawaveenterprises.duckdns.org** (note `tidla`). At inspection:

- A: `130.61.169.52`.
- MX: `50 tidlawaveenterprises.duckdns.org.` (DuckDNS supplied this record).
- TXT: empty; no usable SPF, DKIM or DMARC was present.
- No PTR response was returned for the VPS public address.
- The alternate `tidalwaveenterprises.duckdns.org` did not return an A record.
- `tidalwave.eu.org` did not return an NS delegation in the initial check.

DuckDNS's documented API exposes A/AAAA and a TXT value shared by sub-subdomains;
it does not provide independent root SPF, selector DKIM and `_dmarc` values. This is
unsuitable for reliable authenticated public mail even though its MX permits inbound routing.
Source: https://www.duckdns.org/spec.jsp

Oracle defaults to blocking outbound SMTP port 25 for newer tenancies. Opening an ingress
rule enables receiving; an egress rule alone does not remove the provider's SMTP restriction.
Request an Oracle exception or configure an authenticated SMTP relay on port 587/465.
No relay account or secret has been supplied, so none is silently configured.
Source: https://docs.oracle.com/en-us/iaas/releasenotes/changes/f7e95770-9844-43db-916c-6ccbaf2cfe24/

## Fresh installation (existing production installation must not be repeated)

Run as root from a private copy of `deploy/` after reviewing disk space and port usage:

```sh
bash mail-install.sh tidlawaveenterprises.duckdns.org
python3 mail-bootstrap.py tidlawaveenterprises.duckdns.org
docker restart tidalwave-mail
python3 mail-provision.py
python3 mail-certificate.py
bash mail-run.sh
```

The installer refuses an existing `/opt/tidalwave-mail`, backs up the website, Nginx,
PHP pool and SQLite databases using SQLite's backup API, and starts only a loopback
bootstrap listener. Keep the bootstrap result root-only: it contains the recovery admin.
The regular run script removes the recovery environment, preserves mounted data, and
publishes SMTP/IMAPS. The management identity has normal user rights plus account
provisioning rights because Stalwart only allows granting permissions an identity holds;
it has neither `impersonate` nor `fetchAnyBlob`. All website mail operations authenticate
as the individual mailbox, with its random credential encrypted using a derived app key.

Include `deploy/nginx-mail.conf` inside the existing HTTPS server block; run `nginx -t`
before reloading. It allows attachment uploads at `/mail/compose` without changing global
upload limits. Do not replace the existing Nginx site or change auth.local.php.

The certificate renewal hook runs `/opt/tidalwave-mail/mail-certificate.py` after Certbot
renewal. Copy `mail-manage.py` alongside it, root-owned mode 0700. It reads only the existing
certificate and imports it through the private API. No TLS validation is disabled.

## Mail administration

Open `/admin/mail` as the configured owner Discord ID. Create a mailbox for an existing
website user, choose its local address and a 10–5120 MB quota, then enable access.
The initial owner mailbox is `tobais30@tidlawaveenterprises.duckdns.org` (256 MB).
Users sign in through Discord at `/account` and visit `/mail`. There is no public sign-up
for mail, shared password, master mailbox view, or cross-account mailbox selector.

Disabling mail revokes the mailbox credentials as well as website access. Blocking or
deleting a website user disables their mailbox. Deletion of the website profile retains
disabled mail data for the administrator to review; deleting mail itself requires typing
`DELETE MAILBOX` in the dedicated panel. Moving a message to Trash is reversible.
Mail content is rendered as escaped text; remote images and executable HTML are not loaded.
Attachments are served only after checking that they belong to a message in the signed-in
user's mailbox, and are forced to download with a sandbox policy.

## Migration to tidalwave.eu.org

First obtain ownership/delegation and a DNS provider with independent records. Prepare:

```dns
mail.tidalwave.eu.org.  A    130.61.169.52
tidalwave.eu.org.       MX   10 mail.tidalwave.eu.org.
tidalwave.eu.org.       TXT  "v=spf1 ip4:130.61.169.52 -all"
_dmarc.tidalwave.eu.org. TXT "v=DMARC1; p=none"
```

SPF must instead authorize the relay if one is used. Generate DKIM keys for the new
Stalwart domain and publish its exact selector TXT values from `x:Domain/get` / `dnsZoneFile`;
never invent a public key. Request matching PTR `130.61.169.52 -> mail.tidalwave.eu.org`
from the IP provider. Obtain a certificate for the new mail host and configure its SMTP
hostname. Leave the existing website hostname/auth configuration untouched.

Add the new Stalwart Domain, retain existing accounts, and update each account's `domainId`
through the root maintenance API while adding the old address as an alias. Update the
website's `mail_accounts.address` mapping and `config/mail.local.php` domain/domain_id in
the same maintenance window; changing just the config is insufficient for existing users.
Back up first, verify each mailbox and alias, then test authenticated delivery both ways.
Only enable `external_delivery` after external receipt is confirmed and DNS/TLS pass.
No domain migration, DNS change, or mailbox rename is performed automatically.

## Backup and rollback

`sudo bash deploy/mail-backup.sh` briefly stops only Stalwart, takes a consistent private
archive plus the website mailbox map and encryption configuration, then restarts it using
a trap. Keep an encrypted off-server copy; the VPS copy alone is not disaster recovery.
Never publish backups. Restore the matching data, mailbox map and encryption key together.
Restore is a destructive operation and requires operator confirmation.

To roll back the website release, restore the recorded Git revision and the backed-up
Nginx site, test `nginx -t`, and reload. New database tables are additive; keep them until
explicit cleanup is approved. Do not roll back or overwrite auth.local.php.
