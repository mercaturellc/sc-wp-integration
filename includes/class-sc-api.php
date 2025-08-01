<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SC_API - Updated API client to match current API specification
 * Focuses on the two core methods: productSync and orderProcess
 * 
 * NEW: Enhanced support for special categories with better debugging
 */
class SC_API {
    private $api_url = 'https://sc.mercature.net/api/v1/';
    private $timeout = 60;
    private $last_request_time = 0;
    private $min_request_interval = 1; // 1 second between requests

    /**
     * Product sync with distributor API
     * 
     * @param string $api_id API authentication ID
     * @param string $sync_mode 'F' for full sync, 'P' for partial/stock sync
     * @param string $item_sku_csv Comma-separated SKUs to sync
     * @param array $item_category_list Array of category names to filter by (includes special categories)
     * @param int $page Page number (for pagination)
     * @param int $rows Number of rows per page
     * @param string $locale Locale setting (default: 'us')
     * @return array API response with product data including special category matching
     */
    public function productSync($api_id, $sync_mode, $item_sku_csv = '', $item_category_list = [], $page = 1, $rows = 50, $locale = 'us') {
        if (empty($api_id)) {
            error_log("SC API DEBUG: API ID is empty");
            return ['error' => 'API ID is required'];
        }

        // DEBUG LOGGING
        error_log("SC API DEBUG: productSync called with API ID length: " . strlen($api_id));
        error_log("SC API DEBUG: Sync mode: " . $sync_mode);
        error_log("SC API DEBUG: SKU CSV: " . $item_sku_csv);
        error_log("SC API DEBUG: Categories (including special): " . json_encode($item_category_list));
        error_log("SC API DEBUG: Page: " . $page . ", Rows: " . $rows);

        $endpoint = 'product/sync';
        $payload = [
            'api_id' => sanitize_text_field($api_id),
            'locale' => sanitize_text_field($locale),
            'sync_mode' => in_array($sync_mode, ['F', 'P']) ? $sync_mode : 'P',
            'page' => max(1, intval($page)),
            'rows' => max(1, min(1000, intval($rows)))
        ];

        // Add item_sku_csv if provided
        if (!empty($item_sku_csv)) {
            $payload['item_sku_csv'] = sanitize_text_field($item_sku_csv);
        }

        // Add item_category_list if provided (can include special categories like "Specials", "New")
        if (!empty($item_category_list)) {
            $payload['item_category_list'] = array_map('sanitize_text_field', $item_category_list);
        }

        // DEBUG LOGGING
        error_log("SC API DEBUG: Full payload: " . json_encode($payload));

        $response = $this->makeRequest($endpoint, $payload);
        
        // DEBUG LOGGING
        error_log("SC API DEBUG: Raw makeRequest response: " . json_encode($response));
        
        // Normalize response format
        $normalized = $this->normalizeProductResponse($response);
        
        // DEBUG LOGGING - Enhanced for special categories
        $this->logSpecialCategoriesDebug($normalized);
        
        return $normalized;
    }

    /**
     * Enhanced debug logging specifically for special categories response data
     */
    private function logSpecialCategoriesDebug($response) {
        if (isset($response['error'])) {
            error_log("SC API DEBUG: Response contains error: " . $response['error']);
            return;
        }

        $items = $response['item_catalog']['items'] ?? [];
        if (empty($items)) {
            error_log("SC API DEBUG: No items in response");
            return;
        }

        $special_matches = 0;
        $primary_matches = 0;
        $sample_items = [];

        foreach ($items as $index => $item) {
            $is_special_match = trim($item['is_special_category_match'] ?? '0');
            $special_category = trim($item['special_category'] ?? '');
            $primary_category = trim($item['primary_category'] ?? '');
            $sku = trim($item['item_code'] ?? 'UNKNOWN');

            if ($is_special_match === '1') {
                $special_matches++;
            } else {
                $primary_matches++;
            }

            // Collect first 3 items as samples for detailed logging
            if ($index < 3) {
                $sample_items[] = [
                    'sku' => $sku,
                    'is_special_match' => $is_special_match,
                    'special_category' => $special_category,
                    'primary_category' => $primary_category
                ];
            }
        }

        error_log("SC API DEBUG: Special Categories Summary:");
        error_log("SC API DEBUG: - Total items: " . count($items));
        error_log("SC API DEBUG: - Special category matches: " . $special_matches);
        error_log("SC API DEBUG: - Primary category matches: " . $primary_matches);
        error_log("SC API DEBUG: - Sample items: " . json_encode($sample_items));
    }

