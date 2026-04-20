=== Scanfully ===
Contributors: barrykooij,defries,scanfully
Donate link: https://scanfully.com
Tags: monitoring, site health, broken links, broken media, activity log
Requires at least: 6.0
Tested up to: 6.9.1
Stable tag: 1.3.1
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Requires PHP: 7.4

Scanfully is your favorite WordPress performance and site health monitoring tool.

== Description ==

## SCANFULLY FOR WORDPRESS 

Scanfully is a WordPress performance, site health, and content health monitoring tool. This plugin connects your WordPress site to your [Scanfully](https://scanfully.com) dashboard and acts as the bridge between the two.

The plugin syncs essential site information and securely sends activity data to Scanfully, where it is stored and processed externally. By hosting this data outside of WordPress, Scanfully avoids adding database load or performance overhead to your site.

Combined with external performance checks, uptime monitoring, Broken Links, Broken Media, and a hosted WordPress Activity Log, Scanfully gives you a clear and actionable overview of your site’s health. You can see what changed, when it changed, and how those changes affect performance, stability, and user experience.

The result is a healthier WordPress site, with better visibility, less guesswork, and full control over what happens on your site.

## ONE DASHBOARD TO RULE THEM ALL 

Your Scanfully Dashboard consolidates all your WordPress sites, sending you timely alerts for required changes.

Easily connect changes made inside your WordPress site to performance and site health impact. Get notifications for the events that are important to you. Right when they happen. Exactly when you want to know they happen.

### SCANFULLY FEATURES

Scanfully helps you stay on top of your WordPress Site Health and Performance in many ways. Let’s take a look at what we have available:

#### SINGLE DASHBOARD
**All your sites in one dashboard** allowing you to easily navigate to the various monitoring features.

#### WORDPRESS ACTIVITY LOG
Our WordPress Events Timeline **collects all changes happening inside your WordPress admin**. All these events combined with our checks provide you a unique insight into what’s going on. No longer do you have to guess what change caused the problem your client just reported and insisted he didn't do anything to cause it.

#### BROKEN LINKS MONITOR
The Broken Links monitor checks your site for internal and external links that no longer work. It detects links that return error responses and shows where they were found, so you can fix dead ends before users run into them. This helps keep navigation predictable and content references accurate.

#### BROKEN MEDIA MONITOR
The Broken Media monitor checks the media assets used on your pages, including images, scripts, stylesheets, (YouTube) videos, audio, embeds, and iframes. It detects assets that fail to load and shows exactly where they are used. This helps prevent layout issues, broken functionality, and silent performance regressions.

#### UPTIME MONITORING 
Scanfully checks your WordPress sites with **comprehensive uptime monitoring** and **smart notifications**

#### PERFORMANCE MONITORING
We do fequent **Performance Checks** to measure how fast your site loads, and provide you with an easy to read graph and recommendations

#### SITE HEALTH
**One Site Health dashboard to rule them all**. We collect and import all of your WordPress site’s health data in one view. Easy insights into the site health metrics that matter the most for your site.

#### SMART NOTIFICATIONS
Scanfully's smart notification systems allows you to define where you want to receive [whatever kind of notification you prefer](https://scanfully.com/docs/channels/). We currently offer Slack, Discord, email, and Pushover. 

#### VULNERABILITY SCANNER
Get notified as soon as a plugin or theme on your WordPress site has a known vulnerability. Scanfully checks your installed plugins and themes against known vulnerability databases and alerts you immediately, so you can take action before your site is at risk.

#### LIGHTHOUSE SCANS (coming soon)
Automated insights into the performance, accessibility, and quality of your website in one place


### More information

* Visit the [Scanfully website](http://www.scanfully.com/?utm_source=wp-plugin-repo&utm_medium=link&utm_campaign=more-information)
* Find and contact us on X(formerly Twitter): [@scanfullyapp](http://x.com/scanfullyapp)
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
No, our plugin on listens to changes in the WordPress backend and sends these changes to the Scanfully server. It does not impact your frontend or page load speed.

== Screenshots ==
1. The Scanfully settings screen.

== Changelog ==

= 1.4.0: April 20, 2026 =
Added: Debounced health data sync after plugin install, removal, update, and activation changes.
Added: Admin notice when Scanfully connection is stale for over 2 days with one-click reconnect button.
Added: Refresh failure tracking with error reason in stale connection notice
Added: wordpress-stubs dev dependency.
Changed: Update last_used only on confirmed successful API responses.
Changed: FAQ connect instructions to reflect OAuth flow.
Changed: Site data sync frequency from twice daily to every 3 hours.
Fixed: Send site health data instead of legacy twice_daily hook on connect.

= 1.3.1: February 6, 2026 =
Fix: Use home_url() instead of get_site_url() for site URL detection.
Fix: Improved SSL detection for sites behind reverse proxies and load balancers.
Fix: Meta changes and updates.

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