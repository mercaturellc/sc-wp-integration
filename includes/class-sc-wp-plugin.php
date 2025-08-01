<?php

/**
 * SIMPLIFIED core plugin class - optimized for performance
 */
class SC_Wp_Plugin {
    protected $loader;
    protected $plugin_name;
    protected $version;
    protected $api;
    protected $sync; // Lazy loaded only when needed

    public function __construct() {
        if (defined('SC_WP_PLUGIN_VERSION')) {
            $this->version = SC_WP_PLUGIN_VERSION;
        } else {
            $this->version = '1.0.0';
        }
        $this->plugin_name = 'sc-wp-plugin';

        $this->load_dependencies();
        $this->set_locale();

        // Only initialize lightweight API class
        $this->api = new SC_API();

        // ONLY hook admin functionality if in admin
        if (is_admin()) {
            $this->define_admin_hooks();
        }
        
        // ONLY hook order processing if WooCommerce is active
        if (class_exists('WooCommerce')) {
            $this->define_order_hooks();
        }

        $this->define_sync_hooks();
    }

    private function load_dependencies() {
        // Core classes only
        require plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-api.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-wp-plugin-loader.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-wp-plugin-i18n.php';

        // Admin classes only loaded if in admin
        if (is_admin()) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-sc-wp-plugin-admin.php';
            require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-sc-wp-plugin-admin-view.php';
        }

        $this->loader = new SC_Wp_Plugin_Loader();
    }

    private function set_locale() {
        $plugin_i18n = new SC_Wp_Plugin_i18n();
        $this->loader->add_action('plugins_loaded', $plugin_i18n, 'load_plugin_textdomain');
    }

    private function define_admin_hooks() {
        $plugin_admin = new SC_Wp_Plugin_Admin($this->get_plugin_name(), $this->get_version(), $this->api, $this);
        
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_assets');
        $this->loader->add_action('admin_menu', $plugin_admin, 'add_admin_menu');
        $this->loader->add_action('admin_init', $plugin_admin, 'register_settings');
        $this->loader->add_action('admin_notices', $plugin_admin, 'show_admin_notices');

        // AJAX handlers
        $this->loader->add_action('wp_ajax_sc_run_sync', $plugin_admin, 'ajax_run_sync');
        $this->loader->add_action('wp_ajax_sc_get_status', $plugin_admin, 'ajax_get_status');
    }

    private function define_order_hooks() {
        // Order processing hooks - only if WooCommerce is active
        $this->loader->add_action('woocommerce_checkout_order_processed', $this, 'process_new_order', 10, 3);
    }

    private function define_sync_hooks() {
        // SIMPLIFIED: Only daily sync
        $this->loader->add_action('SC_WP_daily_sync', $this, 'run_daily_sync');
        $this->loader->add_action('init', $this, 'setup_daily_sync');
    }

    public function setup_daily_sync() {
        if (!get_option('sc_api_id', '')) {
            return; // No API ID configured
        }

        // Clear old frequent sync schedules if they exist
        wp_clear_scheduled_hook('SC_WP_scheduled_stock_sync');
        wp_clear_scheduled_hook('SC_WP_scheduled_full_sync');

        // Setup simple daily sync at 3 AM
        if (!wp_next_scheduled('SC_WP_daily_sync')) {
            $tomorrow_3am = strtotime('tomorrow 3:00 AM');
            wp_schedule_event($tomorrow_3am, 'daily', 'SC_WP_daily_sync');
        }
    }

    public function run_daily_sync() {
        // Only run if auto-sync is enabled
        if (get_option('sc_auto_sync_enabled', 'yes') !== 'yes') {
            return;
        }

        try {
            error_log('SC: Starting daily sync');
            
            $sync = $this->get_sync();
            if (!$sync) {
                error_log('SC: Daily sync failed - sync not available');
                return;
            }

            $result = $sync->syncProducts(false);
            
            if ($result['status']) {
                error_log('SC: Daily sync completed - processed ' . $result['processed'] . ' items');
                update_option('sc_last_sync_time', current_time('mysql'));
                update_option('sc_last_sync_count', $result['processed']);
            } else {
                error_log('SC: Daily sync failed: ' . $result['message']);
            }
            
        } catch (Exception $e) {
            error_log('SC: Daily sync exception: ' . $e->getMessage());
        }
    }

    public function process_new_order($order_id, $posted_data, $order) {
        // Simple order processing
        try {
            if (!class_exists('SC_WP_Shop_Order')) {
                require plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-wp-shop-order.php';
            }
            
            $order_processor = new SC_WP_Shop_Order($this->api);
            $order_processor->orderProcess($order_id);
            
        } catch (Exception $e) {
            error_log("SC: Order processing failed for order {$order_id}: " . $e->getMessage());
        }
    }

    /**
     * LAZY LOAD sync instance only when needed
     */
    public function get_sync() {
        if ($this->sync === null) {
            // Only load sync classes when actually needed
            if (!class_exists('SC_WP_Shop_Sync_Utils')) {
                require plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-wp-shop-sync-utils.php';
            }
            if (!class_exists('SC_WP_Shop_Sync')) {
                require plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-wp-shop-sync.php';
            }
            
            $this->sync = new SC_WP_Shop_Sync($this->api);
        }
        return $this->sync;
    }

    // Simple getters
    public function get_api() { return $this->api; }
    public function get_plugin_name() { return $this->plugin_name; }
    public function get_loader() { return $this->loader; }
    public function get_version() { return $this->version; }

    public function run() {
        $this->loader->run();
    }
}