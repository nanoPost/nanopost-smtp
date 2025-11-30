<?php
/**
 * Admin settings page and notices for nanoPost.
 */

defined('ABSPATH') || exit;

/**
 * Redirect to welcome page after activation.
 */
add_action('admin_init', function () {
    if (!get_option('nanopost_activation_redirect', false)) {
        return;
    }

    delete_option('nanopost_activation_redirect');

    // Don't redirect on bulk activate or network admin
    if (isset($_GET['activate-multi']) || is_network_admin()) {
        return;
    }

    wp_safe_redirect(admin_url('options-general.php?page=nanopost&welcome=1'));
    exit;
}, 1); // Priority 1 to run before other admin_init hooks

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
    if (!isset($_GET['page']) || sanitize_text_field($_GET['page']) !== 'nanopost') {
        return;
    }

    $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

    // Handle dismiss
    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field($_GET['_wpnonce']) : '';
    if ($action === 'dismiss-domain-notice' && wp_verify_nonce($nonce, 'nanopost_dismiss_notice')) {
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
    $is_welcome = isset($_GET['welcome']) && sanitize_text_field($_GET['welcome']) === '1';
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'overview';

    // Default to test tab on welcome
    if ($is_welcome && !isset($_GET['tab'])) {
        $active_tab = 'test';
    }
    ?>
    <div class="wrap">
        <h1>nanoPost</h1>

        <?php if ($is_welcome && $site_token): ?>
        <div class="notice notice-success" style="padding: 15px; border-left-color: #00a32a;">
            <h2 style="margin-top: 0;">Welcome to nanoPost!</h2>
            <p>Your site is now connected and ready to send emails. All WordPress system emails will automatically be delivered through nanoPost.</p>
            <p>Try sending a test email below to verify everything is working.</p>
        </div>
        <?php elseif ($is_welcome && !$site_token): ?>
        <div class="notice notice-warning" style="padding: 15px;">
            <h2 style="margin-top: 0;">Almost there!</h2>
            <p>Registration is still in progress. Check the Advanced tab or try re-registering.</p>
        </div>
        <?php endif; ?>

        <nav class="nav-tab-wrapper">
            <a href="<?php echo esc_url(admin_url('options-general.php?page=nanopost&tab=overview')); ?>"
               class="nav-tab <?php echo $active_tab === 'overview' ? 'nav-tab-active' : ''; ?>">Overview</a>
            <a href="<?php echo esc_url(admin_url('options-general.php?page=nanopost&tab=test')); ?>"
               class="nav-tab <?php echo $active_tab === 'test' ? 'nav-tab-active' : ''; ?>">Test Email</a>
            <a href="<?php echo esc_url(admin_url('options-general.php?page=nanopost&tab=advanced')); ?>"
               class="nav-tab <?php echo $active_tab === 'advanced' ? 'nav-tab-active' : ''; ?>">Advanced</a>
        </nav>

        <div class="tab-content" style="padding-top: 20px;">
            <?php
            switch ($active_tab) {
                case 'test':
                    nanopost_render_test_tab();
                    break;
                case 'advanced':
                    nanopost_render_advanced_tab($site_token, $site_id, $registered_domain);
                    break;
                default:
                    nanopost_render_overview_tab($site_token);
                    break;
            }
            ?>
        </div>
    </div>
    <?php
}

/**
 * Render the Overview tab.
 */
