# Discord sign-in, server verification, and bot installation

The website now contains the verification bot itself. It receives Discord-signed
HTTP interactions through PHP at `/discord/interactions`. The separate
`tidalwave-presence` gateway service keeps it online; signed commands continue
to be handled by PHP. No message-content intent is needed.

Discord is the identity provider. Email and Google fallback were not needed for
this implementation. Either provider would still need a separate Discord link to
prove control of a Discord account; they must not bypass verification.

## What happens

1. A visitor opens `/account`, submits the CSRF-protected sign-in form, and grants
   Discord's `identify` scope. The callback checks its single-use, session-bound
   state and Discord issuer, then retrieves `/users/@me`. The permanent Discord
   ID is the identity key; a rename does not create a new identity.
2. They sign in with their Discord account on the website, then open the verification server.
3. The same account uses `/verify` with no arguments.
   Discord's Ed25519 signature, timestamp, application ID, guild ID and member ID
   must all match. The response is ephemeral. DMs and other servers are rejected.
   The signed member ID must match an existing, unblocked website account.
   Interaction replays cannot repeat a mutation. The old code endpoint remains
   available for compatibility, but the website and registered command no longer require it.
4. Verification lasts 30 days. Before BOTH initiating and completing a full-bot
   install, the server checks verification and current membership in your server,
   including membership screening. Discord/network errors deny installation.
5. An enabled bot uses the full OAuth2 code grant. The code is exchanged only from
   a verified website session. Discord checks permission to add apps in the selected
   destination server. The guild ID recorded comes from the token response, never
   the untrusted callback query. No user OAuth tokens are persisted.

This authorizes a verified **website account** to initiate/complete installation.
Discord's installation dialog controls which Discord account authorizes the
destination server; this flow does not collect that second account's identity.
It does not start a bot process or implement Synatra's conversational features.
The full bots must already be hosted separately. It does not remove previously
installed bots or continuously revoke their access when a member leaves.

## 1. Server prerequisites

Install the standard Ubuntu PHP extensions (SQLite is a local file, not a daemon):

```sh
sudo apt install php8.3-curl php8.3-sqlite3 php8.3-common
php -m # confirm curl, pdo_sqlite, sodium, session
cd /var/www/tidalwave
sudo install -d -o tidalwave -g tidalwave -m 0700 storage/auth
sudo cp deploy/nginx-http.conf /etc/nginx/conf.d/tidalwave-http.conf
# Update the site block from deploy/nginx.conf as well.
sudo nginx -t
sudo systemctl reload nginx php8.3-fpm
```

The database, WAL files and sessions live under private `storage/auth`. Keep this
directory out of the public document root, Git, source archives and public backups.
Protect any operational backups and include their retention in the privacy review.
Only the runtime user should write this directory. Source code remains read-only
to that user. Sessions idle out after 1 hour, with an 8-hour absolute limit.

The Nginx log format excludes query strings so OAuth codes and states are not
logged. Ensure any reverse proxy/CDN also excludes them. Never log request bodies,
cookies, Authorization headers or bot tokens. PHP exception arguments are disabled.
The PHP development server logs request URLs: use it only with test credentials.

## 2. Create your verification application in Discord

In the [Discord Developer Portal](https://discord.com/developers/applications):

1. Create a dedicated application, for example **TidalWave Verify**.
2. Record its Application ID and Public Key from General Information. Generate
   a client secret under OAuth2 and a bot token under Bot. Keep secrets private;
   never paste them into the website's public files or commit them to Git.
3. Enable **Guild Install** for this application. Keep **User Install** disabled.
   No privileged Gateway intents or Administrator/Manage Roles permission is needed.
4. Add this exact OAuth2 redirect URI: `https://YOUR_DOMAIN/auth/discord/callback`.
5. Add the verification bot to your server using Discord's OAuth2 URL generator:
   scopes `bot` and `applications.commands`, bot permissions `0`. You must have
   permission to add applications to the server. This is the operator's setup
   installation, not a gated end-user full-bot installation. Leave **Requires OAuth2
   Code Grant** off for this dedicated verification application.
6. Enable Developer Mode in Discord and copy your verification server's ID.
   In Server Settings → Integrations, allow intended members to use `/verify` in
   an appropriate channel; they need Use Application Commands and channel access.
   Complete any server membership screening before verification.

## 3. Configure the website

```sh
cp config/auth.example.php config/auth.local.php
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Edit `auth.local.php` with your real HTTPS origin, the generated app key, application
ID, client secret, public key, bot token, guild ID and optionally a real guild invite.
Set `enabled` to true. The origin has no trailing slash or path; callbacks must match
it exactly. Do not use an arbitrary Host or forwarded header as the origin.

```sh
sudo chgrp tidalwave config/auth.local.php
chmod 0640 config/auth.local.php
sudo -u tidalwave php bin/auth-admin.php init
```

Set the application's **Interactions Endpoint URL** in Discord to:

```text
https://YOUR_DOMAIN/discord/interactions
```

Discord verifies this by sending a signed PING. The endpoint validates the raw body
and returns the required PONG. Invalid signatures return 401. Keep the VPS clock
synchronized; timestamps more than five minutes away are rejected. The endpoint
does no outgoing API calls when processing `/verify`, keeping responses within
Discord's initial-response deadline.

Register the one server-scoped slash command after adding the bot:

```sh
sudo -u tidalwave php bin/register-verification-bot.php
```

This explicitly writes the `/verify` command to your configured Discord server.
It updates that named command only, preserving unrelated commands.

## 4. Enable a full bot when it is ready

For each full bot, use its own application ID, client secret and bot token in
`auth.local.php` → `installations`. The slug must exist in `config/bots.php`.
Synatra is initially disabled because its credentials and permissions were not supplied.

In that full bot's Discord Developer Portal settings:

- Enable **Requires OAuth2 Code Grant**. This is essential: ordinary bot invite
  URLs otherwise bypass a website gate. The website fetches the application settings
  and refuses to proceed if Discord reports this flag is false or the token belongs
  to a different application.
- Enable Guild Install only. Disable User Install for these guild-only flows.
- Configure Public Bot as appropriate for your audience (non-owner installations
  need it enabled). Code-grant enforcement must remain enabled.
- Register `https://YOUR_DOMAIN/bots/synatra/install/callback` (replace the slug
  for another bot). Remove unused redirect URIs and other ungated code-exchange
  backends. Any other backend with the client secret could independently add bots.
