<?php
/**
 * WordPress configuration — Vitalis Labs (production / cPanel).
 *
 * Lives in dont-push/ because it holds live credentials and salts: this file is
 * gitignored and must stay that way (see DEPLOY-CPANEL.md). Upload it to the
 * docroot (e.g. ~/public_html/wp-config.php) via cPanel File Manager or SFTP —
 * never through the git deployment.
 *
 * Before uploading, fill in the four DB_* values from
 * cPanel → Databases → MySQL® Databases. The salts below are already unique to
 * this install; if you replace the file on a running site, keep the existing
 * salts or every logged-in customer is signed out.
 *
 * @package WordPress
 */

// ** Database settings — cPanel → MySQL® Databases ** //

/** Database name (cPanel prefixes it with your account name, e.g. vitalis_wp). */
define( 'DB_NAME', 'vitalisl_wpsawdh' );

/** Database username (also account-prefixed, e.g. vitalis_wpuser). */
define( 'DB_USER', 'vitalisl_admin' );

/** Database password. */
define( 'DB_PASSWORD', 'wa8Eb1Anyb0Yup92JDVa' );

/** Database hostname. On cPanel this is localhost, not a container name. */
define( 'DB_HOST', 'localhost' );

/** Database charset. utf8mb4 — matches the Docker install. */
define( 'DB_CHARSET', 'utf8mb4' );

define( 'WP_HOME',    'https://vitalislabs.us/wordpress' );

define( 'WP_SITEURL', 'https://vitalislabs.us/wordpress' );


/** Database collation. Leave empty unless your host requires otherwise. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Generated for this install. Changing any of them invalidates all cookies —
 * which includes the Vitalis gate unlock cookie, so everyone re-enters the
 * site password.
 */
define( 'AUTH_KEY',         '~(m(m4c{fmB]S$w*-FX[fu?m(bf;4pk/O~2}UeTz-`I]ft.Fmb#{}RWj5]*mmW@_' );
define( 'SECURE_AUTH_KEY',  'j>^t^vv0JJBl<P5Myyq-iTNY_CO^sdXjUtwCe-N6DgviGl3ngx|93YS)*DXY-]W9' );
define( 'LOGGED_IN_KEY',    'W:fgS0pHjHtd^c##?6MGvLFhR7ftJ1jUQ?n&Y&X9D`q5Fg5_RtLUauVR[U|g)Bs#' );
define( 'NONCE_KEY',        '&,Avh5CO5jK7Xh&Y%[87DnR|jr+$t3,uu>K*^F3/,#8>ZAtjGH0TiU9Yp^v=/#XV' );
define( 'AUTH_SALT',        'Qw5iSK?x6XmY9o*!0:P7/^}go<P[PVzTK)JLfU=+?R3&Mo6Da7i?Y!WN>kb^b}Zu' );
define( 'SECURE_AUTH_SALT', '^lOMZqvT)>+2/g1touR*n3Y3Mg{o^WR4)pHt{T#FK1]~N9PReteu_jkh;FEE#xI&' );
define( 'LOGGED_IN_SALT',   'lG8P)/pGVVioV>4:FLPRPr^NVx5+jfaha?2D!RbEoqS?hhKj&r4XaPpB5_*2w!=9' );
define( 'NONCE_SALT',       'caDcDy}fwyGimhVw~;,T#J^vwg?QrE@1#v}hEQKDP/}~TV)ApFr#U%lq=C%4*k5v' );
/**#@-*/

/**
 * WordPress database table prefix.
 *
 * Must match the tables that already exist. cPanel's WordPress installer often
 * randomizes this (e.g. wp_a1b2c3_) — check phpMyAdmin and change this to match
 * before uploading, or the site will act uninstalled.
 */
$table_prefix = 'wp_';

/* Add any custom values between this line and the "stop editing" line. */

/**
 * Debugging — off in production.
 *
 * Docker turns WP_DEBUG on via WORDPRESS_CONFIG_EXTRA. Here it stays false:
 * wp-content/debug.log is publicly readable on shared hosting.
 */
define( 'WP_DEBUG',         false );
define( 'WP_DEBUG_LOG',     false );
define( 'WP_DEBUG_DISPLAY', false );
@ini_set( 'display_errors', 0 );

/**
 * Outgoing mail — read by the theme's inc/mail.php.
 *
 * Without these, order email sends as wordpress@your-domain.com. Venmo is a
 * manual gateway, so the payment-instructions email *is* the checkout: place a
 * test order after deploying and confirm it arrives.
 */
define( 'VITALIS_MAIL_FROM',      'orders@vitalislabs.com' );
define( 'VITALIS_MAIL_FROM_NAME', 'Vitalis Labs' );

/**
 * External SMTP (optional). Uncomment and fill in to route mail through a
 * provider instead of the server's sendmail. Leave commented to use sendmail.
 * VITALIS_SMTP_SECURE is 'tls' or 'ssl'.
 */
// define( 'VITALIS_SMTP_HOST',   'smtp.provider.com' );
// define( 'VITALIS_SMTP_PORT',   587 );
// define( 'VITALIS_SMTP_USER',   'REPLACE_SMTP_USER' );
// define( 'VITALIS_SMTP_PASS',   'REPLACE_SMTP_PASSWORD' );
// define( 'VITALIS_SMTP_SECURE', 'tls' );

/**
 * HTTPS. The gate unlock cookie should only travel over TLS, so force the admin
 * and login over HTTPS once AutoSSL has issued a cert.
 */
define( 'FORCE_SSL_ADMIN', true );

/* If a proxy/CDN terminates TLS, WordPress otherwise sees plain HTTP. */
if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
	$_SERVER['HTTPS'] = 'on';
}

/**
 * Site URLs. Left commented on purpose — the installer already stored these in
 * the database, and defining them here makes Settings → General read-only.
 * Uncomment only if you move domains and need to override a stale value.
 */
// define( 'WP_HOME',    'https://vitalislabs.com' );
// define( 'WP_SITEURL', 'https://vitalislabs.com' );

/**
 * Updates and file access.
 *
 * The vitalis theme is deployed from git, so nothing should be edited from
 * wp-admin — a wp-admin edit would be silently overwritten on the next deploy.
 * Core minor/security updates stay automatic; WooCommerce updates stay manual so
 * a release can't change checkout behind your back.
 */
define( 'DISALLOW_FILE_EDIT',  true );
define( 'WP_AUTO_UPDATE_CORE', 'minor' );

/** Direct filesystem writes — avoids cPanel prompting for FTP credentials. */
define( 'FS_METHOD', 'direct' );

/** WooCommerce admin screens are memory-hungry; 256M is the usual floor. */
define( 'WP_MEMORY_LIMIT',     '256M' );
define( 'WP_MAX_MEMORY_LIMIT', '512M' );

/** Keep post revisions and trash bounded on shared hosting. */
define( 'WP_POST_REVISIONS', 10 );
define( 'EMPTY_TRASH_DAYS',  30 );

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
