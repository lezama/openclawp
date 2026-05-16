# openclaWP scorecard

This is the release-readiness scorecard for turning openclaWP from a useful
developer preview into a plugin people can install, diagnose, and trust.
Update it when a release gate changes.

## North-star path

The plugin is successful when a fresh WordPress 7.0+ site can install the
release ZIP, activate openclaWP with one AI provider, pass `wp openclawp doctor`,
and complete one visible tool-using chat turn from `wp-admin -> openclaWP ->
Chat` without manual code edits.

## Current gates

| Gate | Evidence | Status |
| --- | --- | --- |
| Core unit tests | `vendor/bin/phpunit --do-not-cache-result` | Passing on 2026-05-16 |
| PHP syntax | `find includes tests openclawp.php -name '*.php' -print0 | xargs -0 -n1 php -l` | Passing on 2026-05-16 |
| Composer metadata | `composer validate --strict` | Passing on 2026-05-16 |
| JS lint | `npm run lint` | Passing on 2026-05-16 |
| Block build | `npm run build` | Passing with existing chat bundle-size warnings |
| Runtime smoke | `wp eval-file tests/smoke.php` | Passing, 33 checks |
| Release ZIP smoke | Generated ZIP activated with companion `agents-api` inactive | Passing, vendored agents-api loaded |
| Install diagnostics | `wp openclawp doctor --strict` | Passing on 2026-05-16 in local Docker site |

## Product signals

| Signal | Target | Notes |
| --- | --- | --- |
| First useful answer | One tool-using prompt answers from real site data | Use `what is my latest post?` or `who am I?` |
| Diagnose bad installs | Doctor command points at the missing dependency or setup gap | Fails critical checks, warns on missing provider/agent |
| Trust boundary clarity | Official WhatsApp Cloud API in core; unofficial wacli separate | Core no longer shells out to the linked-device transport |
| Package confidence | ZIP includes runtime deps and built assets only | Release checklist covers file inspection and clean-site smoke |
| Extensibility | Agents, abilities, workflows, providers, and channels stay substrate-driven | openclaWP remains the runtime/UI layer, not the registry owner |

## Known gaps

| Gap | Why it matters | Next check |
| --- | --- | --- |
| Admin UI smoke still needs a clean browser pass | CLI and smoke tests do not prove the wp-admin screens render correctly | Verify Chat, Workflows, Workflow Create, Routines, Channels in a clean WP session |
| Chat bundle exceeds the webpack performance hint | Install works, but first-load weight is high | Split or lazy-load markdown/chart/UI dependencies |
| WhatsApp Cloud API end-to-end is manual | Meta credentials and a reachable webhook are required | Keep signature tests automated; run a manual webhook send before any production claim |
| WordPress 7.0 pre-release setup is date-sensitive | README currently pins RC-era commands | Re-check WordPress 7.0 final status before publishing |
