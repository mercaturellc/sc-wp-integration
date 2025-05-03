<?php
/**
 * The admin view functionality of the plugin.
 *
 * @link       https://gitlab.com/mercature/sc-wp-integration
 * @since      1.0.0
 * @package    SC_Wp_Plugin
 * @subpackage SC_Wp_Plugin/admin
 */

/**
 * The admin view functionality of the plugin.
 *
 * @package    SC_Wp_Plugin
 * @subpackage SC_Wp_Plugin/admin
 * @author     Trent Mercer <trent@gitlab.com/mercature/sc-wp-integration>
 */
class SC_Wp_Plugin_Admin_View {

    /**
     * Render the settings page
     */
    public function render_settings_page() {
        global $wp_version;
        $this->display_admin_notices();
        
        // Check for active sync
        $distributor_id = get_option('sc_distributor_id', 'sc_distributor');
        $lock_key = 'sc_sync_lock_' . $distributor_id;
        $lock_exists = get_transient($lock_key);
        $sync_status_html = '';
        
        if ($lock_exists) {
            $lock_time = get_transient('sc_sync_lock_time_' . $distributor_id);
            $current_time = time();
            $lock_age = $current_time - ($lock_time ?: 0);
            $lock_age_minutes = round($lock_age / 60);
            
            // Get progress information if available
            $current_page = get_transient('sc_sync_current_page');
            $total_pages = get_transient('sc_sync_total_pages');
            $items_processed = get_transient('sc_sync_items_processed');
            $items_total = get_transient('sc_sync_items_expected');
            
            $sync_status_html = '<div class="notice notice-warning inline sc-active-sync-notice">';
            $sync_status_html .= '<p><strong>Sync in Progress</strong>: A sync operation has been running for ';
            $sync_status_html .= $lock_age_minutes . ' ' . ($lock_age_minutes === 1 ? 'minute' : 'minutes') . '.';
            
            if ($current_page && $total_pages) {
                $sync_status_html .= ' Processing page ' . $current_page . ' of ' . $total_pages . '.';
            }
            
            if ($items_processed && $items_total) {
                $percent = min(round(($items_processed / $items_total) * 100), 100);
                $sync_status_html .= ' ' . $items_processed . ' of ' . $items_total . ' items processed (' . $percent . '%).';
            }
            
            $sync_status_html .= '</p>';
            $sync_status_html .= '<p><button id="sc-stop-sync" class="button button-secondary">Stop Sync</button>';
            #$sync_status_html .= ' <button id="sc-force-clear-lock" class="button button-secondary">Force Clear Lock</button></p>';
            $sync_status_html .= '</p></div>';
        } else {
            $sync_status_html = '<div class="notice notice-success inline sc-no-sync-notice">';
            $sync_status_html .= '<p><strong>No Sync in Progress</strong>: System is ready for sync operations.</p>';
            $sync_status_html .= '</div>';
        }
        ?>
        <div class="wrap sc-settings-wrap">
            <h1>SC Shop Integration</h1>
            
            <div class="sc-settings-section">
                <h2>Integration Settings</h2>
                <p>
                    Configure your WooCommerce shop integration. Product selection is managed through the 
                    <a href="https://aztecimport.com/wholesale/integration" target="_blank">Integration Portal</a>.
                </p>
                
                <form method="post" action="options.php">
                    <?php
                    settings_fields('sc_settings');
                    do_settings_sections('sc-settings');
                    submit_button('Save API Settings');
                    ?>
                </form>
            </div>
            
            <div class="sc-settings-section">
                <h2>Product Synchronization</h2>
                <p>Your store automatically syncs inventory every 15 minutes, with a full product sync running daily at 2 AM. You can also manually trigger a sync.</p>
                
                <!-- Sync Status Indicator -->
                <div class="sc-sync-status-indicator">
                    <?php echo $sync_status_html; ?>
                </div>
                
                <table class="form-table sc-sync-info">
                    <tr>
                        <th>Last Full Sync:</th>
                        <td class="sc-last-full-sync">
                            <?php 
                                $last_full_sync = get_option('sc_last_full_sync_time', 'Never');
                                echo esc_html($last_full_sync); 
                            ?>
                            <span class="sc-full-sync-status sc-status-indicator"></span>
                        </td>
                    </tr>
                    <tr>
                        <th>Last Stock Sync:</th>
                        <td class="sc-last-stock-sync">
                            <?php 
                                $last_stock_sync = get_option('sc_last_stock_sync_time', 'Never');
                                echo esc_html($last_stock_sync); 
                            ?>
                            <span class="sc-stock-sync-status sc-status-indicator"></span>
                        </td>
                    </tr>
                    <tr>
                        <th>Next Stock Sync:</th>
                        <td class="sc-next-stock-sync"><?php 
                            $next_stock_sync = wp_next_scheduled('SC_WP_scheduled_stock_sync');
                            echo $next_stock_sync ? date('Y-m-d H:i:s', $next_stock_sync) : 'Not scheduled'; 
                        ?></td>
                    </tr>
                    <tr>
                        <th>Next Full Sync:</th>
                        <td class="sc-next-full-sync"><?php 
                            $next_full_sync = wp_next_scheduled('SC_WP_scheduled_full_sync');
                            echo $next_full_sync ? date('Y-m-d H:i:s', $next_full_sync) : 'Not scheduled'; 
                        ?></td>
                    </tr>
                    <tr>
                        <th>Products Updated:</th>
                        <td class="sc-last-sync-count"><?php echo get_option('sc_last_sync_count', '0'); ?></td>
                    </tr>
                </table>
                
                <div class="sc-sync-buttons">
                    <button id="sc-sync-stock" class="button button-secondary" <?php echo $lock_exists ? 'disabled' : ''; ?>>Sync Inventory Only</button>
                    <button id="sc-sync-full" class="button button-primary" <?php echo $lock_exists ? 'disabled' : ''; ?>>Full Sync (Products & Inventory)</button>
                    <!--
                    <button id="sc-check-lock" class="button button-secondary">Check Sync Status</button>
                    -->
                </div>
                
                <div class="sc-sync-progress <?php echo $lock_exists ? '' : 'hidden'; ?>">
                    <div class="sc-sync-progress-message">
                        <?php
                        if ($lock_exists) {
                            echo 'Synchronizing...';
                            if ($current_page && $total_pages) {
                                echo " (Page {$current_page} of {$total_pages})";
                            }
                        }
                        ?>
                    </div>
                    <div class="sc-sync-progress-bar">
                        <div class="sc-sync-progress-bar-inner" style="<?php 
                            if ($lock_exists && $items_processed && $items_total) {
                                $percent = min(round(($items_processed / $items_total) * 100), 100);
                                echo "width: {$percent}%";
                            } else {
                                echo "width: 0%";
                            }
                        ?>"></div>
                    </div>
                    <?php if ($lock_exists && $items_processed && $items_total): ?>
                    <div class="sc-sync-progress-detail">
                        Processed <?php echo $items_processed; ?> of <?php echo $items_total; ?> items
                    </div>
                    <?php endif; ?>
                </div>
                    
                <div class="sc-sync-notifications"></div>
            </div>
                    
            <!-- Log display area will be inserted by JS -->
                    
            <div class="sc-settings-section">
                <h2>Order Processing</h2>
                <p>
                    When customers place orders on your WooCommerce store, they are automatically submitted for processing. Orders will be fulfilled directly by your distributor.
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Render API settings section
     */
    public function render_api_settings_section() {
        echo '<p>Enter your API credentials to connect with your wholesale distributor account.</p>';
    }

    /**
     * Render API ID field
     */
    public function render_api_id_field() {
        $api_id = get_option('sc_api_id', '');
        ?>
        <input type="text" id="sc_api_id" name="sc_api_id" value="<?php echo esc_attr($api_id); ?>" class="regular-text" />
        <p class="description">Your API ID from your integration portal</p>
        <?php
    }

    /**
     * Display admin notices
     */
    public function display_admin_notices() {
        if (isset($_GET['sync']) && $_GET['sync'] === 'success') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>Synchronization completed successfully. <?php echo esc_html(get_option('sc_last_sync_count', '0')); ?> products were updated.</p>
            </div>
            <?php
        } elseif (isset($_GET['sync']) && $_GET['sync'] === 'error') {
            ?>
            <div class="notice notice-error is-dismissible">
                <p>Synchronization failed. Please check your API settings and try again.</p>
            </div>
            <?php
        }
        
        if (!get_option('sc_api_id', '')) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>Please enter your API ID to enable the integration.</p>
            </div>
            <?php
        }
        
        // Show appropriate notices for sync status
        if (get_option('sc_api_id', '')) {
            if (!get_option('sc_initial_sync_done', false)) {
                ?>
                <div class="notice notice-info is-dismissible">
                    <p>Initial product synchronization is in progress. This may take a few minutes to complete.</p>
                </div>
                <?php
            } else {
                // Show last sync status if it exists
                $last_sync_time = get_option('sc_last_full_sync_time', '');
                $last_sync_count = get_option('sc_last_sync_count', 0);
                
                if (!empty($last_sync_time) && $last_sync_count > 0 && $last_sync_time !== 'Never') {
                    ?>
                    <div class="notice notice-success is-dismissible">
                        <p>Your shop has been synchronized. Last full sync: <?php echo esc_html($last_sync_time); ?> (<?php echo intval($last_sync_count); ?> products)</p>
                    </div>
                    <?php
                }
            }
        }
    }
}