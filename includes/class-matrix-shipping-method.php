<?php
/**
 * Shipping Method — the real WooCommerce shipping method that charges the
 * delivery fee for the selected area.
 *
 * This file is required from the `woocommerce_shipping_init` hook only,
 * because WC_Shipping_Method does not exist earlier.
 *
 * @package Matrix_Area_Delivery_Fee
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Matrix_Area_Delivery_Method' ) ) {
	return;
}

/**
 * Area-based delivery shipping method for Qatar.
 *
 * Reads the selected delivery area (carried on the shipping package by
 * Matrix_Delivery_Area, mirroring billing_city) and returns the matching
 * fee as a real shipping rate, so WooCommerce always has a selectable
 * shipping method on checkout.
 */
class Matrix_Area_Delivery_Method extends WC_Shipping_Method {

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Shipping zone instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = MATRIX_AREA_DELIVERY_METHOD_ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Matrix Area Delivery Fee', 'matrix-area-delivery-fee' );
		$this->method_description = __( 'Area-based delivery fee for Qatar. The cost is taken from the delivery area selected at checkout.', 'matrix-area-delivery-fee' );

		// Allow the method to be added to shipping zones and configured per instance.
		$this->supports = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);

		$this->init();

		// Always enabled so the Qatar zone always offers a rate.
		$this->enabled = 'yes';
		$this->title   = $this->get_option( 'title', __( 'Delivery Fee', 'matrix-area-delivery-fee' ) );
	}

	/**
	 * Initialise settings and form fields.
	 */
	public function init() {
		$this->init_form_fields();
		$this->init_settings();

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Minimal instance settings — just a configurable label.
	 */
	public function init_form_fields() {
		$this->instance_form_fields = array(
			'title' => array(
				'title'       => __( 'Method title', 'matrix-area-delivery-fee' ),
				'type'        => 'text',
				'description' => __( 'Label shown to the customer for this shipping method.', 'matrix-area-delivery-fee' ),
				'default'     => __( 'Delivery Fee', 'matrix-area-delivery-fee' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Resolve the fee for the currently selected delivery area and add it
	 * as a rate. Always adds a rate (even at cost 0) so WooCommerce sees a
	 * valid shipping option.
	 *
	 * @param array $package Shipping package.
	 */
	public function calculate_shipping( $package = array() ) {
		$areas = Matrix_Delivery_Area::instance();

		// Preferred source: the area folded into the package by
		// Matrix_Delivery_Area (it is part of the package hash, so rates
		// recalculate the moment it changes). Fallback: live lookup.
		$city = isset( $package['matrix_delivery_area'] )
			? (string) $package['matrix_delivery_area']
			: $areas->get_selected_area();

		$this->add_rate(
			array(
				'id'      => $this->id . ( $this->instance_id ? '_' . $this->instance_id : '' ),
				'label'   => $this->title,
				'cost'    => '' !== $city ? $areas->get_fee( $city ) : 0,
				'package' => $package,
			)
		);
	}
}
