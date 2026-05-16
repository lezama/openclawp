# Social posts for openclaWP launch

Drafts you can paste in seconds. Each one stands alone — the live Playground link is the asset; everything else just gets people to click it. Replace `@your-handle` with whatever you use on each network.

## The link itself (the only thing you actually need)

```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/lezama/openclawp/main/.wordpress-org/blueprints/blueprint.json
```

That URL boots a real WordPress site in the browser, installs `Automattic/agents-api` + openclaWP, and lands you on a working chat. ~60 seconds. No install, no API key.

---

## Bluesky / Twitter / Mastodon — single post

> An AI agent that lives inside a WordPress site, click-to-try:
>
> 🎬 https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/lezama/openclawp/main/.wordpress-org/blueprints/blueprint.json
>
> Real WP abilities (recent posts, comment counts, plugin list, current user) called as tools. Per-agent MCP server endpoint so Claude Code can call it too. WP 7.0 AI Client + Automattic/agents-api under the hood.
>
> https://github.com/lezama/openclawp

*(297 chars not counting URLs — fits Bluesky's 300-char limit.)*

---

## Twitter / X — 4-tweet thread

**1/4** — hook
> Shipping openclaWP today: an AI agent that lives *inside* a WordPress site. Click → 60-second WordPress Playground boot → real chat against a real WP site → ask "what's my latest post?" and get the actual seeded post back, in your browser, no install, no API key. 👇

**2/4** — link
> 🔗 https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/lezama/openclawp/main/.wordpress-org/blueprints/blueprint.json

**3/4** — why it's different
> Built on Automattic/agents-api + the WP 7.0 AI Client / Connectors API. So:
> • BYO provider (Anthropic, OpenAI, Gemini, Ollama)
> • Every agent gets its own MCP server endpoint, scoped to *that agent's* tool list
> • CPT-backed workflows, per-turn cost dashboard, WhatsApp connector

**4/4** — repo
> Source, Studio + Ollama runbook, comparison vs sd-ai-agent / ClawWP / AI Engine / AI Services / WordPress/ai → https://github.com/lezama/openclawp

---

## LinkedIn — long-form

**Title:** *An agent that lives inside your WordPress site*

> WordPress 7.0 is about to ship with two new building blocks: a unified AI Client (with provider connectors as separate wp.org plugins) and an Abilities API (so any plugin can declare its capabilities as schema'd, permission-gated functions an LLM can call).
>
> [`Automattic/agents-api`](https://github.com/Automattic/agents-api) sits on top of those: agent registration, conversation loops, tool mediation, workflows, channels — the substrate.
>
> openclaWP is the thinnest consumer of that substrate: a developer-preview WordPress plugin that turns a site into a place to *use* registered agents. Chat block, REST chat surface, deterministic workflows, WhatsApp connector, per-turn cost dashboard, and — new — a per-agent MCP server endpoint so Claude Code / Cursor / VS Code can connect.
>
> Easiest way to see what that means: click the Playground link in the repo. ~60 seconds and you're logged into a real WordPress site with an agent answering questions about its own content.
>
> 🔗 https://github.com/lezama/openclawp
> 🎬 https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/lezama/openclawp/main/.wordpress-org/blueprints/blueprint.json
>
> #WordPress #AI #AIAgent #MCP #ModelContextProtocol #OpenSource

---

## Reddit r/Wordpress / r/WordPressPlugins — Show & Tell

**Title:** *[Show] openclaWP — WordPress plugin that exposes registered AI agents as per-agent MCP servers (Playground demo, no install)*

> Hi r/Wordpress 👋
>
> I've been working on **openclaWP** — a developer-preview WordPress plugin that gives registered AI agents (via [Automattic/agents-api](https://github.com/Automattic/agents-api)) a home inside WordPress: chat block, REST chat surface, deterministic workflows, and a per-agent MCP server endpoint at `/openclawp/v1/mcp/{slug}` for Claude Code / Cursor / VS Code.
>
> No clone needed to try it — the repo includes a WordPress Playground blueprint. Open the URL, wait ~60s, you're in a real WordPress install with an agent answering questions about its own content using real `openclawp/get-recent-posts` / `count-comments` / `get-active-plugins` abilities. The model in the demo is a canned-response runner so the demo works without an API key; swap in any [WP AI Provider connector](https://wordpress.org/plugins/ai-provider-for-openai/) afterwards and you're talking to a real model.
>
> Live demo: https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/lezama/openclawp/main/.wordpress-org/blueprints/blueprint.json
> Repo: https://github.com/lezama/openclawp
>
> Built on WordPress 7.0 (AI Client + Abilities API) + Automattic/agents-api. GPL-2.0-or-later, PHP 8.1+, WordPress 7.0+. Honest comparison vs other WordPress AI agent plugins (sd-ai-agent, ClawWP, AI Engine, AI Services, WordPress/ai) is in the README.
>
> Feedback welcome — what would you want a WordPress-native agent to do that openclaWP doesn't already?

---

## Hacker News — Show HN

**Title:** *Show HN: openclaWP – a WordPress plugin that turns each agent into its own MCP server*

> [Live Playground demo (no install)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/lezama/openclawp/main/.wordpress-org/blueprints/blueprint.json)
>
> openclaWP is a developer-preview WordPress plugin that exposes registered AI agents (substrate: [Automattic/agents-api](https://github.com/Automattic/agents-api)) through a Gutenberg chat block, a REST chat surface, deterministic workflows, and a per-agent MCP server endpoint at `/openclawp/v1/mcp/{slug}`. The MCP angle is what's different from the other WordPress AI plugins I'm aware of: instead of exposing every WordPress ability site-wide (the official [WP MCP Adapter](https://github.com/WordPress/mcp-adapter) approach), each agent gets its own endpoint with only the tools that agent is allowed to call.
>
> Under the hood: WordPress 7.0 AI Client + Abilities API + agents-api substrate. Bring Your Own Provider — Anthropic, OpenAI, Gemini, or local Ollama via the [Connectors API](https://wordpress.org/plugins/ai-provider-for-openai/). Per-turn cost dashboard, CPT-backed workflow run recorder, WhatsApp Cloud API channel.
>
> Repo (GPL-2.0-or-later, PHP 8.1+, WordPress 7.0+): https://github.com/lezama/openclawp
>
> The bundled demo runs entirely in the browser via WordPress Playground — no model installed, but the agent still calls real WordPress abilities (`get-recent-posts`, etc.) so you see live WP data quoted back. Comparison matrix vs the rest of the WordPress AI agent space (sd-ai-agent, ClawWP, AI Engine, AI Services, WordPress/ai) is in the README.
>
> Feedback / criticism welcome, especially on the MCP per-agent vs site-wide tradeoff.

---

## Slack / Discord / WordPress Make.WordPress.Org #core-ai — short ping

> Hey folks — shipped a developer-preview plugin this week that might be interesting if you're following the WP AI Building Blocks story.
>
> **openclaWP** is the thinnest possible consumer of `Automattic/agents-api` + the WP 7.0 AI Client + Abilities API. Chat block, REST chat, workflows, per-agent MCP server, WhatsApp channel.
>
> Easiest way to see it: https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/lezama/openclawp/main/.wordpress-org/blueprints/blueprint.json
> Source: https://github.com/lezama/openclawp
>
> Would love feedback — particularly on the per-agent (vs site-wide via WP MCP Adapter) MCP scoping.

---

## Where to post each one

- **Bluesky:** the single-post version (300-char limit)
- **Mastodon:** the single-post version with a CW about WordPress 7.0 RC if you're on a wordpress.org-style instance
- **Twitter/X:** the 4-tweet thread
- **LinkedIn:** the long-form
- **Reddit r/Wordpress** and **r/WordPressPlugins**: the Show & Tell (follow each sub's self-promo rules — usually fine if you're showing something you built and not just driving traffic)
- **Reddit r/MCP** and **r/LocalLLaMA**: the Hacker News version, adapted
- **Hacker News:** the Show HN
- **wordpress.slack.com #core-ai**: the short ping (do this *last*, after a few of the above so people clicking can see the demo is real)
- **Internal a8c P2** (e.g. a relevant agents-api / AI / developer-experience P2): the short ping
- **dev.to / hashnode / your blog:** the launch-post.md from `docs/launch-post.md` (longer than this thread)

## Timing

- Don't post all of these at once — stagger by ~3-6 hours so each platform's algorithm gets fresh engagement to feed on.
- Schedule for when the demo will actually boot reliably (avoid right when you're pushing to `main`; the blueprint URL points at `raw.githubusercontent.com` which can take a minute or two to refresh after a push).
- The Hacker News post is the one that can drive a 10x burst — only fire it once you've confirmed the Playground demo boots green for several anon test loads, and ideally on a Tuesday morning EST.

## Talking points if anyone replies

* **"Why not just use AI Engine / sd-ai-agent / etc.?"** → "Different shape. openclaWP is the thinnest possible consumer of the substrate; the others are batteries-included products. Useful at different points in the lifecycle."
* **"Is this on wp.org?"** → "Not yet. Submission gates on WP 7.0 final shipping (May 20) and a few rough edges getting filed off. GitHub developer preview for now."
* **"Does this work with Claude Max / Pro subscription instead of API?"** → "Yes — via the [AI Provider for Anthropic Max](https://github.com/Ultimate-Multisite/ai-provider-for-anthropic-max) connector. Same flow as Anthropic API; different auth path."
* **"How is this different from WordPress/mcp-adapter?"** → "MCP Adapter exposes every site Ability over MCP. openclaWP exposes one Agent's curated tool surface per endpoint, with separate bearer tokens. Different scope; complementary."
* **"WhatsApp without Meta credentials?"** → "Yes, via the separate [`openclaw/wacli`](https://github.com/openclaw/wacli) linked-device path that lives in a sibling repo because it's unofficial. The Cloud API path is in core."
