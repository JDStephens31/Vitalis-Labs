<?php
/**
 * Vitalis Labs — per-product lab reports.
 *
 * Adds a "Lab Tests" panel to the product data box in wp-admin, where each
 * product carries a list of uploaded lab reports (HPLC / MS certificates of
 * analysis), and a matching "Lab Tests" tab on the single product page,
 * between Description and Reviews.
 *
 * Rows are stored as one array on `_vitalis_lab_tests`:
 *   array( array( 'id' => 12, 'title' => 'HPLC', 'lot' => '24071', 'date' => '2026-05-02' ), … )
 *
 * `id` is a media-library attachment id — the upload itself is a normal
 * WordPress attachment, so the media library stays the single source of truth
 * for the file, its mime type and its size.
 *
 * @package vitalis
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VITALIS_LAB_TESTS_META = '_vitalis_lab_tests';

/**
 * A product's lab reports, dropping rows whose attachment has been deleted.
 *
 * @param int $product_id Product post id.
 * @return array[] Rows with keys id, title, lot, date.
 */
function vitalis_lab_tests( $product_id ) {
	$rows = get_post_meta( $product_id, VITALIS_LAB_TESTS_META, true );
	if ( ! is_array( $rows ) ) {
		return array();
	}

	$out = array();
	foreach ( $rows as $row ) {
		$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
		// wp_get_attachment_url() is the cheap existence check: it returns false
		// once the attachment is gone, which is how a report deleted from the
		// media library disappears from the product instead of 404ing.
		if ( ! $id || ! wp_get_attachment_url( $id ) ) {
			continue;
		}
		$out[] = array(
			'id'    => $id,
			'title' => isset( $row['title'] ) ? (string) $row['title'] : '',
			'lot'   => isset( $row['lot'] ) ? (string) $row['lot'] : '',
			'date'  => isset( $row['date'] ) ? (string) $row['date'] : '',
		);
	}

	return $out;
}

/**
 * Label for a row, falling back to the attachment's own title.
 *
 * @param array $row One row from vitalis_lab_tests().
 * @return string
 */
function vitalis_lab_test_label( $row ) {
	if ( '' !== trim( $row['title'] ) ) {
		return $row['title'];
	}
	$title = get_the_title( $row['id'] );
	return $title ? $title : __( 'Lab report', 'vitalis' );
}

/* -------------------------------------------------------------------------
 * Admin: the Lab Tests product-data panel
 * ---------------------------------------------------------------------- */

/**
 * Register the panel's tab in the product data box.
 */
function vitalis_lab_tests_data_tab( $tabs ) {
	$tabs['vitalis_lab_tests'] = array(
		'label'    => __( 'Lab Tests', 'vitalis' ),
		'target'   => 'vitalis_lab_tests_panel',
		'class'    => array(),
		'priority' => 65,
	);
	return $tabs;
}
add_filter( 'woocommerce_product_data_tabs', 'vitalis_lab_tests_data_tab' );

/**
 * The panel itself: existing rows plus a JS row template.
 *
 * The template is emitted as markup with `__i__` where the row index belongs,
 * matching how WooCommerce's own repeaters work, so assets/admin-lab-tests.js
 * only has to do a string replace to append a row.
 */
