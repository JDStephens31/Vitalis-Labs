<?php
/**
 * Vitalis Labs — general WordPress hardening.
 *
 * The password gate protects the storefront. These close the doors around it
 * that have nothing to do with the gate but everything to do with getting into
 * the store: XML-RPC, username enumeration, and login error messages that
 * confirm which usernames exist.
 *
 * They matter more here than on an ordinary site because the account that owns
 * this store is also the account that can read every customer's address and
 * order history.
 *
 * @package vitalis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * XML-RPC
 *
 * /xmlrpc.php offered wp.getUsersBlogs (password guessing that ignores every
 * wp-login.php protection), system.multicall (hundreds of guesses per HTTP
 * request), and pingback.ping (which turns the site into a traffic reflector).
 *
 * Nothing here uses it: no Jetpack, no mobile app, no remote publishing — the
 * store is administered through wp-admin. If you ever add Jetpack, this is the
 * first thing to revisit.
 * ---------------------------------------------------------------------- */

add_filter( 'xmlrpc_enabled', '__return_false' );

/** Drop the pingback methods, which stay callable even with XML-RPC "off". */
function vitalis_remove_xmlrpc_pingback( $methods ) {
	unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
	return $methods;
}
add_filter( 'xmlrpc_methods', 'vitalis_remove_xmlrpc_pingback' );

/** Stop advertising the endpoint we just disabled. */
function vitalis_remove_pingback_header( $headers ) {
	unset( $headers['X-Pingback'] );
	return $headers;
}
add_filter( 'wp_headers', 'vitalis_remove_pingback_header' );

remove_action( 'wp_head', 'rsd_link' );

/* -------------------------------------------------------------------------
 * Username enumeration
 *
 * Knowing the administrator is called "admin" is half of a brute-force attempt.
 * WordPress gives that away in three places.
 * ---------------------------------------------------------------------- */

/**
 * /wp-json/wp/v2/users — the users route, which lists every author.
 *
 * The gate's REST guard already refuses anonymous REST requests, so this is the
 * second lock on the same door: it holds even if the gate is disabled or a
 * route is added to `vitalis_gate_rest_public_routes`.
 */
function vitalis_block_rest_user_enumeration( $endpoints ) {
	if ( is_user_logged_in() ) {
		return $endpoints;
	}

	foreach ( array_keys( $endpoints ) as $route ) {
		if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
			unset( $endpoints[ $route ] );
		}
	}

	return $endpoints;
}
add_filter( 'rest_endpoints', 'vitalis_block_rest_user_enumeration' );

/**
 * /?author=1 — which redirects to /author/<username>/, printing the login name
 * in the URL bar. Gated already, but free to close.
 */
function vitalis_block_author_enumeration() {
	if ( is_admin() || vitalis_gate_is_privileged() ) {
		return;
	}
	if ( ! empty( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( home_url( '/' ), 302 );
		exit;
	}
}
add_action( 'template_redirect', 'vitalis_block_author_enumeration', 0 );

/** Author archives serve no purpose on a storefront. */
function vitalis_disable_author_archives( $query ) {
	if ( ! is_admin() && $query->is_main_query() && $query->is_author() ) {
		$query->set_404();
		status_header( 404 );
	}
}
add_action( 'pre_get_posts', 'vitalis_disable_author_archives' );

/* -------------------------------------------------------------------------
 * Login
 * ---------------------------------------------------------------------- */

/**
 * One message for every failure.
 *
 * WordPress distinguishes "unknown username" from "the password you entered
 * for the username X is incorrect" — which confirms a valid account and hands
 * an attacker the half of the credential pair they were missing.
 */
function vitalis_generic_login_error() {
	return __( 'That username, email or password is not correct.', 'vitalis' );
}
add_filter( 'login_errors', 'vitalis_generic_login_error' );

/* -------------------------------------------------------------------------
 * Version disclosure
 * ---------------------------------------------------------------------- */

/** The WordPress version in <head> and on every asset URL just helps a scanner. */
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );
