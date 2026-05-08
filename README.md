# openclaWP

A generic chat-with-an-agent WordPress plugin built on [`Automattic/agents-api`](https://github.com/Automattic/agents-api). Other plugins register agents; openclaWP gives the site a place for a user to talk to one.

**What's in the box**

- A `openclawp/chat` block — the chat UI itself, embeddable anywhere a block can render.
- An `openclawp/chat` ability so MCP servers, Studio Code skills, WP-CLI, and other agents can drive a conversation without going through HTTP.
- `POST /openclawp/v1/chat` REST endpoint for browser-driven UIs.
- A CPT (`openclawp_session`) that persists each transcript and exposes it via the standard WP REST API at `/wp/v2/openclawp-sessions`.
- A `WP_Agent_Conversation_Lock` implementation backed by atomic post_meta CAS — no new tables.

## Requirements

- WordPress 7.0+ (provides `wp_ai_client_prompt()` and the abilities API)
- PHP 8.1+
- [`Automattic/agents-api`](https://github.com/Automattic/agents-api) installed and active
- A WP AI Client connector (Anthropic, Google, OpenAI, or [Ollama for local LLMs](docs/local-ollama.md))

## Install

```bash
composer require lezama/openclawp:dev-main
wp plugin activate agents-api openclawp
```

Then register an agent from any plugin or mu-plugin:

```php
add_action( 'wp_agents_api_init', function () {
    wp_register_agent( 'my-agent', array(
        'label'       => __( 'My Agent', 'my-plugin' ),
        'description' => 'A short system prompt and persona description.',
    ) );
} );
```

> Agent registration is **not** an openclaWP API. It's an `agents-api` API. Hooking the substrate's own `wp_agents_api_init` keeps your plugin compatible with any agents-api consumer (openclaWP, Data Machine, future others), not just this one.

## Surfaces

The chat path is exposed four ways. Same shared implementation; pick whichever fits the caller.

**Block** — drop `<!-- wp:openclawp/chat /-->` in any post / template / shortcode / `do_blocks()` call. The wp-admin **openclaWP → Chat** page renders this same block.

**Ability** (preferred for non-browser clients):

```php
$result = wp_get_ability( 'openclawp/chat' )->execute( array(
    'agent'      => 'my-agent',
    'message'    => 'Hello.',
    'session_id' => null, // or a previously returned UUID for multi-turn
) );
// $result === [ 'session_id' => '…', 'reply' => '…', 'completed' => true ]
```

**REST** (for browser-driven UIs):

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/openclawp/v1/chat`              | Send a message; body: `{ agent, message, session_id? }` |
| `GET`  | `/openclawp/v1/chat/{session_id}` | Fetch the full transcript |

Both routes require `manage_options` by default; gate via `openclawp_rest_permission_callback`.

**WhatsApp** (inbound + outbound via Meta's Cloud API). Off by default. Opt in with `add_filter( 'openclawp_register_whatsapp', '__return_true' )`, configure credentials at **openclaWP → WhatsApp**, point Meta's webhook at `/openclawp/v1/whatsapp/webhook`. Inbound text is signature-verified (`X-Hub-Signature-256` HMAC against your App Secret), dispatched to the configured agent, and the reply is posted back via the Graph API. Sessions persist per phone number — your conversation history follows you across days. See [`docs/whatsapp-setup.md`](docs/whatsapp-setup.md) for the full Meta-side runbook.

## Filters reference

| Filter | What it changes |
|---|---|
| `openclawp_register_example_agent`    | Register the bundled `openclawp-example` agent. Default `false`. |
| `openclawp_conversation_store`        | Swap the default CPT-backed store for another `WP_Agent_Conversation_Store` impl. |
| `openclawp_turn_runner_factory`       | Replace the wp-ai-client turn runner with a custom one (e.g. Gemini-OAuth). |
| `openclawp_rest_permission_callback`  | Override the default `manage_options` REST gate. |
| `openclawp_chat_ability_permission`   | Override the default `manage_options` gate on `openclawp/chat`. |
| `openclawp_register_whatsapp`         | Register the WhatsApp Cloud API ingress (webhook + outbound + settings page). Default `false`. |

The `openclawp_chat_turn_completed` action fires after every chat turn with provider, model, token usage, and wall duration — see [docs/instrumentation.md](docs/instrumentation.md) (TBD) or grep `error_log` for `[openclawp] chat_turn=…`.

## Documentation

- [`docs/local-ollama.md`](docs/local-ollama.md) — agent runbook for routing chat to a local Gemma via Ollama (works fully offline)
- [`docs/provider-precedence.md`](docs/provider-precedence.md) — recorded design for per-agent / per-site provider routing (not yet implemented)

## Testing

```bash
composer install
vendor/bin/phpunit                                     # pure-PHP unit tests
studio wp eval 'require "/path/to/tests/smoke.php";'   # integration smoke
```

Smoke covers: plugin loaded, agent registry, both abilities registered, CPT REST exposure, full lock primitive contract.

## Upstream contributions

Building this plugin surfaced and resolved two contract gaps in `Automattic/agents-api`. Both have landed:

- [#95](https://github.com/Automattic/agents-api/issues/95) → [PR #98](https://github.com/Automattic/agents-api/pull/98) (merged) — the conversation store contract takes `int $agent_id` while agents are slug-keyed via `wp_register_agent`. PR added the `WP_Agent_Conversation_Store::META_KEY_AGENT_SLUG` convention constant + clarified docblocks. Non-breaking; openclaWP and Data Machine can converge on the same key.
- [#96](https://github.com/Automattic/agents-api/issues/96) → [PR #97](https://github.com/Automattic/agents-api/pull/97) (merged) — `WP_Agent_Conversation_Loop::run()` short-circuited to one turn when tool mediation was enabled but `should_continue` was unset. PR defaults `should_continue` to a continue-always closure when `tool_executor` + `tool_declarations` are both provided. Mediation users get the loop they expected without boilerplate.

The originally-imagined [#78](https://github.com/Automattic/agents-api/issues/78) "default-stores companion" is **not** materializing in the canonical org per [maintainer direction](https://github.com/Automattic/agents-api/issues/78#issuecomment-4403225762): canonical's boundary stays strictly at contracts / value objects / registries / dispatcher-loop primitives, and concrete stores remain consumer-owned adapters. openclaWP's CPT-backed `WP_Agent_Conversation_Store` + `WP_Agent_Conversation_Lock` and `OpenclaWP_Message_Adapter` (the wp-ai-client transcript bridge) live here as **reference implementations** for any consumer that wants a "no new tables" starting point — copy what fits.

The pattern that *did* land (and we'll keep contributing to) is sharpening canonical's contracts when consumer integration finds a rough edge — see #95/#96 as the template.

## Source provenance

This plugin lifts load-bearing primitives from [`Extra-Chill/data-machine`](https://github.com/Extra-Chill/data-machine) (Chris Huber's flagship agents-api consumer): composer dep + bootstrap guard, `wp_agents_api_init` registration, lock-aware conversation store, wp-abilities-API for tools, `wp_ai_client_prompt()` turn runner. openclaWP trades DM's pipelines / flows / jobs surface for the smallest possible chat plugin.

## License

GPL-2.0-or-later.
