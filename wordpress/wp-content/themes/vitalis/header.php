<?php
/**
 * Header template.
 *
 * @package vitalis
 */
$vitalis_logo = get_template_directory_uri() . '/assets/long_logo.png';
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<!-- Scrolling compliance marquee (matches the original site). -->
<div class="marquee" aria-hidden="true">
	<div class="marquee__track">
		<?php
		$vitalis_ticks = array(
			'RESEARCH USE ONLY — NOT FOR HUMAN CONSUMPTION',
			'MADE &amp; TESTED IN THE USA',
			'99%+ VERIFIED PURITY',
			'COA WITH EVERY BATCH',
		);
		// Repeat enough times for a seamless loop across wide screens.
		for ( $r = 0; $r < 3; $r++ ) {
			foreach ( $vitalis_ticks as $t ) {
				echo '<span class="marquee__item">' . $t . '</span><span class="marquee__sep">&#9670;</span>';
			}
		}
		?>
	</div>
</div>

<header class="site-header">
	<div class="vitalis-wrap bar">
		<a class="site-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Vitalis Labs home">
			<img src="<?php echo esc_url( $vitalis_logo ); ?>" alt="Vitalis Labs" class="brand-logo">
		</a>

		<nav class="main-nav" aria-label="Primary">
			<?php vitalis_nav_links(); ?>
		</nav>

		<div class="header-actions">
			<?php if ( function_exists( 'wc_get_cart_url' ) ) : ?>
				<div class="header-cart">
					<a href="<?php echo esc_url( wc_get_cart_url() ); ?>">
						<span class="header-cart__label">Cart</span> <span class="cart-count">(<?php echo esc_html( WC()->cart ? WC()->cart->get_cart_contents_count() : 0 ); ?>)</span>
					</a>
				</div>
			<?php endif; ?>

			<button type="button" class="nav-toggle" id="vitalis-nav-toggle"
				aria-expanded="false" aria-controls="vitalis-mobile-nav">
				<span class="nav-toggle__box" aria-hidden="true">
					<span class="nav-toggle__bar"></span>
					<span class="nav-toggle__bar"></span>
					<span class="nav-toggle__bar"></span>
				</span>
				<span class="screen-reader-text"><?php esc_html_e( 'Menu', 'vitalis' ); ?></span>
			</button>
		</div>
	</div>

	<!--
		Mobile drawer. Hidden by default and opened by assets/nav.js; the noscript
		block below reverses that so the links stay reachable without JavaScript
		(the desktop nav is display:none at this width).
	-->
	<div class="mobile-nav" id="vitalis-mobile-nav" hidden>
		<nav class="vitalis-wrap" aria-label="<?php esc_attr_e( 'Mobile', 'vitalis' ); ?>">
			<?php vitalis_nav_links(); ?>
		</nav>
	</div>
	<noscript>
		<style>
			.mobile-nav[hidden] { display: block !important; }
			.nav-toggle { display: none !important; }
		</style>
	</noscript>
</header>
