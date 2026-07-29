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
			<?php
			if ( has_nav_menu( 'primary' ) ) {
				wp_nav_menu( array(
					'theme_location' => 'primary',
					'container'      => false,
					'depth'          => 1,
					'fallback_cb'    => false,
				) );
			} else {
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
			?>
		</nav>

		<?php if ( function_exists( 'wc_get_cart_url' ) ) : ?>
			<div class="header-cart">
				<a href="<?php echo esc_url( wc_get_cart_url() ); ?>">
					Cart <span class="cart-count">(<?php echo esc_html( WC()->cart ? WC()->cart->get_cart_contents_count() : 0 ); ?>)</span>
				</a>
			</div>
		<?php endif; ?>
	</div>
</header>
