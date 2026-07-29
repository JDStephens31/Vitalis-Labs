<?php
/**
 * Vitalis Labs — Venmo payment method.
 *
 * Replaces WooCommerce's built-in offline gateways (bank transfer / check /
 * cash on delivery) with a single manual Venmo method, matching how the static
 * site took payment.
 *
 * No money moves through the site. Placing an order puts it **on hold** and
 * emails the customer everything needed to pay: the amount, their order number,
 * the Venmo handle, the QR code, a direct payment link, and instructions. You
 * mark the order Processing once the matching Venmo payment lands.
 *
 * Settings: wp-admin → WooCommerce → Settings → Payments → Venmo.
 *
 * @package vitalis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * Gateway
 * ---------------------------------------------------------------------- */

/**
 * Declare the gateway class.
 *
 * Called from the `woocommerce_payment_gateways` filter rather than on
 * `plugins_loaded`: a theme's functions.php is loaded during
 * `after_setup_theme`, by which point `plugins_loaded` has already fired, so
 * hooking it there would never run. Inside the filter, WC_Payment_Gateway is
 * guaranteed to exist.
 */
function vitalis_venmo_init_gateway() {

	if ( ! class_exists( 'WC_Payment_Gateway' ) || class_exists( 'Vitalis_Gateway_Venmo' ) ) {
		return;
	}

	class Vitalis_Gateway_Venmo extends WC_Payment_Gateway {

		public function __construct() {
			$this->id                 = 'vitalis_venmo';
			$this->method_title       = __( 'Venmo', 'vitalis' );
			$this->method_description = __( 'Manual Venmo payment. The order is placed on hold and the customer is emailed the Venmo QR code, link, amount, and order number. Mark the order Processing once payment arrives.', 'vitalis' );
			$this->has_fields         = false;
			$this->icon               = '';

			$this->init_form_fields();
			$this->init_settings();

			$this->title        = $this->get_option( 'title' );
			$this->description  = $this->get_option( 'description' );
			$this->instructions = $this->get_option( 'instructions' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'      => array(
					'title'   => __( 'Enable/Disable', 'vitalis' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Venmo', 'vitalis' ),
					'default' => 'yes',
				),
				'title'        => array(
					'title'       => __( 'Title', 'vitalis' ),
					'type'        => 'text',
					'description' => __( 'What the customer sees at checkout.', 'vitalis' ),
					'default'     => __( 'Venmo', 'vitalis' ),
					'desc_tip'    => true,
				),
				'description'  => array(
					'title'    => __( 'Description', 'vitalis' ),
					'type'     => 'textarea',
					'default'  => __( "No payment is collected here. After placing your order you'll receive an email with your order number and Venmo details — your order is prepared once payment is received.", 'vitalis' ),
					'desc_tip' => true,
				),
				'handle'       => array(
					'title'       => __( 'Venmo handle', 'vitalis' ),
					'type'        => 'text',
					'description' => __( 'Including the @.', 'vitalis' ),
					'default'     => '@Vitalislabs',
					'desc_tip'    => true,
				),
				'link'         => array(
					'title'       => __( 'Venmo payment link', 'vitalis' ),
					'type'        => 'text',
					'description' => __( 'Opens the Venmo app on a phone. From the Venmo app: Me → QR icon → Share.', 'vitalis' ),
					'default'     => 'https://venmo.com/code?user_id=4645604667950183480&created=1784567282.74056&printed=1',
					'desc_tip'    => true,
				),
				'qr'           => array(
					'title'       => __( 'QR code image URL', 'vitalis' ),
					'type'        => 'text',
					'description' => __( 'Leave blank to use the theme image at assets/venmo.png.', 'vitalis' ),
					'default'     => '',
					'desc_tip'    => true,
				),
				'instructions' => array(
					'title'       => __( 'Extra instructions', 'vitalis' ),
					'type'        => 'textarea',
					'description' => __( 'Shown on the thank-you page and in the payment email, under the standard steps.', 'vitalis' ),
					'default'     => __( 'Orders may be rejected. In the off chance that happens, a full refund will be given (Venmo fees are not reimbursed).', 'vitalis' ),
					'desc_tip'    => true,
				),
			);
		}

		/**
		 * No capture happens here — we just park the order as awaiting payment.
		 */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			$order->update_status(
				'on-hold',
				__( 'Awaiting Venmo payment. Mark this order Processing once the payment arrives.', 'vitalis' )
			);

			// Hold the stock for this order now that it's been placed.
			wc_reduce_stock_levels( $order_id );

			if ( WC()->cart ) {
				WC()->cart->empty_cart();
			}

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}
	}
}

/** Register it, and drop WooCommerce's offline gateways from the list. */
function vitalis_venmo_register( $gateways ) {
	vitalis_venmo_init_gateway();

	if ( ! class_exists( 'Vitalis_Gateway_Venmo' ) ) {
		return $gateways;
	}

	$gateways[] = 'Vitalis_Gateway_Venmo';
	// Belt and braces — these are also disabled in their own settings.
	return array_values( array_diff( $gateways, array( 'WC_Gateway_BACS', 'WC_Gateway_Cheque', 'WC_Gateway_COD' ) ) );
}
add_filter( 'woocommerce_payment_gateways', 'vitalis_venmo_register' );

