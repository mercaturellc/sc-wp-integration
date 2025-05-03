<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}
/* SC_API
Must provide a token passed in the request body
Provides 2 methods:
- productSync
- orderProcess
*/
class SC_API {
    private $api_url = 'https://sc.mercature.net/api/v1/';

    /**
     * Sync products with the SC API
     * 
     * @param string $api_id The API ID for authentication
     * @param string $sync_mode 'F' for full sync, 'P' for partial sync
     * @param array $item_codes Optional list of item codes for partial sync
     * @param int $page Page number for pagination
     * @param int $rows Number of rows per page
     * @return array Product data including categories and pagination info
     */
    public function productSync($api_id, $sync_mode = 'F', $item_codes = [], $page = 1, $rows = 1000) {
        $endpoint = 'product/sync';
        $payload = $this->_buildProductSyncPayload($api_id, $sync_mode, $item_codes, $page, $rows);
        
        $response = $this->_makeApiRequest($endpoint, $payload);
        return $this->_parseProductResponse($response);
    }
    
    /**
     * Process an order via the SC API
     * 
     * @param string $api_id The API ID for authentication
     * @param WC_Order $order WooCommerce order object
     * @param array $items Order items to be processed (filtered items)
     * @param string $note Customer note for the order
     * @return array Response from the API
     */
    public function orderProcess($api_id, $order, $items, $note = '') {
        $endpoint = 'order/process';
        $customer = $this->_extractCustomerData($order);
        $shipping = $this->_extractShippingData($order);
        
        // Ensure note is a string
        if (!is_string($note)) {
            $note = '';
        }
        
        error_log("SC API: Processing order with customer note: " . $note);
        
        $payload = $this->_buildOrderPayload($api_id, $customer, $shipping, $items, $note);
        
        return $this->_makeApiRequest($endpoint, $payload);
    }

    /**
     * Make an API request to SC API
     * 
     * @param string $endpoint API endpoint
     * @param array $payload Data to send
     * @return array Response data
     */
    private function _makeApiRequest($endpoint, $payload) {
        error_log("SC API: Making request to {$endpoint} with payload: " . json_encode($payload));

        static $last_request_time = 0;
        $min_request_interval = 1;

        $current_time = microtime(true);
        $time_since_last = $current_time - $last_request_time;
        if ($time_since_last < $min_request_interval) {
            usleep(($min_request_interval - $time_since_last) * 1000000);
        }
        $last_request_time = microtime(true);

        $url = $this->api_url . $endpoint;
        error_log("SC API: Full request URL: {$url}");

        $args = [
            'body' => json_encode($payload),
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
            'sslverify' => false,
        ];

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            error_log('SC API: Request to ' . $endpoint . ' failed: ' . $response->get_error_message());
            return ['error' => $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        error_log("SC API: Response from {$endpoint} with status code {$status_code}: " . $body);

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('SC API: Invalid JSON response from ' . $endpoint . ': ' . $body);
            return ['error' => 'Invalid JSON response'];
        }

        return $decoded;
    }
    
    /**
     * Build payload for product sync
     *
     * @param string $api_id The API ID for authentication
     * @param string $sync_mode 'F' for full sync, 'P' for partial sync
     * @param array $item_codes Optional list of item codes
     * @param int $page Page number for pagination
     * @param int $rows Number of rows per page
     * @return array Payload data
     */
    private function _buildProductSyncPayload($api_id, $sync_mode, $item_codes, $page, $rows) {
        $payload = [
            'api_id' => $api_id,
            'locale' => 'us',
            'sync_mode' => $sync_mode,
            'page' => max(1, intval($page)),
            'rows' => max(1, min(1000, intval($rows))) // Limit rows to reasonable range
        ];
        
        if ($sync_mode === 'P' && !empty($item_codes)) {
            $payload['item_code_list'] = array_values($item_codes);
        }
        
        return $payload;
    }
    
