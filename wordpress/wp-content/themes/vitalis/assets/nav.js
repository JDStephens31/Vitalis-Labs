/**
 * Mobile navigation drawer.
 *
 * The markup ships with the panel visible so it still works with JavaScript off
 * (header.php's <noscript> keeps it that way); the first thing we do here is
 * close it. No dependencies — this must not wait on jQuery.
 */
( function () {
	'use strict';

	var toggle = document.getElementById( 'vitalis-nav-toggle' );
	var panel  = document.getElementById( 'vitalis-mobile-nav' );

	if ( ! toggle || ! panel ) {
		return;
	}

	function setOpen( open ) {
		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		panel.hidden = ! open;
		document.body.classList.toggle( 'nav-open', open );
	}

	toggle.addEventListener( 'click', function ( e ) {
		e.stopPropagation();
		setOpen( toggle.getAttribute( 'aria-expanded' ) !== 'true' );
	} );

	// Tapping anywhere outside the drawer closes it.
	document.addEventListener( 'click', function ( e ) {
		if ( panel.hidden || panel.contains( e.target ) || toggle.contains( e.target ) ) {
			return;
		}
		setOpen( false );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( ( e.key === 'Escape' || e.key === 'Esc' ) && ! panel.hidden ) {
			setOpen( false );
			toggle.focus();
		}
	} );

	// Rotating to landscape can cross the breakpoint that hides the toggle —
	// don't strand an open drawer (and a locked body) behind a hidden control.
	window.addEventListener( 'resize', function () {
		if ( window.innerWidth > 860 && ! panel.hidden ) {
			setOpen( false );
		}
	} );

	setOpen( false );
}() );
