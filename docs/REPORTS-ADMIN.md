# Reports database administration

The owner opens `/admin/users`, selects an existing Discord-linked account and enables
**Page administrator** and **Reports API**, then saves permissions. Page administrators
can manage projects; the additional API permission grants access to all Reports server
records. User, mail and bot administration and API credentials remain owner-only.
Revocation applies to the next request, including existing login sessions. Blocked users
cannot use the panel. Account deletion removes privileges, so signing up again does not
restore them. Roles are bound to Discord IDs, never display names.

Open `/admin/api` directly or use **Manage Reports API** on `/account`. Select a registered
server, a `sanction` or `report`, its record ID and a nonempty JSON object. Sanction IDs
from the plugin are `player-uuid:TYPE` (e.g. `MUTE`); report IDs are decimal report IDs.
The editor preserves arbitrary plugin fields and supports search and pagination.
JSON is limited to 24 KB and 32 levels of nesting. Existing records have Save and Delete
controls; deletion requires explicit confirmation. Concurrent modifications return 409
instead of overwriting a newer record. Changes are recorded with the acting Discord ID.

These operations modify the **website database replica only**. They do not send commands
to Minecraft or update its local SQLite database. A later plugin event for the same ID
can overwrite a website edit or recreate a deleted record. Website edits never advance
the plugin event cursor. Bidirectional Minecraft moderation is not implemented.

Example sanction data (expiry in Unix milliseconds, null for permanent):

```json
{"player_uuid":"00000000-0000-0000-0000-000000000000","player_name":"Example","type":"MUTE","reason":"Example reason","expires_at":null}
```

Validation: `tests/admin-http.php` covers grants, immediate revocation, forbidden areas,
CSRF, CRUD, stale updates, escaped JSON, separate server records and deletion of roles.
