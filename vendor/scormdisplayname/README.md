# local_scormdisplayname

A Moodle local plugin that lets a teacher give a SCORM activity two independent labels:

- **Name** — the heading shown on the activity page when a student opens the SCORM.
- **Section display name** (new field added by this plugin) — the short link label shown next to the SCORM icon on the course section page.

It also fixes a duplicated-title rendering bug that Moodle 4.5+ / 5.x exhibits on SCORM activity pages (a bare `<h2>` rendered above the `<h1>` heading).

Tested on Moodle 5.0.2 (dev). Plugin-declared support range: Moodle 4.5 → 5.1.

## How it works

A pure local plugin — no `mod/scorm/*` files are modified. Mechanics:

1. **DB**: adds `mdl_local_scormdisplayname (id, scormid, fulltitle, timemodified)` via XMLDB.
2. **Form field injection**: `lib.php` implements the standard `coursemodule_standard_elements`, `coursemodule_definition_after_data`, and `coursemodule_edit_post_actions` callbacks. The new **Section display name** field is shown only on the SCORM mod_form.
3. **Storage "swap"**: when saving, the plugin
   - stores what the teacher typed into **Name** as `local_scormdisplayname.fulltitle` (the activity-page title),
   - writes what the teacher typed into **Section display name** into `mdl_scorm.name` (so the course section link uses it),
   - calls `rebuild_course_cache()` because Moodle 5.x rebuilds the modinfo cache *before* it dispatches `coursemodule_edit_post_actions` (otherwise the section page would keep the pre-overwrite name).
4. **Activity-page render**: a `\core\hook\output\before_http_headers` callback in `classes/hook_callbacks.php`
   - swaps the cm name with `cm_info::set_name($fulltitle)` so the Boost theme's `context_header()` renders the long title in `<h1>`,
   - clears `$PAGE->activityheader->title` (the `activity_header.mustache` template emits an extra bare `<h2>{{title}}</h2>` above the activity-header div; clearing the title suppresses that block — this is the **duplicate-title fix**),
   - sets `$PAGE->set_title(...)` so the browser tab matches what the user is reading.
5. **Edit-form round-trip**: `coursemodule_definition_after_data` reverses the swap when the edit screen is re-opened so the teacher always sees the strings they typed.
6. **Backup / restore**: `backup/moodle2/{backup,restore}_local_scormdisplayname_plugin.class.php` carry `fulltitle` through SCORM activity backup / restore.

A row in `mdl_local_scormdisplayname` exists **only** when the teacher set a Section display name different from Name. Leaving Section display name blank (or equal to Name) reverts that SCORM to vanilla Moodle behaviour and deletes the row.

## Per-course opt-in (v1.1.0)

To scope this plugin to specific courses, create a Moodle course custom field:

1. *Site administration → Courses → Course custom fields*.
2. Add a new **Checkbox** field with **Short name** exactly `enable_scormdisplayname` (the display name can be whatever you like, e.g. "Enable SCORM section display name").
3. On each course where you want the feature, edit the course settings and tick the box.

Behavior:

- If the custom field **does not exist**, the plugin behaves site-wide (legacy / backwards-compatible).
- If the field **exists**, the **Section display name** field and the activity-page heading swap only activate on courses where the box is ticked. Other courses see vanilla Moodle behavior.

## Install

Copy the plugin into your Moodle's `local/` directory (note: drop the `local_` prefix from the folder name when installing — Moodle infers it from the parent directory):

```bash
cp -r local_scormdisplayname /path/to/moodle/local/scormdisplayname
# Then in the browser go to Site administration → Notifications, or:
sudo -u www-data php /path/to/moodle/admin/cli/upgrade.php --non-interactive
```

For a Bitnami Docker container running on this project:

```bash
docker cp plugins/local_scormdisplayname/. moodle-moodle-1:/bitnami/moodle/local/scormdisplayname/
docker exec moodle-moodle-1 chown -R daemon:root /bitnami/moodle/local/scormdisplayname
docker exec -u daemon moodle-moodle-1 php /bitnami/moodle/admin/cli/upgrade.php --non-interactive
```

## Usage

1. Edit any SCORM activity.
2. The **Section display name** field appears under **Name**.
3. Fill it with the short label you want on the course section page (e.g. `Start Lesson`); leave it blank for default Moodle behaviour.
4. Save. The course page shows the short label; clicking it opens the activity page with the long Name as the heading.

## Uninstall

```bash
docker exec -u daemon moodle-moodle-1 php /bitnami/moodle/admin/cli/uninstall_plugins.php \
  --plugins=local_scormdisplayname --run
```

That drops `mdl_local_scormdisplayname` and removes the plugin code. Any SCORM whose `scorm.name` was overwritten with a short label will continue to use that short label in both places after uninstall — restore the long title by re-editing the activity.

## Known limitations

- Targets `mod_scorm` only. Other activity types are untouched.
- English language strings only.
- The `set_name()` / activity_header swap relies on Boost-family themes that build the page heading from `$PAGE->cm->get_formatted_name()`. Custom themes that derive the heading from a different source may not pick up the long title.

## File map

```
local_scormdisplayname/
├── version.php
├── lib.php                                # coursemodule_* form callbacks
├── classes/hook_callbacks.php             # before_http_headers handler
├── db/install.xml                         # mdl_local_scormdisplayname schema
├── db/upgrade.php                         # idempotent upgrade guard
├── db/hooks.php                           # registers the hook
├── lang/en/local_scormdisplayname.php     # English strings
├── backup/moodle2/
│   ├── backup_local_scormdisplayname_plugin.class.php
│   └── restore_local_scormdisplayname_plugin.class.php
└── README.md
```
