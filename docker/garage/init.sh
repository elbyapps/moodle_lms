#!/bin/sh
set -e

apk add --no-cache curl jq >/dev/null 2>&1
pip install --no-cache-dir boto3 >/dev/null 2>&1

GARAGE_ADMIN="http://garage:3903"
ADMIN_TOKEN="${GARAGE_ADMIN_TOKEN:-moodledevadmintoken}"
AUTH="Authorization: Bearer ${ADMIN_TOKEN}"
BUCKET_NAME="${S3_BUCKET:-moodle}"
ACCESS_KEY="${S3_ACCESS_KEY:-GK000000000000000000000000}"
SECRET_KEY="${S3_SECRET_KEY:-0000000000000000000000000000000000000000000000000000000000000000}"
REGION="${S3_REGION:-us-east-1}"

echo "Waiting for Garage admin API..."
for i in $(seq 1 30); do
    if curl -sf -H "$AUTH" "$GARAGE_ADMIN/v1/status" >/dev/null 2>&1; then
        echo "Garage is ready."
        break
    fi
    sleep 2
done

echo "Configuring Garage layout..."
NODE_ID=$(curl -sf -H "$AUTH" "$GARAGE_ADMIN/v1/status" | jq -r '.node')
echo "Node ID: ${NODE_ID}"

if [ -z "$NODE_ID" ] || [ "$NODE_ID" = "null" ]; then
    echo "ERROR: Could not get node ID"
    exit 1
fi

curl -sf -X POST \
    -H "$AUTH" \
    -H "Content-Type: application/json" \
    -d "[{\"id\":\"${NODE_ID}\",\"zone\":\"dc1\",\"capacity\":1073741824,\"tags\":[\"dev\"]}]" \
    "$GARAGE_ADMIN/v1/layout" && echo "Layout staged." || echo "Layout staging skipped (may already exist)."

CURRENT_VERSION=$(curl -sf -H "$AUTH" "$GARAGE_ADMIN/v1/layout" | jq -r '.version')
NEXT_VERSION=$((CURRENT_VERSION + 1))
echo "Applying layout version ${NEXT_VERSION}..."
curl -sf -X POST \
    -H "$AUTH" \
    -H "Content-Type: application/json" \
    -d "{\"version\":${NEXT_VERSION}}" \
    "$GARAGE_ADMIN/v1/layout/apply" && echo "Layout applied." || echo "Layout apply skipped (may already be applied)."

sleep 2

echo "Creating API key..."
curl -sf -X POST \
    -H "$AUTH" \
    -H "Content-Type: application/json" \
    -d "{\"name\":\"moodle\",\"accessKeyId\":\"${ACCESS_KEY}\",\"secretAccessKey\":\"${SECRET_KEY}\"}" \
    "$GARAGE_ADMIN/v1/key/import" >/dev/null 2>&1 && echo "Key created." || echo "Key exists."

echo "Creating bucket '${BUCKET_NAME}'..."
curl -sf -X POST \
    -H "$AUTH" \
    -H "Content-Type: application/json" \
    -d "{\"globalAlias\":\"${BUCKET_NAME}\"}" \
    "$GARAGE_ADMIN/v1/bucket" >/dev/null 2>&1 && echo "Bucket created." || echo "Bucket exists."

echo "Granting bucket permissions..."
BUCKET_ID=$(curl -sf -H "$AUTH" "$GARAGE_ADMIN/v1/bucket?globalAlias=${BUCKET_NAME}" | jq -r '.id')
echo "Bucket ID: ${BUCKET_ID}"

if [ -n "$BUCKET_ID" ] && [ "$BUCKET_ID" != "null" ]; then
    curl -sf -X POST \
        -H "$AUTH" \
        -H "Content-Type: application/json" \
        -d "{\"bucketId\":\"${BUCKET_ID}\",\"accessKeyId\":\"${ACCESS_KEY}\",\"permissions\":{\"read\":true,\"write\":true,\"owner\":true}}" \
        "$GARAGE_ADMIN/v1/bucket/allow" >/dev/null 2>&1 && echo "Permissions granted." || echo "Permissions exist."

    # Enable website access
    curl -sf -X PUT \
        -H "$AUTH" \
        -H "Content-Type: application/json" \
        -d "{\"websiteAccess\":{\"enabled\":true,\"indexDocument\":\"index.html\",\"errorDocument\":\"\"}}" \
        "$GARAGE_ADMIN/v1/bucket?id=${BUCKET_ID}" >/dev/null 2>&1 && echo "Website access enabled." || true

    # Set CORS via S3 API (admin API does not handle CORS correctly)
    echo "Configuring CORS via S3 API..."
    python3 << PYEOF
import boto3
from botocore.config import Config

s3 = boto3.client('s3',
    endpoint_url='http://garage:3900',
    aws_access_key_id='${ACCESS_KEY}',
    aws_secret_access_key='${SECRET_KEY}',
    region_name='${REGION}',
    config=Config(s3={'addressing_style': 'path'})
)

s3.put_bucket_cors(Bucket='${BUCKET_NAME}', CORSConfiguration={
    'CORSRules': [{
        'AllowedOrigins': ['*'],
        'AllowedMethods': ['GET', 'PUT', 'POST', 'HEAD', 'DELETE'],
        'AllowedHeaders': ['*'],
        'ExposeHeaders': ['ETag', 'Content-Length', 'Content-Type'],
        'MaxAgeSeconds': 3600,
    }]
})
print("CORS configured.")
PYEOF

    echo "Bucket '${BUCKET_NAME}' configured successfully"
else
    echo "ERROR: Could not find bucket ID for '${BUCKET_NAME}'"
    exit 1
fi

echo "Garage initialization completed!"
