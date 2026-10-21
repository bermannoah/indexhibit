# Indexhibit

Indexhibit is an archetypal portfolio CMS for everybody.

https://www.indexhibit.org

Indexhibit is a registered trademark of Jeffery Vaska and Daniel Eatock.

This fork is hardened for modern PHP and containerized deployment behind a CDN.

---

## Requirements

- PHP 8.5.12+
- MySQL or MariaDB
- Apache with `mod_rewrite` (recommended) or an equivalent rewrite setup
- PHP extensions: `mysqli`, `pdo_mysql`, `gd`, `mbstring`, `curl`, `exif`, `intl`

Docker is the easiest way to run the full stack locally or in production.

---

## Local development (Docker)

```bash
docker compose up --build
```

With `AUTO_INSTALL=1` (the default in `docker-compose.yml`), the container
creates the schema and seeds the initial user on first boot. Then log in at:

```text
http://localhost:8080/ndxzstudio/
```

Default login:

- **Username:** `index1`
- **Password:** `exhibit`

Change it immediately after first login.

Stop the stack with:

```bash
docker compose down
```

Add `-v` only if you also want to wipe the database and uploaded files:

```bash
docker compose down -v
```

---

## Environment variables

The container reads configuration from environment variables and writes
`ndxzsite/config/config.php` at boot.

| Variable | Required | Description |
| --- | --- | --- |
| `DB_HOST` | yes | MySQL/MariaDB host |
| `DB_NAME` | yes | Database name |
| `DB_USER` | yes | Database user |
| `DB_PASS` | yes | Database password |
| `DB_PREFIX` | no | Table prefix, default `ndxz_` |
| `PUBLIC_URL` | yes | Public base URL, e.g. `https://example.com` |
| `TRUSTED_PROXIES` | no | `*` to trust Fly/Bunny forwarded headers, or a CSV of proxy IPs |
| `AUTO_INSTALL` | no | `1` to install the schema/seed automatically on first boot |
| `SITE_NAME` | no | Site name used during auto-install |
| `ADMIN_EMAIL` | no | Initial admin email during auto-install |
| `ADMIN_FNAME` | no | Initial admin first name |
| `ADMIN_LNAME` | no | Initial admin last name |
| `BUNNY_API_KEY` | no | Bunny.net API key for cache purge |
| `BUNNY_PULL_ZONE_ID` | no | Bunny pull zone ID for cache purge |
| `MEDIA_DRIVER` | no | `local` (default) or `bunny` (mirror uploads to a Storage Zone) |
| `MEDIA_URL` | no | CDN hostname for uploaded media (storage-zone pull zone); empty = serve from `BASEURL` |
| `BUNNY_STORAGE_ZONE` | no | Storage Zone name (not the pull zone) |
| `BUNNY_STORAGE_KEY` | no | Storage Zone password (uploads/deletes); not the account API key |
| `BUNNY_STORAGE_ENDPOINT` | no | Storage API endpoint; default `https://storage.bunnycdn.com` |
| `FORCE_CONFIG_GEN` | no | Set `1` to regenerate `config.php` on next boot |

A template is available in `.env.example`.

---

## Deploying to Fly.io

The repository includes two Fly configurations:

- `fly.toml` — the PHP/Apache web app
- `fly.mariadb.toml` — a MariaDB 11 app with a persistent volume

### 1. Create the MariaDB app

```bash
fly launch --name indexhibit-db --config fly.mariadb.toml --no-deploy
fly secrets set \
  MARIADB_PASSWORD='<db-password>' \
  MARIADB_ROOT_PASSWORD='<root-password>' \
  -a indexhibit-db
fly deploy -c fly.mariadb.toml
```

### 2. Create the web app

```bash
fly launch --name indexhibit --config fly.toml --no-deploy
fly secrets set \
  DB_HOST='indexhibit-db.flycast' \
  DB_NAME='indexhibit' \
  DB_USER='indexhibit' \
  DB_PASS='<db-password>' \
  BUNNY_API_KEY='<bunny-api-key>' \
  BUNNY_PULL_ZONE_ID='<bunny-pull-zone-id>' \
  -a indexhibit
fly deploy -c fly.toml
```

If `.flycast` resolution is unavailable in your organization, use the private
IP of the MariaDB app instead:

