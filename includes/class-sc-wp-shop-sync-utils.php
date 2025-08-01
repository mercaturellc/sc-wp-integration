<?php

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SC_WP_Shop_Sync_Utils - SHARED HOSTING OPTIMIZED
 *
 * Optimized utility class for shared hosting environments
 * Features:
 * - Smaller query batches
 * - Better memory management  
 * - Improved discontinued item handling with detailed logging
 * - Conservative resource usage
 */
class SC_WP_Shop_Sync_Utils {
    private $distributor_id;
    private $query_batch_size = 1500; // Smaller batch size for shared hosting

    public function __construct($distributor_id) {
        $this->distributor_id = $distributor_id;
    }

    /**
     * Get WooCommerce product SKUs - OPTIMIZED for shared hosting
     * Uses smaller queries and better memory management
     *
     * @param bool $include_unassigned (Ignored for compatibility)
     * @param int $limit Number of products to retrieve
     * @param int $offset Starting offset for pagination
     * @return array Array of SKUs
     */
    public function getAllWooCommerceSKUs($include_unassigned = true, $limit = 100, $offset = 0) {
        global $wpdb;

        // Use conservative limits for shared hosting
        $safe_limit = min($limit, $this->query_batch_size);

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                p.ID,
                p.post_title,
                COALESCE(pm.meta_value, '') as sku
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            ORDER BY p.ID
            LIMIT %d OFFSET %d
        ", $safe_limit, $offset));

        $skus = [];
        $skipped_count = 0;
        
        foreach ($results as $result) {
            $sku = trim($result->sku);
            if (!empty($sku)) {
                $skus[] = $sku;
                error_log("SC Utils: Found product '{$result->post_title}' (ID: {$result->ID}) with SKU: '{$sku}'");
            } else {
                $skipped_count++;
            }
        }

        error_log("SC Utils: Processed " . count($results) . " products at offset {$offset}");
        error_log("SC Utils: Found " . count($skus) . " valid SKUs, skipped {$skipped_count} without SKUs");
        
        // Log sample SKUs for debugging
        if (!empty($skus)) {
            error_log("SC Utils: Sample SKUs: " . implode(', ', array_slice($skus, 0, 5)));
        }

        return array_values($skus);
    }

    /**
     * Get total count of products with better performance for shared hosting
     *
     * @return int Total number of products 
     */
    public function getTotalProductsWithSKUs() {
        global $wpdb;

        // Use simpler query for better performance
        $count = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->posts}
            WHERE post_type = 'product'
            AND post_status = 'publish'
        ");

        $total = intval($count);
        error_log("SC Utils: Total products found: {$total}");
        
