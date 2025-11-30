=== nanoPost SMTP ===
Contributors: nanopost
Tags: smtp, email, mail, wp_mail, email delivery
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 0.7.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Zero-config email delivery for WordPress. Reliable SMTP without the setup headaches.

== Description ==

nanoPost SMTP provides reliable email delivery for your WordPress site with zero configuration required.

**Features:**

* Automatic registration - just activate and emails start flowing
* Replaces wp_mail() with reliable SMTP delivery
* Domain verification support for improved deliverability
* WP-CLI commands for power users
* Debug mode for troubleshooting

**How it works:**

1. Activate the plugin
2. Your site automatically registers with nanoPost
3. All WordPress emails are now sent through our reliable infrastructure

No API keys to copy. No SMTP credentials to configure. It just works.

== Installation ==

1. Upload the `nanopost-smtp` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. That's it! Your site is automatically registered and ready to send emails

You can verify the connection at Settings > nanoPost.

== Frequently Asked Questions ==

= Do I need to create an account? =

No. Your site is automatically registered when you activate the plugin.

= Does this work with multisite? =

Per-site activation is supported. Network-wide activation is not currently supported - please activate the plugin on each subsite individually.

= How do I verify my domain? =

Visit Settings > nanoPost and click "Verify Domain" to add a DNS TXT record for improved deliverability.

= Is there a free tier? =

Yes! The free tier includes generous email limits suitable for most small sites.

== Screenshots ==

1. Settings page showing connection status
2. Domain verification interface

== Changelog ==

= 0.7.0 =
* Added welcome banner on activation
* Improved admin page UX

= 0.6.0 =
* Added domain change detection
* Added debug mode toggle
* WP-CLI commands for site management

= 0.5.0 =
* Initial release
* Automatic site registration
* wp_mail replacement
* Domain verification support
* WP-CLI integration

== Upgrade Notice ==

= 0.7.0 =
Improved activation experience with welcome banner.
