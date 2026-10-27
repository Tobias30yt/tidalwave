# Catalog, projects, accounts, and forum

Google sign-in is intentionally not included. Every website account is identified
by its Discord ID, obtained through Discord OAuth. `/verify` takes no code: the
signed command identifies the account and verification guild. Sign in on the website
first. Unknown accounts are not created by commands, blocked users cannot verify,
and timestamp, signature, guild, application, screening, and replay checks remain.

## Bots

There is no fixed number-of-bots cap. Runtime memory/CPU/process limits and available
server capacity still apply. The first successful deployment sets `published_at`;
the public `/bots` page displays its name, description, runtime, and hosting status.
Existing successfully hosted bots are migrated into the catalog. Undeployed drafts
and private source, environment, command, and log data are never published. Removing
a bot removes its public listing. Catalog publication does not create a Discord app
or bypass the existing verification gate for installing configured full bots.

## Projects

The owner and accounts granted Page administrator in `/admin/users` can access `/admin/projects`. Projects can contain
a public GitHub repository URL, a source ZIP, release attachments, or a combination.
GitHub import reads repository name, description, language, stars, and the latest
published release through the public GitHub REST API. It uses a fixed API host,
strict repository URL parsing, no redirects, and bounded responses/timeouts. Import
runs on initial link/change or an explicit refresh checkbox. Private repositories
are not supported. API errors preserve an existing saved project.

Uploads are limited to 25 MB per file; source ZIPs use the bot archive validation.
Release files are opaque downloads. Files are stored outside the public web root
under generated IDs. Draft projects and their direct download URLs remain private.
The Publish checkbox exposes the project and its attachments. Downloads use
`application/octet-stream`, attachment disposition, no-sniff, and a sandbox CSP.
Unpublishing immediately denies future downloads; deletion removes stored files.
Inspect source and release files for secrets before publishing.

## Accounts

`/admin/users` supports searching, blocking, unblocking, and deleting accounts.
The configured administrator cannot block or delete itself through this panel.
Blocking rotates the session key, revokes verification, and preserves a separate
blocked-ID record. Deleting a blocked account retains that block; the deleted
identity remains in the user panel so it can be unblocked. Other deleted accounts
may sign in again, receiving a new account generation and unverified state.
Deletion removes account-linked verification and installation records and anonymizes
existing forum contributions. Admin mutations are recorded in the private audit table.

## Forum

`/forum` lists public questions with search and pagination. Signed-in, unblocked
Discord users may create questions and replies. Questions require 5–160 title
characters and 10–12000 body characters; replies require 2–12000. HTML is escaped
and rendered as plain text. Posting requires CSRF and server-side account checks.
Limits are five questions/hour and twenty replies/ten minutes per account.
Authors may mark their question solved and delete their own questions or replies.
The administrator can also lock/unlock discussions and moderate other posts.
Locked questions reject replies. Deleting a question removes its replies.

## Deployment

Before upgrading, back up SQLite databases with SQLite's backup API. Initialize
`AdminStore`, `ProjectStore`, and `ForumStore` as the app user. Copy the updated
worker to `/opt/tidalwave-runner/worker.py` and restart the worker while its queue is
idle; existing bot containers continue running. Re-register `/verify` using
`php bin/register-verification-bot.php` as the app user.

Allow 52m request bodies only for nginx's exact `/admin/projects/save` location;
keep `/admin/save` at 27m. The PHP pool uses upload_max_filesize=25M and
post_max_size=52M. No `auth.local.php` changes are required.

Validation: `tests/community.php`, the expanded `tests/admin-http.php` in a separate
localhost fixture, `tests/auth-http.php`, and `tests/runner_integration.py` cover
account/CSRF boundaries, publishing and download privacy, moderation, code-free
verification, and successful deployment publication. Never run localhost fixture
tests in a checkout containing real local configuration.
