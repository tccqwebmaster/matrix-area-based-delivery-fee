<?php
/**
 * Plugin Name: Matrix Area Based Delivery Fee Customizer
 * Plugin URI: https://www.linkedin.com/in/mugamathubathusha/
 * Description: Advanced WooCommerce area-based delivery fee management with Qatar zones, automatic fee calculation, CSV import, drag & drop ordering, and backup/restore functionality.
 * Version: 1.3.0
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

/**
 * Shipping method ID used throughout the plugin.
 */
if (!defined('MATRIX_AREA_DELIVERY_METHOD_ID')) {
    define('MATRIX_AREA_DELIVERY_METHOD_ID', 'matrix_area_delivery');
}

/**
 * Register the shipping method class once WooCommerce shipping is initialised.
 * The class must be defined inside woocommerce_shipping_init because the parent
 * WC_Shipping_Method class is not available before that point.
 */
add_action('woocommerce_shipping_init', function() {

    if (class_exists('Matrix_Area_Delivery_Method')) {
        return;
    }

    /**
     * Area-based delivery shipping method for Qatar.
     *
     * Reads the selected delivery area from the billing_city field and returns the
     * matching fee from the matrix_delivery_areas option as a real shipping rate, so
     * WooCommerce always has a selectable shipping method on checkout.
     */
    class Matrix_Area_Delivery_Method extends WC_Shipping_Method {

        public function __construct($instance_id = 0) {
            $this->id                 = MATRIX_AREA_DELIVERY_METHOD_ID;
            $this->instance_id        = absint($instance_id);
            $this->method_title       = __('Matrix Area Delivery Fee', 'matrix-area-delivery-fee');
            $this->method_description = __('Area-based delivery fee for Qatar. The cost is taken from the delivery area selected at checkout.', 'matrix-area-delivery-fee');

            // Allow the method to be added to shipping zones and configured per instance.
            $this->supports = array(
                'shipping-zones',
                'instance-settings',
                'instance-settings-modal',
            );

            $this->init();

            // Always enabled so the Qatar zone always offers a rate.
            $this->enabled = 'yes';
            $this->title   = $this->get_option('title', __('Delivery Fee', 'matrix-area-delivery-fee'));
        }

        /**
         * Initialise settings and form fields.
         */
        public function init() {
            $this->init_form_fields();
            $this->init_settings();

            add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
        }

        /**
         * Minimal instance settings — just a configurable label.
         */
        public function init_form_fields() {
            $this->instance_form_fields = array(
                'title' => array(
                    'title'       => __('Method title', 'matrix-area-delivery-fee'),
                    'type'        => 'text',
                    'description' => __('Label shown to the customer for this shipping method.', 'matrix-area-delivery-fee'),
                    'default'     => __('Delivery Fee', 'matrix-area-delivery-fee'),
                    'desc_tip'    => true,
                ),
            );
        }

        /**
         * Resolve the fee for the currently selected delivery area and add it as a rate.
         * Always adds a rate (even at cost 0) so WooCommerce sees a valid shipping option.
         *
         * @param array $package Shipping package.
         */
        public function calculate_shipping($package = array()) {

            $billing_city = '';

            // Primary source: the customer session value.
            if (WC()->customer) {
                $billing_city = WC()->customer->get_billing_city();
            }

            // Fallback: the posted value during live checkout AJAX recalculation.
            if (empty($billing_city) && isset($_POST['billing_city'])) {
                $billing_city = wc_clean(wp_unslash($_POST['billing_city']));
            }

            // Also handle the serialised post_data sent by update_order_review.
            if (empty($billing_city) && isset($_POST['post_data'])) {
                parse_str(wp_unslash($_POST['post_data']), $post_data);
                if (!empty($post_data['billing_city'])) {
                    $billing_city = wc_clean($post_data['billing_city']);
                }
            }

            $fee_amount = 0;

            if (!empty($billing_city)) {
                $areas = get_option('matrix_delivery_areas', array());

                if (!empty($areas)) {
                    foreach ($areas as $area) {
                        if (isset($area['value']) && $area['value'] === $billing_city) {
                            $fee_amount = isset($area['fee']) ? floatval($area['fee']) : 0;
                            break;
                        }
                    }
                }
            }

            // Always register a rate so checkout has a selectable shipping method.
            $this->add_rate(array(
                'id'      => $this->id . ($this->instance_id ? '_' . $this->instance_id : ''),
                'label'   => __('Delivery Fee', 'matrix-area-delivery-fee'),
                'cost'    => $fee_amount,
                'package' => $package,
            ));
        }
    }
});

