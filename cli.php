<?php
/**
 * WP-CLI commands for nanoPost SMTP
 *
 * @package nanoPost
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * Manage nanoPost SMTP plugin.
 */
class NanoPost_CLI {

    /**
     * Show nanoPost registration status.
     *
     * ## OPTIONS
     *
     * [--verify]
     * : Perform round-trip verification with nanoPost API.
     *
     * ## EXAMPLES
     *
     *     wp nanopost status
     *     wp nanopost status --verify
     *
     * @when after_wp_load
     */
    public function status($args, $assoc_args) {
        $site_id = get_option('nanopost_site_id');
        $site_token = get_option('nanopost_site_token');
        $site_secret = get_option('nanopost_site_secret');
        $debug_mode = get_option('nanopost_debug_mode', false);

        WP_CLI::log('nanoPost Status');
        WP_CLI::log('---------------');
        WP_CLI::log('Site ID:     ' . ($site_id ?: '(not registered)'));
        WP_CLI::log('Site Token:  ' . ($site_token ? substr($site_token, 0, 12) . '...' : '(not registered)'));
        WP_CLI::log('Site Secret: ' . ($site_secret ? substr($site_secret, 0, 12) . '...' : '(not set)'));
        WP_CLI::log('Debug Mode:  ' . ($debug_mode ? 'enabled' : 'disabled'));
        WP_CLI::log('API Base:    ' . NANOPOST_API_BASE);

        if ($site_id && $site_token) {
            WP_CLI::success('Plugin is registered.');
        } else {
            WP_CLI::warning('Plugin is not registered. Run: wp nanopost register');
            return;
        }

        // Perform round-trip verification if requested
        $verify = WP_CLI\Utils\get_flag_value($assoc_args, 'verify', false);
        if ($verify) {
            WP_CLI::log('');
            WP_CLI::log('Verifying API callback...');
            $result = nanopost_verify_callback();

            if ($result['success']) {
                WP_CLI::success('API can reach this site (round-trip verified)');
            } else {
                WP_CLI::warning('API callback failed: ' . $result['error']);
                if (!empty($result['details'])) {
                    WP_CLI::log('Details: ' . $result['details']);
                }
            }
        }
    }

    /**
     * Register or re-register with nanoPost API.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Force re-registration even if already registered.
     *
     * ## EXAMPLES
     *
     *     wp nanopost register
     *     wp nanopost register --force
     *
     * @when after_wp_load
     */
    public function register($args, $assoc_args) {
        $force = WP_CLI\Utils\get_flag_value($assoc_args, 'force', false);

        $site_token = get_option('nanopost_site_token');
        if ($site_token && !$force) {
            WP_CLI::warning('Already registered. Use --force to re-register.');
            return;
        }

        WP_CLI::log('Registering with nanoPost API...');
        WP_CLI::log('Domain: ' . site_url());
        WP_CLI::log('Admin Email: ' . get_option('admin_email'));

        $result = nanopost_register_site($force);

        if ($result['success']) {
            $updated = !empty($result['data']['updated']) ? ' (re-registered)' : '';
            WP_CLI::success('Registered successfully' . $updated);
            WP_CLI::log('Site ID: ' . $result['data']['site_id']);
        } else {
            WP_CLI::error('Registration failed: ' . $result['error']);
        }
    }

    /**
     * Send a test email via nanoPost.
     *
     * ## OPTIONS
     *
     * <email>
     * : The recipient email address.
     *
     * [--subject=<subject>]
     * : Email subject. Default: "nanoPost Test Email"
     *
     * [--message=<message>]
     * : Email body. Default: "This is a test email sent via nanoPost."
     *
     * ## EXAMPLES
     *
     *     wp nanopost test adam@example.com
     *     wp nanopost test adam@example.com --subject="Hello" --message="Test message"
     *
     * @when after_wp_load
     */
    public function test($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Email address required.');
            return;
        }

        $email = $args[0];
        $subject = WP_CLI\Utils\get_flag_value($assoc_args, 'subject', null);
        $message = WP_CLI\Utils\get_flag_value($assoc_args, 'message', null);

        if (!is_email($email)) {
            WP_CLI::error('Invalid email address: ' . $email);
            return;
        }

        WP_CLI::log('Sending test email to: ' . $email);
        WP_CLI::log('Subject: ' . ($subject ?? 'nanoPost Test Email'));

        $result = nanopost_send_test_email($email, $subject, $message);

        if ($result['success']) {
            WP_CLI::success('Email sent successfully.');
        } else {
            WP_CLI::error('Email sending failed: ' . $result['error']);
        }
    }

    /**
     * Enable or disable debug mode.
     *
     * ## OPTIONS
     *
     * <action>
     * : Either "on" or "off".
     *
     * ## EXAMPLES
     *
     *     wp nanopost debug on
     *     wp nanopost debug off
     *
     * @when after_wp_load
     */
    public function debug($args, $assoc_args) {
        if (empty($args[0]) || !in_array($args[0], ['on', 'off'], true)) {
            WP_CLI::error('Usage: wp nanopost debug <on|off>');
            return;
        }

        $enable = $args[0] === 'on';
        update_option('nanopost_debug_mode', $enable);

        if ($enable) {
            WP_CLI::success('Debug mode enabled. Logs will be written to wp-content/debug.log');
        } else {
            WP_CLI::success('Debug mode disabled.');
        }
    }
}

WP_CLI::add_command('nanopost', 'NanoPost_CLI');
