# TidalWave

An original, responsive company website built with PHP 8.3, HTML, CSS, vanilla
JavaScript and SVG. No Composer dependencies, JavaScript build step, Node server,
remote fonts or tracking scripts. Optional Discord authentication uses PHP cURL,
sodium and a private SQLite database; it is disabled until configured.

## Discord authentication and verification bot

See [the complete setup guide](docs/AUTHENTICATION.md). The included PHP verification
bot handles `/verify` through signed Discord webhooks. It needs a Discord application,
your server ID, a public HTTPS domain and private credentials in `config/auth.local.php`.
Verified accounts can install enabled full bots through a guarded OAuth2 code grant.
Full bots must already run separately; this installs them into Discord servers.
Credentials are configured privately on the server and are never included in Git.

## Bot control panel and online presence

The optional [bot control panel](docs/BOT-PANEL.md) gives one configured Discord account
access to source ZIP uploads, private environment settings, installation commands,
Node/Python/Java deployments, logs, and start/stop/restart controls. A separate Docker
worker runs bots with resource limits. The included gateway presence service keeps
the verification bot online while the PHP endpoint continues handling `/verify`.

The [community features](docs/COMMUNITY.md) add automatically published hosted bots,
projects with GitHub import and release downloads, account administration, and a
Discord-linked question-and-answer forum. `/verify` now works without a code.

## Reports API and Tidalwave Mail

See [mail installation, verified status and migration steps](docs/MAIL.md).

The `/api` page documents the private Reports API. A site administrator registers each
server at `/admin/api` and downloads its credential configuration. Servers send ordered,
JSON event batches to `POST /api/reports/v1/events` over HTTPS using their individual
Bearer credential. Credentials are stored hashed, can be rotated or disabled, and there
is no public read endpoint.

`/mail` is a Discord-authenticated mailbox for users whose accounts an administrator
has enabled at `/admin/mail`. Each mailbox uses its own Stalwart credential; mail data
is not accessible to the management identity. The private service endpoint must remain
bound to `127.0.0.1:18080`. Private settings belong in ignored `config/mail.local.php`,
never in Git. External delivery is disabled unless `external_delivery` is explicitly
enabled after mail DNS, TLS, reverse DNS, and provider port-25 policy are verified.
DuckDNS's update API supports address and TXT updates but not custom MX records; use a
domain/DNS provider that supports the records required for reliable public mail.

## Run locally

From this directory, with PHP 8.3 on your PATH:

```sh
php -S 127.0.0.1:8080 -t public dev-router.php
```

Open http://127.0.0.1:8080. The built-in PHP server and `dev-router.php` are for
local development only. Production uses Nginx and PHP-FPM.

On Windows, use a PHP 8.3 installation with cURL, PDO SQLite and sodium enabled
when testing account features. PHP runtimes are not included in this repository.

## Pages and editing

| Route | Content |
|---|---|
| `/` | Original wave hero, introduction, featured bot, project empty state, philosophy, API outlook, contact |
| `/about` | Identity, work, approach and vision |
| `/bots` | Data-driven bot directory, initially Synatra |
| `/projects` | Data-driven software directory; empty until verified projects are added |
| `/api` | Reports API documentation; authenticated event-ingestion endpoint |
| `/contact` | Email, copy action and optional configured social links |
| `/account` | Discord login, server verification, gated bot installation and account deletion |
| `/mail` | Private Discord-authenticated mailbox for administrator-enabled accounts |
| `/legal` | Scoped legal document directory |
| `/legal/privacy`, `/legal/terms`, `/legal/notice` | Clearly marked TidalWave placeholders |
| `/legal/synatra/privacy`, `/legal/synatra/terms` | Preserved Synatra drafts |

- `config/app.php`: email, official social URLs, CSS/JS asset version.
- `config/bots.php`: bot icon, name, description, confirmed features, status,
  documentation URL and legal routes. Public invite links are replaced with account
  verification. Synatra's release status was not supplied and is marked pending.
- `config/auth.example.php`: private configuration template for identity, verification
  and full-bot installation. Copy to ignored `config/auth.local.php`; never commit secrets.
- `config/mail.php`: safe mail defaults. Keep management credentials in the ignored,
  server-only `config/mail.local.php`; do not enable external delivery before DNS and
  reverse-DNS checks pass.
- `config/projects.php`: add only verified project entries. Each supports `name`,
  `description`, `technologies` (array), `status`, nullable `github_url` and
  nullable `documentation_url`.
