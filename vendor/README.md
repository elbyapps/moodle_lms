# vendor/

Drop-in directory for Moodle plugins whose source isn't on a public git
remote — for example: an in-house plugin you haven't published yet, or a
plugin whose upstream has gone silent and you want to freeze a known-good
snapshot.

## How it works

Plugins listed in `moodle-config.json` are cloned by `build.sh` into
`moodle_app/public/<destination>`. Plugins under `vendor/` are *not*
cloned — they're copied straight into the image at build time by
`docker/php/Dockerfile`, alongside the cloned ones.

## Adding a vendored plugin

1. Drop the plugin tree under `vendor/<plugin_name>/` so its `version.php`
   is at `vendor/<plugin_name>/version.php`.

2. Add a `COPY` line to **both** `docker/php/Dockerfile` and
   `docker/nginx/Dockerfile` (nginx needs the static assets):

       COPY vendor/<plugin_name> /var/www/html/moodle_app/public/<destination>

   `<destination>` follows Moodle's plugin path convention — e.g.
   `filter/myfilter`, `local/mything`, `mod/whatever`.

3. Rebuild: `make build-fresh`.

## Why two COPYs?

The PHP image runs the plugin code; the nginx image serves its static
assets directly without going through PHP-FPM. Skipping the nginx COPY
will produce 404s for the plugin's CSS/JS/images.

## Why this isn't `composer`

Moodle plugins aren't composer packages — their layout, autoloading and
upgrade hooks are managed by Moodle's own plugin system. `vendor/` here
is just a directory of plugin trees; it has nothing to do with PHP's
composer `vendor/` convention. The repo's `.gitignore` carries an
explicit `!vendor/` exception so the directory is tracked.
