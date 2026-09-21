# Scanfully Plugin — Copilot Instructions

## Project Overview

- WordPress plugin (PHP 7.4+, WordPress 6.0+) that connects sites to the Scanfully platform
- PSR-4 autoloading: namespace `Scanfully\` maps to `src/`
- Entry point: `scanfully.php` (loads Action Scheduler, defines the `Scanfully()` helper, hooks `plugins_loaded` at priority 20)
- Bootstrap: `src/Main.php` is a singleton (`Main::get()`); `setup()` wires the module controllers (Events, Cron, Connect, PageEdit, WooCheckout when WooCommerce is active)

## Project Structure (src/)

- `API/` — request classes to the Scanfully API; abstract `Request` base with concrete subclasses (EventRequest, SiteDataRequest, ...)
- `Connect/` — OAuth connection flow, dashboard admin page, notices, connection state
- `Cron/` — Action Scheduler job scheduling (`Cron\Controller`)
- `EmailHealth/` — email deliverability monitoring
- `Events/` — timeline event tracking; each event extends the base `Event` class with a hook name constant
- `Health/` — site health data collection and syncing
- `Options/` — plugin options (connection state, tokens, site ID)
- `PageEdit/` — post/page edit integration hooks
- `Sync/` — on-demand sync endpoint handler
- `Util/` — utilities
- `WooCheckout/` — WooCommerce probe checkout gateway (probe requests use the `X-Scanfully-Probe` header, probe orders use `_scanfully_probe_order` post meta)

## Conventions

- All hooks and filters are prefixed `scanfully_` (e.g., `scanfully_api_url`, `scanfully_auth_headers`, `scanfully_health_data`)
- Options in `wp_options` are prefixed `scanfully_connect_`
- Action Scheduler action names are class constants on `Cron\Controller` (e.g., `ACTION_SYNC_SITE_HEALTH`); all jobs use the `'scanfully'` group
- Background/scheduled work uses Action Scheduler (`woocommerce/action-scheduler` from `vendor/`), never raw WP-Cron
- API calls go through `src/API/Request.php` subclasses using `wp_remote_post()`/`wp_remote_get()` with a Bearer token from options; base URL is `Main::API_URL` (filterable)
- Text domain: `scanfully`, domain path `/languages/` (`languages/scanfully.pot`). All user-facing strings must be translatable (`__()`, `esc_html__()`, etc.)
- Keep code compatible with PHP 7.4 (no PHP 8-only syntax)
- Never use a real client's domain in examples; use `scanfully.com`

## Coding Standards & Security

- WordPress Coding Standards (WPCS 3.x) via `phpcs.xml.dist`: short array syntax `[]` is allowed, Yoda conditions are not required, and the `WordPress.Files.FileName` rule is disabled
- Classes, folders and file names are PascalCase and follow PSR-4 (class `AddressCodec` lives in `src/EmailHealth/AddressCodec.php`); functions, methods and variables are `lower_snake_case`
- Escape all output (`esc_html`, `esc_attr`, `esc_url`), sanitize all input, use nonces and capability checks for admin actions, use `$wpdb->prepare()` for queries

## Releases & Tooling

- `scripts/bump-version.sh <version>` updates the version in `readme.txt`, the `scanfully.php` header, and the `SCANFULLY_VERSION` constant
- Releasing is done via the GitHub release workflow (`.github/workflows/release.yml`), which deploys to WordPress.org SVN
- `vendor/` is not committed; run `composer install` after cloning (the release workflow runs a no-dev install)

## Quality gates

- `composer lint` / `composer lint:fix` / `composer lint:strict`: PHPCS (errors only / auto-fix / including warnings)
- `composer stan`: PHPStan (config in `phpstan.neon.dist`, WordPress, WooCommerce and Action Scheduler symbols resolved). New baseline entries need a `# BASELINE:` justification
- `composer test`: PHPUnit unit suite under `tests/Unit` (Brain Monkey via `yoast/wp-test-utils`, no WordPress runtime). Test files are PascalCase and end in `Test.php`, e.g. `tests/Unit/EmailHealth/AddressCodecTest.php`
- `npm run env:test:start` then `npm run test:integration`: PHPUnit integration suite under `tests/Integration`, run in a separate wp-env test environment (`.wp-env.test.json`, port 8889; latest WordPress and WooCommerce on PHP 7.4, needs Docker). `npm run env:start` starts the development site (`.wp-env.json`, port 8888), which the tests never touch. `npm run test:integration:all` runs it with both WooCommerce order storages (legacy posts and HPOS); CI does the same
- `composer qa`: lint, stan and unit tests in one go
- CI (`.github/workflows/qa.yml`) runs these on PHP 7.4 to 8.4
