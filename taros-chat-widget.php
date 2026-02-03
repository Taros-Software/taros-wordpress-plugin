<?php
/**
 * Plugin Name: Taros Chat Widget
 * Plugin URI: https://github.com/Taros-Software/taros-wordpress-plugin
 * Description: Add an AI-powered chat widget to your WordPress or WooCommerce store. Taros helps your customers get instant answers 24/7.
 * Version: 1.0.0
 * Author: Taros
 * Author URI: https://taros.ai
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taros-chat-widget
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

define('TAROS_PLUGIN_VERSION', '1.0.0');
define('TAROS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Allow overriding URLs for testing (define in wp-config.php)
if (!defined('TAROS_APP_URL')) {
    define('TAROS_APP_URL', 'https://taros.ai');
}
if (!defined('TAROS_WIDGET_URL')) {
    define('TAROS_WIDGET_URL', 'https://widgets.taros.ai/widget.js');
}

/**
 * Main Taros Chat Widget Class
 */
class Taros_Chat_Widget {

    /**
     * Instance of this class
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'handle_oauth_callback'));
        add_action('wp_footer', array($this, 'inject_widget_script'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
    }

    /**
     * Add settings link to plugins page
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="options-general.php?page=taros-chat-widget">' . esc_html__('Settings', 'taros-chat-widget') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Add admin menu item
     */
    public function add_admin_menu() {
        add_options_page(
            __('Taros Chat Widget', 'taros-chat-widget'),
            __('Taros Chat', 'taros-chat-widget'),
            'manage_options',
            'taros-chat-widget',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('taros_settings', 'taros_bot_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ));

        register_setting('taros_settings', 'taros_bot_name', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ));

        register_setting('taros_settings', 'taros_widget_enabled', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
        ));
    }

    /**
     * Handle OAuth callback from Taros
     */
    public function handle_oauth_callback() {
        // Check if this is a callback from Taros
        if (!isset($_GET['page']) || $_GET['page'] !== 'taros-chat-widget') {
            return;
        }

        if (!isset($_GET['taros_bot_id']) || !isset($_GET['taros_nonce'])) {
            return;
        }

        // Verify nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['taros_nonce'])), 'taros_connect')) {
            add_settings_error('taros_messages', 'taros_error', __('Security check failed. Please try again.', 'taros-chat-widget'), 'error');
            return;
        }

        // Verify user has permission
        if (!current_user_can('manage_options')) {
            return;
        }

        // Save the bot ID
        $bot_id = sanitize_text_field(wp_unslash($_GET['taros_bot_id']));
        if (!preg_match('/^[a-f0-9\-]{36}$/i', $bot_id)) {
            add_settings_error('taros_messages', 'taros_error', __('Invalid Bot ID received.', 'taros-chat-widget'), 'error');
            return;
        }

        update_option('taros_bot_id', $bot_id);

        // Save bot name if provided
        if (isset($_GET['taros_bot_name'])) {
            $bot_name = sanitize_text_field(wp_unslash($_GET['taros_bot_name']));
            update_option('taros_bot_name', $bot_name);
        }

