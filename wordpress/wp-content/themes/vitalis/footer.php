<?php
/**
 * Footer template.
 *
 * @package vitalis
 */
?>
<footer class="site-footer">
	<div class="vitalis-wrap">
		<div class="cols">
			<div>
				<div class="brand-text" style="font-weight:700;letter-spacing:.14em;">VITALIS LABS</div>
				<p style="max-width:320px;margin:10px 0 0;">Research-grade peptides, synthesized and tested in the USA.</p>
			</div>
			<div>
				<?php
				if ( function_exists( 'wc_get_page_id' ) ) {
					echo '<div style="display:flex;flex-direction:column;gap:8px;">';
					echo '<a href="' . esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ) . '">Shop</a>';
					echo '<a href="' . esc_url( get_permalink( wc_get_page_id( 'cart' ) ) ) . '">Cart</a>';
					echo '<a href="' . esc_url( get_permalink( wc_get_page_id( 'myaccount' ) ) ) . '">My Account &amp; Orders</a>';
					echo '</div>';
				}
				?>
			</div>
		</div>
		<div class="disclaimer">
			All products sold by Vitalis Labs are chemical reagents intended exclusively for laboratory
			research and development use only. They are <strong>not for human or veterinary consumption</strong>
			and are not drugs, foods, cosmetics, or medical devices.
			<br>&copy; <?php echo esc_html( date( 'Y' ) ); ?> Vitalis Labs. All rights reserved.
		</div>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
