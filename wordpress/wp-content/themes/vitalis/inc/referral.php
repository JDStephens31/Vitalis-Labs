<?php
/**
 * Vitalis Labs — "who referred you" checkout field.
 *
 * Every order records the full name of the person who referred the customer.
 * Required at checkout, stored on the order as `_vitalis_referral`, and shown
 * in wp-admin, in the order-confirmation emails, and on the customer's own
 * order view.
 *
 * @package vitalis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VITALIS_REFERRAL_META = '_vitalis_referral';

/** Collapse whitespace so "  Jane   Doe " compares and stores as "Jane Doe". */
function vitalis_referral_clean( $raw ) {
	return trim( preg_replace( '/\s+/', ' ', (string) $raw ) );
}

function vitalis_referral_posted() {
	return isset( $_POST['vitalis_referral'] )
		? vitalis_referral_clean( sanitize_text_field( wp_unslash( $_POST['vitalis_referral'] ) ) )
		: '';
}

/* -------------------------------------------------------------------------
 * The field
 * ---------------------------------------------------------------------- */

function vitalis_referral_field( $checkout ) {
	echo '<div class="vitalis-referral">';
	woocommerce_form_field(
		'vitalis_referral',
		array(
			'type'        => 'text',
			'class'       => array( 'form-row-wide' ),
			'label'       => __( 'Referred by', 'vitalis' ),
			'placeholder' => __( 'Full name of the person who referred you', 'vitalis' ),
			'required'    => true,
		),
		$checkout->get_value( 'vitalis_referral' )
	);
	echo '</div>';
}
add_action( 'woocommerce_after_checkout_billing_form', 'vitalis_referral_field' );

/* -------------------------------------------------------------------------
 * Validation
 * ---------------------------------------------------------------------- */

function vitalis_referral_validate() {
	$value = vitalis_referral_posted();

	if ( '' === $value ) {
		wc_add_notice( __( 'Please enter the full name of the person who referred you.', 'vitalis' ), 'error' );
		return;
	}
	// They were asked for a *full* name, so require at least two parts.
	if ( false === strpos( $value, ' ' ) ) {
		wc_add_notice( __( 'Please enter their full name — first and last.', 'vitalis' ), 'error' );
	}
}
add_action( 'woocommerce_checkout_process', 'vitalis_referral_validate' );

/* -------------------------------------------------------------------------
 * Storage
 * ---------------------------------------------------------------------- */

function vitalis_referral_save( $order ) {
	$value = vitalis_referral_posted();
	if ( '' !== $value ) {
		$order->update_meta_data( VITALIS_REFERRAL_META, $value );
	}
}
add_action( 'woocommerce_checkout_create_order', 'vitalis_referral_save', 20 );

/* -------------------------------------------------------------------------
 * Display: admin, emails, and the customer's order view
 * ---------------------------------------------------------------------- */

/** wp-admin → WooCommerce → Orders → (an order). */
function vitalis_referral_admin( $order ) {
	$value = $order->get_meta( VITALIS_REFERRAL_META );
	if ( $value ) {
		echo '<p><strong>' . esc_html__( 'Referred by', 'vitalis' ) . ':</strong><br>' . esc_html( $value ) . '</p>';
	}
}
add_action( 'woocommerce_admin_order_data_after_billing_address', 'vitalis_referral_admin' );

/** Appended to the order table in both customer and admin emails. */
function vitalis_referral_email_meta( $fields, $sent_to_admin, $order ) {
	$value = is_a( $order, 'WC_Order' ) ? $order->get_meta( VITALIS_REFERRAL_META ) : '';
	if ( $value ) {
		$fields['vitalis_referral'] = array(
			'label' => __( 'Referred by', 'vitalis' ),
			'value' => $value,
		);
	}
	return $fields;
}
add_filter( 'woocommerce_email_order_meta_fields', 'vitalis_referral_email_meta', 10, 3 );

/** My Account → Orders → (an order), and the thank-you page. */
function vitalis_referral_order_view( $order ) {
	$value = $order->get_meta( VITALIS_REFERRAL_META );
	if ( ! $value ) {
		return;
	}
	echo '<p class="vitalis-referral-line"><strong>' . esc_html__( 'Referred by', 'vitalis' ) . ':</strong> '
		. esc_html( $value ) . '</p>';
}
add_action( 'woocommerce_order_details_after_order_table', 'vitalis_referral_order_view' );
