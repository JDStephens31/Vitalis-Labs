/**
 * Lab Tests panel on the product edit screen.
 *
 * Opens the media modal, appends a row per selected file, and removes rows.
 * Row markup comes from the PHP-rendered #tmpl-vitalis-labs-row template so the
 * editable markup lives in one place (inc/lab-tests.php).
 */
( function ( $ ) {
	'use strict';

	var $rows = $( '#vitalis-labs-rows' );
	if ( ! $rows.length ) {
		return;
	}

	var template = $( '#tmpl-vitalis-labs-row' ).html() || '';
	var frame;

	// Row names are indexed, but the index only has to be unique within the
	// submitted form — PHP re-packs the array on save. Seeding the counter past
	// the existing rows keeps added rows from colliding with saved ones.
	var nextIndex = $rows.find( '.vitalis-labs-row' ).length;

	function syncEmpty() {
		$rows.find( '.vitalis-labs-empty' ).toggle( 0 === $rows.find( '.vitalis-labs-row' ).length );
	}

	function addRow( attachment ) {
		var filename = attachment.filename || attachment.title || '';
		var html = template
			.replace( /__i__/g, nextIndex++ )
			.replace( /__id__/g, attachment.id )
			.replace( /__name__/g, $( '<div>' ).text( filename ).html() );

		$rows.find( '.vitalis-labs-empty' ).before( html );
		syncEmpty();
	}

	$( '#vitalis-labs-add' ).on( 'click', function ( e ) {
		e.preventDefault();

		if ( ! frame ) {
			frame = wp.media( {
				title: vitalisLabTests.title,
				button: { text: vitalisLabTests.button },
				multiple: 'add'
			} );

			frame.on( 'select', function () {
				var selection = frame.state().get( 'selection' );
				selection.map( function ( attachment ) {
					return attachment.toJSON();
				} ).forEach( addRow );
				// The frame is reused, and its selection would otherwise still be
				// held on the next open — adding the same file a second time.
				selection.reset();
			} );
		}

		frame.open();
	} );

	$rows.on( 'click', '.vitalis-labs-remove', function ( e ) {
		e.preventDefault();
		$( this ).closest( '.vitalis-labs-row' ).remove();
		syncEmpty();
	} );
}( jQuery ) );
