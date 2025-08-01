<?php

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SC_WP_Shop_Sync - Distributor Sync for WooCommerce
 * 
 * Simple daily sync that efficiently syncs all WooCommerce categories
 * Uses large API batches (1500-5000 items) to minimize API calls
 * Optimized for shared hosting with minimal resource usage
 */
class SC_WP_Shop_Sync {
    private $api;
    private $batch_size = 2000; // Large batch size for efficiency
    private $max_execution_time = 300; // 5 minutes max
    private $image_lookup = null; // Cache for image filename lookups

    public function __construct(SC_API $api) {
        // Prevent frontend initialization to avoid slowdowns
        if (!is_admin() && !wp_doing_ajax() && !wp_doing_cron() && !(defined('WP_CLI') && WP_CLI)) {
            return;
        }

        $this->api = $api;
        
        // Set batch size from options with reasonable limits
        $configured_batch = get_option('sc_api_rows_per_request', 5000);
        $this->batch_size = max(500, min(5000, intval($configured_batch)));
        
        error_log("SC Sync: Configured batch size from options: {$configured_batch}, Final batch size: {$this->batch_size}");
    }

    /**
     * MAIN SYNC METHOD - Simplified and focused on categories
     */
    public function syncProducts($force_sync = false) {
        $lock_key = 'sc_sync_lock';
        if (!$force_sync && get_transient($lock_key)) {
            return ['status' => false, 'message' => 'Sync already in progress', 'processed' => 0];
        }

        set_transient($lock_key, time(), 600); // 10 minute lock

        try {
            $start_time = time();
            $total_processed = 0;
            $total_created = 0;
            $total_updated_categories = 0;
            $total_skipped = 0;
            $all_active_skus = []; // Track all active SKUs for discontinued product cleanup
            
            // Build image lookup cache for performance
            $this->buildImageLookupCache();
            
            // PRIMARY STRATEGY: Sync ALL categories at once
            $categories = $this->getWooCommerceCategories();
            if (empty($categories)) {
                delete_transient($lock_key);
                return ['status' => false, 'message' => 'No WooCommerce categories found. Please create categories first.', 'processed' => 0];
            }

            error_log("SC Sync: Starting sync for ALL categories: " . implode(', ', $categories));
            $result = $this->syncAllCategoriesComplete($categories);
            $total_processed = $result['processed'];
            $total_created = $result['created'];
            $total_updated_categories = $result['category_updates'] ?? 0;
            $total_skipped = $result['skipped'] ?? 0;
            $all_active_skus = $result['active_skus'] ?? [];

            // Clean up discontinued products (simple approach)
            $discontinued = $this->removeDiscontinuedProducts($all_active_skus);

            // Update sync metadata
            update_option('sc_last_sync_time', current_time('mysql'));
            update_option('sc_last_sync_count', $total_processed);
            update_option('sc_initial_sync_done', true);
            
            delete_transient($lock_key);

            $elapsed = time() - $start_time;
            $message = "Sync completed! Processed {$total_processed} items, created {$total_created} new products";
            if ($total_updated_categories > 0) {
                $message .= ", updated categories for {$total_updated_categories} products";
            }
            if ($total_skipped > 0) {
                $message .= ", skipped {$total_skipped} items";
            }
            if ($discontinued > 0) {
                $message .= ", removed {$discontinued} discontinued items";
            }
            $message .= " in {$elapsed} seconds.";

            error_log("SC Sync: Complete sync finished - {$total_processed} processed, {$total_created} created, {$total_updated_categories} category updates, {$total_skipped} skipped, {$discontinued} discontinued in {$elapsed}s");

            return [
                'status' => true,
                'message' => $message,
                'processed' => $total_processed,
                'created' => $total_created,
                'category_updates' => $total_updated_categories,
                'skipped' => $total_skipped,
                'deleted' => $discontinued
            ];

        } catch (Exception $e) {
            delete_transient($lock_key);
            error_log('SC Sync Error: ' . $e->getMessage());
            return ['status' => false, 'message' => 'Sync failed: ' . $e->getMessage(), 'processed' => 0];
        }
    }