/**
 * Register the shipping method with WooCommerce.
 */
add_filter('woocommerce_shipping_methods', function($methods) {
    $methods[MATRIX_AREA_DELIVERY_METHOD_ID] = 'Matrix_Area_Delivery_Method';
    return $methods;
});

/**
 * Fold the selected delivery area into the shipping package so WooCommerce busts its
 * cached rate (the package hash) whenever billing_city changes. Without this, the fee
 * would stay stale when the customer switches areas at checkout.
 */
add_filter('woocommerce_cart_shipping_packages', function($packages) {

    $billing_city = '';

    if (WC()->customer) {
        $billing_city = WC()->customer->get_billing_city();
    }
    if (empty($billing_city) && isset($_POST['billing_city'])) {
        $billing_city = wc_clean(wp_unslash($_POST['billing_city']));
    }
    if (empty($billing_city) && isset($_POST['post_data'])) {
        parse_str(wp_unslash($_POST['post_data']), $post_data);
        if (!empty($post_data['billing_city'])) {
            $billing_city = wc_clean($post_data['billing_city']);
        }
    }

    if (!empty($billing_city)) {
        foreach ($packages as $key => $package) {
            $packages[$key]['matrix_delivery_area'] = $billing_city;
        }
    }

    return $packages;
});

/**
 * Add the shipping method to the Qatar shipping zone (if present and not already added).
 * Returns true once the method is present in a Qatar zone.
 *
 * @return bool
 */
function matrix_area_delivery_add_to_qatar_zone() {

    if (!class_exists('WC_Shipping_Zones')) {
        return false;
    }

    $target_zone = null;

    foreach (WC_Shipping_Zones::get_zones() as $zone_data) {
        $zone = WC_Shipping_Zones::get_zone($zone_data['id']);
        if (!$zone) {
            continue;
        }

        // Match by zone name containing "Qatar".
        if (stripos($zone->get_zone_name(), 'qatar') !== false) {
            $target_zone = $zone;
            break;
        }

        // Or match by a Qatar (QA) country location on the zone.
        foreach ($zone->get_zone_locations() as $location) {
            if ('country' === $location->type && 'QA' === strtoupper($location->code)) {
                $target_zone = $zone;
                break 2;
            }
        }
    }

    if (!$target_zone) {
        return false;
    }

    // Already added? Then we're done.
    foreach ($target_zone->get_shipping_methods() as $method) {
        if (isset($method->id) && MATRIX_AREA_DELIVERY_METHOD_ID === $method->id) {
            return true;
        }
    }

    $target_zone->add_shipping_method(MATRIX_AREA_DELIVERY_METHOD_ID);
    $target_zone->save();

    return true;
}

/**
 * On activation, flag that the Qatar zone needs the method, and try immediately
 * in case WooCommerce shipping is already loaded.
 */
register_activation_hook(__FILE__, function() {
    update_option('matrix_area_delivery_needs_zone_setup', 'yes');
    matrix_area_delivery_add_to_qatar_zone();
});

/**
 * Retry the zone setup once WooCommerce is fully loaded (covers activation while
 * shipping zones weren't yet available). Clears the flag only once it succeeds.
 */
add_action('woocommerce_init', function() {
    if ('yes' !== get_option('matrix_area_delivery_needs_zone_setup')) {
        return;
    }
    if (matrix_area_delivery_add_to_qatar_zone()) {
        delete_option('matrix_area_delivery_needs_zone_setup');
    }
});

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
