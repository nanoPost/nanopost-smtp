<?php
/**
 * Plugin Name: nanoPost SMTP
 * Plugin URI: https://nanopo.st
 * Description: Zero-config email delivery for WordPress
 * Version: 0.8.0
 * Author: nanoPost
 * Author URI: https://nanopo.st
 * Text Domain: nanopost-smtp
 */

defined('ABSPATH') || exit;

// Plugin constants
define('NANOPOST_VERSION', '0.8.0');
define('NANOPOST_PLUGIN_DIR', __DIR__);

// API base - can be overridden in wp-config.php for staging/dev
if (!defined('NANOPOST_API_BASE')) {
    define('NANOPOST_API_BASE', 'https://api.nanopo.st/api');
}

// Sending domain - can be overridden in wp-config.php
if (!defined('NANOPOST_SENDING_DOMAIN')) {
    define('NANOPOST_SENDING_DOMAIN', 'sender.nanopo.st');
}

// Load core functionality
require_once __DIR__ . '/includes/rest-api.php';
require_once __DIR__ . '/includes/registration.php';
require_once __DIR__ . '/includes/mail-handler.php';
require_once __DIR__ . '/includes/admin-page.php';

// Load WP-CLI commands
if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__ . '/cli.php';
}

/**
 * Debug logging - only logs when debug mode is enabled.
 */
function nanopost_debug($message) {
    if (get_option('nanopost_debug_mode', false)) {
        error_log('nanoPost: ' . $message);
    }
}

/**
 * Convert domain URL to slug for email local part.
 *
 * Examples:
 *   https://example.com        → example-com
 *   https://example.com/site1/ → example-com-site1 (multisite subdirectory)
 *
 * @param string $url The site URL.
 * @return string The slugified domain.
 */
function nanopost_domain_to_slug($url) {
    $host = parse_url($url, PHP_URL_HOST);
    $path = parse_url($url, PHP_URL_PATH);

    if (!$host) {
        $host = preg_replace('#^https?://#', '', $url);
        $host = explode('/', $host)[0];
        $host = explode(':', $host)[0];
    }

    if (empty($host)) {
        return 'noreply';
    }

    $host = strtolower($host);
    $host = preg_replace('/^www\./', '', $host);
    if (function_exists('idn_to_ascii')) {
        $host = idn_to_ascii($host) ?: $host;
    }
    $slug = str_replace('.', '-', $host);

    // Include path for multisite subdirectory installs
    if ($path && $path !== '/') {
        $path = trim($path, '/');
        $path = str_replace('/', '-', $path);
        $slug .= '-' . $path;
    }

    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);

    return trim($slug, '-') ?: 'noreply';
}

/**
 * Get the From email address for outgoing emails.
 *
 * @return string The From address.
 */
function nanopost_get_from_address() {
    // Use stored value from API if available
    $stored = get_option('nanopost_from_address');
    if ($stored) {
        return $stored;
    }

    // Fallback to computed value
    $slug = nanopost_domain_to_slug(site_url());
    return $slug . '@' . NANOPOST_SENDING_DOMAIN;
}

/**
 * Plugin activation hook.
 */
register_activation_hook(__FILE__, 'nanopost_activate');
