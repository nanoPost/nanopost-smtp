<?php
/**
 * Admin settings page and notices for nanoPost.
 */

defined('ABSPATH') || exit;

/**
 * Register settings page menu item.
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

/**
 * Register settings.
 */
add_action('admin_init', function () {
    register_setting('nanopost', 'nanopost_site_token');
    register_setting('nanopost', 'nanopost_api_url');
});

/**
 * Check for domain changes and show admin notice.
 */
add_action('admin_init', function () {
    // Only check if registered
    if (!get_option('nanopost_site_token')) {
        return;
    }

    $registered_domain = get_option('nanopost_registered_domain', '');
    $current_domain = site_url();

    // No mismatch
    if ($registered_domain === $current_domain || empty($registered_domain)) {
        return;
    }

    // Check if dismissed
    $dismissed_until = get_option('nanopost_domain_notice_dismissed', 0);
    if ($dismissed_until > time()) {
        return;
    }

    // Show admin notice
    add_action('admin_notices', function () use ($registered_domain, $current_domain) {
        ?>
        <div class="notice notice-warning is-dismissible" id="nanopost-domain-notice">
            <p>
                <strong>nanoPost:</strong> Your site domain changed from
                <code><?php echo esc_html($registered_domain); ?></code> to
                <code><?php echo esc_html($current_domain); ?></code>.
                Emails still send from the old domain.
            </p>
            <p>
                <a href="<?php echo esc_url(admin_url('options-general.php?page=nanopost&action=update-domain')); ?>"
                   class="button button-primary">Update sending domain</a>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('options-general.php?page=nanopost&action=dismiss-domain-notice'), 'nanopost_dismiss_notice')); ?>"
                   class="button">Dismiss for 7 days</a>
            </p>
        </div>
        <?php
    });
});

/**
 * Handle domain notice actions.
 */
add_action('admin_init', function () {
    if (!isset($_GET['page']) || $_GET['page'] !== 'nanopost') {
        return;
    }

    $action = $_GET['action'] ?? '';

    // Handle dismiss
    if ($action === 'dismiss-domain-notice' && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'nanopost_dismiss_notice')) {
        update_option('nanopost_domain_notice_dismissed', time() + (7 * DAY_IN_SECONDS));
        wp_redirect(admin_url('options-general.php?page=nanopost'));
        exit;
    }

    // Handle update domain
    if ($action === 'update-domain') {
        $result = nanopost_update_domain();

        if ($result['success']) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-success"><p>Sending domain updated successfully!</p></div>';
            });
        } else {
            add_action('admin_notices', function () use ($result) {
                echo '<div class="notice notice-error"><p>Failed to update domain: ' . esc_html($result['error']) . '</p></div>';
            });
        }
    }
});

/**
 * Render the settings page.
 */
function nanopost_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('nanopost_settings')) {
        nanopost_handle_settings_form();
    }

    $site_token = get_option('nanopost_site_token', '');
    $site_id = get_option('nanopost_site_id', '');
    $registered_domain = get_option('nanopost_registered_domain', '');
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
                <?php if ($registered_domain): ?>
                <tr>
                    <th>Registered Domain</th>
                    <td>
                        <code><?php echo esc_html($registered_domain); ?></code>
                        <?php if ($registered_domain !== site_url()): ?>
                            <span style="color: orange; margin-left: 10px;">
                                ⚠️ Current: <code><?php echo esc_html(site_url()); ?></code>
                            </span>
                        <?php endif; ?>
                    </td>
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
 * Handle settings form submissions.
 */
function nanopost_handle_settings_form() {
    // Handle re-registration request
    if (isset($_POST['nanopost_register'])) {
        $old_site_id = get_option('nanopost_site_id', '(none)');
        $old_site_token = get_option('nanopost_site_token', '(none)');

        nanopost_debug("=== REGISTRATION START ===");
        nanopost_debug("Previous site_id: {$old_site_id}");
        nanopost_debug("Previous site_token: " . substr($old_site_token, 0, 10) . "...");

        $result = nanopost_register_site(true);

        if ($result['success']) {
            nanopost_debug("Updated flag: " . ($result['data']['updated'] ?? 'false'));
            nanopost_debug("=== REGISTRATION SUCCESS ===");
            echo '<div class="notice notice-success"><p>Registered successfully!</p></div>';
        } else {
            nanopost_debug("=== REGISTRATION FAILED ===");
            echo '<div class="notice notice-error"><p>Registration failed: ' . esc_html($result['error']) . '</p></div>';
        }
    }

    // Send test email if requested
    if (isset($_POST['nanopost_test']) && !empty($_POST['nanopost_test_email'])) {
        $test_to = sanitize_email($_POST['nanopost_test_email']);
        $result = nanopost_send_test_email($test_to);

        if ($result['success']) {
            echo '<div class="notice notice-success"><p>Test email sent to ' . esc_html($test_to) . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to send test email: ' . esc_html($result['error']) . '</p></div>';
        }
    }

    // Handle debug mode toggle
    if (isset($_POST['nanopost_save_settings'])) {
        $debug_mode = isset($_POST['nanopost_debug_mode']) ? true : false;
        update_option('nanopost_debug_mode', $debug_mode);
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }
}
