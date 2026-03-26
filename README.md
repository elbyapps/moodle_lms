# REB Moodle LMS

Dockerized Moodle 5.0.1 e-learning platform with git-based plugin management.

## Quick Start

```bash
# 1. Copy and configure environment
cp .env.example .env

# 2. Build Moodle (clones core + plugins)
./build.sh

# 3. Start development environment
docker compose -f docker-compose.yml -f docker-compose.dev.yml up --build
```

Moodle will be available at `http://localhost:8080`.

Default admin credentials: `admin` / `Admin123!`

## Architecture

### Plugin Management

Plugins are defined in `moodle-config.json`. Each entry specifies a git repository, branch/tag, and destination path within Moodle. `build.sh` reads this config, clones Moodle core to `moodle_app/`, and installs plugins to `moodle_app/public/<destination>`.

To add a plugin, append an entry to the `plugins` array in `moodle-config.json`:

```json
{
  "name": "my_plugin",
  "repository": "https://github.com/org/moodle-mod_example.git",
  "version": "main",
  "destination": "mod/example"
}
```

Then re-run `./build.sh` (existing plugins are skipped automatically).

### Included Plugins

| Plugin | Type | Description |
|---|---|---|
| theme_moove | Theme | Modern responsive theme |
| theme_elby | Theme | Elby custom theme |
| auth_oidc | Auth | Microsoft OpenID Connect |
| auth_antihammer | Auth | Brute-force login protection |
| mod_attendance | Activity | Attendance tracking |
| mod_attendanceregister | Activity | Attendance register |
| mod_customcert | Activity | Custom certificates |
| mod_organizer | Activity | Appointment organizer |
| mod_activitymap | Activity | Activity dependency map |
| format_grid | Course format | Grid course format |
| enrol_attributes | Enrolment | Profile-based auto-enrolment |
| block_accessibility | Block | Accessibility tools |
| block_completion_progress | Block | Progress bar block |
| block_attendance | Block | Attendance block |
| filter_wiris | Filter | MathType equation editor |
| local_o365 | Local | Microsoft 365 integration |
| local_bulkenrol | Local | Bulk user enrolment |
| local_reblibrary | Local | REB digital library (S3 storage) |
| local_elby_dashboard | Local | Elby dashboard |
| tool_coursefields | Admin tool | Custom course fields |
| customfield_dynamic | Custom field | Dynamic custom fields |
| booktool_importepub | Book tool | EPUB import for Book module |
| report_benchmark | Report | Performance benchmarking |

### Docker Services

| Service | Description |
|---|---|
| **php** | PHP 8.2-FPM with Moodle and all extensions |
| **cron** | Moodle scheduled task runner (same image as php) |
| **nginx** | Web server proxying to PHP-FPM |
| **mariadb** | MariaDB 11 database (dev only) |
| **garage** | S3-compatible object storage (dev only) |
| **garage-init** | One-shot bucket/key setup for Garage (dev only) |
| **certbot** | Let's Encrypt certificate renewal (prod only) |

### Container Paths

| Path | Purpose |
|---|---|
| `/var/www/html/moodle_app` | Moodle installation |
| `/var/www/html/moodle_app/public` | Web root |
| `/var/www/moodledata` | Moodle data directory |

## Configuration

Copy `.env.example` to `.env` and customize. Key variable groups:

### Database

| Variable | Default | Description |
|---|---|---|
| `DB_TYPE` | `mariadb` | Database type (`mariadb` or `pgsql`) |
| `DB_HOST` | `mariadb` | Database hostname |
| `DB_PORT` | `3306` | Database port |
| `DB_NAME` | `moodle` | Database name |
| `DB_USER` | `moodleuser` | Database user |
| `DB_PASSWORD` | `moodlepass` | Database password |

### Moodle

| Variable | Default | Description |
|---|---|---|
| `MOODLE_WWWROOT` | `http://localhost:8080` | Full site URL (overrides components below) |
| `MOODLE_PROTOCOL` | `http` | URL protocol |
| `MOODLE_HOST` | `localhost` | Hostname |
| `MOODLE_PORT` | `8080` | Port |
| `MOODLE_DEBUG` | `false` | Enable debug mode |
| `MOODLE_REVERSEPROXY` | `false` | Behind a reverse proxy |
| `MOODLE_SSLPROXY` | `false` | SSL terminated by proxy |

