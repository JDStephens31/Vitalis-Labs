<?php
/**
 * Front page: marquee (in header) + split hero + stats + catalog.
 * Rebuilt to match the original static site.
 *
 * @package vitalis
 */
$assets   = get_template_directory_uri() . '/assets';
$shop_url = function_exists( 'wc_get_page_id' ) ? get_permalink( wc_get_page_id( 'shop' ) ) : home_url( '/' );

get_header(); ?>

<section class="hero">
	<div class="vitalis-wrap hero__grid">
		<div class="hero__copy">
			<span class="eyebrow-pill"><span class="diamond">&#9670;</span> Made &amp; Tested in the USA</span>
			<h1 class="hero__title">The <span class="accent">standard</span><br>for research<br>peptides.</h1>
			<p class="hero__lede">Synthesized and lyophilized in the United States, then independently
				verified for purity and identity — a certificate of analysis with every batch.</p>
			<div class="hero__actions">
				<a class="btn" href="<?php echo esc_url( $shop_url ); ?>">Explore the catalog</a>
				<a class="btn ghost" href="<?php echo esc_url( $shop_url ); ?>">See the testing</a>
			</div>
		</div>

		<div class="hero__art">
			<span class="bracket bracket--tl"></span>
			<span class="bracket bracket--br"></span>
			<div class="vial-trio">
				<img class="vial vial--sm" style="--glow:#37a24a" src="<?php echo esc_url( $assets ); ?>/bpc-157.png" alt="BPC-157">
				<img class="vial vial--lg" style="--glow:#8b5cf6" src="<?php echo esc_url( $assets ); ?>/ghk-cu.png" alt="GHK-Cu">
				<img class="vial vial--sm" style="--glow:#e8622a" src="<?php echo esc_url( $assets ); ?>/reta-30.png" alt="Retatrutide">
			</div>
		</div>
	</div>
</section>

<!-- Stats bar -->
<section class="statbar">
	<div class="vitalis-wrap statbar__grid">
		<div class="stat"><div class="stat__num">99%+</div><div class="stat__label">Verified Purity</div></div>
		<div class="stat"><div class="stat__num">100%</div><div class="stat__label">Batch Tested</div></div>
		<div class="stat"><div class="stat__num">48H</div><div class="stat__label">Cold-Chain Dispatch</div></div>
		<div class="stat"><div class="stat__num accent">USA</div><div class="stat__label">Synthesized &amp; Tested</div></div>
	</div>
</section>

<main class="site-main">
	<div class="vitalis-wrap">
		<div class="catalog-head">
			<div>
				<div class="section-eyebrow">01 — Catalog</div>
				<h2 class="section-title">The Vitalis catalog</h2>
			</div>
			<p class="catalog-note">All products supplied for laboratory research use only.
				Certificate of analysis included.</p>
		</div>
		<?php
		if ( function_exists( 'wc_get_products' ) ) {
			echo do_shortcode( '[products limit="8" columns="4" orderby="menu_order" order="ASC" visibility="visible"]' );
		} else {
			echo '<p style="color:var(--muted);">Install &amp; activate WooCommerce to display the catalog.</p>';
		}
		?>
	</div>
</main>

<?php get_footer(); ?>
