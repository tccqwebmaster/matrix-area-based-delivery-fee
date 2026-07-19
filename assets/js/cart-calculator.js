/**
 * Cart shipping calculator: submit as soon as a Delivery Area is chosen.
 *
 * WooCommerce only auto-submits on change at CHECKOUT (checkout.js watches
 * .update_totals_on_change); on the cart page the calculator waits for the
 * "Update totals" button. Selecting an area is a deliberate action with one
 * obvious outcome — see the fee — so we submit for the customer.
 *
 * Delegated from the document because the calculator markup is replaced by
 * WooCommerce's cart fragments after any AJAX cart update.
 */
( function () {
	'use strict';

	document.addEventListener( 'change', function ( event ) {
		var field = event.target;

		if ( ! field || 'calc_shipping_city' !== field.id ) {
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
			flag.type = 'hidden';
			flag.name = 'calc_shipping';
			flag.value = '1';
			form.appendChild( flag );
		}

		form.submit();
	} );
} )();
