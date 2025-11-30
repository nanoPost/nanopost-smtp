<?php
/**
 * Registration logic for nanoPost API.
 */

defined('ABSPATH') || exit;

/**
 * Handle plugin activation.
 * Generates site_secret and schedules registration.
 */
function nanopost_activate() {
    // Generate site_secret if not exists
    if (!get_option('nanopost_site_secret')) {
        update_option('nanopost_site_secret', bin2hex(random_bytes(32)));
    }

    if (!get_option('nanopost_site_token')) {
        update_option('nanopost_needs_registration', true);
    }

    // Flag for redirect to welcome page (only on fresh install)
    if (!get_option('nanopost_site_token')) {
        add_option('nanopost_activation_redirect', true);
    }
}

/**
 * Auto-register with nanoPost API on init.
 */
add_action('init', function () {
    if (!get_option('nanopost_needs_registration')) {
        return;
    }

    nanopost_debug("=== AUTO-REGISTRATION START ===");

    // Clear the flag first to prevent repeated attempts
    delete_option('nanopost_needs_registration');

    $result = nanopost_register_site();

    if ($result['success']) {
        nanopost_debug("=== AUTO-REGISTRATION SUCCESS ===");
    } else {
        nanopost_debug("=== AUTO-REGISTRATION FAILED: {$result['error']} ===");
    }
});

/**
 * Register or re-register site with nanoPost API.
 *
 * @param bool $regenerate_secret Whether to generate a new site_secret.
 * @return array Result with 'success' boolean and 'error' or 'data' keys.
 */
function nanopost_register_site($regenerate_secret = false) {
    // Generate new secret if requested or if none exists
    $site_secret = get_option('nanopost_site_secret');
    if ($regenerate_secret || !$site_secret) {
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
        return [
            'success' => false,
            'error' => $response->get_error_message(),
        ];
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
        update_option('nanopost_registered_domain', site_url());
        if (!empty($body['from_address'])) {
            update_option('nanopost_from_address', $body['from_address']);
        }

        nanopost_debug("New site_id: {$body['site_id']}");
        nanopost_debug("New site_token: " . substr($body['site_token'], 0, 10) . "...");

        return [
            'success' => true,
            'data' => $body,
        ];
    }

    return [
        'success' => false,
        'error' => $body['error'] ?? 'Unknown error',
    ];
}

/**
 * Verify API callback (round-trip verification).
 *
 * Asks the nanoPost API to call back to this site's verify endpoint,
 * confirming that the API can reach this WordPress installation.
 *
 * @return array Result with 'success' boolean and 'error'/'details' keys on failure.
 */
function nanopost_verify_callback() {
    $site_token = get_option('nanopost_site_token');

    if (empty($site_token)) {
        return [
            'success' => false,
            'error' => 'Not registered',
        ];
    }

    $request_payload = [
        'site_token' => $site_token,
    ];

    nanopost_debug("Request URL: " . NANOPOST_API_BASE . '/site/verify-callback');

    $response = wp_remote_post(NANOPOST_API_BASE . '/site/verify-callback', [
        'timeout' => 30,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode($request_payload),
    ]);

    if (is_wp_error($response)) {
        nanopost_debug("Response error: " . $response->get_error_message());
        return [
            'success' => false,
            'error' => $response->get_error_message(),
        ];
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $raw_body = wp_remote_retrieve_body($response);
    $body = json_decode($raw_body, true);

    nanopost_debug("Response HTTP status: {$status_code}");
    nanopost_debug("Response body: {$raw_body}");

    if ($status_code === 200 && !empty($body['success'])) {
        return [
            'success' => true,
            'data' => $body,
        ];
    }

    return [
        'success' => false,
        'error' => $body['error'] ?? 'Verification failed',
        'details' => $body['details'] ?? null,
    ];
}

/**
 * Update domain with nanoPost API.
 *
 * @return array Result with 'success' boolean and 'error' or 'data' keys.
 */
function nanopost_update_domain() {
    $site_token = get_option('nanopost_site_token');

    if (empty($site_token)) {
        return [
            'success' => false,
            'error' => 'Not registered',
        ];
    }

    $request_payload = [
        'site_token' => $site_token,
        'new_domain' => site_url(),
    ];

    nanopost_debug("Request URL: " . NANOPOST_API_BASE . '/site/update-domain');
    nanopost_debug("Request payload: " . json_encode($request_payload));

    $response = wp_remote_post(NANOPOST_API_BASE . '/site/update-domain', [
        'timeout' => 30,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode($request_payload),
    ]);

    if (is_wp_error($response)) {
        nanopost_debug("Response error: " . $response->get_error_message());
        return [
            'success' => false,
            'error' => $response->get_error_message(),
        ];
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $raw_body = wp_remote_retrieve_body($response);
    $body = json_decode($raw_body, true);

    nanopost_debug("Response HTTP status: {$status_code}");
    nanopost_debug("Response body: {$raw_body}");

    if ($status_code === 200) {
        update_option('nanopost_registered_domain', site_url());

        return [
            'success' => true,
            'data' => $body,
        ];
    }

    return [
        'success' => false,
        'error' => $body['error'] ?? 'Unknown error',
    ];
}
