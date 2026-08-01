<?php
/**
 * Vitalis Labs — site password gate.
 *
 * One shared password guards the storefront in two places:
 *   1. On first load, before ANY page is reachable, together with a required
 *      confirmation that the visitor is 21+ and understands the products are
 *      not for human consumption.
 *   2. Again at checkout, as a per-order confirmation.
 *
 * The password is stored ONLY as a hash (wp_hash_password() — bcrypt on WP 6.8+)
 * in the `vitalis_gate_hash` option, and is checked server-side. The plaintext
 * and the hash never reach the browser. This mirrors the Supabase design in
 * dont-push/supabase-referral-gate.sql, where the hash lives in a table no API
 * role can read and verify_referral_password() returns only true/false.
 *
 * The block is a real server-side redirect on `template_redirect`, not a CSS
 * overlay: locked visitors never receive a single byte of page content, so
 * view-source or curl can't walk around it. The REST API, `wc-ajax` and
 * admin-ajax are separate channels that bypass `template_redirect` entirely;
 * each gets its own guard under "The other doors into the same store".
 *
 * There is no default password. An unconfigured gate fails closed and nags in
 * wp-admin until someone sets one at: Settings → Vitalis Gate.
 *
 * @package vitalis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VITALIS_GATE_OPTION  = 'vitalis_gate_hash';
const VITALIS_GATE_COOKIE  = 'vitalis_gate';
const VITALIS_GATE_MAX_TRIES = 10;             // per IP, per window
const VITALIS_GATE_WINDOW    = 900;            // 15 minutes
const VITALIS_GATE_MIN_LEN   = 8;              // minimum length when setting one

/**
 * The password earlier versions of this theme seeded on first run.
 *
 * It shipped in the public repository and in DEPLOY-CPANEL.md, so it is not a
 * secret and is never set anymore. It survives here for one purpose: detecting
 * an install still running on it, so `vitalis_gate_legacy_notice()` can say so.
 */
const VITALIS_GATE_LEGACY_DEFAULT = 'V1T4L1S';

/* -------------------------------------------------------------------------
 * Password storage
 * ---------------------------------------------------------------------- */

/**
 * The stored hash, or '' when no password has been set yet.
 *
 * Nothing is seeded here. A theme-supplied default is public by definition —
 * anyone who can read the source knows it — so an unconfigured gate fails
 * closed instead: `vitalis_gate_check()` refuses every candidate and
 * `vitalis_gate_is_unlocked()` refuses every cookie, locking the storefront
 * until an admin sets a real password in Settings → Vitalis Gate. Admins are
 * exempt from the gate, so they can always get in to do it.
 */
function vitalis_gate_hash() {
	return (string) get_option( VITALIS_GATE_OPTION, '' );
}

/** Whether a site password has been set at all. */
function vitalis_gate_is_configured() {
	return '' !== vitalis_gate_hash();
}

/** Replace the site password. Invalidates every existing unlock cookie. */
function vitalis_gate_set_password( $plain ) {
	update_option( VITALIS_GATE_OPTION, wp_hash_password( $plain ), false );
}

/** Constant-time-ish check of a candidate against the stored hash. */
function vitalis_gate_check( $candidate ) {
	$hash = vitalis_gate_hash();
	if ( '' === $hash ) {
		return false;
	}
	return wp_check_password( $candidate, $hash );
}

/**
 * Whether this install is still on the password the theme used to ship with.
 *
 * bcrypt is deliberately slow, so the answer is cached against the hash it was
 * computed from — it only recomputes when the password actually changes.
 */
function vitalis_gate_is_legacy_default() {
	$hash = vitalis_gate_hash();
	if ( '' === $hash ) {
		return false;
	}

	$key    = 'vitalis_gate_legacy_' . md5( $hash );
	$cached = get_transient( $key );
	if ( false !== $cached ) {
		return (bool) $cached;
	}

	$is_legacy = wp_check_password( VITALIS_GATE_LEGACY_DEFAULT, $hash );
	set_transient( $key, $is_legacy ? 1 : 0, DAY_IN_SECONDS );

	return $is_legacy;
}

