=== Scanfully ===
Contributors: barrykooij,defries,scanfully
Donate link: https://scanfully.com
Tags: monitoring, site health, broken links, user activity, activity log
Requires at least: 6.0
Tested up to: 7.1.3
Stable tag: 1.9.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Requires PHP: 7.4

Catch WordPress downtime, slowdowns, broken content, and email problems. Track site changes and get alerts in one dashboard.

== Description ==

= Know which WordPress sites need attention =

A client finds a broken image. A password reset never arrives. A site gets slower after a round of updates.

These problems can develop while your homepage still loads normally. Finding them means checking more than whether a site is online.

Scanfully brings uptime, performance, content health, email checks, and WordPress activity together. You can see which sites need attention and investigate what happened around the same time.

Use it to look after your own WordPress sites or manage client sites from one dashboard.

This plugin connects WordPress to the [Scanfully monitoring service](https://scanfully.com/). A Scanfully account is required. Start with a [14-day free trial](https://scanfully.com/pricing/); continued monitoring requires a paid subscription.

= Find problems across your sites =

* **Uptime monitoring:** Check your site and selected pages for failures, then review outages, recoveries, and response details. CDN-aware checks help you investigate availability when caching sits between visitors and your server.
* **Performance monitoring:** Track response times and see where a request slows down. Review DNS lookup, connection, TLS, server response, and HTML download times to choose where to investigate.
* **Content health:** Find broken internal and external links, missing images, and other media that fail to load. See the pages using each resource, share findings, and recheck after a repair. Mark known false positives so they stop appearing in your reports. Crawls check public HTML; they do not log in or execute JavaScript.
* **SSL and DNS monitoring:** Spot certificate expiry and configuration problems, and review DNS changes across resolvers. Check what changed when a domain, certificate, or mail setup starts behaving differently.
* **Plugin vulnerabilities and updates:** See known vulnerabilities affecting installed plugins, including inactive plugins. Review severity, available fixes, and reported updates to decide which sites need follow-up.
* **WordPress Site Health:** Review WordPress, PHP, server, and database information without opening each site's admin. Get alerts about missing security updates and software versions that no longer receive security fixes. Inspect must-use plugins and drop-ins, and mark expected ones to focus on unexpected changes. Mute accepted health issues until they change or are resolved.
* **Email monitoring:** Check whether a test message sent through WordPress reaches Scanfully's inbox. Review authentication and blocklist findings when delivery fails. This tests your site's sending setup; it does not verify delivery to every customer's inbox.

= Understand what changed inside WordPress =

When something breaks, the next step is often finding out what changed.

Scanfully's WordPress Activity Log records updates, plugin activation and deactivation, content edits, media changes, and changes to WordPress users. It also covers settings, menus, widgets, the Customizer, and Site Editor changes. Edits through WordPress's plugin and theme file editors include the changed code. Inspect change details, including content comparisons and failed-update errors, to see what actually happened.

For users with elevated permissions, it records successful logins, password and email changes, and application-password events. Failed logins are summarized hourly.

Search and filter the activity log to narrow your investigation by user, event, or date. Jump to a date or export results to CSV when you need to review or share the history. Activity history is kept for 18 months. Compare those events with downtime and performance results to build a timeline of the problem.

An update near a slowdown gives you a starting point for investigation. The timing alone does not establish the cause.

= Keep client sites organized =

The Scanfully dashboard brings current status, findings, and history into each site's monitoring views.

Group sites by client or label, filter your portfolio, and save views for the sites you regularly check. Filter by WordPress or PHP version to find sites sharing an outdated stack. Switch between sites while keeping the monitoring view you're working in.

= Get useful alerts without watching the dashboard =

Send notifications through email, Slack, Discord, or Pushover. Set rules across your sites, clients, or labels so new sites fit your notification setup.

Group related alerts, use quiet hours, snooze notifications during planned work, or review a daily digest. The notification log shows delivery history when you need to check an alert.

= Bring monitoring into your own workflow =

Use the [Scanfully API](https://docs.scanfully.com/account/api/) to work with supported monitoring data and account operations.

Connect supported AI apps through [MCP](https://docs.scanfully.com/account/mcp/) with access you approve and can revoke. Connections default to read-only access; optional write permissions cover the actions documented by Scanfully.

Protect your Scanfully account with authenticator-based two-factor sign-in, including when you use a social sign-in provider.

= WooCommerce checkout monitoring: beta =

A working homepage does not tell you whether a shopper can reach checkout.

For accounts with beta access, Scanfully can test a configured purchase flow through the product, cart, and checkout steps. Tests stop at the payment step without completing a payment. Review the failed step and available screenshots when a test fails.

See the [WooCommerce monitoring documentation](https://docs.scanfully.com/woocommerce/) for requirements and beta access.

= How the plugin and service work together =

The plugin collects WordPress activity and health information, then sends it to Scanfully through background jobs. It also runs the WordPress side of email tests and supported checkout tests.

Scanfully stores received monitoring data and runs the dashboard outside WordPress. The plugin handles local collection and background syncing; external checks request pages from your site.

Visit the [documentation](https://docs.scanfully.com/) for setup and troubleshooting, or [contact support](https://scanfully.com/contact/) for help.

== Installation ==

= Install from WordPress =

1. Open Plugins > Add New Plugin in your WordPress admin.
2. Search for "Scanfully", then click "Install Now" and "Activate".
3. Open Settings > Scanfully and click "Connect your Scanfully account".
4. Sign in to Scanfully, or create an account, and authorize the connection.
5. Return to Scanfully to review your site and configure your monitoring and notifications.

= Install manually =

1. Upload the `scanfully` folder to `/wp-content/plugins/`.
2. Activate Scanfully from the Plugins screen.
3. Follow steps 3 to 5 above to connect your site.

For a walkthrough, see [Connect your website](https://docs.scanfully.com/onboarding/connect-your-website-to-scanfully/).

== Frequently Asked Questions ==

= Do I need a Scanfully account? =

Yes. This plugin connects your site to Scanfully's external monitoring service. A 14-day free trial is available; continued monitoring requires a paid subscription. See [current plans and pricing](https://scanfully.com/pricing/).

= Where do I connect my site? =

Open Settings > Scanfully in WordPress and click "Connect your Scanfully account". Authorize the connection in Scanfully. You will then return to WordPress with your site connected.

= Can I monitor several WordPress sites? =

Yes. Connect each site to your Scanfully account. Use clients, labels, and saved views to organize them in the dashboard.

= Does the plugin affect page speed? =

The plugin collects activity and health data inside WordPress and sends it through background jobs. Scanfully stores and processes the data it receives and runs external monitoring checks. The plugin still uses your site's resources for collection and scheduled tasks.

= Why is some content missing from a scan? =

Content crawls inspect public, server-rendered HTML. Content behind a login, added by JavaScript, or revealed through interaction is outside that coverage. See [what Scanfully does not scan](https://docs.scanfully.com/content/what-scanfully-does-not-scan/).

= Where can I find setup and troubleshooting help? =

Use the [Scanfully documentation](https://docs.scanfully.com/) or email [support@scanfully.com](mailto:support@scanfully.com).

== External services ==

This plugin connects to the Scanfully service at [scanfully.com](https://scanfully.com/) and its API at `api.scanfully.com`. It does not send monitoring data until you connect your site. Starting the connection passes your site URL and return URL to Scanfully.

Once connected, the plugin sends the following data to provide monitoring, diagnostics, and WordPress activity context:

* **Site and health information:** WordPress, PHP, server, and database details, including the database username; site settings; user counts; plugin inventory; must-use plugin and drop-in details, including file hashes; and directory sizes. Health data syncs on connection, every three hours, and after plugin changes. Directory sizes sync daily.
* **WordPress activity:** Event details, the acting user's ID and display name, and relevant old and new setting values. Content and file edits can include changed text or code and nearby context. This includes content from draft, private, and password-protected posts.
* **WordPress user events:** User IDs, display names, and roles. Events for users with elevated permissions also include their email address. Successful logins and first application-password use for those users include IP addresses, forwarded proxy information, and browser details.
* **Failed-login summaries:** Hourly counts and details of targeted users with elevated permissions. These summaries omit IP addresses and attempted usernames.
* **WooCommerce configuration:** On connected WooCommerce sites, shop details sync even when checkout tests are off. These include the store address, WooCommerce version, product and cart/checkout URLs, shipping details, and login requirements. When checkout tests are enabled, Scanfully creates test orders. The plugin cancels test orders and deletes cancelled test orders through scheduled cleanup after seven days by default.

Email checks are enabled by default after connection. The plugin sends test messages through your site's mail setup to a Scanfully address. Messages include site and version details and verification identifiers. The sender defaults to your WordPress administration email. The plugin also reports the detected mail plugin or WordPress version and local send errors to the API. You can turn these checks off under Settings > Scanfully.

Read Scanfully's [terms and conditions](https://docs.scanfully.com/general/terms-and-conditions/), [privacy policy](https://docs.scanfully.com/general/privacy-policy/), and [GDPR information](https://docs.scanfully.com/general/is-scanfully-gdpr-compliant/) for service and data-handling details.


== Screenshots ==
1. The Scanfully settings screen.

== Changelog ==

= 1.9.0: October 19, 2026 =
* Added: the activity log reports what changed in a saved post, posts deleted permanently, categories, tags and other terms, and bulk actions as one row listing every post or term
* Added: media library changes, every setting of the core settings screens, navigation menus and widgets, Customizer theme settings, and Site Editor styles, templates, template parts and fonts in the activity log
* Added: plugin and theme file editor edits, plugin and theme installs, deletions and theme switches, and failed plugin, theme and WordPress updates in the activity log
* Added: logins, password and email address changes, application passwords and super admin changes of accounts with elevated rights in the activity log
* Added: failed logins are counted per hour and sent once an hour
* Added: the site data includes the must-use plugins, their folders and the drop-ins, checked for changes every hour
* Changed: saves that change nothing are no longer reported, and permalink structure changes replace rewrite rule flushes
* Fixed: SEOPress 404 records showing as saved posts in the activity log
* Fixed: WooCommerce checkout test subscriptions could not be cancelled, and checkout test requests could run the shop's cron jobs on sites with ALTERNATE_WP_CRON

= 1.8.1: October 16, 2026 =
* Added: WooCommerce checkout monitoring can buy subscription and variable subscription products; subscriptions created by a test order are cancelled right away without sending emails and removed with the test order
* Removed: development-only files from the plugin package
* Fixed: subscriptions created by WooCommerce checkout tests showing in the Subscriptions admin list and its status counts

= 1.8.0: October 13, 2026 =
* Added: site data includes each plugin's latest version from WordPress's own update check, so Scanfully can show which plugins have an update
* Changed: help center and notification channels links now point to docs.scanfully.com
* Fixed: a sync requested from the dashboard now runs right away instead of waiting for the next visit to the site or for the pending regular syncs
* Fixed: jobs scheduled within ten minutes of an uninstall being stored under the deleted Action Scheduler group
* Security: the connect flow uses PKCE, so an authorization code that ends up anywhere else cannot be exchanged for tokens

= 1.7.0: September 21, 2026 =
* Added: WooCommerce checkout monitoring support.
* Added: PHPCS, PHPStan, PHPUnit and wp-env tooling with composer scripts and QA workflow
* Added: uninstall cleanup that removes all plugin options, scheduled jobs and the WooCheckout probe user
* Fixed: connect success handler continuing to render the page after redirecting
* Fixed: token refresh locking sites out when refreshes overlap or the API returns an error response
* Fixed: deactivation leaving queued event jobs behind and queueing new jobs for Scanfully itself
* Fixed: email deliverability check interval from the API is kept between 15 minutes and 7 days
* Fixed: manual email deliverability check postponing the scheduled checks
* Fixed: update events reported for failed installs, for Scanfully itself in other folders, and with the wrong version for themes outside the default theme folder
* Fixed: email deliverability checks treating API problems as mail failures and missing real first failures
* Fixed: directory sizes reported as 0 or stale; they are now measured fresh and skipped when they can't be measured
* Fixed: Scanfully settings page saves waiting on slow Scanfully API calls
* Fixed: Scanfully settings page breaking on an unreadable stored date
* Fixed: token refresh resetting the connection date and last-used date
* Fixed: Scanfully admin notices showing to users who can't manage the connection
* Fixed: recurring job checks running on every front-end request and possible duplicate recurring jobs
* Fixed: dates shown in UTC on sites that use a manual UTC offset
* Fixed: site ID not encoded in API URLs, and the dashboard button ignoring the dashboard URL filter
* Fixed: connect notices showing HTML entities for translated text with special characters
* Fixed: site health data raising a warning when collected under WP-CLI or a system cron
* Fixed: slow user count on large sites, and database size including other installs' tables
* Fixed: single-file plugins reported with the slug "." in site health data
* Fixed: site health reporting the raised PHP memory limit instead of the normal one
* Fixed: publish events dropped right after a draft save, and Quick Edit and page builder saves missing from the timeline
* Fixed: rewrite rule flushes creating repeated timeline events
* Fixed: oversized timeline events being dropped, and update events crashing on invalid input
* Fixed: timeline events silently lost when the API rejects them; temporary failures are now retried
* Fixed: email deliverability checks with a guessable nonce, slow transport detection and long timeouts; added a setting to turn the checks off
* Security: post passwords are no longer sent in timeline events
* Security: connect flow accepting an empty state, which let a forged link connect the site to another Scanfully account
* Security: API requests now verify TLS certificates
* Security: stricter site ID validation in the connect flow
* Security: edit shortcut no longer redirects visitors who can't edit, and its responses are never cached
* Security: email deliverability checks are only sent to Scanfully ping addresses
* Security: API tokens are no longer loaded on every request
* Security: site API tokens are revoked on disconnect, reconnect and uninstall

= 1.6.0: August 20, 2026 =
* Added: report OS distribution (os_id, os_id_like, os_version) in health data
* Added: on-demand REST endpoint to trigger site health and directory sync
* Fixed: validate token response before constructing Options to prevent fatal TypeError
* Fixed: email deliverability cadence stuck on 30-minute interval due to false-positive wp_mail() failures
* Fixed: prevent duplicate email deliverability ping actions by using a self-scheduling single action
* Fixed: clear refresh-failure state on successful API requests to prevent false broken-connection notice

= 1.5.1: April 28, 2026 =
* Added: configurable From address override for email deliverability pings

= 1.5.0: April 27, 2026 =
* Added: email deliverability monitoring via Action Scheduler and plugin admin UI
* Fixed: prevent Action Scheduler args overflow on PostSaved events by summarizing post data

= 1.4.0: April 20, 2026 =
* Added: Debounced health data sync after plugin install, removal, update, and activation changes.
* Added: Admin notice when Scanfully connection is stale for over 2 days with one-click reconnect button.
* Added: Refresh failure tracking with error reason in stale connection notice
* Added: Background job processing via Action Scheduler for events and health sync, replacing blocking inline API calls and WP-Cron.
* Added: wordpress-stubs dev dependency.
* Changed: Update last_used only on confirmed successful API responses.
* Changed: FAQ connect instructions to reflect OAuth flow.
* Changed: Site data sync frequency from twice daily to every 3 hours.
* Fixed: Send site health data instead of legacy twice_daily hook on connect.

= 1.3.1: February 6, 2026 =
* Fix: Use home_url() instead of get_site_url() for site URL detection.
* Fix: Improved SSL detection for sites behind reverse proxies and load balancers.
* Fix: Meta changes and updates.

= 1.3.0: November 28, 2025 =
* Feature: Added Scanfully edit feature, allowing you to directly open a post/page edit page from the Scanfully dashboard.
* Tweak: Meta updates.
* Fix: PHP documentation fixes.
* Fix: Overal PHP code clean up.
* Fix: Clean twice_daily cron on plugin deactivation.

= 1.2.7 : May 12, 2025 =
* Tweak: Meta updates.

= 1.2.6 : Jul 17, 2024 =
* Tweak: Specify __DIR__ on autoload require
* Tweak: Generate health data in separate function for reusability.
* Tweak: Added 'scanfully_health_data' filter for health data.

= 1.2.5 : Jun 18, 2024 =
* Tweak: Directly run site health cron jobs after connecting.

= 1.2.4 : Jun 15, 2024 =
* Tweak: Updated logos.
* Tweak: Set correct event names for pluginactivate and plugindeactivate events.

= 1.2.3 : May 14, 2024 =
* Tweak: Removed error_log call.

= 1.2.2 : May 14, 2024 =
* Tweak: Display correct version on the bottom of the connect screen.
* Tweak: Added scanfully_connect_page_content_end action to connect screen.

= 1.2.1 : May 13, 2024 =
* Tweak: Fixed an issue with logging plugin updates for our own plugin.

= 1.2.0 : May 12, 2024 =
* Feature: Added new site data properties.
* Feature: Added support for new directories Health data.
* Tweak: Escape redirect_uri and site in GET parameters to connect screen.
* Tweak: Only try to refresh tokens when connected.
* Tweak: Only send health data when connected.

= 1.1.2 : April 16, 2024 =
* Tweak: Fixed CoreUpdate event naming.

= 1.1.1 : March 18, 2024 =
* Tweak: Fixed small API connectivity issue

= 1.1.0 : March 18, 2024 =
* Feature: Added new site event hooks
* Feature: Added site health communication
* Feature: Added support for Scanfully Connect
* Tweak: Various design tweaks and improvements
* Tweak: Various bug fixes and minor improvements

= 1.0.0 : November 1, 2023 =
* Initial version

== Upgrade Notice ==
None yet.