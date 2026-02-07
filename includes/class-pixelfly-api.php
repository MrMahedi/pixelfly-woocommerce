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

        // Detect if this is an sGTM endpoint (subdomain.pixelfly.io that's not 'track')
        // sGTM URLs: https://{uuid}.pixelfly.io or custom domains
        // Proxy URLs: https://track.pixelfly.io/e or custom domains ending in /e or /api/events
        $is_sgtm_endpoint = $this->is_sgtm_endpoint($this->endpoint);

        if ($is_sgtm_endpoint) {
            // For sGTM, test by checking if the server responds
            return $this->test_sgtm_server_reachable();
        }

        // Proxy container: test via /api/events endpoint
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
     * Detect if endpoint is an sGTM container (not proxy)
     *
     * @param string $endpoint The endpoint URL
     * @return bool True if sGTM endpoint
     */
    private function is_sgtm_endpoint($endpoint)
    {
        $parsed = wp_parse_url($endpoint);
        if (!$parsed || empty($parsed['host'])) {
            return false;
        }

        $host = $parsed['host'];
        $path = $parsed['path'] ?? '';

        // Proxy endpoints have /e or /api/events path
        if (preg_match('#^/(e|api/events)/?$#', $path)) {
            return false;
        }

        // track.pixelfly.io is the proxy
        if ($host === 'track.pixelfly.io') {
            return false;
        }

        // *.pixelfly.io subdomains (except track) are sGTM
        if (preg_match('/^[a-f0-9]+\.pixelfly\.io$/i', $host)) {
            return true;
        }

        // Custom domains without /e or /api/events path are likely sGTM
        // This is a heuristic - custom domains pointing to root are sGTM
        if (empty($path) || $path === '/') {
            return true;
        }

        return false;
    }

    /**
     * Test sGTM server reachability (simple GET request)
     *
     * @return array Result with success status and message
     */
    private function test_sgtm_server_reachable()
    {
        // Try to reach the sGTM server's healthz endpoint or root
        $base_url = rtrim($this->endpoint, '/');

        // First try /healthz (common health check endpoint)
        $response = wp_remote_get($base_url . '/healthz', [
            'timeout' => 10,
            'sslverify' => true,
        ]);

        if (!is_wp_error($response)) {
            $code = wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 400) {
                return [
                    'success' => true,
                    'message' => __('sGTM server is reachable! Connection successful.', 'pixelfly'),
                ];
            }
        }

        // Fallback: try root URL
        $response = wp_remote_get($base_url . '/', [
            'timeout' => 10,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Cannot reach sGTM server: ', 'pixelfly') . $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);

        // sGTM returns 200 or 400 for root (no GTM request params)
        // Any response means server is reachable
        if ($code >= 200 && $code < 500) {
            return [
                'success' => true,
                'message' => __('sGTM server is reachable! Connection successful.', 'pixelfly'),
            ];
        }

        return [
            'success' => false,
            'message' => sprintf(
                __('sGTM server returned HTTP %d. Please verify the endpoint URL.', 'pixelfly'),
                $code
            ),
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

        if (empty($endpoint) || empty($measurement_id)) {
            $this->log_error('sGTM configuration incomplete - endpoint and measurement_id required');
            return false;
        }

        // Build /g/collect URL (gtag.js format - works with standard GA4 Client)
        $url = $this->build_g_collect_url($endpoint, $measurement_id, $event_data);

        // Send as POST with empty body (gtag.js style)
        $response = wp_remote_post($url, [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'text/plain',
            ],
            'body' => '',
        ]);

        if (is_wp_error($response)) {
            $this->log_error('sGTM request failed: ' . $response->get_error_message(), $event_data);
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        // Log the event if logging is enabled
        if (get_option('pixelfly_event_logging', false)) {
            $this->log_event($event_data, $response_code, $response_body);
        }

        // GA4/sGTM returns 200 or 204 on success
        if ($response_code >= 200 && $response_code < 300) {
            $this->debug_log('Event sent to sGTM successfully via /g/collect', [
                'event' => $event_data['event'] ?? 'unknown',
                'transaction_id' => $event_data['transaction_id'] ?? 'unknown',
            ]);
            return true;
        }

        $this->log_error('sGTM returned error: ' . $response_code, [
            'response' => $response_body,
            'event' => $event_data,
        ]);
        return false;
    }

    /**
     * Build /g/collect URL with GA4 gtag.js format parameters
     *
     * @param string $endpoint sGTM endpoint URL
     * @param string $measurement_id GA4 Measurement ID (G-XXXXXXX)
     * @param array $event_data Event data
     * @return string Full URL with query parameters
     */
    private function build_g_collect_url($endpoint, $measurement_id, $event_data)
    {
        $user_data = $event_data['user_data'] ?? [];
        $items = $event_data['items'] ?? [];

        // Generate client_id from fbp or create deterministic one
        $client_id = null;
        if (!empty($user_data['fbp'])) {
            $parts = explode('.', $user_data['fbp']);
            if (count($parts) >= 4) {
                $client_id = $parts[2] . '.' . $parts[3];
            }
        }
        if (!$client_id) {
            $random = crc32($user_data['ph'] ?? $event_data['transaction_id'] ?? uniqid());
            $client_id = abs($random) . '.' . ($event_data['event_time'] ?? time());
        }

        // Base parameters (gtag.js format)
        $params = [
            'v' => '2',                          // Protocol version
            'tid' => $measurement_id,             // Measurement ID
            'gtm' => '45je4a',                    // GTM hash (indicates server request)
            'cid' => $client_id,                  // Client ID
            '_p' => mt_rand(100000000, 999999999), // Random cache buster
            'en' => 'purchase',                   // Event name
            '_s' => '1',                          // Session hit count
        ];

        // Event ID for deduplication (critical for Facebook CAPI)
        if (!empty($event_data['event_id'])) {
            $params['ep.event_id'] = $event_data['event_id'];
        }

        // Mark as delayed/server-side event (for sGTM to detect and prioritize server data)
        $params['ep.is_delayed'] = 'true';
        $params['ep.data_source'] = 'server';

        // Action source for Facebook CAPI (website, physical_store, phone_call, chat)
        $params['ep.action_source'] = $event_data['action_source'] ?? 'website';

        // Event source URL for Facebook CAPI
        if (!empty($event_data['event_source_url'])) {
            $params['ep.event_source_url'] = $event_data['event_source_url'];
        }

        // Transaction data
        if (!empty($event_data['transaction_id'])) {
            $params['ep.transaction_id'] = $event_data['transaction_id'];
        }
        if (isset($event_data['value'])) {
            $params['epn.value'] = (float) $event_data['value'];
        }
        if (!empty($event_data['currency'])) {
            $params['ep.currency'] = $event_data['currency'];
        }
        if (!empty($event_data['shipping'])) {
            $params['epn.shipping'] = (float) $event_data['shipping'];
        }
        if (!empty($event_data['tax'])) {
            $params['epn.tax'] = (float) $event_data['tax'];
        }
        if (!empty($event_data['coupon'])) {
            $params['ep.coupon'] = $event_data['coupon'];
        }

        // Facebook-specific parameters (for CAPI tag in sGTM)
        // content_ids - array of product IDs
        $content_ids = $event_data['content_ids'] ?? array_column($items, 'item_id');
        if (!empty($content_ids)) {
            $params['ep.content_ids'] = wp_json_encode($content_ids);
        }

        // content_type - always 'product' for ecommerce
        $params['ep.content_type'] = 'product';

        // num_items - total quantity
        $num_items = 0;
        foreach ($items as $item) {
            $num_items += (int) ($item['quantity'] ?? 1);
        }
        $params['epn.num_items'] = $num_items;

        // content_name - first item name or combined
        if (!empty($items)) {
            $content_names = array_column($items, 'item_name');
            $params['ep.content_name'] = implode(', ', array_filter($content_names));
        }

        // content_category - first item category
        if (!empty($items[0]['item_category'])) {
            $params['ep.content_category'] = $items[0]['item_category'];
        }

        // User data for Enhanced Conversions (as user properties)
        if (!empty($user_data['em'])) {
            $params['up.email'] = $user_data['em'];
        }
        if (!empty($user_data['ph'])) {
            $params['up.phone'] = $user_data['ph'];
        }
        if (!empty($user_data['fn'])) {
            $params['up.first_name'] = $user_data['fn'];
        }
        if (!empty($user_data['ln'])) {
            $params['up.last_name'] = $user_data['ln'];
        }
        if (!empty($user_data['ct'])) {
            $params['up.city'] = $user_data['ct'];
        }
        if (!empty($user_data['st'])) {
            $params['up.region'] = $user_data['st'];
        }
        if (!empty($user_data['country'])) {
            $params['up.country'] = $user_data['country'];
        }
        if (!empty($user_data['zp'])) {
            $params['up.postal_code'] = $user_data['zp'];
        }

        // Facebook click IDs
        if (!empty($user_data['fbp'])) {
            $params['ep.fbp'] = $user_data['fbp'];
        }
        if (!empty($user_data['fbc'])) {
            $params['ep.fbc'] = $user_data['fbc'];
        }

        // External ID for Facebook
        if (!empty($user_data['external_id'])) {
            $params['ep.external_id'] = $user_data['external_id'];
        }

        // Items array (pr1, pr2, etc. - GA4 ecommerce format)
        foreach ($items as $index => $item) {
            $i = $index + 1;
            if (!empty($item['item_id'])) {
                $params["pr{$i}.id"] = $item['item_id'];
            }
            if (!empty($item['item_name'])) {
                $params["pr{$i}.nm"] = $item['item_name'];
            }
            if (!empty($item['item_category'])) {
                $params["pr{$i}.ca"] = $item['item_category'];
            }
            if (isset($item['price'])) {
                $params["pr{$i}.pr"] = (float) $item['price'];
            }
            if (isset($item['quantity'])) {
                $params["pr{$i}.qt"] = (int) $item['quantity'];
            }
        }

        // UTM parameters
        $context = $event_data['context'] ?? [];
        $utm = $context['utm'] ?? [];
        if (!empty($utm['utm_source'])) {
            $params['cs'] = $utm['utm_source'];
        }
        if (!empty($utm['utm_medium'])) {
            $params['cm'] = $utm['utm_medium'];
        }
        if (!empty($utm['utm_campaign'])) {
            $params['cn'] = $utm['utm_campaign'];
        }
        if (!empty($utm['gclid'])) {
            $params['gclid'] = $utm['gclid'];
        }
        if (!empty($utm['fbclid'])) {
            $params['ep.fbclid'] = $utm['fbclid'];
        }
        // TikTok click ID
        if (!empty($utm['ttclid'])) {
            $params['ep.ttclid'] = $utm['ttclid'];
        }
        // LinkedIn click ID
        if (!empty($utm['li_fat_id'])) {
            $params['ep.li_fat_id'] = $utm['li_fat_id'];
        }
        // Snapchat click ID
        if (!empty($utm['sccid'])) {
            $params['ep.sccid'] = $utm['sccid'];
        }
        // Microsoft/Bing click ID
        if (!empty($utm['msclkid'])) {
            $params['ep.msclkid'] = $utm['msclkid'];
        }

        // IP and User Agent for server-side matching
        if (!empty($context['ip'])) {
            $params['ep.ip_override'] = $context['ip'];
        }
        if (!empty($context['user_agent'])) {
            $params['ep.user_agent'] = $context['user_agent'];
        }

        // Build URL
        $base_url = rtrim($endpoint, '/') . '/g/collect';
        return $base_url . '?' . http_build_query($params);
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
     * Test sGTM connection via /g/collect (gtag.js format)
     *
     * @param string|null $endpoint Override endpoint
     * @param string|null $measurement_id Override measurement ID
     * @param string|null $api_secret Override API secret (not used for /g/collect but kept for UI)
     * @return array Result with success status and message
     */
    public function test_sgtm_connection($endpoint = null, $measurement_id = null, $api_secret = null)
    {
        $endpoint = $endpoint ?: get_option('pixelfly_sgtm_endpoint', '');
        $measurement_id = $measurement_id ?: get_option('pixelfly_sgtm_measurement_id', '');

        if (empty($endpoint) || empty($measurement_id)) {
            return [
                'success' => false,
                'message' => __('sGTM Endpoint and Measurement ID are required.', 'pixelfly'),
            ];
        }

        // Build /g/collect test URL (gtag.js format)
        $timestamp = time();
        $random = mt_rand(1000000000, 9999999999);
        $client_id = $random . '.' . $timestamp;

        $params = [
            'v' => '2',
            'tid' => $measurement_id,
            'gtm' => '45je4a',
            'cid' => $client_id,
            '_p' => mt_rand(100000000, 999999999),
            'en' => 'pixelfly_test',
            '_s' => '1',
            'ep.test' => 'connection',
        ];

        $url = rtrim($endpoint, '/') . '/g/collect?' . http_build_query($params);

        $response = wp_remote_post($url, [
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'text/plain',
            ],
            'body' => '',
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Connection failed: ', 'pixelfly') . $response->get_error_message(),
            ];
        }

        $response_code = wp_remote_retrieve_response_code($response);

        // GA4/sGTM returns 200 or 204 on success
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
