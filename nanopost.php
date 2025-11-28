<?php
/**
 * Plugin Name: nanoPost
 * Description: Zero-config email delivery for WordPress
 * Version: 0.2.0
 * Author: nanoPost
 */

defined('ABSPATH') || exit;

define('NANOPOST_API_BASE', 'https://api-master-ja5zao.laravel.cloud/api');

/**
 * Auto-register with nanoPost API on plugin activation
 */
register_activation_hook(__FILE__, function () {
    // Skip if already registered
    if (get_option('nanopost_site_token')) {
        return;
    }

    $response = wp_remote_post(NANOPOST_API_BASE . '/register', [
        'timeout' => 30,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode([
            'domain' => site_url(),
            'admin_email' => get_option('admin_email'),
        ]),
    ]);

    if (!is_wp_error($response)) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['site_token'])) {
            update_option('nanopost_site_token', $body['site_token']);
            update_option('nanopost_site_id', $body['site_id']);
            update_option('nanopost_api_url', NANOPOST_API_BASE . '/mail');
        }
    }
});

/**
 * Hijack wp_mail() and send via nanoPost API
 */
add_filter('pre_wp_mail', function ($null, $atts) {
    $api_url = get_option('nanopost_api_url', 'https://api-master-ja5zao.laravel.cloud/api/mail');
    $site_token = get_option('nanopost_site_token', '');

    if (empty($site_token)) {
        error_log('nanoPost: No site_token configured');
        return null; // Fall back to default wp_mail
    }

    // Handle recipient (can be string or array)
    $to = is_array($atts['to']) ? implode(',', $atts['to']) : $atts['to'];

    $response = wp_remote_post($api_url, [
        'timeout' => 30,
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ],
        'body' => json_encode([
            'site_token' => $site_token,
            'site_url' => site_url(),
            'to' => $to,
            'subject' => ($atts['subject'] ?? '(no subject)') . ' [' . substr(md5(time()), 0, 6) . ']',
            'message' => $atts['message'] ?? '',
            'from_name' => get_bloginfo('name'),
        ]),
    ]);

    if (is_wp_error($response)) {
        error_log('nanoPost: API error - ' . $response->get_error_message());
        return null; // Fall back to default wp_mail
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (!empty($body['success'])) {
        return true; // Email sent successfully
    }

    error_log('nanoPost: API returned error - ' . json_encode($body));
    return null; // Fall back to default wp_mail
}, 10, 2);

/**
 * Admin settings page
 */
add_action('admin_menu', function () {
    add_options_page(
        'nanoPost Settings',
        'nanoPost',
        'manage_options',
        'nanopost',
        'nanopost_settings_page'
    );
});

function nanopost_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('nanopost_settings')) {
        // Handle re-registration request
        if (isset($_POST['nanopost_register'])) {
            $response = wp_remote_post(NANOPOST_API_BASE . '/register', [
                'timeout' => 30,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode([
                    'domain' => site_url(),
                    'admin_email' => get_option('admin_email'),
                ]),
            ]);

            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($body['site_token'])) {
                    update_option('nanopost_site_token', $body['site_token']);
                    update_option('nanopost_site_id', $body['site_id']);
                    update_option('nanopost_api_url', NANOPOST_API_BASE . '/mail');
                    echo '<div class="notice notice-success"><p>Registered successfully!</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Registration failed: ' . esc_html($body['error'] ?? 'Unknown error') . '</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>Registration failed: ' . esc_html($response->get_error_message()) . '</p></div>';
            }
        }

        // Send test email if requested
        if (isset($_POST['nanopost_test']) && !empty($_POST['nanopost_test_email'])) {
            $test_to = sanitize_email($_POST['nanopost_test_email']);
            $result = wp_mail($test_to, 'nanoPost Test', 'This is a test email from nanoPost.');
            if ($result) {
                echo '<div class="notice notice-success"><p>Test email sent to ' . esc_html($test_to) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to send test email. Check error log.</p></div>';
            }
        }
    }

    $site_token = get_option('nanopost_site_token', '');
    $site_id = get_option('nanopost_site_id', '');
    ?>
    <div class="wrap">
        <h1>nanoPost Settings</h1>

        <h2>Registration Status</h2>
        <form method="post">
            <?php wp_nonce_field('nanopost_settings'); ?>
            <table class="form-table">
                <tr>
                    <th>Status</th>
                    <td>
                        <?php if ($site_token): ?>
                            <span style="color: green; font-weight: bold;">Registered</span>
                        <?php else: ?>
                            <span style="color: red; font-weight: bold;">Not registered</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($site_id): ?>
                <tr>
                    <th>Site ID</th>
                    <td><code><?php echo esc_html($site_id); ?></code></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th></th>
                    <td>
                        <input type="submit" name="nanopost_register" class="button"
                               value="<?php echo $site_token ? 'Re-register' : 'Register Now'; ?>">
                        <p class="description">
                            <?php echo $site_token ? 'Generate a new token (invalidates old one)' : 'Connect this site to nanoPost'; ?>
                        </p>
                    </td>
                </tr>
            </table>
        </form>

        <h2>Test Email</h2>
        <form method="post">
            <?php wp_nonce_field('nanopost_settings'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="nanopost_test_email">Send To</label></th>
                    <td>
                        <input type="email" id="nanopost_test_email" name="nanopost_test_email"
                               placeholder="your@email.com" class="regular-text">
                        <input type="submit" name="nanopost_test" class="button" value="Send Test Email">
                    </td>
                </tr>
            </table>
        </form>
    </div>
    <?php
}

/**
 * Register settings
 */
add_action('admin_init', function () {
    register_setting('nanopost', 'nanopost_site_token');
    register_setting('nanopost', 'nanopost_api_url');
});