/* -------------------------------------------------------------------------
 * Unlock cookie
 * ---------------------------------------------------------------------- */

/**
 * Cookie payload. Derived from the current hash + this site's auth salts, so
 * it can't be forged, and rotating the password logs everyone back out.
 */
function vitalis_gate_token() {
	return wp_hash( 'vitalis_gate|' . vitalis_gate_hash(), 'auth' );
}

function vitalis_gate_is_unlocked() {
	// No password set means no valid token exists — refuse everything rather
	// than handing out access derived from an empty hash.
	if ( ! vitalis_gate_is_configured() ) {
		return false;
	}
	if ( empty( $_COOKIE[ VITALIS_GATE_COOKIE ] ) ) {
		return false;
	}
	return hash_equals( vitalis_gate_token(), (string) $_COOKIE[ VITALIS_GATE_COOKIE ] );
}

/**
 * @param bool $remember Persist for 30 days, vs. a session cookie that dies
 *                       with the browser.
 */
function vitalis_gate_unlock( $remember = false ) {
	setcookie(
		VITALIS_GATE_COOKIE,
		vitalis_gate_token(),
		array(
			'expires'  => $remember ? time() + 30 * DAY_IN_SECONDS : 0,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);
}

/* -------------------------------------------------------------------------
 * Brute-force throttle
 * ---------------------------------------------------------------------- */

function vitalis_gate_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0';
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
}

function vitalis_gate_try_key() {
	return 'vitalis_gate_try_' . md5( vitalis_gate_client_ip() );
}

function vitalis_gate_tries() {
	return (int) get_transient( vitalis_gate_try_key() );
}

function vitalis_gate_record_try() {
	set_transient( vitalis_gate_try_key(), vitalis_gate_tries() + 1, VITALIS_GATE_WINDOW );
}

function vitalis_gate_clear_tries() {
	delete_transient( vitalis_gate_try_key() );
}

/* -------------------------------------------------------------------------
 * The block itself
 * ---------------------------------------------------------------------- */

/**
 * Callers that are trusted regardless of which door they came through.
 *
 * Split out of `vitalis_gate_is_exempt()` because the AJAX and REST guards
 * below need *this* half and not the other one. The transport-based exemptions
 * (AJAX, REST) exist so wp-admin keeps working; reusing them to decide whether
 * an anonymous request may proceed is exactly the mistake that let the Store
 * API place orders around the gate.
 */
function vitalis_gate_is_privileged() {
	if ( wp_doing_cron() ) {
		return true;
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}
	// Admins are never locked out of their own store.
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}
	return false;
}

/**
 * Requests that must never be gated *by the page-render block*, or the site
 * becomes unadministrable: wp-admin, the login screen, AJAX, REST, cron, and
 * the CLI.
 *
 * AJAX and REST are exempt here only because `template_redirect` is the wrong
 * place to police them — they get their own guards, further down, that check
 * the unlock cookie properly.
 */
function vitalis_gate_is_exempt() {
	if ( is_admin() || wp_doing_ajax() ) {
		return true;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}
	if ( vitalis_gate_is_privileged() ) {
		return true;
	}
	return (bool) apply_filters( 'vitalis_gate_exempt', false );
}

/**
 * Runs before any template renders. Either lets the request through or prints
 * the gate and stops.
 */