function vitalis_lab_tests_panel() {
	global $post;

	$rows = vitalis_lab_tests( $post->ID );
	?>
	<div id="vitalis_lab_tests_panel" class="panel woocommerce_options_panel">
		<?php // Marks the panel as submitted, so "all rows removed" is distinguishable
			// from "panel not on this form" in vitalis_lab_tests_save(). ?>
		<input type="hidden" name="vitalis_lab_tests_present" value="1">

		<div class="options_group">
			<p style="padding: 12px 12px 0;">
				<?php esc_html_e( 'Lab reports shown on the product page under "Lab Tests". PDFs and images both work.', 'vitalis' ); ?>
			</p>

			<table class="widefat vitalis-labs-table" style="margin: 8px 0 12px; width: calc(100% - 24px); margin-left: 12px;">
				<thead>
					<tr>
						<th style="width: 34%;"><?php esc_html_e( 'File', 'vitalis' ); ?></th>
						<th style="width: 26%;"><?php esc_html_e( 'Label', 'vitalis' ); ?></th>
						<th style="width: 16%;"><?php esc_html_e( 'Lot / batch', 'vitalis' ); ?></th>
						<th style="width: 16%;"><?php esc_html_e( 'Test date', 'vitalis' ); ?></th>
						<th style="width: 8%;"></th>
					</tr>
				</thead>
				<tbody id="vitalis-labs-rows">
					<?php foreach ( $rows as $i => $row ) : ?>
						<?php vitalis_lab_tests_row( $i, $row ); ?>
					<?php endforeach; ?>
					<tr class="vitalis-labs-empty"<?php echo $rows ? ' style="display:none;"' : ''; ?>>
						<td colspan="5"><em><?php esc_html_e( 'No lab reports uploaded yet.', 'vitalis' ); ?></em></td>
					</tr>
				</tbody>
			</table>

			<p style="padding: 0 12px 12px;">
				<button type="button" class="button button-primary" id="vitalis-labs-add">
					<?php esc_html_e( 'Upload lab report', 'vitalis' ); ?>
				</button>
			</p>
		</div>

		<script type="text/html" id="tmpl-vitalis-labs-row">
			<?php vitalis_lab_tests_row( '__i__', array( 'id' => '__id__', 'title' => '', 'lot' => '', 'date' => '' ), '__name__' ); ?>
		</script>
	</div>
	<?php
}
add_action( 'woocommerce_product_data_panels', 'vitalis_lab_tests_panel' );

/**
 * One editable row.
 *
 * @param int|string $index    Row index, or the `__i__` placeholder in the template.
 * @param array      $row      Row data.
 * @param string     $filename Override for the displayed filename (template only).
 */
function vitalis_lab_tests_row( $index, $row, $filename = '' ) {
	$id   = $row['id'];
	$name = 'vitalis_lab_tests[' . $index . ']';

	if ( '' === $filename ) {
		$url      = is_numeric( $id ) ? wp_get_attachment_url( (int) $id ) : '';
		$filename = $url ? basename( wp_parse_url( $url, PHP_URL_PATH ) ) : '';
	}
	?>
	<tr class="vitalis-labs-row">
		<td>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $id ); ?>">
			<span class="vitalis-labs-row__file"><?php echo esc_html( $filename ); ?></span>
		</td>
		<td>
			<input type="text" name="<?php echo esc_attr( $name ); ?>[title]"
				value="<?php echo esc_attr( $row['title'] ); ?>"
				placeholder="<?php esc_attr_e( 'HPLC certificate of analysis', 'vitalis' ); ?>" style="width: 100%;">
		</td>
		<td>
			<input type="text" name="<?php echo esc_attr( $name ); ?>[lot]"
				value="<?php echo esc_attr( $row['lot'] ); ?>" style="width: 100%;">
		</td>
		<td>
			<input type="date" name="<?php echo esc_attr( $name ); ?>[date]"
				value="<?php echo esc_attr( $row['date'] ); ?>" style="width: 100%;">
		</td>
		<td>
			<button type="button" class="button vitalis-labs-remove" aria-label="<?php esc_attr_e( 'Remove this lab report', 'vitalis' ); ?>">
				<?php esc_html_e( 'Remove', 'vitalis' ); ?>
			</button>
		</td>
	</tr>
	<?php
}

/**
 * Media modal + repeater script, product edit screens only.
 */
function vitalis_lab_tests_admin_assets( $hook ) {
	if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
		return;
	}
	if ( 'product' !== get_post_type() ) {
		return;
	}

	wp_enqueue_media();
	wp_enqueue_script(
		'vitalis-admin-lab-tests',
		get_template_directory_uri() . '/assets/admin-lab-tests.js',
		array( 'jquery' ),
		VITALIS_VERSION,
		true
	);
	wp_localize_script( 'vitalis-admin-lab-tests', 'vitalisLabTests', array(
		'title'  => __( 'Select or upload lab reports', 'vitalis' ),
		'button' => __( 'Add to product', 'vitalis' ),
	) );
}
add_action( 'admin_enqueue_scripts', 'vitalis_lab_tests_admin_assets' );

