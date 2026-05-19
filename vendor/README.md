# vendor/

Drop-in directory for Moodle plugins whose source isn't on a public git
remote — for example: an in-house plugin you haven't published yet, or a
plugin whose upstream has gone silent and you want to freeze a known-good
snapshot.

## How it works

`build.sh` reads `moodle-config.json` and processes every plugin entry,
choosing how to install it based on the entry's `source` field:

- `source: "git"` (default) — clone from `repository` at `version`.
- `source: "vendor"` — copy the directory at `path` (relative to the repo
  root, under `vendor/`) into `moodle_app/public/<destination>`.

Both Docker build stages (`docker/php/Dockerfile` and the `moodle_fetch`
stage of `docker/nginx/Dockerfile`) `COPY vendor ./vendor` before running
`build.sh`, so vendored plugins land in `public/` exactly like cloned
ones. There's no separate per-plugin `COPY` to maintain, and no way for
the PHP and nginx images to disagree about which plugins are installed.

## Adding a vendored plugin

1. Drop the plugin tree at `vendor/<name>/` so its `version.php` lives at
   `vendor/<name>/version.php`.

2. Add an entry to `moodle-config.json`:

   ```json
   {
     "name": "<name>",
     "source": "vendor",
     "path": "vendor/<name>",
     "destination": "local/<name>"
   }
   ```

   `destination` follows Moodle's plugin path convention — e.g.
   `filter/myfilter`, `local/mything`, `mod/whatever`. It is interpreted
   relative to Moodle's web root (`moodle_app/public/`).

3. Rebuild: `make build-fresh`.

## Placeholder example

`vendor/example_local_hello/` ships a minimal valid `local_` plugin
(`version.php` + `lang/en/local_hello.php`) as a copy-paste template.
It is **not** installed automatically — nothing in `vendor/` is touched
unless `moodle-config.json` references it. Delete the directory once
you don't need the template, or wire it up per its own README to see
the vendoring flow end-to-end.

## Why this isn't `composer`

Moodle plugins aren't composer packages — their layout, autoloading and
upgrade hooks are managed by Moodle's own plugin system. `vendor/` here
is just a directory of plugin trees; it has nothing to do with PHP's
composer `vendor/` convention. The repo's `.gitignore` carries an
explicit `!vendor/` exception so the directory is tracked.
