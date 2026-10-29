( function ( $, wp ) {
	'use strict';

	var frame = null;

	function getStrings() {
		return window.myeasybuyAdmin || {};
	}

	function addPhoto( attachment ) {
		var id = attachment.id;
		var preview = $( '#myeasybuy-photo-preview' );
		var inputs = $( '#myeasybuy-photo-inputs' );

		if ( inputs.find( 'input[value="' + id + '"]' ).length ) {
			return;
		}

		var thumbUrl = attachment.sizes && attachment.sizes.thumbnail
			? attachment.sizes.thumbnail.url
			: attachment.url;

		var item = $( '<div class="myeasybuy-photo-item" data-id="' + id + '">' +
			'<button type="button" class="myeasybuy-photo-remove" aria-label="remove">&times;</button>' +
			'<img src="' + thumbUrl + '" alt="" />' +
			'</div>' );

		preview.append( item );
		inputs.append( '<input type="hidden" name="inspection_photo_ids[]" value="' + id + '" />' );
	}

	function openMediaFrame() {
		var strings = getStrings();

		if ( frame ) {
			frame.open();
			return;
		}

		frame = wp.media( {
			title: strings.selectPhotos || 'Select photos',
			button: {
				text: strings.usePhotos || 'Use selected photos'
			},
			library: {
				type: 'image'
			},
			multiple: true
		} );

		frame.on( 'select', function () {
			var selection = frame.state().get( 'selection' );
			selection.each( function ( attachment ) {
				addPhoto( attachment.toJSON() );
			} );
		} );

		frame.open();
	}

	$( document ).on( 'click', '#myeasybuy-add-photos', function ( event ) {
		event.preventDefault();
		openMediaFrame();
	} );

	$( document ).on( 'click', '.myeasybuy-photo-remove', function () {
		var item = $( this ).closest( '.myeasybuy-photo-item' );
		var id = String( item.data( 'id' ) );

		item.remove();
		$( '#myeasybuy-photo-inputs' ).find( 'input[value="' + id + '"]' ).remove();
	} );

	// Auto-focus tracking search on inbound page for barcode scanners.
	var trackingInput = document.getElementById( 'myeasybuy-tracking-search' );
	if ( trackingInput && ! trackingInput.value ) {
		trackingInput.focus();
		trackingInput.select();
	}
}( jQuery, window.wp ) );