- `src/Views`: page templates. `src/Components`: shared navigation, footer and cards.
- `public/assets/css/site.css`: responsive design and reduced-motion/print modes.
- `public/assets/images/authorized`: your own authorized assets; see its README.

All bundled graphics are original SVG. No Geometry Dash assets are downloaded or
embedded. The Synatra mark is an original illustrative placeholder, not a claim
that an official logo was supplied. Replace it with an authorized official icon
when available. Styling is polished without JavaScript; JavaScript progressively
adds the mobile menu, clipboard, print controls and a small scroll offset.

## Ubuntu / OCI deployment

Designed for Ubuntu 24.04 with Nginx 1.24, PHP-FPM 8.3, ARM64 and a small shared
VPS. No architecture-specific application binaries are shipped. The following
system setup requires an administrator; the application runs as unprivileged
`tidalwave`, and Git updates run as your deployment user.

1. The source repository is https://github.com/Tobias30yt/tidalwave.
   Do not publish secrets, local configuration backups, databases or log files.
2. Install server packages and create the dedicated runtime user:

```sh
sudo apt update
sudo apt install nginx php8.3-fpm php8.3-cli php8.3-opcache php8.3-curl php8.3-sqlite3 php8.3-common git
sudo adduser --system --group --no-create-home tidalwave
sudo install -d -o "$USER" -g www-data -m 0755 /var/www/tidalwave
git clone https://github.com/Tobias30yt/tidalwave.git /var/www/tidalwave
cd /var/www/tidalwave
sudo install -d -o tidalwave -g tidalwave -m 0750 storage/logs
sudo install -d -o tidalwave -g tidalwave -m 0700 storage/auth
```

The deployment user owns source files. Keep directories readable/traversable
(0755) and application files readable (0644). The PHP runtime should be able to
write only `storage/logs` and `storage/auth`, not application/configuration files. Do not use 0777.
Private auth configuration must be 0640 with group `tidalwave`; follow the auth guide.
Never point Nginx at the repository root; only `/var/www/tidalwave/public` is public.

3. Copy and edit the server examples:

```sh
sudo cp deploy/php-fpm.conf /etc/php/8.3/fpm/pool.d/tidalwave.conf
sudo cp deploy/nginx-http.conf /etc/nginx/conf.d/tidalwave-http.conf
sudo cp deploy/nginx.conf /etc/nginx/sites-available/tidalwave
# Edit server_name to your actual domain before enabling.
sudoedit /etc/nginx/sites-available/tidalwave
sudo ln -s /etc/nginx/sites-available/tidalwave /etc/nginx/sites-enabled/tidalwave
sudo cp deploy/logrotate.conf /etc/logrotate.d/tidalwave
sudo cp deploy/cron.conf /etc/cron.d/tidalwave-auth
sudo chmod 0644 /etc/cron.d/tidalwave-auth
sudo php-fpm8.3 -t
sudo nginx -t
sudo systemctl reload php8.3-fpm nginx
```

Remove an unused default Nginx site only after verifying it serves no other site.
The default FPM pool can remain if other applications use it; disable it only if
unused. The TidalWave pool uses at most 3 on-demand workers with a 64 MB PHP
memory limit each; measure actual load before increasing worker counts.

4. Point your domain's DNS to the VPS. Permit TCP 80/443 in the OCI network security
   group/security list and host firewall. Retain restricted SSH access. Do not expose
   the local development port 8080 or any PHP-FPM port to the internet.
5. Enable HTTPS using your certificate workflow. For an ordinary public domain:

```sh
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d YOUR_DOMAIN
sudo certbot renew --dry-run
```

Configure HTTP-to-HTTPS redirection. The app sends HSTS on direct HTTPS requests;
add `add_header Strict-Transport-Security "max-age=31536000" always;` in the
TLS server block to cover static responses too. Do not enable includeSubDomains
or preload without reviewing all relevant hosts. The sample config is an HTTP
bootstrap, not a complete TLS deployment. If adding a load balancer later, validate
trusted proxy handling explicitly; never trust arbitrary forwarded headers.

6. Enable the packaged OPcache for FPM. Suggested settings in
   `/etc/php/8.3/fpm/conf.d/99-tidalwave.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=64
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=2000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
expose_php=Off
```

Restart/reload PHP-FPM after changing ini settings. Settings affect all pools using
that PHP service; coordinate with any other PHP applications. Nginx caches static
assets for one day and compresses text/CSS/JS/SVG; HTML is revalidated every time.
Account pages and authentication responses use private no-store caching.