    /**
     * Parse API response for product sync
     *
     * @param array $response API response
     * @return array Parsed data with items, categories, and pagination info
     */
    private function _parseProductResponse($response) {
        if (!is_array($response)) {
            return [
                'error' => 'Invalid response format',
                'raw_response' => $response,
                'item_catalog' => ['items' => [], 'page_num' => 1, 'page_total' => 1, 'item_total' => 0]
            ];
        }

        if (isset($response['error'])) {
            return [
                'error' => $response['error'],
                'raw_response' => $response,
                'item_catalog' => ['items' => [], 'page_num' => 1, 'page_total' => 1, 'item_total' => 0]
            ];
        }

        $parsed = [
            'item_catalog' => [
                'items' => [],
                'categories' => [],
                'page_num' => 1,
                'page_total' => 1,
                'item_total' => 0
            ]
        ];

        if (isset($response['item_catalog'])) {
            // Extract items
            if (isset($response['item_catalog']['items'])) {
                $parsed['item_catalog']['items'] = $response['item_catalog']['items'];
            } elseif (isset($response['items'])) {
                $parsed['item_catalog']['items'] = $response['items'];
            }

            // Extract categories
            if (isset($response['item_catalog']['categories'])) {
                $parsed['item_catalog']['categories'] = $response['item_catalog']['categories'];
            }

            // Extract pagination info
            $parsed['item_catalog']['page_num'] = isset($response['item_catalog']['page_num']) 
                ? intval($response['item_catalog']['page_num']) : 1;
            $parsed['item_catalog']['page_total'] = isset($response['item_catalog']['page_total']) 
                ? intval($response['item_catalog']['page_total']) : 1;
            $parsed['item_catalog']['item_total'] = isset($response['item_catalog']['item_total']) 
                ? intval($response['item_catalog']['item_total']) : count($parsed['item_catalog']['items']);
        } else {
            error_log('SC API: Unexpected response structure: ' . print_r($response, true));
        }

        return $parsed;
    }
    
    /**
     * Build payload for order processing
     *
     * @param string $api_id The API ID for authentication
     * @param array $customer Customer data
     * @param array $shipping Shipping data
     * @param array $items Order items
     * @param string $note Customer note
     * @return array Payload data
     */
    private function _buildOrderPayload($api_id, $customer, $shipping, $items, $note) {
        // Ensure note is a string, not an array
        if (!is_string($note)) {
            $note = '';
        }
        
        $payload = [
            'api_id' => $api_id,
            'sc_order' => [
                'ord_locale' => 'us',
                'ord_checkoutmode' => 'Existing Terms',
                'ord_requestoremail' => $customer['email'],
                'ord_requestor_firstname' => $customer['first_name'],
                'ord_requestor_lastname' => $customer['last_name'],
                'ord_requestor_address' => $customer['address'],
                'ord_requestor_state' => $customer['state'],
                'ord_requestor_zip' => $customer['zip'],
                'ord_requestor_city' => $customer['city'],
                'ord_same_address_flg' => 0,
                'ord_instructions' => $note, // This is where the customer note should go
                'ord_shipping_address' => $shipping['address'],
                'ord_shipping_state' => $shipping['state'],
                'ord_shipping_zip' => $shipping['zip'],
                'ord_shipping_city' => $shipping['city'],
                'sc_items' => $items
            ]
        ];
        
        // Log payload for debugging
        error_log("SC API: Order payload with customer note: " . json_encode($payload));
        
        return $payload;
    }
    
    /**
     * Extract customer data from WooCommerce order
     *
     * @param WC_Order $order WooCommerce order object
     * @return array Customer data
     */
    private function _extractCustomerData($order) {
        return [
            'email'      => sanitize_email($order->get_billing_email()),
            'first_name' => sanitize_text_field($order->get_billing_first_name()),
            'last_name'  => sanitize_text_field($order->get_billing_last_name()),
            'address'    => sanitize_text_field($order->get_billing_address_1()),
            'state'      => sanitize_text_field($order->get_billing_state()),
            'zip'        => sanitize_text_field($order->get_billing_postcode()),
            'city'       => sanitize_text_field($order->get_billing_city())
        ];
    }
    
    /**
     * Extract shipping data from WooCommerce order
     *
     * @param WC_Order $order WooCommerce order object
     * @return array Shipping data
     */
    private function _extractShippingData($order) {
        return [
            'address' => sanitize_text_field($order->get_shipping_address_1()),
            'state'   => sanitize_text_field($order->get_shipping_state()),
            'zip'     => sanitize_text_field($order->get_shipping_postcode()),
            'city'    => sanitize_text_field($order->get_shipping_city())
        ];
    }
}