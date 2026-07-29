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
 * view-source or curl can't walk around it.
 *
 * Change the password at: wp-admin → Settings → Vitalis Gate.
 *
 * @package vitalis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VITALIS_GATE_OPTION  = 'vitalis_gate_hash';
const VITALIS_GATE_COOKIE  = 'vitalis_gate';
const VITALIS_GATE_DEFAULT = 'V1T4L1S';        // seeded once; change it in Settings
const VITALIS_GATE_MAX_TRIES = 10;             // per IP, per window
const VITALIS_GATE_WINDOW    = 900;            // 15 minutes

/* -------------------------------------------------------------------------
 * Password storage
 * ---------------------------------------------------------------------- */

/**
 * The stored hash, seeding the default on first run so the site is never
 * accidentally wide open.
 */
function vitalis_gate_hash() {
	$hash = get_option( VITALIS_GATE_OPTION );
	if ( ! $hash ) {
		$hash = wp_hash_password( VITALIS_GATE_DEFAULT );
		update_option( VITALIS_GATE_OPTION, $hash, false );
	}
	return $hash;
}

/** Replace the site password. Invalidates every existing unlock cookie. */
function vitalis_gate_set_password( $plain ) {
	update_option( VITALIS_GATE_OPTION, wp_hash_password( $plain ), false );
}

/** Constant-time-ish check of a candidate against the stored hash. */
function vitalis_gate_check( $candidate ) {
	return wp_check_password( $candidate, vitalis_gate_hash() );
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
 * Requests that must never be gated, or the site becomes unadministrable:
 * wp-admin, the login screen, AJAX, REST, cron, and the CLI.
 */
function vitalis_gate_is_exempt() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return true;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}
	// Admins are never locked out of their own store.
	if ( current_user_can( 'manage_options' ) ) {
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

/** Server-side check — the order is refused unless the password matches. */
function vitalis_gate_validate_checkout() {
	$candidate = isset( $_POST['vitalis_order_password'] ) ? wp_unslash( $_POST['vitalis_order_password'] ) : '';

	if ( '' === $candidate ) {
		wc_add_notice( 'Please enter your site password to confirm this order.', 'error' );
		return;
	}
	if ( ! vitalis_gate_check( $candidate ) ) {
		wc_add_notice( 'Incorrect password — please check with your referrer.', 'error' );
	}
}
add_action( 'woocommerce_after_checkout_validation', 'vitalis_gate_validate_checkout' );

/* -------------------------------------------------------------------------
 * Settings → Vitalis Gate
 * ---------------------------------------------------------------------- */

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
		if ( strlen( $new ) < 4 ) {
			$notice = '<div class="notice notice-error"><p>Password must be at least 4 characters.</p></div>';
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
						<p class="description">Share this with referred customers. Admins are never asked for it.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Update password' ); ?>
		</form>
	</div>
	<?php
}
