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

# 4. (Optional) For solo testing from your own paired number
npx wp-env run cli wp option update openclawp_wacli_allow_self_messages 1
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

Chat routes default to `manage_options`; gate via `openclawp_rest_permission_callback`. The wacli webhook is gated by HMAC signature only.

---

## WhatsApp transport reference (Path B)

Settings live at **wp-admin → openclaWP → Channels → WhatsApp**:

| Option | Purpose | Default |
|---|---|---|
| `openclawp_wacli_agent`               | Slug of the agent that receives messages. Required. | `''` |
| `openclawp_wacli_secret`              | HMAC-SHA256 secret. Auto-generated on first Connect. | auto |
| `openclawp_wacli_binary`              | Path to the `wacli` executable. | resolved from PATH / Homebrew |
| `openclawp_wacli_allowed_jids`        | Comma-separated JID allowlist. Empty = allow every chat. | `''` |
| `openclawp_wacli_allow_self_messages` | Let the linked account's own messages reach the agent. Solo testing only — outbound `msg_id` dedupe still prevents echo loops. | `0` |

**How it works.** `wacli sync --follow --webhook ...` POSTs each inbound message to `/openclawp/v1/wacli/webhook`, signed with HMAC-SHA256. The transport normalizes wacli's PascalCase payload, runs it through `agents/chat`, and shells out to `wacli send text` with the agent's reply. `OpenclaWP_Wacli_Channel` extends [`AgentsAPI\AI\Channels\WP_Agent_Channel`](https://github.com/Automattic/agents-api/blob/main/src/Channels/class-wp-agent-channel.php) so new transports (Telegram, Email, Slack…) implement the same base class identically.

**Loop prevention.** Each outbound `msg_id` is parked in a 5-min transient; inbound webhook events whose `msg_id` matches are silent-skipped. The legacy `from_me` skip is now gated on `openclawp_wacli_allow_self_messages`, so solo testing works without losing the loop guard.

---

## Filters reference

| Filter | What it changes |
|---|---|
| `openclawp_register_example_agent`        | Register the bundled `openclawp-example` agent. Default `false`. |
| `openclawp_conversation_store`            | Swap the default CPT-backed store for another `WP_Agent_Conversation_Store` impl. |
| `openclawp_turn_runner_factory`           | Replace the wp-ai-client turn runner with a custom one. |
| `openclawp_rest_permission_callback`      | Override the default `manage_options` REST gate. |
| `openclawp_chat_ability_permission`       | Override the default `manage_options` gate on `openclawp/chat`. |
| `openclawp_wacli_skip_self_messages`      | Per-request override of the from_me skip. Default reads `openclawp_wacli_allow_self_messages`. |
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