/* -------------------------------------------------------------------------
 * Shared payment details
 * ---------------------------------------------------------------------- */

function vitalis_venmo_settings() {
	$s = get_option( 'woocommerce_vitalis_venmo_settings', array() );
	return wp_parse_args(
		is_array( $s ) ? $s : array(),
		array(
			'handle'       => '@Vitalislabs',
			'link'         => 'https://venmo.com/code?user_id=4645604667950183480&created=1784567282.74056&printed=1',
			'qr'           => '',
			'instructions' => '',
		)
	);
}

function vitalis_venmo_qr_url() {
	$s = vitalis_venmo_settings();
	return $s['qr'] ? $s['qr'] : get_template_directory_uri() . '/assets/venmo.png';
}

/**
 * What the customer should put in the Venmo note so you can match the payment.
 * Deliberately just the order number and name — a Venmo note can be public, so
 * the phone number and shipping address are left out.
 */
function vitalis_venmo_note( $order ) {
	return sprintf(
		"Order %s / %s",
		$order->get_order_number(),
		trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() )
	);
}

/** True when this order was placed with the Venmo method. */
function vitalis_venmo_is_venmo( $order ) {
	return is_a( $order, 'WC_Order' ) && 'vitalis_venmo' === $order->get_payment_method();
}

/* -------------------------------------------------------------------------
 * The payment block (email + thank-you page)
 * ---------------------------------------------------------------------- */

/**
 * Inline styles throughout and a table-based layout — this has to survive
 * email clients, which strip <style> blocks and ignore flexbox.
 */
function vitalis_venmo_block_html( $order ) {
	$s      = vitalis_venmo_settings();
	$amount = $order->get_formatted_order_total();
	$number = $order->get_order_number();
	$note   = vitalis_venmo_note( $order );

	ob_start();
	?>
	<table cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:0 0 24px;border:1px solid #d8d7de;border-radius:3px;background:#ffffff;">
		<tr>
			<td style="padding:22px 24px;font-family:'Space Grotesk',Arial,Helvetica,sans-serif;color:#16151c;">

				<div style="font-family:'Space Mono',monospace;font-size:11px;letter-spacing:.2em;color:#6a6974;margin-bottom:14px;">
					HOW TO PAY
				</div>

				<p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#34333b;">
					No payment was collected on the site. Send the amount below on Venmo and
					your order is prepared as soon as it arrives.
				</p>

				<!-- amount + order number -->
				<table cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom:18px;border:1px solid #e6e4ec;border-radius:3px;">
					<tr>
						<td style="padding:14px 16px;font-family:'Space Mono',monospace;font-size:11px;letter-spacing:.14em;color:#6a6974;">AMOUNT TO SEND</td>
						<td align="right" style="padding:14px 16px;font-size:22px;font-weight:700;color:#16151c;"><?php echo wp_kses_post( $amount ); ?></td>
					</tr>
					<tr>
						<td style="padding:0 16px 14px;font-family:'Space Mono',monospace;font-size:11px;letter-spacing:.14em;color:#6a6974;">ORDER NUMBER</td>
						<td align="right" style="padding:0 16px 14px;font-family:'Space Mono',monospace;font-size:15px;letter-spacing:.08em;color:#6d28d9;"><?php echo esc_html( $number ); ?></td>
					</tr>
					<tr>
						<td style="padding:0 16px 14px;font-family:'Space Mono',monospace;font-size:11px;letter-spacing:.14em;color:#6a6974;">SEND TO</td>
						<td align="right" style="padding:0 16px 14px;font-size:15px;font-weight:600;color:#16151c;"><?php echo esc_html( $s['handle'] ); ?></td>
					</tr>
				</table>

				<!-- QR -->
				<div style="text-align:center;margin-bottom:18px;">
					<img src="<?php echo esc_url( vitalis_venmo_qr_url() ); ?>" alt="Venmo QR code for <?php echo esc_attr( $s['handle'] ); ?>" width="200" height="200" style="display:inline-block;width:200px;height:200px;border:1px solid #e6e4ec;border-radius:3px;padding:8px;background:#ffffff;">
					<div style="font-family:'Space Mono',monospace;font-size:11px;letter-spacing:.12em;color:#6a6974;margin-top:10px;">
						SCAN TO PAY <?php echo esc_html( $s['handle'] ); ?>
					</div>
				</div>

				<?php if ( $s['link'] ) : ?>
					<div style="text-align:center;margin-bottom:20px;">
						<a href="<?php echo esc_url( $s['link'] ); ?>" style="display:inline-block;background:#3d95ce;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:12px 24px;border-radius:3px;">
							Open in Venmo
						</a>
						<div style="font-size:11px;color:#8a8992;margin-top:8px;">on your phone, this opens the Venmo app</div>
					</div>
				<?php endif; ?>

				<!-- the note to paste -->
				<div style="font-family:'Space Mono',monospace;font-size:10px;letter-spacing:.18em;color:#8a8992;margin-bottom:6px;">
					PASTE THIS AS YOUR VENMO NOTE
				</div>
				<div style="background:#f4f3f7;border:1px solid #e6e4ec;border-radius:3px;padding:12px 14px;font-family:'Space Mono',monospace;font-size:13px;color:#24232a;margin-bottom:18px;">
					<?php echo esc_html( $note ); ?>
				</div>

				<!-- steps -->
				<ol style="margin:0 0 4px;padding-left:20px;font-size:13.5px;line-height:1.75;color:#34333b;">
					<li>Open Venmo and scan the QR code (or tap <em>Open in Venmo</em>).</li>
					<li>Send <strong><?php echo wp_kses_post( $amount ); ?></strong> to <strong><?php echo esc_html( $s['handle'] ); ?></strong>.</li>
					<li>Paste the note above so we can match your payment to this order.</li>
				</ol>

				<?php if ( $s['instructions'] ) : ?>
					<p style="margin:16px 0 0;font-size:12.5px;line-height:1.6;color:#6a6974;">
						<?php echo wp_kses_post( wpautop( wptexturize( $s['instructions'] ) ) ); ?>
					</p>
				<?php endif; ?>

			</td>
		</tr>
	</table>
	<?php
	return ob_get_clean();
}

