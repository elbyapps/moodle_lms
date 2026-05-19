# example_local_hello

Placeholder vendored Moodle plugin. **Delete this directory** once you
have a real vendored plugin to ship, or keep it as a copy-paste template.

It is inert: nothing under `vendor/` is installed unless an entry in
`moodle-config.json` references it. To actually wire this stub into a
build, add to `moodle-config.json`:

```json
{
  "name": "local_hello",
  "source": "vendor",
  "path": "vendor/example_local_hello",
  "destination": "local/hello"
}
```

Then rebuild. After the next `make build-fresh` you'll find the plugin
at `moodle_app/public/local/hello/version.php` and Moodle's installer
will offer to install "Hello (vendor example)" on the next page hit.

See `../README.md` for the full vendoring workflow.
