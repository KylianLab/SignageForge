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

The first person to sign in becomes the owner of the organization.

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

Get the new code, install its dependencies, then let the upgrade command do
the rest:

```bash
git pull                     # or unpack the new release
composer install --no-dev --optimize-autoloader
php bin/console signageforge:upgrade
```

`signageforge:upgrade` puts up a maintenance page (screens keep playing from
their cache), copies the SQLite database to `var/backups/`, runs the
migrations, rebuilds the front-end assets in production and clears the cache.
If a step fails, the site stays in maintenance: fix the problem and run the
command again. With MariaDB/MySQL/PostgreSQL, back up the database with your
usual tools first. Options: `--no-backup`, `--skip-assets`.

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
