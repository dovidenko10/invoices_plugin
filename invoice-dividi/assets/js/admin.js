/**
 * Invoice Dividi – Admin Scripts
 *
 * Handles the WordPress media uploader for the company logo field.
 *
 * @package Invoice_Dividi
 */

/* global wp */
( function ( $ ) {
	'use strict';

	/**
	 * Logo upload / remove buttons on the settings page.
	 */
	$( document ).on( 'click', '.invoice-dividi-upload-logo', function ( e ) {
		e.preventDefault();

		var $button  = $( this );
		var $target  = $( $button.data( 'target' ) );
		var $preview = $( $button.data( 'preview' ) );

		// If the media frame already exists, re-open it.
		if ( window.invoiceDividiMediaFrame ) {
			window.invoiceDividiMediaFrame.open();
			return;
		}

		// Create the media frame.
		window.invoiceDividiMediaFrame = wp.media( {
			title:    'Select Company Logo',
			button:   { text: 'Use this image' },
			multiple: false,
			library:  { type: 'image' }
		} );

		// When an image is selected in the media frame.
		window.invoiceDividiMediaFrame.on( 'select', function () {
			var attachment = window.invoiceDividiMediaFrame
				.state()
				.get( 'selection' )
				.first()
				.toJSON();

			$target.val( attachment.url );
			$preview.attr( 'src', attachment.url ).show();
		} );

		window.invoiceDividiMediaFrame.open();
	} );

	/**
	 * Remove logo button.
	 */
	$( document ).on( 'click', '.invoice-dividi-remove-logo', function ( e ) {
		e.preventDefault();

		var $button  = $( this );
		var $target  = $( $button.data( 'target' ) );
		var $preview = $( $button.data( 'preview' ) );

		$target.val( '' );
		$preview.attr( 'src', '' ).hide();

		// Reset the media frame so a fresh one is created next time.
		window.invoiceDividiMediaFrame = null;
	} );

} )( jQuery );
