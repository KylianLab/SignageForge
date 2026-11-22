# Deploying Signage Forge

Signage Forge is a regular Symfony application: PHP behind Apache or nginx, a
database, and a folder for uploaded media. No Node.js and no container are
required. A `Containerfile` is provided for those who prefer Podman.

## Requirements

- PHP ≥ 8.2 with `ctype`, `iconv`, `intl`, `pdo_sqlite` / `pdo_mysql` /
  `pdo_pgsql` (the one you use), and `ldap` if you sign in with LDAP/AD.
  RHEL 9 / Rocky / Alma: `dnf module enable php:8.2` (or 8.3), then
  `dnf install php-fpm php-intl php-pdo php-mysqlnd php-pgsql php-ldap php-opcache`.
  Debian 12: `apt install php-fpm php-intl php-sqlite3 php-mysql php-pgsql php-ldap`.
- Composer 2.
- A database: SQLite (nothing to install), MariaDB ≥ 10.6 / MySQL ≥ 8.0, or
  PostgreSQL ≥ 13.
- Apache 2.4 or nginx, in front of PHP-FPM.
- Building the CSS downloads the Tailwind binary from GitHub once
  (`var/tailwind/`). On a server without internet access, build on another
  machine and copy `public/assets/`, or set `binary` in
  `config/packages/symfonycasts_tailwind.yaml` to a binary you provide.

## Install

```bash
cd /srv
git clone <your Signage Forge repository> signageforge
cd signageforge
composer install --no-dev --optimize-autoloader
php bin/console signageforge:install
```

The installer asks for the database, the public URL, the real-time mode and a
first admin, writes `.env.local`, creates the schema and builds the front-end
assets. Every question has an option for scripted installs, see
`php bin/console signageforge:install --help`.

### Or set up from the browser

Skip `signageforge:install`, configure the web server (below), and open the
site: every page leads to `/install` until setup is done. The first step asks
for a setup token, which proves you have access to the server; read it with

```bash
cat var/setup-token.txt
```

The browser setup writes `.env.local`, so the web server user needs write
access to the project folder during setup (you can take it back afterwards).
Once an admin exists, `/install` is closed for good.

Then:

```bash
# The web server user must be able to write var/ and data/
chown -R apache:apache var data          # Debian: www-data
# SELinux (RHEL): allow writes to those two folders
semanage fcontext -a -t httpd_sys_rw_content_t "/srv/signageforge/(var|data)(/.*)?"
restorecon -R /srv/signageforge
```

Copy the PHP settings from `deploy/php.ini` into your PHP configuration
(`/etc/php.d/` or `/etc/php/8.x/fpm/conf.d/`). The upload limits there decide
the largest video you can upload.

Configure the web server with `deploy/apache.conf` or `deploy/nginx.conf`.
The document root is `public/`.

## Configuration

All settings are environment variables, documented in `.env`. Put your values
in `.env.local` (the installer writes it) or in the real environment of
PHP-FPM. For production, compile them once for speed:

```bash
composer dump-env prod
```