    /**
     * Process order through distributor API
     * 
     * @param string $api_id API authentication ID
     * @param WC_Order $order WooCommerce order object
     * @param array $items Filtered order items
     * @param array $options Additional options (e.g., customer note, distributor_id, testing mode)
     * @return array API response
     */
    public function orderProcess($api_id, $order, $items, $options = []) {
        if (empty($api_id)) {
            return ['error' => 'API ID is required'];
        }

        if (!$order instanceof WC_Order) {
            return ['error' => 'Invalid order object'];
        }

        if (empty($items)) {
            return ['error' => 'No items to process'];
        }

        $endpoint = 'order/process';
        
        $payload = $this->buildOrderPayload($api_id, $order, $items, $options);
        
        return $this->makeRequest($endpoint, $payload);
    }

    /**
     * Make HTTP request to API with error handling and rate limiting
     */
    private function makeRequest($endpoint, $payload) {
        // Rate limiting
        $this->enforceRateLimit();

        $url = $this->api_url . $endpoint;
        
        $args = [
            'body' => wp_json_encode($payload),
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'SC-WP-Plugin/' . (defined('SC_WP_PLUGIN_VERSION') ? SC_WP_PLUGIN_VERSION : '1.0')
            ],
            'timeout' => $this->timeout,
            'sslverify' => true, // Enable SSL verification for security
            'method' => 'POST'
        ];

        error_log("SC API: Requesting {$endpoint} with " . strlen($args['body']) . " bytes of data");
        
        // DEBUG LOGGING
        error_log("SC API DEBUG: Full URL: " . $url);
        error_log("SC API DEBUG: Request body: " . $args['body']);
        error_log("SC API DEBUG: Request headers: " . json_encode($args['headers']));

        $response = wp_remote_post($url, $args);

        // Handle HTTP errors
        if (is_wp_error($response)) {
            $error_message = 'HTTP request failed: ' . $response->get_error_message();
            error_log("SC API: {$error_message}");
            // DEBUG LOGGING
            error_log("SC API DEBUG: WP Error details: " . $response->get_error_message());
            return ['error' => $error_message];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // DEBUG LOGGING
        error_log("SC API DEBUG: HTTP Status Code: " . $status_code);
        error_log("SC API DEBUG: Raw response body length: " . strlen($body) . " characters");
        error_log("SC API DEBUG: Response headers: " . json_encode(wp_remote_retrieve_headers($response)));
        
        // Only log first 1000 characters of response body to avoid overwhelming logs
        if (strlen($body) > 1000) {
            error_log("SC API DEBUG: Response body preview: " . substr($body, 0, 1000) . "... [truncated]");
        } else {
            error_log("SC API DEBUG: Full response body: " . $body);
        }

        // Handle HTTP status codes
        if ($status_code !== 200) {
            $error_message = "HTTP {$status_code}: " . wp_remote_retrieve_response_message($response);
            error_log("SC API: {$error_message}");
            // DEBUG LOGGING
            error_log("SC API DEBUG: Non-200 status - body: " . $body);
            return ['error' => $error_message];
        }

        // Parse JSON response
        $decoded = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = 'Invalid JSON response: ' . json_last_error_msg();
            error_log("SC API: {$error_message}. Raw response: " . substr($body, 0, 500));
            // DEBUG LOGGING
            error_log("SC API DEBUG: JSON decode error: " . json_last_error_msg() . " - Full body: " . $body);
            return ['error' => $error_message];
        }

        error_log("SC API: {$endpoint} completed successfully");
        // DEBUG LOGGING
        error_log("SC API DEBUG: Successfully decoded JSON response with " . count($decoded) . " top-level keys");
        
