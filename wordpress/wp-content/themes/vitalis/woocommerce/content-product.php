<?php
/**
 * Product card in loops (shop + [products]). Rebuilt to match the original
 * "Vitalis catalog" cards: vial with cap-colored glow, dose badge, purity +
 * live stock, price, and a quantity + ADD control.
 *
 * @package vitalis
 */

defined( 'ABSPATH' ) || exit;

global $product;
if ( empty( $product ) || ! $product->is_visible() ) {
	return;
}

$pid     = $product->get_id();
$cap     = get_post_meta( $pid, '_vitalis_cap', true );
$cap     = $cap ? $cap : '#8b5cf6';
$dose    = get_post_meta( $pid, '_vitalis_dose', true );
$purity  = get_post_meta( $pid, '_vitalis_purity', true );
$purity  = $purity ? $purity : '99.9%';
$sub     = get_post_meta( $pid, '_vitalis_sub', true );
$link    = get_permalink( $pid );

// Live stock text, mirroring the old "In stock: N" / "Only N left".
$stock_txt = '';
if ( $product->managing_stock() ) {
	$qty = (int) $product->get_stock_quantity();
	if ( $qty <= 0 ) {
		$stock_txt = 'Out of stock';
	} elseif ( $qty <= 3 ) {
		$stock_txt = 'Only ' . $qty . ' left';
	} else {
		$stock_txt = 'In stock: ' . $qty;
	}
} elseif ( ! $product->is_in_stock() ) {
	$stock_txt = 'Out of stock';
}
$max = ( $product->managing_stock() && $product->get_stock_quantity() > 0 ) ? (int) $product->get_stock_quantity() : 10;
?>
<li <?php wc_product_class( 'vl-card', $product ); ?> style="--cap: <?php echo esc_attr( $cap ); ?>;">
	<a class="vl-card__media" href="<?php echo esc_url( $link ); ?>">
		<?php
		if ( has_post_thumbnail( $pid ) ) {
			echo get_the_post_thumbnail( $pid, 'large', array( 'class' => 'vl-vial', 'alt' => esc_attr( $product->get_name() ) ) );
		} else {
			echo wc_placeholder_img( 'large' );
		}
		?>
	</a>

	<div class="vl-card__body">
		<div class="vl-card__titlerow">
			<h3 class="vl-card__title"><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></h3>
			<?php if ( $dose ) : ?><span class="dose-badge"><?php echo esc_html( $dose ); ?></span><?php endif; ?>
		</div>

		<?php if ( $sub ) : ?><p class="vl-card__sub"><?php echo esc_html( $sub ); ?></p><?php endif; ?>

		<div class="vl-card__meta">
			<span class="mono">HPLC <?php echo esc_html( $purity ); ?></span>
			<?php if ( $stock_txt ) : ?><span class="mono vl-stock"><?php echo esc_html( $stock_txt ); ?></span><?php endif; ?>
		</div>

		<div class="vl-card__buy">
			<span class="vl-price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
			<?php if ( $product->is_in_stock() && $product->is_purchasable() ) : ?>
				<form class="vl-add" method="post" action="">
					<select name="quantity" aria-label="Quantity">
						<?php for ( $i = 1; $i <= min( $max, 10 ); $i++ ) : ?>
							<option value="<?php echo esc_attr( $i ); ?>"><?php echo esc_html( $i ); ?></option>
						<?php endfor; ?>
					</select>
					<input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $pid ); ?>">
					<button type="submit" class="btn vl-add__btn">Add +</button>
				</form>
			<?php else : ?>
				<span class="vl-soldout mono">Sold out</span>
			<?php endif; ?>
		</div>
	</div>
</li>
