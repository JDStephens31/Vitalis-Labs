<?php
/**
 * Vitalis Labs theme functions.
 *
 * @package vitalis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VITALIS_VERSION', '1.6.0' );

/**
 * Site password gate — blocks the storefront until the shared password and the
 * 21+ / not-for-human-consumption confirmation are given, asks for the password
 * again at checkout, and holds the REST/AJAX endpoints to the same rule.
 * See inc/gate.php.
 */
require_once get_template_directory() . '/inc/gate.php';

/** Every order must belong to a customer account. */
require_once get_template_directory() . '/inc/account.php';

/** Required "full name of who referred you" field on every order. */
require_once get_template_directory() . '/inc/referral.php';

/** XML-RPC, username enumeration, and login error hardening. */
require_once get_template_directory() . '/inc/hardening.php';

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

	// Mobile drawer toggle. No dependencies, deferred to the footer.
	wp_enqueue_script(
		'vitalis-nav',
		get_template_directory_uri() . '/assets/nav.js',
		array(),
		VITALIS_VERSION,
		true
	);

	// Single products get two per-product variables, because both values live in
	// the database rather than the stylesheet:
	//   --cap        the product's cap colour, tinting the gallery glow the same
	//                way the catalog cards are tinted.
	//   --vl-img-ar  the featured image's own aspect ratio, so the gallery frame
	//                can be cut to the photo instead of defaulting to a square.
	//                See .woocommerce-product-gallery in style.css.
	if ( function_exists( 'is_product' ) && is_product() ) {
		$pid  = get_queried_object_id();
		$vars = '';

		$cap = get_post_meta( $pid, '_vitalis_cap', true );
		if ( $cap && preg_match( '/^#[0-9a-fA-F]{3,8}$/', $cap ) ) {
			$vars .= '--cap:' . $cap . ';';
		}

		$thumb_id = get_post_thumbnail_id( $pid );
		$thumb    = $thumb_id ? wp_get_attachment_metadata( $thumb_id ) : false;
		if ( ! empty( $thumb['width'] ) && ! empty( $thumb['height'] ) ) {
			// Left as a division so calc() can fold it against the frame height.
			$vars .= '--vl-img-ar:' . (int) $thumb['width'] . '/' . (int) $thumb['height'] . ';';
		}

		if ( $vars ) {
			wp_add_inline_style( 'vitalis-style', 'body.single-product{' . $vars . '}' );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'vitalis_assets' );

/**
 * Primary navigation links, rendered in both the desktop bar and the mobile
 * drawer. `items_wrap` drops wp_nav_menu's auto id so calling this twice on a
 * page doesn't emit duplicate element ids.
 */
function vitalis_nav_links() {
	if ( has_nav_menu( 'primary' ) ) {
		wp_nav_menu( array(
			'theme_location' => 'primary',
			'container'      => false,
			'depth'          => 1,
			'items_wrap'     => '<ul class="%2$s">%3$s</ul>',
			'fallback_cb'    => false,
		) );
		return;
	}

	echo '<ul>';
	echo '<li><a href="' . esc_url( home_url( '/' ) ) . '">Catalog</a></li>';
	if ( function_exists( 'wc_get_page_id' ) ) {
		echo '<li><a href="' . esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ) . '">Shop</a></li>';
		if ( is_user_logged_in() ) {
			echo '<li><a href="' . esc_url( wc_get_account_endpoint_url( 'orders' ) ) . '">My Orders</a></li>';
		}
		echo '<li><a href="' . esc_url( get_permalink( wc_get_page_id( 'myaccount' ) ) ) . '">' . ( is_user_logged_in() ? 'Account' : 'Log in' ) . '</a></li>';
	}
	echo '</ul>';
}

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
 * Drop WooCommerce's sidebar.
 *
 * `woocommerce_get_sidebar()` calls `get_sidebar( 'shop' )`. This theme has no
 * sidebar-shop.php or sidebar.php, so WordPress fell through to its own
 * theme-compat sidebar — the raw Search / Pages / Archives / Categories dump
 * that was appearing under the shop grid. The store has no sidebar by design.
 */
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

/* ---------------------------------------------------------------------------
 * Single product page.
 *
 * Rebuilt out of WooCommerce's summary hooks rather than a template override,
 * so WooCommerce keeps ownership of the markup it updates (gallery, cart form,
 * tabs) and the Venmo/referral/gate includes keep their hook points.
 * ------------------------------------------------------------------------- */

