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
                'message' => __('API key is not configured', 'pixelfly'),
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
                'message' => __('Connection successful!', 'pixelfly'),
                'response' => $result,
            ];
        }

        return [
            'success' => false,
            'message' => __('Connection failed. Please check your API key and endpoint.', 'pixelfly'),
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
     * Send event to sGTM via GA4 Measurement Protocol
     *
     * @param array $event_data Stored event data (PixelFly format)
     * @return bool Success status
     */
    public function send_event_to_sgtm($event_data)
    {
        $endpoint = get_option('pixelfly_sgtm_endpoint', '');
        $measurement_id = get_option('pixelfly_sgtm_measurement_id', '');
        $api_secret = get_option('pixelfly_sgtm_api_secret', '');

        if (empty($endpoint) || empty($measurement_id) || empty($api_secret)) {
            $this->log_error('sGTM configuration incomplete');
            return false;
        }

        $payload = $this->transform_to_ga4_payload($event_data);

        $url = rtrim($endpoint, '/') . '/mp/collect?measurement_id=' . urlencode($measurement_id) . '&api_secret=' . urlencode($api_secret);

        $response = wp_remote_post($url, [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            $this->log_error('sGTM request failed: ' . $response->get_error_message(), $event_data);
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);

        // Log the event if logging is enabled
        if (get_option('pixelfly_event_logging', false)) {
            $this->log_event($event_data, $response_code, wp_remote_retrieve_body($response));
        }

        // GA4 MP returns 204 on success, sGTM may return 200
        if ($response_code >= 200 && $response_code < 300) {
            $this->debug_log('Event sent to sGTM successfully', [
                'event' => $event_data['event'] ?? 'unknown',
                'transaction_id' => $event_data['transaction_id'] ?? 'unknown',
            ]);
            return true;
        }

        $this->log_error('sGTM returned error: ' . $response_code, [
            'response' => wp_remote_retrieve_body($response),
            'event' => $event_data,
        ]);
        return false;
    }

    /**
     * Fire event using the configured firing method (PixelFly or sGTM)
     *
     * @param array $event_data Event data to send
     * @return bool|array Success status
     */
    public function fire_event($event_data)
    {
        $firing_method = get_option('pixelfly_delayed_firing_method', 'sgtm');

        if ($firing_method === 'sgtm') {
            return $this->send_event_to_sgtm($event_data);
        }

        return $this->send_event($event_data);
    }

    /**
     * Test sGTM connection via GA4 Measurement Protocol
     *
     * @param string|null $endpoint Override endpoint
     * @param string|null $measurement_id Override measurement ID
     * @param string|null $api_secret Override API secret
     * @return array Result with success status and message
     */
    public function test_sgtm_connection($endpoint = null, $measurement_id = null, $api_secret = null)
    {
        $endpoint = $endpoint ?: get_option('pixelfly_sgtm_endpoint', '');
        $measurement_id = $measurement_id ?: get_option('pixelfly_sgtm_measurement_id', '');
        $api_secret = $api_secret ?: get_option('pixelfly_sgtm_api_secret', '');

        if (empty($endpoint) || empty($measurement_id) || empty($api_secret)) {
            return [
                'success' => false,
                'message' => __('sGTM Endpoint, Measurement ID, and API Secret are all required.', 'pixelfly'),
            ];
        }

        $url = rtrim($endpoint, '/') . '/mp/collect?measurement_id=' . urlencode($measurement_id) . '&api_secret=' . urlencode($api_secret);

        $response = wp_remote_post($url, [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'client_id' => 'test_' . time(),
                'events' => [[
                    'name' => 'test_connection',
                    'params' => [
                        'engagement_time_msec' => 1,
                    ],
                ]],
            ]),
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Connection failed: ', 'pixelfly') . $response->get_error_message(),
            ];
        }

        $response_code = wp_remote_retrieve_response_code($response);

        if ($response_code >= 200 && $response_code < 300) {
            return [
                'success' => true,
                'message' => __('Connection successful! sGTM is responding.', 'pixelfly'),
            ];
        }

        return [
            'success' => false,
            'message' => sprintf(__('Connection failed: HTTP %d', 'pixelfly'), $response_code),
        ];
    }

    /**
     * Transform stored event data into GA4 Measurement Protocol format
     *
     * @param array $event_data Stored event data (PixelFly format)
     * @return array GA4 MP payload
     */
    private function transform_to_ga4_payload($event_data)
    {
        $user_data = $event_data['user_data'] ?? [];

        // Derive client_id from fbp cookie or generate one
        $client_id = null;
        if (!empty($user_data['fbp'])) {
            $parts = explode('.', $user_data['fbp']);
            if (count($parts) >= 4) {
                $client_id = $parts[2] . '.' . $parts[3];
            }
        }
        if (!$client_id) {
            $client_id = ($event_data['event_time'] ?? time()) . '.' . crc32($user_data['ph'] ?? $event_data['transaction_id'] ?? uniqid());
        }

        // Build event params
        $event_params = [
            'transaction_id' => $event_data['transaction_id'] ?? '',
            'value' => (float) ($event_data['value'] ?? 0),
            'currency' => $event_data['currency'] ?? get_woocommerce_currency(),
            'engagement_time_msec' => 1,
        ];

        // Add shipping/tax/coupon if present
        if (!empty($event_data['shipping'])) {
            $event_params['shipping'] = (float) $event_data['shipping'];
        }
        if (!empty($event_data['tax'])) {
            $event_params['tax'] = (float) $event_data['tax'];
        }
        if (!empty($event_data['coupon'])) {
            $event_params['coupon'] = $event_data['coupon'];
        }

        // Transform items
        if (!empty($event_data['items'])) {
            $event_params['items'] = array_map(function ($item) {
                return [
                    'item_id' => $item['item_id'] ?? '',
                    'item_name' => $item['item_name'] ?? '',
                    'item_category' => $item['item_category'] ?? '',
                    'item_variant' => $item['item_variant'] ?? '',
                    'price' => (float) ($item['price'] ?? 0),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                ];
            }, $event_data['items']);
        }

        // Add UTM/click ID params if available
        $context = $event_data['context'] ?? [];
        $utm = $context['utm'] ?? [];
        if (!empty($utm)) {
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $key) {
                if (!empty($utm[$key])) {
                    $event_params[$key] = $utm[$key];
                }
            }
            if (!empty($utm['gclid'])) {
                $event_params['gclid'] = $utm['gclid'];
            }
        }

        // Enhanced Conversions user_data — SHA-256 hashed PII
        $enhanced_user_data = [];
        if (!empty($user_data['em'])) {
            $enhanced_user_data['sha256_email_address'] = hash('sha256', strtolower(trim($user_data['em'])));
        }
        if (!empty($user_data['ph'])) {
            $phone = $user_data['ph'];
            if (substr($phone, 0, 1) !== '+') {
                // Add country code based on store country
                $country_code = substr(get_option('woocommerce_default_country', 'BD:'), 0, 2);
                $country_dial = ['BD' => '+880', 'IN' => '+91', 'US' => '+1', 'UK' => '+44'];
                $phone = ($country_dial[$country_code] ?? '+880') . $phone;
            }
            $enhanced_user_data['sha256_phone_number'] = hash('sha256', $phone);
        }
        if (!empty($user_data['fn']) || !empty($user_data['ln'])) {
            $address = [];
            if (!empty($user_data['fn'])) {
                $address['sha256_first_name'] = hash('sha256', strtolower(trim($user_data['fn'])));
            }
            if (!empty($user_data['ln'])) {
                $address['sha256_last_name'] = hash('sha256', strtolower(trim($user_data['ln'])));
            }
            if (!empty($user_data['ct'])) {
                $address['city'] = $user_data['ct'];
            }
            if (!empty($user_data['st'])) {
                $address['region'] = $user_data['st'];
            }
            if (!empty($user_data['zp'])) {
                $address['postal_code'] = $user_data['zp'];
            }
            if (!empty($user_data['country'])) {
                $address['country'] = $user_data['country'];
            }
            $enhanced_user_data['address'] = $address;
        }

        if (!empty($enhanced_user_data)) {
            $event_params['user_data'] = $enhanced_user_data;
        }

        // Build user properties (plain text for GA4 reporting and Facebook CAPI in sGTM)
        $user_properties = [];
        $property_map = [
            'ph' => 'phone',
            'em' => 'email',
            'fn' => 'first_name',
            'ln' => 'last_name',
            'ct' => 'city',
            'st' => 'region',
            'country' => 'country',
        ];
        foreach ($property_map as $data_key => $prop_name) {
            if (!empty($user_data[$data_key])) {
                $user_properties[$prop_name] = ['value' => $user_data[$data_key]];
            }
        }

        $payload = [
            'client_id' => $client_id,
            'events' => [[
                'name' => 'purchase',
                'params' => $event_params,
            ]],
        ];

        // Add user_id (phone number as identifier)
        if (!empty($user_data['ph'])) {
            $payload['user_id'] = $user_data['ph'];
        }

        if (!empty($user_properties)) {
            $payload['user_properties'] = $user_properties;
        }

        return $payload;
    }

    /**
     * Get the firing method label
     *
     * @return string
     */
    public static function get_firing_method_label()
    {
        $method = get_option('pixelfly_delayed_firing_method', 'sgtm');
        return $method === 'sgtm' ? 'sGTM' : 'PixelFly';
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
