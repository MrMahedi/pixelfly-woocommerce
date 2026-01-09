<?php

/**
 * PixelFly API Client
 *
 * Handles communication with PixelFly tracking endpoint
 */

if (!defined('ABSPATH')) {
    exit;
}

class PixelFly_API
{

    /**
     * API endpoint
     */
    private $endpoint;

    /**
     * API key
     */
    private $api_key;

    /**
     * Debug mode
     */
    private $debug;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->endpoint = get_option('pixelfly_endpoint', 'https://track.pixelfly.io/e');
        $this->api_key = get_option('pixelfly_api_key', '');
        $this->debug = get_option('pixelfly_debug_mode', false);
    }

    /**
     * Send event to PixelFly
     *
     * @param array $event_data Event data to send
     * @return bool|array Success status or response data
     */
    public function send_event($event_data)
    {
        if (empty($this->api_key)) {
            $this->log_error('API key not configured');
            return false;
        }

        $response = wp_remote_post($this->endpoint, [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-PF-Key' => $this->api_key,
            ],
            'body' => wp_json_encode($event_data),
        ]);

        if (is_wp_error($response)) {
            $this->log_error('API request failed: ' . $response->get_error_message(), $event_data);
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        // Log the event if logging is enabled
        if (get_option('pixelfly_event_logging', false)) {
            $this->log_event($event_data, $response_code, $response_body);
        }

        if ($response_code >= 200 && $response_code < 300) {
            $this->debug_log('Event sent successfully', [
                'event' => $event_data['event'] ?? 'unknown',
                'event_id' => $event_data['event_id'] ?? 'unknown',
            ]);
            return json_decode($response_body, true);
        }

        $this->log_error('API returned error: ' . $response_code, [
            'response' => $response_body,
            'event' => $event_data,
        ]);
        return false;
    }

    /**
     * Test API connection
     *
     * @return array Result with success status and message
     */
    public function test_connection()
    {
        if (empty($this->api_key)) {
            return [
                'success' => false,
                'message' => __('API key is not configured', 'pixelfly-woocommerce'),
            ];
        }

        $test_event = [
            'event' => 'test_connection',
            'event_id' => 'test_' . time(),
            'value' => 0,
            'currency' => get_woocommerce_currency(),
        ];

        $result = $this->send_event($test_event);

        if ($result) {
            return [
                'success' => true,
                'message' => __('Connection successful!', 'pixelfly-woocommerce'),
                'response' => $result,
            ];
        }

        return [
            'success' => false,
            'message' => __('Connection failed. Please check your API key and endpoint.', 'pixelfly-woocommerce'),
        ];
    }

    /**
     * Log event to database
     */
    private function log_event($event_data, $response_code, $response_body)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pixelfly_event_log';

        $wpdb->insert($table_name, [
            'event_type' => $event_data['event'] ?? 'unknown',
            'event_id' => $event_data['event_id'] ?? '',
            'order_id' => $event_data['transaction_id'] ?? null,
            'response_code' => $response_code,
            'response_body' => $response_body,
            'created_at' => current_time('mysql'),
        ]);
    }

    /**
     * Log error
     */
    private function log_error($message, $context = [])
    {
        if ($this->debug || WP_DEBUG) {
            error_log('[PixelFly] Error: ' . $message . ' | Context: ' . wp_json_encode($context));
        }
    }

    /**
     * Debug log
     */
    private function debug_log($message, $context = [])
    {
        if ($this->debug) {
            error_log('[PixelFly] Debug: ' . $message . ' | Context: ' . wp_json_encode($context));
        }
    }
}