    /**
     * Sync ALL categories at once - much more efficient!
     * Gets ALL items from ALL categories in one API call series
     */
    private function syncAllCategoriesComplete($categories) {
        $api_id = get_option('sc_api_id');
        if (empty($api_id)) {
            return ['processed' => 0, 'created' => 0, 'category_updates' => 0, 'active_skus' => []];
        }

        $processed = 0;
        $created = 0;
        $category_updates = 0;
        $total_skipped = 0;
        $active_skus = [];
        $page = 1;
        $total_pages = 0;

        error_log("SC Sync: Starting complete sync for ALL categories: " . implode(', ', $categories));

        do {
            error_log("SC Sync: Fetching page {$page} for ALL categories with batch size: {$this->batch_size}");
            
            $response = $this->api->productSync(
                $api_id,
                'F', // Full sync to get dimensions
                '', // EMPTY SKU CSV - category-driven only
                $categories, // ALL categories at once 
                $page,
                $this->batch_size
            );

            if (isset($response['error'])) {
                error_log("SC API Error for ALL categories page {$page}: " . $response['error']);
                break;
            }

            $item_catalog = $response['item_catalog'] ?? [];
            $items = $item_catalog['items'] ?? [];
            $page_total = $item_catalog['page_total'] ?? 1;
            $item_total = $item_catalog['item_total'] ?? 0;
            
            // Log pagination info on first page
            if ($page === 1) {
                $total_pages = $page_total;
                error_log("SC Sync: ALL categories have {$item_total} total items across {$page_total} pages");
            }

            if (empty($items)) {
                if ($page === 1) {
                    error_log("SC Sync: No items found for ANY categories");
                }
                break;
            }

            error_log("SC Sync: Processing page {$page}/{$page_total} for ALL categories: " . count($items) . " items");

            // Collect active SKUs from this page
            foreach ($items as $item) {
                $sku = trim($item['item_code'] ?? '');
                if (!empty($sku)) {
                    $active_skus[] = $sku;
                }
            }

            // Process items from this page - now handles simple primary category assignment
            $page_result = $this->processItemsWithCategories($items);
            $processed += $page_result['processed'];
            $created += $page_result['created'];
            $category_updates += $page_result['category_updates'];
            $total_skipped += ($page_result['skipped'] ?? 0);

            // Move to next page
            $page++;
            
            // Safety check - don't go beyond reported page total
            if ($page > $page_total) {
                error_log("SC Sync: Reached final page ({$page_total}) for ALL categories");
                break;
            }

            // Brief pause between pages
            sleep(1);

        } while (true);

        error_log("SC Sync: ALL categories complete: {$processed} total processed, {$created} created, {$category_updates} category updates, {$total_skipped} skipped across " . ($page - 1) . " pages");

        return ['processed' => $processed, 'created' => $created, 'category_updates' => $category_updates, 'skipped' => $total_skipped, 'active_skus' => $active_skus];
    }

    /**
     * Process items from API response - now handles simple primary category assignment
     * Uses the primary_category field from API
     */
    private function processItemsWithCategories($items) {
        if (empty($items)) {
            return ['processed' => 0, 'created' => 0, 'category_updates' => 0, 'skipped' => 0];
        }

        $processed = 0;
        $created = 0;
        $category_updates = 0;
        $skipped = 0;
        $skipped_reasons = [];

        // Get existing products by SKU for efficient lookup
        $skus = array_filter(array_map(function($item) {
            return trim($item['item_code'] ?? '');
        }, $items));

        $existing_products = $this->getProductsBySKUs($skus);
        
        error_log("SC Sync: Processing " . count($items) . " items, found " . count($existing_products) . " existing products");

        foreach ($items as $item) {
            $sku = trim($item['item_code'] ?? '');
            if (empty($sku)) {
                error_log("SC Sync: Skipping item with empty SKU");
                $skipped++;
                $skipped_reasons['empty_sku'] = ($skipped_reasons['empty_sku'] ?? 0) + 1;
                continue;
            }

            $product_data = $this->extractProductData($item);
            
            // Simple logic: Get the category this item should be in
            $target_categories = $this->determineAllCategoriesFromItem($item);
            
            if (empty($target_categories)) {
                $primary_cat = trim($item['primary_category'] ?? '');
                error_log("SC Sync: SKIPPED - No valid categories found for SKU {$sku} (primary_category: '{$primary_cat}')");
                $skipped++;
                $key = !empty($primary_cat) ? "missing_category_{$primary_cat}" : 'no_primary_category';
                $skipped_reasons[$key] = ($skipped_reasons[$key] ?? 0) + 1;
                continue;
            }

            if (isset($existing_products[$sku])) {
                // Update existing product
                error_log("SC Sync: Updating existing product SKU: {$sku} with categories: " . implode(', ', $target_categories));
                $update_result = $this->updateProductWithCategories($existing_products[$sku], $product_data, $target_categories);
                if ($update_result['success']) {
                    $processed++;
                    if ($update_result['categories_updated']) {
                        $category_updates++;
                        error_log("SC Sync: Updated categories for existing product SKU: {$sku}");
                    }
                } else {
                    error_log("SC Sync: Failed to update existing product SKU: {$sku}");
                }
            } else {
                // Create new product
                error_log("SC Sync: Creating new product SKU: {$sku} in categories: " . implode(', ', $target_categories));
                $result = $this->createProductWithCategories($product_data, $target_categories);
                if ($result) {
                    $processed++;
                    $created++;
                    error_log("SC Sync: Successfully created product SKU: {$sku}");
                } else {
                    error_log("SC Sync: Failed to create new product SKU: {$sku} in categories: " . implode(', ', $target_categories));
                }
            }
        }

        // Log skip summary
        if ($skipped > 0) {
            error_log("SC Sync: SKIP SUMMARY - {$skipped} items skipped:");
            foreach ($skipped_reasons as $reason => $count) {
                error_log("SC Sync: - {$reason}: {$count} items");
            }
        }

        error_log("SC Sync: Batch complete - Processed: {$processed}, Created: {$created}, Category Updates: {$category_updates}, Skipped: {$skipped}");
        return ['processed' => $processed, 'created' => $created, 'category_updates' => $category_updates, 'skipped' => $skipped];
    }

