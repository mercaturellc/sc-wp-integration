<?php
/**
 * Simplified admin view - clean and focused
 */
class SC_Wp_Plugin_Admin_View {

    public function render_settings_page() {
        $api_id = get_option('sc_api_id', '');
        $batch_size = get_option('sc_api_rows_per_request', 2000);
        $auto_sync = get_option('sc_auto_sync_enabled', 'yes') === 'yes';
        $cart_validation = get_option('sc_cart_validation_enabled', 'yes') === 'yes';
        $last_sync = get_option('sc_last_sync_time', 'Never');
        $last_count = get_option('sc_last_sync_count', 0);
        $sync_active = get_transient('sc_sync_lock');
        
        $wc_categories = $this->getWooCommerceCategories();
        $product_count = $this->getProductCount();
        ?>
        <div class="wrap">
            <h1>SC Shop Integration</h1>
            <p class="sc-help-link">
                <a href="https://sc.mercature.net/static/docs/setup_guide/welcome.html" target="_blank">📚 Documentation & Setup Guide</a>
            </p>
            
            <!-- Configuration -->
            <div class="card">
                <h2>Configuration</h2>
                <form method="post" action="options.php">
                    <?php settings_fields('sc_settings'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">API ID</th>
                            <td>
                                <input type="text" name="sc_api_id" value="<?php echo esc_attr($api_id); ?>" 
                                       class="regular-text" placeholder="Enter your API ID" />
                                <p class="description">Get this from your distributor integration portal</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">Batch Size</th>
                            <td>
                                <input type="number" name="sc_api_rows_per_request" 
                                       value="<?php echo esc_attr($batch_size); ?>" 
                                       min="500" max="5000" class="small-text" />
                                <p class="description">Products per API request (500-5000). Higher = faster sync.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">Daily Auto-Sync</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sc_auto_sync_enabled" value="yes" 
                                           <?php checked($auto_sync); ?> />
                                    Enable automatic daily sync at 3 AM
                                </label>
                                <p class="description">Keeps your products automatically updated every day</p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button(); ?>
                </form>
            </div>

            <?php if (!empty($api_id)): ?>
            <!-- Status -->
            <div class="card">
                <h2>Sync Status</h2>
                
                <div class="sync-stats">
                    <div class="stat">
                        <strong><?php echo number_format($product_count); ?></strong>
                        <span>Products</span>
                    </div>
                    <div class="stat">
                        <strong><?php echo count($wc_categories); ?></strong>
                        <span>Categories</span>
                    </div>
                    <div class="stat">
                        <strong><?php echo number_format($last_count); ?></strong>
                        <span>Last Sync</span>
                    </div>
                </div>

                <?php if (!empty($wc_categories)): ?>
                <div class="categories-info">
                    <h4>Categories to Sync:</h4>
                    <div class="category-list">
                        <?php foreach ($wc_categories as $category): ?>
                            <span class="category-tag"><?php echo esc_html($category); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <p class="description">Products from your distributor matching these category names will be synced.</p>
                </div>
                <?php else: ?>
                <div class="notice notice-warning inline">
                    <p><strong>No categories found.</strong> 
                    <a href="<?php echo admin_url('edit-tags.php?taxonomy=product_cat&post_type=product'); ?>">Create product categories</a> 
                    to enable sync.</p>
                </div>
                <?php endif; ?>

                <?php if ($sync_active): ?>
                <div class="notice notice-info inline">
                    <p><strong>Sync in progress...</strong> Please wait for it to complete.</p>
                </div>
                <?php endif; ?>

                <p>
                    <button id="run-sync" class="button button-primary" 
                            <?php echo $sync_active ? 'disabled' : ''; ?>>
                        <?php echo $sync_active ? 'Syncing...' : 'Run Sync Now'; ?>
                    </button>
                </p>
                
                <div class="sync-info">
                    <p><strong>Last Sync:</strong> <?php echo $last_sync === 'Never' ? 'Never' : date('M j, Y g:i A', strtotime($last_sync)); ?></p>
                    <?php if ($auto_sync): ?>
                    <p><strong>Next Auto-Sync:</strong> Tomorrow at 3:00 AM</p>
                    <?php endif; ?>
                    
                    <div class="image-matching-info">
                        <h4>📷 Automatic Image Matching</h4>
                        <p><strong>How it works:</strong> During sync, products are automatically assigned images from your Media Library when the image filename matches the product SKU.</p>
                        <p><strong>Supported formats:</strong> JPG, PNG, WebP (e.g., <code>SKU123.jpg</code>, <code>ABC-456.png</code>)</p>
                    </div>
                </div>

                <div id="sync-messages"></div>

            </div>

            <?php endif; ?>
        </div>

        <style>
        .sc-help-link {
            text-align: right;
            margin: -10px 0 20px 0;
        }
        
        .sc-help-link a {
            text-decoration: none;
            color: #646970;
            font-size: 14px;
        }
        
        .sc-help-link a:hover {
            color: #0073aa;
        }
        
        .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            padding: 20px;
            margin: 20px 0;
        }
        
        .card h2 {
            margin-top: 0;
            font-size: 1.3em;
        }
        
        .sync-stats {
            display: flex;
            gap: 30px;
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        
        .stat {
            text-align: center;
        }
        
        .stat strong {
            display: block;
            font-size: 24px;
            color: #0073aa;
            line-height: 1.2;
        }
        
        .stat span {
            font-size: 12px;
            color: #646970;
            text-transform: uppercase;
        }
        
        .categories-info {
            margin: 20px 0;
        }
        
        .category-list {
            margin: 10px 0;
        }
        
        .category-tag {
            display: inline-block;
            background: #0073aa;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            margin: 2px;
        }
        
        .sync-info {
            margin-top: 15px;
            padding: 10px;
            background: #f6f7f7;
            border-radius: 4px;
        }
        
        .sync-info p {
            margin: 5px 0;
        }
        
        .image-matching-info {
            background: #e8f4fd;
            border: 1px solid #b8daff;
            border-radius: 4px;
            padding: 15px;
            margin: 15px 0;
        }
        
        .image-matching-info h4 {
            margin: 0 0 10px 0;
            color: #0073aa;
        }
        
        .image-matching-info p {
            margin: 8px 0;
            font-size: 14px;
        }
        
        .image-matching-info code {
            background: #f1f1f1;
            padding: 2px 4px;
            border-radius: 3px;
            font-family: Consolas, Monaco, monospace;
        }
        
        .cart-validation-info {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 4px;
            padding: 15px;
            margin: 15px 0;
        }
        
        .cart-validation-info h4 {
            margin: 0 0 10px 0;
            color: #0369a1;
        }
        
        .cart-validation-info p {
            margin: 8px 0;
            font-size: 14px;
        }
        
        .notice.inline {
            margin: 15px 0;
        }
        
        #sync-messages .notice {
            margin: 15px 0;
        }
        
        @media (max-width: 768px) {
            .sync-stats {
                flex-direction: column;
                gap: 15px;
            }
            
            .stat {
                display: flex;
                align-items: center;
                gap: 10px;
                text-align: left;
            }
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            $('#run-sync').on('click', function(e) {
                e.preventDefault();
                
                if ($(this).prop('disabled')) return;
                
                if (!confirm('Run product sync now? This may take a few minutes.')) {
                    return;
                }
                
                var $button = $(this);
                var $messages = $('#sync-messages');
                
                $button.prop('disabled', true).text('Syncing...');
                $messages.html('<div class="notice notice-info"><p>Starting sync...</p></div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'sc_run_sync',
                        nonce: '<?php echo wp_create_nonce('sc_sync_nonce'); ?>'
                    },
                    timeout: 300000, // 5 minute timeout
                    success: function(response) {
                        if (response.success) {
                            var message = 'Sync completed successfully!';
                            if (response.data.processed) {
                                message += ' Processed ' + response.data.processed + ' items.';
                            }
                            if (response.data.created) {
                                message += ' Created ' + response.data.created + ' new products.';
                            }
                            if (response.data.deleted) {
                                message += ' Removed ' + response.data.deleted + ' discontinued items.';
                            }
                            
                            $messages.html('<div class="notice notice-success"><p>' + message + '</p></div>');
                            
                            // Refresh page after delay
                            setTimeout(function() {
                                window.location.reload();
                            }, 3000);
                            
                        } else {
                            $messages.html('<div class="notice notice-error"><p>Sync failed: ' + 
                                         (response.data.message || 'Unknown error') + '</p></div>');
                            $button.prop('disabled', false).text('Run Sync Now');
                        }
                    },
                    error: function(xhr, status, error) {
                        $messages.html('<div class="notice notice-error"><p>Connection error: ' + error + '</p></div>');
                        $button.prop('disabled', false).text('Run Sync Now');
                    }
                });
            }
            
            function assignImages() {
                var $button = $('#assign-images');
                var $messages = $('#sync-messages');
                
                $button.prop('disabled', true).text('Assigning Images...');
                $messages.html('<div class="notice notice-info"><p>Looking for images to assign...</p></div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'sc_assign_images',
                        nonce: '<?php echo wp_create_nonce('sc_sync_nonce'); ?>'
                    },
                    timeout: 60000, // 1 minute timeout
                    success: function(response) {
                        if (response.success) {
                            var message = 'Image assignment completed!';
                            if (response.data.assigned) {
                                message += ' Assigned images to ' + response.data.assigned + ' products';
                            }
                            if (response.data.processed) {
                                message += ' (checked ' + response.data.processed + ' products)';
                            }
                            
                            $messages.html('<div class="notice notice-success"><p>' + message + '</p></div>');
                            
                        } else {
                            $messages.html('<div class="notice notice-error"><p>Image assignment failed: ' + 
                                         (response.data.message || 'Unknown error') + '</p></div>');
                        }
                        
                    },
                    error: function(xhr, status, error) {
                        $messages.html('<div class="notice notice-error"><p>Connection error: ' + error + '</p></div>');
                    }
                });
            });
        });
        </script>
        <?php
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

    private function getProductCount() {
        global $wpdb;
        $count = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} 
            WHERE post_type = 'product' AND post_status = 'publish'
        ");
        return intval($count);
    }
}