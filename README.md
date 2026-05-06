# Moodle in containers

An opinionated, production-ready way to run Moodle 5.x with Docker
Compose. PHP-FPM behind nginx, Redis for sessions and MUC, Postgres or
MariaDB, plugin set declared in JSON. Three compose overlays cover dev,
staging and prod.

This is not a Bitnami-style appliance. It assumes you're comfortable
editing a Dockerfile and reading entrypoint scripts. The opinions are
documented; everything is intended to be forked.

## Stack

| Service  | Image                       | Where        | Purpose                                                |
|----------|-----------------------------|--------------|--------------------------------------------------------|
| `php`    | built from `docker/php/`    | all stacks   | PHP-FPM 8.2 with Moodle extensions; serves Moodle code |
| `nginx`  | built from `docker/nginx/`  | all stacks   | HTTP-only on `:8080`; SSL is terminated upstream       |
| `redis`  | `redis:alpine`              | base + prod  | session handler + MUC backend                          |
| `cron`   | reuses `php` image          | base         | runs `admin/cli/cron.php` on a 60s loop                |
| `mariadb`| `mariadb:lts`               | dev / db     | dev-only DB; in prod the DB is external                |
| `garage` | `dxflrs/garage`             | dev          | dev-only S3-compatible store for plugins that need S3  |

Web root is `/var/www/html/moodle_app/public`. Moodle data is at
`/var/www/moodledata`. Code lives in the image, not in a named volume —
upgrades happen by rebuilding the image, not by mutating a volume.

## Quick start (dev)

```bash
cp .env.example .env
make dev
# browse to http://localhost:8080
```

The first run will build the PHP and nginx images (this clones Moodle
core and every plugin in `moodle-config.json`), bring up MariaDB and
Garage, and let Moodle's installer initialize the DB on first hit.

Stop with `make dev-down`.

## Plugins

`moodle-config.json` is the single source of truth. Each entry:

```json
{
  "name": "moodle-mod_attendance",
  "repository": "https://github.com/danmarsden/moodle-mod_attendance.git",
  "version": "MOODLE_501_STABLE",
  "destination": "mod/attendance"
}
```

`build.sh` clones each repo at the given ref into
`moodle_app/public/<destination>`. The script:

- Clones with `--depth 1 --branch <ref>`, so `version` must be a tag or
  branch — **not** a commit SHA.
- Removes `.git/` after cloning to keep the image smaller.
- Skips plugins whose target directory already exists.
- Verifies after the loop that every configured plugin actually landed
  on disk; missing one fails the build instead of shipping a broken
  image.

### Pin discipline

Branch tips move; tags don't. Prefer tags. The `MOODLE_*_STABLE`
branches are the exception — they only receive backports within a
release line, so they're effectively stable refs. See
[`docs/plugin-clone-anomalies.md`](docs/plugin-clone-anomalies.md) for
the verification recipe and known traps (e.g. `block_configurablereports`'s
orphan `master` branch).

### Plugins not on a public git remote

Drop the plugin tree under `vendor/<name>/` and add a `COPY` to the PHP
and nginx Dockerfiles. See [`vendor/README.md`](vendor/README.md).

## Configuration

Copy `.env.example` to `.env` and edit. Key knobs:

| Variable                       | Default            | Notes                                                            |
|--------------------------------|--------------------|------------------------------------------------------------------|
| `DB_TYPE`                      | `mariadb`          | or `pgsql`                                                       |
| `DB_HOST`, `DB_PORT`, ...      | dev defaults       | in prod, point at your external DB                               |
| `MOODLE_WWWROOT`               | `http://localhost:8080` | full URL Moodle should advertise                            |
| `MOODLE_REVERSEPROXY`          | `false`            | **must be `false`** when the upstream proxy forwards your `Host` |
| `MOODLE_SSLPROXY`              | `false`            | set to `true` in prod so Moodle emits `https://` URLs            |
| `SERVER_CPUS`, `SERVER_MEMORY_GB` | host-sized      | feed the resource calculator (see below)                         |
| `OPCACHE_MEMORY_MB`            | `256`              | per replica                                                      |
| `OPCACHE_JIT_BUFFER_MB`        | `128`              | per replica                                                      |
| `OPCACHE_INTERNED_STRINGS_MB`  | `64`               | bump from PHP's stock 8 MB — Moodle bootstraps a lot of strings  |
| `REDIS_HOST`, `REDIS_PORT`     | `redis:6379`       | sessions + MUC                                                   |

