# openclaWP

Generic chat-with-an-agent WordPress plugin built on [`Automattic/agents-api`](https://github.com/Automattic/agents-api).

openclaWP is the thinnest viable consumer of the canonical agents-api substrate: it registers a sample agent, exposes a REST chat endpoint, persists transcripts as a WordPress custom post type, and ships a minimal wp-admin chat page. Downstream plugins register their own agents on top.

## What this is, and what it isn't

It **is** a runtime chassis: agent registration, conversation loop integration, transcript persistence, lock primitive, REST surface, admin chat, sample tool ability.

It **isn't** an end-user product. There's no domain logic, no UI shell beyond the admin page, no provider keys, no front-end widget. It assumes [WP AI Client](https://make.wordpress.org/core/) (WP 7.0+) is already configured for your AI provider — openclaWP delegates the entire "talk to a model" step to `wp_ai_client_prompt()`.

## Requirements

- WordPress 7.0+ (provides `wp_ai_client_prompt()` and the abilities API)
- PHP 8.1+
- [`Automattic/agents-api`](https://github.com/Automattic/agents-api) installed and active

## Install

```bash
composer require lezama/openclawp:dev-main
wp plugin activate agents-api openclawp
```

The Composer install will pull `agents-api` from GitHub (it's not on Packagist yet — see this plugin's `composer.json` for the VCS repository entry).

## Route to a local LLM (Gemma via Ollama) — agent runbook

> The instructions in this section are written for an autonomous coding agent that has shell access to the WordPress install. Every step is a command with a verifiable result. If you are a human and prefer a UI walkthrough, use the [WP-AI Provider for Ollama](https://wordpress.org/plugins/ai-provider-for-ollama/) plugin's settings screen instead.

### Preconditions to check before doing anything

```bash
command -v ollama >/dev/null && echo "ok: ollama installed" || echo "missing: ollama"
curl -sf http://localhost:11434/api/tags >/dev/null && echo "ok: ollama daemon reachable" || echo "missing: ollama daemon (run `ollama serve` or start the app)"
wp plugin is-active openclawp && echo "ok: openclawp active" || echo "missing: openclawp"
wp eval 'echo defined("AGENTS_API_LOADED") ? "ok: agents-api loaded" : "missing: agents-api";'
```

If any line says `missing:`, resolve that before continuing — every later step depends on these.

### Steps

1. Pull a Gemma model. `gemma4:26b` is a 26B/4B-active MoE; faster than its size suggests, fits in ~17 GB of RAM/VRAM. If RAM is tight, use `gemma3:1b` (815 MB) or `gemma2:2b` (1.6 GB) instead — replace the tag below accordingly.

   ```bash
   ollama pull gemma4:26b
   ```

   Verify: `ollama list | grep -q '^gemma4:26b' && echo ok || echo missing`

2. Install the WP-AI Ollama provider plugin. The official `WordPress/ai-provider-for-ollama` repo is a placeholder; the production plugin lives at `Fueled/ai-provider-for-ollama`.

   ```bash
   cd "$(wp eval 'echo WP_PLUGIN_DIR;')"
   git clone https://github.com/Fueled/ai-provider-for-ollama.git
   cd ai-provider-for-ollama && composer install --no-dev
   wp plugin activate ai-provider-for-ollama
   ```

   Verify the provider is registered with the AI Client default registry:

   ```bash
   wp eval 'echo in_array("ollama", WordPress\AiClient\AiClient::defaultRegistry()->getRegisteredProviderIds(), true) ? "ok" : "missing";'
   ```

3. Configure the host + model. Default host `http://localhost:11434` is correct for a local Ollama; set the model to the tag pulled in step 1.

   ```bash
   wp option update ai_provider_for_ollama_settings --format=json '{"host":"http://localhost:11434","model":"gemma4:26b"}'
   ```

4. Make Ollama the *only* configured provider for this site. WP AI Client picks the first configured provider when no caller specifies one; deactivating other provider plugins removes ambiguity.

   ```bash
   for p in ai-provider-for-anthropic ai-provider-for-openai ai-provider-for-google; do
     wp plugin is-active "$p" 2>/dev/null && wp plugin deactivate "$p"
   done
   ```

   Verify the active provider count is exactly 1:

   ```bash
   wp eval '$ids = WordPress\AiClient\AiClient::defaultRegistry()->getRegisteredProviderIds(); echo count($ids) === 1 && $ids[0] === "ollama" ? "ok" : ("wrong: " . implode(",", $ids));'
   ```

### End-to-end verification

Confirm openclaWP routes a real chat to Gemma:

```bash
wp eval '
wp_set_current_user( 1 );
$req = new WP_REST_Request( "POST", "/openclawp/v1/chat" );
$req->set_body_params( array( "agent" => "openclawp-default", "message" => "Reply with exactly one word: ping" ) );
$res = rest_do_request( $req );
$d = $res->get_data();
$session = $d["session_id"] ?? "";
$reply = trim( strtolower( $d["reply"] ?? "" ) );
echo $session && $reply === "ping" ? "ok" : ( "fail: status=" . $res->get_status() . " reply=\"" . ( $d["reply"] ?? "" ) . "\"" );
'
```

Expected output: `ok`. First call after a model swap takes 10–30s while Ollama loads the model into memory; subsequent turns are interactive.

### Rollback

To restore a cloud provider, reactivate its plugin (its API key is preserved in `wp_options`):

```bash
wp plugin activate ai-provider-for-anthropic   # or ai-provider-for-openai / ai-provider-for-google
```

The AI Client immediately re-prefers it on the next call. To remove Ollama entirely:

```bash
wp plugin deactivate ai-provider-for-ollama
wp option delete ai_provider_for_ollama_settings
ollama rm gemma4:26b
```

### Failure signals

| Symptom | Likely cause | Fix |
|---|---|---|
| `cURL error 28: Operation too slow` from `wp_ai_client_prompt()` | Model first-load on a small machine exceeds the 30s curl timeout | Re-run; retry succeeds once the model is resident. For a permanent fix, pull a smaller model or extend the AI Client transporter timeout. |
| Reply is empty string | Provider returned an error — reply was masked | Re-issue the prompt directly: `wp eval '$r = wp_ai_client_prompt("ping")->generate_text_result(); echo is_wp_error($r) ? $r->get_error_message() : $r->toText();'` |
| `wrong: anthropic,ollama` from step 4 verify | Another provider plugin is still active | Re-run step 4's deactivate loop, or extend it to cover whatever provider plugin is active. |
| Site can't reach `localhost:8887` after step 4 | Coincidence — Studio site idle-stopped | `studio site start --path <site>` |

## Quickstart — register your own agent

```php
add_action( 'openclawp_register_agents', function () {
    wp_register_agent( 'my-agent', array(
        'label'       => __( 'My Agent', 'my-plugin' ),
        'description' => 'A short system prompt and persona description.',
        'meta'        => array(
            'source_plugin'  => 'my-plugin/my-plugin.php',
            'source_type'    => 'bundled-agent',
            'source_package' => 'my-org/my-plugin',
            'source_version' => '1.0.0',
        ),
    ) );
} );
```

Agents become immediately available in the admin chat picker and via `GET /wp-json/openclawp/v1/agents`.

## Filters reference

- `openclawp_register_agents` — fires inside `wp_agents_api_init`. Call `wp_register_agent()` from here.
- `openclawp_conversation_store` — replace the default CPT-backed store (e.g. with the upcoming `agents-api-default-stores` companion plugin or a custom-table store at scale).
- `openclawp_turn_runner_factory` — swap the provider call. Receives a default factory wrapping `wp_ai_client_prompt()`. Return a `callable( WP_Agent ): callable` factory.
- `openclawp_rest_permission_callback` — override the default `manage_options` gate on REST routes.

## REST routes

| Method | Path | Purpose |
|---|---|---|
| `GET`    | `/openclawp/v1/agents`               | List registered agents |
| `POST`   | `/openclawp/v1/chat`                 | Send a message; body: `{ agent, message, session_id? }` |
| `GET`    | `/openclawp/v1/chat/sessions`        | List the current user's recent sessions |
| `GET`    | `/openclawp/v1/chat/{session_id}`    | Fetch the full transcript for a session |
| `DELETE` | `/openclawp/v1/chat/{session_id}`    | Delete a session |

All routes require `manage_options` by default; gate via `openclawp_rest_permission_callback`.

## Storage shape

Each conversation is one `openclawp_session` post. `post_content` holds the messages array as JSON (per `WP_Agent_Conversation_Store::update_session()` which receives the complete transcript on each turn). Auxiliary fields (`workspace_type`, `workspace_id`, `agent_id`, `metadata`, `provider`, `model`, `provider_response_id`, `context`, `last_read_at`, `expires_at`) live in post_meta. The lock primitive uses an atomic `add_post_meta(..., $unique=true)` test-and-set on `_openclawp_lock`.

This is deliberate: no new tables, fully WordPress-canonical, and trivially swappable behind the `openclawp_conversation_store` filter when you outgrow it.

## Architecture (one paragraph)

`openclawp.php` boots agents-api if not already loaded, then hooks `plugins_loaded:20` to wire services. `OpenclaWP_Agent_Registrar` registers `openclawp-default` on `wp_agents_api_init`. `OpenclaWP_Conversation_Store` implements both `WP_Agent_Conversation_Store` and `WP_Agent_Conversation_Lock` against `wp_posts` + `wp_postmeta`. `OpenclaWP_Runner` wraps `\AgentsAPI\AI\WP_Agent_Conversation_Loop::run()`; the turn runner closure delegates to `wp_ai_client_prompt()`. `OpenclaWP_Rest` exposes `/openclawp/v1/`. `OpenclaWP_Admin` renders a vanilla-JS chat page that calls those routes.

## Source provenance

This plugin is the WordPress port of the patterns used by [`Automattic/data-machine`](https://github.com/Automattic/data-machine) — Chris Huber's flagship agents-api consumer. openclaWP keeps DM's load-bearing primitives (composer dep + bootstrap guard, `wp_agents_api_init` registration, lock-aware conversation store, wp-abilities-API for tools, `wp_ai_client_prompt()` turn runner) but trades DM's pipelines / flows / jobs / React surface for the smallest possible chat plugin.

## License

GPL-2.0-or-later.
