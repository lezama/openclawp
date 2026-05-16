# An agent that lives inside your WordPress site

*Launch post draft. Publish on Make.WordPress.org/ai, dev.to, your own blog, or a P2 — and link the live Playground demo so readers can click instead of clone.*

---

## TL;DR

I shipped **[openclaWP](https://github.com/lezama/openclawp)**, a WordPress plugin that lets registered AI agents live inside your site: they read your content, talk back through a Gutenberg chat block, run deterministic workflows, and — new this week — expose their tool surface as **per-agent MCP servers** that Claude Code / Cursor / VS Code can connect to.

The thing I want to point at first is the [**live Playground demo**](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/lezama/openclawp/main/.wordpress-org/blueprints/blueprint.json). No install, no API key, no Docker. Click, wait ~60 seconds, you're logged into a real WordPress site with an agent answering questions about its own content. The "model" is a canned-response runner — but the agent is calling **real WordPress abilities** (`openclawp/get-recent-posts`, `count-comments`, `get-active-plugins`, `get-current-user`) under the hood, so you see live WP data quoted back. Swap in any [WordPress AI Client](https://wordpress.org/plugins/ai-provider-for-openai/) connector after the demo and you're talking to a real model.

## Why another WordPress AI plugin?

The honest answer: because I want to live inside the WordPress AI Building Blocks story, not next to it. Three things shipped recently (or are landing now) that make a thin agent plugin meaningfully different from what came before:

1. **[`Automattic/agents-api`](https://github.com/Automattic/agents-api)** — the agent substrate. Registration, conversation loops, tool mediation, workflows, channels, memory model. Open-source, generic, single-purpose: be the plumbing.
2. **WordPress 7.0 AI Client + Connectors API** — Bring Your Own Provider via wp.org plugins. No proprietary proxy. Anthropic, OpenAI, Gemini, Ollama, local WebLLM all play.
3. **[WordPress Abilities API](https://github.com/WordPress/abilities-api)** — sites describe their capabilities as named, schema'd, permission-gated functions. Any plugin's `wp_register_ability()` is an LLM tool the moment an agent declares it.

openclaWP is the thinnest possible consumer of those three. The substrate owns the contract; openclaWP owns the materialization — CPT-backed sessions, CPT-backed workflow runs, CPT-backed usage telemetry, REST routes, admin pages, Gutenberg block, WhatsApp connector. The substrate is general; openclaWP is the reference assembly.

## What you can do with it today

* **Chat with a registered agent** from a Gutenberg block (`wp:openclawp/chat`), the wp-admin Chat page, or `POST /openclawp/v1/chat`. Sessions are CPT-backed (`openclawp_session`) so a refresh doesn't lose your thread.
* **Compose agents and abilities into workflows** — `${inputs.x}` / `${steps.y.output.z}` bindings, a deterministic step runner, a Run Now form, and a recent-runs list with per-step trace. Triggers can be on-demand, a WordPress action, or cron (via Action Scheduler).
* **Track token spend** — every chat turn lands in a per-turn usage row with provider, model, input/output tokens, and a USD cost estimate from a filterable pricing table. wp-admin → openclaWP → Usage shows totals, per-day, per-model breakdowns, and recent turns.
* **Connect from Claude Code / Cursor / VS Code** — each registered agent can have its own MCP server at `/openclawp/v1/mcp/{slug}`. `tools/list` returns only the tools that agent is configured to use; `tools/call` dispatches through the Abilities API. Bearer-token auth, hashed with `wp_hash_password`.
* **Reach the agent from outside WordPress** — the WhatsApp Cloud API channel is in core; Telegram, Slack, email, and others can subclass `WP_Agent_Channel` and register through the `openclawp_channels` filter.

## Where it fits in the landscape

This space is suddenly crowded. Here's the honest matrix (full version in [the README](https://github.com/lezama/openclawp#how-openclawp-compares)):

|  | openclaWP | [sd-ai-agent](https://github.com/Ultimate-Multisite/superdav-ai-agent) | [ClawWP](https://github.com/hifriendbot/clawwp) | [AI Engine](https://github.com/jordymeow/ai-engine) | [AI Services](https://github.com/felixarntz/ai-services) | [WordPress/ai](https://github.com/WordPress/ai) |
|---|---|---|---|---|---|---|
| Substrate | `agents-api` + WP 7.0 AI Client | WP 7.0 AI Client | self | self | self | WP 7.0 AI Client + Abilities API |
| Per-agent MCP server | scoped to one agent's tools | via WP MCP Adapter (site-wide) | — | site-wide | via WP MCP Adapter | — |
| Workflows | deterministic, CPT-backed | — | — | — | — | (on roadmap) |
| Playground demo | ✅ | ✅ | — | — | ✅ | ✅ |
| Concrete channels | WhatsApp Cloud API | — | Telegram / Slack / Discord | — | — | — |

If you're using the WordPress AI Building Blocks or planning to, openclaWP is the thinnest consumer that gives you a chat UI, workflows, and an MCP endpoint. If you want a closed, batteries-included experience, sd-ai-agent or AI Engine are heavier-but-faster-onramp alternatives.

## What's next

* **Submission to wp.org** is the next gate — the plugin is on GitHub as developer preview while WordPress 7.0 itself is in RC.
* **More channel implementations** — Telegram and Slack are the obvious next two. The base class is already in agents-api.
* **MCP resources + prompts** — v1 of the MCP server only exposes `tools/list` and `tools/call`. Memory layers as resources and substrate prompt templates as prompts are the natural follow-ups.
* **WP-CLI substrate commands** — `wp openclawp doctor` shipped this week for install-health diagnostics; deeper commands (run an agent, dispatch a workflow, audit usage) are coming.

## How to try it

```text
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/lezama/openclawp/main/.wordpress-org/blueprints/blueprint.json
```

Or if you want a real model:

```bash
# In any WordPress 7.0+ plugin dir:
git clone https://github.com/Automattic/agents-api.git
git clone https://github.com/lezama/openclawp.git
( cd openclawp && composer install --no-dev )
wp plugin activate agents-api openclawp
# Then drop in any wp.org AI provider plugin and add an API key.
```

Full Studio + local Ollama recipe in [the README](https://github.com/lezama/openclawp#path-a--studio--local-ollama-default). Ollama on `gemma4:e2b` gets you a working tool-using agent on `localhost` with no API key.

## Feedback?

GitHub issues / discussions on [`lezama/openclawp`](https://github.com/lezama/openclawp). The project is developer preview and will move fast for a while. The bits that should land into core or canonical agents-api will get proposed there — openclaWP is meant to stay small.

— *Miguel*
