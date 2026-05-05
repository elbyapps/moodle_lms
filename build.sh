#!/bin/bash
# Build a Moodle 5.x source tree by cloning core and the plugins listed in
# moodle-config.json.
#
# Plugin destinations in moodle-config.json are paths relative to Moodle's
# web root — e.g. "auth/oidc" — and Moodle 5.x serves its web root from
# $DEST_FOLDER/public/. This script therefore clones every plugin to
#   $DEST_FOLDER/public/$PLUGIN_DEST
# Keeping the "public/" prefix as a build-script detail (instead of baking it
# into moodle-config.json) means the JSON stays portable across Moodle layouts.
#
# Requirements: git, jq.

set -euo pipefail

CONFIG_FILE="moodle-config.json"

command_exists() { command -v "$1" >/dev/null 2>&1; }

for cmd in git jq; do
  if ! command_exists "$cmd"; then
    echo "Error: '$cmd' is not installed." >&2
    exit 1
  fi
done

[ -f "$CONFIG_FILE" ] || { echo "Error: $CONFIG_FILE not found." >&2; exit 1; }

MOODLE_REPO=$(jq -r '.moodle.repository' "$CONFIG_FILE")
MOODLE_VERSION=$(jq -r '.moodle.version' "$CONFIG_FILE")
DEST_FOLDER=$(jq -r '.destination_folder' "$CONFIG_FILE")

echo "Configuration:"
echo "  Moodle:      $MOODLE_REPO @ $MOODLE_VERSION"
echo "  Destination: $DEST_FOLDER"

# 1. Moodle core ------------------------------------------------------------
if [ -d "$DEST_FOLDER" ] && [ -f "$DEST_FOLDER/public/index.php" ]; then
  echo "Moodle core already present at $DEST_FOLDER, skipping core clone."
else
  if [ -d "$DEST_FOLDER" ]; then
    echo "Removing incomplete $DEST_FOLDER directory..."
    rm -rf "$DEST_FOLDER"
  fi
  echo "Cloning Moodle core..."
  git clone --depth 1 --branch "$MOODLE_VERSION" "$MOODLE_REPO" "$DEST_FOLDER"
fi

# Moodle 5.x must ship a public/ web root.
if [ ! -d "$DEST_FOLDER/public" ]; then
  echo "Error: $DEST_FOLDER/public does not exist. Moodle 5.x requires the public/ layout." >&2
  exit 1
fi

# 2. Plugins ----------------------------------------------------------------
# Read all plugin lines first so we run the loop in the parent shell. A
# 'jq ... | while read' pipeline runs the loop in a subshell where 'set -e'
# does not abort the script on a failed clone — that's how plugins got
# silently dropped from earlier image builds.
mapfile -t PLUGIN_LINES < <(jq -c '.plugins[]' "$CONFIG_FILE")

failed=()
installed=0
skipped=0

for plugin in "${PLUGIN_LINES[@]}"; do
  PLUGIN_NAME=$(jq -r '.name'        <<<"$plugin")
  PLUGIN_REPO=$(jq -r '.repository'  <<<"$plugin")
  PLUGIN_VERSION=$(jq -r '.version'  <<<"$plugin")
  PLUGIN_DEST=$(jq -r '.destination' <<<"$plugin")

  # IMPORTANT: skip-check and clone use the SAME path. Earlier versions
  # checked $PLUGIN_DEST but cloned to public/$PLUGIN_DEST, which silently
  # skipped re-cloning whenever a stale legacy directory existed.
  TARGET="$DEST_FOLDER/public/$PLUGIN_DEST"

  if [ -d "$TARGET" ]; then
    echo "  -> Skipping $PLUGIN_NAME (already at public/$PLUGIN_DEST)"
    skipped=$((skipped + 1))
    continue
  fi

  echo "  -> Installing $PLUGIN_NAME @ $PLUGIN_VERSION -> public/$PLUGIN_DEST"
  mkdir -p "$(dirname "$TARGET")"

  if git clone --depth 1 --branch "$PLUGIN_VERSION" --recursive "$PLUGIN_REPO" "$TARGET"; then
    rm -rf "$TARGET/.git"
    installed=$((installed + 1))
  else
    echo "ERROR: failed to clone $PLUGIN_NAME from $PLUGIN_REPO @ $PLUGIN_VERSION" >&2
    failed+=("$PLUGIN_NAME")
    rm -rf "$TARGET"
  fi
done

# 3. Summary + post-condition ----------------------------------------------
echo "----------------------------------------"
echo "Plugin summary: installed=$installed skipped=$skipped failed=${#failed[@]}"
if [ "${#failed[@]}" -gt 0 ]; then
  printf '  failed: %s\n' "${failed[@]}" >&2
  exit 1
fi

# Sanity: every configured plugin must now be on disk at the expected path.
missing=()
for plugin in "${PLUGIN_LINES[@]}"; do
  PLUGIN_DEST=$(jq -r '.destination' <<<"$plugin")
  [ -d "$DEST_FOLDER/public/$PLUGIN_DEST" ] || missing+=("$PLUGIN_DEST")
done
if [ "${#missing[@]}" -gt 0 ]; then
  echo "ERROR: plugins missing after build:" >&2
  printf '  %s\n' "${missing[@]}" >&2
  exit 1
fi

echo "Build complete: $installed installed, $skipped skipped."
