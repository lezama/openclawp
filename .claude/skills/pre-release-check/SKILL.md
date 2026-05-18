---
name: pre-release-check
description: Run the release.yml gates locally before pushing a `v*` tag. Verifies plugin header / OPENCLAWP_VERSION / tag agree, runs the build pipeline, audits .distignore against the produced zip, and re-runs smoke + unit tests. Use when the user asks to "ship v0.x", "tag a release", "pre-release check", or before any `git tag v*`.
---

# pre-release-check

`.github/workflows/release.yml` only fires after you push a tag. By then it's too late — a mismatch between `Version:` in `openclawp.php`, `OPENCLAWP_VERSION`, and the tag fails the build but the tag still exists on origin. This skill runs the same gates locally so you tag with confidence.

## When to invoke

- `/pre-release-check 0.1.1` — explicit version arg.
- User says "ship", "tag", "release", "publish" in connection with openclawp.
- Before any `git tag v*` command, even if the user didn't ask explicitly.

## What it checks

| Gate | Source of truth | Command |
|---|---|---|
| Plugin header version | `openclawp.php` line "Version: X" | `grep -oE "Version:\s*[0-9.]+" openclawp.php` |
| Code constant | `OPENCLAWP_VERSION` in `openclawp.php` | `grep -oE "OPENCLAWP_VERSION',\s*'[0-9.]+'"` |
| Argument | `$1` | — |
| All three agree | — | Bail with diff if not. |
| Smoke tests | `tests/smoke.php` | `php tests/smoke.php` |
| Unit tests | `vendor/bin/phpunit --testsuite unit` | (must be 0 fails) |
| Build artefacts | `bin/build.sh` | `bash bin/build.sh $version` then unzip + sanity-check |
| `.distignore` audit | Generated zip | List zip contents; flag any file matching common-secret patterns (`.env*`, `*.key`, `tools/`, `tests/`, `node_modules/`) |
| WP.org headers | `openclawp.php` | Confirm `Requires at least`, `Requires PHP`, `Tested up to` are present |
| README freshness | `readme.txt` | Check `Stable tag:` matches `$version` |

## Inputs

```
/pre-release-check                       # uses Version: from openclawp.php
/pre-release-check 0.2.0                 # check against this version
/pre-release-check 0.2.0 --no-build      # skip bin/build.sh (faster)
```

## Outputs

- A green/red gate report.
- On green: print the exact `git tag v<version> && git push origin v<version>` command, but **do not execute it** — that's the user's call.
- On red: a punch list of what to fix, with file paths and line numbers.

## Implementation outline

```bash
set -e
version="${1:-$(grep -oE 'Version:\s*[0-9.]+' openclawp.php | head -1 | sed -E 's/Version:\s*//')}"
header_ver="$(grep -oE 'Version:\s*[0-9.]+' openclawp.php | head -1 | sed -E 's/Version:\s*//')"
const_ver="$(grep -oE "OPENCLAWP_VERSION',\s*'[0-9.]+'" openclawp.php | sed -E "s/.*'([0-9.]+)'.*/\\1/")"
readme_ver="$(grep -oE 'Stable tag:\s*[0-9.]+' readme.txt | sed -E 's/Stable tag:\s*//')"

[ "$version" = "$header_ver" ] || { echo "::error::header=$header_ver != $version"; exit 1; }
[ "$version" = "$const_ver" ] || { echo "::error::OPENCLAWP_VERSION=$const_ver != $version"; exit 1; }
[ "$version" = "$readme_ver" ] || { echo "::error::readme.txt Stable tag=$readme_ver != $version"; exit 1; }

php tests/smoke.php
vendor/bin/phpunit --testsuite unit
bash bin/build.sh "$version"

# .distignore audit
unzip -l "openclawp-${version}.zip" | grep -E '(\.env|/tools/|/tests/|/node_modules/|/\.git/|composer\.json|phpcs\.|phpstan\.|playwright)' \
  && { echo "::error::leakage into zip — fix .distignore"; exit 1; } || true

echo "✅ Ready to tag v${version}"
echo "Run: git tag v${version} && git push origin v${version}"
```

## Failure modes

- If `vendor/` doesn't exist locally, prompt the user to run `composer install` first — don't auto-run (composer install is slow and pollutes state).
- If `node_modules/` doesn't exist, `bin/build.sh` will fail at the `npm run build` step. Prompt for `npm ci`.

## Related

- `.github/workflows/release.yml` (the workflow this mirrors)
- `bin/build.sh`
- `.distignore`
