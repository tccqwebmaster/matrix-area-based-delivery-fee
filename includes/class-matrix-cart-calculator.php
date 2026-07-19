<?php
/**
 * Cart "Calculate shipping" — make it work with delivery areas.
 *
 * What this fixes on the cart page:
 *
 * 1. The calculator's city field was a free-text input, so a customer had to
 *    know the exact spelling of an area to get a fee — and a typo silently
 *    produced free shipping. It is now the same Delivery Area dropdown used at
 *    checkout (filtered on the form-field args, not by overriding
 *    WooCommerce's template).
 *
 * 2. The fee only refreshed after clicking "Update totals". Choosing an area
 *    now submits the calculator immediately.
 *
 * The fee lookup itself already worked: this store runs WooCommerce's
 * "force shipping to the customer billing address" setting, so the calculator
 * writes billing_city — which is what the delivery fee is keyed on. Verified
 * on staging as a guest: picking Bani Hajer returned 25 QR.
 * `mirror_area_to_billing()` below is the guard for the OTHER setting, where
 * the calculator would write only the shipping address.
 *
 * @package Matrix_Area_Delivery_Fee
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delivery Area support for the cart shipping calculator.
 */
class Matrix_Cart_Calculator {

	/**
	 * Singleton instance.
	 *
	 * @var Matrix_Cart_Calculator|null
	 */
	private static $instance = null;

	/**
	 * Get (or build) the singleton.
	 *
	 * @return Matrix_Cart_Calculator
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks (once — singleton guarantees no duplicate filters).
	 */
	private function __construct() {
		add_filter( 'woocommerce_form_field_args', array( $this, 'city_field_to_dropdown' ), 20, 3 );
		add_action( 'woocommerce_calculated_shipping', array( $this, 'mirror_area_to_billing' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_auto_update' ), 30 );
	}

	/**
	 * Turn the calculator's city field into the Delivery Area dropdown.
	 *
	 * WooCommerce builds this field with `woocommerce_form_field()`, so the
	 * args filter is enough — no template override (and no fight with themes
	 * that ship their own cart templates).
	 *
	 * @param array  $args  Field args.
	 * @param string $key   Field key.
	 * @param mixed  $value Current value.
	 * @return array
	 */
	public function city_field_to_dropdown( $args, $key, $value = null ) {
		if ( 'calc_shipping_city' !== $key ) {
			return $args;
		}

		$areas = Matrix_Delivery_Area::instance();

		$args['type']    = 'select';
		$args['options'] = $areas->get_options();

		return $args;
	}

	/**
	 * Mirror the area chosen in the calculator onto billing_city.
	 *
	 * The delivery fee and the checkout dropdown are keyed on billing_city.
	 * With "force shipping to the customer billing address" enabled (this
	 * store's setting) WooCommerce already writes billing_city and this does
	 * nothing. If that setting is ever turned off, the calculator would write
	 * the shipping address only and the fee would come out free — this keeps
	 * one source of truth either way, and means checkout opens with the area
	 * the customer picked in the cart.
	 *
	 * Only configured areas are copied — a stale or hand-posted value must not
	 * silently become the customer's billing city.
	 */
	public function mirror_area_to_billing() {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return;
		}

		// Already set by WooCommerce (ship-to-billing stores): nothing to do.
		if ( '' !== (string) WC()->customer->get_billing_city() ) {
			return;
		}

		$city = (string) WC()->customer->get_shipping_city();

		if ( '' === $city || null === Matrix_Delivery_Area::instance()->get_area( $city ) ) {
			return;
		}

		WC()->customer->set_billing_city( $city );
		WC()->customer->save();
	}

	/**
	 * Cart page only: submit the calculator as soon as an area is picked, so
	 * the fee appears without a second click on "Update totals".
	 */
	public function enqueue_auto_update() {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}

		$rel  = 'assets/js/cart-calculator.js';
		$path = MATRIX_AREA_DELIVERY_PATH . $rel;

		if ( ! file_exists( $path ) ) {
			return;
		}

		wp_enqueue_script(
			'matrix-cart-calculator',
			MATRIX_AREA_DELIVERY_URL . $rel,
			array(),
			(string) filemtime( $path ),
			true // footer
		);
	}
}