        return $decoded;
    }

    /**
     * Enforce rate limiting between API requests
     */
    private function enforceRateLimit() {
        $current_time = microtime(true);
        $time_since_last = $current_time - $this->last_request_time;
        
        if ($time_since_last < $this->min_request_interval) {
            $sleep_time = $this->min_request_interval - $time_since_last;
            usleep($sleep_time * 1000000);
        }
        
        $this->last_request_time = microtime(true);
    }

    /**
     * Normalize product sync response to consistent format
     * Enhanced for special categories support
     */
    private function normalizeProductResponse($response) {
        // DEBUG LOGGING
        error_log("SC API DEBUG: normalizeProductResponse input keys: " . implode(', ', array_keys($response)));
        
        if (isset($response['error'])) {
            // DEBUG LOGGING
            error_log("SC API DEBUG: Response contains error: " . $response['error']);
            return $response;
        }

        // Ensure consistent structure
        $normalized = [
            'item_catalog' => [
                'items' => [],
                'categories' => [],
                'page_num' => 1,
                'page_total' => 1,
                'item_total' => 0,
                'item_count' => 0
            ]
        ];

        // Extract items from various possible response structures
        if (isset($response['item_catalog']['items'])) {
            $normalized['item_catalog']['items'] = $response['item_catalog']['items'];
            // DEBUG LOGGING
            error_log("SC API DEBUG: Found items in response['item_catalog']['items'] - count: " . count($response['item_catalog']['items']));
        } elseif (isset($response['items'])) {
            $normalized['item_catalog']['items'] = $response['items'];
            // DEBUG LOGGING
            error_log("SC API DEBUG: Found items in response['items'] - count: " . count($response['items']));
        } else {
            // DEBUG LOGGING
            error_log("SC API DEBUG: NO ITEMS FOUND in response structure");
        }

        // Extract categories
        if (isset($response['item_catalog']['categories'])) {
            $normalized['item_catalog']['categories'] = $response['item_catalog']['categories'];
        } elseif (isset($response['categories'])) {
            $normalized['item_catalog']['categories'] = $response['categories'];
        }

        // Extract pagination info
        if (isset($response['item_catalog'])) {
            // DEBUG LOGGING
            error_log("SC API DEBUG: item_catalog keys: " . implode(', ', array_keys($response['item_catalog'])));
            
            $catalog = $response['item_catalog'];
            $normalized['item_catalog']['page_num'] = isset($catalog['page_num']) ? intval($catalog['page_num']) : 1;
            $normalized['item_catalog']['page_total'] = isset($catalog['page_total']) ? intval($catalog['page_total']) : 1;
            $normalized['item_catalog']['item_total'] = isset($catalog['item_total']) ? intval($catalog['item_total']) : count($normalized['item_catalog']['items']);
            $normalized['item_catalog']['item_count'] = isset($catalog['item_count']) ? intval($catalog['item_count']) : count($normalized['item_catalog']['items']);
        } else {
            $normalized['item_catalog']['item_total'] = count($normalized['item_catalog']['items']);
            $normalized['item_catalog']['item_count'] = count($normalized['item_catalog']['items']);
            // DEBUG LOGGING
            error_log("SC API DEBUG: No item_catalog found in response");
        }

        // Validate special category fields in items
        $items_with_special_fields = 0;
        foreach ($normalized['item_catalog']['items'] as $item) {
            if (isset($item['is_special_category_match']) || isset($item['special_category'])) {
                $items_with_special_fields++;
            }
        }
        
        error_log("SC API DEBUG: Items with special category fields: {$items_with_special_fields} / " . count($normalized['item_catalog']['items']));

        return $normalized;
    }

    /**
     * Build order payload for API submission
     */
    private function buildOrderPayload($api_id, $order, $items, $options) {
        // Extract customer data
        $billing_phone = $order->get_billing_phone();
        $customer_ip = $order->get_customer_ip_address();
        
        // Extract customer note
        $customer_note = '';
        if (isset($options['customer_special_instructions'])) {
            $customer_note = $options['customer_special_instructions'];
        } else {
            $customer_note = $order->get_customer_note();
        }
        
        // Ensure note is a string
        if (!is_string($customer_note)) {
            $customer_note = '';
        }

        // Get distributor ID from options or default
        $distributor_id = isset($options['distributor_id']) ? $options['distributor_id'] : 'AZT';
        
        // Get testing mode from options
        $testing = isset($options['testing']) ? intval($options['testing']) : 0;
        
        // Get source references from options
        $ord_srcref1 = isset($options['ord_srcref1']) ? sanitize_text_field($options['ord_srcref1']) : '';
        $ord_srcref2 = isset($options['ord_srcref2']) ? sanitize_text_field($options['ord_srcref2']) : '';

        // Determine if shipping address is same as billing
        $same_address = (
            $order->get_billing_address_1() === $order->get_shipping_address_1() &&
            $order->get_billing_city() === $order->get_shipping_city() &&
            $order->get_billing_state() === $order->get_shipping_state() &&
            $order->get_billing_postcode() === $order->get_shipping_postcode()
        );

        // Get shipping names (fallback to billing if not provided)
        $shipping_first_name = $order->get_shipping_first_name() ?: $order->get_billing_first_name();
        $shipping_last_name = $order->get_shipping_last_name() ?: $order->get_billing_last_name();

        // Format items for API (using correct field names from Postman collection)
        $formatted_items = [];
        foreach ($items as $item) {
            $formatted_items[] = [
                'item_code' => sanitize_text_field($item['sku']), // API expects 'item_code'
                'item_qty' => max(1, intval($item['quantity'])) // API expects 'item_qty'
            ];
        }

        $payload = [
            'api_id' => sanitize_text_field($api_id),
            'sc_order' => [
                'distributor_id' => sanitize_text_field($distributor_id),
                'ord_instructions' => sanitize_textarea_field($customer_note),
                'ord_locale' => 'us',
                'ord_checkoutmode' => 'standard',
                'ord_ip_address' => sanitize_text_field($customer_ip ?: ''),
                'ord_requestoremail' => sanitize_email($order->get_billing_email()),
                'ord_requestorphone' => sanitize_text_field($billing_phone ?: ''),
                'ord_requestor_firstname' => sanitize_text_field($order->get_billing_first_name()),
                'ord_requestor_lastname' => sanitize_text_field($order->get_billing_last_name()),
                'ord_requestor_address' => sanitize_text_field($order->get_billing_address_1()),
                'ord_requestor_zip' => sanitize_text_field($order->get_billing_postcode()),
                'ord_requestor_city' => sanitize_text_field($order->get_billing_city()),
                'ord_requestor_state' => sanitize_text_field($order->get_billing_state()),
                'ord_same_address_flg' => $same_address,
                'ord_shipping_firstname' => sanitize_text_field($shipping_first_name),
                'ord_shipping_lastname' => sanitize_text_field($shipping_last_name),
                'ord_shipping_address' => sanitize_text_field($order->get_shipping_address_1() ?: $order->get_billing_address_1()),
                'ord_shipping_zip' => sanitize_text_field($order->get_shipping_postcode() ?: $order->get_billing_postcode()),
                'ord_shipping_city' => sanitize_text_field($order->get_shipping_city() ?: $order->get_billing_city()),
                'ord_shipping_state' => sanitize_text_field($order->get_shipping_state() ?: $order->get_billing_state()),
                'sc_items' => $formatted_items
            ]
        ];

        // Add testing flag if specified
        if ($testing) {
            $payload['testing'] = $testing;
        }

        // Add source references if provided
        if (!empty($ord_srcref1)) {
            $payload['sc_order']['ord_srcref1'] = $ord_srcref1;
        }
        if (!empty($ord_srcref2)) {
            $payload['sc_order']['ord_srcref2'] = $ord_srcref2;
        }

        return $payload;
    }

    /**
     * Test API connectivity with category filtering
     * Enhanced to work with special categories
     */
    public function testConnection($api_id, $test_categories = []) {
        if (empty($api_id)) {
            return ['success' => false, 'message' => 'API ID is required'];
        }

        try {
            // Test with both regular and special categories if provided
            $response = $this->productSync($api_id, 'P', '', $test_categories, 1, 1);
            
            if (isset($response['error'])) {
                return ['success' => false, 'message' => $response['error']];
            }

            // Enhanced success message with category info
            $message = 'API connection successful';
            if (!empty($test_categories)) {
                $message .= ' (tested with categories: ' . implode(', ', $test_categories) . ')';
            }

            return ['success' => true, 'message' => $message];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Connection test failed: ' . $e->getMessage()];
        }
    }

    /**
     * Get API status and information
     */
    public function getApiInfo() {
        return [
            'api_url' => $this->api_url,
            'timeout' => $this->timeout,
            'rate_limit' => $this->min_request_interval . ' seconds between requests',
            'ssl_verify' => 'enabled',
            'special_categories_support' => 'enabled'
        ];
    }

    /**
     * Convenience method to sync products by category (including special categories)
     */
    public function syncByCategory($api_id, $categories, $sync_mode = 'F', $page = 1, $rows = 50) {
        return $this->productSync($api_id, $sync_mode, '', $categories, $page, $rows);
    }

    /**
     * Convenience method to sync specific SKUs
     */
    public function syncBySKUs($api_id, $skus, $sync_mode = 'P', $page = 1, $rows = 50) {
        $sku_csv = is_array($skus) ? implode(',', $skus) : $skus;
        return $this->productSync($api_id, $sync_mode, $sku_csv, [], $page, $rows);
    }

    /**
     * NEW: Convenience method specifically for syncing special categories
     * @param string $api_id
     * @param array $special_categories Array of special category names like ['Specials', 'New']
     * @param array $regular_categories Array of regular category names
     * @param string $sync_mode
     * @param int $page
     * @param int $rows
     * @return array
     */
    public function syncSpecialCategories($api_id, $special_categories = [], $regular_categories = [], $sync_mode = 'F', $page = 1, $rows = 50) {
        $all_categories = array_merge($special_categories, $regular_categories);
        
        error_log("SC API: Syncing special categories: " . implode(', ', $special_categories) . 
                  " with regular categories: " . implode(', ', $regular_categories));
        
        return $this->productSync($api_id, $sync_mode, '', $all_categories, $page, $rows);
    }
}