function vitalis_gate_maybe_block() {
	if ( vitalis_gate_is_exempt() ) {
		return;
	}

	$error = '';

	// Handle a gate submission.
	if ( isset( $_POST['vitalis_gate_submit'] ) ) {
		$nonce = isset( $_POST['vitalis_gate_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['vitalis_gate_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'vitalis_gate' ) ) {
			$error = 'That form expired — please try again.';
		} elseif ( vitalis_gate_tries() >= VITALIS_GATE_MAX_TRIES ) {
			$error = 'Too many attempts. Please wait 15 minutes and try again.';
		} elseif ( empty( $_POST['vitalis_gate_disclaimer'] ) ) {
			$error = 'Please confirm you are 21 or older and that these are not for human consumption.';
		} else {
			// Don't sanitize the password — it must be compared byte for byte.
			$candidate = isset( $_POST['vitalis_gate_password'] ) ? wp_unslash( $_POST['vitalis_gate_password'] ) : '';

			if ( '' === $candidate ) {
				$error = 'Enter the site password to continue.';
			} elseif ( vitalis_gate_check( $candidate ) ) {
				vitalis_gate_clear_tries();
				vitalis_gate_unlock( ! empty( $_POST['vitalis_gate_remember'] ) );
				// Redirect so a refresh doesn't repost the password.
				wp_safe_redirect( vitalis_gate_current_url() );
				exit;
			} else {
				vitalis_gate_record_try();
				$error = 'Incorrect password — please check with your referrer.';
			}
		}
	}

	if ( vitalis_gate_is_unlocked() ) {
		return;
	}

	vitalis_gate_render( $error );
	exit;
}
add_action( 'template_redirect', 'vitalis_gate_maybe_block', 1 );

/** The URL the visitor asked for, so we can send them back to it after unlocking. */
function vitalis_gate_current_url() {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
	return home_url( $path );
}

/**
 * The gate screen. Deliberately self-contained — no theme templates, no
 * enqueued assets — because nothing else about the site should be reachable.
 */
function vitalis_gate_render( $error = '' ) {
	// Tell caches and crawlers this isn't the real page.
	status_header( 401 );
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );

	// Core advertises the REST root from wp_head/template_redirect, neither of
	// which runs once we exit here — so a locked site looked to API clients like
	// a site with no REST API at all. The WordPress app discovers the API from
	// this header before it has any credentials to offer; without it, it can only
	// report the site as private. The header exposes the endpoint URL, not access
	// to it: vitalis_gate_rest_guard() still refuses every route but the index.
	if ( function_exists( 'rest_url' ) ) {
		header( 'Link: <' . esc_url_raw( rest_url() ) . '>; rel="https://api.w.org/"', false );
	}

	$logo = get_template_directory_uri() . '/assets/long_logo.png';
	?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?> — Private access</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
	<style>
		:root { --bg:#08080a; --panel:#0d0d11; --line:rgba(255,255,255,.1); --line-2:rgba(255,255,255,.16);
		        --text:#f3f2f6; --text-2:#d8d7de; --muted:#8a8992; --faint:#55545e; --accent:#8b5cf6; }
		* { box-sizing:border-box; }
		body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px;
		       background:var(--bg); color:var(--text); font-family:'Space Grotesk',system-ui,sans-serif;
		       -webkit-font-smoothing:antialiased; }
		.card { width:100%; max-width:420px; background:var(--panel); border:1px solid var(--line);
		        border-radius:3px; padding:34px 30px; box-shadow:0 40px 90px rgba(0,0,0,.6); }
		.brand { display:flex; align-items:center; gap:12px; margin-bottom:24px; }
		.brand img { height:24px; width:auto; display:block; }
		h1 { margin:0 0 8px; font-weight:300; font-size:24px; letter-spacing:-.01em; }
		p.lede { margin:0 0 22px; font-size:13.5px; line-height:1.6; color:var(--muted); }
		input[type=password] { width:100%; background:var(--bg); border:1px solid var(--line); color:var(--text);
		        font-family:inherit; font-size:14px; padding:13px 14px; border-radius:2px; outline:none; }
		input[type=password]:focus { border-color:color-mix(in oklab, var(--accent) 70%, white); }
		.err { color:#e8622a; font-size:12px; margin-top:10px; line-height:1.5; }
		.confirm { display:flex; align-items:flex-start; gap:9px; margin-top:18px; padding:14px 15px;
		           background:var(--bg); border:1px solid var(--line); border-radius:2px;
		           font-size:12.5px; line-height:1.55; color:var(--text-2); cursor:pointer; }
		.remember { display:flex; align-items:center; gap:9px; margin-top:14px; font-size:13px; color:var(--muted); cursor:pointer; }
		input[type=checkbox] { accent-color:var(--accent); width:15px; height:15px; flex-shrink:0; margin:2px 0 0; }
		button { width:100%; margin-top:22px; font-family:inherit; font-size:14px; font-weight:500; color:#0b0b0f;
		         background:color-mix(in oklab, var(--accent) 88%, white); border:none; padding:14px;
		         border-radius:2px; cursor:pointer; transition:transform .15s; }
		button:hover { transform:translateY(-1px); }
		.foot { font-family:'Space Mono',monospace; font-size:9.5px; letter-spacing:.16em; color:var(--faint);
		        margin-top:18px; text-align:center; }
		strong { color:var(--text); font-weight:600; }
	</style>
</head>
<body>
	<main class="card">
		<div class="brand"><img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></div>

		<h1>Private access</h1>
		<p class="lede">This site is invite-only. Enter the password you were given to continue.</p>

		<form method="post" action="<?php echo esc_url( vitalis_gate_current_url() ); ?>">
			<?php wp_nonce_field( 'vitalis_gate', 'vitalis_gate_nonce' ); ?>
			<input type="password" name="vitalis_gate_password" placeholder="Site password" autocomplete="off" autofocus>

			<?php if ( $error ) : ?>
				<div class="err"><?php echo esc_html( $error ); ?></div>
			<?php endif; ?>

			<label class="confirm">
				<input type="checkbox" name="vitalis_gate_disclaimer" value="1">
				<span>I confirm I am <strong>21 years of age or older</strong>, and I understand these products are
				<strong>not for human consumption</strong> and are sold strictly for laboratory research use only.</span>
			</label>

			<label class="remember">
				<input type="checkbox" name="vitalis_gate_remember" value="1">
				Remember me on this device
			</label>

			<button type="submit" name="vitalis_gate_submit" value="1">Enter site</button>
		</form>

		<div class="foot">RESEARCH USE ONLY — NOT FOR HUMAN CONSUMPTION</div>
	</main>
</body>
</html>
	<?php
}