| Variable | Purpose |
| --- | --- |
| `DATABASE_URL` | Database connection. |
| `DEFAULT_URI` | Public URL, used in links generated outside a request. |
| `SIGNAGEFORGE_DATA_DIR` | Where media is stored (default `data/`). Back it up. |
| `SIGNAGEFORGE_REALTIME` | `polling` (default) or `mercure`. |
| `SIGNAGEFORGE_POLL_INTERVAL` | Seconds between checks in polling mode. |
| `TRUSTED_PROXIES` | IPs of your reverse proxy, so HTTPS and client IPs are right. |
| `SIGNAGEFORGE_UPDATE_REPOSITORY` | GitHub repository checked for new releases; empty turns the check off. |
| `SIGNAGEFORGE_WEB_UPDATES` | `true` allows one-click updates from the System page (off by default). |
| `SIGNAGEFORGE_TIMEZONE` | Time zone of screen schedules, e.g. `Europe/Brussels` (default: PHP's `date.timezone`). |
| `SIGNAGEFORGE_NEW_USER_ROLE` | Role of people signing in for the first time: `VIEWER` (default), `EDITOR` or `ADMIN`. |
| `AUTH_LOCAL_PASSWORD` | `false` hides email + password sign-in. |
| `ENTRA_*` | Microsoft 365 sign-in. |
| `LDAP_*` | LDAP / Active Directory sign-in. |

### Sign-in

- **Local accounts**: `php bin/console signageforge:user:create someone@example.org --admin`.
  Run it again on an existing email to reset the password.
- **LDAP / Active Directory**: set `LDAP_URL` (`ldaps://` recommended),
  `LDAP_BASE_DN`, a service account in `LDAP_BIND_DN` / `LDAP_BIND_PASSWORD`,
  and `LDAP_LOGIN_ATTR` (`sAMAccountName` for AD). Users are created in
  Signage Forge on their first sign-in.
- **Microsoft 365**: register an application in Entra ID with the redirect URI
  `https://<your-host>/login/microsoft/check`, then set `ENTRA_CLIENT_ID`,
  `ENTRA_CLIENT_SECRET` and `ENTRA_TENANT` (your tenant id).

The first person to sign in becomes the owner of the organization. Everyone
after that starts with `SIGNAGEFORGE_NEW_USER_ROLE` (viewer by default: with
LDAP or Microsoft 365, anyone in the directory can sign in). Admins change
roles under **Users** in the dashboard:

| Role | Can |
| --- | --- |
| Viewer | see screens, media and playlists |
| Editor | also manage media and playlists, choose what screens and groups play and when |
| Admin | also pair and remove screens, manage screen groups and users |
| Owner | also manage admins and owners |

## Real-time updates

**Polling** (default) needs nothing else: screens ask for their playlist every
`SIGNAGEFORGE_POLL_INTERVAL` seconds and get an empty `304` answer when nothing
changed. Changes reach screens within that delay.

**Mercure** pushes changes to screens instantly. It is a small separate
program (a single binary) that keeps the screens' connections open, so PHP
workers are not tied up:

1. Install the hub with `deploy/mercure.service`; set its two JWT keys to the
   `MERCURE_JWT_SECRET` from `.env.local`.
2. Expose it on the **same domain** as Signage Forge at `/.well-known/mercure`
   (commented blocks in `deploy/apache.conf` / `deploy/nginx.conf`). Turn
   proxy buffering off.
3. Set `SIGNAGEFORGE_REALTIME=mercure`, `MERCURE_URL` (where PHP publishes, can be
   `http://127.0.0.1:3000/.well-known/mercure`) and `MERCURE_PUBLIC_URL`
   (what screens use, `https://<your-host>/.well-known/mercure`).

If the hub goes down, the dashboard keeps working and screens fall back to a
one-minute check.

## Screens

On each display, open `https://<your-host>/pair` in a browser in kiosk mode
(Chromium: `--kiosk --autoplay-policy=no-user-gesture-required`). Claim the
code from the dashboard. The device remembers its player URL; media is cached
so playback survives network outages.

## Upgrading

The **System** page of the dashboard (admins) shows the installed version and
whether a newer release exists on GitHub. It checks at most every six hours;
set `SIGNAGEFORGE_UPDATE_REPOSITORY=` (empty) on servers that must not contact
the internet.

### From a release (recommended)

```bash
php bin/console signageforge:self-update
```

It downloads the ready-to-install package of the latest release, checks its
SHA-256, puts up a maintenance page (screens keep playing from their cache),
backs up the SQLite database, replaces the code, then runs the new version's
`signageforge:upgrade`. Your settings (`.env.local`), `var/` and `data/` are
never touched. If anything fails, the previous files are put back; they are
also kept in `var/update/previous/` until the next update.

**Servers without internet access:** download `signageforge-X.Y.Z.zip` and
`signageforge-X.Y.Z.zip.sha256` from the release page, copy both to the
server, then:

```bash
php bin/console signageforge:self-update --package=/tmp/signageforge-X.Y.Z.zip
```

**One click from the browser:** set `SIGNAGEFORGE_WEB_UPDATES=true` and the
System page offers an **Install** button that runs the same update in the
background. It is off by default because the web server user then needs write
access to the whole project folder, which many IT departments rule out.

### From git

```bash
git pull
composer install --no-dev --optimize-autoloader
php bin/console signageforge:upgrade
```

`signageforge:upgrade` puts up the maintenance page, copies the SQLite
database to `var/backups/`, runs the migrations, rebuilds the front-end assets
in production and clears the cache. If a step fails, the site stays in
maintenance: fix the problem and run the command again. With
MariaDB/MySQL/PostgreSQL, back up the database with your usual tools first.
Options: `--no-backup`, `--skip-assets`.

## Backups

Back up the database and the `data/` folder (media, and the SQLite file when
you use SQLite).

## Podman

```bash
podman build -t signageforge .
podman run -d --name signageforge -p 8080:80 \
  -v signageforge-data:/srv/signageforge/data \
  -e APP_SECRET="$(openssl rand -hex 16)" \
  -e DEFAULT_URI=http://localhost:8080 \
  -e DATABASE_URL="sqlite:///%kernel.project_dir%/data/signageforge.db" \
  signageforge
podman exec -it -u www-data signageforge php bin/console signageforge:user:create you@example.org --admin
```

Use `-e` for any other variable above. The container runs pending migrations on
start. To run it as a systemd service, generate a Quadlet unit or use
`podman generate systemd`.

## Publishing a release (maintainers)

1. Set the new version in `src/Version.php` and commit.
2. Tag and push: `git tag v0.2.0 && git push origin v0.2.0`.

The **Release** workflow (`.github/workflows/release.yml`) runs the tests,
builds the package with `php bin/build-release` (production dependencies,
compiled assets, no tests) and publishes it with its `.sha256` on the release
page. You can run `php bin/build-release` locally to check the package; it
writes to `var/release/`.
