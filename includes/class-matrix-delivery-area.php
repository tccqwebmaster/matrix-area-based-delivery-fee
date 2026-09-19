<?php
/**
 * Delivery Area — the area repository plus everything that surfaces the
 * selected Delivery Area across WooCommerce.
 *
 * Field-ownership contract with the "TCC Qatar Custom" plugin:
 *
 *   - THIS plugin owns `billing_city` ONLY (the Delivery Area dropdown:
 *     type, options, label, required flag) — at checkout, in My Account
 *     address forms, and in the QA country locale that WooCommerce's
 *     address-i18n.js re-applies after AJAX events.
 *   - TCC Qatar Custom owns every other checkout field (visibility,
 *     labels, required flags, field order). Neither plugin touches the
 *     other's properties, so AJAX refreshes cannot produce conflicting
 *     field states.
 *
 * The Delivery Area keeps using `billing_city` (not custom meta) so it
 * stays natively compatible with WooCommerce addresses, shipping zones,
 * Conditional Payments, HPOS and checkout AJAX. On top of that, the
 * selected area is copied to order meta (`_matrix_delivery_area` +
 * `_matrix_delivery_area_label`) so it is available on the order edit
 * screen, the orders list, emails, the customer account and the REST API
 * (order meta_data) without re-deriving it.
 *
 * @package Matrix_Area_Delivery_Fee
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delivery area repository + checkout dropdown + order surfacing.
 */
class Matrix_Delivery_Area {

	/** Option holding the configured areas (managed by Matrix_Area_Delivery_Admin). */
	const OPTION = 'matrix_delivery_areas';

	/** Order meta: raw area value (mirrors billing_city at purchase time). */
	const ORDER_META_AREA = '_matrix_delivery_area';

	/** Order meta: bilingual display label ("English - عربي"). */
	const ORDER_META_LABEL = '_matrix_delivery_area_label';

	/** Orders list column key. */
	const COLUMN_KEY = 'matrix_delivery_area';

	/**
	 * Option: minimum order value (QAR) for delivery to FREE areas (fee 0).
	 * 0 = no minimum. Set on the Delivery Areas admin page.
	 */
	const MIN_ORDER_OPTION = 'matrix_free_area_min_order';

	/**
	 * Singleton instance.
	 *
	 * @var Matrix_Delivery_Area|null
	 */
	private static $instance = null;

	/**
	 * Get (or build) the singleton.
	 *
	 * @return Matrix_Delivery_Area
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
		// The ONLY checkout field this plugin touches: billing_city.
		add_filter( 'woocommerce_billing_fields', array( $this, 'billing_city_dropdown' ), 20 );
		add_filter( 'woocommerce_get_country_locale', array( $this, 'qatar_city_locale' ), 20 );

		// Bust WooCommerce's cached shipping rates when the area changes.
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'add_area_to_packages' ) );

		// Only a configured area may be submitted.
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_area' ), 10, 2 );

		// Minimum order for free-delivery areas: shown in the checkout order
		// review (the AJAX fragment, so it follows area/cart changes) and in
		// the cart totals; enforced in validate_area().
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'render_min_order_notice' ) );
		add_action( 'woocommerce_before_cart_totals', array( $this, 'render_min_order_notice' ) );

		// Persist to order meta (HPOS-safe CRUD) and surface everywhere.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_meta' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'render_admin_order_meta' ) );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_orders_column' ), 20 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_orders_column_hpos' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_orders_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_orders_column_legacy' ), 10, 2 );
		add_filter( 'woocommerce_email_order_meta_fields', array( $this, 'email_meta_fields' ), 10, 3 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_customer_order_meta' ) );
	}

	/* ---------------------------------------------------------------------
	 * Area repository — public API (also used by the shipping method and,
	 * if needed, by other plugins).
	 * ------------------------------------------------------------------ */

	/**
	 * All configured areas: array of {en, ar, value, fee}.
	 *
	 * @return array[]
	 */
	public function get_areas() {
		$areas = get_option( self::OPTION, array() );

		return is_array( $areas ) ? $areas : array();
	}

	/**
	 * A single area row by its stored value, or null.
	 *
	 * @param string $value Area value (the billing_city value).
	 * @return array|null
	 */
	public function get_area( $value ) {
		if ( '' === (string) $value ) {
			return null;
		}
		foreach ( $this->get_areas() as $area ) {
			if ( isset( $area['value'] ) && (string) $area['value'] === (string) $value ) {
				return $area;
			}
		}

		return null;
	}

	/**
	 * Delivery fee for an area value (0 when unknown / free).
	 *
	 * @param string $value Area value.
	 * @return float
	 */
	public function get_fee( $value ) {
		$area = $this->get_area( $value );

		return ( $area && isset( $area['fee'] ) ) ? (float) $area['fee'] : 0.0;
	}

