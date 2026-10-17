# Docker / Fly.io / Bunny.net Deployment

Indexhibit is containerized as a PHP 8.5 Apache app. It expects a MySQL/MariaDB
database and is designed to sit behind the Bunny.net pull CDN for static assets
only.

## Environment variables

| Variable | Required | Description |
| --- | --- | --- |
| `DB_HOST` | yes | MySQL/MariaDB host |
| `DB_NAME` | yes | Database name |
| `DB_USER` | yes | Database user |
| `DB_PASS` | yes | Database password |
| `DB_PREFIX` | no | Table prefix (default: `ndxz_`) |
| `PUBLIC_URL` | yes | Public base URL, e.g. `https://example.com` |
| `TRUSTED_PROXIES` | no | `*` to trust Fly/Bunny forwarded headers, or a CSV of IPs |
| `AUTO_INSTALL` | no | `1` to auto-create the schema/seed on first boot |
| `SITE_NAME` | no | Site name used during auto-install |
| `ADMIN_EMAIL` | no | Initial admin email during auto-install |
| `ADMIN_FNAME` | no | Initial admin first name |
| `ADMIN_LNAME` | no | Initial admin last name |
| `BUNNY_API_KEY` | no | Bunny.net API key for cache purge |
| `BUNNY_PULL_ZONE_ID` | no | Bunny pull zone ID for purge-all |

The default initial login is still **`index1` / `exhibit`** (change it
immediately after first login).

There is no browser installer; installation runs from the CLI
(`ndxzstudio/install-cli.php`) or automatically from the container entrypoint
when `AUTO_INSTALL=1`.

## Local development with Docker Compose

```bash
docker compose up --build
```

With `AUTO_INSTALL=1` the container creates the schema and seeds the default
user on first boot. Log in at `http://localhost:8080/ndxzstudio/`.

## Fly.io

### 1. MariaDB app

Create and configure the MariaDB app using the provided `fly.mariadb.toml`:

```bash
fly launch --name indexhibit-db --config fly.mariadb.toml --no-deploy
fly secrets set MARIADB_PASSWORD='<db-password>' MARIADB_ROOT_PASSWORD='<root-password>'
fly deploy -c fly.mariadb.toml
```

### 2. Web app

```bash
fly launch --name indexhibit --config fly.toml --no-deploy
fly secrets set \
  DB_HOST='indexhibit-db.flycast' \
  DB_NAME='indexhibit' \
  DB_USER='indexhibit' \
  DB_PASS='<db-password>' \
  BUNNY_API_KEY='<bunny-api-key>' \
  BUNNY_PULL_ZONE_ID='<bunny-pull-zone-id>'
fly deploy -c fly.toml
```

If `.flycast` hostname resolution doesn't work for your organization, use the
MariaDB app's private IP (`fly ips list -a indexhibit-db`) as `DB_HOST`.

With `AUTO_INSTALL=1` in `fly.toml`, the first boot seeds the database. Log in
at:

```text
https://example.com/ndxzstudio/
```

Change the default password immediately.

To install manually instead of at boot:

```bash
DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... \
  php ndxzstudio/install-cli.php
```

## Bunny.net

Bunny.net is a **pull** CDN, so there is no upload step: it pulls assets from
your Fly app origin.

1. Create a pull zone for `example.com`.
2. Set the origin URL to your Fly app, e.g. `https://indexhibit.fly.dev`.
3. Add the hostname `example.com`.
4. Add the edge rules below.
5. TLS terminates at Fly (`force_https = true` in `fly.toml`). Point your DNS
   at Bunny and configure Bunny's SSL for the public hostname.

### Edge rules

For each rule, match the request URL and set the action:

| Path | Action | Edge cache TTL | Notes |
| --- | --- | --- | --- |
| `/ndxzstudio/*` | **Bypass cache** | — | Admin/auth responses set cookies; never cache |
| `/files/*` | **Cache** (force / override edge TTL) | `31536000` (1 year) | Uploaded media; also covers non-image uploads |
| `/ndxzsite/css/*` | **Cache** (force / override edge TTL) | `31536000` | Theme CSS |
| `/ndxzsite/js/*` | **Cache** (force / override edge TTL) | `31536000` | Theme JS |
| `/ndxzsite/img/*` | **Cache** (force / override edge TTL) | `31536000` | Theme images |
| `/ndxzsite/default/*` | **Cache** (force / override edge TTL) | `31536000` | Default theme assets |
| `/ndxzsite/mobile/*` | **Cache** (force / override edge TTL) | `31536000` | Mobile theme assets |

Notes:

- In Bunny's UI the cache action is **Force Cache** and/or **Override Edge
  Cache TTL**; the bypass action is **Bypass Cache**.
- Leave HTML page URLs (`/`, `/project/…`) uncached. The app already sends
  `Cache-Control: no-cache, must-revalidate`, so Bunny will not cache them. If
  you want to be explicit, add a **Bypass cache** rule for them.
- Do **not** enable **Ignore Query String** on `/ndxzsite/*`: the app
  cache-busts CSS/JS with `?v=…`, and purge is per-URL.
- The origin already sends `Cache-Control: public, max-age=31536000, immutable`
  for static extensions, so Bunny caches those by default. The `/files/*` force
  rule mainly ensures non-image uploads (pdf, zip, …) are cached too.
- A long TTL is safe because the app purges the CDN automatically:
  - per-URL on publish/unpublish/content edit and CSS save
  - whole pull zone on **Clear Site Cache** in the admin

The app calls Bunny's purge API when content is published/unpublished/edited
and when the site cache is cleared, using `BUNNY_API_KEY` and
`BUNNY_PULL_ZONE_ID`.

## Troubleshooting

- `php -l` should be run against changed PHP files during development.
- Uploaded files live on the `files` volume; config lives on the `config`
  volume. Back both up.
- `FORCE_CONFIG_GEN=1` regenerates `ndxzsite/config/config.php` from the
  current environment on next container start.
