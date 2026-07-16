<?php
/**
 * Plugin Name: Matrix Area Based Delivery Fee Customizer
 * Plugin URI: https://www.linkedin.com/in/mugamathubathusha/
 * Description: WooCommerce area-based delivery for Qatar: a real shipping method priced by the Delivery Area (billing_city) dropdown, with CSV import/export, drag & drop ordering, and backup/restore.
 * Version: 2.0.1
 * Author: Mugamathu Bathusha
 * Author URI: https://www.linkedin.com/in/mugamathubathusha/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: matrix-area-delivery-fee
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 10.9
 *
 * Scope (by design): this plugin owns the shipping method, the shipping
 * calculation, the Delivery Area dropdown (billing_city) and how the area
 * is surfaced on orders. All OTHER checkout field customisation (removed
 * fields, required phone, field order, labels) lives in the TCC Qatar
 * Custom plugin — see class-matrix-delivery-area.php for the contract.
 *
 * @package Matrix_Area_Delivery_Fee
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MATRIX_AREA_DELIVERY_VERSION', '2.0.1' );
define( 'MATRIX_AREA_DELIVERY_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Shipping method ID used throughout the plugin.
 */
if ( ! defined( 'MATRIX_AREA_DELIVERY_METHOD_ID' ) ) {
	define( 'MATRIX_AREA_DELIVERY_METHOD_ID', 'matrix_area_delivery' );
}

// Declare WooCommerce HPOS compatibility.
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Boot the plugin once all plugins are loaded (multisite/mu-plugin safe,
 * unlike inspecting the active_plugins option).
 */
add_action( 'plugins_loaded', 'matrix_area_delivery_init' );

function matrix_area_delivery_init() {

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function() {
			echo '<div class="error"><p><strong>Matrix Area Based Delivery Fee Customizer</strong> requires WooCommerce to be installed and active.</p></div>';
		} );
		return;
	}

	require_once MATRIX_AREA_DELIVERY_PATH . 'includes/class-matrix-delivery-area.php';
	Matrix_Delivery_Area::instance();

	if ( is_admin() ) {
		require_once MATRIX_AREA_DELIVERY_PATH . 'includes/class-matrix-admin.php';
	}
}

/**
 * Load the shipping method class once WooCommerce shipping is initialised
 * (the parent WC_Shipping_Method class does not exist before that point).
 */
add_action( 'woocommerce_shipping_init', function() {
	require_once MATRIX_AREA_DELIVERY_PATH . 'includes/class-matrix-shipping-method.php';
} );

/**
 * Register the shipping method with WooCommerce.
 */
add_filter( 'woocommerce_shipping_methods', function( $methods ) {
	$methods[ MATRIX_AREA_DELIVERY_METHOD_ID ] = 'Matrix_Area_Delivery_Method';
	return $methods;
} );

/**
 * Add the shipping method to the Qatar shipping zone (if present and not
 * already added). Returns true once the method is present in a Qatar zone.
 *
 * @return bool
 */
function matrix_area_delivery_add_to_qatar_zone() {

	if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
		return false;
	}

	$target_zone = null;

	foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
		$zone = WC_Shipping_Zones::get_zone( $zone_data['id'] );
		if ( ! $zone ) {
			continue;
		}

		// Match by zone name containing "Qatar".
		if ( stripos( $zone->get_zone_name(), 'qatar' ) !== false ) {
			$target_zone = $zone;
			break;
		}

		// Or match by a Qatar (QA) country location on the zone.
		foreach ( $zone->get_zone_locations() as $location ) {
			if ( 'country' === $location->type && 'QA' === strtoupper( $location->code ) ) {
				$target_zone = $zone;
				break 2;
			}
		}
	}

	if ( ! $target_zone ) {
		return false;
	}

	// Already added? Then we're done.
	foreach ( $target_zone->get_shipping_methods() as $method ) {
		if ( isset( $method->id ) && MATRIX_AREA_DELIVERY_METHOD_ID === $method->id ) {
			return true;
		}
	}

	$target_zone->add_shipping_method( MATRIX_AREA_DELIVERY_METHOD_ID );
	$target_zone->save();

	return true;
}

/**
 * On activation, flag that the Qatar zone needs the method, and try
 * immediately in case WooCommerce shipping is already loaded.
 */
register_activation_hook( __FILE__, function() {
	update_option( 'matrix_area_delivery_needs_zone_setup', 'yes' );
	matrix_area_delivery_add_to_qatar_zone();
} );

/**
 * Retry the zone setup once WooCommerce is fully loaded (covers activation
 * while shipping zones weren't yet available). Clears the flag only once
 * it succeeds.
 */
add_action( 'woocommerce_init', function() {
	if ( 'yes' !== get_option( 'matrix_area_delivery_needs_zone_setup' ) ) {
		return;
	}
	if ( matrix_area_delivery_add_to_qatar_zone() ) {
		delete_option( 'matrix_area_delivery_needs_zone_setup' );
	}
} );