/* -------------------------------------------------------------------------
 * The other doors into the same store
 *
 * `template_redirect` only guards page renders. WordPress and WooCommerce
 * answer on three more channels that never reach it, and each one used to walk
 * straight past the gate:
 *
 *   /wp-json/…            the REST API, including WooCommerce's Store API,
 *                         which will read the catalog *and place orders*
 *   /?wc-ajax=…           WC_AJAX, hooked to template_redirect at priority 0 —
 *                         ahead of the gate at priority 1
 *   /wp-admin/admin-ajax  WooCommerce's front-end AJAX actions
 *
 * All three are now held to the same unlock cookie as a normal page view.
 * ---------------------------------------------------------------------- */

/**
 * REST routes an unlocked-out visitor may still reach.
 *
 * Empty by design: this storefront's cart, checkout and account pages are the
 * classic WooCommerce shortcodes, which use admin-ajax and `wc-ajax` rather
 * than REST, so nothing on the front end needs an anonymous REST route. Add
 * prefixes here (e.g. '/wc/store/v1/products') only if you switch a page over
 * to the block versions, which do talk to the Store API.
 *
 * @return string[] Route prefixes.
 */
function vitalis_gate_rest_public_routes() {
	return (array) apply_filters( 'vitalis_gate_rest_public_routes', array() );
}

/**
 * Routes an anonymous caller may reach, matched *exactly* rather than by prefix.
 *
 * Just the API index. Anything that authenticates with an Application Password
 * — the WordPress mobile app included — has to read `/wp-json/` before it can
 * log in, because that response is where it finds the authorization endpoint.
 * Refusing it is why the app reports the site as password-protected and stops:
 * it never gets far enough to offer credentials.
 *
 * The index carries the site title, description, URL and the list of route
 * names. It carries no posts, products, prices, customers or orders.
 *
 * This is deliberately separate from vitalis_gate_rest_public_routes(), which
 * matches on prefix. The index cannot go there: its route is '/', and '/' is a
 * prefix of every route in the API, so adding it would open the whole surface
 * — /wc/store/v1/products included, which publishes the catalog the gate
 * exists to keep private.
 *
 * @return string[] Exact route names.
 */
