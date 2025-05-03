<?php
/* SC_WP_Shop_Order
Submit Order Direct to the Distributors for each of the items
uses: SC_API
*/
class SC_WP_Shop_Order {
    private $api;
    private $distributor_id;
    
    /**
     * Constructor
     * 
     * @param SC_API $api API client instance
     * @param string $distributor_id Optional distributor ID, defaults to value from settings
     * @throws InvalidArgumentException If API or distributor ID is invalid
     */
    public function __construct(SC_API $api, $distributor_id = null) {
        if (!$api instanceof SC_API) {
            throw new InvalidArgumentException('Invalid SC_API instance provided.');
        }
        
        $this->api = $api;
        $this->distributor_id = $distributor_id ?: get_option('sc_distributor_id', 'sc_distributor');
        
        if (empty($this->distributor_id)) {
            throw new InvalidArgumentException('Distributor ID is missing or invalid.');
        }
    }
    
    /**
     * Process an order and submit it to SC API
     * Only sends distributor items to the API
     * 
     * @param int $order_id WooCommerce order ID
     * @return array API response on success
     * @throws InvalidArgumentException If order ID is invalid
     * @throws RuntimeException If order processing fails
     */
    public function orderProcess($order_id) {
        if (!is_numeric($order_id) || $order_id <= 0) {
            throw new InvalidArgumentException("Invalid order ID: {$order_id}");
        }
        
        // Get WooCommerce order
        $order = wc_get_order($order_id);
        if (!$order) {
            $error_message = "Invalid or non-existent order ID: {$order_id}";
            error_log("SC Order Process: {$error_message}");
            throw new RuntimeException($error_message);
        }
        
        // Filter items from this distributor only
        $distributor_items = $this->filterDistributorItems($order);
        
        // If no distributor items, no need to process through API
        if (empty($distributor_items)) {
            $message = "No distributor items found in order {$order_id}";
            error_log("SC Order Process: {$message}");
            $order->add_order_note($message);
            return ['status' => 'skipped', 'message' => $message];
        }
        
        $api_id = get_option('sc_api_id', '');
        if (empty($api_id)) {
            $error_message = "SC API ID is missing for order {$order_id}";
            error_log("SC Order Process: {$error_message}");
            $order->add_order_note($error_message);
            throw new RuntimeException($error_message);
        }
        
        try {
            // Let the API class handle the request with filtered items
            // Make sure the customer note is properly formatted as a string
            $customer_note = $order->get_customer_note();
            if (!is_string($customer_note)) {
                $customer_note = '';
            }
            
            // Add debugging
            error_log('SC Order Process: Customer note before API call: ' . print_r($customer_note, true));
            error_log('SC Order Process: Distributor items before API call: ' . print_r($distributor_items, true));
            
            // The issue might be in how the API class handles the customer note
            // Make sure we're passing it separately and explicitly
            $response = $this->api->orderProcess(
                $api_id, 
                $order, 
                $distributor_items, 
                ['customer_special_instructions' => $customer_note]
            );
            
            // Validate API response
            if (!is_array($response)) {
                $error_message = "Invalid API response format for order {$order_id}";
                error_log("SC Order Process: {$error_message}");
                $order->add_order_note($error_message);
                throw new RuntimeException($error_message);
            }
            
            // Handle API error response
            if (isset($response['error'])) {
                $error_message = 'SC Order Submission Error: ' . esc_html($response['error']);
                error_log($error_message);
                $order->add_order_note($error_message);
                throw new RuntimeException($error_message);
            }
            
            // Log success and update order metadata
            $this->handleSuccessfulOrder($order, $response);
            return $response;
            
        } catch (Exception $e) {
            $error_message = 'SC Order Process Exception: ' . $e->getMessage();
            error_log($error_message);
            $order->add_order_note($error_message);
            throw new RuntimeException($error_message);
        }
    }
    
    /**
     * Filter order items to only include those from the current distributor
     * 
     * @param WC_Order $order WooCommerce order object
     * @return array Filtered order items for the distributor
     * @throws RuntimeException If product data is invalid
     */
    private function filterDistributorItems($order) {
        $distributor_items = [];
        
        // Get all items in the order
        $items = $order->get_items();
        if (empty($items)) {
            return $distributor_items;
        }
        
        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            if (!$product_id) {
                error_log("SC Order Process: Missing product ID for order item in order {$order->get_id()}");
                continue;
            }
            
            $distributor = get_post_meta($product_id, '_distributor_id', true);
            
            // Only include items from this distributor
            if ($distributor === $this->distributor_id) {
                $product = $item->get_product();
                if (!$product) {
                    $error_message = "Invalid product for product ID {$product_id} in order {$order->get_id()}";
                    error_log("SC Order Process: {$error_message}");
                    throw new RuntimeException($error_message);
                }
                
                // Format meta data properly
                $formatted_meta = [];
                $meta_data = $item->get_meta_data();
                if (!empty($meta_data)) {
                    foreach ($meta_data as $meta) {
                        if ($meta instanceof WC_Meta_Data) {
                            $meta_data = $meta->get_data();
                            $formatted_meta[] = [
                                'key' => $meta_data['key'],
                                'value' => $meta_data['value']
                            ];
                        }
                    }
                }
                
                // Format item for API
                $distributor_items[] = [
                    'item_id' => $product_id,
                    'sku' => $product->get_sku() ?: "no-sku-{$product_id}",
                    'name' => $item->get_name() ?: "Unnamed Product {$product_id}",
                    'quantity' => $item->get_quantity(),
                    'price' => $order->get_item_total($item, false, false),
                    'total' => $item->get_total(),
                    'meta_data' => $formatted_meta
                ];
            }
        }
        
        return $distributor_items;
    }
    
    /**
     * Handle a successful order submission
     * 
     * @param WC_Order $order WooCommerce order object
     * @param array $response API response
     * @throws RuntimeException If response data is invalid
     */
    private function handleSuccessfulOrder($order, $response) {
        if (!isset($response['sc_order']) || !is_array($response['sc_order'])) {
            $error_message = "Invalid SC order data in API response for order {$order->get_id()}";
            error_log("SC Order Process: {$error_message}");
            $order->add_order_note($error_message);
            throw new RuntimeException($error_message);
        }
        
        $sc_order_data = $response['sc_order'];
        
        // Store SC order ID if available
        if (isset($sc_order_data['orh_orh_id'])) {
            update_post_meta($order->get_id(), '_sc_order_id', $sc_order_data['orh_orh_id']);
        }
        
        // Store order total from SC
        if (isset($sc_order_data['orh_ordtotal'])) {
            update_post_meta($order->get_id(), '_sc_order_total', $sc_order_data['orh_ordtotal']);
        }
        
        // Store order date from SC
        if (isset($sc_order_data['orh_orddate'])) {
            update_post_meta($order->get_id(), '_sc_order_date', $sc_order_data['orh_orddate']);
        }
        
        // Add order items data - ensure it's properly formatted JSON
        if (isset($sc_order_data['sc_items']) && is_array($sc_order_data['sc_items'])) {
            update_post_meta($order->get_id(), '_sc_order_items', wp_json_encode($sc_order_data['sc_items']));
        }
        
        // Add order note
        $note = 'Distributor items successfully submitted to SC. ';
        if (isset($sc_order_data['orh_orh_id'])) {
            $note .= 'SC Order ID: ' . esc_html($sc_order_data['orh_orh_id']);
        }
        $order->add_order_note($note);
    }
}