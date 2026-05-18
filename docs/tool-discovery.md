# Tool discovery (catalog mode)

openclaWP ships two meta-abilities that let an agent discover and invoke other abilities at runtime:

- `openclawp/list-tools` — returns slug + 1-line description for every registered ability, filterable by `category` and paginated by `cursor`.
- `openclawp/execute-tool` — takes `tool` (slug) + `args` (object), dispatches via the Abilities API, and returns the result.

They exist because shoving every tool's input schema into every system prompt is expensive. On a site with 50 registered abilities, the catalog can chew through 3-5k tokens per turn — for tools the model usually does not call.

## Enable catalog mode on an agent

Set `catalog_mode => true` in the agent's `default_config`:

```php
wp_register_agent(
    'my-catalog-agent',
    array(
        'label'          => 'My Catalog Agent',
        'description'    => 'You can answer questions about this site. Call `list-tools` to see what is available, then `execute-tool` to invoke the one you need.',
        'default_config' => array(
            'provider'     => 'auto',
            'model'        => 'claude-haiku-4-5',
            'catalog_mode' => true,
            'tools'        => array(
                'openclawp/get-recent-posts',
                'openclawp/count-comments',
                'openclawp/get-active-plugins',
                'openclawp/get-current-user',
                // ... 40 more
            ),
            'max_turns'    => 6,
        ),
    )
);
```

When `catalog_mode` is on:

- The system-prompt cost drops to two declarations (`list-tools` + `execute-tool`) regardless of how many abilities are in `tools`.
- The `tools` list is still consulted: the model can only `execute-tool` a slug it can already see via `list-tools`. The list is restricted to the agent's allowed `tools` when one is supplied.
- Subagent delegate-tools (`delegate-to-<slug>`) are *not* hidden behind the catalog — coordinator routing is structural.

When `catalog_mode` is off (default), behavior is byte-identical to pre-0.7 openclaWP: every ability in `tools` is declared in the system prompt directly.

## Trade-off

| | catalog off (default) | catalog on |
|--|--|--|
| System-prompt cost | ~O(N) tokens for N tools | ~constant (~150 tokens for the two meta-tools) |
| First turn latency | 1 round-trip | usually 2 (model calls `list-tools`, then the real tool) |
| When it pays off | Sites with <20 tools, or where the model usually calls a tool on turn 1 | Sites with many tools where the model usually does *not* need one |

Run the math: if the model needs a tool on T% of turns and the catalog has N tools, catalog mode wins when the saved per-turn tokens × (1 - T%) > the extra round-trip cost on T%.

## Categories

`list-tools` filters by `category`. Categories come from:

1. The ability's explicit `category` (set when calling `wp_register_ability`).
2. Otherwise, the slug's namespace (`posts/recent` → `posts`).

Sites with consistent slug naming get usable categories for free.

## Caching (not yet)

The current implementation re-walks the abilities registry on every `list-tools` call. For sites with hundreds of abilities, per-conversation or per-workspace caching is a follow-up — track it in [issue #37 follow-ups](https://github.com/lezama/openclawp/issues/37).