function vitalis_gate_rest_public_exact_routes() {
	return (array) apply_filters( 'vitalis_gate_rest_public_exact_routes', array( '/' ) );
}

/** The route being dispatched, as set by rest_api_loaded() on parse_request. */
function vitalis_gate_current_rest_route() {
	if ( ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
		return '/' . ltrim( (string) $GLOBALS['wp']->query_vars['rest_route'], '/' );
	}
	return '';
}

/**
 * Require the gate for anonymous REST requests.
 *
 * Logged-in users pass: that covers wp-admin, WooCommerce Analytics, the block
 * editor and any WooCommerce API key, all of which resolve a user before this
 * filter runs. Core's own cookie/nonce check still runs afterwards at priority
 * 100, so this only ever adds a requirement.
 *
 * @param WP_Error|null|true $result Result of any earlier authentication check.
 * @return WP_Error|null|true
 */
function vitalis_gate_rest_guard( $result ) {
	// Someone has already decided this request's fate — don't overrule them.
	if ( ! empty( $result ) ) {
		return $result;
	}
	if ( is_user_logged_in() || vitalis_gate_is_privileged() ) {
		return $result;
	}
	if ( vitalis_gate_is_unlocked() ) {
		return $result;
	}

	$route = vitalis_gate_current_rest_route();

	// Exact matches first. An empty route means we couldn't tell what was being
	// dispatched, which is never a match.
	if ( '' !== $route && in_array( $route, vitalis_gate_rest_public_exact_routes(), true ) ) {
		return $result;
	}

	foreach ( vitalis_gate_rest_public_routes() as $prefix ) {
		if ( '' !== $prefix && 0 === strpos( $route, $prefix ) ) {
			return $result;
		}
	}

	return new WP_Error(
		'vitalis_gate_locked',
		__( 'This site is private. Enter the site password to continue.', 'vitalis' ),
		array( 'status' => 401 )
	);
}
add_filter( 'rest_authentication_errors', 'vitalis_gate_rest_guard', 10 );

/**
 * Unregister the Store API checkout route entirely.
 *
 * The gate's password confirmation and the "referred by" field are validated on
 * `woocommerce_checkout_process` / `woocommerce_after_checkout_validation`,
 * which only fire inside WC_Checkout::process_checkout() — the classic path.
 * The Store API runs its own pipeline, so POST /wc/store/v1/checkout created
 * orders with no password, no referrer and an auto-generated account.
 *
 * This store checks out through the `[woocommerce_checkout]` shortcode, so the
 * route has no legitimate caller. If you ever move to the checkout *block*,
 * return false from `vitalis_gate_block_store_api_checkout` — the order guard
 * below then becomes the enforcement point, and your block will need to send
 * the password and referrer in `extensions.vitalis`.
 */
function vitalis_gate_unregister_store_checkout( $endpoints ) {
	if ( ! apply_filters( 'vitalis_gate_block_store_api_checkout', true ) ) {
		return $endpoints;
	}

	foreach ( array_keys( $endpoints ) as $route ) {
		if ( preg_match( '#^/wc/store(/v\d+)?/checkout#', $route ) ) {
			unset( $endpoints[ $route ] );
		}
	}

	return $endpoints;
}
add_filter( 'rest_endpoints', 'vitalis_gate_unregister_store_checkout' );

/**
 * Second layer: if a Store API order is ever built, hold it to the same rules
 * as a classic one. Unreachable while the route above is unregistered.
 *
 * @param WC_Order        $order   Draft order.
 * @param WP_REST_Request $request The checkout request.
 */
