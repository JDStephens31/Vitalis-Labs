<?php
/**
 * Vitalis Labs — outgoing mail.
 *
 * The WordPress Docker image has no sendmail binary, so wp_mail() fails
 * outright and order emails never leave the container. This routes mail through
 * SMTP instead, configured from constants defined in docker-compose.yml
 * (WORDPRESS_CONFIG_EXTRA), which read from .env:
 *
 *   VITALIS_SMTP_HOST / _PORT / _USER / _PASS / _SECURE
 *   VITALIS_MAIL_FROM / _FROM_NAME
 *
 * Locally those point at the `mailpit` service, so everything the site sends is
 * readable at http://localhost:8025 instead of going to real inboxes. For
 * production, point them at a real SMTP provider — nothing here changes.
 *
 * @package vitalis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send through SMTP when a host is configured. Without one, WordPress keeps its
 * default behaviour (and keeps failing loudly in the log, which is correct).
 */
function vitalis_mail_smtp( $phpmailer ) {
	if ( ! defined( 'VITALIS_SMTP_HOST' ) || ! VITALIS_SMTP_HOST ) {
		return;
	}

	$phpmailer->isSMTP();
	$phpmailer->Host = VITALIS_SMTP_HOST;
	$phpmailer->Port = defined( 'VITALIS_SMTP_PORT' ) ? (int) VITALIS_SMTP_PORT : 1025;

	// Mailpit takes anonymous mail; a real provider needs credentials.
	if ( defined( 'VITALIS_SMTP_USER' ) && VITALIS_SMTP_USER ) {
		$phpmailer->SMTPAuth = true;
		$phpmailer->Username = VITALIS_SMTP_USER;
		$phpmailer->Password = defined( 'VITALIS_SMTP_PASS' ) ? VITALIS_SMTP_PASS : '';
	} else {
		$phpmailer->SMTPAuth = false;
	}

	if ( defined( 'VITALIS_SMTP_SECURE' ) && VITALIS_SMTP_SECURE ) {
		$phpmailer->SMTPSecure = VITALIS_SMTP_SECURE; // 'tls' or 'ssl'
	} else {
		$phpmailer->SMTPSecure  = '';
		$phpmailer->SMTPAutoTLS = false;
	}
}
add_action( 'phpmailer_init', 'vitalis_mail_smtp' );

/**
 * WordPress defaults to wordpress@localhost, which most mail servers reject
 * outright as an invalid address.
 */
function vitalis_mail_from( $from ) {
	return ( defined( 'VITALIS_MAIL_FROM' ) && VITALIS_MAIL_FROM ) ? VITALIS_MAIL_FROM : $from;
}
add_filter( 'wp_mail_from', 'vitalis_mail_from' );

function vitalis_mail_from_name( $name ) {
	return ( defined( 'VITALIS_MAIL_FROM_NAME' ) && VITALIS_MAIL_FROM_NAME ) ? VITALIS_MAIL_FROM_NAME : $name;
}
add_filter( 'wp_mail_from_name', 'vitalis_mail_from_name' );

/**
 * Record why a send failed. Silent email failures are the single most annoying
 * thing to debug on a store, and this is the difference between "no email
 * arrived" and a line in wp-content/debug.log saying exactly why.
 */
function vitalis_mail_log_failure( $error ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[vitalis] wp_mail failed: ' . $error->get_error_message() );
	}
}
add_action( 'wp_mail_failed', 'vitalis_mail_log_failure' );
