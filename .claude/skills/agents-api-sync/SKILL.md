---
name: agents-api-sync
description: Diff vendor/automattic/agents-api against upstream Automattic/agents-api HEAD; surface breaking interface changes (new abstract methods, signature shifts) before bumping the pinned SHA. Use when the user asks to "sync agents-api", "bump agents-api", "check agents-api drift", or before doing `composer update automattic/agents-api`.
disable-model-invocation: true
---

# agents-api-sync

openclawp pins `automattic/agents-api` to a specific SHA via `composer.json`. When upstream lands a PR that widens an interface — e.g. PR #166 added `WP_Agent_Conversation_Store::list_sessions()` and fataled the plugin at load on bump — the only way to learn about it is the activation crash. This skill closes that loop.

## When to invoke

- Before bumping `automattic/agents-api` in `composer.json` / `composer.lock`.
- When `pendientes.md` mentions the "agents-api convergence" milestone moving forward.
- After a known upstream PR lands (e.g. user says "PR #166 just merged, check us").

## What it does

1. Reads the currently pinned SHA from `composer.lock` (or from `vendor/automattic/agents-api/`'s git metadata if a worktree is present).
2. Fetches `Automattic/agents-api` HEAD via `gh api`.
3. Lists commits between pinned SHA and HEAD.
4. For each commit, surfaces:
   - **Interface changes**: new methods added to `interface` declarations under `src/`.
   - **Signature changes**: method parameter or return-type diffs on existing interfaces.
   - **New requires**: classes/files now `require_once`'d by canonical bootstrap.
   - **Deleted symbols**: anything that openclawp's adapters reference.
5. Cross-references each finding against `includes/` (which classes in openclawp implement which interfaces).
6. Produces a `bump-plan.md` with:
   - The SHA range
   - A checklist of openclawp files that need changes
   - Which changes are mechanical (e.g. add stub method) vs. design (e.g. new contract semantics)

## Inputs

```
/agents-api-sync                # diff against upstream HEAD
/agents-api-sync <sha>          # diff against a specific upstream commit/tag
```

## Implementation outline

```bash
# 1. Current pin
current_sha=$(jq -r '.packages[] | select(.name == "automattic/agents-api") | .source.reference' composer.lock)

# 2. Target SHA
target_sha=${1:-$(gh api repos/Automattic/agents-api/commits/main --jq .sha)}

# 3. Range
gh api "repos/Automattic/agents-api/compare/${current_sha}...${target_sha}" \
  --jq '.commits[] | "\(.sha[0:8]) \(.commit.message | split("\n")[0])"'

# 4. Interface deltas
gh api "repos/Automattic/agents-api/compare/${current_sha}...${target_sha}" \
  --jq '.files[] | select(.filename | test("^src/.*interface.*\\.php$|^src/.*class-wp-agent.*\\.php$")) | .filename'
# then fetch each file's patch and look for `abstract public function` / `public function ...: ` changes
```

## Outputs

- `bump-plan.md` at the repo root (gitignored) with:
  - SHA range diff summary
  - Per-file action items in openclawp's `includes/`
  - Smoke checklist: `php tests/smoke.php`, `php openclawp.php` syntax, `vendor/bin/phpunit --testsuite unit`
- Console summary highlighting any **breaking** change (new abstract method, removed symbol).

## Failure modes

- If `gh api` rate-limits, fall back to a local `git clone --depth=1` of upstream into `/tmp/`.
- If the pinned SHA can't be resolved (e.g. dev-main with no `source.reference`), prompt the user for the SHA explicitly.

## Related

- Issue #33: list_sessions fatal — the canonical example this skill prevents.
- `pendientes.md` → "Agents API convergence" milestone.
- `tareas/` workflow for tracking bump plans.