function vitalis_gate_store_api_guard( $order, $request ) {
	$exception = '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException';
	if ( ! class_exists( $exception ) ) {
		return;
	}

	$ext      = isset( $request['extensions']['vitalis'] ) ? (array) $request['extensions']['vitalis'] : array();
	$password = isset( $ext['password'] ) ? (string) $ext['password'] : '';
	$referral = isset( $ext['referral'] ) ? vitalis_referral_clean( $ext['referral'] ) : '';

	if ( ! is_user_logged_in() ) {
		throw new $exception( 'vitalis_account_required', __( 'You must be signed in to place an order.', 'vitalis' ), 403 );
	}
	if ( ! vitalis_gate_check( $password ) ) {
		throw new $exception( 'vitalis_gate_password', __( 'Incorrect site password.', 'vitalis' ), 403 );
	}
	if ( '' === $referral || false === strpos( $referral, ' ' ) ) {
		throw new $exception( 'vitalis_referral_required', __( 'Please enter the full name of the person who referred you.', 'vitalis' ), 403 );
	}

	$order->update_meta_data( VITALIS_REFERRAL_META, $referral );
}
add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'vitalis_gate_store_api_guard', 10, 2 );

/**
 * Require the gate for the store's AJAX endpoints.
 *
 * Runs on `wp_loaded`, which fires for both `/?wc-ajax=…` (before WC_AJAX picks
 * it up on template_redirect) and `/wp-admin/admin-ajax.php` (which boots
 * WordPress the same way). Only store actions are guarded — core's own
 * anonymous AJAX is left alone so nothing unrelated breaks.
 */
function vitalis_gate_guard_ajax() {
	$wc_ajax = isset( $_GET['wc-ajax'] ) ? sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ) : '';
	$action  = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

	$is_store_ajax = '' !== $wc_ajax
		|| ( wp_doing_ajax() && '' !== $action && preg_match( '/^(wc|woocommerce)[-_]/', $action ) );

	if ( ! $is_store_ajax ) {
		return;
	}
	if ( vitalis_gate_is_privileged() || vitalis_gate_is_unlocked() ) {
		return;
	}

	status_header( 401 );
	nocache_headers();
	wp_send_json(
		array( 'error' => __( 'This site is private. Enter the site password to continue.', 'vitalis' ) ),
		401
	);
}
add_action( 'wp_loaded', 'vitalis_gate_guard_ajax', 0 );

/* -------------------------------------------------------------------------
 * Checkout: require the same password again, per order
 * ---------------------------------------------------------------------- */

/** The field, rendered just above the Place Order button. */
function vitalis_gate_checkout_field() {
	?>
	<div class="vitalis-order-gate" style="margin:18px 0; padding:16px 18px; background:var(--bg,#08080a); border:1px solid var(--line,rgba(255,255,255,.16)); border-radius:2px;">
		<label for="vitalis_order_password" style="display:block; font-family:'Space Mono',monospace; font-size:10px; letter-spacing:.2em; color:#8a8992; margin-bottom:10px;">
			CONFIRM YOUR ORDER
		</label>
		<p style="margin:0 0 10px; font-size:12.5px; line-height:1.5; color:#8a8992;">
			For security, enter the same site password you used to enter the site.
		</p>
		<input type="password" id="vitalis_order_password" name="vitalis_order_password" autocomplete="off"
		       placeholder="Site password"
		       style="width:100%; background:#08080a; border:1px solid rgba(255,255,255,.16); color:#f3f2f6; font-family:inherit; font-size:14px; padding:12px 13px; border-radius:2px; outline:none;">
	</div>
	<?php
}
add_action( 'woocommerce_review_order_before_submit', 'vitalis_gate_checkout_field' );

/**
 * Server-side check — the order is refused unless the password matches.
 *
 * Shares the front gate's attempt counter. Without it this field was an
 * unmetered oracle for the site password: the gate itself stops after ten
 * wrong guesses, but checkout would take them all day.
 */
