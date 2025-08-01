<?php

/**
 * Simplified admin functionality - focused and fast
 */
class SC_Wp_Plugin_Admin {
    private $plugin_name;
    private $version;
    public $api;
    private $plugin_instance;
    public $shop_order;
    private $view;

    public function __construct($plugin_name, $version, $api = null, $plugin_instance = null) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->api = $api;
        $this->plugin_instance = $plugin_instance;

        // Initialize view
        require_once plugin_dir_path(__FILE__) . 'class-sc-wp-plugin-admin-view.php';
        $this->view = new SC_Wp_Plugin_Admin_View();

        // Initialize order processing if API is valid
        if ($this->api instanceof SC_API) {
            require plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-wp-shop-order.php';
            $this->shop_order = new SC_WP_Shop_Order($this->api);
        }

        $this->register_hooks();
    }

    /**
     * Get sync instance only when needed (lazy loading)
     */
    private function get_sync() {
        if ($this->plugin_instance && method_exists($this->plugin_instance, 'get_sync')) {
            return $this->plugin_instance->get_sync();
        }
        return null;
    }

    private function register_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        
        if ($this->api instanceof SC_API) {
            // SIMPLIFIED: Only daily sync
            add_action('init', array($this, 'setup_daily_sync'));
            add_action('SC_WP_daily_sync', array($this, 'run_daily_sync'));
            
            // AJAX handlers - simplified
            add_action('wp_ajax_sc_run_sync', array($this, 'ajax_run_sync'));
            add_action('wp_ajax_sc_assign_images', array($this, 'ajax_assign_images'));
            add_action('wp_ajax_sc_get_status', array($this, 'ajax_get_status'));
            
            // Order processing
            add_action('woocommerce_checkout_order_processed', array($this, 'process_order'), 10, 3);
        }
        
        add_action('admin_notices', array($this, 'show_admin_notices'));
    }

    public function show_admin_notices() {
        $screen = get_current_screen();
        if ($screen->id !== 'settings_page_sc-settings') {
            return;
        }

        $api_id = get_option('sc_api_id');
        if (empty($api_id)) {
            ?>
            <div class="notice notice-error">
                <p><strong>SC Integration:</strong> Please enter your API ID to enable product synchronization.</p>
            </div>
            <?php
            return;
        }

        // Show category status
        $wc_categories = $this->getWooCommerceCategories();
        if (empty($wc_categories)) {
            ?>
            <div class="notice notice-warning">
                <p><strong>Setup Required:</strong> Please create product categories in <strong>Products → Categories</strong> to enable sync.</p>
            </div>
            <?php
        } else {
            ?>
            <div class="notice notice-success">
                <p><strong>Ready:</strong> Found <?php echo count($wc_categories); ?> categories ready for sync.</p>
            </div>
            <?php
        }
    }

    private function getWooCommerceCategories() {
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'fields' => 'names'
        ]);

        if (is_wp_error($categories)) {
            return [];
        }

        return array_filter($categories, function($cat) {
            return !in_array(strtolower($cat), ['uncategorized']);
        });
    }

    /**
     * SIMPLIFIED: Setup daily sync only
     */
    public function setup_daily_sync() {
        // Clear old frequent syncs
        wp_clear_scheduled_hook('SC_WP_scheduled_stock_sync');
        wp_clear_scheduled_hook('SC_WP_scheduled_full_sync');
        
        // Setup daily sync at 3 AM
        if (!wp_next_scheduled('SC_WP_daily_sync')) {
            $tomorrow_3am = strtotime('tomorrow 3:00 am');
            wp_schedule_event($tomorrow_3am, 'daily', 'SC_WP_daily_sync');
        }
    }

    /**
     * SIMPLIFIED: Run daily sync
     */
    public function run_daily_sync() {
        if (get_transient('sc_sync_lock')) {
            return; // Skip if already running
        }

        try {
            $sync = $this->get_sync();
            if (!$sync) {
                error_log('SC Admin: Daily sync failed - sync class not available');
                return;
            }

            $result = $sync->syncProducts(false);
            
            if ($result['status']) {
                update_option('sc_last_sync_time', current_time('mysql'));
                update_option('sc_last_sync_count', $result['processed']);
                error_log('SC Admin: Daily sync completed - processed ' . $result['processed'] . ' items');
            } else {
                error_log('SC Admin: Daily sync failed: ' . $result['message']);
            }
            
        } catch (Exception $e) {
            error_log('SC Admin: Daily sync exception: ' . $e->getMessage());
        }
    }

    public function register_settings() {
        register_setting('sc_settings', 'sc_api_id', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('sc_settings', 'sc_api_rows_per_request', array(
            'sanitize_callback' => array($this, 'sanitize_batch_size'),
            'default' => 2000
        ));

        register_setting('sc_settings', 'sc_auto_sync_enabled', array(
            'sanitize_callback' => array($this, 'sanitize_boolean'),
            'default' => 'yes'
        ));

        register_setting('sc_settings', 'sc_cart_validation_enabled', array(
            'sanitize_callback' => array($this, 'sanitize_boolean'),
            'default' => 'yes'
        ));
    }

    public function sanitize_batch_size($value) {
        $value = intval($value);
        return max(500, min(5000, $value)); // Allow large batches for efficiency
    }

    public function sanitize_boolean($value) {
        return $value === 'yes' || $value === '1' || $value === 1 || $value === true ? 'yes' : 'no';
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'settings_page_sc-settings') return;
        
        wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/sc-wp-plugin-admin.css', array(), $this->version);
        wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/sc-wp-plugin-admin.js', array('jquery'), $this->version, true);
        
        wp_localize_script($this->plugin_name, 'scSyncData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sc_sync_nonce'),
            'lastSync' => get_option('sc_last_sync_time', 'Never'),
            'lastSyncCount' => get_option('sc_last_sync_count', '0'),
            'nextSync' => $this->getNextSyncTime(),
            'categories' => $this->getWooCommerceCategories()
        ));
    }

    private function getNextSyncTime() {
        $timestamp = wp_next_scheduled('SC_WP_daily_sync');
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : 'Not scheduled';
    }

    public function add_admin_menu() {
        add_options_page('SC Integration', 'SC Integration', 'manage_options', 
            'sc-settings', array($this->view, 'render_settings_page'));
    }

    /**
     * SIMPLIFIED: Run sync via AJAX
     */
    public function ajax_run_sync() {
        check_ajax_referer('sc_sync_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        if (get_transient('sc_sync_lock')) {
            wp_send_json_error('Sync already in progress. Please wait for it to complete.');
        }

        try {
            $sync = $this->get_sync();
            if (!$sync) {
                wp_send_json_error(['message' => 'Sync functionality not available']);
                return;
            }

            $result = $sync->syncProducts(true); // Force sync

            if ($result['status']) {
                update_option('sc_last_sync_time', current_time('mysql'));
                update_option('sc_last_sync_count', $result['processed']);

                wp_send_json_success([
                    'message' => $result['message'],
                    'processed' => $result['processed'],
                    'created' => $result['created'] ?? 0,
                    'deleted' => $result['deleted'] ?? 0
                ]);
            } else {
                wp_send_json_error(['message' => $result['message']]);
            }

        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Sync failed: ' . $e->getMessage()]);
        }
    }

    /**
     * AJAX assign images to products
     */
    public function ajax_assign_images() {
        check_ajax_referer('sc_sync_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        try {
            $sync = $this->get_sync();
            if (!$sync) {
                wp_send_json_error(['message' => 'Sync functionality not available']);
                return;
            }

            $result = $sync->assignImagesToAllProducts(200); // Process 200 products at a time

            if ($result['status']) {
                wp_send_json_success([
                    'message' => $result['message'],
                    'processed' => $result['processed'],
                    'assigned' => $result['assigned']
                ]);
            } else {
                wp_send_json_error(['message' => $result['message']]);
            }

        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Image assignment failed: ' . $e->getMessage()]);
        }
    }

    /**
     * AJAX get status
     */
    public function ajax_get_status() {
        check_ajax_referer('sc_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $sync_active = get_transient('sc_sync_lock') ? true : false;
        
        wp_send_json_success([
            'sync_active' => $sync_active,
            'last_sync' => get_option('sc_last_sync_time', 'Never'),
            'last_count' => get_option('sc_last_sync_count', 0),
            'next_sync' => $this->getNextSyncTime(),
            'categories' => $this->getWooCommerceCategories()
        ]);
    }

    // Order Processing (simplified)
    public function process_order($order_id, $posted_data, $order) {
        if (!$this->shop_order) return;

        try {
            $this->shop_order->orderProcess($order_id);
        } catch (Exception $e) {
            error_log("SC Order processing failed for order {$order_id}: " . $e->getMessage());
        }
    }

    // Backward compatibility
    public function enqueue_styles($hook) { $this->enqueue_assets($hook); }
    public function enqueue_scripts($hook) { $this->enqueue_assets($hook); }
}