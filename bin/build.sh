#!/usr/bin/env bash
# Produce a WordPress plugin zip suitable for upload.
#
# Usage: bash bin/build.sh <version>
#
# Inputs:
#   <version>  Plugin version, e.g. "0.1.0". Used in the output filename.
#
# Outputs (cwd):
#   openclawp-<version>.zip   Versioned zip
#   openclawp.zip             Slug-only copy (the "latest" artefact)
#
# Honors .distignore for what to exclude. Mirrors svn-style WP.org packaging.

set -euo pipefail

if [ "$#" -lt 1 ]; then
	echo "usage: $0 <version>" >&2
	exit 1
fi

VERSION="$1"
PLUGIN_SLUG="openclawp"
REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAGING_DIR="$(mktemp -d)"
trap 'rm -rf "$STAGING_DIR"' EXIT

DEST="$STAGING_DIR/$PLUGIN_SLUG"
mkdir -p "$DEST"

# rsync respects .distignore. --exclude-from must point at a file with one
# pattern per line, which is exactly the .distignore format.
rsync -a \
	--exclude-from="$REPO_ROOT/.distignore" \
	--exclude=".git" \
	--exclude="bin/" \
	--exclude="vendor/bin/" \
	"$REPO_ROOT/" "$DEST/"

# Sanity check: main plugin file must be present.
if [ ! -f "$DEST/openclawp.php" ]; then
	echo "::error::openclawp.php missing in build output" >&2
	exit 1
fi

OUTPUT_VERSIONED="$REPO_ROOT/${PLUGIN_SLUG}-${VERSION}.zip"
OUTPUT_LATEST="$REPO_ROOT/${PLUGIN_SLUG}.zip"

rm -f "$OUTPUT_VERSIONED" "$OUTPUT_LATEST"

(cd "$STAGING_DIR" && zip -rq "$OUTPUT_VERSIONED" "$PLUGIN_SLUG")
cp "$OUTPUT_VERSIONED" "$OUTPUT_LATEST"

echo "ok: built $OUTPUT_VERSIONED ($(du -h "$OUTPUT_VERSIONED" | cut -f1))"
echo "ok: built $OUTPUT_LATEST"
