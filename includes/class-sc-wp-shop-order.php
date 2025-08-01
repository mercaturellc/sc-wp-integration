<?php

/**
 * SC_WP_Shop_Order - Simplified order processing
 * Focuses on reliable order submission to distributor API
 */
class SC_WP_Shop_Order {
    private $api;
    
    public function __construct(SC_API $api) {
        if (!$api instanceof SC_API) {
            throw new InvalidArgumentException('Valid SC_API instance required');
        }
        $this->api = $api;
    }
    
    /**
     * Process an order and submit to distributor API
     * 
     * @param int $order_id WooCommerce order ID
     * @return array Processing result
     */
    public function orderProcess($order_id) {
        // Validate order ID
        if (!is_numeric($order_id) || $order_id <= 0) {
            throw new InvalidArgumentException("Invalid order ID: {$order_id}");
        }
        
        // Get WooCommerce order
        $order = wc_get_order($order_id);
        if (!$order) {
            $message = "Order not found: {$order_id}";
            error_log("SC Order: {$message}");
            throw new RuntimeException($message);
        }
        
        // Check if already processed
        $existing_sc_order_id = get_post_meta($order_id, '_sc_order_id', true);
        if (!empty($existing_sc_order_id)) {
            $message = "Order {$order_id} already processed (SC Order ID: {$existing_sc_order_id})";
            error_log("SC Order: {$message}");
            return ['status' => 'already_processed', 'message' => $message, 'sc_order_id' => $existing_sc_order_id];
        }
        
        // Get order items
        $items = $this->extractOrderItems($order);
        
        if (empty($items)) {
            $message = "No valid items found in order {$order_id}";
            error_log("SC Order: {$message}");
            $order->add_order_note($message);
            return ['status' => 'no_items', 'message' => $message];
        }
        
        // Get API ID
        $api_id = get_option('sc_api_id', '');
        if (empty($api_id)) {
            $message = "API ID not configured for order {$order_id}";
            error_log("SC Order: {$message}");
            $order->add_order_note($message);
            throw new RuntimeException($message);
        }
        
        try {
            error_log("SC Order: Processing order {$order_id} with " . count($items) . " items");
            
            // Submit to API
            $response = $this->api->orderProcess($api_id, $order, $items, [
                'customer_special_instructions' => $order->get_customer_note()
            ]);
            
            // Handle API response
            if (isset($response['error'])) {
                $error_message = 'API Error: ' . $response['error'];
                error_log("SC Order: {$error_message} for order {$order_id}");
                $order->add_order_note($error_message);
                throw new RuntimeException($error_message);
            }
            
            // Process successful response
            $this->handleSuccessfulSubmission($order, $response);
            
            error_log("SC Order: Successfully processed order {$order_id}");
            return ['status' => 'success', 'message' => 'Order submitted successfully', 'response' => $response];
            
        } catch (Exception $e) {
            $error_message = 'Order processing failed: ' . $e->getMessage();
            error_log("SC Order: {$error_message} for order {$order_id}");
            $order->add_order_note($error_message);
            throw new RuntimeException($error_message);
        }
    }
    
    /**
     * Extract and format order items for API submission
     */
    private function extractOrderItems($order) {
        $items = [];
        
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if (!$product) {
                error_log("SC Order: Invalid product for item {$item_id} in order {$order->get_id()}");
                continue;
            }
            
            $sku = $product->get_sku();
            if (empty($sku)) {
                error_log("SC Order: No SKU for product {$product->get_id()} in order {$order->get_id()}");
                continue;
            }
            
            // Format item for API
            $items[] = [
                'item_id' => $product->get_id(),
                'sku' => $sku,
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'price' => $order->get_item_total($item, false, false),
                'total' => $item->get_total(),
                'meta_data' => $this->formatItemMetaData($item)
            ];
        }
        
        return $items;
    }
    
    /**
     * Format item meta data for API
     */
    private function formatItemMetaData($item) {
        $formatted_meta = [];
        $meta_data = $item->get_meta_data();
        
        foreach ($meta_data as $meta) {
            if ($meta instanceof WC_Meta_Data) {
                $data = $meta->get_data();
                $formatted_meta[] = [
                    'key' => $data['key'],
                    'value' => $data['value']
                ];
            }
        }
        
        return $formatted_meta;
    }
    
    /**
     * Handle successful API submission
     */
    private function handleSuccessfulSubmission($order, $response) {
        if (!isset($response['sc_order']) || !is_array($response['sc_order'])) {
            error_log("SC Order: Invalid response structure for order {$order->get_id()}");
            return;
        }
        
        $sc_order = $response['sc_order'];
        $order_id = $order->get_id();
        
        // Store SC order ID
        if (isset($sc_order['orh_orh_id'])) {
            update_post_meta($order_id, '_sc_order_id', sanitize_text_field($sc_order['orh_orh_id']));
            $sc_order_id = $sc_order['orh_orh_id'];
        } else {
            $sc_order_id = 'Unknown';
        }
        
        // Store additional SC order data
        if (isset($sc_order['orh_ordtotal'])) {
            update_post_meta($order_id, '_sc_order_total', floatval($sc_order['orh_ordtotal']));
        }
        
        if (isset($sc_order['orh_orddate'])) {
            update_post_meta($order_id, '_sc_order_date', sanitize_text_field($sc_order['orh_orddate']));
        }
        
        // Store order items data
        if (isset($sc_order['sc_items']) && is_array($sc_order['sc_items'])) {
            update_post_meta($order_id, '_sc_order_items', wp_json_encode($sc_order['sc_items']));
        }
        
        // Store full response for debugging
        update_post_meta($order_id, '_sc_order_response', wp_json_encode($response));
        
        // Add success note to order
        $note = "Order successfully submitted to distributor (SC Order ID: {$sc_order_id})";
        $order->add_order_note($note);
        
        error_log("SC Order: Stored order data for WC order {$order_id}, SC order {$sc_order_id}");
    }
    
    /**
     * Check if an order has been processed
     */
    public function isOrderProcessed($order_id) {
        $sc_order_id = get_post_meta($order_id, '_sc_order_id', true);
        return !empty($sc_order_id);
    }
    
    /**
     * Get SC order ID for a WooCommerce order
     */
    public function getScOrderId($order_id) {
        return get_post_meta($order_id, '_sc_order_id', true);
    }
    
    /**
     * Get SC order data for a WooCommerce order
     */
    public function getScOrderData($order_id) {
        return [
            'sc_order_id' => get_post_meta($order_id, '_sc_order_id', true),
            'sc_order_total' => get_post_meta($order_id, '_sc_order_total', true),
            'sc_order_date' => get_post_meta($order_id, '_sc_order_date', true),
            'sc_order_items' => json_decode(get_post_meta($order_id, '_sc_order_items', true), true),
            'sc_order_response' => json_decode(get_post_meta($order_id, '_sc_order_response', true), true)
        ];
    }
}