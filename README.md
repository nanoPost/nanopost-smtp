# nanoPost SMTP

Zero-config email delivery for WordPress. Reliable SMTP without the setup headaches.

[![License: GPL v2](https://img.shields.io/badge/License-GPL_v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)

## Description

nanoPost SMTP provides reliable email delivery for your WordPress site with zero configuration required.

### Features

- **Automatic registration** - just activate and emails start flowing
- **Replaces wp_mail()** with reliable SMTP delivery
- **Domain verification** support for improved deliverability
- **WP-CLI commands** for power users
- **Debug mode** for troubleshooting

### How it works

1. Activate the plugin
2. Your site automatically registers with nanoPost
3. All WordPress emails are now sent through our reliable infrastructure

No API keys to copy. No SMTP credentials to configure. It just works.

## Installation

1. Upload the `nanopost-smtp` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. That's it! Your site is automatically registered and ready to send emails

You can verify the connection at **Settings > nanoPost**.

## WP-CLI Commands

nanoPost SMTP includes full WP-CLI support for managing the plugin from the command line.

### `wp nanopost status`

Show registration status and configuration.

```bash
wp nanopost status
```

**Options:**

| Option | Description |
|--------|-------------|
| `--verify` | Perform round-trip verification with nanoPost API |

**Examples:**

```bash
# Show current status
wp nanopost status

# Verify API can reach this site
wp nanopost status --verify
```

### `wp nanopost register`

Register or re-register with the nanoPost API.

```bash
wp nanopost register
```

**Options:**

| Option | Description |
|--------|-------------|
| `--force` | Force re-registration even if already registered |

**Examples:**

```bash
# Register (if not already registered)
wp nanopost register

# Force re-registration
wp nanopost register --force
```

### `wp nanopost test`

Send a test email via nanoPost.

```bash
wp nanopost test <email>
```

**Options:**

| Option | Description |
|--------|-------------|
| `--subject=<subject>` | Email subject (default: "nanoPost Test Email") |
| `--message=<message>` | Email body (default: "This is a test email sent via nanoPost.") |

**Examples:**

```bash
# Send test email with defaults
wp nanopost test user@example.com

# Send with custom subject and message
wp nanopost test user@example.com --subject="Hello" --message="Test message"
```

### `wp nanopost debug`

Enable or disable debug mode.

```bash
wp nanopost debug <on|off>
```

**Examples:**

```bash
# Enable debug logging
wp nanopost debug on

# Disable debug logging
wp nanopost debug off
```

When enabled, logs are written to `wp-content/debug.log`.

## FAQ

### Do I need to create an account?

No. Your site is automatically registered when you activate the plugin.

### I'm developing locally - will this work?

Your WordPress installation must be publicly accessible for nanoPost to work. We verify your site ownership via callback, which requires the API to reach your site.

**For local development**, use a tunneling service like [ngrok](https://ngrok.com), [Cloudflare Tunnel](https://developers.cloudflare.com/cloudflare-one/connections/connect-apps/), or [Expose](https://expose.dev) to make your local site accessible. Once you deploy to production, the plugin will automatically detect the domain change and prompt you to update.

### Does this work with multisite?

Per-site activation is supported. Network-wide activation is not currently supported - please activate the plugin on each subsite individually.

### How do I verify my domain?

Visit **Settings > nanoPost** and click "Verify Domain" to add a DNS TXT record for improved deliverability.

### Is there a free tier?

Yes! The free tier includes generous email limits suitable for most small sites.

## Changelog

### 0.7.0
- Added welcome banner on activation
- Added `--verify` flag for round-trip API verification
- Improved admin page UX

For older versions, see [changelog.txt](changelog.txt).

## License

This plugin is licensed under the [GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

## Links

- [nanoPost Website](https://nanopo.st)
- [Report Issues](https://github.com/nanoPost/nanopost-smtp/issues)
