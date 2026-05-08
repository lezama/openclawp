#!/usr/bin/env bash
# Run wacli inside the wordpress container with the persistent store dir.
# wp-env's `cli` container is separate and doesn't share /usr/local/bin
# with the wordpress container where wacli was installed.
set -euo pipefail

WP_CONTAINER=$(docker ps --format '{{.Names}}' \
	| grep -E '^wp-env-.*-wordpress-1$' \
	| grep -v tests \
	| head -1)
if [ -z "$WP_CONTAINER" ]; then
	echo "✖ wordpress container is not running. Did you run \`npm start\`?" >&2
	exit 1
fi

# -it for QR display; -e to point wacli at the persistent store.
exec docker exec -it -e WACLI_STORE_DIR=/var/lib/wacli "$WP_CONTAINER" \
	/usr/local/bin/wacli "$@"