	/**
	 * Bilingual display label for an area value ("English - عربي").
	 * Falls back to the raw value for legacy orders with unknown areas.
	 *
	 * @param string $value Area value.
	 * @return string
	 */
	public function get_label( $value ) {
		$area = $this->get_area( $value );

		if ( $area && isset( $area['en'], $area['ar'] ) ) {
			return $area['en'] . ' - ' . $area['ar'];
		}

		return (string) $value;
	}

	/**
	 * Dropdown options for the checkout / address forms.
	 *
	 * @return array<string,string>
	 */
	public function get_options() {
		$options = array(
			'' => self::is_arabic() ? 'اختر منطقة التوصيل' : __( 'Select Delivery Area', 'matrix-area-delivery-fee' ),
		);

		foreach ( $this->get_areas() as $area ) {
			if ( isset( $area['value'], $area['en'], $area['ar'] ) ) {
				$options[ $area['value'] ] = $area['en'] . ' - ' . $area['ar'];
			}
		}

		return $options;
	}

	/**
	 * The Delivery Area field label (single definition, reused everywhere).
	 * Bilingual by design — readable in both store languages.
	 *
	 * @return string
	 */
	public static function field_label() {
		return __( 'Delivery Area – منطقة التوصيل', 'matrix-area-delivery-fee' );
	}

	/**
	 * Whether the current request renders in Arabic (WPML sets the locale
	 * per language).
	 *
	 * @return bool
	 */
	public static function is_arabic() {
		return 0 === strpos( (string) get_locale(), 'ar' );
	}

