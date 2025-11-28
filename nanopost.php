<?php
/**
 * Plugin Name: nanoPost
 * Description: Zero-config email delivery for WordPress
 * Version: 0.4.0
 * Author: nanoPost
 */

defined('ABSPATH') || exit;

define('NANOPOST_API_BASE', 'https://api-master-ja5zao.laravel.cloud/api');

/**
 * REST API endpoints for verification
 */
add_action('rest_api_init', function () {
    // Domain verification (Stage 3)
    register_rest_route('nanopost/v1', '/verify', [
        'methods' => 'GET',
        'callback' => function ($request) {
            $challenge = $request->get_param('challenge');

            if (empty($challenge)) {
                return new WP_Error('missing_challenge', 'Challenge required', ['status' => 400]);
            }

            // Prevent caching of verification responses
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');

            // Echo back the challenge to prove plugin is installed
            return [
                'challenge' => $challenge,
                'site_url' => site_url(),
            ];
        },
        'permission_callback' => '__return_true',
    ]);

    // Recipient verification (Stage 5) - HMAC protected
    register_rest_route('nanopost/v1', '/verify-recipient', [
        'methods' => 'GET',
        'callback' => function ($request) {
            $email = $request->get_param('email');
            $timestamp = $request->get_param('timestamp');
            $signature = $request->get_param('signature');
            $site_secret = get_option('nanopost_site_secret');

            // Prevent caching
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');

            // Verify signature exists
            if (empty($signature) || empty($timestamp) || empty($site_secret)) {
                return new WP_Error('forbidden', 'Forbidden', ['status' => 403]);
            }

            // Check timestamp (within 5 minutes)
            if (abs(time() - intval($timestamp)) > 300) {
                return new WP_Error('forbidden', 'Forbidden', ['status' => 403]);
            }

            // Verify HMAC signature
            $expected = hash_hmac('sha256', $email . $timestamp, $site_secret);
            if (!hash_equals($expected, $signature)) {
                return new WP_Error('forbidden', 'Forbidden', ['status' => 403]);
            }

            // Signature valid - now check recipient
            if (empty($email) || !is_email($email)) {
                return ['allowed' => false, 'reason' => 'Invalid email'];
            }

            // Check if email belongs to a WP user
            if (email_exists($email)) {
                return ['allowed' => true, 'reason' => 'WordPress user'];
            }

            // Check if it's the admin email
            if ($email === get_option('admin_email')) {
                return ['allowed' => true, 'reason' => 'Admin email'];
            }

            return ['allowed' => false, 'reason' => 'Not a WordPress user'];
        },
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Schedule registration on plugin activation
 * (Actual registration happens after init when REST API is available)
 */
register_activation_hook(__FILE__, function () {
    // Generate site_secret if not exists
    if (!get_option('nanopost_site_secret')) {
        update_option('nanopost_site_secret', bin2hex(random_bytes(32)));
    }

    if (!get_option('nanopost_site_token')) {
        update_option('nanopost_needs_registration', true);
    }
});

/**
 * Auto-register with nanoPost API (runs after init)
 */
add_action('init', function () {
    if (!get_option('nanopost_needs_registration')) {
        return;
    }

    // Clear the flag first to prevent repeated attempts
    delete_option('nanopost_needs_registration');

    // Ensure site_secret exists
    if (!get_option('nanopost_site_secret')) {
        update_option('nanopost_site_secret', bin2hex(random_bytes(32)));
    }

    $response = wp_remote_post(NANOPOST_API_BASE . '/register', [
        'timeout' => 30,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode([
            'domain' => site_url(),
            'admin_email' => get_option('admin_email'),
            'site_secret' => get_option('nanopost_site_secret'),
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
 * Parse wp_mail headers (string or array) into associative array
 */
function nanopost_parse_headers($headers) {
    $parsed = [
        'from' => '',
        'from_name' => '',
        'reply_to' => '',
        'cc' => [],
        'bcc' => [],
        'content_type' => 'text/plain',
    ];

    if (empty($headers)) {
        return $parsed;
    }

    // Convert string headers to array
    if (!is_array($headers)) {
        $headers = explode("\n", str_replace("\r\n", "\n", $headers));
    }

    foreach ($headers as $header) {
        if (strpos($header, ':') === false) {
            continue;
        }

        list($name, $value) = explode(':', $header, 2);
        $name = strtolower(trim($name));
        $value = trim($value);

        switch ($name) {
            case 'from':
                // Parse "Name <email>" or just "email"
                if (preg_match('/^(.+?)\s*<(.+?)>$/', $value, $matches)) {
                    $parsed['from_name'] = trim($matches[1], ' "\'');
                    $parsed['from'] = $matches[2];
                } else {
                    $parsed['from'] = $value;
                }
                break;
            case 'reply-to':
                $parsed['reply_to'] = $value;
                break;
            case 'cc':
                $parsed['cc'][] = $value;
                break;
            case 'bcc':
                $parsed['bcc'][] = $value;
                break;
            case 'content-type':
                if (stripos($value, 'text/html') !== false) {
                    $parsed['content_type'] = 'text/html';
                }
                break;
        }
    }

    return $parsed;
}

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

    // Parse headers
    $headers = nanopost_parse_headers($atts['headers'] ?? []);

    // Build payload
    $payload = [
        'site_token' => $site_token,
        'site_url' => site_url(),
        'to' => $to,
        'subject' => $atts['subject'] ?? '(no subject)',
        'message' => $atts['message'] ?? '',
        'content_type' => $headers['content_type'],
        'from_name' => get_bloginfo('name'),
    ];

    // Include original from address (will become reply-to on API side)
    if (!empty($headers['from'])) {
        $payload['original_from'] = $headers['from'];
        if (!empty($headers['from_name'])) {
            $payload['original_from_name'] = $headers['from_name'];
        }
    }

    // Include reply-to if explicitly set
    if (!empty($headers['reply_to'])) {
        $payload['reply_to'] = $headers['reply_to'];
    }

    // Include CC/BCC if present
    if (!empty($headers['cc'])) {
        $payload['cc'] = implode(',', $headers['cc']);
    }
    if (!empty($headers['bcc'])) {
        $payload['bcc'] = implode(',', $headers['bcc']);
    }

    $response = wp_remote_post($api_url, [
        'timeout' => 30,
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ],
        'body' => json_encode($payload),
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
            // Generate new site_secret on re-registration
            update_option('nanopost_site_secret', bin2hex(random_bytes(32)));

            $response = wp_remote_post(NANOPOST_API_BASE . '/register', [
                'timeout' => 30,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode([
                    'domain' => site_url(),
                    'admin_email' => get_option('admin_email'),
                    'site_secret' => get_option('nanopost_site_secret'),
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
