/**
 * Cart shipping calculator: Delivery Area dropdown + instant totals.
 *
 * Why the dropdown is built here instead of in PHP: Porto overrides
 * `cart/shipping-calculator.php` with hardcoded <input> markup, so
 * WooCommerce's woocommerce_form_field() — and therefore every server-side
 * filter on it — never runs for this field. Swapping the rendered input leaves
 * the theme's template alone and survives Porto updates. Without JavaScript
 * the field simply stays the free-text input WooCommerce shipped.
 *
 * Options come from wp_localize_script (matrixDeliveryAreas), so the list is
 * the same one the checkout dropdown renders.
 */
( function () {
	'use strict';

	var FIELD_ID = 'calc_shipping_city';

	/**
	 * Replace the free-text city input with a Delivery Area <select>.
	 */
	function buildDropdown() {
		var input = document.getElementById( FIELD_ID );

		if ( ! input || 'INPUT' !== input.tagName ) {
			return; // Already a dropdown, or the calculator is not on this page.
		}

		if ( 'undefined' === typeof matrixDeliveryAreas || ! matrixDeliveryAreas.options ) {
			return; // No areas configured — leave WooCommerce's field alone.
		}

		var select = document.createElement( 'select' );

		select.id        = input.id;
		select.name      = input.name;
		select.className = input.className;

		matrixDeliveryAreas.options.forEach( function ( area ) {
			var option = document.createElement( 'option' );
			option.value       = area.value;
			option.textContent = area.label;
			select.appendChild( option );
		} );

		/*
		 * Keep whatever area the customer already has. An unrecognised value
		 * (a legacy free-text city, or a typo) matches no option, so the
		 * select falls back to the "Select Delivery Area" placeholder rather
		 * than showing a fee the customer can no longer reproduce.
		 */
		select.value = input.value;

		input.parentNode.replaceChild( select, input );
	}

	/**
	 * Submit the calculator as soon as an area is chosen.
	 *
	 * WooCommerce only auto-submits on change at CHECKOUT (checkout.js watches
	 * .update_totals_on_change); on the cart page the calculator waits for the
	 * "Update totals" button. Picking an area is a deliberate action with one
	 * obvious outcome — see the fee — so submit it for the customer.
	 */
	function onChange( event ) {
		var field = event.target;

		if ( ! field || FIELD_ID !== field.id ) {
			return;
		}

		var form = field.closest( 'form.woocommerce-shipping-calculator' );

		if ( ! form ) {
			return;
		}

		/*
		 * WooCommerce processes the calculator only when `calc_shipping` is
		 * posted. That value normally comes from the submit BUTTON, and a
		 * scripted form.submit() does not include button values — so post it
		 * as a hidden field instead.
		 */
		if ( ! form.querySelector( 'input[type="hidden"][name="calc_shipping"]' ) ) {
			var flag = document.createElement( 'input' );
			flag.type  = 'hidden';
			flag.name  = 'calc_shipping';
			flag.value = '1';
			form.appendChild( flag );
		}

		form.submit();
	}

	// Delegated: the calculator markup is replaced by WooCommerce's cart
	// fragments after any AJAX cart update.
	document.addEventListener( 'change', onChange );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', buildDropdown );
	} else {
		buildDropdown();
	}

	// Cart fragments re-render the calculator: rebuild the dropdown after
	// WooCommerce updates the totals (quantity change, coupon, remove item).
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_wc_div updated_cart_totals updated_shipping_method', buildDropdown );
	}
} )();
