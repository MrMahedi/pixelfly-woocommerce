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
        // Add hidden fields to checkout
        add_action('woocommerce_after_order_notes', [$this, 'add_utm_fields']);

        // Save UTM to order meta
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_utm_to_order']);

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
     * Save UTM to order meta
     */
    public function save_utm_to_order($order_id)
    {
        foreach ($this->utm_fields as $field) {
            $value = '';

            // Check POST data
            if (isset($_POST[$field]) && !empty($_POST[$field])) {
                $value = sanitize_text_field($_POST[$field]);
            }
            // Fallback to session
            elseif (isset($_SESSION['pixelfly_' . $field])) {
                $value = $_SESSION['pixelfly_' . $field];
            }

            if ($value) {
                update_post_meta($order_id, '_' . $field, $value);
            }
        }

        // Also save Facebook cookies
        if (isset($_COOKIE['_fbp'])) {
            update_post_meta($order_id, '_fbp', sanitize_text_field($_COOKIE['_fbp']));
        }
        if (isset($_COOKIE['_fbc'])) {
            update_post_meta($order_id, '_fbc', sanitize_text_field($_COOKIE['_fbc']));
        }
    }

    /**
     * Enqueue UTM capture script
     */
    public function enqueue_utm_script()
    {
        if (!is_checkout()) {
            return;
        }

        // Inline script to populate hidden fields from URL/sessionStorage
        $script = '
            (function() {
                var urlParams = new URLSearchParams(window.location.search);
                var utmFields = ' . wp_json_encode($this->utm_fields) . ';

                utmFields.forEach(function(field) {
                    var value = urlParams.get(field) || sessionStorage.getItem("pf_" + field) || "";
                    var input = document.getElementById("pf_" + field);
                    if (input && value) {
                        input.value = value;
                    }
                    // Persist in session storage for SPA navigations
                    if (value) {
                        sessionStorage.setItem("pf_" + field, value);
                    }
                });

                // Also persist UTM from first landing
                window.addEventListener("load", function() {
                    utmFields.forEach(function(field) {
                        var value = urlParams.get(field);
                        if (value) {
                            sessionStorage.setItem("pf_" + field, value);
                        }
                    });
                });
            })();
        ';

        wp_add_inline_script('pixelfly-tracker', $script);
    }
}
