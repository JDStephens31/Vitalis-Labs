<?php
/**
 * Vitalis Labs theme functions.
 *
 * @package vitalis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VITALIS_VERSION', '1.4.0' );

/**
 * Site password gate — blocks the storefront until the shared password and the
 * 21+ / not-for-human-consumption confirmation are given, and asks for the
 * password again at checkout. See inc/gate.php.
 */
require_once get_template_directory() . '/inc/gate.php';

/** Required "full name of who referred you" field on every order. */
require_once get_template_directory() . '/inc/referral.php';

/** Manual Venmo payment method + the payment-instructions email. */
require_once get_template_directory() . '/inc/venmo.php';

/** SMTP delivery — without this, wp_mail() has no transport in Docker. */
require_once get_template_directory() . '/inc/mail.php';

/**
 * Theme setup.
 */
function vitalis_setup() {
	// Core.
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'custom-logo', array(
		'height'      => 60,
		'width'       => 260,
		'flex-height' => true,
		'flex-width'  => true,
	) );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );

	// WooCommerce.
	add_theme_support( 'woocommerce' );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );

	register_nav_menus( array(
		'primary' => __( 'Primary Menu', 'vitalis' ),
		'footer'  => __( 'Footer Menu', 'vitalis' ),
	) );
}
add_action( 'after_setup_theme', 'vitalis_setup' );

/**
 * Styles & fonts.
 */
function vitalis_assets() {
	// Space Grotesk + Space Mono, matching the original site.
	wp_enqueue_style(
		'vitalis-fonts',
		'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&family=Space+Mono:wght@400;700&display=swap',
		array(),
		null
	);
	wp_enqueue_style( 'vitalis-style', get_stylesheet_uri(), array( 'vitalis-fonts' ), VITALIS_VERSION );
}
add_action( 'wp_enqueue_scripts', 'vitalis_assets' );

/**
 * Add a body class so our CSS scopes cleanly.
 */
function vitalis_body_class( $classes ) {
	$classes[] = 'vitalis';
	return $classes;
}
add_filter( 'body_class', 'vitalis_body_class' );

/* ---------------------------------------------------------------------------
 * WooCommerce content wrappers (classic-theme integration).
 * WooCommerce expects the theme to open a wrapper before its main content and
 * close it after. We remove WC's default (Storefront-style) wrappers and
 * provide our own so shop/product/cart/checkout render inside <main>.
 * ------------------------------------------------------------------------- */
remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );

function vitalis_wc_wrapper_start() {
	echo '<main class="site-main"><div class="vitalis-wrap">';
}
add_action( 'woocommerce_before_main_content', 'vitalis_wc_wrapper_start', 10 );

function vitalis_wc_wrapper_end() {
	echo '</div></main>';
}
add_action( 'woocommerce_after_main_content', 'vitalis_wc_wrapper_end', 10 );

// Products per row already handled by CSS grid; keep WC from forcing columns.
add_filter( 'loop_shop_columns', function () { return 3; } );

/**
 * Show a short "research use only" note under the add-to-cart on single
 * products, echoing the original site's compliance framing.
 */
function vitalis_research_note() {
	echo '<p class="mono" style="color:var(--muted);font-size:12px;letter-spacing:.06em;margin-top:14px;">RESEARCH USE ONLY — NOT FOR HUMAN CONSUMPTION.</p>';
}
add_action( 'woocommerce_after_add_to_cart_button', 'vitalis_research_note' );
