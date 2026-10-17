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
| `MEDIA_DRIVER` | no | `local` (default) or `bunny` |
| `MEDIA_URL` | no | Public base for uploaded media (CDN hostname) |
| `BUNNY_STORAGE_ZONE` | no | Bunny Storage Zone name |
| `BUNNY_STORAGE_KEY` | no | Bunny Storage Zone password (PUT/DELETE) |
| `BUNNY_STORAGE_ENDPOINT` | no | Default `https://storage.bunnycdn.com` |

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

## Deploying from a private repository

> **Do not deploy production directly from the upstream repo.** Keep upstream
> as a source to sync from, and deploy from your own private repo so your
> config, secrets, and the exact ref you ship stay under your control.

There are two shapes for that private repo. Neither uses submodules:

- **Full mirror** — contains the app source; `fly deploy` builds it. This is
  also what you need if you want the Fly dashboard/GitHub integration to build
  for you.
- **Thin deploy repo** — contains only Fly config plus a deploy script; it
  clones upstream at deploy time. Local/scripted deploys only.

The steps below describe the full mirror; the thin variant is at the end.

### 1. Create the private mirror

Import the public repo into a new private repo (GitHub → **New repository** →
**Import a repository**), or mirror-push:

```bash
git clone --bare https://github.com/bermannoah/indexhibit.git
cd indexhibit.git
git push --mirror git@github.com:<you>/<private-repo>.git
```

### 2. Track upstream

```bash
git remote add upstream https://github.com/bermannoah/indexhibit.git
git fetch upstream
git rebase upstream/main   # or: git merge upstream/main
```

Keep private-only files in paths upstream doesn't track, or in a single commit
on top of upstream, so rebases stay clean. If you edit upstream-tracked files
like `fly.toml`, expect the occasional conflict and resolve by keeping your app
name and region.

### 3. Configure Fly

The `fly.toml` / `fly.mariadb.toml` in this repo are generic templates. In the
private repo, replace the placeholders:

- `fly.toml`: `app`, `primary_region`, `PUBLIC_URL`, `SITE_NAME`, `ADMIN_*`
- `fly.mariadb.toml`: `app` (for example `<app-name>-db`), `primary_region`

The Fly CLI will generate a `fly.toml` for you if you don't have one — run
`fly launch` and decline the database/Redis add-ons, since MariaDB is deployed
from `fly.mariadb.toml`. Otherwise, create the apps directly:

```bash
fly auth login
fly apps create <app-name>    -o <fly-org>
fly apps create <app-name>-db -o <fly-org>
```

### 4. Secrets

```bash
# MariaDB app
fly secrets set MARIADB_PASSWORD='...' MARIADB_ROOT_PASSWORD='...' -a <app-name>-db

# web app
fly secrets set \
  DB_HOST='<app-name>-db.flycast' \
  DB_NAME='indexhibit' DB_USER='indexhibit' DB_PASS='...' \
  BUNNY_API_KEY='...' BUNNY_PULL_ZONE_ID='...' \
  -a <app-name>
```

For non-interactive deploys, create an app-scoped deploy token:

```bash
fly tokens create deploy --app <app-name>
```

### 5. Deploy

Locally, from the private repo:

```bash
fly deploy -c fly.mariadb.toml
fly deploy -c fly.toml
```

You can also connect the private repo to the app in the Fly dashboard and
deploy from there. No GitHub Actions or other CI is required.

### 6. First run

With `AUTO_INSTALL=1`, the first boot seeds the database:

```text
https://<your-domain>/ndxzstudio/
```

Change the default password immediately.

### 7. Alternative: thin deploy repo

A thin repo keeps no app code in git. It clones upstream at deploy time and
copies in the private Fly config:

```text
deploy-repo/
├── fly.toml
├── fly.mariadb.toml
├── deploy.sh
└── README.md
```

`deploy.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

UPSTREAM_REPO="${UPSTREAM_REPO:-https://github.com/bermannoah/indexhibit.git}"
UPSTREAM_REF="${1:-main}"

workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT

git clone --depth 1 --branch "$UPSTREAM_REF" "$UPSTREAM_REPO" "$workdir"
cp fly.toml fly.mariadb.toml "$workdir/"

cd "$workdir"
fly deploy -c fly.mariadb.toml
fly deploy -c fly.toml
```

```bash
./deploy.sh main   # or a tag for a reproducible deploy
```

Tradeoffs:

- Pin `UPSTREAM_REF` to a tag (or branch) for reproducible deploys; `main`
  deploys whatever upstream currently has.
