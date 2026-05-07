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