/**
 * Save the panel.
 *
 * Runs on `woocommerce_process_product_meta`, which WooCommerce only fires
 * after its own nonce and capability checks pass.
 */
function vitalis_lab_tests_save( $post_id ) {
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// An untouched panel (e.g. a quick edit) must not wipe existing reports;
	// only a submitted product form carries the marker input.
	if ( ! isset( $_POST['vitalis_lab_tests'] ) ) {
		if ( isset( $_POST['vitalis_lab_tests_present'] ) ) {
			delete_post_meta( $post_id, VITALIS_LAB_TESTS_META );
		}
		return;
	}

	$posted = wp_unslash( $_POST['vitalis_lab_tests'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( ! is_array( $posted ) ) {
		return;
	}

	$rows = array();
	foreach ( $posted as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			continue;
		}

		$date = isset( $row['date'] ) ? sanitize_text_field( $row['date'] ) : '';
		// The date input hands us YYYY-MM-DD; anything else is discarded rather
		// than stored in a format the front end can't format.
		if ( $date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = '';
		}

		$rows[] = array(
			'id'    => $id,
			'title' => isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '',
			'lot'   => isset( $row['lot'] ) ? sanitize_text_field( $row['lot'] ) : '',
			'date'  => $date,
		);
	}

	if ( $rows ) {
		update_post_meta( $post_id, VITALIS_LAB_TESTS_META, $rows );
	} else {
		delete_post_meta( $post_id, VITALIS_LAB_TESTS_META );
	}
}
add_action( 'woocommerce_process_product_meta', 'vitalis_lab_tests_save' );

/* -------------------------------------------------------------------------
 * Front end: the Lab Tests product tab
 * ---------------------------------------------------------------------- */

/**
 * Slot the tab in between Description (10) and Reviews (30).
 */
function vitalis_lab_tests_tab( $tabs ) {
	$tabs['vitalis_lab_tests'] = array(
		'title'    => __( 'Lab Tests', 'vitalis' ),
		'priority' => 15,
		'callback' => 'vitalis_lab_tests_tab_content',
	);
	return $tabs;
}
add_filter( 'woocommerce_product_tabs', 'vitalis_lab_tests_tab' );

/**
 * Tab body: the uploaded reports, or a note when there are none yet.
 */
function vitalis_lab_tests_tab_content() {
	global $product;

	$rows = $product ? vitalis_lab_tests( $product->get_id() ) : array();

	echo '<h2>' . esc_html__( 'Lab Tests', 'vitalis' ) . '</h2>';

	if ( ! $rows ) {
		echo '<p>' . esc_html__(
			'The certificate of analysis for this product\'s current batch is available on request.',
			'vitalis'
		) . '</p>';
		return;
	}

	echo '<ul class="vl-labs">';
	foreach ( $rows as $row ) {
		$url  = wp_get_attachment_url( $row['id'] );
		$file = get_attached_file( $row['id'] );
		$ext  = strtoupper( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$size = ( $file && file_exists( $file ) ) ? size_format( filesize( $file ) ) : '';

		$meta = array_filter( array(
			$ext,
			$size,
			$row['lot'] ? sprintf( /* translators: %s: lot number. */ __( 'Lot %s', 'vitalis' ), $row['lot'] ) : '',
			$row['date'] ? date_i18n( get_option( 'date_format' ), strtotime( $row['date'] ) ) : '',
		) );

		echo '<li class="vl-labs__item">';
		echo '<a class="vl-labs__link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">'
			. esc_html( vitalis_lab_test_label( $row ) ) . '</a>';
		if ( $meta ) {
			echo '<span class="mono vl-labs__meta">' . esc_html( implode( ' · ', $meta ) ) . '</span>';
		}
		echo '</li>';
	}
	echo '</ul>';

	echo '<p class="vl-labs__note mono">'
		. esc_html__( 'Reports are provided for the batch stated on each certificate.', 'vitalis' )
		. '</p>';
}
