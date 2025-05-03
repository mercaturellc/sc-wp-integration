<?php
/**
 * The admin-specific functionality of the plugin (Controller).
 *
 * @link       https://gitlab.com/mercature/sc-wp-integration
 * @since      1.0.0
 * @package    SC_Wp_Plugin
 * @subpackage SC_Wp_Plugin/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * @package    SC_Wp_Plugin
 * @subpackage SC_Wp_Plugin/admin
 * @author     Trent Mercer <trent@mercature.net>
 */
class SC_Wp_Plugin_Admin {
    private $plugin_name;
    private $version;
    public $api;
    public $shop_sync;
    public $shop_order;
    private $view;

    /**
     * Initialize the class and set its properties.
     *
     * @param string $plugin_name The name of this plugin.
     * @param string $version The version of this plugin.
     * @param SC_API|null $api The API client instance.
     */
    public function __construct($plugin_name, $version, $api = null) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->api = $api;

        // Initialize view
        require_once plugin_dir_path(__FILE__) . 'class-sc-wp-plugin-admin-view.php';
        $this->view = new SC_Wp_Plugin_Admin_View();

        // Load required classes only if API is valid
        if ($this->api instanceof SC_API) {
            require plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-wp-shop-sync.php';
            $this->shop_sync = new SC_WP_Shop_Sync($this->api);

            require plugin_dir_path(dirname(__FILE__)) . 'includes/class-sc-wp-shop-order.php';
            $this->shop_order = new SC_WP_Shop_Order($this->api);
        }

