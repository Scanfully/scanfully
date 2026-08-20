=== Scanfully ===
Contributors: barrykooij,defries,scanfully
Donate link: https://scanfully.com
Tags: monitoring, site health, broken links, broken media, activity log
Requires at least: 6.0
Tested up to: 7.1.0
Stable tag: 1.5.1
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Requires PHP: 7.4

Scanfully monitors your WordPress sites for downtime, performance changes, vulnerabilities, broken content, email delivery problems, DNS changes, SSL issues, and important activity.

== Description ==

## SCANFULLY FOR WORDPRESS 

Your WordPress site can be online while other things are already going wrong.

Emails can stop arriving. Images can disappear. Performance can drop. DNS can change. SSL can fail. A vulnerable plugin can remain installed.

Scanfully monitors those signals and combines them with activity from inside WordPress. This gives you a clearer picture of what changed, what failed, and what needs attention.

The Scanfully plugin connects your WordPress site to your [Scanfully](https://scanfully.com) dashboard. It securely syncs essential site information, Site Health data, and WordPress activity.

Scanfully stores and processes monitoring data outside WordPress. This keeps most monitoring work away from your site's database and frontend.

External checks monitor uptime, performance, SSL, DNS, vulnerabilities, content, and email deliverability. Meanwhile, the plugin provides the WordPress context around those checks.

Together, those signals help you spot problems earlier and understand what happened around the same time.

## SEE WHAT'S HAPPENING ACROSS YOUR WORDPRESS SITES

Scanfully brings monitoring data from all your connected WordPress sites into one dashboard.

But putting everything in one place is only part of the value.

Monitoring signals become much more useful when you can see them together.

A plugin update might be followed by a performance drop. A DNS change might affect email delivery. A content edit might introduce broken images or links.

Scanfully combines those events and checks, so you have more context when something changes.

Instead of jumping between different monitoring tools and WordPress dashboards, you can see what happened from one place.

## SCANFULLY FEATURES

Scanfully monitors different parts of your WordPress sites because site health is more than uptime alone.

#### WORDPRESS ACTIVITY LOG

Scanfully records important activity from inside WordPress.

This includes events such as plugin changes, theme changes, content updates, logins, and other WordPress activity.

The Activity Log becomes especially useful when you compare those events with monitoring data. It helps you understand what changed before a problem appeared.

#### UPTIME MONITORING

Scanfully checks whether your WordPress sites remain available and reachable.

It records outages and recoveries, and it can notify you when availability changes.

Our uptime monitoring also supports CDN-aware checks for sites that sit behind a CDN.

#### PERFORMANCE MONITORING

Scanfully runs regular performance checks and tracks how site speed changes over time.

The dashboard shows performance in easy-to-read graphs, so you can spot slowdowns and investigate when they started.

Performance data can include DNS lookup time, connection time, TLS negotiation, TTFB, and download time.

This helps you look beyond a single page-load number when diagnosing a slowdown.

#### SITE HEALTH

Scanfully collects Site Health information from WordPress and brings it into your Scanfully dashboard.

You can review important WordPress health data without logging into every individual site.

This becomes especially useful when you manage several WordPress sites.

#### CONTENT HEALTH

A site can be online and fast while parts of its content are still broken.

Scanfully's Content Health monitoring checks links and media used across your pages.

The Broken Links monitor checks internal and external links and identifies links that no longer work. It also shows where Scanfully found them.

The Broken Media monitor checks images, scripts, stylesheets, videos, audio, embeds, and iframes. It identifies assets that fail to load and shows where they are used.

Together, these checks help you find content problems before visitors or clients report them.

#### SSL CERTIFICATE MONITORING

Scanfully checks whether your site's SSL certificate is still present and working correctly.

SSL problems can affect browser trust, forms, payment flows, redirects, integrations, and other site functionality.

Monitoring helps you spot certificate problems before they remain unnoticed for too long.

#### DNS MONITORING

Scanfully monitors important DNS records and changes.

DNS affects far more than where your website points. It also plays a role in email delivery, SSL, CDN behavior, and site availability.

Tracking DNS alongside your other monitoring data gives you more context when something suddenly stops working.

#### VULNERABILITY MONITORING

Scanfully checks installed WordPress plugins and themes for known vulnerabilities.

When a known vulnerability affects software on one of your sites, Scanfully gives you the information needed to investigate it.

This helps you focus on vulnerabilities that are actually relevant to the WordPress sites you manage.

#### EMAIL DELIVERABILITY MONITORING

WordPress can report that an email was sent even when that message never reaches an inbox.

Scanfully monitors the email delivery path beyond the initial WordPress send attempt.

It can check mail delivery, important DNS authentication records, blocklist status, and whether a test message reaches an actual inbox.

This helps you detect problems affecting contact forms, password resets, WooCommerce emails, membership emails, and other WordPress-generated messages.

#### SMART NOTIFICATIONS

Scanfully notifies you when monitored events need your attention.

You decide where those notifications should go using [notification channels](https://scanfully.com/docs/channels/).

Scanfully currently supports email, Slack, Discord, and Pushover.

The goal is to surface useful changes without forcing you to watch the dashboard all day.

#### SHAREABLE REPORTS

Some findings need to be shared with clients, team members, or other people responsible for the site.

Scanfully supports shareable reports that give others direct access to relevant findings.

This is particularly useful for Content Health reports covering broken links and broken media.

Instead of sending screenshots or manually rewriting findings, you can share the actual results.

## MONITOR WORDPRESS WITH MORE CONTEXT

No single check can tell you whether a WordPress site is healthy.

A site can be online but slow. It can be fast but contain broken images. WordPress can send an email that never arrives. DNS can change while WordPress itself looks perfectly normal.

That is why Scanfully monitors your site from several angles.

It combines external monitoring with information from inside WordPress, giving you better visibility into what changed and what needs attention.

Monitoring does not prevent every problem.

But it gives you a better chance of spotting problems earlier, diagnosing them faster, and understanding what happened.


### More information

* Visit the [Scanfully website](https://www.scanfully.com/?utm_source=wp-plugin-repo&utm_medium=link&utm_campaign=more-information)
* Find and contact us on X(formerly Twitter): [@scanfullyapp](https://x.com/scanfullyapp)
* Follow us on [LinkedIn](https://www.linkedin.com/company/scanfully)

== Installation ==

Starting with Scanfully consists of just two steps: installing and setting up the plugin. Scanfully is designed to work with your site’s specific needs, so remember to connect your WordPress site with your Scanfully Dashboard.

### INSTALL SCANFULLY FOR WORDPRESS FROM WITHIN WORDPRESS

1. Visit the plugins page within your dashboard and select "Add New";
1. Search for ‘Scanfully’;
1. Activate Scanfully from your Plugins page;
1. Go to "after activation" below.

### INSTALL SCANFULLY FOR WORDPRESS MANUALLY

1. Upload the scanfully folder to the `/wp-content/plugins/` directory;
1. Activate the Scanfully for WordPress plugin through the Plugins menu in WordPress;
1. Go to ‘after activation’ below.

### AFTER ACTIVATION

1. Connect your WordPress site to your Scanfully Dashboard.


== Frequently Asked Questions ==

= How do I connect my website to Scanfully? =
Go to Settings > Scanfully in your WordPress admin and click the "Connect" button. You will be redirected to your Scanfully dashboard to authorize the connection. Once approved, you will be redirected back to your WordPress site and the connection is complete.

= Where's the Scanfully settings screen? =
Settings > Scanfully.

= Does the plugin impact my page speed? =
Scanfully is designed to keep monitoring work away from frontend page loads. The plugin collects WordPress activity and Site Health data, then sends that information to Scanfully in the background. External monitoring and data processing happen outside your WordPress site.

== Screenshots ==
1. The Scanfully settings screen.

== Changelog ==

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