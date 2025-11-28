<?php
/**
 * Plugin Name: nanoPost
 * Description: Zero-config email delivery for WordPress
 * Version: 0.1.0-poc
 * Author: nanoPost
 */

defined('ABSPATH') || exit;

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
            'subject' => $atts['subject'] ?? '(no subject)',
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
        // Always save settings
        update_option('nanopost_site_token', sanitize_text_field($_POST['nanopost_site_token'] ?? ''));
        update_option('nanopost_api_url', esc_url_raw($_POST['nanopost_api_url'] ?? ''));

        if (isset($_POST['nanopost_save'])) {
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
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
    $api_url = get_option('nanopost_api_url', 'https://api-master-ja5zao.laravel.cloud/api/mail');
    ?>
    <div class="wrap">
        <h1>nanoPost Settings</h1>
        <form method="post">
            <?php wp_nonce_field('nanopost_settings'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="nanopost_site_token">Site Token</label></th>
                    <td>
                        <input type="text" id="nanopost_site_token" name="nanopost_site_token"
                               value="<?php echo esc_attr($site_token); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label for="nanopost_api_url">API URL</label></th>
                    <td>
                        <input type="url" id="nanopost_api_url" name="nanopost_api_url"
                               value="<?php echo esc_attr($api_url); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label for="nanopost_test_email">Test Email</label></th>
                    <td>
                        <input type="email" id="nanopost_test_email" name="nanopost_test_email"
                               placeholder="your@email.com" class="regular-text">
                        <input type="submit" name="nanopost_test" class="button" value="Send Test Email">
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="nanopost_save" class="button-primary" value="Save Settings">
            </p>
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
