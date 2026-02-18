#!/bin/bash
# Bootstrap script for initial Let's Encrypt certificate provisioning.
#
# Solves the chicken-and-egg problem: nginx needs certs to start with SSL,
# but certbot needs nginx running to complete the ACME HTTP-01 challenge.
#
# Usage: ./docker/nginx/init-letsencrypt.sh
#   Reads MOODLE_HOST and CERTBOT_EMAIL from .env file in the project root.

set -euo pipefail

# ---------------------------------------------------------------------------
# Load variables from .env
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

if [ ! -f "$PROJECT_ROOT/.env" ]; then
  echo "Error: .env file not found at $PROJECT_ROOT/.env"
  echo "Copy .env.example to .env and configure MOODLE_HOST and CERTBOT_EMAIL."
  exit 1
fi

# Source .env (only the variables we need)
MOODLE_HOST=$(grep -E '^MOODLE_HOST=' "$PROJECT_ROOT/.env" | cut -d '=' -f2-)
CERTBOT_EMAIL=$(grep -E '^CERTBOT_EMAIL=' "$PROJECT_ROOT/.env" | cut -d '=' -f2-)

if [ -z "$MOODLE_HOST" ] || [ "$MOODLE_HOST" = "localhost" ]; then
  echo "Error: MOODLE_HOST must be set to a real domain in .env (not localhost)."
  exit 1
fi

if [ -z "$CERTBOT_EMAIL" ] || [ "$CERTBOT_EMAIL" = "admin@example.com" ]; then
  echo "Error: CERTBOT_EMAIL must be set to a real email address in .env."
  exit 1
fi

COMPOSE="docker compose -f $PROJECT_ROOT/docker-compose.yml -f $PROJECT_ROOT/docker-compose.prod.yml"
DATA_PATH="certbot_conf"
LIVE_PATH="/etc/letsencrypt/live/$MOODLE_HOST"

echo "### Provisioning Let's Encrypt certificate for $MOODLE_HOST ..."

# ---------------------------------------------------------------------------
# 1. Download recommended TLS parameters (if not already present)
# ---------------------------------------------------------------------------
echo "### Downloading recommended TLS parameters ..."
$COMPOSE run --rm --entrypoint "" certbot sh -c "
  mkdir -p /etc/letsencrypt
  if [ ! -f /etc/letsencrypt/options-ssl-nginx.conf ]; then
    wget -qO /etc/letsencrypt/options-ssl-nginx.conf \
      https://raw.githubusercontent.com/certbot/certbot/master/certbot-nginx/certbot_nginx/_internal/tls_configs/options-ssl-nginx.conf
  fi
  if [ ! -f /etc/letsencrypt/ssl-dhparams.pem ]; then
    wget -qO /etc/letsencrypt/ssl-dhparams.pem \
      https://raw.githubusercontent.com/certbot/certbot/master/certbot/certbot/ssl-dhparams.pem
  fi
"

# ---------------------------------------------------------------------------
# 2. Create dummy certificate so nginx can start
# ---------------------------------------------------------------------------
echo "### Creating dummy certificate for $MOODLE_HOST ..."
$COMPOSE run --rm --entrypoint "" certbot sh -c "
  mkdir -p /etc/letsencrypt/live/$MOODLE_HOST
  openssl req -x509 -nodes -newkey rsa:2048 -days 1 \
    -keyout /etc/letsencrypt/live/$MOODLE_HOST/privkey.pem \
    -out /etc/letsencrypt/live/$MOODLE_HOST/fullchain.pem \
    -subj '/CN=localhost'
"

# ---------------------------------------------------------------------------
# 3. Start nginx with the dummy cert
# ---------------------------------------------------------------------------
echo "### Starting nginx ..."
$COMPOSE up --force-recreate -d nginx

# ---------------------------------------------------------------------------
# 4. Delete the dummy certificate
# ---------------------------------------------------------------------------
echo "### Deleting dummy certificate ..."
$COMPOSE run --rm --entrypoint "" certbot sh -c "
  rm -rf /etc/letsencrypt/live/$MOODLE_HOST
  rm -rf /etc/letsencrypt/archive/$MOODLE_HOST
  rm -f  /etc/letsencrypt/renewal/$MOODLE_HOST.conf
"

# ---------------------------------------------------------------------------
# 5. Request real certificate from Let's Encrypt
# ---------------------------------------------------------------------------
echo "### Requesting Let's Encrypt certificate for $MOODLE_HOST ..."
$COMPOSE run --rm --entrypoint "certbot" certbot certonly \
  --webroot \
  -w /var/www/certbot \
  -d "$MOODLE_HOST" \
  --email "$CERTBOT_EMAIL" \
  --agree-tos \
  --no-eff-email \
  --force-renewal

# ---------------------------------------------------------------------------
# 6. Reload nginx with the real certificate
# ---------------------------------------------------------------------------
echo "### Reloading nginx ..."
$COMPOSE exec nginx nginx -s reload

echo "### Done! SSL certificate for $MOODLE_HOST has been provisioned."