- You cannot patch the app privately — the deployed code is exactly the
  upstream ref.
- The Fly dashboard/GitHub build integration needs the source, so it only
  works with the full-mirror option. The thin repo is for local deploys.

The config files are identical to the mirror option — same placeholders,
secrets, and deploy commands.

## Bunny.net

Bunny.net is a **pull** CDN, so there is no upload step: it pulls assets from
your Fly app origin.

1. Create a pull zone for `example.com`.
2. Set the origin URL to your Fly app, e.g. `https://indexhibit.fly.dev`.
3. Add the hostname `example.com`.
4. Add the edge rules below.
5. TLS terminates at Fly (`force_https = true` in `fly.toml`). Point your DNS
   at Bunny and configure Bunny's SSL for the public hostname.

### Media storage (Storage Zone)

Uploaded media (`/files/...`) can live in a Bunny Storage Zone instead of the
Fly volume. The app still generates images/thumbnails locally (GD) and then
mirrors them to the zone.

1. Create a **Storage Zone** and note its name and password.
2. Create a **Pull Zone** with that storage zone as its origin, and add your
   media hostname (for example `cdn.example.com`).
3. Configure the app:

| Variable | Value |
| --- | --- |
| `MEDIA_DRIVER` | `bunny` |
| `MEDIA_URL` | `https://cdn.example.com` |
| `BUNNY_STORAGE_ZONE` | storage zone name |
| `BUNNY_STORAGE_KEY` | storage zone password |
| `BUNNY_STORAGE_ENDPOINT` | optional (regional endpoint) |

```bash
fly secrets set \
  MEDIA_DRIVER='bunny' \
  MEDIA_URL='https://cdn.example.com' \
  BUNNY_STORAGE_ZONE='...' \
  BUNNY_STORAGE_KEY='...' \
  -a <app-name>
```

Notes:

- Storage keys mirror the app paths (`files/gimgs/...`), so
  `https://cdn.example.com/files/gimgs/x.jpg` maps to `files/gimgs/x.jpg` in
  the storage zone.
- `dimgs/` (dynamic resizes) and the page cache stay on the Fly volume — they
  are derived/local by design. Only uploaded media and generated thumbnails go
  to the zone.
- Deletes remove the object from the zone and purge its CDN URL.
- With `MEDIA_DRIVER=local` (or `MEDIA_URL` empty) nothing changes: media is
  served from `BASEURL` as before.

### Edge rules

For each rule, match the request URL and set the action:

| Path | Action | Edge cache TTL | Notes |
| --- | --- | --- | --- |
| `/ndxzstudio/*` | **Bypass Perma-Cache** | — | Admin/auth responses set cookies; never cache |
| `/files/*` | **Override Cache Time** | `31536000` (1 year) | Uploaded media; also covers non-image uploads |
| `/ndxzsite/css/*` | **Override Cache Time** | `31536000` | Theme CSS |
| `/ndxzsite/js/*` | **Override Cache Time** | `31536000` | Theme JS |
| `/ndxzsite/img/*` | **Override Cache Time** | `31536000` | Theme images |
| `/ndxzsite/default/*` | **Override Cache Time** | `31536000` | Default theme assets |
| `/ndxzsite/mobile/*` | **Override Cache Time** | `31536000` | Mobile theme assets |

Notes:

- **`Bypass Perma-Cache` is not a general "don't cache".** Perma-Cache is
  Bunny's permanent store for static files. This action only makes the matched
  request skip that permanent copy; normal edge caching still applies. What
  actually keeps admin dynamic, in addition to this rule, is the origin
  `Cache-Control: no-cache, must-revalidate` that `/ndxzstudio/index.php`
  already sends (with Bunny's "Respect Cache-Control" enabled). If your plan
  exposes a general **Bypass Cache** / **Disable Cache** action, add that to the
  admin rule too.
- Enable **Perma-Cache** at the pull zone level (per file extension) if you
  want the strongest caching for static assets. The app purges on change, so
  long-lived / permanent caching is safe.
- Leave HTML page URLs (`/`, `/project/…`) uncached. If you want to be
  explicit, add a **Bypass Perma-Cache** rule for them as well.
- Do **not** enable **Ignore Query String** on `/ndxzsite/*`: the app
  cache-busts CSS/JS with `?v=…`, and purge is per-URL.
- The origin already sends `Cache-Control: public, max-age=31536000, immutable`
  for static extensions, so Bunny caches those by default. The `/files/*`
  **Override Cache Time** rule mainly ensures non-image uploads (pdf, zip, …)
  are cached for the same long TTL.
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
