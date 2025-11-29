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
     * ## EXAMPLES
     *
     *     wp nanopost status
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

        // Generate new site_secret
        $site_secret = bin2hex(random_bytes(32));
        update_option('nanopost_site_secret', $site_secret);

        $payload = [
            'domain' => site_url(),
            'admin_email' => get_option('admin_email'),
            'site_secret' => $site_secret,
        ];

        WP_CLI::log('Domain: ' . $payload['domain']);
        WP_CLI::log('Admin Email: ' . $payload['admin_email']);

        $response = wp_remote_post(NANOPOST_API_BASE . '/register', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($payload),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            WP_CLI::error('Request failed: ' . $response->get_error_message());
            return;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200 || empty($body['site_token'])) {
            WP_CLI::error('Registration failed (HTTP ' . $status . '): ' . json_encode($body));
            return;
        }

        update_option('nanopost_site_id', $body['site_id']);
        update_option('nanopost_site_token', $body['site_token']);
        delete_option('nanopost_needs_registration');

        $updated = !empty($body['updated']) ? ' (re-registered)' : '';
        WP_CLI::success('Registered successfully' . $updated);
        WP_CLI::log('Site ID: ' . $body['site_id']);
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
        $subject = WP_CLI\Utils\get_flag_value($assoc_args, 'subject', 'nanoPost Test Email');
        $message = WP_CLI\Utils\get_flag_value($assoc_args, 'message', 'This is a test email sent via nanoPost at ' . date('Y-m-d H:i:s'));

        if (!is_email($email)) {
            WP_CLI::error('Invalid email address: ' . $email);
            return;
        }

        $site_token = get_option('nanopost_site_token');
        if (!$site_token) {
            WP_CLI::error('Not registered. Run: wp nanopost register');
            return;
        }

        WP_CLI::log('Sending test email to: ' . $email);
        WP_CLI::log('Subject: ' . $subject);

        $result = wp_mail($email, $subject, $message);

        if ($result) {
            WP_CLI::success('Email sent successfully.');
        } else {
            WP_CLI::error('Email sending failed. Check debug log for details.');
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