    /**
     * SIMPLE METHOD: Determine WooCommerce category from API primary_category
     * 
     * Logic:
     * - Use primary_category from API
     * - Only assign if that category exists in WooCommerce
     * - Return array with single category or empty if doesn't exist
     * 
     * @param array $item API item data
     * @return array Array with category name if valid, empty if not
     */
    private function determineAllCategoriesFromItem($item) {
        $primary_category = trim($item['primary_category'] ?? '');
        
        $sku = trim($item['item_code'] ?? 'UNKNOWN');
        error_log("SC Sync: Category determination for SKU {$sku} - primary_category: '{$primary_category}'");
        
        if (empty($primary_category)) {
            error_log("SC Sync: No primary category for SKU {$sku}");
            return [];
        }
        
        if ($this->categoryExists($primary_category)) {
            error_log("SC Sync: Using primary category '{$primary_category}' for SKU {$sku}");
            return [$primary_category];
        } else {
            error_log("SC Sync: Primary category '{$primary_category}' does not exist in WooCommerce for SKU {$sku}");
            return [];
        }
    }

    /**
     * Check if a category exists in WooCommerce
     * @param string $category_name Category name to check
     * @return bool True if category exists
     */
    private function categoryExists($category_name) {
        if (empty($category_name)) {
            return false;
        }
        
        // Check by name first
        $category = get_term_by('name', $category_name, 'product_cat');
        if ($category) {
            return true;
        }
        
        // Check by slug
        $category = get_term_by('slug', sanitize_title($category_name), 'product_cat');
        if ($category) {
            return true;
        }
        
        return false;
    }

    /**
     * BACKWARD COMPATIBILITY: Keep the old method name but use new logic
     * @deprecated Use determineAllCategoriesFromItem instead
     */
    private function determineCategoryFromItem($item) {
        $categories = $this->determineAllCategoriesFromItem($item);
        return !empty($categories) ? $categories[0] : '';
    }

    /**
     * BACKWARD COMPATIBILITY: Keep the old method name but redirect to new logic
     * @deprecated Use processItemsWithMultipleCategories instead
     */
    private function processItemsWithSpecialCategories($items) {
        return $this->processItemsWithMultipleCategories($items);
    }

