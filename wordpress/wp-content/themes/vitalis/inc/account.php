<?php
/**
 * Vitalis Labs — every order must belong to a customer account.
 *
 * This was previously enforced only by the WooCommerce setting
 * `woocommerce_enable_guest_checkout = no`, living in the database and nowhere
 * else. Nothing in the repository set it, so a rebuilt or restored database
 * came up with guest checkout back ON — WooCommerce's own default — and the
 * requirement quietly disappeared with no visible change to the site.
 *
 * The rule now lives in code, in three layers:
 *
 *   1. `woocommerce_checkout_registration_required` — the lever WooCommerce
 *      itself reads when deciding whether to allow a guest through.
 *   2. The option is forced to 'no' for front-end reads, so anything consulting
 *      it directly agrees. wp-admin still sees the stored value, so the
 *      WooCommerce settings screen stays honest about what's in the database.
 *   3. A check on the finished order: no customer id, no order. This is the one
 *      that can't be argued with, because it tests the outcome rather than the
 *      configuration.
 *
 * Note what "required" means to WooCommerce: a signed-out customer isn't turned
 * away, they're *registered on the spot* from their billing email, and the order
 * lands on that new account. That is the behaviour this store already had. If
 * you'd rather people sign in or register deliberately *before* they can reach
 * checkout, see vitalis_account_require_prior_login() at the bottom.
 *
 * Browsing and the cart stay open to everyone; only ordering needs an account.
 *
 * @package vitalis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * 1 + 2. Guest checkout is off, and stays off
 * ---------------------------------------------------------------------- */

add_filter( 'woocommerce_checkout_registration_required', '__return_true', 99 );

/** Customers must be able to *create* the account the checkout demands. */
add_filter( 'woocommerce_checkout_registration_enabled', '__return_true', 99 );

/**
 * Force the underlying option off for front-end reads.
 *
 * Left alone in wp-admin so WooCommerce → Settings → Accounts still shows the
 * real stored value rather than a checkbox that silently refuses to change.
 *
 * @param mixed $value Stored option value.
 * @return mixed
 */
function vitalis_account_force_no_guest_checkout( $value ) {
	return is_admin() ? $value : 'no';
}
add_filter( 'option_woocommerce_enable_guest_checkout', 'vitalis_account_force_no_guest_checkout', 99 );

/* -------------------------------------------------------------------------
 * 3. The check that actually stops the order
 * ---------------------------------------------------------------------- */

/**
 * Refuse to build an order that isn't attached to a user account.
 *
 * By this point WooCommerce has already run `process_customer()`, which signs
 * in an existing customer or registers a new one, and `create_order()` has set
 * the customer id from the resulting session. A zero here means every layer
 * above has failed or been filtered away, and the order would be a true guest
 * order. Throwing aborts checkout: WC_Checkout::create_order() catches the
 * exception and surfaces the message as a checkout error.
 *
 * Priority 5 so this runs before the referral field is stored at 20.
 *
 * @param WC_Order $order Draft order.
 * @throws Exception When the order has no customer.
 */
function vitalis_account_require_customer( $order ) {
	if ( $order->get_customer_id() > 0 ) {
		return;
	}

	throw new Exception(
		esc_html__( 'You need an account to place an order. Please sign in and try again.', 'vitalis' )
	);
}
add_action( 'woocommerce_checkout_create_order', 'vitalis_account_require_customer', 5 );

/* -------------------------------------------------------------------------
 * Optional: demand a deliberate sign-in, not an automatic one
 * ---------------------------------------------------------------------- */

/**
 * Stricter reading of "an account is required": the customer must already be
 * signed in when they reach checkout, rather than being registered silently as
 * the order is placed.
 *
 * Off by default, because it's a real change to how customers buy — a first-
 * time buyer has to register and come back — and that's a business decision,
 * not a security one. To turn it on, delete the `return;` below.
 */
function vitalis_account_require_prior_login() {
	return; // Remove this line to require a deliberate sign-in.

	if ( is_user_logged_in() ) { // phpcs:ignore Squiz.PHP.NonExecutableCode.Unreachable
		return;
	}

	wc_add_notice(
		sprintf(
			/* translators: %s: my-account page URL */
			__( 'You need an account to place an order. Please <a href="%s">sign in or register</a>, then return to checkout.', 'vitalis' ),
			esc_url( wc_get_page_permalink( 'myaccount' ) )
		),
		'error'
	);
}
add_action( 'woocommerce_checkout_process', 'vitalis_account_require_prior_login', 5 );