/**
 * Spec strip under the product title — dose, purity and live stock, matching
 * the meta row on the catalog cards.
 */
function vitalis_single_specs() {
	global $product;
	if ( ! $product ) {
		return;
	}

	$pid    = $product->get_id();
	$dose   = get_post_meta( $pid, '_vitalis_dose', true );
	$purity = get_post_meta( $pid, '_vitalis_purity', true );
	$purity = $purity ? $purity : '99.9%';

	echo '<div class="vl-single__specs">';
	if ( $dose ) {
		echo '<span class="dose-badge">' . esc_html( $dose ) . '</span>';
	}
	echo '<span class="mono vl-single__spec">HPLC ' . esc_html( $purity ) . '</span>';
	echo '<span class="mono vl-single__spec">COA included</span>';
	echo '</div>';
}
add_action( 'woocommerce_single_product_summary', 'vitalis_single_specs', 6 );

/**
 * Show the "research use only" note *after* the cart form, not inside it.
 *
 * On `woocommerce_after_add_to_cart_button` the note lands between the floated
 * quantity/button controls and wraps alongside them — that's the text running
 * into the Add to cart button. Outside the form it gets its own row.
 */
function vitalis_research_note() {
	echo '<p class="vl-single__note mono">RESEARCH USE ONLY — NOT FOR HUMAN CONSUMPTION.</p>';
}
add_action( 'woocommerce_after_add_to_cart_form', 'vitalis_research_note', 5 );

/**
 * Replace WooCommerce's product_meta block with a spec table.
 *
 * Also drops the category row when the product only carries the auto-assigned
 * default ("Uncategorized") — that's WordPress bookkeeping, not a fact about
 * the product. Real categories still render.
 */
remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );

function vitalis_single_meta() {
	global $product;
	if ( ! $product ) {
		return;
	}

	$rows = array();

	if ( wc_product_sku_enabled() && $product->get_sku() ) {
		$rows['SKU'] = esc_html( $product->get_sku() );
	}

	$cats = vitalis_meaningful_product_cats( $product->get_id() );
	if ( $cats ) {
		$links = array();
		foreach ( $cats as $cat ) {
			$links[] = '<a href="' . esc_url( get_term_link( $cat ) ) . '">' . esc_html( $cat->name ) . '</a>';
		}
		$rows[ _n( 'Category', 'Categories', count( $cats ), 'vitalis' ) ] = implode( ', ', $links );
	}

	if ( ! $rows ) {
		return;
	}

	echo '<dl class="vl-single__meta">';
	foreach ( $rows as $label => $value ) {
		echo '<dt>' . esc_html( $label ) . '</dt><dd class="mono">' . wp_kses_post( $value ) . '</dd>';
	}
	echo '</dl>';
}
add_action( 'woocommerce_single_product_summary', 'vitalis_single_meta', 40 );

/**
 * A product's categories, minus the store's default one.
 *
 * @param int $product_id Product post id.
 * @return WP_Term[]
 */
function vitalis_meaningful_product_cats( $product_id ) {
	$terms = get_the_terms( $product_id, 'product_cat' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return array();
	}

	$default = (int) get_option( 'default_product_cat', 0 );

	return array_values( array_filter( $terms, function ( $term ) use ( $default ) {
		return (int) $term->term_id !== $default;
	} ) );
}

/**
 * Keep the default category out of the breadcrumb too, so a product reads
 * "Home / Retatrutide 10mg" instead of "Home / Uncategorized / Retatrutide 10mg".
 */
function vitalis_trim_breadcrumb( $crumbs ) {
	$default = (int) get_option( 'default_product_cat', 0 );
	if ( ! $default ) {
		return $crumbs;
	}

	$term = get_term( $default, 'product_cat' );
	if ( ! $term || is_wp_error( $term ) ) {
		return $crumbs;
	}

	return array_values( array_filter( $crumbs, function ( $crumb ) use ( $term ) {
		return ! isset( $crumb[0] ) || $crumb[0] !== $term->name;
	} ) );
}
add_filter( 'woocommerce_get_breadcrumb', 'vitalis_trim_breadcrumb' );
