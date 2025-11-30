<?php
/**
 * Mail handling - intercepts wp_mail() and sends via nanoPost API.
 */

defined('ABSPATH') || exit;

/**
 * Send a test email via nanoPost.
 *
 * @param string $to      Recipient email address.
 * @param string $subject Email subject (optional).
 * @param string $message Email body (optional).
 * @return array Result with 'success' boolean and 'error' key on failure.
 */
function nanopost_send_test_email($to, $subject = null, $message = null) {
    $subject = $subject ?? 'nanoPost Test Email';
    $message = $message ?? 'This is a test email sent via nanoPost at ' . date('Y-m-d H:i:s');

    if (!get_option('nanopost_site_token')) {
        return [
            'success' => false,
            'error' => 'Not registered',
        ];
    }

    nanopost_debug("=== TEST EMAIL START ===");
    nanopost_debug("Recipient: {$to}");
    nanopost_debug("Site token configured: yes");
    nanopost_debug("API URL: " . get_option('nanopost_api_url', NANOPOST_API_BASE . '/mail'));

    $result = wp_mail($to, $subject, $message);

    if ($result) {
        nanopost_debug("=== TEST EMAIL SUCCESS ===");
        return ['success' => true];
    }

    nanopost_debug("=== TEST EMAIL FAILED ===");
    return [
        'success' => false,
        'error' => 'wp_mail() returned false',
    ];
}

/**
 * Parse wp_mail headers (string or array) into associative array.
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
 * Hijack wp_mail() and send via nanoPost API.
 */
add_filter('pre_wp_mail', 'nanopost_handle_mail', 10, 2);

function nanopost_handle_mail($null, $atts) {
    $api_url = get_option('nanopost_api_url', NANOPOST_API_BASE . '/mail');
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

    // Accept both "success" (sync) and "queued" (async) responses
    if (!empty($body['success']) || !empty($body['queued'])) {
        $email_log_id = $body['email_log_id'] ?? null;
        nanopost_debug("Email log ID: " . ($email_log_id ?: 'n/a'));
        nanopost_debug("=== MAIL SEND SUCCESS ===");
        return true;
    }

    error_log('nanoPost: API returned error - ' . $raw_body);
    nanopost_debug("=== MAIL SEND FAILED (API rejected) ===");
    return false;
}
