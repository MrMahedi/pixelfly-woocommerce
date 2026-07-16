<?php

/**
 * PixelFly UTM Capture
 *
 * Captures UTM parameters and click IDs at checkout
 */

if (!defined('ABSPATH')) {
    exit;
}

class PixelFly_UTM_Capture
{

    /**
     * UTM and click ID fields to capture
     */
    private $utm_fields = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'fbclid',
        'gclid',
        'ttclid',
        'msclkid',
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        // Add hidden fields to checkout (classic / shortcode checkout only)
        add_action('woocommerce_after_order_notes', [$this, 'add_utm_fields']);

        // Save UTM to order meta — classic (shortcode) checkout passes an order id
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_utm_classic_checkout'], 10, 1);

        // Save UTM to order meta — block / Store API checkout passes an order object
        add_action('woocommerce_store_api_checkout_update_order_meta', [$this, 'save_utm_to_order'], 10, 1);

        // Enqueue UTM capture script
        add_action('wp_enqueue_scripts', [$this, 'enqueue_utm_script']);

        // Store UTM on session start
        add_action('init', [$this, 'capture_utm_to_session']);
    }

    /**
     * Capture UTM parameters to session on page load
     */
    public function capture_utm_to_session()
    {
        if (is_admin()) {
            return;
        }

        // Start session if not started
        if (!session_id() && !headers_sent()) {
            session_start();
        }

        foreach ($this->utm_fields as $field) {
            if (isset($_GET[$field]) && !empty($_GET[$field])) {
                $_SESSION['pixelfly_' . $field] = sanitize_text_field($_GET[$field]);
            }
        }
    }

    /**
     * Add hidden UTM fields to checkout
     */
    public function add_utm_fields($checkout)
    {
        echo '<div id="pixelfly-utm-fields" style="display:none;">';

        foreach ($this->utm_fields as $field) {
            $value = '';

            // Try session first
            if (isset($_SESSION['pixelfly_' . $field])) {
                $value = $_SESSION['pixelfly_' . $field];
            }
            // Then URL params
            elseif (isset($_GET[$field])) {
                $value = sanitize_text_field($_GET[$field]);
            }

            echo '<input type="hidden" name="' . esc_attr($field) . '" id="pf_' . esc_attr($field) . '" value="' . esc_attr($value) . '">';
        }

        echo '</div>';
    }

    /**
     * Classic (shortcode) checkout callback. This hook passes an order id, so we
     * resolve it to an order object and reuse the shared save routine.
     *
     * @param int $order_id
     */
    public function save_utm_classic_checkout($order_id)
    {
        $order = wc_get_order($order_id);

        if ($order) {
            $this->save_utm_to_order($order);
        }
    }

    /**
     * Save UTM to order meta.
     *
     * Uses the order CRUD API (update_meta_data + save) so it works with HPOS
     * (custom order tables) as well as legacy post-based storage. Reads values
     * from POST (classic checkout hidden fields) first, then from a first-party
     * cookie (works for block/Store API checkout, which submits no form fields),
     * then from the PHP session as a last resort.
     *
     * @param WC_Order|int $order Order object (block checkout) or id (safety).
     */
    public function save_utm_to_order($order)
    {
        if (is_numeric($order)) {
            $order = wc_get_order($order);
        }

        if (!$order instanceof WC_Order) {
            return;
        }

        $dirty = false;

        foreach ($this->utm_fields as $field) {
            $value = '';

            // Classic checkout submits these as hidden POST fields.
            if (isset($_POST[$field]) && $_POST[$field] !== '') {
                $value = sanitize_text_field(wp_unslash($_POST[$field]));
            }
            // Block checkout submits no form fields, so fall back to the cookie.
            elseif (isset($_COOKIE['pixelfly_' . $field]) && $_COOKIE['pixelfly_' . $field] !== '') {
                $value = sanitize_text_field(wp_unslash($_COOKIE['pixelfly_' . $field]));
            }
            // Legacy PHP-session fallback.
            elseif (isset($_SESSION['pixelfly_' . $field]) && $_SESSION['pixelfly_' . $field] !== '') {
                $value = sanitize_text_field($_SESSION['pixelfly_' . $field]);
            }

            // Last resort for Google Ads: recover the gclid from Google's own
            // first-party cookies (_gcl_aw / _gcl_dc) that gtag.js writes. This
            // covers shoppers whose click id never reached our own cookie/session.
            if ($value === '' && $field === 'gclid') {
                $value = $this->gclid_from_google_cookie();
            }

            if ($value !== '') {
                $order->update_meta_data('_' . $field, $value);
                $dirty = true;
            }
        }

        // Also save Facebook cookies.
        if (isset($_COOKIE['_fbp'])) {
            $order->update_meta_data('_fbp', sanitize_text_field(wp_unslash($_COOKIE['_fbp'])));
            $dirty = true;
        }
        if (isset($_COOKIE['_fbc'])) {
            $order->update_meta_data('_fbc', sanitize_text_field(wp_unslash($_COOKIE['_fbc'])));
            $dirty = true;
        }

        if ($dirty) {
            $order->save();
        }
    }

    /**
     * Extract a gclid from Google's own first-party cookies.
     *
     * gtag.js stores the click id in _gcl_aw (Search) and _gcl_dc (Display) in the
     * form "GCL.<timestamp>.<gclid>". We prefer _gcl_aw and fall back to _gcl_dc.
     *
     * @return string The gclid, or '' if none is present.
     */
    private function gclid_from_google_cookie()
    {
        foreach (['_gcl_aw', '_gcl_dc'] as $cookie) {
            if (empty($_COOKIE[$cookie])) {
                continue;
            }

            $parts = explode('.', sanitize_text_field(wp_unslash($_COOKIE[$cookie])));

            // Expect GCL.<timestamp>.<gclid>; the gclid is everything after the 2nd dot.
            if (count($parts) >= 3) {
                return implode('.', array_slice($parts, 2));
            }
        }

        return '';
    }

    /**
     * Enqueue UTM capture script
     */
    public function enqueue_utm_script()
    {
        // Runs on every frontend page (not just checkout) so a gclid/UTM present only
        // on the Google Ads landing URL is persisted to a cookie immediately, instead
        // of being lost by the time the shopper reaches checkout.

        // Inline script to populate hidden fields and persist UTM to a cookie so the
        // server can read it at checkout — including block checkout, which submits no
        // form fields. The cookie is what save_utm_to_order() reads as its fallback.
        $script = '
            (function() {
                var urlParams = new URLSearchParams(window.location.search);
                var utmFields = ' . wp_json_encode($this->utm_fields) . ';

                function setPfCookie(field, value) {
                    // 30-day first-party cookie, readable by PHP as pixelfly_<field>.
                    document.cookie = "pixelfly_" + field + "=" + encodeURIComponent(value) +
                        "; path=/; max-age=" + (60 * 60 * 24 * 30) + "; SameSite=Lax";
                }

                utmFields.forEach(function(field) {
                    var value = urlParams.get(field) || sessionStorage.getItem("pf_" + field) || "";
                    var input = document.getElementById("pf_" + field);
                    if (input && value) {
                        input.value = value;
                    }
                    // Persist for SPA navigations and for server-side reads at checkout.
                    if (value) {
                        sessionStorage.setItem("pf_" + field, value);
                        setPfCookie(field, value);
                    }
                });

                // Also persist UTM from first landing.
                window.addEventListener("load", function() {
                    utmFields.forEach(function(field) {
                        var value = urlParams.get(field);
                        if (value) {
                            sessionStorage.setItem("pf_" + field, value);
                            setPfCookie(field, value);
                        }
                    });
                });
            })();
        ';

        wp_add_inline_script('pixelfly-tracker', $script);
    }
}