function vitalis_gate_validate_checkout() {
	if ( vitalis_gate_tries() >= VITALIS_GATE_MAX_TRIES ) {
		wc_add_notice( 'Too many incorrect password attempts. Please wait 15 minutes and try again.', 'error' );
		return;
	}

	$candidate = isset( $_POST['vitalis_order_password'] ) ? wp_unslash( $_POST['vitalis_order_password'] ) : '';

	if ( '' === $candidate ) {
		wc_add_notice( 'Please enter your site password to confirm this order.', 'error' );
		return;
	}
	if ( ! vitalis_gate_check( $candidate ) ) {
		vitalis_gate_record_try();
		wc_add_notice( 'Incorrect password — please check with your referrer.', 'error' );
		return;
	}

	vitalis_gate_clear_tries();
}
add_action( 'woocommerce_after_checkout_validation', 'vitalis_gate_validate_checkout' );

/* -------------------------------------------------------------------------
 * Settings → Vitalis Gate
 * ---------------------------------------------------------------------- */

/**
 * Say so, loudly and on every admin screen, when the gate isn't protecting
 * anything: either no password has been set, or it's still the one that shipped
 * in the repository.
 */
function vitalis_gate_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = admin_url( 'options-general.php?page=vitalis-gate' );

	if ( ! vitalis_gate_is_configured() ) {
		printf(
			'<div class="notice notice-error"><p><strong>Vitalis Gate:</strong> no site password is set, so the storefront is closed to everyone. <a href="%s">Set one now</a>.</p></div>',
			esc_url( $settings )
		);
		return;
	}

	if ( vitalis_gate_is_legacy_default() ) {
		printf(
			'<div class="notice notice-error"><p><strong>Vitalis Gate:</strong> this site is still using the password the theme used to ship with. It is published in the repository, so the gate and the checkout confirmation currently stop nobody. <a href="%s">Change it now</a>.</p></div>',
			esc_url( $settings )
		);
	}
}
add_action( 'admin_notices', 'vitalis_gate_admin_notice' );

function vitalis_gate_settings_page() {
	add_options_page(
		'Vitalis Gate',
		'Vitalis Gate',
		'manage_options',
		'vitalis-gate',
		'vitalis_gate_settings_render'
	);
}
add_action( 'admin_menu', 'vitalis_gate_settings_page' );

function vitalis_gate_settings_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notice = '';
	if ( isset( $_POST['vitalis_gate_new'] ) && check_admin_referer( 'vitalis_gate_settings' ) ) {
		$new = wp_unslash( $_POST['vitalis_gate_new'] );
		if ( strlen( $new ) < VITALIS_GATE_MIN_LEN ) {
			$notice = '<div class="notice notice-error"><p>Password must be at least '
				. (int) VITALIS_GATE_MIN_LEN . ' characters.</p></div>';
		} elseif ( VITALIS_GATE_LEGACY_DEFAULT === $new ) {
			$notice = '<div class="notice notice-error"><p>That is the password this theme used to ship with — it is published in the repository. Choose a different one.</p></div>';
		} else {
			vitalis_gate_set_password( $new );
			$notice = '<div class="notice notice-success"><p>Site password updated. Everyone will be asked for the new one on their next visit.</p></div>';
		}
	}
	?>
	<div class="wrap">
		<h1>Vitalis Gate</h1>
		<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<p>
			This single password guards the whole storefront: visitors must enter it (with the 21+ /
			not-for-human-consumption confirmation) before any page loads, and again on the checkout page
			before an order can be placed.
		</p>
		<p>
			It is stored only as a hash and checked on the server, so it never appears in the page source.
			Changing it signs everyone out of the gate immediately.
		</p>
		<form method="post">
			<?php wp_nonce_field( 'vitalis_gate_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="vitalis_gate_new">New site password</label></th>
					<td>
						<input name="vitalis_gate_new" id="vitalis_gate_new" type="text" class="regular-text" autocomplete="off">
						<p class="description">
							At least <?php echo (int) VITALIS_GATE_MIN_LEN; ?> characters. Share it with referred
							customers; admins are never asked for it. There is no default — until one is set here,
							the storefront stays closed.
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Update password' ); ?>
		</form>
	</div>
	<?php
}
