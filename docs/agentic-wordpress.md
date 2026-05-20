# Agentic WordPress Layer

This tracks the WordPress-agent pieces added after reviewing current WordPress agent projects and the Hermes-style agent patterns worth borrowing. Scope is WordPress core content, MCP, workflows, memory, budgets, and multimodal context. Elementor-specific tooling is intentionally out of scope.

## Included now

- Core content abilities:
  `openclawp/list-content`, `openclawp/get-content`, `openclawp/create-content`, `openclawp/preview-content-update`, `openclawp/update-content`, `openclawp/delete-content`.
- Snapshot and rollback abilities:
  `openclawp/list-content-snapshots`, `openclawp/restore-content-snapshot`.
- Effect metadata:
  read/write/destructive/external tags are declared at ability registration so the existing decision gate can pause risky tool calls.
- Budget guard:
  `openclawp_pre_chat_turn` blocks turns when configured usage caps are reached; `openclawp_pre_tool_execute` blocks tool calls past the per-turn cap.
- Memory:
  `openclawp/remember` and `openclawp/search-memory` store explicit consented memories with provenance and expiry metadata.
- Knowledge-base vector seam:
  the KB table has optional embedding metadata columns and search can be replaced through `openclawp_kb_vector_search_results`.
- Multimodal bridge:
  channel attachments are summarized into the model prompt until providers pass native image/audio parts.
- MCP client bridge:
  external MCP tools register under `mcp/<server>/<tool>` and are tagged as external effects.

## Configuration

Budget caps are read from `openclawp_options` and can be overridden with `openclawp_budget_limits`.

Supported keys:

- `budget_daily_usd`
- `budget_monthly_usd`
- `budget_daily_turns`
- `budget_monthly_turns`
- `budget_agent_daily_usd`
- `budget_agent_monthly_usd`
- `budget_agent_daily_turns`
- `budget_agent_monthly_turns`
- `budget_max_tool_calls_per_turn`

Content post types default to `post` and `page`. Override with `openclawp_content_post_types`.

Memory abilities require explicit `consent: true` by default. Override with `openclawp_memory_requires_consent` only for controlled internal workflows.

## Follow-ups

- Native provider multimodal parts once the active AI Client connectors expose a common shape.
- Real embedding generation and reindex jobs, using the existing KB columns and vector-search filter.
- Multisite/agency inventory and cross-site routing. Keep this separate from the core content ability pack so a single-site install remains small and auditable.
- Admin UI for budget caps, memories, and content snapshots.
