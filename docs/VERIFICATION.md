# Verification — 2026-10-24

Completed locally on Windows using official PHP 8.3.36, with no application packages:

- PHP syntax checks passed for all application PHP files and test helpers.
- Vanilla JavaScript passed `node --check` (development check only).
- HTTP integration suite passed for 13 page routes and 5 referenced assets.
- Internal links and fragments resolve; pages have one h1 and one main landmark,
  with no duplicate IDs or dummy `href="#"` controls.
- CSP, nosniff, frame restrictions and absence of newly created cookies on public pages verified.
- Private paths return 404; unsupported state-changing methods return 405;
  HEAD has an empty response body; unknown API paths remain 404.
- URL validation and context-appropriate HTML escaping tests passed.
- All 32 Synatra legal sections are byte-identical to their retrieved HTML sections
  after the documented internal-link mapping. Source SHA-256 hashes match.
- Desktop homepage visually inspected, including its complete below-fold content.
- All 12 pages checked at a 390px viewport with no horizontal overflow.
- Mobile homepage and Synatra legal page visually inspected.
- Mobile menu expansion, Escape closure and focus restoration verified.
- Contact email copying produced the success status in the browser.
- Browser error/warning log was empty during the checked interactions.
- PHP error-log directory remained empty during successful checks.

Authentication update:

- 42 service checks cover identity binding, username changes, hashed and replaced
  codes, atomic one-time use, expiry, wrong accounts, signature tampering, stale
  timestamps, PING, application/guild checks, screening, replay rejection, membership,
  disabled code grants, provider failure, blocks, account deletion/recreation,
  rate limits, CSRF, OAuth state binding, session rotation and idle timeout.
- 25 HTTP checks passed using synthetic credentials and locally signed interactions:
  cookie flags, no-store, OAuth start URL/scope/state, wrong issuer, state replay,
  anonymous/unverified installs, code generation, unsigned/tampered/stale webhook
  rejection, signed PING and verification, disabled bots, deletion and session clearing.
- No test followed an OAuth redirect to Discord or installed a bot on a real server.
- Account login layout inspected at desktop and 390px widths without overflow.
- Authentication remains disabled in the delivered configuration; no real credentials
  are present. The temporary HTTP fixture configuration is removed after tests.

Reduced-motion and print styles are included and inspected in source. They were
not emulated in the browser. Clipboard-denial fallback is implemented but was not
forced in the browser. No automated accessibility audit or real assistive-device
test was performed. Screenshots are local QA outputs under ignored `tests/output`.

Not performed: deployment to OCI, Linux Nginx/FPM configuration validation, TLS
provisioning, load testing, external social/invite link validation (not supplied),
or legal readiness review. Run the documented server configuration checks on the
target host. Live Discord OAuth and real bot installation are not validated until
the operator configures their applications, bot settings, guild and HTTPS domain.
The Nginx logging format was updated to exclude OAuth callback query strings.
Run both PHP-FPM and Nginx configuration tests after installing the new snippets on the
target host. Both Synatra documents remain drafts and TidalWave documents remain
explicit placeholders. No VPS access, domain or GitHub remote was supplied.
