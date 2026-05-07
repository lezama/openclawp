# openclaWP

Generic chat-with-an-agent WordPress plugin built on [`Automattic/agents-api`](https://github.com/Automattic/agents-api). Other plugins register agents; openclaWP gives the site a place for a user to talk to one — as a Gutenberg block, a REST endpoint, an `openclawp/chat` ability, or via WhatsApp.

> **For coding agents (Claude, Codex, …).** This README is structured so you can pick the right install path from the table below and run the corresponding shell block unattended. Each path ends with the plugin active and a chat-capable surface available.

## Pick a path

| You want… | Use | Setup |
|---|---|---|
| Chat in wp-admin (block / REST / ability), no WhatsApp | **Path A — Studio** | ~2 min |
| Chat **and** WhatsApp messages reaching the agent | **Path B — wp-env (Docker)** | ~5 min |
| Already-running WordPress (Pressable / VPS / Local / your own Docker) | **Path C — composer require** | depends |

WhatsApp pairing requires running the [`wacli`](https://github.com/openclaw/wacli) binary from PHP (`proc_open`). Studio's PHP-WASM sandbox can't execute host binaries, so anything touching wacli needs a real Linux PHP — wp-env is the easiest. The other surfaces (block / REST / ability) work everywhere.

---

## Path A — Studio (chat-only, no WhatsApp)

Requires the [Studio Mac app](https://developer.wordpress.com/studio/) and its CLI (`studio` on PATH).

```bash
# 1. Create a fresh local site
studio site create \
	--name openclawp-demo \
	--wp latest --php 8.4 \
	--skip-browser

SITE_PATH="$HOME/Studio/openclawp-demo"

# 2. Install the plugin and its substrate
mkdir -p "$SITE_PATH/wp-content/plugins"
cd "$SITE_PATH/wp-content/plugins"
git clone https://github.com/Automattic/agents-api.git
git clone https://github.com/lezama/openclawp.git
( cd openclawp && composer install --no-dev )

# 3. Activate
studio --path "$SITE_PATH" wp plugin activate agents-api openclawp

# 4. Add an AI provider key (Anthropic example — swap for OpenAI / Gemini)
studio --path "$SITE_PATH" wp option update \
	connectors_ai_anthropic_api_key "$ANTHROPIC_API_KEY"
```

Then drop a `<!-- wp:openclawp/chat /-->` block in any post or template, or visit **wp-admin → openclaWP → Chat**.

---

## Path B — wp-env (with WhatsApp via wacli)

Ships in this repo at [`tools/wp-env/`](tools/wp-env). One bootstrap brings up WP 7.0-RC2 + PHP 8.4 + `agents-api` + `openclawp` + the `WordPress/ai` stack and installs `wacli` inside the container.

```bash
# 1. Clone openclawp + its substrate side-by-side
git clone https://github.com/Automattic/agents-api.git
git clone https://github.com/lezama/openclawp.git

# 2. Boot the wp-env stack
cd openclawp/tools/wp-env
npm install
npm start
# (first run pulls Docker images, builds WordPress/ai, installs wacli — ~5 min)

# 3. Add an AI provider key
npx wp-env run cli wp option update \
	connectors_ai_anthropic_api_key "$ANTHROPIC_API_KEY"

# 4. (Optional) Test mode: respond ONLY to messages from your own paired
#    number, ignore everyone else. Set this if you're pairing your personal
#    account and don't want the agent answering family or coworkers.
npx wp-env run cli wp option update openclawp_wacli_self_message_mode only
```

Then:

1. Open `http://localhost:8888/wp-admin/admin.php?page=openclawp-channels&channel=wacli` (admin / `password`).
2. Click **Connect WhatsApp** and scan the QR with WhatsApp → *Settings → Linked Devices*.
3. Send a WhatsApp text and the agent replies via Anthropic.

Tear down with `npm stop` (keeps volumes) or `npm run destroy` (wipes pairing). Full troubleshooting in [`tools/wp-env/README.md`](tools/wp-env/README.md).

**Why a separate Linux container.** Studio runs PHP under Emscripten/WASM, which can't `proc_open` host binaries. wp-env spins up a real `wordpress:*` Docker image, so wacli's `whatsmeow` connection and `wacli send text` shell-out both work normally.

---

## Path C — your own WordPress

If you already have a real-Linux WP install (Pressable, VPS, [Local](https://localwp.com/), etc.):

```bash
# Inside any WP plugins/ directory:
git clone https://github.com/Automattic/agents-api.git
git clone https://github.com/lezama/openclawp.git
( cd openclawp && composer install --no-dev )
wp plugin activate agents-api openclawp
wp option update connectors_ai_anthropic_api_key "$ANTHROPIC_API_KEY"
```

For WhatsApp, install wacli on the same machine that runs PHP (`brew install steipete/tap/wacli` on macOS, or download from [wacli releases](https://github.com/openclaw/wacli/releases) on Linux), then pair from **wp-admin → openclaWP → Channels → WhatsApp → Connect WhatsApp**.

---

## Register an agent

Agent registration is **not** an openclaWP API — it's an `agents-api` API. Any plugin or mu-plugin can register one:

```php
add_action( 'wp_agents_api_init', function () {
    wp_register_agent( 'my-agent', array(
        'label'          => __( 'My Agent', 'my-plugin' ),
        'description'    => 'You are a helpful assistant. Be concise.',
        'default_config' => array(
            'provider' => 'auto',
            'model'    => 'claude-haiku-4-5',
        ),
    ) );
} );
```

Hooking the substrate's own `wp_agents_api_init` keeps the plugin compatible with any agents-api consumer (openclaWP, Data Machine, …), not just this one. For a working tool-using example, set `add_filter( 'openclawp_register_example_agent', '__return_true' )` and the bundled `openclawp-example` agent registers itself — it can read recent posts, count comments, and list active plugins.

---

## Surfaces

The chat path is exposed three ways. Same shared implementation; pick whichever fits the caller.

- **Block** — drop `<!-- wp:openclawp/chat /-->` anywhere a block can render. The wp-admin **openclaWP → Chat** page renders this same block.
- **Ability** (preferred for non-browser callers) — `wp_get_ability( 'openclawp/chat' )->execute( [ 'agent' => '…', 'message' => '…', 'session_id' => null ] )` returns `[ 'session_id', 'reply', 'completed' ]`.
- **REST**:

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/openclawp/v1/chat`              | Send a message; body: `{ agent, message, session_id? }` |
| `GET`  | `/openclawp/v1/chat/{session_id}` | Fetch the full transcript |
| `POST` | `/openclawp/v1/wacli/webhook`     | HMAC-gated inbound from `wacli sync` (Path B) |
| `GET`  | `/openclawp/v1/whatsapp/webhook`  | Meta verification challenge (WhatsApp Cloud API alternative to wacli) |
| `POST` | `/openclawp/v1/whatsapp/webhook`  | HMAC-gated inbound from Meta's Graph API (WhatsApp Cloud API alternative to wacli) |

Chat routes default to `manage_options`; gate via `openclawp_rest_permission_callback`. Both webhook endpoints are gated by HMAC signature only.

---

## WhatsApp transport reference (Path B)

Settings live at **wp-admin → openclaWP → Channels → WhatsApp**:

| Option | Purpose | Default |
|---|---|---|
| `openclawp_wacli_agent`               | Slug of the agent that receives messages. Required. | `''` |
| `openclawp_wacli_secret`              | HMAC-SHA256 secret. Auto-generated on first Connect. | auto |
| `openclawp_wacli_binary`              | Path to the `wacli` executable. | resolved from PATH / Homebrew |
| `openclawp_wacli_allowed_jids`        | Comma-separated JID allowlist. Empty = allow every chat. | `''` |
| `openclawp_wacli_self_message_mode`   | One of `block` (default, prod-safe), `allow` (process every message including your own), `only` (test mode — respond only to your own messages, drop others). Also surfaced as a dropdown in *openclaWP → Channels → WhatsApp → Settings*. | `block` |

### Self-message modes

The bot is paired as a *linked device* on a real WhatsApp account, so deciding whose messages should reach the agent matters:

| Mode | Linked-account messages | Other contacts | When to use |
|---|---|---|---|
| `block` (default) | silent-skipped | reach the agent | Production / shared bot account |
| `allow`           | reach the agent | reach the agent | Dedicated bot account, no humans on it |
| `only`            | reach the agent | silent-skipped | **Solo testing** — pair your own number to demo without the agent replying to your family / coworkers |

The legacy boolean `openclawp_wacli_allow_self_messages` (shipped briefly between #9 and the test-mode rollout) maps to `allow` mode; the enum option is authoritative when both are set.

**How it works.** `wacli sync --follow --webhook ...` POSTs each inbound message to `/openclawp/v1/wacli/webhook`, signed with HMAC-SHA256. The transport normalizes wacli's PascalCase payload, runs it through `agents/chat`, and shells out to `wacli send text` with the agent's reply. `OpenclaWP_Wacli_Channel` extends [`AgentsAPI\AI\Channels\WP_Agent_Channel`](https://github.com/Automattic/agents-api/blob/main/src/Channels/class-wp-agent-channel.php) so new transports (Telegram, Email, Slack…) implement the same base class identically.

**Loop prevention.** Each outbound `msg_id` is parked in a 5-min transient; inbound webhook events whose `msg_id` matches are silent-skipped. This guard runs in every mode (including `allow` / `only`) so the agent never reacts to its own outgoing replies.

---

## WhatsApp Cloud API (alternative to wacli)

A second WhatsApp transport using **Meta's official [Cloud API](https://developers.facebook.com/docs/whatsapp/cloud-api)** is bundled too. Pick this instead of wacli when you have (or want) a real WhatsApp Business account, a verified phone number ID, and a permanent access token — the standard path for production-grade business deployments.

Off by default. Opt in:

```php
add_filter( 'openclawp_register_whatsapp', '__return_true' );
```

Configure credentials at **wp-admin → openclaWP → WhatsApp** (Phone Number ID, App Secret, Permanent Access Token, Webhook Verify Token, default agent), then point Meta's webhook at `/openclawp/v1/whatsapp/webhook`. Inbound text is signature-verified (`X-Hub-Signature-256` HMAC against your App Secret), dispatched to the configured agent, and the reply is posted back via the Graph API. Sessions persist per phone number — your conversation history follows you across days.

See [`docs/whatsapp-setup.md`](docs/whatsapp-setup.md) for the full Meta-side runbook (Developer app, system user token, webhook configuration, ngrok / cloudflared tunneling for localhost).

## Filters reference

| Filter | What it changes |
|---|---|
| `openclawp_register_example_agent`        | Register the bundled `openclawp-example` agent. Default `false`. |
| `openclawp_register_whatsapp`             | Register the WhatsApp Cloud API ingress (webhook + outbound + settings page). Default `false`. |
| `openclawp_conversation_store`            | Swap the default CPT-backed store for another `WP_Agent_Conversation_Store` impl. |
| `openclawp_turn_runner_factory`           | Replace the wp-ai-client turn runner with a custom one. |
| `openclawp_rest_permission_callback`      | Override the default `manage_options` REST gate. |
| `openclawp_chat_ability_permission`       | Override the default `manage_options` gate on `openclawp/chat`. |
| `openclawp_wacli_skip_self_messages`      | Per-request override of the from_me skip. Default tracks `openclawp_wacli_self_message_mode` (true in `block`, false in `allow` / `only`). |
| `openclawp_wacli_binary_candidates`       | Add custom paths to wacli auto-discovery. |
| `openclawp_channels`                      | Register additional Channels in the wp-admin Channels list view. |

`openclawp_chat_turn_completed` fires after every chat turn with provider, model, token usage, and wall duration — grep `error_log` for `[openclawp] chat_turn=…`.

---

## Testing

```bash
composer install
vendor/bin/phpunit                                     # 26 unit tests
```

Plus a smoke that needs a running WP (any of the paths above):

```bash
wp eval-file tests/smoke.php       # or studio --path … wp eval-file
```

End-to-end (REST → ability → `proc_open`) is exercised manually via Path B since it depends on a paired WhatsApp account.

---

## Documentation

- [`tools/wp-env/README.md`](tools/wp-env/README.md) — Path B troubleshooting + advanced flags
- [`docs/local-ollama.md`](docs/local-ollama.md) — agent runbook for routing chat to a local Gemma via Ollama
- [`docs/provider-precedence.md`](docs/provider-precedence.md) — recorded design for per-agent / per-site provider routing

---

## Source provenance

Lifts load-bearing primitives from [Extra-Chill/data-machine](https://github.com/Extra-Chill/data-machine) (Chris Huber's flagship agents-api consumer): composer dep + bootstrap guard, `wp_agents_api_init` registration, lock-aware conversation store, wp-abilities-API for tools, `wp_ai_client_prompt()` turn runner. openclaWP trades DM's pipelines / flows / jobs surface for the smallest possible chat plugin.

## License

GPL-2.0-or-later.