function nanopost_render_overview_tab($site_token) {
    $sender_name = get_bloginfo('name');
    $from_address = nanopost_get_from_address();
    ?>
    <div style="max-width: 600px;">
        <h2>Zero-config email delivery for WordPress</h2>
        <p style="font-size: 14px; line-height: 1.6;">
            nanoPost handles all your WordPress emails automatically. No SMTP credentials to configure,
            no API keys to manage. Just activate and your emails start flowing through our reliable infrastructure.
        </p>

        <h3>How it works</h3>
        <ol style="font-size: 14px; line-height: 1.8;">
            <li>Your site registered automatically when you activated the plugin</li>
            <li>All <code>wp_mail()</code> calls are routed through nanoPost</li>
            <li>We deliver your emails reliably via our SMTP infrastructure</li>
        </ol>

        <?php if ($site_token): ?>
        <p style="margin-top: 20px;">
            <span style="color: green; font-size: 16px;">&#10003;</span>
            <strong>Connected</strong> &mdash; Your site is registered and ready to send emails.
        </p>

        <h3>Sender Details</h3>
        <table class="form-table" style="margin-top: 0;">
            <tr>
                <th style="width: 120px; padding: 10px 10px 10px 0;">From Name</th>
                <td style="padding: 10px 0;"><code><?php echo esc_html($sender_name); ?></code></td>
            </tr>
            <tr>
                <th style="padding: 10px 10px 10px 0;">From Address</th>
                <td style="padding: 10px 0;"><code><?php echo esc_html($from_address); ?></code></td>
            </tr>
        </table>
        <?php else: ?>
        <p style="margin-top: 20px;">
            <span style="color: red; font-size: 16px;">&#10007;</span>
            <strong>Not connected</strong> &mdash; Registration may still be in progress.
        </p>
        <p>
            <a href="<?php echo esc_url(admin_url('options-general.php?page=nanopost&tab=advanced')); ?>" class="button">
                Check Advanced Settings
            </a>
        </p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render the Test Email tab.
 */
function nanopost_render_test_tab() {
    ?>
    <h2>Send Test Email</h2>
    <p>Verify that emails are being delivered correctly by sending a test message.</p>

    <form method="post">
        <?php wp_nonce_field('nanopost_settings'); ?>
        <table class="form-table">
            <tr>
                <th><label for="nanopost_test_email">Recipient</label></th>
                <td>
                    <input type="email" id="nanopost_test_email" name="nanopost_test_email"
                           value="<?php echo esc_attr(get_option('admin_email')); ?>"
                           class="regular-text" required>
                    <p class="description">Email address to send the test message to.</p>
                </td>
            </tr>
        </table>
        <p class="submit">
            <input type="submit" name="nanopost_test" class="button button-primary" value="Send Test Email">
        </p>
    </form>
    <?php
}

/**
 * Render the Advanced tab.
 */
function nanopost_render_advanced_tab($site_token, $site_id, $registered_domain) {
    ?>
    <h2>Registration Status</h2>
    <table class="form-table">
        <tr>
            <th>Status</th>
            <td>
                <?php if ($site_token): ?>
                    <span style="color: green; font-weight: bold;">&#10003; Registered</span>
                <?php else: ?>
                    <span style="color: red; font-weight: bold;">&#10007; Not registered</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php if ($registered_domain): ?>
        <tr>
            <th>Sending Domain</th>
            <td>
                <code><?php echo esc_html($registered_domain); ?></code>
                <?php if ($registered_domain !== site_url()): ?>
                    <span style="color: orange; margin-left: 10px;">
                        &#9888; Current site URL: <code><?php echo esc_html(site_url()); ?></code>
                    </span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endif; ?>
        <?php if ($site_id): ?>
        <tr>
            <th>Site ID</th>
            <td><code><?php echo esc_html($site_id); ?></code></td>
        </tr>
        <?php endif; ?>
    </table>

    <form method="post" style="margin-top: 10px;">
        <?php wp_nonce_field('nanopost_settings'); ?>
        <input type="submit" name="nanopost_register" class="button"
               value="<?php echo $site_token ? 'Re-register Site' : 'Register Now'; ?>">
        <p class="description">
            <?php echo $site_token ? 'Generate a new token (invalidates the old one).' : 'Connect this site to nanoPost.'; ?>
        </p>
    </form>

    <hr style="margin: 30px 0;">

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
    <?php
}

/**
 * Handle settings form submissions.
 */
function nanopost_handle_settings_form() {
    // Handle re-registration request
    if (isset($_POST['nanopost_register'])) {
        $result = nanopost_register_site(true);

        if ($result['success']) {
            echo '<div class="notice notice-success"><p>Registered successfully!</p></div>';
        } else {
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
        update_option('nanopost_debug_mode', !empty($_POST['nanopost_debug_mode']));
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }
}
