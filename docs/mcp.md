# MCP servers in openclaWP

openclaWP exposes each registered agent's tool surface as an MCP server so external clients (Claude Desktop, Cursor, VS Code MCP, WordPress.com MCP, Pressable MCP, …) can drive the agent's abilities.

## Current path — official `mcp-adapter` (WP 7.0+)

WordPress 7.0 ships the official [`mcp-adapter`](https://github.com/WordPress/mcp-adapter) package, which handles the MCP wire protocol (JSON-RPC over HTTP, the 2025-06-18 schema, tool catalog format, transport upgrades) directly against the WP Abilities API.

openclaWP registers one adapter server per `openclawp_mcp_server` CPT row:

- **Route:** `/openclawp/v1/mcp-adapter/{slug}`
- **Auth:** `Authorization: Bearer <token>` (same per-server tokens as before; admins in their own wp-admin session bypass)
- **Tool catalog:** the agent's declared `default_config['tools']` (each one a registered ability) plus a synthetic `openclawp-mcp/delegate--{server}--{subagent}` ability per declared subagent.

Per-agent scoping: an MCP server post resolves to exactly one agent. The adapter is told to expose only that agent's abilities. Two MCP servers exposing different agents on the same site never share tools.

### Abilities exposed by openclaWP itself

Out of the box openclaWP registers these abilities via `wp_register_ability()`:

| Ability | Purpose |
| --- | --- |
| `openclawp/echo` | Smoke-test ability. Echoes back its `text` input. |
| `openclawp/chat` | Run one chat turn against a registered agent. Returns `{ session_id, reply, completed }`. |
| `openclawp/get-time` | Optional fixture (enable via `add_filter( 'openclawp_register_loop_demo', '__return_true' )`). Returns the current server time as ISO 8601 + Unix. |
| `openclawp/get-recent-posts`, `openclawp/count-comments`, `openclawp/get-active-plugins`, `openclawp/get-current-user` | Optional site-introspection fixtures (enable via `add_filter( 'openclawp_register_site_introspection', '__return_true' )`). |
| `openclawp-mcp/delegate--{server}--{subagent}` | Auto-registered per (MCP server, subagent) when a coordinator agent declares subagents. Wraps the canonical `agents/chat` delegation path so MCP clients can dispatch to subagents through the same surface as direct abilities. |

Which abilities a given MCP server exposes depends on its bound agent and the post's optional tool allowlist. The allowlist matches against the sanitized provider-safe tool name (`/` → `__`).

## Legacy path — hand-rolled JSON-RPC

The previous openclaWP releases shipped a hand-rolled JSON-RPC 2.0 handler at `/openclawp/v1/mcp/{slug}`. It is **deprecated** and **off by default**.

To keep it live for one minor release while you migrate external clients:

```php
define( 'OPENCLAWP_MCP_LEGACY', true );
```

(or set the `OPENCLAWP_MCP_LEGACY` env var, or filter `openclawp_mcp_legacy_enabled` → true). Every response carries:

- `Sunset: Wed, 01 Jul 2026 00:00:00 GMT` (RFC 8594)
- `Deprecation: true`
- `Link: </openclawp/v1/mcp-adapter/>; rel="successor-version"`

Each request also logs a `_doing_it_wrong()` deprecation notice (once per request) pointing at the new route.

## Migration checklist

1. Upgrade openclaWP to a build that includes the adapter shim.
2. Verify the adapter is loaded: an MCP server row's admin page shows the `/mcp-adapter/{slug}` endpoint URL.
3. Repoint external clients (Claude Desktop, Cursor, VS Code MCP) at the new URL. Bearer tokens stay valid — no need to rotate.
4. Once all clients are happy, drop `OPENCLAWP_MCP_LEGACY` from `wp-config.php`. The legacy route disappears.
