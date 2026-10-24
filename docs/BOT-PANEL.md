# Bot control panel

`/admin` is restricted to the immutable Discord account ID in `config/admin.local.php`.
The link appears in that account's `/account` page after Discord login. Usernames never
grant privileges. Every mutation requires the signed-in account and a session CSRF token.
The existing `auth.local.php` is not modified by this feature.

## Using the panel

1. Create your application and bot in the Discord Developer Portal; obtain its token.
2. Create a bot in the panel. Upload a ZIP containing source files (25 MB compressed,
   100 MB extracted, maximum 2000 entries). Exclude `.env`, `.git`, `node_modules`, and venvs.
   A single enclosing source folder is stripped automatically.
3. Choose Node.js 22, Python 3, or Java 17. Enter `node index.js`, `python bot.py`, or
   `java -jar bot.jar`. Quoted arguments are accepted. This is an argument list, not a shell.
4. Add installation commands, for example `npm ci`, `pip install -r requirements.txt`,
   or `sudo apt-get update && sudo apt-get install -y ffmpeg`. Commands execute as root
   **inside the isolated setup container**, with a 15-minute timeout. Host sudo is not exposed.
5. Enter `.env` as unquoted `KEY=value` lines. Values are literal, not shell-expanded.
   They are injected into the process environment and `/app/.env`. Installation does not
   receive these values. Keep credentials out of installation commands and source archives.
6. Save, then choose **Deploy saved version**. The worker installs dependencies once per
   source/installation revision, reusing the image when only environment/start settings change.
   Start, stop, and restart reuse the deployed container. Refresh the page to update logs.

A running container retains files across stops/restarts. Deploy replaces its filesystem
with the source image; back up bot-generated data before deploying a replacement.
Failed installation leaves the previous bot in place. An immediate startup failure rolls
back to the previous container. A process that starts successfully can still fail later;
inspect its logs. The panel does not create Discord applications or issue their tokens.

## Isolation and limits

PHP cannot access the Docker socket. A root-owned, fixed-function worker accepts only
known queue actions and generated identifiers. No command from an upload is run by the
host shell. ZIPs are validated both by PHP and the worker, rejecting traversal, links,
encrypted entries, secret files, and oversized expansion. Each bot gets a separate
container without host mounts, published ports, devices, or elevated runtime capabilities.
Runtime is uid 1000 with no-new-privileges, 512 MB RAM, 1 CPU, and 128 processes.
Setup is limited to 1 GB RAM, 1 CPU, and 256 processes. Four bot records are allowed.
The dedicated bridge blocks private network ranges, cloud metadata, and host input.
Logs rotate at 2 MB with two files. The worker displays the last 32 KB and redacts saved
environment values; bot authors must still avoid printing encoded/transformed secrets.
Writable container data is checked every ten seconds and stopped above 512 MB or when
the host's 3 GB disk reserve is reached. This is monitoring, not a filesystem quota.
Uploaded archives and environment values are private files outside the public web root;
server administrators can access them. Protect server backups accordingly.

## Server setup

Install Docker and Python 3. Build `tidalwave-runtime:v1` with
`docker build -f deploy/bot-runtime.Dockerfile -t tidalwave-runtime:v1 .`.
Create `config/admin.local.php`, owned root:tidalwave mode 0640, returning
`['owner_id' => 'YOUR_DISCORD_USER_ID']`. Never configure access by username.
Initialize `AdminStore` as the website user, then copy `runner/worker.py` and
`deploy/bot-network.sh` to `/opt/tidalwave-runner/` as root-owned files (0644 and 0755).
Install `deploy/tidalwave-runner.service` into `/etc/systemd/system/`, create
`/var/lib/tidalwave-runner` root-owned mode 0700, then enable the service.
Increase only the app's PHP pool upload limits to 25M/27M and its nginx location
`/admin/save` body limit to 27m. The admin storage directory remains mode 0700.

The runner polls SQLite every two seconds, records changes in an audit table, and marks
interrupted jobs failed on restart. Re-submit a failed deployment after examining logs.
Service code changes must be copied to `/opt/tidalwave-runner/worker.py` and the service restarted.

## Verification bot presence

The `/verify` handler still receives signed Discord HTTP interactions. A separate
`discord.py` gateway client with no privileged intents advertises online presence.
It reads the existing bot token privately through PHP and never logs it. Install
`runner/requirements.txt` into `/opt/tidalwave-presence`, then install and enable
`deploy/tidalwave-presence.service`. Discord credentials and the existing HTTP endpoint
stay unchanged. See the [Discord gateway documentation](https://docs.discord.com/developers/events/gateway)
and [Docker resource constraints](https://docs.docker.com/engine/containers/resource_constraints/).