        return $total;
    }

    /**
     * Convert SKU array to CSV string with detailed logging
     * Essential for proper discontinued item tracking
     *
     * @param array $skus Array of SKUs
     * @return string CSV string of SKUs
     */
    public function skusToCSV($skus) {
        if (empty($skus)) {
            error_log("SC Utils: skusToCSV received empty array");
            return '';
        }

        $original_count = count($skus);
        error_log("SC Utils: Converting {$original_count} SKUs to CSV");
        
        // Clean and validate SKUs
        $clean_skus = [];
        $empty_skus = 0;
        $duplicate_skus = 0;
        
        foreach ($skus as $sku) {
            $clean_sku = trim(sanitize_text_field($sku));
            if (empty($clean_sku)) {
                $empty_skus++;
                continue;
            }
            
            if (in_array($clean_sku, $clean_skus)) {
                $duplicate_skus++;
                continue;
            }
            
            $clean_skus[] = $clean_sku;
        }

        // Log cleaning results
        if ($empty_skus > 0) {
            error_log("SC Utils: Filtered out {$empty_skus} empty SKUs");
        }
        if ($duplicate_skus > 0) {
            error_log("SC Utils: Filtered out {$duplicate_skus} duplicate SKUs");
        }
        
        $final_count = count($clean_skus);
        error_log("SC Utils: Final CSV will contain {$final_count} valid unique SKUs");
        
        // Log first few SKUs for debugging
        if (!empty($clean_skus)) {
            $sample = array_slice($clean_skus, 0, 5);
            error_log("SC Utils: Sample SKUs being sent to API: " . implode(', ', $sample));
        }

        return implode(',', $clean_skus);
    }

    /**
     * IMPROVED discontinued products handling with detailed logging
     * This is critical for ensuring items not in distributor catalog are removed
     *
     * @param array $skus Array of SKUs to discontinue/delete
     * @return int Number of products discontinued
     */
    public function discontinueProducts($skus) {
        if (empty($skus)) {
            error_log("SC Utils: discontinueProducts - No SKUs provided");
            return 0;
        }

        // Clean and validate SKUs
        $clean_skus = array_unique(array_filter(array_map('trim', $skus)));
        
        if (empty($clean_skus)) {
            error_log("SC Utils: discontinueProducts - No valid SKUs after cleaning");
            return 0;
        }

        $total_skus = count($clean_skus);
        error_log("SC Utils: discontinueProducts - Processing {$total_skus} SKUs for deletion");
        error_log("SC Utils: SKUs to delete: " . implode(', ', array_slice($clean_skus, 0, 10)) . 
                 ($total_skus > 10 ? '...' : ''));

        $deleted_count = 0;
        $not_found_count = 0;
        $error_count = 0;

        // Process SKUs in small batches for shared hosting
        $batches = array_chunk($clean_skus, 20); // Small batches
        
        foreach ($batches as $batch_index => $batch_skus) {
            error_log("SC Utils: Processing deletion batch " . ($batch_index + 1) . "/" . count($batches));
            
            foreach ($batch_skus as $sku) {
                $product_id = $this->getProductIdBySku($sku);
                
                if (!$product_id) {
                    $not_found_count++;
                    error_log("SC Utils: Product not found for SKU '{$sku}' - may have been deleted already");
                    continue;
                }
                
                // Get product details before deletion
                $product = get_post($product_id);
                $product_title = $product ? $product->post_title : 'Unknown Title';
                
                // Attempt to delete product
                $deletion_result = wp_delete_post($product_id, true); // true = force delete, bypass trash
                
                if ($deletion_result) {
                    $deleted_count++;
                    error_log("SC Utils: DELETED product '{$product_title}' (ID: {$product_id}, SKU: {$sku})");
                } else {
                    $error_count++;
                    error_log("SC Utils: FAILED to delete product '{$product_title}' (ID: {$product_id}, SKU: {$sku})");
                }
            }
            
            // Pause between batches to be gentle on shared hosting
            if ($batch_index < count($batches) - 1) {
                sleep(1);
            }
        }

        // Log final results
        error_log("SC Utils: Deletion completed - Deleted: {$deleted_count}, Not Found: {$not_found_count}, Errors: {$error_count}");
        
        if ($error_count > 0) {
            error_log("SC Utils: WARNING - {$error_count} products failed to delete. Check WordPress permissions and database integrity.");
        }

        return $deleted_count;
    }

    /**
     * Get product ID by SKU with improved error handling
     *
     * @param string $sku Product SKU
     * @return int|false Product ID or false
     */
    public function getProductIdBySku($sku) {
        $sku = trim($sku);
        if (empty($sku)) {
            return false;
        }
        
        // Try WooCommerce function first (if available)
        if (function_exists('wc_get_product_id_by_sku')) {
            $product_id = wc_get_product_id_by_sku($sku);
            if ($product_id) {
                return $product_id;
            }
        }

        // Fallback to direct database query
        global $wpdb;
        
        try {
            $product_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                WHERE meta_key = '_sku'
                AND meta_value = %s
                LIMIT 1",
                $sku
            ));

            return $product_id ? (int) $product_id : false;
            
        } catch (Exception $e) {
            error_log("SC Utils: Error finding product by SKU '{$sku}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get products without SKUs - OPTIMIZED for shared hosting
     *
     * @param int $limit Limit number of results
     * @return array Array of product objects without SKUs
     */
    public function getProductsWithoutSKUs($limit = 50) {
        global $wpdb;

        // Use smaller limits for shared hosting
        $safe_limit = min($limit, $this->query_batch_size);

        try {
            $products = $wpdb->get_results($wpdb->prepare("
                SELECT p.ID, p.post_title
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
                WHERE p.post_type = 'product'
                AND p.post_status = 'publish'
                AND (pm.meta_value IS NULL OR pm.meta_value = '')
                LIMIT %d
            ", $safe_limit));

            $count = count($products);
            error_log("SC Utils: Found {$count} products without SKUs");

            return $products ?: [];
            
        } catch (Exception $e) {
            error_log("SC Utils: Error getting products without SKUs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Discontinue products without SKUs (set them to out of stock)
     * OPTIMIZED for shared hosting with smaller batches
     *
     * @param array $products Array of product objects
     * @return int Number of products discontinued
     */
    public function discontinueProductsWithoutSKUs($products) {
        if (empty($products)) {
            error_log("SC Utils: No products without SKUs to discontinue");
            return 0;
        }

        $total_products = count($products);
        error_log("SC Utils: Discontinuing {$total_products} products without SKUs");

        global $wpdb;
        $discontinued_count = 0;
        
        // Process in small batches
        $batches = array_chunk($products, 25); // Smaller batches for shared hosting
        
        foreach ($batches as $batch_index => $batch_products) {
            $meta_updates = [];
            
            foreach ($batch_products as $product) {
                $id = intval($product->ID);
                $meta_updates[] = "({$id}, '_stock', '0')";
                $meta_updates[] = "({$id}, '_stock_status', 'outofstock')";
                $meta_updates[] = "({$id}, '_manage_stock', 'yes')";
                
                error_log("SC Utils: Discontinuing product without SKU: '{$product->post_title}' (ID: {$product->ID})");
            }

            if (!empty($meta_updates)) {
                try {
                    $values = implode(',', $meta_updates);
                    $result = $wpdb->query("
                        INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                        VALUES {$values}
                        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)
                    ");
                    
                    if ($result !== false) {
                        $discontinued_count += count($batch_products);
                    } else {
                        error_log("SC Utils: Failed to discontinue batch " . ($batch_index + 1) . ": " . $wpdb->last_error);
                    }
                    
                } catch (Exception $e) {
                    error_log("SC Utils: Exception discontinuing batch " . ($batch_index + 1) . ": " . $e->getMessage());
                }
            }
            
            // Pause between batches
            if ($batch_index < count($batches) - 1) {
                sleep(1);
            }
        }

        error_log("SC Utils: Discontinued {$discontinued_count} products without SKUs");
        return $discontinued_count;
    }

    /**
     * Validate configuration with better error reporting
     *
     * @return array Status and message
     */
    public function validateConfig() {
        $api_id = get_option('sc_api_id', '');
        $errors = [];

        if (empty($api_id)) {
            $errors[] = 'API ID is not configured';
        }

        if (!function_exists('wc_get_product_id_by_sku')) {
            $errors[] = 'WooCommerce is not active or not properly installed';
        }

        // Check database connectivity
        global $wpdb;
        if (!$wpdb || $wpdb->last_error) {
            $errors[] = 'Database connection issues detected';
        }

        // Check memory limit
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit && intval($memory_limit) < 64) {
            $errors[] = 'Memory limit may be too low for sync operations (current: ' . $memory_limit . ')';
        }

        if (!empty($errors)) {
            $error_message = implode(', ', $errors);
            error_log('SC Utils: Configuration errors: ' . $error_message);
            return ['status' => false, 'message' => $error_message];
        }

        error_log('SC Utils: Configuration validation passed');
        return ['status' => true, 'message' => 'Configuration valid'];
    }

    /**
     * Get total count of all products (with and without SKUs)
     * OPTIMIZED query for shared hosting
     *
     * @return int Total number of products
     */
    public function getTotalProducts() {
        global $wpdb;

        try {
            $count = $wpdb->get_var("
                SELECT COUNT(*)
                FROM {$wpdb->posts}
                WHERE post_type = 'product'
                AND post_status = 'publish'
            ");

            return intval($count);
            
        } catch (Exception $e) {
            error_log('SC Utils: Error getting total products: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Gentle memory cleanup for shared hosting
     */
    public function cleanupMemory() {
        $before_mb = round(memory_get_usage(true) / 1024 / 1024, 2);
        
        // Clear caches gently
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients();
        }
        
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // Clear database query cache
        global $wpdb;
        if ($wpdb && method_exists($wpdb, 'flush')) {
            $wpdb->flush();
        }

        // Garbage collection
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        $after_mb = round(memory_get_usage(true) / 1024 / 1024, 2);
        $freed_mb = $before_mb - $after_mb;
        
        if ($freed_mb > 1) {
            error_log("SC Utils: Memory cleanup freed {$freed_mb}MB");
        }
    }

    /**
     * Get memory usage information
     * 
     * @return array Memory usage details
     */
    public function getMemoryInfo() {
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = ini_get('memory_limit');
        
        return [
            'current_mb' => round($current / 1024 / 1024, 2),
            'peak_mb' => round($peak / 1024 / 1024, 2),
            'limit' => $limit,
            'limit_mb' => $limit ? intval($limit) : 'Unknown'
        ];
    }
}