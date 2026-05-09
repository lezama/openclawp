#!/usr/bin/env bash
# Tail WhatsApp messages for the paired account and forward each to the
# openclaWP webhook. Normally started automatically when you click
# "Connect WhatsApp" in wp-admin (the plugin transitions from `auth` to
# `sync` itself); this helper exists for manual debugging.
set -euo pipefail

SECRET=$(npx wp-env run cli wp option get openclawp_wacli_secret 2>&1 \
	| grep -v '^ℹ\|^✔\|^$' | tail -1 | tr -d '\r')

if [ -z "$SECRET" ]; then
	echo "✖ openclawp_wacli_secret is not set. Click \`Connect WhatsApp\` in wp-admin once first." >&2
	exit 1
fi

WP_CONTAINER=$(docker ps --format '{{.Names}}' \
	| grep -E '^wp-env-.*-wordpress-1$' \
	| grep -v tests \
	| head -1)

# Apache inside the container listens on port 80, so wacli reaches the
# webhook via http://localhost/... not the host-side http://localhost:8888.
# Pass the secret via WACLI_WEBHOOK_SECRET env var instead of --webhook-secret
# so it never appears in /proc/<pid>/cmdline.
exec docker exec \
	-e WACLI_STORE_DIR=/var/lib/wacli \
	-e WACLI_WEBHOOK_SECRET="$SECRET" \
	"$WP_CONTAINER" \
	/usr/local/bin/wacli sync --follow \
		--webhook http://localhost/wp-json/openclawp/v1/wacli/webhook
