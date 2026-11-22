# Roadmap

Signage Forge is an early MVP. The core loop works: **pair a screen → upload
media → build a playlist → play it in a loop, even offline**, with local, LDAP
and Microsoft 365 sign-in.

This list is ordered by priority, not by date. Items move up when someone
needs them; open an issue to discuss one.

## Before a first release

Things that exist in the code but have not been checked against real systems
yet.

- [ ] Sign in with Microsoft 365 against a real Entra ID tenant
- [x] Sign in against a real LDAP directory (public OpenLDAP test server)
- [ ] Sign in against Active Directory
- [x] Run the migrations and the test suite on real MariaDB 11.2 and MySQL 8.2 servers
- [ ] Same on a real PostgreSQL server
- [ ] Build and run the `Containerfile` with Podman
- [ ] Run a real Mercure hub behind Apache and nginx
- [x] Play videos and survive a network outage, including a restart, in a desktop browser
- [ ] Same on real kiosk devices (Raspberry Pi,
      Android TV)

## Installation and updates

- [x] **Web installer.** First-run setup in the browser: database with a
      connection test, public URL, real-time mode, first admin. Protected by a
      one-time setup token and locked once done. The `signageforge:install`
      command stays for scripted installs.
- [ ] **Web updates.** See the installed and the available version, then run
      the update from the admin area: maintenance mode, database backup,
      migrations, asset rebuild, cache clear.
- [x] **`signageforge:upgrade` command** doing the same from the terminal.

## Managing screens and content

- [x] Per-user roles enforced in the UI (owner, admin, editor, viewer), with a Users page
- [ ] Scheduling: time-of-day and day-of-week playlists
- [ ] Screen groups
- [ ] Portrait / landscape orientation per screen (stored today, not used yet)
- [ ] Widgets and overlays: clock, weather, news ticker

## Platform

- [ ] Several organizations on one install (multi-tenant)
- [ ] S3 / MinIO storage for media
- [ ] Interface translations, starting with French and Dutch