	/**
	 * The area currently selected by the customer.
	 *
	 * Order of trust: customer session, then the posted billing_city field,
	 * then the serialised post_data sent by update_order_review — this is
	 * what keeps shipping in sync during checkout AJAX recalculation.
	 *
	 * The shipping city is consulted last: the cart's "Calculate shipping"
	 * form writes the SHIPPING address, so without this the fee would stay
	 * empty for any customer who had not already set a billing city.
	 * Matrix_Cart_Calculator mirrors it back to billing, so this is a
	 * fallback for the request that does the mirroring, not the usual path.
	 *
	 * @return string
	 */
	public function get_selected_area() {
		$city = '';

		if ( function_exists( 'WC' ) && WC()->customer ) {
			$city = (string) WC()->customer->get_billing_city();

			if ( '' === $city ) {
				$city = (string) WC()->customer->get_shipping_city();
			}
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only lookups inside WooCommerce's own checkout AJAX.
		if ( '' === $city && isset( $_POST['billing_city'] ) ) {
			$city = wc_clean( wp_unslash( $_POST['billing_city'] ) );
		}

		// The cart's "Calculate shipping" form, on the request that submits it.
		if ( '' === $city && isset( $_POST['calc_shipping_city'] ) ) {
			$city = wc_clean( wp_unslash( $_POST['calc_shipping_city'] ) );
		}

		if ( '' === $city && isset( $_POST['post_data'] ) ) {
			parse_str( wp_unslash( $_POST['post_data'] ), $post_data );
			if ( ! empty( $post_data['billing_city'] ) ) {
				$city = wc_clean( $post_data['billing_city'] );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $city;
	}

	/* ---------------------------------------------------------------------
	 * Checkout field + locale.
	 * ------------------------------------------------------------------ */

	/**
	 * Turn billing_city into the Delivery Area dropdown.
	 *
	 * Hooked to woocommerce_billing_fields so the same dropdown (with only
	 * valid areas) also appears on the My Account billing address form.
	 *
	 * @param array $fields Billing fields.
	 * @return array
	 */
	public function billing_city_dropdown( $fields ) {
		if ( ! isset( $fields['billing_city'] ) ) {
			return $fields;
		}

		$fields['billing_city']['type']     = 'select';
		$fields['billing_city']['label']    = self::field_label();
		$fields['billing_city']['required'] = true;
		$fields['billing_city']['class']    = array( 'form-row-wide' );
		$fields['billing_city']['options']  = $this->get_options();

		return $fields;
	}

	/**
	 * Mirror the city label/required into the QA country locale so
	 * WooCommerce's address-i18n.js re-applies OUR label after checkout
	 * events instead of reverting to the default "Town / City".
	 *
	 * Only the `city` key is written here; the rest of the QA locale
	 * belongs to TCC Qatar Custom (disjoint keys, no conflict).
	 *
	 * @param array $locale Country locale rules.
	 * @return array
	 */
	public function qatar_city_locale( $locale ) {
		$city = isset( $locale['QA']['city'] ) ? (array) $locale['QA']['city'] : array();

		$city['label']    = self::field_label();
		$city['required'] = true;

		$locale['QA']['city'] = $city;

		return $locale;
	}

	/**
	 * Reject checkout submissions with an area that is not configured.
	 *
	 * Empty-value handling stays with core (the field is required).
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Checkout validation errors.
	 */
	public function validate_area( $data, $errors ) {
		$city = isset( $data['billing_city'] ) ? (string) $data['billing_city'] : '';

		if ( '' !== $city && null === $this->get_area( $city ) ) {
			$errors->add(
				'matrix_delivery_area_invalid',
				self::is_arabic()
					? 'يرجى اختيار منطقة توصيل صحيحة من القائمة.'
					: __( 'Please select a valid Delivery Area from the list.', 'matrix-area-delivery-fee' )
			);
			return;
		}

		$shortfall = $this->min_order_shortfall( $city );
		if ( $shortfall > 0 ) {
			$errors->add( 'matrix_min_order', $this->min_order_message( $city, $shortfall ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Minimum order for free-delivery areas.
	 * ------------------------------------------------------------------ */

	/**
	 * Configured minimum (QAR) for free areas; 0 = no minimum.
	 *
	 * @return float
	 */
	public function free_area_minimum() {
		return max( 0.0, (float) get_option( self::MIN_ORDER_OPTION, 0 ) );
	}

	/**
	 * Order value the minimum is measured against: the cart's products after
	 * coupon discounts (incl. tax, if any) — delivery excluded. Same basis as
	 * WooCommerce's own "Free shipping → minimum order amount".
	 *
	 * @return float
	 */
	private function cart_order_value() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}
		return (float) WC()->cart->get_cart_contents_total() + (float) WC()->cart->get_cart_contents_tax();
	}

	/**
	 * How much more the customer must add to be delivered to $area_value,
	 * or 0 when no minimum applies.
	 *
	 * Applies only when: a minimum is set, the area exists and is FREE
	 * (fee 0 — the inside-Doha areas), and the cart needs delivery at all
	 * (digital gift-card-only orders are exempt). Paid areas never have a
	 * minimum — they pay their fee.
	 *
	 * @param string $area_value Selected area (billing_city value).
	 * @return float
	 */
	public function min_order_shortfall( $area_value ) {
		$minimum = $this->free_area_minimum();
		if ( $minimum <= 0 || '' === (string) $area_value ) {
			return 0.0;
		}
		$area = $this->get_area( $area_value );
		if ( ! $area || (float) ( $area['fee'] ?? 0 ) > 0 ) {
			return 0.0;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->cart->needs_shipping() ) {
			return 0.0;
		}
		$value = $this->cart_order_value();
		return $value < $minimum ? round( $minimum - $value, wc_get_price_decimals() ) : 0.0;
	}

	/**
	 * Bilingual "minimum order" message.
	 *
	 * @param string $area_value Area value.
	 * @param float  $shortfall  Amount still missing.
	 * @return string HTML (prices formatted by wc_price).
	 */
	public function min_order_message( $area_value, $shortfall ) {
		$area    = $this->get_area( $area_value );
		$minimum = wc_price( $this->free_area_minimum() );
		$missing = wc_price( $shortfall );

		if ( self::is_arabic() ) {
			$name = $area['ar'] ?? (string) $area_value;
			return sprintf( 'الحد الأدنى للطلب للتوصيل إلى %1$s هو %2$s. يرجى إضافة منتجات بقيمة %3$s إضافية لإتمام الطلب.', esc_html( $name ), $minimum, $missing );
		}

		$name = $area['en'] ?? (string) $area_value;
		return sprintf(
			/* translators: 1: area name, 2: minimum order amount, 3: amount still missing */
			__( 'Minimum order for delivery to %1$s is %2$s. Please add %3$s more to place your order.', 'matrix-area-delivery-fee' ),
			esc_html( $name ),
			$minimum,
			$missing
		);
	}

	/**
	 * Show the minimum-order notice in the checkout review / cart totals
	 * while the selected free area's minimum is not met.
	 */
	public function render_min_order_notice() {
		$area      = $this->get_selected_area();
		$shortfall = $this->min_order_shortfall( $area );
		if ( $shortfall <= 0 ) {
			return;
		}
		echo '<div class="woocommerce-error matrix-min-order-notice" role="alert" style="margin:0 0 1em">'
			. wp_kses_post( $this->min_order_message( $area, $shortfall ) )
			. '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Shipping recalculation.
	 * ------------------------------------------------------------------ */

	/**
	 * Fold the selected area into every shipping package so WooCommerce
	 * busts its cached rate (the package hash) whenever the area changes.
	 * Without this the fee would stay stale when the customer switches
	 * areas at checkout.
	 *
	 * @param array $packages Shipping packages.
	 * @return array
	 */
	public function add_area_to_packages( $packages ) {
		$city = $this->get_selected_area();

		if ( '' !== $city ) {
			foreach ( $packages as $key => $package ) {
				$packages[ $key ]['matrix_delivery_area'] = $city;
			}
		}

		return $packages;
	}

	/* ---------------------------------------------------------------------
	 * Order meta + surfacing (order edit, orders list, emails, account, REST).
	 * ------------------------------------------------------------------ */

	/**
	 * Copy the selected area onto the order as meta.
	 *
	 * Saved via WC_Order CRUD (HPOS-safe) and automatically exposed in the
	 * REST API as order meta_data.
	 *
	 * @param WC_Order $order Order being created (not yet saved).
	 * @param array    $data  Posted checkout data.
	 */
	public function save_order_meta( $order, $data ) {
		$city = (string) $order->get_billing_city();

		if ( '' === $city ) {
			return;
		}

		$order->update_meta_data( self::ORDER_META_AREA, $city );
		$order->update_meta_data( self::ORDER_META_LABEL, $this->get_label( $city ) );
	}

	/**
	 * Display label for an order — saved meta first, live lookup fallback
	 * for orders that predate this version.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public function get_order_area_label( $order ) {
		$label = (string) $order->get_meta( self::ORDER_META_LABEL );

		if ( '' === $label ) {
			$city  = (string) $order->get_billing_city();
			$label = '' !== $city ? $this->get_label( $city ) : '';
		}

		return $label;
	}

	/**
	 * Order edit screen: show the area under the billing address.
	 *
	 * @param WC_Order $order Order being edited.
	 */
	public function render_admin_order_meta( $order ) {
		$label = $this->get_order_area_label( $order );

		if ( '' === $label ) {
			return;
		}

		printf(
			'<p class="matrix-delivery-area"><strong>%s</strong> %s</p>',
			esc_html__( 'Delivery Area:', 'matrix-area-delivery-fee' ),
			esc_html( $label )
		);
	}

	/**
	 * Add the Delivery Area column to the orders list (HPOS and legacy).
	 *
	 * @param array $columns List table columns.
	 * @return array
	 */
	public function add_orders_column( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new[ self::COLUMN_KEY ] = __( 'Delivery Area', 'matrix-area-delivery-fee' );
			}
		}

		if ( ! isset( $new[ self::COLUMN_KEY ] ) ) {
			$new[ self::COLUMN_KEY ] = __( 'Delivery Area', 'matrix-area-delivery-fee' );
		}

		return $new;
	}

	/**
	 * Render the column on the HPOS orders table.
	 *
	 * @param string   $column Column key.
	 * @param WC_Order $order  Order for the current row.
	 */
	public function render_orders_column_hpos( $column, $order ) {
		if ( self::COLUMN_KEY === $column && $order instanceof WC_Order ) {
			echo esc_html( $this->get_order_area_label( $order ) );
		}
	}

	/**
	 * Render the column on the legacy (post-based) orders table.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Order post ID.
	 */
	public function render_orders_column_legacy( $column, $post_id ) {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}

		$order = wc_get_order( $post_id );

		if ( $order ) {
			echo esc_html( $this->get_order_area_label( $order ) );
		}
	}

	/**
	 * Add the Delivery Area to order emails (admin and customer).
	 *
	 * @param array    $fields        Email meta fields.
	 * @param bool     $sent_to_admin Whether the email goes to the admin.
	 * @param WC_Order $order         Order.
	 * @return array
	 */
	public function email_meta_fields( $fields, $sent_to_admin, $order ) {
		if ( $order instanceof WC_Order ) {
			$label = $this->get_order_area_label( $order );

			if ( '' !== $label ) {
				$fields[ self::COLUMN_KEY ] = array(
					'label' => __( 'Delivery Area', 'matrix-area-delivery-fee' ),
					'value' => $label,
				);
			}
		}

		return $fields;
	}

	/**
	 * Customer account (My Account → View order): show the area after the
	 * order table.
	 *
	 * @param WC_Order $order Order being viewed.
	 */
	public function render_customer_order_meta( $order ) {
		$label = $this->get_order_area_label( $order );

		if ( '' === $label ) {
			return;
		}

		printf(
			'<p class="matrix-delivery-area"><strong>%s</strong> %s</p>',
			esc_html__( 'Delivery Area:', 'matrix-area-delivery-fee' ),
			esc_html( $label )
		);
	}
}
