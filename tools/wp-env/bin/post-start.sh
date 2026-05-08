#!/usr/bin/env bash
# After wp-env start: install wacli inside the WordPress container,
# persist its data dir across restarts, and configure openclaWP defaults.
#
# Run automatically by `wp-env start` via lifecycleScripts.afterStart.

set -euo pipefail

# wacli release pinned. Bump as needed.
WACLI_TAG="v0.8.1"

# wp-env mangles the working dir's basename into the container name.
# Discover the wordpress container by matching the suffix wp-env appends.
WP_CONTAINER=$(docker ps --format '{{.Names}}' \
	| grep -E '^wp-env-.*-wordpress-1$' \
	| grep -v tests \
	| head -1)
if [ -z "$WP_CONTAINER" ]; then
	echo "✖ Could not find a running wp-env wordpress container." >&2
	exit 1
fi

ARCH=$(docker exec "$WP_CONTAINER" uname -m)
case "$ARCH" in
	aarch64|arm64) TARBALL="wacli-linux-arm64.tar.gz" ;;
	x86_64|amd64)  TARBALL="wacli-linux-amd64.tar.gz" ;;
	*) echo "Unsupported container arch: '$ARCH'" >&2; exit 1 ;;
esac

URL="https://github.com/openclaw/wacli/releases/download/${WACLI_TAG}/${TARBALL}"

echo "→ Installing wacli ${WACLI_TAG} (${ARCH}) inside ${WP_CONTAINER}…"

docker exec -u root "$WP_CONTAINER" bash -c "
	set -e
	if [ -x /usr/local/bin/wacli ] && /usr/local/bin/wacli version 2>/dev/null | grep -q '${WACLI_TAG#v}'; then
		echo '   wacli ${WACLI_TAG} already installed.'
		exit 0
	fi
	apt-get update -y >/dev/null 2>&1 || true
	apt-get install -y --no-install-recommends curl ca-certificates >/dev/null 2>&1 || true
	curl -fsSL '${URL}' -o /tmp/wacli.tar.gz
	tar -xzf /tmp/wacli.tar.gz -C /tmp
	mv /tmp/wacli /usr/local/bin/wacli
	chmod +x /usr/local/bin/wacli
	rm -f /tmp/wacli.tar.gz
	mkdir -p /var/lib/wacli
	echo '   wacli installed at /usr/local/bin/wacli'
"

# wp-env's PHP-FPM runs as the host user (uid $UID, gid 20 on macOS) so it
# can write into bind-mounted plugin sources. wacli needs to own its store
# dir to chmod it to 0700 — chown after creation, not before.
HOST_UID=$(id -u)
HOST_GID=$(id -g)
docker exec -u root "$WP_CONTAINER" bash -c "
	chown -R ${HOST_UID}:${HOST_GID} /var/lib/wacli
	chmod 700 /var/lib/wacli
"
echo "   wacli store dir owned by ${HOST_UID}:${HOST_GID} at /var/lib/wacli"

# wp-env clones WordPress/ai from GitHub but doesn't run its build. Without
# this step the plugin shows "plugin assets are not built" everywhere.
AI_PLUGIN_DIR=$(echo "$HOME/.wp-env/wp-env-"*"/ai" 2>/dev/null | awk '{print $NF}')
if [ -d "$AI_PLUGIN_DIR" ] && [ ! -f "$AI_PLUGIN_DIR/build/build.php" ]; then
	echo "→ Building WordPress/ai plugin assets (one-time)…"
	(
		cd "$AI_PLUGIN_DIR"
		# Use whatever node 22+ is on PATH; nvm if available, else system node.
		if [ -s "$HOME/.nvm/nvm.sh" ]; then
			# shellcheck disable=SC1090,SC1091
			. "$HOME/.nvm/nvm.sh" && nvm use 22 >/dev/null 2>&1 || true
		fi
		npm ci --no-audit --no-fund >/dev/null 2>&1
		npm run build >/dev/null 2>&1
	)
	echo "   built."
fi

echo "→ Configuring openclaWP defaults…"
npx wp-env run cli wp option update openclawp_wacli_binary /usr/local/bin/wacli --quiet
npx wp-env run cli wp option update openclawp_wacli_agent openclawp-example --quiet

# Drop a mu-plugin that activates the bundled example agent and points
# wacli at a writable per-container store on every request.
docker exec -u root "$WP_CONTAINER" bash -c "
	mkdir -p /var/www/html/wp-content/mu-plugins
	cat > /var/www/html/wp-content/mu-plugins/openclawp-test-defaults.php <<'PHP'
<?php
/**
 * Test-env defaults for openclaWP. Activates the example agent and pins
 * the wacli store dir to a path PHP-FPM can chmod inside the container.
 */
defined( 'ABSPATH' ) || exit;

add_filter( 'openclawp_register_example_agent', '__return_true' );

if ( ! getenv( 'WACLI_STORE_DIR' ) ) {
	putenv( 'WACLI_STORE_DIR=/var/lib/wacli' );
	\$_SERVER['WACLI_STORE_DIR'] = '/var/lib/wacli';
}
PHP
	chown ${HOST_UID}:${HOST_GID} /var/www/html/wp-content/mu-plugins/openclawp-test-defaults.php
"

cat <<EOF

✓ wp-env ready.

  Site URL:    http://localhost:8888
  Admin:       http://localhost:8888/wp-admin
  User:        admin
  Password:    password

  Channels:    http://localhost:8888/wp-admin/admin.php?page=openclawp-channels

Next steps:
  1) Add an AI provider key (Anthropic shown):
     npx wp-env run cli wp option update connectors_ai_anthropic_api_key "\$ANTHROPIC_API_KEY"
  2) Pair WhatsApp from wp-admin → openclaWP → Channels → WhatsApp → Connect
  3) (Optional) Allow self-messages for solo testing:
     npx wp-env run cli wp option update openclawp_wacli_allow_self_messages 1
EOF
