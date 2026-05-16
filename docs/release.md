# Release checklist

Use this checklist before tagging or publishing a release package.

## Version and compatibility

1. Update `Version` in `openclawp.php`.
2. Update `OPENCLAWP_VERSION` in `openclawp.php`.
3. Update `Stable tag` and `Changelog` in `readme.txt`.
4. Confirm `Requires at least`, `Tested up to`, and `Requires PHP` match the
   main plugin header.
5. Do not add `Requires Plugins: agents-api` unless `agents-api` is published
   under that exact WordPress.org slug; core can load the Composer dependency
   itself from `vendor/automattic/agents-api/agents-api.php`.
6. Run `composer update --lock` when dependency constraints change and commit
   the resulting `composer.lock` so `dev-main` dependencies stay pinned.
7. While WordPress 7.0 is still pre-release, keep the README setup commands
   pinned to the current 7.0 RC. After WordPress 7.0 final ships, remove the RC
   pinning note and command.

## Build and test

Run these from the plugin root:

```bash
composer install
npm ci
npm run build
npm run lint
vendor/bin/phpunit
```

Then smoke test in a WordPress 7.0+ site:

```bash
wp eval-file tests/smoke.php
wp openclawp doctor --strict
```

For the default local model path, also verify a tool-using chat prompt in
`wp-admin -> openclaWP -> Chat`, such as `what is my latest post?`.

## Packaging

1. Build frontend assets before packaging.
2. Install Composer dependencies with `composer install --no-dev --optimize-autoloader`.
3. Include built block assets and runtime Composer dependencies in the release.
4. Exclude development-only files listed in `.distignore`.
5. Confirm the generated ZIP contains `openclawp/openclawp.php`, built block
   assets, `vendor/autoload.php`, and no `node_modules`, tests, source assets,
   Composer manifests, npm manifests, or release-only tooling files.
6. Install the generated ZIP on a clean WordPress 7.0+ site with the companion
   `agents-api` plugin inactive, then confirm `AGENTS_API_PLUGIN_FILE` points to
   `openclawp/vendor/automattic/agents-api/agents-api.php`.
7. Confirm `readme.txt` includes dependency, external-services, changelog, and
   upgrade-notice sections.

## Manual release checks

1. Activate openclaWP and one AI provider on a clean site. Either activate a
   companion `agents-api` plugin or confirm the vendored Composer copy loads.
2. Confirm `wp-admin -> openclaWP -> Chat` loads without PHP notices.
3. Confirm `POST /wp-json/openclawp/v1/chat` is blocked for users without the
   configured permission.
4. Confirm the workflow list and run detail views load in wp-admin.
5. If WhatsApp Cloud API is enabled, verify webhook signatures reject invalid
   requests.
6. Update `docs/scorecard.md` with the package smoke result, bundle-size
   warnings, and any remaining manual gaps before tagging.