- Set the custom installation link to `https://YOUR_DOMAIN/account`. Do not use
  a public raw invite link in this website's cards.
- Choose only the permissions actually required by the full bot; set the numeric
  `permissions` string accordingly. No Administrator permission is requested by default.
- Set the installation's `enabled` to true after testing your real bot.

The server checks this guard on the way to Discord and again at the callback.
Changing the bot's Discord setting away from code-grant enforcement can expose
direct invites outside this website; keep it enabled. This is an operator setting,
not something the website can force globally.

## Administration and maintenance

```sh
sudo -u tidalwave php bin/auth-admin.php revoke DISCORD_USER_ID
sudo -u tidalwave php bin/auth-admin.php block DISCORD_USER_ID
sudo -u tidalwave php bin/auth-admin.php unblock DISCORD_USER_ID
```

Revoke resets verification and pending codes; a permitted member can reverify.
Block also prevents sign-in/reverification/installation and persists across account
deletion/recreation. Unblock clears that block but still requires fresh verification.
These actions do not uninstall bots already in other servers.

Run hourly as the runtime user, for example in `/etc/cron.d/tidalwave-auth`:

```cron
17 * * * * tidalwave /usr/bin/php /var/www/tidalwave/bin/auth-admin.php cleanup
```

Expired rows and old session files are removed. Expiry is checked during use even
before physical cleanup. Accounts remain until deletion; blocked identifiers remain
until unblocked. Review these choices, backups and actual hosting with the legal
documents before enabling public sign-in. The TidalWave privacy placeholder now
describes account processing; the preserved Synatra policy snapshots are unchanged.

## Failure behavior and validation

- Missing setup: the account page explains that setup is pending; installation stays locked.
- Cancelled/expired OAuth: start again from `/account`. Only the latest OAuth flow
  in a browser session remains valid. Cookies must be enabled.
- Invalid CSRF: the action is rejected; reload the account page.
- Discord unavailable/rate-limited: deny installation and invite the visitor to retry.
- Wrong server/account, expired/replayed code or incomplete screening: no verification.
- No `/verify`: check installation scopes, registration, command/channel permissions.
- Signature failure: check the public key, application ID, clock and unmodified raw
  request body. Do not disable signature checking to troubleshoot.
- SQLite errors: check extension availability and ownership of storage/auth (including
  existing SQLite/WAL files). Never fix this by making the directory public or 0777.

Run `php tests/auth.php` with curl, sodium and pdo_sqlite enabled, plus the existing
smoke/security tests. The HTTP fixture suite `php tests/auth-http.php ORIGIN` requires
a dedicated localhost PHP preview with the same project and no existing
`config/auth.local.php`; it refuses to overwrite a real configuration. Its generated
credentials are synthetic, and it never follows a redirect to Discord.

Live Discord OAuth, signed callbacks from Discord, and real bot installation still
require your applications, server, domain and credentials. Offline tests are not
evidence that those external settings are correct.

## Primary references

- [Discord OAuth2 and advanced bot authorization](https://docs.discord.com/developers/topics/oauth2)
- [Discord interactions overview and signature validation](https://docs.discord.com/developers/interactions/overview)
- [Application commands](https://docs.discord.com/developers/interactions/application-commands)
- [Application settings and code-grant flag](https://docs.discord.com/developers/resources/application)
- [Guild membership](https://docs.discord.com/developers/resources/guild)
