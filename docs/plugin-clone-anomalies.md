# Plugin clone anomalies

## block_configurable_reports — empty `master` branch

The upstream https://github.com/jleyva/moodle-block_configurablereports
keeps `master` as an orphan branch containing only `README.txt`. The actual
code lives on the **default branch** `MOODLE_4x_STABLE` (also tagged `5.2.0`).

If `moodle-config.json` says `"version": "master"`, `build.sh` will clone
only the README and Moodle will refuse to upgrade with
`detectedbrokenplugin / Missing version.php file`.

## How to verify a plugin reference

Always check the candidate branch actually has plugin code:

    curl -sI https://raw.githubusercontent.com/<org>/<repo>/<branch>/version.php
    # 200 = branch has code; 404 = empty/orphan

For the GitHub default branch:

    curl -s https://api.github.com/repos/<org>/<repo> | jq -r .default_branch

## Pin discipline

`build.sh` clones with `git clone --depth 1 --branch <ref>`, which accepts
tags or branch names but **not commit SHAs**. So a plugin can be pinned at
the tag level at best.

Prefer tags over branches. Branch tips can move between rebuilds, which
makes two CI runs of the same commit ship different plugin code. The
`MOODLE_*_STABLE` branches are an exception — they only receive backports
within a release line, so they're effectively stable maintenance refs.

When evaluating an upstream's latest tag for an upgrade:

1. Read the tag's `version.php` and check `$plugin->supported` /
   `$plugin->requires` against your target Moodle version. A tag that's
   *newer* than `master` can still be older in terms of Moodle
   compatibility.
2. If the upstream only tags release candidates (e.g. `v4.4.0-RC2`),
   weigh staying on `master` against the RC risk — there's no universally
   right answer.
3. If the upstream doesn't tag at all (common for in-house plugins),
   either ask the maintainer to start tagging, or vendor a known-good
   snapshot under `vendor/` (see `vendor/README.md`).
