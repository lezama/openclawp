# Prompt evals

openclaWP guards against prompt regressions in two complementary layers. Layer 1
is shipped today; Layer 2 is queued. Tracking issue:
[#46](https://github.com/lezama/openclawp/issues/46).

## Layer 1 — payload snapshot tests (shipping)

Goal: catch unintentional drift in the provider payload (system prompt, message
list, tool catalog, model preference) at zero token cost.

For each canonical `(agent, channel)` pair the suite freezes a user message,
asks the production code to build the request payload openclaWP would send to
the provider, and diffs that payload against a committed JSON snapshot under
`tests/integration/prompt-assembly/__snapshots__/`.

Pairs currently covered:

| Snapshot                            | Agent                            | Channel    |
|-------------------------------------|----------------------------------|------------|
| `loop-demo--chat.json`              | `openclawp-loop-demo`            | `chat`     |
| `site-introspection--whatsapp.json` | `openclawp-site-introspection`   | `whatsapp` |
| `coordinator--chat.json`            | `openclawp-coordinator`          | `chat`     |
| `workflow-drafter--chat.json`       | `openclawp-workflow-drafter`     | `chat`     |
| `example--whatsapp.json`            | `openclawp-example`              | `whatsapp` |

### Run

```bash
composer test:assembly
```

Runs on every CI build inside the existing `phpunit` job (`.github/workflows/tests.yml`).
Adds <1s to the test cycle — no model calls, no network.

### Failure mode

A failing snapshot prints a unified diff of expected vs actual JSON and points
at the regenerate command. Example:

```
Failed asserting that two strings are identical.
--- Expected
+++ Actual
@@ @@
-	"system_instruction": "You are a precise assistant. …",
+	"system_instruction": "You are a regressed assistant. …",
```

### Updating snapshots (intentional changes)

When you change a system prompt, tool description, or default config on
purpose, regenerate the affected snapshots and commit the diff:

```bash
UPDATE_SNAPSHOTS=1 composer test:assembly
git add tests/integration/prompt-assembly/__snapshots__
```

The PR reviewer reads the diff to confirm the change is intentional. Treat
every snapshot churn the same way you'd treat a copy edit on a customer-facing
string.

### Adding a new pair

Add a row to `canonical_pairs()` in
`tests/integration/prompt-assembly/PromptAssemblySnapshotTest.php`, then run:

```bash
UPDATE_SNAPSHOTS=1 composer test:assembly
```

The first run creates the snapshot. Subsequent runs diff against it.

### Implementation notes

- The assembler lives in
  `tests/integration/prompt-assembly/PromptPayloadAssembler.php`. It mirrors
  `OpenclaWP_Runner::build_turn_runner()` minus the provider call — same
  agent description, same transcript, same `OpenclaWP_Tools_Resolver` output,
  same model-preference resolver.
- JSON is pretty-printed with sorted keys (recursive on assoc arrays, preserving
  list order). Trailing newline. No machine-specific paths. Tabs for indent so
  the files match the repo's PHP/JS style.
- Zero new composer deps. Plain PHPUnit + `file_put_contents()` for updates.

## Layer 2 — black-box conversation evals (deferred)

YAML-defined eval suite covering 10 seed conversations across chat block,
WhatsApp, Telegram, and MCP, executed by promptfoo's HTTP provider against a
booted wp-env. Tracked in
[#49](https://github.com/lezama/openclawp/issues/49).
