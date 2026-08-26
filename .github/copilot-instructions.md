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

- WordPress Coding Standards via `ruleset.xml` (WordPress standard, but short array syntax `[]` is allowed and the `WordPress.Files.FileName` rule is disabled)
- Escape all output (`esc_html`, `esc_attr`, `esc_url`), sanitize all input, use nonces and capability checks for admin actions, use `$wpdb->prepare()` for queries

## Releases & Tooling

- `scripts/bump-version.sh <version>` updates the version in `readme.txt`, the `scanfully.php` header, and the `SCANFULLY_VERSION` constant
- Releasing is done via the GitHub release workflow (`.github/workflows/release.yml`), which deploys to WordPress.org SVN
- There are no PHPUnit tests in this repo; verify changes manually in the local WP install
