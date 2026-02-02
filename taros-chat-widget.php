<?php
/**
 * Plugin Name: Taros Chat Widget
 * Plugin URI: https://taros.ai
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
        add_action('wp_footer', array($this, 'inject_widget_script'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
    }

    /**
     * Add settings link to plugins page
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="options-general.php?page=taros-chat-widget">' . __('Settings', 'taros-chat-widget') . '</a>';
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

        register_setting('taros_settings', 'taros_widget_enabled', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => true,
        ));
    }

    /**
     * Render the settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Show success message after save
        if (isset($_GET['settings-updated'])) {
            add_settings_error('taros_messages', 'taros_message', __('Settings saved.', 'taros-chat-widget'), 'updated');
        }

        $bot_id = get_option('taros_bot_id', '');
        $widget_enabled = get_option('taros_widget_enabled', true);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php settings_errors('taros_messages'); ?>

            <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); max-width: 600px; margin-top: 20px;">
                <form action="options.php" method="post">
                    <?php settings_fields('taros_settings'); ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="taros_bot_id"><?php esc_html_e('Bot ID', 'taros-chat-widget'); ?></label>
                            </th>
                            <td>
                                <input
                                    type="text"
                                    id="taros_bot_id"
                                    name="taros_bot_id"
                                    value="<?php echo esc_attr($bot_id); ?>"
                                    class="regular-text"
                                    placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                                />
                                <p class="description">
                                    <?php echo wp_kses(__('Find your Bot ID in the <a href="https://app.taros.ai/dashboard" target="_blank">Taros Dashboard</a> under Widget settings.', 'taros-chat-widget'), array('a' => array('href' => array(), 'target' => array()))); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Enable Widget', 'taros-chat-widget'); ?></th>
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

                    <?php submit_button(__('Save Settings', 'taros-chat-widget')); ?>
                </form>
            </div>

            <div style="background: #f0f6fc; padding: 20px; border-radius: 8px; border-left: 4px solid #2271b1; max-width: 600px; margin-top: 20px;">
                <h3 style="margin-top: 0;"><?php esc_html_e('Need help?', 'taros-chat-widget'); ?></h3>
                <p><?php esc_html_e('Visit our documentation or contact support:', 'taros-chat-widget'); ?></p>
                <ul style="margin-bottom: 0;">
                    <li><a href="https://taros.ai/docs" target="_blank"><?php esc_html_e('Documentation', 'taros-chat-widget'); ?></a></li>
                    <li><a href="https://taros.ai/support" target="_blank"><?php esc_html_e('Contact Support', 'taros-chat-widget'); ?></a></li>
                </ul>
            </div>
        </div>
        <?php
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
        <script data-bot="<?php echo esc_attr($bot_id); ?>" src="https://widgets.taros.ai/widget.js" async></script>
        <?php
    }
}

// Initialize the plugin
Taros_Chat_Widget::get_instance();