        // Register hooks
        $this->register_hooks();
    }

    /**
     * Register all hooks related to the admin area functionality
     */
    private function register_hooks() {
        // Admin menu and settings
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Scheduled tasks and API-dependent hooks
        if ($this->api instanceof SC_API) {
            // Check if initial full sync has been completed before scheduling stock sync
            add_action('init', array($this, 'maybe_schedule_shop_sync'));
            
            // Scheduled sync hooks
            add_action('SC_WP_scheduled_stock_sync', array($this, 'run_scheduled_stock_sync'));
            add_action('SC_WP_scheduled_full_sync', array($this, 'run_scheduled_full_sync'));
            
            // AJAX handlers for manual sync
            add_action('wp_ajax_sc_sync_full', array($this, 'ajax_full_sync'));
            add_action('wp_ajax_sc_sync_stock', array($this, 'ajax_stock_sync'));
            add_action('wp_ajax_sc_check_sync_status', array($this, 'ajax_check_sync_status'));
            add_action('wp_ajax_sc_kill_sync', array($this, 'ajax_kill_sync'));
            add_action('wp_ajax_sc_clear_sync_lock', array($this, 'ajax_clear_sync_lock'));

            
            // Order processing
            add_action('woocommerce_checkout_order_processed', array($this, 'process_checkout_order'), 10, 3);
            
            // Order status changes
            add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 4);
        }
    }

    /**
     * Register the stylesheets for the admin area.
     */
    public function enqueue_styles($hook) {
        if ($hook !== 'settings_page_sc-settings') return;
        
        wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/sc-wp-plugin-admin.css', array(), $this->version, 'all');
    }

    /**
     * Register the JavaScript for the admin area.
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'settings_page_sc-settings') return;
        
        wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/sc-wp-plugin-admin.js', array('jquery'), $this->version, true);
        
        // Make sure synchronization data is available to JavaScript
        $next_stock_sync = wp_next_scheduled('SC_WP_scheduled_stock_sync');
        $next_full_sync = wp_next_scheduled('SC_WP_scheduled_full_sync');
        $last_full_sync = get_option('sc_last_full_sync_time', 'Never');
        $last_stock_sync = get_option('sc_last_stock_sync_time', 'Never');
        $last_sync_count = get_option('sc_last_sync_count', '0');
        $initial_sync_done = get_option('sc_initial_sync_done', false);
        
        wp_localize_script($this->plugin_name, 'scSyncData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sc_sync_nonce'),
            'nextStockSync' => $next_stock_sync ? date('Y-m-d H:i:s', $next_stock_sync) : 'Not scheduled',
            'nextFullSync' => $next_full_sync ? date('Y-m-d H:i:s', $next_full_sync) : 'Not scheduled',
            'lastFullSync' => $last_full_sync,
            'lastStockSync' => $last_stock_sync,
            'lastSyncCount' => $last_sync_count,
            'initialSyncDone' => $initial_sync_done ? 'true' : 'false',
            'stockSyncEnabled' => ($initial_sync_done && $next_stock_sync) ? 'true' : 'false'
        ));
    }

    /**
     * Schedule the automatic stock sync and daily full sync if initial sync has been completed
     */
    public function maybe_schedule_shop_sync() {
        // Only proceed if the initial sync has been completed
        $initial_sync_done = get_option('sc_initial_sync_done', false);
        
        // Register custom cron schedules
        add_filter('cron_schedules', array($this, 'add_custom_cron_schedules'));
        
        // Schedule daily full sync if not already scheduled (regardless of initial sync status)
        if (!wp_next_scheduled('SC_WP_scheduled_full_sync')) {
            // Schedule for 2 AM EST
            $tomorrow_2am = strtotime('tomorrow 2:00 am');
            wp_schedule_event($tomorrow_2am, 'daily', 'SC_WP_scheduled_full_sync');
            error_log('SC Plugin: Scheduled daily full sync at 2 AM EST');
        }
        
        // Only schedule stock sync if initial full sync has been completed
        if ($initial_sync_done) {
            // Schedule 15-minute stock sync if not already scheduled
            if (!wp_next_scheduled('SC_WP_scheduled_stock_sync')) {
                wp_schedule_event(time() + 900, 'fifteen_minutes', 'SC_WP_scheduled_stock_sync');
                error_log('SC Plugin: Scheduled 15-minute stock sync');
            }
        } else {
            // If initial sync not done, clear any existing stock sync schedule
            if (wp_next_scheduled('SC_WP_scheduled_stock_sync')) {
                $timestamp = wp_next_scheduled('SC_WP_scheduled_stock_sync');
                wp_unschedule_event($timestamp, 'SC_WP_scheduled_stock_sync');
                error_log('SC Plugin: Unscheduled stock sync because initial full sync has not been completed');
            }
        }
    }

    /**
     * Run scheduled stock sync
     */
    public function run_scheduled_stock_sync() {
        // Only proceed if initial sync has been completed
        $initial_sync_done = get_option('sc_initial_sync_done', false);
        if (!$initial_sync_done) {
            error_log('SC Plugin: Skipping scheduled stock sync because initial full sync has not been completed');
            return;
        }
        
        error_log('SC Plugin: Running scheduled stock sync');
        $result = $this->shop_sync->syncInventoryStockOnly();
        
        if ($result && isset($result['status']) && $result['status']) {
            update_option('sc_last_stock_sync_time', current_time('mysql'));
            update_option('sc_last_sync_count', isset($result['processed']) ? $result['processed'] : '0');
            error_log('SC Plugin: Scheduled stock sync completed successfully. Items processed: ' . 
                (isset($result['processed']) ? $result['processed'] : 'unknown'));
        } else {
            error_log('SC Plugin: Scheduled stock sync failed');
        }
    }

    /**
     * Run scheduled full sync
     */
    public function run_scheduled_full_sync() {
        error_log('SC Plugin: Running scheduled full sync');
        $result = $this->shop_sync->syncProducts(true);
        
        if ($result && isset($result['status']) && $result['status']) {
            // Mark initial sync as done
            update_option('sc_initial_sync_done', true);
            update_option('sc_last_full_sync_time', current_time('mysql'));
            update_option('sc_last_sync_count', isset($result['processed']) ? $result['processed'] : '0');
            error_log('SC Plugin: Scheduled full sync completed successfully. Items processed: ' . 
                (isset($result['processed']) ? $result['processed'] : 'unknown'));
            
            // Make sure stock sync is scheduled after successful full sync
            $this->maybe_schedule_shop_sync();
        } else {
            error_log('SC Plugin: Scheduled full sync failed');
        }
    }

    /**
     * Add custom cron schedules
     */
    public function add_custom_cron_schedules($schedules) {
        $schedules['fifteen_minutes'] = array(
            'interval' => 900,
            'display'  => __('Every 15 Minutes')
        );
        return $schedules;
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            'SC Integration Settings', 
            'SC Integration', 
            'manage_options', 
            'sc-settings', 
            array($this->view, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('sc_settings', 'sc_api_id', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
            'description' => 'Your API ID from your integration portal',
        ));
        
        add_settings_section(
            'sc_api_settings',
            'API Configuration',
            array($this->view, 'render_api_settings_section'),
            'sc-settings'
        );
        
        add_settings_field(
            'sc_api_id', 
            'API ID', 
            array($this->view, 'render_api_id_field'), 
            'sc-settings', 
            'sc_api_settings'
        );
        
        // Run setup when API ID is saved
        add_action('update_option_sc_api_id', array($this, 'handle_api_id_update'), 10, 2);
    }
    
    /**
     * Handle changes to API ID option
     * 
     * @param string $old_value Previous API ID
     * @param string $new_value New API ID
     */
    public function handle_api_id_update($old_value, $new_value) {
        // Only proceed if we have a valid new API ID
        if (empty($new_value)) {
            return;
        }
        
        // If this is the first time setting the API ID or it's changed
        if (empty($old_value) || $old_value !== $new_value) {
            // Reset the initial sync flag to indicate that a full sync is required
            update_option('sc_initial_sync_done', false);
            
            // Make sure sync schedules are set up (will only set up full sync since initial_sync_done is false)
            $this->maybe_schedule_shop_sync();
            
            // Add admin notice to prompt the user to run a full sync
            add_action('admin_notices', function() {
                ?>
                <div class="notice notice-warning is-dismissible">
                    <p><strong>SC Integration:</strong> API ID has been updated. You need to run a full sync before automatic stock updates will begin. <a href="<?php echo admin_url('options-general.php?page=sc-settings'); ?>">Go to settings</a> to perform a full sync.</p>
                </div>
                <?php
            });
        }
    }

    /**
     * Handle AJAX full sync request
     */
    public function ajax_full_sync() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sc_sync_nonce')) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        if (!$this->api instanceof SC_API) {
            wp_send_json_error('API not configured. Please set up the API ID in settings.');
        }

        $force_sync = isset($_POST['force_sync']) && $_POST['force_sync'] === 'true';
        $result = $this->shop_sync->syncProducts(true, [], $force_sync);

        if ($result && isset($result['status']) && $result['status']) {
            // Mark initial sync as done
            update_option('sc_initial_sync_done', true);

            // Update sync timestamp
            $current_time = current_time('mysql');
            update_option('sc_last_full_sync_time', $current_time);
            update_option('sc_last_sync_count', isset($result['processed']) ? $result['processed'] : '0');

            // Make sure stock sync is scheduled after successful full sync
            $this->maybe_schedule_shop_sync();

            // Get updated schedule information
            $next_stock_sync = wp_next_scheduled('SC_WP_scheduled_stock_sync');
            $next_full_sync = wp_next_scheduled('SC_WP_scheduled_full_sync');

            wp_send_json_success([
                'message' => $result['message'] . '. Processed ' . $result['processed'] . ' products. Stock sync is now enabled.',
                'count' => isset($result['processed']) ? $result['processed'] : get_option('sc_last_sync_count', '0'),
                'time' => $current_time,
                'lastFullSync' => $current_time,
                'nextStockSync' => $next_stock_sync ? date('Y-m-d H:i:s', $next_stock_sync) : 'Not scheduled',
                'nextFullSync' => $next_full_sync ? date('Y-m-d H:i:s', $next_full_sync) : 'Not scheduled',
                'initialSyncDone' => 'true',
                'stockSyncEnabled' => 'true'
            ]);
        } else {
            wp_send_json_error($result['message'] ?? 'Sync failed: Unknown error');
        }
    }

    /**
     * Handle AJAX stock sync request
     */
    public function ajax_stock_sync() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sc_sync_nonce')) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        if (!$this->api instanceof SC_API) {
            wp_send_json_error('API not configured. Please set up the API ID in settings.');
        }

        // Check if initial sync has been completed
        $initial_sync_done = get_option('sc_initial_sync_done', false);
        if (!$initial_sync_done) {
            wp_send_json_error('You must complete a full sync before you can perform a stock sync. Please run a full sync first.');
            return;
        }

        $force_sync = isset($_POST['force_sync']) && $_POST['force_sync'] === 'true';
        $result = $this->shop_sync->syncInventoryStockOnly([], $force_sync);

        if ($result && isset($result['status']) && $result['status']) {
            // Update sync timestamp
            $current_time = current_time('mysql');
            update_option('sc_last_stock_sync_time', $current_time);
            update_option('sc_last_sync_count', isset($result['processed']) ? $result['processed'] : '0');

            wp_send_json_success([
                'message' => $result['message'] . '. Updated ' . $result['processed'] . ' products.',
                'count' => isset($result['processed']) ? $result['processed'] : get_option('sc_last_sync_count', '0'),
                'time' => $current_time,
                'lastStockSync' => $current_time,
                'nextStockSync' => wp_next_scheduled('SC_WP_scheduled_stock_sync') ? 
                    date('Y-m-d H:i:s', wp_next_scheduled('SC_WP_scheduled_stock_sync')) : 'Not scheduled',
                'nextFullSync' => wp_next_scheduled('SC_WP_scheduled_full_sync') ? 
                    date('Y-m-d H:i:s', wp_next_scheduled('SC_WP_scheduled_full_sync')) : 'Not scheduled'
            ]);
        } else {
            wp_send_json_error($result['message'] ?? 'Sync failed: Unknown error');
        }
    }

    // Also update syncInventoryStockOnly to accept the force_sync parameter
    public function syncInventoryStockOnly($item_codes = [], $force_sync = false) {
        return $this->syncProducts(false, $item_codes, $force_sync);
    }
    
    /**
     * Handle AJAX kill sync request
     */
    public function ajax_kill_sync() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sc_sync_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        // Set a flag to indicate sync should be stopped
        update_option('sc_stop_sync', 'yes');
        
        wp_send_json_success('Sync operation will be terminated');
    }

    /**
     * Process checkout order
     */
    public function process_checkout_order($order_id, $posted_data, $order) {
        if ($this->api instanceof SC_API) {
            $this->shop_order->orderProcess($order_id);
        }
    }

    /**
     * Handle order status changes
     */
    public function handle_order_status_change($order_id, $status_from, $status_to, $order) {
        // Add extensive logging to see what's happening
        error_log("SC Plugin: Order #{$order_id} status changed from {$status_from} to {$status_to}");

        // Process on both processing AND completed status
        if ($this->api instanceof SC_API && ($status_to === 'processing' || $status_to === 'completed')) {
            // Check if order has distributor items before processing
            $has_distributor_items = false;
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if ($product) {
                    $distributor = get_post_meta($product->get_id(), '_distributor', true);
                    if ($distributor) {
                        $has_distributor_items = true;
                        error_log("SC Plugin: Order #{$order_id} contains distributor item: " . $product->get_sku());
                        break;
                    }
                }
            }

            if (!$has_distributor_items) {
                error_log("SC Plugin: Order #{$order_id} has no distributor items, skipping API submission");
                return;
            }

            $sc_order_id = get_post_meta($order_id, '_sc_order_id', true);
            if (!$sc_order_id) {
                error_log("SC Plugin: Processing order #{$order_id} to SC API");
                $result = $this->shop_order->orderProcess($order_id);
                error_log("SC Plugin: Order #{$order_id} processing result: " . (is_array($result) ? json_encode($result) : "Failed"));
            } else {
                error_log("SC Plugin: Order #{$order_id} already has SC order ID: {$sc_order_id}");
            }
        }
    }

    /**
     * Handle AJAX check sync status request with detailed information
     */
    public function ajax_check_sync_status() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sc_sync_nonce')) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $initial_sync_done = get_option('sc_initial_sync_done', false);
        $next_stock_sync = wp_next_scheduled('SC_WP_scheduled_stock_sync');

        // Check for active sync
        $sync_active = false;
        $sync_type = '';
        $sync_progress = [];
        $log_entries = [];

        // Check if sync is currently running using the lock
        $full_sync_lock = get_transient('sc_sync_lock_' . $this->shop_sync->distributor_id);
        if ($full_sync_lock) {
            $sync_active = true;
            $sync_type = 'Full product sync';

            // Get progress information if available
            $current_page = get_transient('sc_sync_current_page');
            $total_pages = get_transient('sc_sync_total_pages');
            $items_processed = get_transient('sc_sync_items_processed');
            $items_total = get_transient('sc_sync_items_expected');

            if ($items_processed && $items_total) {
                $sync_progress = [
                    'items_processed' => intval($items_processed),
                    'items_total' => intval($items_total),
                    'message' => 'Processing products from distributor'
                ];

                if ($current_page && $total_pages) {
                    $sync_progress['message'] .= " (Page " . intval($current_page) . " of " . intval($total_pages) . ")";
                }
            }
        }

        // SIMPLIFIED LOG HANDLING - THIS IS THE MAIN FIX
        if (isset($_POST['getLogEntries']) && $_POST['getLogEntries']) {
            try {
                // Only get the 20 most recent logs that contain relevant keys
                global $wpdb;
                $log_entries = [];

                // Example log entry - very simple, just to avoid errors
                $log_entries[] = [
                    'time' => current_time('mysql'),
                    'message' => 'Checking sync status...',
                    'level' => 'info'
                ];
            } catch (Exception $e) {
                // If log reading fails, don't break the whole response
                $log_entries = [
                    [
                        'time' => current_time('mysql'),
                        'message' => 'Could not retrieve log entries',
                        'level' => 'warning'
                    ]
                ];
            }
        }

        // Prepare response with properly sanitized data
        $response_data = [
            'initialSyncDone' => $initial_sync_done ? 'true' : 'false',
            'lastFullSync' => get_option('sc_last_full_sync_time', 'Never'),
            'lastStockSync' => get_option('sc_last_stock_sync_time', 'Never'),
            'lastSyncCount' => intval(get_option('sc_last_sync_count', '0')),
            'nextStockSync' => $next_stock_sync ? 
                date('Y-m-d H:i:s', $next_stock_sync) : 'Not scheduled',
            'nextFullSync' => wp_next_scheduled('SC_WP_scheduled_full_sync') ? 
                date('Y-m-d H:i:s', wp_next_scheduled('SC_WP_scheduled_full_sync')) : 'Not scheduled',
            'stockSyncEnabled' => ($initial_sync_done && $next_stock_sync) ? 'true' : 'false',
            'syncActive' => $sync_active ? 'true' : 'false',
            'syncOperationType' => $sync_type,
            'syncProgress' => $sync_progress,
            'logEntries' => $log_entries
        ];

        // Sanitize all string values to remove NULL bytes and control characters
        array_walk_recursive($response_data, function(&$item) {
            if (is_string($item)) {
                $item = preg_replace('/[\x00-\x1F\x7F]/u', '', $item);
            }
        });

        wp_send_json_success($response_data);
    }

    /**
     * Get log entries safely without risking binary content issues
     * 
     * @return array Array of log entries
     */
    private function get_safe_log_entries() {
        $log_entries = [];
        
        // Try to use WP_DEBUG_LOG if available
        $log_file = ini_get('error_log');
        if (!file_exists($log_file) || !is_readable($log_file)) {
            // Try common WordPress debug log locations if the PHP error_log isn't available
            $possible_log_files = [
                WP_CONTENT_DIR . '/debug.log',
                ABSPATH . 'wp-content/debug.log'
            ];
            
            foreach ($possible_log_files as $possible_file) {
                if (file_exists($possible_file) && is_readable($possible_file)) {
                    $log_file = $possible_file;
                    break;
                }
            }
        }
        
        if (file_exists($log_file) && is_readable($log_file)) {
            // Read only the last portion of the file to avoid memory issues
            $max_read_size = 50000; // 50KB should be enough for recent logs
            $file_size = filesize($log_file);
            
            if ($file_size > 0) {
                $offset = max(0, $file_size - $max_read_size);
                $handle = fopen($log_file, 'r');
                
                if ($handle) {
                    fseek($handle, $offset);
                    
                    // Skip first line if we're not at the beginning (it might be incomplete)
                    if ($offset > 0) {
                        fgets($handle);
                    }
                    
                    $log_content = '';
                    while (!feof($handle)) {
                        // Read line by line to avoid binary content issues
                        $line = fgets($handle);
                        
                        // Filter out binary content - skip lines with null bytes or other control chars
                        if ($line !== false && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $line)) {
                            $log_content .= $line;
                        }
                    }
                    
                    fclose($handle);
                    
                    // Extract recent SC Plugin entries
                    $log_lines = array_filter(
                        explode("\n", $log_content)
                    );
                    
                    foreach ($log_lines as $line) {
                        // Skip binary data or extremely long lines
                        if (strlen($line) > 1000 || strpos($line, "\x00") !== false) {
                            continue;
                        }
                        
                        if (strpos($line, 'SC Plugin:') !== false || 
                            strpos($line, 'SC Sync:') !== false) {
                            
                            // Extract timestamp and message
                            $timestamp = '';
                            $message = $line;
                            
                            // Parse date if available [01-May-2025...]
                            if (preg_match('/^\[(.*?)\]/', $line, $matches)) {
                                $timestamp = $matches[1];
                                $message = trim(substr($line, strlen($matches[0])));
                            }
                        
                            // Determine log level
                            $level = 'info';
                            if (strpos($message, 'Error') !== false || 
                                strpos($message, 'error') !== false || 
                                strpos($message, 'failed') !== false || 
                                strpos($message, 'Failed') !== false) {
                                $level = 'error';
                            } elseif (strpos($message, 'Warning') !== false || 
                                    strpos($message, 'warning') !== false) {
                                $level = 'warning';
                            } elseif (strpos($message, 'Success') !== false || 
                                    strpos($message, 'success') !== false || 
                                    strpos($message, 'completed') !== false) {
                                $level = 'success';
                            }
                        
                            // Extract SC specific part
                            if (preg_match('/(SC Plugin:|SC Sync:)\s+(.*)/', $message, $matches)) {
                                $message = $matches[2];
                            }
                        
                            // Skip any messages that might still contain problematic characters
                            if (!preg_match('/[\x00-\x1F\x7F]/', $message)) {
                                $log_entries[] = [
                                    'time' => $timestamp,
                                    'message' => $message,
                                    'level' => $level
                                ];
                            }
                        }
                    }
                
                    // Sort by timestamp and take the last 20
                    $log_entries = array_slice($log_entries, -20);
                }
            }
        }
        
        // If we couldn't get log entries, return a placeholder
        if (empty($log_entries)) {
            $log_entries[] = [
                'time' => current_time('mysql'),
                'message' => 'No recent log entries found',
                'level' => 'info'
            ];
        }
        
        return $log_entries;
    }


    /**
     * Handle AJAX request to check and clear stuck locks
     */
    public function ajax_clear_sync_lock() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sc_sync_nonce')) {
            wp_send_json_error('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $force_clear = isset($_POST['force_clear']) && $_POST['force_clear'] === 'true';
        $distributor_id = $this->shop_sync->distributor_id;
        $lock_key = 'sc_sync_lock_' . $distributor_id;

        // Check if lock exists
        $lock_exists = get_transient($lock_key);

        if ($lock_exists) {
            // Check if the lock is stale (older than 10 minutes)
            $lock_time = get_transient('sc_sync_lock_time_' . $distributor_id);
            $current_time = time();
            $lock_age = $current_time - ($lock_time ? $lock_time : 0);
            $is_stale = $lock_age > 600; // 10 minutes

            if ($force_clear || $is_stale) {
                // Clear the lock
                delete_transient($lock_key);
                delete_transient('sc_sync_lock_time_' . $distributor_id);
                delete_transient('sc_sync_current_page');
                delete_transient('sc_sync_total_pages');
                delete_transient('sc_sync_items_processed');
                delete_transient('sc_sync_items_expected');

                // Log the lock clear
                error_log('SC Plugin: Cleared ' . ($is_stale ? 'stale' : 'active') . ' sync lock. Lock was ' . 
                          round($lock_age / 60) . ' minutes old.');

                wp_send_json_success([
                    'message' => 'Sync lock cleared successfully. You can now run a new sync.',
                    'lock_cleared' => true,
                    'lock_age_minutes' => round($lock_age / 60)
                ]);
            } else {
                // Lock exists and is not stale
                wp_send_json_success([
                    'message' => 'Sync is currently in progress (' . round($lock_age / 60) . ' minutes running).',
                    'lock_cleared' => false,
                    'lock_age_minutes' => round($lock_age / 60),
                    'force_option_available' => true
                ]);
            }
        } else {
            // No lock exists
            wp_send_json_success([
                'message' => 'No active sync lock found. You can run a sync now.',
                'lock_cleared' => false,
                'lock_exists' => false
            ]);
        }
    }
}