### Admin (auto-install)

| Variable | Default |
|---|---|
| `MOODLE_ADMIN_USER` | `admin` |
| `MOODLE_ADMIN_PASS` | `Admin123!` |
| `MOODLE_ADMIN_EMAIL` | `admin@example.com` |
| `MOODLE_SITE_FULLNAME` | `Moodle Dev` |
| `MOODLE_SITE_SHORTNAME` | `moodle` |

## Production Deployment

```bash
# Build and run with external database + SSL
docker compose -f docker-compose.yml -f docker-compose.prod.yml up --build
```

The production compose file:
- Connects php/cron/nginx to the `dokploy-network` for external database access
- Enables HTTPS on port 443 with Let's Encrypt via Certbot
- Does **not** include MariaDB or Garage (use external services)

Set `CERTBOT_EMAIL` in your `.env` for Let's Encrypt registration.

## S3 Object Storage

The **reblibrary** plugin uses S3-compatible object storage for PDF files and cover images. Any S3-compatible service works (Garage, SeaweedFS, AWS S3, etc.).

### Development

[Garage](https://garagehq.deuxfleurs.fr/) runs automatically as a Docker service via `docker-compose.dev.yml`. No extra setup needed — the bucket and API keys are created on startup by the `garage-init` service.

- S3 API: `http://localhost:3900`
- Admin API: `http://localhost:3903`

Default dev credentials (in `.env.example`):

| Variable | Default |
|---|---|
| `S3_ENDPOINT` | `http://garage:3900` |
| `S3_ACCESS_KEY` | `GK000000000000000000000000` |
| `S3_SECRET_KEY` | `0000000000000000000000000000000000000000000000000000000000000000` |
| `S3_BUCKET` | `moodle` |
| `S3_REGION` | `us-east-1` |

### Production

In production, point the S3 environment variables at your dedicated S3-compatible server. No Garage container is included in the production compose file.

Update your `.env`:

```bash
S3_ENDPOINT=https://s3.your-server.com
S3_ACCESS_KEY=your-access-key
S3_SECRET_KEY=your-secret-key
S3_BUCKET=moodle
S3_REGION=us-east-1
```

### Setting Up a Standalone Garage Server

If you want to run Garage as your production S3 server:

1. **Install Garage** (see [official docs](https://garagehq.deuxfleurs.fr/documentation/quick-start/)):

   ```bash
   curl -Lo /usr/local/bin/garage \
     https://garagehq.deuxfleurs.fr/_releases/v1.1.0/x86_64-unknown-linux-musl/garage
   chmod +x /usr/local/bin/garage
   ```

2. **Create configuration** at `/etc/garage.toml`:

   ```toml
   metadata_dir = "/var/lib/garage/meta"
   data_dir = "/var/lib/garage/data"
   db_engine = "sqlite"
   replication_factor = 1

   [s3_api]
   s3_region = "us-east-1"
   api_bind_addr = "[::]:3900"

   [admin]
   api_bind_addr = "[::]:3903"

   [rpc]
   bind_addr = "[::]:3901"
   secret = "<generate with: openssl rand -hex 32>"
   ```

3. **Start Garage and configure layout**:

   ```bash
   garage server &

   # Get node ID
   garage status

   # Configure layout (replace NODE_ID with first 16 chars from status)
   garage layout assign -z dc1 -c 100GB NODE_ID
   garage layout apply --version 1
   ```

4. **Create bucket and API key**:

   ```bash
   garage key create moodle-key
   garage bucket create moodle
   garage bucket allow moodle --read --write --owner --key moodle-key

   # Show key credentials (use these in your .env)
   garage key info moodle-key
   ```

5. **Update `.env`** with the key ID and secret from `garage key info`.

## Prerequisites

- Docker and Docker Compose
- `git` and `jq` (for running `build.sh` locally)