## Updates and rollback

Commit config/content changes to your repository. Before deployment, run tests on
the new revision in a staging checkout. Keep the previous commit hash and deploy
during a quiet interval; a basic Git pull is not an atomic deployment.

```sh
cd /var/www/tidalwave
git status --short
git rev-parse HEAD
git pull --ff-only
find . -type f -name '*.php' -not -path './storage/*' -exec php -l {} \;
sudo systemctl reload php8.3-fpm
curl -I https://YOUR_DOMAIN/
```

Stop if the working tree has unexpected changes or the pull is not fast-forward.
Increment `asset_version` after CSS/JS changes. If verification fails, restore the
previous verified revision using `git switch --detach PREVIOUS_COMMIT` on a clean
checkout, reload FPM, and verify again. Keep logs and local secrets outside Git.
For zero-downtime updates, deploy tested release directories and atomically switch
a `tidalwave` symlink, preserving writable storage and reloading OPcache afterward.

## Verification

With the local server running, execute the integration checks (Python standard
library only; Python is a development tool, not a serving dependency):

```sh
python tests/smoke.py http://127.0.0.1:8080
php tests/security.php
php tests/auth.php
# Dedicated localhost preview only, no pre-existing auth.local.php:
php tests/auth-http.php http://127.0.0.1:8080
```

Checks cover all pages, HTTP status/security headers, internal links and fragments,
asset availability, blocked internal files, unsupported methods, HTML structure,
and verbatim legal section preservation against checksummed snapshots.
Also check a desktop and narrow viewport, keyboard navigation, mobile menu Escape,
clipboard success/fallback, print layout, and OS reduced-motion preferences.

## Security and privacy

Routing uses a fixed allowlist, never user-controlled template includes. Configured
URLs allow local paths or HTTPS only; displayed content is escaped. The front
controller sets CSP, nosniff, frame restrictions, referrer and permissions policies.
Account state changes require POST and CSRF tokens; OAuth callbacks require single-use
session-bound state and the Discord issuer. The bot interaction endpoint uses Ed25519
signatures instead of browser CSRF. HTTP-only SameSite cookies, session regeneration,
timeouts, code hashing, expiry, atomic consumption and rate limits protect accounts.
All bot installations require server verification and fresh membership checks. The
Discord code-grant flag is checked before issuing and redeeming the authorization.
Unsupported methods are rejected with 405. No uploads or public developer API exist.
Errors are logged without credentials or exception argument traces and not displayed.
Keep PHP/Nginx security updates current and rotate logs. Verify actual log retention
and providers in your legal documents before publishing.

## Legal and content readiness

The code is deployable; public-launch legal readiness is intentionally incomplete.
Both original Synatra policies were reviewed. All original legal section wording,
warnings and prepared dates are preserved in their scoped pages. Their original
GitHub Pages references are explicitly contextualized, not silently replaced with
OCI claims. See `docs/legal-source/README.md` and checksums for provenance.

Before public launch, complete/review the TidalWave operator identity, Impressum,
privacy information (including new account processing), applicable terms and Synatra
draft details. Configure Discord applications, full-bot credentials, documentation
and official profile URLs when available. Add verified project data
when available. No fabricated teams, metrics, service status or achievements are used.
No API launch date is promised. A rights-reserved LICENSE is supplied pending the
owner's licence choice; the project is not claimed to be open source.

## Troubleshooting

- **502**: check `systemctl status php8.3-fpm`, `journalctl -u php8.3-fpm`, the pool
  syntax, `/run/php/tidalwave.sock` and socket group permissions.
- **404 on all pages**: verify `root`, `try_files`, enabled server block and domain.
- **403 / missing styles**: check traversal permissions, correct document root and
  asset files; never solve this by exposing the repository or setting 0777.
- **500**: inspect `storage/logs/php-error.log` and Nginx/FPM logs. Check PHP version,
  template/config syntax and log-directory ownership. Keep display_errors disabled.
- **Stale CSS**: update asset_version and reload; check Nginx caching.
- **Clipboard blocked**: HTTPS or localhost is needed. The fallback selects the
  address and tells the visitor to copy manually; mailto works without JavaScript.
- **High memory/CPU**: inspect worker usage and the separate Discord bot; tune only
  after measuring. There is no polling or animation library. Authentication uses a
  small local SQLite database and an HTTP verification bot with no persistent process.
- **TLS/DNS failures**: check DNS, OCI rules, host firewall and certificate renewal.
