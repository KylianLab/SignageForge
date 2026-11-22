# Signage Forge

Open-source, self-hostable **digital signage**. One app — your screens are just URLs.

Upload media, build a playlist, point a screen at it. It plays in a loop and
**keeps playing even when the network drops**.

## How it works

Signage Forge is a single Symfony app. A display device opens `/pair`, shows a short
code, and you claim it from the dashboard. From then on the device runs
`/player/<token>` fullscreen in a kiosk browser — works on a Raspberry Pi, a
mini-PC, an Android TV/Fire Stick, a smart TV, or just any browser.

- **Real-time** updates through a Mercure hub, or plain polling where nothing
  can be installed next to PHP.
- **Offline-resilient** — a service worker caches media so playback survives an
  outage.
- **Local media storage** on disk (an S3/MinIO driver can be added later).
- **Portable database** — SQLite, MariaDB/MySQL or PostgreSQL.
- **Flexible auth** — local email/password, **Microsoft 365 SSO** (Entra ID),
  and **LDAP/Active Directory**, each toggled by environment variables.
- **No Node.js** — front-end assets use AssetMapper and the standalone Tailwind
  binary.

## Authentication

The dashboard requires sign-in; the player and pairing endpoints stay open so
screens (which have no login) keep working. Three methods, all optional and
enabled purely via environment variables (see `.env`):

| Method                  | Enable by setting                                         |
| ----------------------- | --------------------------------------------------------- |
| Local email + password  | on by default (`AUTH_LOCAL_PASSWORD`); create users with `php bin/console signageforge:user:create` |
| Microsoft 365 SSO       | `ENTRA_CLIENT_ID` + `ENTRA_CLIENT_SECRET` (+ `ENTRA_TENANT`) |
| LDAP / Active Directory | `LDAP_URL` (+ bind DN / base DN)                           |

The login page renders only the methods you've configured. The first user to
sign in becomes the organization **owner**.

## Install on a server

```bash
composer install --no-dev --optimize-autoloader
php bin/console signageforge:install   # database, real-time mode, first admin
```

Then point Apache or nginx at `public/`. You can also skip the command and
finish the setup in the browser at `/install`. The full guide, with web server
configs, Mercure and Podman, is in [docs/deployment.md](docs/deployment.md).

## Quick start (development)

Requirements: PHP ≥ 8.2 (with `pdo_sqlite`, `intl`, `ctype`, `iconv`, and
`ldap` for LDAP sign-in) and Composer.

```bash
composer install
php bin/console doctrine:migrations:migrate -n
php bin/console signageforge:user:create admin@signageforge.test --password=signageforge-admin --admin
php bin/console tailwind:build --watch   # in a second terminal
symfony serve                            # or: php -S 127.0.0.1:8000 -t public
```

Then:

1. Open <http://localhost:8000/pair> on the device you want to use as a screen.
2. Open <http://localhost:8000/dashboard> → **Add screen** → enter the code.
3. Upload media, create a playlist, assign it to the screen. Done.

Checks:

```bash
composer test      # PHPUnit
composer phpstan   # static analysis (level 8)
```

## Tech stack

| Layer       | Choice                                            |
| ----------- | ------------------------------------------------- |
| Framework   | Symfony 7.4 LTS, PHP ≥ 8.2                        |
| Database    | Doctrine ORM → SQLite / MariaDB / PostgreSQL      |
| Real-time   | Polling with ETag (default) or a Mercure hub      |
| Storage     | Local disk                                        |
| UI          | Twig + Symfony UX (Turbo, Stimulus) + Tailwind 4  |

## Project layout

```
src/Controller/           pairing, player, media files, login
src/Controller/Dashboard/ screens, media, playlists (server-rendered, Turbo)
src/Entity/               Doctrine entities
src/Security/             local, LDAP and Microsoft 365 sign-in
src/Service/              tenancy, storage, player config, screen notifications
src/Command/              signageforge:install, signageforge:user:create
templates/                Twig templates
assets/controllers/       Stimulus controllers (player, pairing, playlist editor)
migrations/               portable Doctrine migrations (Schema API)
deploy/                   Apache, nginx, PHP and Mercure configs
public/sw.js              offline media cache (player)
docs/                     deployment guide
```

## Status & roadmap

This is an early MVP focused on the core loop: **pair → upload → playlist →
play (offline-safe)**, with authentication in place. Next up: a web installer
and updates from the admin area, roles, scheduling and screen groups. The full
list is in [ROADMAP.md](ROADMAP.md).

## License

Business Source License 1.1 (`BUSL-1.1`). See the `LICENSE` file.