```bash
fly ips list -a indexhibit-db
```

### 3. Database passwords

Generate them with `openssl rand -hex 32` (hex avoids shell/URL quoting
issues) and use two distinct values:

```bash
app_pass=$(openssl rand -hex 32)   # MARIADB_PASSWORD and DB_PASS
root_pass=$(openssl rand -hex 32)  # MARIADB_ROOT_PASSWORD
```

- `MARIADB_PASSWORD` and `DB_PASS` must be the **same** value — that is the
  app user credential.
- `MARIADB_ROOT_PASSWORD` is separate. Generate a fresh, distinct value; do
  not reuse the app password.
- Keep them in Fly secrets, never in `fly.toml` (which is committed).
- The MariaDB image only applies `MARIADB_*` on the **first** initialization
  of an empty data volume. To rotate a live database, change the password in
  MariaDB first (`ALTER USER …`), then update the secret and redeploy.

The thin deploy script can generate them for you (`./deploy.sh gen-passwords`
— see [docs/DEPLOY.md](docs/DEPLOY.md#7-alternative-thin-deploy-repo)).

### 4. Log in

`AUTO_INSTALL=1` in `fly.toml` seeds the database on first boot. Log in at:

```text
https://example.com/ndxzstudio/
```

Change the default password immediately.

Deploying from a private mirror of this repo? See
[docs/DEPLOY.md](docs/DEPLOY.md#deploying-from-a-private-repository).

---

## Bunny.net CDN

Bunny.net is configured as a **pull** CDN for static assets only. Point the
pull zone at the Fly app origin, add your public hostname, then add edge rules:

| Path | Action | Edge TTL |
| --- | --- | --- |
| `/ndxzstudio/*` | **Bypass Perma-Cache** | — |
| `/files/*` | **Override Cache Time** | 1 year |
| `/ndxzsite/css/*`, `/js/*`, `/img/*`, `/default/*`, `/mobile/*` | **Override Cache Time** | 1 year |

`Bypass Perma-Cache` only opts the request out of Bunny's permanent copy of
static files; it is not a general "don't cache". Admin stays dynamic because
the app sends `Cache-Control: no-cache`, and HTML pages are not cached. Do not
enable "Ignore Query String" on `/ndxzsite/*` — CSS/JS use `?v=` cache busting.
See [docs/DEPLOY.md](docs/DEPLOY.md) for the full rule details.

When `BUNNY_API_KEY` and `BUNNY_PULL_ZONE_ID` are set, Indexhibit purges the
CDN automatically when pages are published/unpublished/edited, CSS assets are
saved, or the site cache is cleared.

---

## Security

This fork includes a number of hardening fixes, including:

- CSRF protection for the admin backend
- Password hashing with `password_hash()` / `password_verify()`
- Random login tokens with `HttpOnly`, `Secure`, and `SameSite` cookies
- Random password-reset tokens
- Removed `eval()` from the front-end parser
- SQL injection fixes in admin modules
- Path traversal and arbitrary file read/write fixes
- Upload directory hardening (`files/.htaccess` and root rewrite rules)
- Output escaping for common stored XSS vectors
- Proxy/CDN-aware `BASEURL` and HTTPS detection

---

## Manual installation (CLI)

There is no browser installer. Install from the command line:

```bash
export DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=...
export SITE_NAME=example.com ADMIN_EMAIL=you@example.com
php ndxzstudio/install-cli.php
```

It requires write access to:

- `files/`
- `files/gimgs/`
- `files/dimgs/`
- `ndxzsite/config/`
- `ndxzsite/cache/`

Configured via `PUBLIC_URL`, `DB_*`, and `ADMIN_*` environment variables. The
CLI installer is idempotent: running it again is safe.

---

## Directory notes

| Path | Purpose |
| --- | --- |
| `index.php` | Front controller |
| `ndxzsite/` | Themes, plugins, assets, public cache |
| `ndxzsite/config/` | Runtime configuration (`config.php` is generated) |
| `ndxzstudio/` | Admin backend |
| `files/` | Uploaded media (keep on a persistent volume) |
| `docker/` | Docker entrypoint, Apache config, config generator |
| `docs/DEPLOY.md` | Detailed deployment guide |
