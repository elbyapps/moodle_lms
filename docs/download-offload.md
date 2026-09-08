# Download saturation: fix and rollout

## What changes

- `vendor/admin_tool_objectfs/classes/local/store/object_file_system.php`: honour
  Moodle's existing X-Sendfile configuration for readable local copies even when
  ObjectFS is configured. Signed redirects still take priority; `preferexternal`
  is respected. Never fetch an external-only file just to offload it. Core's
  alias/header checks decide whether offload is possible. Remote streaming and
  remote Range handling remain fallbacks.
- `vendor/local_reblibrary/download.php`: **all library assets download directly
  from object storage**, regardless of size or type. Existing stable download URLs
  still perform login/capability checks and validate `resources/` keys, release the
  session, then return a short-lived signed HTTPS redirect. No PHP byte proxy,
  local-file fallback or opt-in setting remains. GET and HEAD are signed separately;
  HEAD has no GET-only S3 response overrides. Redirects are private/no-store, and
  signed GET responses privately cacheable. Storage handles file bytes, missing
  objects and Range responses. Signing/invalid-endpoint failures return a generic
  503, not a proxy fallback; SDK error details and signatures are not logged.
- Production sets core `folder/maxsizetodownload` to 100 MiB using
  `MOODLE_FOLDER_MAX_DOWNLOAD_MB`. Core checks this before ZIP generation, including
  direct endpoint requests. Individual files remain downloadable. Publish larger
  collections as prebuilt archives served through `pluginfile.php`, rather than
  generating a new ZIP for every request. This is an admission limit, **not a ZIP
  cache**: smaller archives still run synchronously.
- Production nginx defaults to 4 CPUs / 4 GiB (configurable with `NGINX_CPUS` and
  `NGINX_MEMORY`), with quota-aware worker autotuning. PHP concurrency is unchanged.
- Base/development nginx now mounts the same moodledata as PHP, read-only; merged
  production/staging configurations retain their named-volume overrides.
- All app services have bounded JSON logs (3 × 50 MB per container). Rotation only
  takes effect on recreation. Existing logs are not deleted/truncated by this fix.

## Evidence from production, 2026-09-08

At ~10:07–10:24 UTC, 49,126 requests transferred 65.8 GB, of which 33.9 GB came
from `pluginfile.php` and 30.2 GB from `/mod/` endpoints. 18.8% ended as HTTP 499.
PHP replicas were capped at 26 workers each and logging slow file/ZIP requests.
Nginx had 2 CPUs/1 GiB, high cgroup CPU pressure and repeated memory-limit events.
ObjectFS was enabled, signed redirects disabled, and local deletion disabled.

The original diagnosis that every ObjectFS read necessarily went to S3 was too
broad: ObjectFS can read a local copy, but its configured `xsendfile_file()`
returned false rather than using core's local offload. Such files were still
streamed **through PHP**, despite a configured `/dataroot/` nginx alias. This is
the primary code defect fixed here. Public S3 redirects are a separate optimisation.

Read-only checks through `%48` also established:

- An actual signed ObjectFS request to the public storage IP, with TLS verification,
  returned 206 in 27 ms; the first 1 KiB matched the local file. The initial HTTP
  400 was a probe error (`moodle_url` HTML-escaped the query), corrected by using
  `out(false)`. This proves one sampled object, not all clients/routes.
- The sampled ObjectFS storage response had no CORS headers for the LMS origin.
  Library bucket CORS and browser validation are **deployment gates**, because
  the new library code always redirects. Do not roll it out until those pass.
- Against real production Moodle, a separate CLI process evaluated the candidate
  class under a temporary class name and process-local config override. For the
  same 2,650,202-byte file, the deployed implementation declined local offload;
  the configured candidate accepted it via both file and legacy hash APIs.
  This changed no served code/settings and **does not verify HTTP response headers
  or nginx's end-to-end delivery**; those remain canary acceptance checks.

## Local tests (no database or production changes)

From the repository root, with a PHP 8.2+ CLI image available:

```sh
for test in download-offload library-signing library-signing-sdk library-download-http; do
  docker run --rm --network none --entrypoint php \
    -v "$PWD:/work:ro" repo-php:latest "/work/scripts/tests/$test.php" || exit
done
```

These are boundary tests against the actual vendored classes/controller with
Moodle dependencies stubbed. `library-signing-sdk.php` also serializes and signs
GET/HEAD with the image's **real bundled AWS SDK**, dummy credentials and no
network. The other signing test captures the SDK command arguments. The HTTP
test creates a localhost-only test server inside the network-isolated container.
They do not replace a Moodle + nginx + storage integration test or browser checks.

Read-only review identified GET-only overrides on signed HEAD and a missing dev
nginx data mount; both were corrected. At the owner's request, the optional library
proxy was subsequently removed entirely. HTTP tests now enforce redirects for
PDFs, small covers, media and archives even with no legacy setting; failure tests
verify no proxy fallback or secret leakage. All four suites and PHP syntax checks
are run by the parent. Base/dev/prod/staging merged Compose assertions verified
shared data sources, read-only nginx, log bounds and production limits. The
reviewer did not rerun the final revision.

## Production preflight (read-only, use tmux %48)

Before deploying:

1. Record deployed git ref and immutable PHP/nginx image IDs; retain rollback
   images. Back up config files and the existing folder limit. Do not print `.env`
   or full Docker inspections containing secrets. Preserve host volume overrides.
