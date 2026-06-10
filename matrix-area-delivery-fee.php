<?php
/**
 * Plugin Name: Matrix Area Based Delivery Fee Customizer
 * Plugin URI: https://www.linkedin.com/in/mugamathubathusha/
 * Description: Advanced WooCommerce area-based delivery fee management with Qatar zones, automatic fee calculation, CSV import, drag & drop ordering, and backup/restore functionality.
 * Version: 1.2.0
 * Author: Mugamathu Bathusha
 * Author URI: https://www.linkedin.com/in/mugamathubathusha/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: matrix-area-delivery-fee
 * Requires at least: 5.0
 * Tested up to: 6.5
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Declare WooCommerce HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Include admin class
if (is_admin()) {
    require_once plugin_dir_path(__FILE__) . 'includes/class-matrix-admin.php';
}

// Check if WooCommerce is active
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    
    /**
     * Modify WooCommerce billing_city field to dropdown with Qatar delivery areas
     * Compatible with WooCommerce Conditional Payments plugin
     * Uses billing_city field (not custom meta) to ensure compatibility
     */
    add_filter('woocommerce_billing_fields', function ($fields) {

        $fields['billing_city']['type'] = 'select';
        $fields['billing_city']['label'] = __('Delivery Area – منطقة التوصيل', 'matrix-area-delivery-fee');
        $fields['billing_city']['required'] = true;
        $fields['billing_city']['class'] = array('form-row-wide');

        // Get delivery areas from database
        $saved_areas = get_option('matrix_delivery_areas');
        
        // Build options array
        $options = array(
            '' => __('Select Delivery Area', 'matrix-area-delivery-fee')
        );
        
        if (!empty($saved_areas)) {
            foreach ($saved_areas as $area) {
                $options[$area['value']] = $area['en'] . ' - ' . $area['ar'];
            }
        }
        
        $fields['billing_city']['options'] = $options;

        return $fields;
    });
    
    /**
     * Add delivery fee based on selected area
     */
    add_action('woocommerce_cart_calculate_fees', function() {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        // Get the selected billing city
        $billing_city = WC()->customer->get_billing_city();
        
        if (empty($billing_city)) {
            return;
        }

        // Get delivery areas with fees
        $areas = get_option('matrix_delivery_areas');
        
        if (empty($areas)) {
            return;
        }

        // Find the fee for the selected area
        $fee_amount = 0;
        foreach ($areas as $area) {
            if ($area['value'] === $billing_city) {
                $fee_amount = isset($area['fee']) ? floatval($area['fee']) : 0;
                break;
            }
        }

        // Add fee if greater than 0
        if ($fee_amount > 0) {
            WC()->cart->add_fee(__('Delivery Fee', 'matrix-area-delivery-fee'), $fee_amount);
        } elseif ($fee_amount == 0) {
            // Show free delivery
            WC()->cart->add_fee(__('Delivery Fee', 'matrix-area-delivery-fee'), 0);
        }
    });

    /**
     * Fix: Re-initialise CyberSource Unified Checkout after WooCommerce checkout updates.
     * When billing_city (delivery area) changes, WC fires updated_checkout which replaces
     * the payment fragment. This script re-triggers payment_method_selected after the DOM
     * settles so the CyberSource widget (Google Pay / Apple Pay / card form) re-mounts.
     */
    add_action('wp_footer', function() {
        if (!is_checkout()) return;
        ?>
        <script>
        (function($) {
            var matrixCsReinit = null;
            $(document.body).on('updated_checkout', function() {
                // Clear any pending reinit
                if (matrixCsReinit) clearTimeout(matrixCsReinit);
                // Wait 400ms for CyberSource widget to finish its own updated_checkout handler,
                // then re-trigger payment_method_selected to force a clean re-mount
                matrixCsReinit = setTimeout(function() {
                    var $paymentInput = $('input[name="payment_method"]:checked');
                    if ($paymentInput.length && $paymentInput.val().indexOf('visa_acceptance') !== -1) {
                        $(document.body).trigger('payment_method_selected');
                    }
                }, 600);
            });
        })(jQuery);
        </script>
        <?php
    });
    
    
} else {
    // Show admin notice if WooCommerce is not active
    add_action('admin_notices', function() {
        echo '<div class="error"><p><strong>Matrix Area Based Delivery Fee Customizer</strong> requires WooCommerce to be installed and active.</p></div>';
    });
}
