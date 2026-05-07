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

The chat path is exposed three ways. Same shared implementation; pick whichever fits the caller.

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

## Filters reference

| Filter | What it changes |
|---|---|
| `openclawp_register_example_agent`    | Register the bundled `openclawp-example` agent. Default `false`. |
| `openclawp_conversation_store`        | Swap the default CPT-backed store for another `WP_Agent_Conversation_Store` impl. |
| `openclawp_turn_runner_factory`       | Replace the wp-ai-client turn runner with a custom one (e.g. Gemini-OAuth). |
| `openclawp_rest_permission_callback`  | Override the default `manage_options` REST gate. |
| `openclawp_chat_ability_permission`   | Override the default `manage_options` gate on `openclawp/chat`. |

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

Building this plugin surfaced two gaps in `Automattic/agents-api` that the team is tracking:

- [#78](https://github.com/Automattic/agents-api/issues/78) `agents-api-default-stores` companion plugin — openclaWP's CPT-backed `WP_Agent_Conversation_Store` + `WP_Agent_Conversation_Lock` implementation is offered as the seed.
- [#95](https://github.com/Automattic/agents-api/issues/95) `int $agent_id` parameter in the conversation store contract doesn't match the slug-keyed `wp_register_agent` model.

A wp-ai-client transcript adapter (the `OpenclaWP_Message_Adapter` in this plugin) is a strong candidate for a small `agents-api-wp-ai-client` companion package — every consumer of agents-api that uses `wp_ai_client_prompt()` re-implements the same role / DTO conversion today.

## Source provenance

This plugin lifts load-bearing primitives from [`Extra-Chill/data-machine`](https://github.com/Extra-Chill/data-machine) (Chris Huber's flagship agents-api consumer): composer dep + bootstrap guard, `wp_agents_api_init` registration, lock-aware conversation store, wp-abilities-API for tools, `wp_ai_client_prompt()` turn runner. openclaWP trades DM's pipelines / flows / jobs surface for the smallest possible chat plugin.

## License

GPL-2.0-or-later.