2. Confirm PHP and nginx mount the **same** moodledata and that nginx can read a
   sampled `filedir/aa/bb/hash` file. `preferexternal` must be false to use this
   local path. If local deletion is enabled later, coordinate it with in-flight
   offloaded reads; do not assume local existence from DB metadata alone.
3. Verify public signed access before deploying the library controller or enabling
   ObjectFS's separate signed-URL setting:

   ```sh
   docker exec -i moodle_lms-php-89 php /dev/stdin \
     --contextid=177277 --public-ip=197.243.27.25 \
     < scripts/check-download-offload.php
   ```

   Use a real folder context containing a >=1 MiB file. The example IP bypasses
   the container's private hosts entry while retaining TLS hostname validation.
   Re-resolve the public IP before use. The probe verifies a signed 1 KiB Range
   response and compares it with a local copy if available; no URLs or credentials
   are printed and no settings change. A missing remote copy can legitimately
   fail even when local offload will work. A 403 at the bucket root alone is not
   evidence that signed downloads work.
4. **Required for library deployment:** repeat with `--library-key=resources/.../file.pdf` using
   a real >=1 KiB PDF. Validate its public endpoint separately if it differs from
   ObjectFS (omit `--public-ip` or use the correct address). Configure storage CORS
   for the exact LMS origin: GET/HEAD, request header Range, exposed Content-Range,
   Accept-Ranges, Content-Length and ETag. Do not enable credentialed wildcard CORS.
   The CLI probe checks GET/range headers; browser checks must also cover HEAD,
   preview, saving a PDF, cover images (including <1 MiB), video seek and URL expiry.
   Confirm all library uploads' stable `download.php?key=...` URLs redirect and no
   asset bytes are returned by PHP. Use HTTPS in development too; no plaintext
   exception is built into download delivery.

## Deployment sequence

Changes here are code/config only; they do not require a DB schema migration.
Do not blindly invoke the current rolling script during this incident:
`scripts/deploy.sh code` waits for **all existing replicas** to become healthy,
so existing saturation can prevent progression. It also recreates the singleton
nginx at the end, interrupting its active downloads. Schedule/approve that boundary.

1. Build and tag candidate PHP and nginx images without stopping the live stack.
   A fresh image installs the vendored source. `build.sh` skips already-existing
   plugin directories in a local `moodle_app` checkout; do not mistake an old dev
   copy for the candidate code. Do not run `make build-fresh` in production: it
   tears down the stack.
2. Give nginx the configured headroom before shifting more delivery onto it.
   Use an approved drain/recreate window (or a parallel proxy cutover) to activate
   worker autotuning and log limits; do not promise a lossless singleton restart.
3. Start **one** candidate PHP replica with the existing production env/volumes
   and explicit folder limit, preserving the old replicas. Validate its health
   and direct requests before adding it to the live upstream. Avoid entrypoint
   recursion over a live moodledata tree during an improvised `docker run`; use
   the site's established canary procedure.
4. Check a known local PDF: permission checks still run, FastCGI returns an
   `X-Accel-Redirect`, nginx serves the same bytes/Content-Type/Disposition, and
   byte ranges return 206. Direct external access to `/dataroot/...` must remain
   404. Verify an external-only file still works via the existing fallback.
5. Verify a folder above 100 MiB has no ZIP button and the direct ZIP URL is
   rejected before archiving; its individual files remain usable. Check a smaller
   ZIP still works. Arrange prebuilt archives for required large collections.
6. Roll remaining PHP replicas only after the canary passes; recreate cron with
   the same config. Retain old images until post-deploy checks pass. Log rotation
   on Redis can wait for its normal maintenance window; do not interrupt sessions
   simply to activate log settings.
7. Library redirects activate with the candidate code; there is no
   `local_reblibrary/s3_download_redirect` toggle. Any old stored value is ignored.
   Independently, **after public-storage tests pass**, optionally enable
   `tool_objectfs/enablepresignedurls=1` for ordinary Moodle files:

   ```sh
   docker exec <candidate-php> php /var/www/html/moodle_app/admin/cli/cfg.php \
     --component=tool_objectfs --name=enablepresignedurls --set=1
   ```

   Keep `OBJECTFS_PRESIGNED` in `.env` consistent with the intended DB setting;
   changing the environment alone does not update an existing DB setting. Avoid
   `setup_objectfs.php --force` as an incidental deploy step: it also overwrites
   unrelated deletion/migration controls. Do not make the buckets public.

## Acceptance and rollback

Compare the same routes before/after under comparable live traffic: homepage
TTFB/p95, completed requests and 499/5xx rate, PHP active/idle workers, nginx CPU
throttling/memory-event deltas and network throughput. Watch both PHP queues and
nginx; offloading does not remove bandwidth demand. Full swap alone does not
identify the bottleneck. Investigate unexplained host memory separately.

- Gate library deployment on CORS/browser checks. If the canary fails, stop the
  rollout and revert to the retained image; no runtime PHP-proxy fallback exists.
  Previously issued signed URLs remain usable until expiry.
- Toggle ObjectFS signed URLs back to 0 independently. Local offload still works.
- Roll PHP/nginx back to retained image IDs on canary failure. Keep necessary
  nginx capacity rather than automatically restoring an inadequate resource cap.
- Restore the previous folder limit through the deployment environment. Setting
  `MOODLE_FOLDER_MAX_DOWNLOAD_MB=0` permits unlimited ZIPs; it is not a safe normal
  operating mode. Removing the override restores the DB setting outside the prod
  Compose default, so restore the previous Compose/config snapshot if required.
- No cache purges, service restarts, DB setting writes or production deployment
  are performed by `check-download-offload.php`.
