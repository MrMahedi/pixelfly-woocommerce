<?php

/**
 * PixelFly User Data Collection
 *
 * Handles collection of user data for enhanced matching (fbp, fbc, etc.)
 */

if (!defined('ABSPATH')) {
    exit;
}

class PixelFly_User_Data
{

    /**
     * Initialize user data handling
     * Sets _fbc cookie from fbclid URL parameter
     */
    public static function init()
    {
        // Only run on frontend, not admin
        if (is_admin()) {
            return;
        }

        // Set _fbc cookie from fbclid if not already set
        self::maybe_set_fbc_cookie();
    }

    /**
     * Set _fbc cookie from fbclid URL parameter
     * Must be called before headers are sent
     */
    public static function maybe_set_fbc_cookie()
    {
        // Check if _fbc cookie already exists
        if (!empty($_COOKIE['_fbc'])) {
            return;
        }

        // Check for fbclid in URL
        $fbclid = isset($_GET['fbclid']) ? sanitize_text_field($_GET['fbclid']) : '';
        if (empty($fbclid)) {
            return;
        }

        // Don't set cookie if headers already sent
        if (headers_sent()) {
            return;
        }

        // Generate _fbc value: fb.{subdomain_index}.{creation_time}.{fbclid}
        $fbc = 'fb.1.' . (time() * 1000) . '.' . $fbclid;

        // Set cookie for 90 days (same as Meta Pixel)
        $expire = time() + (90 * 24 * 60 * 60);
        setcookie('_fbc', $fbc, $expire, '/', '', is_ssl(), false);

        // Also set in $_COOKIE for immediate availability in this request
        $_COOKIE['_fbc'] = $fbc;
    }

    /**
     * Get current user data for tracking
     *
     * @return array User data with available fields
     */
    public static function get_user_data()
    {
        $user_data = [];

        // Facebook cookies
        $user_data['fbp'] = self::get_fbp();
        $user_data['fbc'] = self::get_fbc();

        // Client and session IDs
        $user_data['client_id'] = self::get_client_id();
        $user_data['session_id'] = self::get_session_id();

        // Logged in user data
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $user_data['em'] = strtolower($user->user_email);

            // Get customer data if available
            $customer = new WC_Customer($user->ID);
            if ($customer) {
                $user_data['fn'] = $customer->get_first_name();
                $user_data['ln'] = $customer->get_last_name();
                $user_data['ph'] = preg_replace('/[^0-9]/', '', $customer->get_billing_phone());
                $user_data['ct'] = strtolower($customer->get_billing_city());
                $user_data['st'] = $customer->get_billing_state();
                $user_data['country'] = strtoupper($customer->get_billing_country());
                $user_data['external_id'] = (string) $user->ID;
            }
        }

        // Filter out empty values
        return array_filter($user_data, function ($value) {
            return !empty($value);
        });
    }

    /**
     * Get user data from order
     *
     * @param WC_Order $order
     * @return array User data from order
     */
    public static function get_user_data_from_order($order)
    {
        $user_data = [
            'fn' => $order->get_billing_first_name(),
            'ln' => $order->get_billing_last_name(),
            'em' => strtolower($order->get_billing_email()),
            'ph' => preg_replace('/[^0-9]/', '', $order->get_billing_phone()),
            'ct' => strtolower($order->get_billing_city()),
            'st' => $order->get_billing_state(),
            'zp' => $order->get_billing_postcode(),
            'country' => strtoupper($order->get_billing_country()),
            'external_id' => preg_replace('/[^0-9]/', '', $order->get_billing_phone()),
        ];

        // Add Facebook cookies if available
        $user_data['fbp'] = self::get_fbp();
        $user_data['fbc'] = self::get_fbc();

        // Filter out empty values
        return array_filter($user_data, function ($value) {
            return !empty($value);
        });
    }

    /**
     * Get _fbp cookie value
     */
    public static function get_fbp()
    {
        return isset($_COOKIE['_fbp']) ? sanitize_text_field($_COOKIE['_fbp']) : '';
    }

    /**
     * Get _fbc cookie value or generate from fbclid
     */
    public static function get_fbc()
    {
        if (!empty($_COOKIE['_fbc'])) {
            return sanitize_text_field($_COOKIE['_fbc']);
        }

        // Generate from fbclid if available
        $fbclid = isset($_GET['fbclid']) ? sanitize_text_field($_GET['fbclid']) : '';
        if ($fbclid) {
            return 'fb.1.' . time() * 1000 . '.' . $fbclid;
        }

        return '';
    }

    /**
     * Get Google Analytics client ID
     */
    public static function get_client_id()
    {
        if (!empty($_COOKIE['_ga'])) {
            $ga_cookie = sanitize_text_field($_COOKIE['_ga']);
            // GA cookie format: GA1.2.{client_id}
            $parts = explode('.', $ga_cookie);
            if (count($parts) >= 4) {
                return $parts[2] . '.' . $parts[3];
            }
        }
        return '';
    }

    /**
     * Get session ID
     */
    public static function get_session_id()
    {
        // Try to get from GA4 session cookie
        foreach ($_COOKIE as $name => $value) {
            if (strpos($name, '_ga_') === 0) {
                $parts = explode('.', $value);
                if (count($parts) >= 3) {
                    return $parts[2];
                }
            }
        }
        return '';
    }

    /**
     * Get IP address
     */
    public static function get_client_ip()
    {
        $ip_keys = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field($_SERVER[$key]);
                // Handle comma-separated IPs
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '';
    }

    /**
     * Get user agent
     */
    public static function get_user_agent()
    {
        return isset($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field($_SERVER['HTTP_USER_AGENT'])
            : '';
    }

    /**
     * Get all context data for server-side events
     */
    public static function get_context()
    {
        return [
            'ip' => self::get_client_ip(),
            'user_agent' => self::get_user_agent(),
        ];
    }
}
