Update the existing `moodle-deploy` role to support three deployment profiles via a single `moodle_deploy_mode` variable. The Moodle app repo (`elbyapps/moodle_lms`) has been updated with SSL/certbot support — the ansible side needs to catch up. Also add a backup role.

Read the Moodle app's `docker-compose.yml`, `docker-compose.dev.yml`, `docker-compose.prod.yml`, `docker/nginx/prod.conf.template`, `docker/nginx/init-letsencrypt.sh`, and `.env.example` to understand the Docker architecture before making changes.

## Deployment profiles

| | `production` | `school` |
|---|---|---|
| **Compose files** | `docker-compose.yml` + `docker-compose.prod.yml` | `docker-compose.yml` + `docker-compose.dev.yml` |
| **SSL** | HTTPS via Let's Encrypt (certbot) | HTTP only |
| **Database** | External (DB_HOST points to remote server) | Local MariaDB container (from dev compose) |
| **Ports** | 80 (redirect) + 443 | 80 |
| **Protocol** | https | http |
| **MOODLE_SSLPROXY** | true | false |

The **test server** uses `production` mode (same HTTPS + external DB setup, just on a test domain).

## What changed in the Moodle app repo

- `docker-compose.prod.yml` now has: a `certbot` service (auto-renewal every 12h), `certbot_conf` + `certbot_webroot` volumes, port 443 on nginx, and `MOODLE_HOST` env for nginx SSL cert paths.
- `docker/nginx/prod.conf.template` has HTTP→HTTPS redirect + ACME challenge on port 80, full SSL on port 443.
- `docker/nginx/init-letsencrypt.sh` is a bootstrap script for first-time cert provisioning (creates dummy cert → starts nginx → requests real cert from Let's Encrypt → reloads nginx). It reads `MOODLE_HOST` and `CERTBOT_EMAIL` from `.env`.
- `.env.example` now includes `CERTBOT_EMAIL`, `MOODLE_REVERSEPROXY`, `MOODLE_SSLPROXY`, `MOODLE_MAIL_NOREPLY`, `MOODLE_MAIL_PREFIX`.

## Changes needed

### Port mapping — important

The base `docker-compose.yml` maps `${MOODLE_PORT:-8080}:80` on nginx. The prod overlay *adds* `443:443`. Since prod uses `include: - docker-compose.yml`, both port mappings are active in production: `80:80` (ACME challenges + redirect) and `443:443` (HTTPS). Therefore `MOODLE_PORT` must be `80` for **both** modes — it is NOT the SSL port. The SSL port comes solely from the prod overlay.

### SSL cert check — important

Certs live inside the `certbot_conf` Docker named volume, not on the host filesystem. To check if certs exist, probe inside a container:
```
docker run --rm -v certbot_conf:/etc/letsencrypt alpine test -f /etc/letsencrypt/live/{{ moodle_host }}/fullchain.pem
```
Do NOT use `ansible.builtin.stat` on a host path — the volume is not bind-mounted.

### 1. Add `moodle_deploy_mode` variable to the role

In `defaults/main.yml`, add:
- `moodle_deploy_mode: school` (safe default — HTTP, local DB)
- `moodle_port: 80` (both modes use 80; SSL 443 comes from prod overlay)
- Derive compose files, protocol, ssl settings from the mode
- Add new defaults: `moodle_certbot_email`, `moodle_ssl_proxy`, `moodle_reverse_proxy`, `moodle_mail_noreply`, `moodle_mail_prefix`

### 2. Update `tasks/deploy.yml`

Use the mode to select compose files:
- `production` → `docker compose -f docker-compose.yml -f docker-compose.prod.yml up --build -d`
- `school` → `docker compose -f docker-compose.yml -f docker-compose.dev.yml up --build -d` (keep the local MariaDB start)

### 3. Update `handlers/main.yml`

The handlers currently hardcode `docker-compose.dev.yml`. They need to use the correct compose files based on `moodle_deploy_mode`.

### 4. Update `templates/moodle.env.j2`

Add the missing variables:
- `MOODLE_REVERSEPROXY={{ moodle_reverse_proxy }}`
- `MOODLE_SSLPROXY={{ moodle_ssl_proxy }}`
- `MOODLE_MAIL_NOREPLY={{ moodle_mail_noreply }}`
- `MOODLE_MAIL_PREFIX={{ moodle_mail_prefix }}`
- `CERTBOT_EMAIL={{ moodle_certbot_email }}`

Fix `MOODLE_WWWROOT` — when port is 443 or 80, omit the port from the URL (currently it always appends `:{{ moodle_port }}`).

### 5. Add `tasks/ssl-bootstrap.yml`

New task file, included from `main.yml` after deploy but before health-check, **only when `moodle_deploy_mode == 'production'`**:

- Check if certs exist by probing inside the Docker volume (see "SSL cert check" note above) — do NOT use `ansible.builtin.stat` on a host path
- If certs don't exist, run `./docker/nginx/init-letsencrypt.sh` from `{{ moodle_install_dir }}`
- The script reads `MOODLE_HOST` and `CERTBOT_EMAIL` from `.env` (already templated)

### 6. Update `tasks/health-check.yml`

Adjust the health check URL based on mode:
- `production` → `https://localhost/` with `validate_certs: false` (self-signed during bootstrap)
- `school` → `http://localhost/` (current behavior, now on port 80)

### 7. Update inventory to set mode per group

- **`group_vars/moodle_vms.yml`** (schools): `moodle_deploy_mode: school` (default, LAN HTTP)
- **`group_vars/rtb_hq.yml`** (new file for HQ/test/prod servers): `moodle_deploy_mode: production`, plus `moodle_certbot_email`, and any HQ-specific overrides

### 8. Create a `moodle-backup` role

New role at `ansible/roles/moodle-backup/`:

- A backup script template that:
  - Dumps the database (mysqldump for mariadb, pg_dump for pgsql) — for `school` mode exec into the mariadb container, for `production` mode connect to the external DB host
  - Tars `{{ moodle_install_dir }}/moodledata/`
  - Stores backups in `{{ moodle_install_dir }}/backups/` with date stamps
  - Deletes backups older than 7 days
- A cron job running the backup script daily
- New playbook `ansible/playbooks/05-setup-backups.yml` targeting `moodle_vms` with the `moodle-backup` role

## Important constraints

- Keep everything idempotent — SSL bootstrap must skip if certs already exist
- The external database is managed separately — do NOT provision it
- Follow existing patterns: FQCN for all modules, `ansible_managed` comment in templates, tasks split into included files
- `school` mode must keep working exactly as the current role does (HTTP, local MariaDB) but on port 80 — don't break existing school deployments
