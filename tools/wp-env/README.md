# `tools/wp-env/` — local Docker stack for openclaWP

WP 7.0-RC2 + PHP 8.4 + `agents-api` + `openclawp` + `WordPress/ai` + `wacli`, end-to-end inside a real Linux container. Studio's PHP-WASM sandbox can't `proc_open` host binaries, so anything that touches `wacli` runs here.

## For coding agents (one-shot bootstrap)

The host needs Docker Desktop / OrbStack with ≥ 10 GB free in its disk image, plus Node 20+ on PATH. Then, side-by-side with this checkout, clone `Automattic/agents-api` (the `.wp-env.json` mounts it from `../../../agents-api`):

```bash
# Run from the parent dir of openclawp/
git clone https://github.com/Automattic/agents-api.git
cd openclawp/tools/wp-env
npm install
npm start
```

When `npm start` returns, the site is at `http://localhost:8888/wp-admin` (admin / `password`). The `afterStart` hook (`bin/post-start.sh`) installs `wacli`, builds `WordPress/ai`'s assets, sets sane defaults, and prints the next steps.

## Configure an AI provider

```bash
npx wp-env run cli wp option update \
	connectors_ai_anthropic_api_key "$ANTHROPIC_API_KEY"
```

Or visit *Settings → Connectors → Anthropic → Set up*.

## Pair WhatsApp

1. Open `http://localhost:8888/wp-admin/admin.php?page=openclawp-channels&channel=wacli`
2. Click **Connect WhatsApp**
3. Scan the QR with WhatsApp → *Settings → Linked Devices*

The plugin auto-transitions from `wacli auth` to `wacli sync --webhook ...` on success — there's no second daemon to start.

## Test from your own number (solo testing)

By default, messages from the linked account itself are silent-skipped to prevent reply loops. To exercise the agent from your own paired phone:

```bash
npx wp-env run cli wp option update openclawp_wacli_allow_self_messages 1
```

The outbound `msg_id` dedupe still prevents echo loops while this is on.

## Tear down

```bash
npm stop                 # stop containers, keep volumes (wacli pairing survives)
npm run destroy          # nuke containers AND volumes (re-pair on next start)
```

## What `bin/post-start.sh` does

| Step | Why |
|---|---|
| Installs the pinned `wacli` release (linux/amd64 or linux/arm64) into `/usr/local/bin/` of the wordpress container | wp-env's `cli` container is separate; wacli runs in `wordpress` where PHP-FPM can `proc_open` it |
| `chown $(id -u):$(id -g) /var/lib/wacli && chmod 700` | wp-env's PHP-FPM runs as the host user (uid 501 on macOS), not www-data; wacli wants 0700 on its store |
| Builds `WordPress/ai`'s JS assets (one-time) | wp-env clones from GitHub but doesn't run npm; without this you see "plugin assets are not built" everywhere |
| Drops `mu-plugins/openclawp-test-defaults.php` | Activates the bundled `openclawp-example` agent and pins `WACLI_STORE_DIR` to the persistent path |

## Troubleshooting

| Symptom | Fix |
|---|---|
| `no space left on device` during image pull | `docker builder prune -a -f && docker volume prune -f`, or raise the disk image size in Docker Desktop / OrbStack |
| `fatal: couldn't find remote ref trunk` during plugin clone | Upstream changed default branch — pin with `#branch` in `.wp-env.json` |
| `store is locked (another wacli is running?)` after a double-click | `docker exec -u root <wp-container> pkill -9 -f wacli; rm -f /var/lib/wacli/LOCK` then click Connect again |
| `chmod /var/lib/wacli: operation not permitted` | uid mismatch — re-run `npm start` so `bin/post-start.sh` re-applies the chown |
| `server returned error 401` on outbound | `wacli send` and `wacli sync` occasionally race for the WhatsApp server connection. Known wacli limitation; first reply usually succeeds. Retry by sending another message. |

## Files

| Path | What it is |
|---|---|
| `.wp-env.json` | wp-env config — WP 7.0-RC2, PHP 8.4, plugin mounts, `afterStart` hook |
| `package.json` | Helper npm scripts wrapping `wp-env run cli` |
| `bin/post-start.sh` | Lifecycle hook (above) |
| `bin/run-wacli.sh` | Run any `wacli` subcommand inside the wordpress container |
| `bin/run-wacli-sync.sh` | Standalone webhook-posting sync (debug only — wp-admin auto-spawns it) |

## Where the plugins are mounted

The `mappings` section binds your local checkouts into the container:

| Container path | Host path (relative to this dir) |
|---|---|
| `wp-content/plugins/openclawp` | `../..` (this repo's root) |
| `wp-content/plugins/agents-api` | `../../../agents-api` (sibling of `openclawp/`) |

Edits in either checkout are reflected immediately — no rebuild required for PHP changes.