/** Plain-text twin, for the text/plain part of the email. */
function vitalis_venmo_block_text( $order ) {
	$s = vitalis_venmo_settings();

	$lines = array(
		strtoupper( __( 'How to pay', 'vitalis' ) ),
		'',
		sprintf( 'Amount to send: %s', wp_strip_all_tags( $order->get_formatted_order_total() ) ),
		sprintf( 'Order number:   %s', $order->get_order_number() ),
		sprintf( 'Send to:        %s (Venmo)', $s['handle'] ),
		'',
		sprintf( 'Venmo note:     %s', vitalis_venmo_note( $order ) ),
	);

	if ( $s['link'] ) {
		$lines[] = sprintf( 'Pay here:       %s', $s['link'] );
	}

	$lines[] = '';
	$lines[] = 'Your order is prepared once payment is received.';

	if ( $s['instructions'] ) {
		$lines[] = '';
		$lines[] = wp_strip_all_tags( $s['instructions'] );
	}

	return implode( "\n", $lines ) . "\n\n----------\n\n";
}

/* -------------------------------------------------------------------------
 * Where it appears
 * ---------------------------------------------------------------------- */

/**
 * Customer emails (the on-hold "order received" mail is the one that matters).
 * Skipped for admin copies — you don't need instructions to pay yourself.
 */
function vitalis_venmo_email_block( $order, $sent_to_admin, $plain_text, $email = null ) {
	if ( $sent_to_admin || ! vitalis_venmo_is_venmo( $order ) ) {
		return;
	}
	// Once you've marked it paid, the payment instructions are just noise.
	if ( $order->is_paid() || $order->has_status( array( 'completed', 'cancelled', 'refunded' ) ) ) {
		return;
	}

	if ( $plain_text ) {
		echo vitalis_venmo_block_text( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		echo vitalis_venmo_block_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
add_action( 'woocommerce_email_before_order_table', 'vitalis_venmo_email_block', 15, 4 );

/**
 * The thank-you page points at the email rather than repeating the payment
 * details. Keeping the QR, handle and amount out of the website means the
 * payment instructions live in exactly one place — the customer's inbox — so
 * there's no page to share, cache, or screenshot them from.
 */
function vitalis_venmo_thankyou( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || ! vitalis_venmo_is_venmo( $order ) || $order->is_paid() ) {
		return;
	}
	?>
	<div class="vitalis-venmo-sent" style="margin:0 0 24px;padding:18px 20px;border:1px solid var(--line-2,rgba(255,255,255,.16));border-radius:2px;">
		<div style="font-family:'Space Mono',monospace;font-size:10px;letter-spacing:.2em;color:var(--muted,#8a8992);margin-bottom:10px;">
			PAYMENT INSTRUCTIONS SENT
		</div>
		<p style="margin:0;font-size:14px;line-height:1.65;">
			<?php
			printf(
				/* translators: %s: customer's email address */
				esc_html__( 'We\'ve emailed your Venmo payment details to %s — the amount, your order number, and the QR code. Your order is prepared once payment is received.', 'vitalis' ),
				'<strong>' . esc_html( $order->get_billing_email() ) . '</strong>'
			);
			?>
		</p>
		<p style="margin:12px 0 0;font-size:12.5px;line-height:1.6;color:var(--muted,#8a8992);">
			<?php esc_html_e( "Don't see it? Check your spam folder.", 'vitalis' ); ?>
		</p>
	</div>
	<?php
}
add_action( 'woocommerce_thankyou', 'vitalis_venmo_thankyou', 5 );
