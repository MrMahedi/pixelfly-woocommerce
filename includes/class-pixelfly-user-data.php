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
                $user_data['zp'] = $customer->get_billing_postcode();
                $user_data['country'] = strtoupper($customer->get_billing_country());
                // external_id must be the SAME value on every event for a shopper,
                // otherwise Meta treats browsing and buying as two different people.
                // The normalized phone is the only identifier guests have too, so it
                // is the one identity used everywhere. See get_external_id().
                $user_data['external_id'] = self::get_external_id(
                    $customer->get_billing_phone(),
                    $customer->get_billing_country()
                );
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
            'external_id' => self::get_external_id(
                $order->get_billing_phone(),
                $order->get_billing_country()
            ),
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
     * ISO 3166-1 alpha-2 -> E.164 country calling code.
     * Mirrors DIAL_CODES in the Worker (pixelflycloudflare/src/utils/hash.ts) so a
     * number hashed here and a number hashed at the edge produce the same digest.
     */
    const DIAL_CODES = [
        'bd' => '880', 'in' => '91', 'pk' => '92', 'lk' => '94', 'np' => '977', 'mv' => '960',
        'gb' => '44', 'ie' => '353', 'us' => '1', 'ca' => '1', 'au' => '61', 'nz' => '64',
        'ae' => '971', 'sa' => '966', 'qa' => '974', 'kw' => '965', 'om' => '968', 'bh' => '973',
        'my' => '60', 'sg' => '65', 'id' => '62', 'th' => '66', 'ph' => '63', 'vn' => '84',
        'nl' => '31', 'de' => '49', 'fr' => '33', 'it' => '39', 'es' => '34', 'se' => '46', 'no' => '47',
    ];

    /**
     * Only a real 2-letter ISO code is a usable hint. "UK" is accepted as an alias
     * for GB because WooCommerce stores sometimes carry it.
     *
     * @param string $country
     * @return string lowercase ISO code, or '' when there is no usable hint
     */
    public static function iso_hint($country)
    {
        $iso = strtolower(trim((string) $country));
        if ($iso === 'uk') {
            $iso = 'gb';
        }

        return preg_match('/^[a-z]{2}$/', $iso) ? $iso : '';
    }

    /**
     * Normalize a phone to digits-only E.164 (no leading +).
     *
     * A single leading "0" is a national trunk prefix: it must be REPLACED by the
     * country calling code, never prefixed with it. '880' . '01712345678' yields
     * 88001712345678, a number that does not exist and matches nobody.
     *
     * @param string $phone
     * @param string $country optional ISO 3166-1 alpha-2 hint
     * @return string
     */
    public static function normalize_phone($phone, $country = '')
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone);
        if ($digits === '') {
            return '';
        }

        // "00" is the international call prefix; what follows already carries a code.
        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }

        $iso = self::iso_hint($country);
        $dial = $iso !== '' ? (self::DIAL_CODES[$iso] ?? '') : '';

        if (strpos($digits, '0') === 0) {
            if ($iso !== '') {
                // The merchant told us a country. Use it, or leave the number alone
                // if we do not know its dialling code — never fall back to BD here.
                return $dial !== '' ? $dial . substr($digits, 1) : $digits;
            }
            // No country at all. Assume Bangladesh for the 01XXXXXXXXX mobile shape
            // only, and leave every other shape untouched.
            if (strlen($digits) === 11 && strpos($digits, '01') === 0) {
                return '880' . substr($digits, 1);
            }

            return $digits;
        }

        // Bare 10-digit national number with no trunk zero. Requires an explicit BD
        // country — assuming it would mangle a 10-digit US number. Orders always
        // carry a billing country, so real BD orders still normalize.
        if (strlen($digits) === 10 && $iso === 'bd') {
            return '880' . $digits;
        }

        return $digits;
    }

    /**
     * Full E.164 with a leading + (what Google Enhanced Conversions expects).
     * Adds the country calling code to a bare national number, and never adds it
     * twice to a number that already carries it.
     *
     * @param string $phone
     * @param string $country optional ISO 3166-1 alpha-2 hint
     * @return string
     */
    public static function to_e164($phone, $country = '')
    {
        $digits = self::normalize_phone($phone, $country);
        if ($digits === '') {
            return '';
        }

        $iso = self::iso_hint($country);
        $dial = $iso !== '' ? (self::DIAL_CODES[$iso] ?? '') : '';

        if ($dial !== '' && strpos($digits, $dial) !== 0) {
            $digits = $dial . $digits;
        }

        return '+' . $digits;
    }

    /**
     * The single identifier used as external_id everywhere.
     *
     * Meta uses external_id to stitch a shopper's events together, so it has to be
     * byte-identical on every event for that person. Previously browsing sent the
     * WordPress account number while the order sent raw phone digits, so one shopper
     * looked like two people and the browse-to-buy funnel never joined.
     *
     * The normalized phone is used because guests have one too — a WordPress account
     * number only exists for logged-in customers, which is a minority of COD traffic.
     *
     * @param string $phone
     * @param string $country ISO 3166-1 alpha-2 hint
     * @return string E.164 digits, or '' when there is no usable phone
     */
    public static function get_external_id($phone, $country = '')
    {
        $normalized = self::normalize_phone($phone, $country);

        // Too short to be a real number - better no identifier than a wrong one.
        return strlen($normalized) >= 8 ? $normalized : '';
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
            return 'fb.1.' . (time() * 1000) . '.' . $fbclid;
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

    /**
     * Check if user data collection is enabled
     *
     * @return bool
     */
    public static function is_user_data_enabled()
    {
        // Check if WooCommerce is active and customer exists
        if (!function_exists('WC') || !WC()->customer instanceof WC_Customer) {
            return false;
        }

        // Check plugin setting (default to enabled)
        return (bool) get_option('pixelfly_user_data_enabled', true);
    }
}
