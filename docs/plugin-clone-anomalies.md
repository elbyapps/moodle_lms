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