        // Redirect to remove query params
        wp_safe_redirect(admin_url('options-general.php?page=taros-chat-widget&connected=1'));
        exit;
    }

    /**
     * Get the connect URL for Taros OAuth
     */
    private function get_connect_url() {
        $nonce = wp_create_nonce('taros_connect');
        $callback_url = admin_url('options-general.php?page=taros-chat-widget');
        $site_url = home_url();

        return add_query_arg(array(
            'callback_url' => urlencode($callback_url),
            'site_url' => urlencode($site_url),
            'nonce' => $nonce,
            'platform' => 'wordpress',
        ), TAROS_APP_URL . '/connect/wordpress');
    }

    /**
     * Render the settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $bot_id = get_option('taros_bot_id', '');
        $bot_name = get_option('taros_bot_name', '');
        $widget_enabled = get_option('taros_widget_enabled', true);
        $is_connected = !empty($bot_id);

        // Show success message after connection
        if (isset($_GET['connected'])) {
            add_settings_error('taros_messages', 'taros_message', __('Successfully connected to Taros!', 'taros-chat-widget'), 'updated');
        }

        // Show success message after disconnect
        if (isset($_GET['disconnected'])) {
            add_settings_error('taros_messages', 'taros_message', __('Disconnected from Taros.', 'taros-chat-widget'), 'updated');
        }

        // Show success message after save
        if (isset($_GET['settings-updated'])) {
            add_settings_error('taros_messages', 'taros_message', __('Settings saved.', 'taros-chat-widget'), 'updated');
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php settings_errors('taros_messages'); ?>

            <?php if (!$is_connected) : ?>
                <!-- Not Connected State -->
                <div style="background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); max-width: 600px; margin-top: 20px; text-align: center;">
                    <h2 style="color: #1d2327; margin-top: 0;"><?php esc_html_e('Get started with Taros', 'taros-chat-widget'); ?></h2>
                    <p style="color: #646970; font-size: 14px; margin-bottom: 24px;">
                        <?php esc_html_e('Add an AI-powered chat widget to your site. Help your visitors get instant answers 24/7.', 'taros-chat-widget'); ?>
                    </p>
                    <a href="<?php echo esc_url($this->get_connect_url()); ?>" class="button button-primary button-hero" style="font-size: 16px; padding: 8px 32px;">
                        <?php esc_html_e('Connect with Taros', 'taros-chat-widget'); ?>
                    </a>
                    <p style="color: #a7aaad; font-size: 12px; margin-top: 16px;">
                        <?php esc_html_e("Don't have an account?", 'taros-chat-widget'); ?>
                        <a href="<?php echo esc_url(TAROS_APP_URL); ?>" target="_blank"><?php esc_html_e('Start a free trial', 'taros-chat-widget'); ?></a>
                    </p>
                </div>

            <?php else : ?>
                <!-- Connected State -->
                <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); max-width: 600px; margin-top: 20px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #e0e0e0;">
                        <div>
                            <span style="color: #00a32a; font-weight: 500;">&#10003; <?php esc_html_e('Connected', 'taros-chat-widget'); ?></span>
                            <?php if ($bot_name) : ?>
                                <span style="color: #646970; margin-left: 8px;"><?php echo esc_html($bot_name); ?></span>
                            <?php endif; ?>
                        </div>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('options-general.php?page=taros-chat-widget&action=disconnect'), 'taros_disconnect')); ?>" class="button" onclick="return confirm('<?php esc_attr_e('Are you sure you want to disconnect?', 'taros-chat-widget'); ?>');">
                            <?php esc_html_e('Disconnect', 'taros-chat-widget'); ?>
                        </a>
                    </div>

                    <form action="options.php" method="post">
                        <?php settings_fields('taros_settings'); ?>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php esc_html_e('Widget Status', 'taros-chat-widget'); ?></th>
                                <td>
                                    <label for="taros_widget_enabled">
                                        <input
                                            type="checkbox"
                                            id="taros_widget_enabled"
                                            name="taros_widget_enabled"
                                            value="1"
                                            <?php checked($widget_enabled, true); ?>
                                        />
                                        <?php esc_html_e('Show the chat widget on your site', 'taros-chat-widget'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <!-- Hidden field to preserve bot_id -->
                        <input type="hidden" name="taros_bot_id" value="<?php echo esc_attr($bot_id); ?>" />
                        <input type="hidden" name="taros_bot_name" value="<?php echo esc_attr($bot_name); ?>" />

                        <?php submit_button(__('Save Settings', 'taros-chat-widget')); ?>
                    </form>
                </div>

                <div style="background: #f0f6fc; padding: 20px; border-radius: 8px; border-left: 4px solid #2271b1; max-width: 600px; margin-top: 20px;">
                    <p style="margin: 0;">
                        <?php
                        echo wp_kses(
                            __('Manage your chatbot settings in the <a href="https://taros.ai/dashboard" target="_blank">Taros Dashboard</a>.', 'taros-chat-widget'),
                            array('a' => array('href' => array(), 'target' => array()))
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php

        // Handle disconnect action
        if (isset($_GET['action']) && $_GET['action'] === 'disconnect') {
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'taros_disconnect')) {
                return;
            }
            delete_option('taros_bot_id');
            delete_option('taros_bot_name');
            wp_safe_redirect(admin_url('options-general.php?page=taros-chat-widget&disconnected=1'));
            exit;
        }
    }

    /**
     * Inject the widget script in the footer
     */
    public function inject_widget_script() {
        // Don't show in admin
        if (is_admin()) {
            return;
        }

        // Check if widget is enabled
        $widget_enabled = get_option('taros_widget_enabled', true);
        if (!$widget_enabled) {
            return;
        }

        // Get bot ID
        $bot_id = get_option('taros_bot_id', '');
        if (empty($bot_id)) {
            return;
        }

        // Sanitize bot ID (should be a UUID)
        $bot_id = sanitize_text_field($bot_id);
        if (!preg_match('/^[a-f0-9\-]{36}$/i', $bot_id)) {
            return;
        }

        // Output the widget script
        ?>
        <script data-bot="<?php echo esc_attr($bot_id); ?>" src="<?php echo esc_url(TAROS_WIDGET_URL); ?>" async></script>
        <?php
    }
}

// Initialize the plugin
Taros_Chat_Widget::get_instance();