    /**
     * Create new WooCommerce product with multiple categories
     */
    private function createProductWithCategories($data, $category_names) {
        try {
            error_log("SC Sync: createProductWithCategories called for SKU: {$data['sku']}, Title: {$data['title']}, Categories: " . implode(', ', $category_names));
            
            $product_id = wp_insert_post([
                'post_title' => $data['title'],
                'post_content' => $data['description'],
                'post_status' => 'publish',
                'post_type' => 'product'
            ]);

            if (is_wp_error($product_id)) {
                error_log('SC Create Product Error - wp_insert_post failed for SKU ' . $data['sku'] . ': ' . $product_id->get_error_message());
                return false;
            }

            if (!$product_id) {
                error_log('SC Create Product Error - wp_insert_post returned false/0 for SKU ' . $data['sku']);
                return false;
            }

            error_log("SC Sync: wp_insert_post succeeded for SKU {$data['sku']}, product ID: {$product_id}");

            // Set product type
            $type_result = wp_set_object_terms($product_id, 'simple', 'product_type');
            if (is_wp_error($type_result)) {
                error_log('SC Create Product Error - product_type assignment failed for product ID ' . $product_id . ': ' . $type_result->get_error_message());
            } else {
                error_log("SC Sync: Set product type to 'simple' for product ID {$product_id}");
            }

            // Set product meta
            error_log("SC Sync: Setting meta for new product ID {$product_id}");
            $this->setProductMeta($product_id, $data);

            // Assign to ALL categories
            error_log("SC Sync: Assigning categories to new product ID {$product_id}");
            $this->assignToMultipleCategories($product_id, $category_names);

            // AUTO-ASSIGN IMAGES: Set product image if filename matches SKU
            $this->assignProductImages($product_id, $data['sku']);

            error_log("SC Sync: Successfully created and configured product ID: {$product_id}, SKU: {$data['sku']} with categories: " . implode(', ', $category_names));
            return $product_id;

        } catch (Exception $e) {
            error_log('SC Create Product Exception for SKU ' . $data['sku'] . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update existing WooCommerce product with proper category management
     */
    private function updateProductWithCategories($product_id, $data, $target_categories) {
        try {
            error_log("SC Sync: updateProductWithCategories called for product ID {$product_id}, SKU: {$data['sku']}");
            
            // Update post if needed
            if (!empty($data['title'])) {
                $post_result = wp_update_post([
                    'ID' => $product_id,
                    'post_title' => $data['title'],
                    'post_content' => $data['description']
                ]);
                
                if (is_wp_error($post_result)) {
                    error_log("SC Sync: wp_update_post failed for product ID {$product_id}: " . $post_result->get_error_message());
                } else {
                    error_log("SC Sync: wp_update_post succeeded for product ID {$product_id}");
                }
            }

            // Update meta
            error_log("SC Sync: Updating meta for product ID {$product_id}");
            $this->setProductMeta($product_id, $data);

            // Check if categories need updating
            error_log("SC Sync: Updating categories for product ID {$product_id} with categories: " . implode(', ', $target_categories));
            $categories_updated = $this->updateProductCategories($product_id, $target_categories);

            // AUTO-ASSIGN IMAGES: Update product image if filename matches SKU
            $this->assignProductImages($product_id, $data['sku']);

            error_log("SC Sync: updateProductWithCategories completed for product ID {$product_id}, categories_updated: " . ($categories_updated ? 'true' : 'false'));
            return ['success' => true, 'categories_updated' => $categories_updated];

        } catch (Exception $e) {
            error_log('SC Update Product Error for ID ' . $product_id . ': ' . $e->getMessage());
            return ['success' => false, 'categories_updated' => false];
        }
    }

    /**
     * Update product categories - always assign to ensure proper categories
     * Returns true if categories were assigned/updated
     */
    private function updateProductCategories($product_id, $target_categories) {
        if (empty($target_categories)) {
            error_log("SC Sync: No target categories provided for product ID {$product_id}");
            return false;
        }

        // Get current categories for logging
        $current_terms = wp_get_object_terms($product_id, 'product_cat', ['fields' => 'names']);
        $current_categories = is_array($current_terms) ? $current_terms : [];
        
        error_log("SC Sync: Updating categories for product ID {$product_id} - Current: [" . implode(', ', $current_categories) . "] -> Target: [" . implode(', ', $target_categories) . "]");
        
        // Always update categories to ensure they're correct
        $this->assignToMultipleCategories($product_id, $target_categories);
        
        // Verify the assignment worked
        $new_terms = wp_get_object_terms($product_id, 'product_cat', ['fields' => 'names']);
        $new_categories = is_array($new_terms) ? $new_terms : [];
        
        if (!empty($new_categories)) {
            error_log("SC Sync: Successfully assigned categories to product ID {$product_id}: [" . implode(', ', $new_categories) . "]");
            return true;
        } else {
            error_log("SC Sync: Failed to assign categories to product ID {$product_id}");
            return false;
        }
    }

    /**
     * Assign product to multiple WooCommerce categories (only existing ones)
     */
    private function assignToMultipleCategories($product_id, $category_names) {
        if (empty($category_names)) {
            error_log("SC Sync: No categories provided for product ID: {$product_id}");
            return;
        }

        $category_ids = [];
        
        foreach ($category_names as $category_name) {
            if (empty($category_name)) continue;
            
            $category = get_term_by('name', $category_name, 'product_cat');
            if (!$category) {
                $category = get_term_by('slug', sanitize_title($category_name), 'product_cat');
            }
            
            if ($category) {
                $category_ids[] = $category->term_id;
                error_log("SC Sync: Found category '{$category_name}' with ID {$category->term_id} for product ID: {$product_id}");
            } else {
                error_log("SC Sync: Category '{$category_name}' not found in WooCommerce for product ID: {$product_id} (skipping)");
            }
        }

        if (!empty($category_ids)) {
            // Remove duplicates and assign all categories
            $category_ids = array_unique($category_ids);
            error_log("SC Sync: Attempting to assign product ID {$product_id} to category IDs: " . implode(', ', $category_ids));
            
            $result = wp_set_object_terms($product_id, $category_ids, 'product_cat');
            
            if (is_wp_error($result)) {
                error_log("SC Sync: wp_set_object_terms failed for product ID {$product_id}: " . $result->get_error_message());
            } else {
                error_log("SC Sync: wp_set_object_terms returned: " . print_r($result, true));
                error_log("SC Sync: Successfully assigned product ID {$product_id} to " . count($category_ids) . " categories: " . implode(', ', $category_names));
            }
        } else {
            error_log("SC Sync: No valid existing categories found for product ID: {$product_id}");
        }
    }

    /**
     * BACKWARD COMPATIBILITY: Keep the old single-category method
     * @deprecated Use assignToMultipleCategories instead
     */
    private function assignToCategory($product_id, $category_name) {
        $this->assignToMultipleCategories($product_id, [$category_name]);
    }

    /**
     * Create new WooCommerce product (backward compatibility)
     * @deprecated Use createProductWithCategories instead
     */
    private function createProduct($data, $category_name) {
        return $this->createProductWithCategories($data, [$category_name]);
    }

    /**
     * Update existing WooCommerce product (backward compatibility)
     * @deprecated Use updateProductWithCategories instead
     */
    private function updateProduct($product_id, $data, $category_name) {
        $result = $this->updateProductWithCategories($product_id, $data, [$category_name]);
        return $result['success'];
    }

    /**
     * NEW METHOD: Fix category assignments for existing products
     * This can be run separately to update existing products with correct categories
     */
    public function fixExistingProductCategories($limit = 100) {
        global $wpdb;
        
        // Get existing products that need category updates
        $products = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID as product_id, pm.meta_value as sku
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND pm.meta_value != ''
            ORDER BY p.ID DESC
            LIMIT %d
        ", $limit));

        if (empty($products)) {
            return ['status' => false, 'message' => 'No products found to update', 'processed' => 0];
        }

        // Get SKUs for API call
        $skus = array_map(function($product) {
            return $product->sku;
        }, $products);

        // Fetch current data from API
        $api_id = get_option('sc_api_id');
        if (empty($api_id)) {
            return ['status' => false, 'message' => 'No API ID configured', 'processed' => 0];
        }

        try {
            $sku_csv = implode(',', array_slice($skus, 0, 100)); // Limit API call
            
            $response = $this->api->productSync(
                $api_id,
                'P', // Partial sync for category info
                $sku_csv,
                [],
                1,
                500
            );

            if (isset($response['error'])) {
                return ['status' => false, 'message' => 'API Error: ' . $response['error'], 'processed' => 0];
            }

            $items = $response['item_catalog']['items'] ?? [];
            if (empty($items)) {
                return ['status' => false, 'message' => 'No items returned from API', 'processed' => 0];
            }

            // Create SKU to item mapping
            $item_by_sku = [];
            foreach ($items as $item) {
                $sku = trim($item['item_code'] ?? '');
                if (!empty($sku)) {
                    $item_by_sku[$sku] = $item;
                }
            }

            $updated = 0;
            foreach ($products as $product) {
                if (!isset($item_by_sku[$product->sku])) {
                    continue; // SKU not found in API response
                }

                $item = $item_by_sku[$product->sku];
                $target_categories = $this->determineAllCategoriesFromItem($item);
                
                if (!empty($target_categories)) {
                    $categories_updated = $this->updateProductCategories($product->product_id, $target_categories);
                    if ($categories_updated) {
                        $updated++;
                        error_log("SC Sync: Fixed categories for SKU {$product->sku} - assigned to: " . implode(', ', $target_categories));
                    }
                }
            }

            return [
                'status' => true,
                'message' => "Updated categories for {$updated} products out of " . count($products) . " checked",
                'processed' => count($products),
                'updated' => $updated
            ];

        } catch (Exception $e) {
            error_log('SC Fix Categories Error: ' . $e->getMessage());
            return ['status' => false, 'message' => 'Error: ' . $e->getMessage(), 'processed' => 0];
        }
    }

    // ... (rest of the methods remain the same as in the original code)

    /**
     * Simple discontinued products removal
     * Uses a conservative approach to avoid accidentally deleting products
     */
    private function removeDiscontinuedProducts($active_skus = []) {
        global $wpdb;
        
        // Get products that haven't been synced in the last 7 days
        $stale_threshold = date('Y-m-d H:i:s', strtotime('-7 days'));
        
        $stale_products = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID as product_id, pm1.meta_value as sku
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_sc_last_sync'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND pm1.meta_value != ''
            AND (pm2.meta_value IS NULL OR pm2.meta_value < %s)
            LIMIT 50
        ", $stale_threshold));

        if (empty($stale_products)) {
            return 0;
        }

        $deleted = 0;
        foreach ($stale_products as $product) {
            // If we have active SKUs list, double-check that this SKU is not in it
            if (!empty($active_skus) && in_array($product->sku, $active_skus)) {
                continue; // Skip deletion if SKU is still active
            }

            // Delete the product
            if (wp_delete_post($product->product_id, true)) {
                $deleted++;
                error_log("SC Sync: Deleted discontinued product SKU: {$product->sku}");
            }
        }

        return $deleted;
    }

    /**
     * Sync a single category efficiently using large batches
     */
    private function syncCategory($category_name) {
        $api_id = get_option('sc_api_id');
        if (empty($api_id)) {
            return ['processed' => 0, 'created' => 0, 'active_skus' => []];
        }

        $processed = 0;
        $created = 0;
        $active_skus = [];
        $page = 1;

        do {
            // Get products from API with large batch size
            $response = $this->api->productSync(
                $api_id,
                'F', // Full sync to get dimensions
                '', // No SKU CSV - category-driven
                [$category_name],
                $page,
                $this->batch_size
            );

            if (isset($response['error'])) {
                error_log("SC API Error for {$category_name}: " . $response['error']);
                break;
            }

            $items = $response['item_catalog']['items'] ?? [];
            if (empty($items)) {
                break;
            }

            // Track active SKUs
            foreach ($items as $item) {
                $sku = trim($item['item_code'] ?? '');
                if (!empty($sku)) {
                    $active_skus[] = $sku;
                }
            }

            // Process all items in this batch - now uses multiple categories logic
            $batch_result = $this->processItemsWithMultipleCategories($items);
            $processed += $batch_result['processed'];
            $created += $batch_result['created'];

            // Check if we have more pages
            $page_total = $response['item_catalog']['page_total'] ?? 1;
            $page++;
            
            if ($page > $page_total) {
                break;
            }

            // Brief pause to be gentle on shared hosting
            sleep(1);
            
        } while (true);

        return ['processed' => $processed, 'created' => $created, 'active_skus' => $active_skus];
    }

    /**
     * Sync existing products by their current SKUs (ensures no products are missed)
     */
    private function syncExistingProducts($time_limit) {
        $start_time = time();
        $processed = 0;
        $active_skus = [];
        
        // Get all existing product SKUs in batches
        global $wpdb;
        $offset = 0;
        $batch_size = 500; // SKUs per batch
        
        do {
            if ((time() - $start_time) > ($time_limit - 10)) {
                break; // Stop if running out of time
            }
            
            // Get batch of existing SKUs
            $existing_skus = $wpdb->get_col($wpdb->prepare("
                SELECT DISTINCT pm.meta_value
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE pm.meta_key = '_sku'
                AND pm.meta_value != ''
                AND p.post_type = 'product'
                AND p.post_status = 'publish'
                ORDER BY p.ID
                LIMIT %d OFFSET %d
            ", $batch_size, $offset));
            
            if (empty($existing_skus)) {
                break; // No more SKUs
            }
            
            // Sync this batch of SKUs
            $result = $this->syncSKUBatch($existing_skus);
            $processed += $result['processed'];
            $active_skus = array_merge($active_skus, $result['active_skus']);
            
            $offset += $batch_size;
            sleep(1); // Brief pause
            
        } while (true);
        
        return ['processed' => $processed, 'active_skus' => $active_skus];
    }

    /**
     * Sync a batch of SKUs via API
     */
    private function syncSKUBatch($skus) {
        $api_id = get_option('sc_api_id');
        if (empty($api_id) || empty($skus)) {
            return ['processed' => 0, 'active_skus' => []];
        }

        try {
            $sku_csv = implode(',', array_slice($skus, 0, 100)); // Limit to 100 SKUs per API call
            
            $response = $this->api->productSync(
                $api_id,
                'F', // Full sync to get dimensions
                $sku_csv, // Use SKU CSV
                [], // No categories
                1,
                500 // Large batch size
            );

            if (isset($response['error'])) {
                error_log("SC API Error for SKU batch: " . $response['error']);
                return ['processed' => 0, 'active_skus' => []];
            }

            $items = $response['item_catalog']['items'] ?? [];
            $active_skus = [];
            
            // Track which SKUs are active
            foreach ($items as $item) {
                $sku = trim($item['item_code'] ?? '');
                if (!empty($sku)) {
                    $active_skus[] = $sku;
                }
            }
            
            // Process items - now uses simple category logic
            $result = $this->processItemsWithCategories($items);
            
            return ['processed' => $result['processed'], 'active_skus' => $active_skus];
            
        } catch (Exception $e) {
            error_log('SC SKU Batch Sync Error: ' . $e->getMessage());
            return ['processed' => 0, 'active_skus' => []];
        }
    }

    /**
     * Safely remove discontinued products using API verification
     */
    private function removeDiscontinuedProductsSafely($confirmed_active_skus) {
        global $wpdb;
        
        // Get products not seen in recent sync
        $stale_threshold = date('Y-m-d H:i:s', strtotime('-7 days')); // More conservative - 1 week
        
        $stale_skus = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT pm1.meta_value as sku
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_sc_last_sync'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND pm1.meta_value != ''
            AND (pm2.meta_value IS NULL OR pm2.meta_value < %s)
            LIMIT 50
        ", $stale_threshold));

        if (empty($stale_skus)) {
            return 0;
        }

        // Double-check with API before deleting
        $api_id = get_option('sc_api_id');
        if (empty($api_id)) {
            return 0;
        }

        try {
            $sku_csv = implode(',', $stale_skus);
            
            $response = $this->api->productSync(
                $api_id,
                'P', // Partial sync for verification
                $sku_csv,
                [],
                1,
                100
            );

            $api_active_skus = [];
            if (!isset($response['error']) && isset($response['item_catalog']['items'])) {
                foreach ($response['item_catalog']['items'] as $item) {
                    $sku = trim($item['item_code'] ?? '');
                    if (!empty($sku)) {
                        $api_active_skus[] = $sku;
                    }
                }
            }

            // Only delete SKUs that are confirmed not in API response
            $truly_discontinued = array_diff($stale_skus, $api_active_skus);
            
            $deleted = 0;
            foreach ($truly_discontinued as $sku) {
                $product_id = $this->getProductIdBySku($sku);
                if ($product_id && wp_delete_post($product_id, true)) {
                    $deleted++;
                }
            }

            return $deleted;
            
        } catch (Exception $e) {
            error_log('SC Discontinued Check Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get product ID by SKU
     */
    private function getProductIdBySku($sku) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_sku' AND meta_value = %s
            LIMIT 1
        ", $sku));
    }

    /**
     * Build image lookup cache for efficient SKU-to-image matching
     * Performance optimized: builds once per sync, used for all products
     */
    private function buildImageLookupCache() {
        if ($this->image_lookup !== null) {
            return; // Already cached
        }

        global $wpdb;
        
        // Get all images from media library with their filenames
        $images = $wpdb->get_results("
            SELECT p.ID as attachment_id, p.post_title, p.guid, pm.meta_value as filename
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type LIKE 'image/%'
            ORDER BY p.ID DESC
        ");

        $this->image_lookup = [];
        
        foreach ($images as $image) {
            if (empty($image->filename)) continue;
            
            // Extract filename without path and extension
            $filename = basename($image->filename);
            $name_without_ext = pathinfo($filename, PATHINFO_FILENAME);
            
            // Store both with and without extension for flexible matching
            $this->image_lookup[strtolower($name_without_ext)] = $image->attachment_id;
            $this->image_lookup[strtolower($filename)] = $image->attachment_id;
            
            // Also try the post_title in case it matches SKU
            if (!empty($image->post_title)) {
                $this->image_lookup[strtolower($image->post_title)] = $image->attachment_id;
            }
        }

        error_log("SC Sync: Built image lookup cache with " . count($this->image_lookup) . " entries");
    }

    /**
     * Assign product images based on SKU matching
     * Looks for images with filenames matching the product SKU
     */
    private function assignProductImages($product_id, $sku) {
        if (empty($sku) || empty($this->image_lookup)) {
            return;
        }

        $sku_lower = strtolower($sku);
        $attachment_id = null;

        // Try different variations of SKU matching
        $variations = [
            $sku_lower,
            $sku_lower . '.jpg',
            $sku_lower . '.jpeg', 
            $sku_lower . '.png',
            $sku_lower . '.webp',
            str_replace(['-', '_', ' '], '', $sku_lower), // Remove separators
            str_replace(['-', '_', ' '], '', $sku_lower) . '.jpg'
        ];

        foreach ($variations as $variation) {
            if (isset($this->image_lookup[$variation])) {
                $attachment_id = $this->image_lookup[$variation];
                break;
            }
        }

        if ($attachment_id) {
            // Check if product already has this image to avoid unnecessary updates
            $current_image_id = get_post_meta($product_id, '_thumbnail_id', true);
            
            if ($current_image_id != $attachment_id) {
                // Set as featured image
                set_post_thumbnail($product_id, $attachment_id);
                
                // Also add to product gallery if not already there
                $gallery_ids = get_post_meta($product_id, '_product_image_gallery', true);
                $gallery_array = !empty($gallery_ids) ? explode(',', $gallery_ids) : [];
                
                if (!in_array($attachment_id, $gallery_array)) {
                    array_unshift($gallery_array, $attachment_id); // Add to beginning
                    update_post_meta($product_id, '_product_image_gallery', implode(',', array_unique($gallery_array)));
                }
                
                error_log("SC Sync: Assigned image (ID: {$attachment_id}) to product SKU: {$sku}");
            }
        }
    }

    /**
     * MANUAL IMAGE ASSIGNMENT: Assign images to all existing products (one-time operation)
     * This can be run separately to retroactively assign images to existing products
     */
    public function assignImagesToAllProducts($limit = 100) {
        $this->buildImageLookupCache();
        
        if (empty($this->image_lookup)) {
            return ['status' => false, 'message' => 'No images found in media library', 'processed' => 0];
        }

        global $wpdb;
        
        // Get products without featured images
        $products = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID as product_id, pm.meta_value as sku
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_thumbnail_id'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND pm.meta_value != ''
            AND (pm2.meta_value IS NULL OR pm2.meta_value = '')
            LIMIT %d
        ", $limit));

        $assigned = 0;
        foreach ($products as $product) {
            $this->assignProductImages($product->product_id, $product->sku);
            
            // Check if image was actually assigned
            if (get_post_meta($product->product_id, '_thumbnail_id', true)) {
                $assigned++;
            }
        }

        return [
            'status' => true,
            'message' => "Assigned images to {$assigned} products out of " . count($products) . " checked",
            'processed' => count($products),
            'assigned' => $assigned
        ];
    }

    /**
     * Set WooCommerce product meta data
     */
    private function setProductMeta($product_id, $data) {
        error_log("SC Sync: Setting meta for product ID {$product_id}, SKU: {$data['sku']}");
        
        $meta = [
            '_sku' => $data['sku'],
            '_manage_stock' => 'yes',
            '_stock' => $data['stock_qty'],
            '_stock_status' => $data['stock_status'],
            '_sc_last_sync' => current_time('mysql')
        ];

        // Add pricing
        if ($data['price'] > 0) {
            $meta['_regular_price'] = $data['price'];
            $meta['_price'] = $data['price'];
        }

        // Add dimensions (no weight per user request)
        if ($data['length'] > 0) $meta['_length'] = $data['length'];
        if ($data['width'] > 0) $meta['_width'] = $data['width'];
        if ($data['height'] > 0) $meta['_height'] = $data['height'];

        // Set all meta at once
        $meta_success = 0;
        $meta_failed = 0;
        foreach ($meta as $key => $value) {
            $result = update_post_meta($product_id, $key, $value);
            if ($result !== false) {
                $meta_success++;
            } else {
                $meta_failed++;
                error_log("SC Sync: Failed to set meta {$key} for product ID {$product_id}");
            }
        }
        
        error_log("SC Sync: Set {$meta_success} meta fields successfully, {$meta_failed} failed for product ID {$product_id}");

        // Use WooCommerce methods for dimensions to ensure proper saving
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            if ($product) {
                if ($data['length'] > 0) $product->set_length($data['length']);
                if ($data['width'] > 0) $product->set_width($data['width']);
                if ($data['height'] > 0) $product->set_height($data['height']);
                $wc_result = $product->save();
                error_log("SC Sync: WooCommerce product->save() returned: " . print_r($wc_result, true));
            } else {
                error_log("SC Sync: Could not get WooCommerce product object for ID {$product_id}");
            }
        }
    }

    /**
     * Extract product data from API response
     */
    private function extractProductData($item) {
        // Stock from stock_status field
        $stock_qty = max(0, intval($item['stock_status'] ?? 0));
        $stock_status = $stock_qty > 0 ? 'instock' : 'outofstock';

        // Pricing
        $price = floatval($item['retail_price'] ?? 0);

        // Dimensions from semicolon-separated string
        $length = $width = $height = 0;
        if (!empty($item['dimmensions'])) {
            $dims = explode(';', $item['dimmensions']);
            if (count($dims) >= 3) {
                $length = floatval(trim($dims[0] ?? 0));
                $width = floatval(trim($dims[1] ?? 0));
                $height = floatval(trim($dims[2] ?? 0));
            }
        }

        return [
            'sku' => trim($item['item_code'] ?? ''),
            'title' => trim($item['item_description'] ?? ''),
            'description' => trim($item['item_description'] ?? ''),
            'stock_qty' => $stock_qty,
            'stock_status' => $stock_status,
            'price' => $price,
            'length' => $length,
            'width' => $width,
            'height' => $height
        ];
    }

    /**
     * Get WooCommerce categories
     */
    private function getWooCommerceCategories() {
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'fields' => 'names'
        ]);

        if (is_wp_error($categories)) return [];

        // Filter out default categories and ensure it's a simple indexed array
        $filtered = array_filter($categories, function($cat) {
            return !in_array(strtolower($cat), ['uncategorized']);
        });

        // Re-index the array to ensure it's a simple indexed array, not associative
        return array_values($filtered);
    }

    /**
     * Get existing products by SKUs efficiently - only PUBLISHED products
     */
    private function getProductsBySKUs($skus) {
        if (empty($skus)) return [];

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($skus), '%s'));
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID as product_id, pm.meta_value as sku, p.post_status
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND pm.meta_key = '_sku'
            AND pm.meta_value IN ({$placeholders})
        ", $skus));

        $product_map = [];
        $status_count = [];
        
        foreach ($results as $row) {
            $product_map[$row->sku] = $row->product_id;
            $status_count[$row->post_status] = ($status_count[$row->post_status] ?? 0) + 1;
        }

        error_log("SC Sync: Found " . count($product_map) . " PUBLISHED products by SKU. Status breakdown: " . print_r($status_count, true));
        return $product_map;
    }

    // Public methods for admin interface
    public function getSyncStats() {
        global $wpdb;
        $total_products = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts} 
            WHERE post_type = 'product' AND post_status = 'publish'
        ");

        return [
            'total_products' => intval($total_products),
            'last_sync' => get_option('sc_last_sync_time', 'Never'),
            'last_count' => get_option('sc_last_sync_count', 0),
            'batch_size' => $this->batch_size
        ];
    }

    public function getRecentLogs() {
        // Return minimal logs to reduce overhead
        return [
            'Last sync: ' . get_option('sc_last_sync_time', 'Never'),
            'Products synced: ' . get_option('sc_last_sync_count', 0)
        ];
    }

    /**
     * NEW METHOD: Show which categories from API don't exist in WooCommerce
     * This helps identify which categories you might want to create manually
     */
    public function checkMissingCategories($limit = 200) {
        $api_id = get_option('sc_api_id');
        if (empty($api_id)) {
            return ['status' => false, 'message' => 'No API ID configured', 'processed' => 0];
        }

        try {
            // Get sample of products from API to discover categories
            $response = $this->api->productSync(
                $api_id,
                'P', // Partial sync just for category discovery
                '', // No SKU filter
                [], // All categories
                1,
                $limit
            );

            if (isset($response['error'])) {
                return ['status' => false, 'message' => 'API Error: ' . $response['error'], 'processed' => 0];
            }

            $items = $response['item_catalog']['items'] ?? [];
            if (empty($items)) {
                return ['status' => false, 'message' => 'No items returned from API', 'processed' => 0];
            }

            // Collect all unique primary categories from API
            $api_categories = [];
            
            foreach ($items as $item) {
                $primary_category = trim($item['primary_category'] ?? '');
                
                if (!empty($primary_category)) {
                    $api_categories[$primary_category] = true;
                }
            }

            // Check which ones exist in WooCommerce
            $missing_categories = [];
            $existing_categories = [];
            
            foreach (array_keys($api_categories) as $category_name) {
                if ($this->categoryExists($category_name)) {
                    $existing_categories[] = $category_name;
                } else {
                    $missing_categories[] = $category_name;
                }
            }

            error_log("SC Sync: Category Analysis - Missing: " . implode(', ', $missing_categories));
            error_log("SC Sync: Category Analysis - Existing: " . implode(', ', $existing_categories));

            return [
                'status' => true,
                'message' => "Category analysis complete",
                'processed' => count($items),
                'missing' => $missing_categories,
                'existing' => $existing_categories
            ];

        } catch (Exception $e) {
            error_log('SC Check Categories Error: ' . $e->getMessage());
            return ['status' => false, 'message' => 'Error: ' . $e->getMessage(), 'processed' => 0];
        }
    }

    // Backward compatibility
    public function syncProductsInChunks($full_sync = true, $force_sync = false, $rows_per_request = null) {
        return $this->syncProducts($force_sync);
    }
}