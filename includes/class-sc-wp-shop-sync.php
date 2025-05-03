<?php
/* SC_WP_Shop_Sync
Sync WooCommerce Shop Inventory with Distributors
uses: SC_API
*/

class SC_WP_Shop_Sync {
    private $api;
    private $distributor_id = 'AZT';
    private $batch_size = 50; // Reduced for better memory management
    #private $max_sync_items = 100; // Limit total items per sync
    private $categories_map = []; // Cache for category mapping
    // Special categories
    private $special_categories = [
        'special' => '!',  // Special character for Specials category
        'back_in_stock' => '^', // Special character for Back In Stock category
        'new' => '@'      // Special character for New category
    ];
    
    // Add server configuration check
    public function __construct(SC_API $api, $distributor_id = null) {
        $this->api = $api;
        $this->distributor_id = $distributor_id ?: get_option('sc_distributor_id', 'sc_distributor');

        // Adjust batch size based on server memory
        $memory_limit = $this->getServerMemoryLimit();
        if ($memory_limit > 256) {
            $this->batch_size = 100; // More memory available, process more at once
        } elseif ($memory_limit < 128) {
            $this->batch_size = 25; // Less memory, be more conservative
        }
    }

    private function getServerMemoryLimit() {
        $memory_limit = ini_get('memory_limit');
        if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
            if ($matches[2] == 'M') {
                return $matches[1]; // Return MB as integer
            }
        }
        return 128; // Default assumption
    }

    /**
     * Validate configuration settings
     * 
     * @return array Status and message
     */
    public function validateConfig() {
        $api_id = get_option('sc_api_id', '');
        $errors = [];

        if (empty($api_id)) {
            $errors[] = 'API ID is not configured';
        }

        if (empty($this->distributor_id)) {
            $errors[] = 'Distributor ID is not configured';
        }

        if (!function_exists('wc_get_product_id_by_sku')) {
            $errors[] = 'WooCommerce is not active';
        }

        if (!empty($errors)) {
            error_log('SC Sync: Configuration errors: ' . implode(', ', $errors));
            return ['status' => false, 'message' => implode(', ', $errors)];
        }

        return ['status' => true, 'message' => 'Configuration valid'];
    }

    /**
     * Synchronize products from SC API to WooCommerce
     * 
     * @param bool $full_sync Whether to perform a full sync ('F') or partial sync ('P')
     * @param array $item_codes Optional list of SKUs for partial sync
     * @param bool $force_sync Whether to force sync even if another sync is in progress
     * @return array Status, message, and processed count
     */
    public function syncProducts($full_sync = false, $item_codes = [], $force_sync = false) {
        try {
            $config = $this->validateConfig();
            if (!$config['status']) {
                return [
                    'status' => false,
                    'message' => $config['message'],
                    'processed' => 0
                ];
            }

            $api_id = get_option('sc_api_id', '');
            $sync_mode = $full_sync ? 'F' : 'P';

            // Check for ongoing sync to prevent race conditions
            $lock_key = 'sc_sync_lock_' . $this->distributor_id;
            if (get_transient($lock_key) && !$force_sync) {
                return [
                    'status' => false,
                    'message' => 'Another sync is already in progress',
                    'processed' => 0
                ];
            }

            // Set lock and record lock time
            set_transient($lock_key, true, 900); // Lock for 15 minutes (increased from 5)
            set_transient('sc_sync_lock_time_' . $this->distributor_id, time(), 900);

            // Reset sync progress tracking
            delete_transient('sc_sync_current_page');
            delete_transient('sc_sync_total_pages');
            delete_transient('sc_sync_items_processed');
            delete_transient('sc_sync_items_expected');

            error_log('SC Sync: Starting sync with API ID: ' . ($api_id ?: 'None') . ', Mode: ' . $sync_mode . ', Distributor: ' . $this->distributor_id);

            // Get initial inventory data to get pagination info
            $inventory_data = $this->getInventoryData($api_id, $sync_mode, $item_codes, 1);

            // Process categories first if doing a full sync - ONLY using API-provided categories
            if ($full_sync && !empty($inventory_data['categories'])) {
                $this->setupAPICategories($inventory_data['categories']);
            }

            // Process all pages if multi-page response
            $total_pages = isset($inventory_data['page_total']) ? intval($inventory_data['page_total']) : 1;
            $total_items_processed = 0;
            $total_items_expected = isset($inventory_data['item_total']) ? intval($inventory_data['item_total']) : 0;

            // Store expected totals for tracking
            set_transient('sc_sync_total_pages', $total_pages, 900);
            set_transient('sc_sync_items_expected', $total_items_expected, 900);
            set_transient('sc_sync_items_processed', 0, 900);

            error_log("SC Sync: Total pages to process: {$total_pages}, Expected total items: {$total_items_expected}");

            // Process each page
            for ($page = 1; $page <= $total_pages; $page++) {
                // Update current page for tracking
                set_transient('sc_sync_current_page', $page, 900);

                // Check if sync should be stopped
                if (get_option('sc_stop_sync') === 'yes') {
                    delete_option('sc_stop_sync');
                    error_log("SC Sync: Sync operation terminated by admin at page {$page}");
                    break;
                }

                // Get data for current page (reuse first page data if we already have it)
                if ($page > 1) {
                    $inventory_data = $this->getInventoryData($api_id, $sync_mode, $item_codes, $page);
                    // Force garbage collection between page fetches
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }

                if (empty($inventory_data['items'])) {
                    error_log("SC Sync: No items found on page {$page}");
                    continue;
                }

                $items_processed = $this->processItemsBatch($inventory_data['items'], $full_sync);
                $total_items_processed += $items_processed;

                // Update total processed count
                set_transient('sc_sync_items_processed', $total_items_processed, 900);

                error_log("SC Sync: Processed page {$page}/{$total_pages}, items on this page: {$items_processed}, total processed: {$total_items_processed}");

                // Prevent memory issues - more aggressive cleanup
                $this->cleanupMemory();

                // Optional: Add a small delay between pages to avoid API rate limits
                if ($page < $total_pages) {
                    sleep(1);
                }
            }

            $sync_time = current_time('mysql');
            update_option('sc_last_sync_time_' . $this->distributor_id, $sync_time);
            update_option('sc_last_sync_count_' . $this->distributor_id, $total_items_processed);
            update_option($full_sync ? 'sc_last_full_sync_time' : 'sc_last_stock_sync_time', $sync_time);

            error_log('SC Sync: Completed processing ' . $total_items_processed . ' items for distributor ' . $this->distributor_id);

            // Clean up transients used for progress tracking
            delete_transient('sc_sync_current_page');
            delete_transient('sc_sync_total_pages');
            delete_transient('sc_sync_items_processed');
            delete_transient('sc_sync_items_expected');

            // Release the sync lock
            delete_transient($lock_key);
            delete_transient('sc_sync_lock_time_' . $this->distributor_id);

            return [
                'status' => true,
                'message' => 'Sync completed successfully',
                'processed' => $total_items_processed,
                'expected' => $total_items_expected
            ];
        } catch (Exception $e) {
            error_log('SC Sync: Exception during sync: ' . $e->getMessage());

            // Clean up transients used for progress tracking
            delete_transient('sc_sync_current_page');
            delete_transient('sc_sync_total_pages');
            delete_transient('sc_sync_items_processed');
            delete_transient('sc_sync_items_expected');

            // Ensure lock is released on error
            delete_transient($lock_key);
            delete_transient('sc_sync_lock_time_' . $this->distributor_id);

            return [
                'status' => false,
                'message' => 'Sync failed: ' . $e->getMessage(),
                'processed' => $total_items_processed ?? 0
            ];
        }
    }

    /**
     * Clean up memory aggressively
     */
    private function cleanupMemory() {
        wc_delete_product_transients();
        wp_cache_flush();
        global $wpdb;
        $wpdb->flush();
        
        // Force PHP garbage collection if available
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }

    private function processItemsBatch($items, $full_sync) {
        $items_processed = 0;
        $total_items = count($items);
        $failed_items = [];

        // Process items in batches
        for ($offset = 0; $offset < $total_items; $offset += $this->batch_size) {
            global $wpdb;
            $wpdb->query('START TRANSACTION');

            try {
                $batch = array_slice($items, $offset, $this->batch_size);
                $batch_success = true;

                foreach ($batch as $item) {
                    // Track specifically problematic items
                    $tracking_skus = ['D4751A']; // Add other problematic SKUs
                    $is_tracked = in_array($item['sku'], $tracking_skus);

                    if ($is_tracked) {
                        error_log("SC Sync: Processing tracked SKU {$item['sku']}");
                    }

                    // Robust SKU check
                    $product_id = $this->getProductIdBySku($item['sku']);

                    if (!$product_id && $full_sync) {
                        $product_id = $this->createProduct($item);
                        if (!$product_id) {
                            error_log("SC Sync: Failed to create product SKU {$item['sku']}");
                            $failed_items[] = $item['sku'];
                            $batch_success = false;
                            continue;
                        }
                    }

                    if ($product_id) {
                        $this->updateProduct($product_id, $item, $full_sync);
                        $items_processed++;

                        if ($is_tracked) {
                            error_log("SC Sync: Successfully processed {$item['sku']} with ID {$product_id}");
                        }
                    } else {
                        if ($is_tracked) {
                            error_log("SC Sync: Skipped processing {$item['sku']} - no product ID");
                        }
                        $failed_items[] = $item['sku'];
                    }
                }

                // Commit only if all items in the batch succeeded
                if ($batch_success) {
                    $wpdb->query('COMMIT');
                } else {
                    // Individual items have already been logged, no need to roll back everything
                    $wpdb->query('COMMIT');
                }
            } catch (Exception $e) {
                $wpdb->query('ROLLBACK');
                error_log("SC Sync: Batch processing exception: " . $e->getMessage());
            }

            // Clear memory within the batch
            unset($batch);
            $this->cleanupMemory();
        }

        // Log all failed items at the end
        if (!empty($failed_items)) {
            error_log("SC Sync: Failed to process these SKUs: " . implode(', ', $failed_items));
            // Store for reporting
            update_option('sc_sync_failed_items', array_slice($failed_items, 0, 100)); // Store limited number
        }

        return $items_processed;
    }

    /**
     * Robustly get product ID by SKU with direct database query
     * 
     * @param string $sku Product SKU
     * @return int|false Product ID or false
     */
    private function getProductIdBySku($sku) {
        // First try WooCommerce function
        $product_id = wc_get_product_id_by_sku($sku);
        if ($product_id) {
            return $product_id;
        }

        // Fallback to direct database query to bypass cache
        global $wpdb;
        $product_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta 
            WHERE meta_key = '_sku' 
            AND meta_value = %s 
            LIMIT 1",
            $sku
        ));

        if ($product_id) {
            error_log('SC Sync: Found product ID ' . $product_id . ' for SKU ' . $sku . ' via database query');
            return (int)$product_id;
        }

        return false;
    }

    /**
     * Just update stock levels and prices (partial sync)
     * 
     * @param array $item_codes Optional list of SKUs to sync
     * @param bool $force_sync Whether to force sync even if another sync is in progress
     * @return array Status, message, and processed count
     */
    public function syncInventoryStockOnly($item_codes = [], $force_sync = false) {
        return $this->syncProducts(false, $item_codes, $force_sync);
    }

    public function getInventoryData($api_id, $sync_mode, $item_codes = [], $page = 1) {
        $data = [
            'items' => [], 
            'categories' => [],
            'page_num' => $page,
            'page_total' => 1,
            'item_total' => 0
        ];
        
        $max_retries = 3;
        $retry_count = 0;
        $response = null;
    
        while ($retry_count < $max_retries) {
            try {
                $response = $this->api->productSync($api_id, $sync_mode, $item_codes, $page);
                if (!empty($response) && !isset($response['error'])) {
                    break;
                }
            } catch (Exception $e) {
                error_log('SC Sync: API call failed: ' . $e->getMessage());
            }
            $retry_count++;
            error_log("SC Sync: Retry $retry_count for sync_mode $sync_mode, page $page due to " . (isset($response['error']) ? $response['error'] : 'empty response'));
            sleep(2);
        }
    
        if (empty($response) || isset($response['error'])) {
            error_log('SC Sync: Failed to fetch data: ' . (isset($response['error']) ? $response['error'] : 'Empty response'));
            return $data;
        }
    
        // Extract distributor_id from API response (adjust the key based on actual response structure)
        if (isset($response['distributor_id'])) {
            $this->distributor_id = sanitize_text_field($response['distributor_id']);
            error_log('SC Sync: Updated distributor_id to ' . $this->distributor_id . ' from API response');
        } else {
            error_log('SC Sync: No distributor_id found in API response, using default: ' . $this->distributor_id);
        }
    
        // Extract pagination information
        if (!empty($response['item_catalog'])) {
            $data['page_num'] = isset($response['item_catalog']['page_num']) ? intval($response['item_catalog']['page_num']) : 1;
            $data['page_total'] = isset($response['item_catalog']['page_total']) ? intval($response['item_catalog']['page_total']) : 1;
            $data['item_total'] = isset($response['item_catalog']['item_total']) ? intval($response['item_catalog']['item_total']) : 0;
        }
    
        // Extract categories
        if (!empty($response['item_catalog']['categories'])) {
            $data['categories'] = array_map('sanitize_text_field', $response['item_catalog']['categories']);
            error_log('SC Sync: Retrieved ' . count($data['categories']) . ' categories from API');
        }
    
        // Extract items
        if (!empty($response['item_catalog']['items'])) {
            foreach ($response['item_catalog']['items'] as $item) {
                if (empty($item['esm_code'])) {
                    error_log('SC Sync: Skipping item with no esm_code');
                    continue;
                }

                $data['items'][] = [
                    'sku'          => sanitize_text_field($item['esm_code']), // Confirm this mapping is correct
                    'stock'        => isset($item['stock_status']) ? intval($item['stock_status']) : 0,
                    'price'        => isset($item['us_price']) ? floatval($item['us_price']) : 0,
                    'retail_price' => isset($item['retail_price']) ? floatval($item['retail_price']) : 0,
                    'description'  => sanitize_textarea_field($item['esm_description'] ?? ''),
                    'image_url'    => "https://aztecimport.com/static/photos/{$item['esm_code']}.png",
                    'catalog'      => isset($item['catalog']) ? sanitize_text_field($item['catalog']) : '',
                    'catalog_page' => isset($item['catalog_page']) ? sanitize_text_field($item['catalog_page']) : '',
                    'dimensions'   => isset($item['dimmensions']) ? sanitize_text_field($item['dimmensions']) : '',
                    'item_uom'     => isset($item['esm_uom']) ? sanitize_text_field($item['esm_uom']) : '',
                    'category'     => isset($item['primary_category']) ? sanitize_text_field($item['primary_category']) : ''
                ];
            }
        } else {
            error_log('SC Sync: No items found in item_catalog');
        }
    
        error_log('SC Sync: Retrieved ' . count($data['items']) . ' items from API on page ' . $data['page_num'] . ' of ' . $data['page_total']);
        return $data;
    }

    /**
     * Setup categories based on the API response while also ensuring special categories exist
     * 
     * @param array $categories List of category names from API
     */
    private function setupAPICategories($categories) {
        // Reset categories map to ensure we only use what comes from the API and special categories
        $this->categories_map = [];
        
        error_log('SC Sync: Setting up ' . count($categories) . ' categories from API');
        
        // First, ensure our special categories exist
        $this->ensureSpecialCategoriesExist();
        
        // Then setup all the regular categories from the API
        foreach ($categories as $category) {
            $term = term_exists($category, 'product_cat');
            
            if (!$term) {
                $term = wp_insert_term($category, 'product_cat', [
                    'description' => "Products from category {$category}"
                ]);
                
                if (is_wp_error($term)) {
                    error_log('SC Sync: Failed to create category ' . $category . ': ' . $term->get_error_message());
                    continue;
                }
            }
            
            // Store the term ID for later use
            $this->categories_map[$category] = is_array($term) ? $term['term_id'] : $term;
            error_log('SC Sync: Category ' . $category . ' setup with ID ' . $this->categories_map[$category]);
        }
        
        // Store categories for this specific distributor
        update_option('sc_distributor_categories_' . $this->distributor_id, $this->categories_map);
    }
    
    /**
     * Ensure special categories (Specials, Back In Stock, New) exist
     */
    private function ensureSpecialCategoriesExist() {
        $special_category_names = [
            'special' => 'Specials',
            'back_in_stock' => 'Back In Stock', 
            'new' => 'New'
        ];
        
        foreach ($special_category_names as $key => $category_name) {
            $term = term_exists($category_name, 'product_cat');
            
            if (!$term) {
                $term = wp_insert_term($category_name, 'product_cat', [
                    'description' => "Products marked as {$category_name}"
                ]);
                
                if (is_wp_error($term)) {
                    error_log('SC Sync: Failed to create special category ' . $category_name . ': ' . $term->get_error_message());
                    continue;
                }
            }
            
            // Store the term ID for later use
            $this->categories_map[$key] = is_array($term) ? $term['term_id'] : $term;
            error_log('SC Sync: Special category ' . $category_name . ' setup with ID ' . $this->categories_map[$key]);
        }
    }

    /**
     * Create a new product
     * 
     * @param array $item Product data
     * @return int|false Product ID or false on failure
     */
    private function createProduct($item) {
        try {
            // Double-check for existing product to prevent duplicates
            $existing_product_id = $this->getProductIdBySku($item['sku']);
            if ($existing_product_id) {
                error_log('SC Sync: Skipped creating product SKU ' . $item['sku'] . ' - already exists with ID ' . $existing_product_id);
                return $existing_product_id;
            }

            $product_id = wp_insert_post([
                'post_title'   => $item['description'] ?: 'Unnamed Product',
                'post_content' => $this->formatProductDescription($item),
                'post_excerpt' => wp_trim_words($item['description'], 20),
                'post_status'  => 'publish',
                'post_type'    => 'product'
            ]);
            
            if (is_wp_error($product_id)) {
                error_log('SC Sync - Error creating product SKU ' . $item['sku'] . ': ' . $product_id->get_error_message());
                return false;
            }
            
            update_post_meta($product_id, '_sku', $item['sku']);
            
            // Keep as simple product for seamless checkout integration
            wp_set_object_terms($product_id, 'simple', 'product_type');
            
            // Just track the distributor ID for backend differentiation
            update_post_meta($product_id, '_distributor', $this->distributor_id);
            update_post_meta($product_id, '_distributor_id', $this->distributor_id);
            
            // Set primary category based on the API response
            $this->setProductCategory($product_id, $item['category'], $item['description']);
            
            $this->setProductDimensions($product_id, $item['dimensions']);
            
            error_log('SC Sync: Created new product SKU ' . $item['sku'] . ' with ID ' . $product_id);
            return $product_id;
        } catch (Exception $e) {
            error_log('SC Sync: Exception creating product SKU ' . $item['sku'] . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Format product description with additional details
     * 
     * @param array $item Product data
     * @return string Formatted description
     */
    private function formatProductDescription($item) {
        return $item['description'];
    }

    /**
     * Set product category based on primary_category and special characters in description
     * 
     * @param int $product_id Product ID
     * @param string $category Category name from API
     * @param string $description Product description to check for special characters
     */
    private function setProductCategory($product_id, $category, $description = '') {
        try {
            // Load categories map if not already loaded
            if (empty($this->categories_map)) {
                $this->categories_map = get_option('sc_distributor_categories_' . $this->distributor_id, []);
                $this->ensureSpecialCategoriesExist();
            }
            
            // Get current categories for this product
            $current_terms = wp_get_object_terms($product_id, 'product_cat', ['fields' => 'ids']);
            if (is_wp_error($current_terms)) {
                $current_terms = [];
            }
            
            // Determine if product should be in any special categories
            $special_term_ids = [];
            foreach ($this->special_categories as $key => $special_char) {
                if (strpos($description, $special_char) !== false) {
                    if (isset($this->categories_map[$key])) {
                        $special_term_ids[] = (int)$this->categories_map[$key];
                        error_log('SC Sync: Product ID ' . $product_id . ' added to special category: ' . $key);
                    }
                }
            }
            
            // Handle primary category assignment
            $primary_term_id = 0;
            
            if (!empty($category)) {
                // Check if the primary_category matches any predefined category
                if (isset($this->categories_map[$category])) {
                    // Direct match found
                    $primary_term_id = (int)$this->categories_map[$category];
                    error_log('SC Sync: Primary category match found for product ID ' . $product_id . ': ' . $category);
                } else {
                    // Try partial matches
                    foreach ($this->categories_map as $defined_category => $term_id) {
                        // Skip special categories in this loop
                        if (in_array($defined_category, ['special', 'back_in_stock', 'new'])) {
                            continue;
                        }
                        
                        // Check for partial matches
                        if (stripos($category, $defined_category) !== false || 
                            stripos($defined_category, $category) !== false) {
                            $primary_term_id = (int)$term_id;
                            error_log('SC Sync: Partial category match found for product ID ' . $product_id . ': ' . $defined_category);
                            break;
                        }
                    }
                }
            }
            
            // Combine primary category with special categories
            $new_term_ids = $special_term_ids;
            if ($primary_term_id > 0) {
                $new_term_ids[] = $primary_term_id;
            }
            
            // If no categories assigned, use default uncategorized
            if (empty($new_term_ids)) {
                $uncategorized_term = get_term_by('slug', 'uncategorized', 'product_cat');
                if ($uncategorized_term) {
                    $new_term_ids[] = (int)$uncategorized_term->term_id;
                    error_log('SC Sync: No category matches found, setting product ID ' . $product_id . ' to uncategorized');
                }
            }
            
            // Update the product categories
            wp_set_object_terms($product_id, $new_term_ids, 'product_cat');
            
            error_log('SC Sync: Set categories for product ID ' . $product_id . ': ' . implode(', ', $new_term_ids));
        } catch (Exception $e) {
            error_log('SC Sync: Exception setting category for product ID ' . $product_id . ': ' . $e->getMessage());
        }
    }


/**
 * Set product dimensions as attributes and shipping properties
 * 
 * @param int $product_id Product ID
 * @param string $dimensions Dimensions string
 */
    private function setProductDimensions($product_id, $dimensions) {
        if (empty($dimensions)) {
            return;
        }
    
        $dims = explode(';', trim($dimensions, ';'));
        if (count($dims) < 3) {
            error_log('SC Sync: Invalid dimensions format for product ID ' . $product_id . ': ' . $dimensions);
            return;
        }
    
        // Set as custom attributes for display
        $attributes = [
            'length' => [
                'name' => 'Length',
                'value' => floatval($dims[0]) . '"',
                'position' => 0,
                'is_visible' => 1,
                'is_variation' => 0,
                'is_taxonomy' => 0
            ],
            'width' => [
                'name' => 'Width',
                'value' => floatval($dims[1]) . '"',
                'postion' => 1,
                'is_visible' => 1,
                'is_variation' => 0,
                'is_taxonomy' => 0
            ],
            'height' => [
                'name' => 'Height',
                'value' => floatval($dims[2]) . '"',
                'position' => 2,
                'is_visible' => 1,
                'is_variation' => 0,
                'is_taxonomy' => 0
            ]
        ];
    
        update_post_meta($product_id, '_product_attributes', $attributes);
        
        // Set shipping dimensions
        update_post_meta($product_id, '_length', floatval($dims[0]));
        update_post_meta($product_id, '_width', floatval($dims[1]));
        update_post_meta($product_id, '_height', floatval($dims[2]));
        
        // Add weight  
        if (isset($dims[3]) && is_numeric($dims[3])) {
            $weight = floatval($dims[3]);
            update_post_meta($product_id, '_weight', $weight);
        }
    }

    /**
     * Update an existing product
     * 
     * @param int $product_id Product ID
     * @param array $item Product data
     * @param bool $full_sync Whether to perform a full update
     */
    private function updateProduct($product_id, $item, $full_sync) {
        try {
            $is_distributor_product = get_post_meta($product_id, '_distributor', true) === $this->distributor_id;

            // Always update stock regardless of sync type
            wc_update_product_stock($product_id, $item['stock']);
            update_post_meta($product_id, '_stock_status', $item['stock'] > 0 ? 'instock' : 'outofstock');
            
            if ($is_distributor_product || empty(get_post_meta($product_id, '_distributor', true))) {
                update_post_meta($product_id, '_distributor', $this->distributor_id);
                update_post_meta($product_id, '_distributor_id', $this->distributor_id);
                
                // Ensure it's a simple product type for seamless checkout
                wp_set_object_terms($product_id, 'simple', 'product_type');
            }
            
            if (!empty($item['retail_price'])) {
                update_post_meta($product_id, '_regular_price', $item['retail_price']);
                update_post_meta($product_id, '_price', $item['retail_price']);
            }
            
            // Only update other fields during full sync
            if ($full_sync) {
                // Use direct updates rather than WC_Product object to save memory
                wp_update_post([
                    'ID' => $product_id,
                    'post_title' => $item['description'],
                    'post_content' => $this->formatProductDescription($item),
                    'post_excerpt' => wp_trim_words($item['description'], 20)
                ]);
                
                // Only update image if needed
                if (!has_post_thumbnail($product_id)) {
                    $this->setProductImage($product_id, $item['sku'], $item['image_url']);
                }
                
                if ($is_distributor_product || empty(get_post_meta($product_id, '_distributor', true))) {
                    // Update categories based on description and primary category
                    $this->setProductCategory($product_id, $item['category'], $item['description']);
                }
                
                $this->setProductDimensions($product_id, $item['dimensions']);
            }
        } catch (Exception $e) {
            error_log('SC Sync: Exception updating product ID ' . $product_id . ': ' . $e->getMessage());
        }
    }

    /**
     * Set product featured image
     * Optimized to check existence first before attempting to download
     * With additional safety checks and error handling for missing images
     * 
     * @param int $product_id Product ID
     * @param string $sku Product SKU
     * @param string $image_url Image URL
     */
    private function setProductImage($product_id, $sku, $image_url) {
        // Set a timeout just for this operation
        $original_time_limit = ini_get('max_execution_time');
        set_time_limit(30); // Ensure we have enough time but won't hang forever
        
        try {
            // Skip if product already has an image
            if (has_post_thumbnail($product_id)) {
                return;
            }

            // Try to find existing image before downloading
            $existing_image_id = $this->findExistingImage($sku);
            if ($existing_image_id) {
                set_post_thumbnail($product_id, $existing_image_id);
                return;
            }
            
            // Skip if URL is invalid
            if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
                error_log('SC Sync: Invalid image URL for product ID ' . $product_id . ': ' . $image_url);
                return;
            }

            // First check if the image exists on the server with a HEAD request
            $args = [
                'method' => 'HEAD',
                'timeout' => 3,
                'redirection' => 3,
                'sslverify' => false
            ];
            
            $response = wp_remote_request($image_url, $args);
            
            // If the HEAD request fails or returns non-200 status, skip download attempt
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                error_log('SC Sync: Image not found for product ID ' . $product_id . ' at URL: ' . $image_url);
                return;
            }
            
            // Only download and process image if it exists
            $image_id = $this->uploadImageFromUrl($image_url);
            if ($image_id) {
                set_post_thumbnail($product_id, $image_id);
            }
        } catch (Exception $e) {
            error_log('SC Sync: Exception setting image for product ID ' . $product_id . ': ' . $e->getMessage());
        } finally {
            // Restore original time limit
            set_time_limit($original_time_limit);
        }
    }

    /**
     * Find existing product image
     * 
     * @param string $sku Product SKU
     * @return int|false Attachment ID or false
     */
    private function findExistingImage($sku) {
        // More efficient query to find existing image
        global $wpdb;
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta 
            WHERE meta_key = '_wp_attached_file' 
            AND meta_value LIKE %s 
            LIMIT 1",
            '%' . $wpdb->esc_like($sku) . '%'
        ));
        
        return $attachment_id ? (int)$attachment_id : false;
    }

    /**
     * Upload image from URL
     * 
     * @param string $image_url Image URL
     * @return int|false Attachment ID or false
     */
    private function uploadImageFromUrl($image_url) {
        try {
            $image_name = basename($image_url);
            $upload_dir = wp_upload_dir();
            $image_path = $upload_dir['path'] . '/' . $image_name;

            // Check if file already exists
            if (file_exists($image_path)) {
                $attachment = $this->getAttachmentFromPath($image_path);
                if ($attachment) {
                    return $attachment;
                }
            }

            // Use WordPress HTTP API with lower timeout
            $response = wp_remote_get($image_url, [
                'timeout' => 5, 
                'sslverify' => false,
                'user-agent' => 'WordPress/' . get_bloginfo('version')
            ]);
            
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                error_log('SC Sync: Failed to download image: ' . $image_url);
                return false;
            }

            $image_data = wp_remote_retrieve_body($response);
            if (empty($image_data)) {
                error_log('SC Sync: Empty image data from URL: ' . $image_url);
                return false;
            }

            // Verify the image data is valid before saving
            if (!$this->isValidImageData($image_data)) {
                error_log('SC Sync: Invalid image data from URL: ' . $image_url);
                return false;
            }

            // Save file to disk
            file_put_contents($image_path, $image_data);
            
            // Check and validate file type
            $filetype = wp_check_filetype($image_path, null);
            if (!$filetype['type'] || strpos($filetype['type'], 'image/') !== 0) {
                error_log('SC Sync: Invalid file type for image: ' . $image_url);
                if (file_exists($image_path)) {
                    unlink($image_path);
                }
                return false;
            }

            // Prepare attachment data
            $attachment = [
                'post_mime_type' => $filetype['type'],
                'post_title'     => sanitize_file_name($image_name),
                'post_content'   => '',
                'post_status'    => 'inherit'
            ];

            // Insert attachment
            $attach_id = wp_insert_attachment($attachment, $image_path);
            if (is_wp_error($attach_id)) {
                if (file_exists($image_path)) {
                    unlink($image_path);
                }
                return false;
            }

            // Set timeout limit specifically for image processing
            $original_time_limit = ini_get('max_execution_time');
            set_time_limit(60); // Increase to 60 seconds just for image processing
            
            try {
                // Generate metadata and thumbnails with error handling
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $attach_data = wp_generate_attachment_metadata($attach_id, $image_path);
                
                // If metadata generation fails, clean up and return false
                if (empty($attach_data)) {
                    wp_delete_attachment($attach_id, true);
                    error_log('SC Sync: Failed to generate attachment metadata for: ' . $image_url);
                    return false;
                }
                
                wp_update_attachment_metadata($attach_id, $attach_data);
            } catch (Exception $e) {
                error_log('SC Sync: Exception during image processing: ' . $e->getMessage());
                wp_delete_attachment($attach_id, true);
                return false;
            } finally {
                // Restore original time limit
                set_time_limit($original_time_limit);
            }

            return $attach_id;
        } catch (Exception $e) {
            error_log('SC Sync: Exception uploading image: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if data is a valid image
     * 
     * @param string $data Image data
     * @return bool Whether data is a valid image
     */
    private function isValidImageData($data) {
        // Quick check for image signatures
        $signatures = [
            "\xFF\xD8\xFF" => 'image/jpeg',  // JPEG
            "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A" => 'image/png', // PNG
            "GIF87a" => 'image/gif', // GIF
            "GIF89a" => 'image/gif', // GIF
        ];
        
        foreach ($signatures as $hex => $mime) {
            if (strncmp($data, $hex, strlen($hex)) === 0) {
                return true;
            }
        }
        
        // If no valid signature found, likely not an image
        return false;
    }
    
    /**
     * Get attachment ID from file path
     * 
     * @param string $path File path
     * @return int|false Attachment ID or false
     */
    private function getAttachmentFromPath($path) {
        try {
            // More efficient direct query
            global $wpdb;
            $upload_dir = wp_upload_dir();
            $relative_path = str_replace($upload_dir['basedir'] . '/', '', $path);
            
            $attachment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta 
                WHERE meta_key = '_wp_attached_file' 
                AND meta_value = %s 
                LIMIT 1", 
                $relative_path
            ));
            
            return $attachment_id ? (int)$attachment_id : false;
        } catch (Exception $e) {
            error_log('SC Sync: Exception getting attachment: ' . $e->getMessage());
            return false;
        }
    }
}