=== Scanfully ===
Contributors: barrykooij,defries,scanfully
Donate link: https://scanfully.com
Tags: scanfully, performance, monitoring, site health
Requires at least: 6.0
Tested up to: 6.5.3
Stable tag: 1.2.5
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Requires PHP: 7.4

Scanfully is your favorite WordPress performance and site health monitoring tool.

== Description ==

## SCANFULLY FOR WORDPRESS 

Scanfully is your favorite WordPress Performance and Site Health monitoring tool. This plugin connects your WordPress site to your [Scanfully](https://scanfully.com) dashboard and bridges the two. It syncs critical information from your WordPress site to our dashboard as well as it collects the changes happening in your WordPress site. Combined with our various performance-related scans, Scanfully offers you insight into a fully encompasing overview of your site's health.

## ONE DASHBOARD TO RULE THEM ALL 

Your Scanfully Dashboard consolidates all your WordPress sites, sending you timely alerts for required changes.

Easily connect changes made inside your WordPress site to performance and site health impact. Get notifications for the events that are important to you. Right when they happen. Exactly when you want to know they happen.

### SCANFULLY FEATURES

Scanfully helps you stay on top of your WordPress Site Health and Performance in many ways. Let’s take a look at what we have available:

#### SINGLE DASHBOARD
**All your sites in one dashboard** allowing you to easily navigate to the various monitoring features.

#### UPTIME MONITORING 
Scanfully checks your WordPress sites with **comprehensive uptime monitoring** and **smart notifications**

#### PERFORMANCE MONITORING
We do fequent **Performance Checks** to measure how fast your site loads, and provide you with an easy to read graph and recommendations

#### SITE HEALTH
**One Site Health dashboard to rule them all**. We collect and import all of your WordPress site’s health data in one view. Easy insights into the site health metrics that matter the most for your site.

#### WORDPRESS EVENTS TIMELINE
Our WordPress Events Timeline **collects all changes happening inside your WordPress admin**. All these events combined with our checks provide you a unique insight into what’s going on. No longer do you have to guess what change caused the problem your client just reported and insisted he didn't do anything to cause it.

#### SMART NOTIFICATIONS
Scanfully's smart notification systems allows you to define where you want to receive [whatever kind of notification you prefer](https://scanfully.com/docs/channels/). We currently offer Slack, Discord, email, and Pushover. 

#### LIGHTHOUSE SCANS (coming soon)
Automated insights into the performance, accessibility, and quality of your website in one place

#### VULNERABILITTY SCANS (coming soon)
You will want to know as soon as possible when your WordPress site has a vulnerable plugin or theme, right? Well, that's exactly what you'll be receiving notification for when we launch this feature. 

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
In order for your website to securely communicate with your Scanfully dashboard, we need your site's API keys. Your site API details can be found in your [Scanfully dashboard](https://dashboard.scanfully.com/sites?utm_source=wp-plugin-repo&utm_medium=link&utm_campaign=more-information). Copy and paste these details in the Scanfully settings screen in your WordPress admin panel.

= Where's the Scanfully settings screen? =
Settings > Scanfully.

= Does the plugin impact my page speed? =
No, our plugin on listens to changes in the WordPress backend and sends these changes to the Scanfully server. It does not impact your frontend or page load speed.

== Screenshots ==
1. The Scanfully settings screen.

== Changelog ==

= 1.2.6 : Jun XX, 2024 =
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