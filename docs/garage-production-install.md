# Production-Ready Garage Installation Guide

Garage is an S3-compatible distributed object storage system designed for self-hosting. This guide covers deploying Garage in production for use with the Moodle LMS platform.

**Official docs:** https://garagehq.deuxfleurs.fr/documentation/

---

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Running Garage](#running-garage)
5. [Cluster Setup](#cluster-setup)
6. [Bucket and Key Management](#bucket-and-key-management)
7. [Reverse Proxy with Nginx](#reverse-proxy-with-nginx)
8. [Connecting to Moodle](#connecting-to-moodle)
9. [Storage Recommendations](#storage-recommendations)
10. [Verification](#verification)

---

## Prerequisites

### Hardware

| Setup | Nodes | Replication | Use Case |
|-------|-------|-------------|----------|
| Single-node | 1 | `replication_factor = 1` | Small deployments, non-critical data |
| Multi-node | 3+ | `replication_factor = 3` | Production with high availability |

Each node needs:
- Persistent storage for data and metadata
- SSD recommended for metadata directory
- Direct IP connectivity between all nodes (for multi-node setups)

### Software

- Linux server (Debian/Ubuntu, RHEL, etc.)
- Docker (if using container deployment), or ability to run a static binary
- `openssl` for generating secrets
- Nginx (or another reverse proxy) for TLS termination

### Network

- Port **3901** open between Garage nodes (RPC)
- Port **3900** accessible from reverse proxy (S3 API)
- Port **3903** accessible locally only (Admin API)
- A domain name with DNS configured (e.g., `s3.example.com`)

---

## Installation

### Option A: Docker Image (Recommended)

```bash
docker pull dxflrs/garage:v2.2.0
```

> Avoid using the `latest` tag in production. Pin to a specific version.

### Option B: Static Binary

Download the binary for your architecture from https://garagehq.deuxfleurs.fr/download/ and place it in your `$PATH`:

```bash
wget https://garagehq.deuxfleurs.fr/_releases/v2.2.0/x86_64-unknown-linux-musl/garage
chmod +x garage
sudo mv garage /usr/local/bin/
```

---

## Configuration

Create the configuration file at `/etc/garage.toml`.

### Generate Secrets

```bash
# RPC secret (must be identical on all nodes)
openssl rand -hex 32

# Admin API token
openssl rand -base64 32

# Metrics token (for Prometheus scraping)
openssl rand -base64 32
```

### Production Configuration

```toml
metadata_dir = "/var/lib/garage/meta"
data_dir = "/var/lib/garage/data"
db_engine = "lmdb"
metadata_auto_snapshot_interval = "6h"

compression_level = 2

# Use replication_factor = 3 for multi-node, 1 for single-node
replication_factor = 3

rpc_bind_addr = "[::]:3901"
rpc_public_addr = "<THIS_NODE_PUBLIC_IP>:3901"
rpc_secret = "<GENERATED_RPC_SECRET>"

[s3_api]
s3_region = "garage"
api_bind_addr = "[::]:3900"
root_domain = ".s3.garage"

[s3_web]
bind_addr = "[::]:3902"
root_domain = ".web.garage"
index = "index.html"

[admin]
api_bind_addr = "127.0.0.1:3903"
admin_token = "<GENERATED_ADMIN_TOKEN>"
metrics_token = "<GENERATED_METRICS_TOKEN>"
```

### Key Settings Explained

| Setting | Description |
|---------|-------------|
| `db_engine = "lmdb"` | Fastest engine, recommended for production. Use `sqlite` if you need 32-bit support or architecture portability. |
| `metadata_auto_snapshot_interval` | Protects against metadata corruption from unclean shutdowns. |
| `compression_level = 2` | Light compression. Set to `0` to disable if data is already compressed (images, video). |
| `rpc_public_addr` | Must be reachable by other nodes. Set per-node. |
| `rpc_secret` | Must be **identical** across all nodes in the cluster. |
| `admin.api_bind_addr` | Bind to `127.0.0.1` in production to prevent external access. |

### Create Data Directories

```bash
sudo mkdir -p /var/lib/garage/meta /var/lib/garage/data
sudo chown -R garage:garage /var/lib/garage
```

---

## Running Garage

### Option A: Systemd Service (Binary Install)

Create `/etc/systemd/system/garage.service`:

```ini
[Unit]
Description=Garage S3-compatible object storage
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=garage
Group=garage
ExecStart=/usr/local/bin/garage -c /etc/garage.toml server
Restart=always
RestartSec=5
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
```

```bash
# Create service user
sudo useradd --system --no-create-home --shell /usr/sbin/nologin garage
sudo chown -R garage:garage /var/lib/garage

# Enable and start
sudo systemctl daemon-reload
sudo systemctl enable garage
sudo systemctl start garage

# Check status
sudo systemctl status garage
journalctl -u garage -f
```

### Option B: Docker

```bash
docker run \
  -d \
  --name garage \
  --restart always \
  --network host \
  -v /etc/garage.toml:/etc/garage.toml:ro \
  -v /var/lib/garage/meta:/var/lib/garage/meta \
  -v /var/lib/garage/data:/var/lib/garage/data \
  dxflrs/garage:v2.2.0
```

> **Host networking** is required for inter-node RPC communication, especially over IPv6.

### Option C: Docker Compose

```yaml
version: "3"
services:
  garage:
    image: dxflrs/garage:v2.2.0
    network_mode: "host"
    restart: unless-stopped
    volumes:
      - /etc/garage.toml:/etc/garage.toml:ro
      - /var/lib/garage/meta:/var/lib/garage/meta
      - /var/lib/garage/data:/var/lib/garage/data
```

### Docker CLI Alias

For convenience when using Docker:

```bash
alias garage="docker exec -ti garage /garage"
```

---

## Cluster Setup

### Single-Node Setup

```bash
# Check node status
garage status

# Get the node ID from the output
garage layout assign -z dc1 -c 100G <NODE_ID>
garage layout apply --version 1
```

Replace `100G` with your desired storage capacity.

### Multi-Node Setup (3+ Nodes)

**1. Get each node's ID:**

```bash
garage node id
# Output: 563e1ac8...@10.0.0.1:3901
```

**2. Connect nodes to each other** (run on any one node — discovery is transitive):

```bash
garage node connect <NODE_2_ID>@<NODE_2_IP>:3901
garage node connect <NODE_3_ID>@<NODE_3_IP>:3901
```

**3. Verify all nodes are visible:**

```bash
garage status
```

**4. Assign layout** (zones ensure replicas are distributed across failure domains):

```bash
garage layout assign <NODE_1_ID> -z dc1 -c 500G -t node1
garage layout assign <NODE_2_ID> -z dc2 -c 500G -t node2
garage layout assign <NODE_3_ID> -z dc3 -c 500G -t node3
```

**5. Review and apply:**

```bash
garage layout show
garage layout apply --version 1
```

> Garage stores one copy per zone. Usable capacity equals the smallest zone's capacity.

---

## Bucket and Key Management

### Create a Bucket

```bash
garage bucket create moodle
```

### Create an API Key

```bash
garage key create moodle-app-key
```

Note the **Access Key ID** and **Secret Access Key** from the output — you will need these for Moodle configuration.

### Grant Permissions

```bash
garage bucket allow \
  --read \
  --write \
  --owner \
  moodle \
  --key moodle-app-key
```

### Enable CORS (Required for Browser Uploads)

Using the AWS CLI or a script with boto3:

```bash
aws s3api put-bucket-cors \
  --endpoint-url http://localhost:3900 \
  --bucket moodle \
  --cors-configuration '{
    "CORSRules": [{
      "AllowedOrigins": ["https://your-moodle-domain.com"],
      "AllowedMethods": ["GET", "PUT", "POST", "HEAD", "DELETE"],
      "AllowedHeaders": ["*"],
      "ExposeHeaders": ["ETag", "Content-Length", "Content-Type"],
      "MaxAgeSeconds": 3600
    }]
  }'
```

> Replace `AllowedOrigins` with your actual Moodle URL. Avoid using `*` in production.

### Verify Setup

```bash
garage bucket info moodle
garage key info moodle-app-key
```

---

## Reverse Proxy with Nginx

TLS termination is handled by Nginx in front of Garage.

### DNS

Create an A record pointing to your server:

```
s3.example.com  →  <SERVER_IP>
```

### Nginx Configuration

```nginx
upstream garage_s3 {
    server 127.0.0.1:3900;
    # Add more nodes for load balancing:
    # server 10.0.0.2:3900;
    # server 10.0.0.3:3900;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;

    server_name s3.example.com;

    ssl_certificate     /etc/letsencrypt/live/s3.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/s3.example.com/privkey.pem;

    # Allow large uploads
    client_max_body_size 500M;

    location / {
        proxy_pass http://garage_s3;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header Host $http_host;
        proxy_max_temp_file_size 0;

        # Timeouts for large uploads
        proxy_connect_timeout 300;
        proxy_send_timeout 300;
        proxy_read_timeout 300;
    }
}

# HTTP to HTTPS redirect
server {
    listen 80;
    listen [::]:80;
    server_name s3.example.com;
    return 301 https://$host$request_uri;
}
```

### Obtain TLS Certificate

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d s3.example.com
```

### Test

```bash
sudo nginx -t
sudo systemctl reload nginx
```

---

## Connecting to Moodle

### Environment Variables

Set these in your `.env` file for the Moodle deployment:

```bash
S3_ENDPOINT=https://s3.example.com
S3_ACCESS_KEY=<KEY_ID_FROM_GARAGE>
S3_SECRET_KEY=<SECRET_FROM_GARAGE>
S3_BUCKET=moodle
S3_REGION=garage
```

These are passed to the PHP and Cron containers via `docker-compose.yml`.

### Moodle Admin Panel (reblibrary Plugin)

After Moodle is running, configure the reblibrary plugin under **Site Administration**:

| Setting | Value |
|---------|-------|
| `s3_endpoint` | `https://s3.example.com` (internal endpoint used by PHP container) |
| `s3_public_endpoint` | `https://s3.example.com` (endpoint used by browsers) |
| `s3_access_key` | Your Garage access key |
| `s3_secret_key` | Your Garage secret key |
| `s3_bucket` | `moodle` |
| `s3_region` | `garage` |

> If Garage runs on the same Docker network as Moodle, `s3_endpoint` can use the internal address (e.g., `http://garage:3900`) while `s3_public_endpoint` uses the public HTTPS URL.

---

## Storage Recommendations

### Filesystem

| Directory | Recommendation |
|-----------|---------------|
| Metadata (`/var/lib/garage/meta`) | SSD with BTRFS or ZFS for integrity checking. Take regular snapshots. |
| Data (`/var/lib/garage/data`) | XFS preferred. EXT4 has inode limitations with large object counts. |

### Capacity Planning

- With `replication_factor = 3`, each object is stored 3 times
- Usable capacity = smallest zone's capacity
- Plan for balanced storage across zones

### Backups

- Enable `metadata_auto_snapshot_interval = "6h"` (already in the config above)
- Back up `/var/lib/garage/meta` regularly
- Data blocks are content-addressed and can be reconstructed from other nodes in a multi-node setup

---

## Verification

### Test with AWS CLI

```bash
# Install
pip install awscli

# Configure
export AWS_ACCESS_KEY_ID=<YOUR_KEY>
export AWS_SECRET_ACCESS_KEY=<YOUR_SECRET>
export AWS_DEFAULT_REGION=garage
export AWS_ENDPOINT_URL=https://s3.example.com

# Test operations
aws s3 ls
aws s3 ls s3://moodle
aws s3 cp /tmp/test.txt s3://moodle/test.txt
aws s3 cp s3://moodle/test.txt /tmp/test-download.txt
aws s3 rm s3://moodle/test.txt
```

### Health Check

```bash
curl -s http://localhost:3903/health
```

### Cluster Status

```bash
garage status
garage bucket info moodle
garage key info moodle-app-key
```