`docker/php/calculate-resources.sh` derives PHP-FPM `pm.*` values from
`SERVER_CPUS`, `SERVER_MEMORY_GB`, `CPUS_PER_REPLICA`, and
`PHP_MEMORY_FRACTION`. The numbers are baked into the rendered
`www.conf` at container start.

## Stacks

| Make target | Files used                                                  | What it adds over the base                                |
|-------------|-------------------------------------------------------------|-----------------------------------------------------------|
| `make dev`  | `docker-compose.yml` + `docker-compose.dev.yml`             | MariaDB, Garage (S3), bind mounts                         |
| `make staging` | `docker-compose.yml` + `docker-compose.staging.yml`      | external DB, no Garage                                    |
| `make prod` | `docker-compose.yml` + `docker-compose.prod.yml`            | external DB, Redis, multiple PHP replicas, named volumes  |
| `make db-up`| `docker-compose.db.yml`                                     | standalone MariaDB only (e.g. for staging-on-host setups) |

If a `docker-compose.local.yml` exists at the repo root it is included
automatically by every Make target above. Use it for host-specific
overrides (pre-existing external volumes, host-specific DB credentials,
etc.) — it's gitignored.

## Production realities

- **Run behind a reverse proxy.** This stack speaks HTTP on `:8080`. Put
  nginx, Caddy, Traefik, or a cloud load balancer in front of it for
  TLS. The `MOODLE_SSLPROXY=true` + `MOODLE_REVERSEPROXY=false` combo
  is what tells Moodle to emit `https://` URLs while accepting HTTP
  traffic without flagging `reverseproxyabused`.
- **External DB.** `docker-compose.prod.yml` doesn't ship a database
  service. Point `DB_HOST` at the real one.
- **Redis is required in prod.** Sessions go through `\core\session\redis`,
  and MUC's session/application modes are mapped to a Redis store. Run
  `scripts/setup_redis_muc.php` once after the first prod boot to
  create the MUC redis store and set the mode mappings (it prints the
  persisted state for verification).
- **Read-only sessions.** `$CFG->enable_read_only_sessions = true` is on,
  which skips session write locks on read-only pages — meaningful
  concurrency win behind Redis. Safe because no caches still resolve to
  PHP's session handler.
- **Rolling deploys.** `scripts/deploy.sh code` does a rolling
  replacement of PHP-FPM replicas without downtime. Nginx is a single
  instance, so expect a ~5s blip when it's recreated at the end.
- **Cron doesn't fail loudly.** The cron container loops `cron.php` with
  `|| true` because Moodle legitimately exits non-zero during
  maintenance mode and pending upgrades. Look at the container logs
  rather than its exit code to tell whether cron is actually working.
- **Image, not volume.** Code is baked into the image; there is no
  `moodle_app` named volume to upgrade. To roll out a code change:
  rebuild the image, then redeploy.

## Make targets

```
make help            # list all targets
make build           # build every service that has a build: stanza
make build-fresh     # full rebuild: no cache + drop the moodle_app volume
make dev | dev-down
make staging | staging-down
make prod | prod-down
make db-up | db-down
make logs s=php      # tail logs for one service
make shell           # bash into the PHP container
make clean-cache     # purge Moodle MUC caches
```

## Repo layout

```
.
├── build.sh                      # clones Moodle core + plugins per moodle-config.json
├── moodle-config.json            # single source of truth: Moodle ref + plugin list
├── config.php.docker             # Moodle config rendered into the image
├── docker/
│   ├── php/                      # PHP-FPM image (also runs cron)
│   └── nginx/                    # nginx image with Moodle's tree baked in
├── docker-compose.yml            # base
├── docker-compose.{dev,staging,prod,db}.yml
├── scripts/
│   ├── deploy.sh                 # rolling replacement of PHP replicas
│   └── setup_redis_muc.php       # one-shot MUC redis-store + mode mapping
├── docs/
│   └── plugin-clone-anomalies.md # plugin pin discipline + known traps
└── vendor/                       # plugins not on a public git remote (see vendor/README.md)
```

## Contributing

Forks welcome. The shape is intentionally minimal — if you find yourself
copying the same overlay across multiple deployments, that's a signal
the pattern belongs here, not in your fork.
