<?php
/**
 * Plugin Name: nanoPost SMTP
 * Plugin URI: https://nanopo.st
 * Description: Zero-config email delivery for WordPress
 * Version: 0.5.0
 * Author: nanoPost
 * Author URI: https://nanopo.st
 * Text Domain: nanopost-smtp
 */

defined('ABSPATH') || exit;

define('NANOPOST_API_BASE', 'https://api-master-ja5zao.laravel.cloud/api');

// Load WP-CLI commands
if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__ . '/cli.php';
}

/**
 * Debug logging - only logs when debug mode is enabled
 */
function nanopost_debug($message) {
    if (get_option('nanopost_debug_mode', false)) {
        error_log('nanoPost: ' . $message);
    }
}

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

            nanopost_debug("verify-recipient called for: {$email}");

            // Prevent caching
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');

            // Verify signature exists
            if (empty($signature) || empty($timestamp) || empty($site_secret)) {
                nanopost_debug("verify-recipient: missing signature, timestamp, or site_secret");
                return new WP_Error('forbidden', 'Forbidden', ['status' => 403]);
            }

            // Check timestamp (within 5 minutes)
            if (abs(time() - intval($timestamp)) > 300) {
                nanopost_debug("verify-recipient: timestamp expired (diff=" . abs(time() - intval($timestamp)) . "s)");
                return new WP_Error('forbidden', 'Forbidden', ['status' => 403]);
            }

            // Verify HMAC signature
            $expected = hash_hmac('sha256', $email . $timestamp, $site_secret);
            if (!hash_equals($expected, $signature)) {
                nanopost_debug("verify-recipient: HMAC signature mismatch");
                return new WP_Error('forbidden', 'Forbidden', ['status' => 403]);
            }

            nanopost_debug("verify-recipient: signature valid, checking recipient");

            // Signature valid - now check recipient
            if (empty($email) || !is_email($email)) {
                nanopost_debug("verify-recipient: invalid email format");
                return ['allowed' => false, 'reason' => 'Invalid email'];
            }

            // Check if email belongs to a WP user
            if (email_exists($email)) {
                nanopost_debug("verify-recipient: {$email} is a WordPress user - ALLOWED");
                return ['allowed' => true, 'reason' => 'WordPress user'];
            }

            // Check if it's the admin email
            if ($email === get_option('admin_email')) {
                nanopost_debug("verify-recipient: {$email} is admin email - ALLOWED");
                return ['allowed' => true, 'reason' => 'Admin email'];
            }

            nanopost_debug("verify-recipient: {$email} not a WordPress user - DENIED");
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

    nanopost_debug("=== AUTO-REGISTRATION START ===");

    // Clear the flag first to prevent repeated attempts
    delete_option('nanopost_needs_registration');

    // Ensure site_secret exists
    $site_secret = get_option('nanopost_site_secret');
    if (!$site_secret) {
        $site_secret = bin2hex(random_bytes(32));
        update_option('nanopost_site_secret', $site_secret);
        nanopost_debug("Generated new site_secret: " . substr($site_secret, 0, 10) . "...");
    }

    $request_payload = [
        'domain' => site_url(),
        'admin_email' => get_option('admin_email'),
        'site_secret' => $site_secret,
    ];

    nanopost_debug("Request URL: " . NANOPOST_API_BASE . '/register');
    nanopost_debug("Request payload: " . json_encode($request_payload));

    $response = wp_remote_post(NANOPOST_API_BASE . '/register', [
        'timeout' => 30,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode($request_payload),
    ]);

    if (is_wp_error($response)) {
        nanopost_debug("Response error: " . $response->get_error_message());
        nanopost_debug("=== AUTO-REGISTRATION FAILED (WP error) ===");
        return;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $raw_body = wp_remote_retrieve_body($response);
    $body = json_decode($raw_body, true);

    nanopost_debug("Response HTTP status: {$status_code}");
    nanopost_debug("Response body: {$raw_body}");

    if (!empty($body['site_token'])) {
        update_option('nanopost_site_token', $body['site_token']);
        update_option('nanopost_site_id', $body['site_id']);
        update_option('nanopost_api_url', NANOPOST_API_BASE . '/mail');

        nanopost_debug("New site_id: {$body['site_id']}");
        nanopost_debug("New site_token: " . substr($body['site_token'], 0, 10) . "...");
        nanopost_debug("=== AUTO-REGISTRATION SUCCESS ===");
    } else {
        nanopost_debug("=== AUTO-REGISTRATION FAILED (no token in response) ===");
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

    // Handle recipient (can be string or array)
    $to = is_array($atts['to']) ? implode(',', $atts['to']) : $atts['to'];
    $subject = $atts['subject'] ?? '(no subject)';

    nanopost_debug("wp_mail intercepted: to={$to}, subject={$subject}");

    if (empty($site_token)) {
        error_log('nanoPost: No site_token configured - cannot send');
        return false;
    }

    // Parse headers
    $headers = nanopost_parse_headers($atts['headers'] ?? []);
    nanopost_debug("Parsed headers: content_type={$headers['content_type']}, from={$headers['from']}, reply_to={$headers['reply_to']}");

    if (!empty($headers['cc'])) {
        nanopost_debug("CC recipients: " . implode(', ', $headers['cc']));
    }
    if (!empty($headers['bcc'])) {
        nanopost_debug("BCC recipients: " . implode(', ', $headers['bcc']));
    }

    // Build payload
    $payload = [
        'site_token' => $site_token,
        'site_url' => site_url(),
        'to' => $to,
        'subject' => $subject,
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

    nanopost_debug("Sending to API: {$api_url}");
    nanopost_debug("Payload: " . json_encode($payload));

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
        nanopost_debug("API request failed: " . $response->get_error_message());
        nanopost_debug("=== MAIL SEND FAILED (WP error) ===");
        return false;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $raw_body = wp_remote_retrieve_body($response);
    $body = json_decode($raw_body, true);

    nanopost_debug("Response HTTP status: {$status_code}");
    nanopost_debug("Response body: {$raw_body}");

    if (!empty($body['success'])) {
        nanopost_debug("=== MAIL SEND SUCCESS ===");
        return true;
    }

    error_log('nanoPost: API returned error - ' . $raw_body);
    nanopost_debug("=== MAIL SEND FAILED (API rejected) ===");
    return false;
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
            // Store previous values for debugging
            $old_site_id = get_option('nanopost_site_id', '(none)');
            $old_site_token = get_option('nanopost_site_token', '(none)');

            nanopost_debug("=== REGISTRATION START ===");
            nanopost_debug("Previous site_id: {$old_site_id}");
            nanopost_debug("Previous site_token: " . substr($old_site_token, 0, 10) . "...");

            // Generate new site_secret on re-registration
            $new_secret = bin2hex(random_bytes(32));
            update_option('nanopost_site_secret', $new_secret);
            nanopost_debug("Generated new site_secret: " . substr($new_secret, 0, 10) . "...");

            $request_payload = [
                'domain' => site_url(),
                'admin_email' => get_option('admin_email'),
                'site_secret' => $new_secret,
            ];

            nanopost_debug("Request URL: " . NANOPOST_API_BASE . '/register');
            nanopost_debug("Request payload: " . json_encode($request_payload));

            $response = wp_remote_post(NANOPOST_API_BASE . '/register', [
                'timeout' => 30,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($request_payload),
            ]);

            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
                $raw_body = wp_remote_retrieve_body($response);
                $body = json_decode($raw_body, true);

                nanopost_debug("Response HTTP status: {$status_code}");
                nanopost_debug("Response body: {$raw_body}");

                if (!empty($body['site_token'])) {
                    update_option('nanopost_site_token', $body['site_token']);
                    update_option('nanopost_site_id', $body['site_id']);
                    update_option('nanopost_api_url', NANOPOST_API_BASE . '/mail');

                    nanopost_debug("New site_id: {$body['site_id']}");
                    nanopost_debug("New site_token: " . substr($body['site_token'], 0, 10) . "...");
                    nanopost_debug("Updated flag: " . ($body['updated'] ?? 'false'));
                    nanopost_debug("=== REGISTRATION SUCCESS ===");

                    echo '<div class="notice notice-success"><p>Registered successfully!</p></div>';
                } else {
                    nanopost_debug("=== REGISTRATION FAILED (no token in response) ===");
                    echo '<div class="notice notice-error"><p>Registration failed: ' . esc_html($body['error'] ?? 'Unknown error') . '</p></div>';
                }
            } else {
                nanopost_debug("Response error: " . $response->get_error_message());
                nanopost_debug("=== REGISTRATION FAILED (WP error) ===");
                echo '<div class="notice notice-error"><p>Registration failed: ' . esc_html($response->get_error_message()) . '</p></div>';
            }
        }

        // Send test email if requested
        if (isset($_POST['nanopost_test']) && !empty($_POST['nanopost_test_email'])) {
            $test_to = sanitize_email($_POST['nanopost_test_email']);

            nanopost_debug("=== TEST EMAIL START ===");
            nanopost_debug("Recipient: {$test_to}");
            nanopost_debug("Site token configured: " . (get_option('nanopost_site_token') ? 'yes' : 'NO'));
            nanopost_debug("API URL: " . get_option('nanopost_api_url', '(not set)'));

            $result = wp_mail($test_to, 'nanoPost Test', 'This is a test email from nanoPost.');

            if ($result) {
                nanopost_debug("=== TEST EMAIL SUCCESS ===");
                echo '<div class="notice notice-success"><p>Test email sent to ' . esc_html($test_to) . '</p></div>';
            } else {
                nanopost_debug("=== TEST EMAIL FAILED ===");
                echo '<div class="notice notice-error"><p>Failed to send test email. Check error log.</p></div>';
            }
        }

        // Handle debug mode toggle
        if (isset($_POST['nanopost_save_settings'])) {
            $debug_mode = isset($_POST['nanopost_debug_mode']) ? true : false;
            update_option('nanopost_debug_mode', $debug_mode);
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
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

        <h2>Debug Settings</h2>
        <form method="post">
            <?php wp_nonce_field('nanopost_settings'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="nanopost_debug_mode">Debug Mode</label></th>
                    <td>
                        <label>
                            <input type="checkbox" id="nanopost_debug_mode" name="nanopost_debug_mode"
                                   <?php checked(get_option('nanopost_debug_mode', false)); ?>>
                            Enable debug logging
                        </label>
                        <p class="description">
                            Writes detailed trace messages to the PHP error log. Useful for troubleshooting.
                        </p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="nanopost_save_settings" class="button button-primary" value="Save Settings">
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
