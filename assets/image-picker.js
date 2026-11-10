( function () {
	if ( ! window.wp || ! wp.media ) {
		return;
	}

	document.querySelectorAll( '[data-image-picker]' ).forEach( function ( picker ) {
		var input = picker.querySelector( '[data-image-input]' );
		var preview = picker.querySelector( '[data-image-preview]' );
		var select = picker.querySelector( '[data-image-select]' );
		var remove = picker.querySelector( '[data-image-remove]' );
		var frame;

		select.addEventListener( 'click', function () {
			if ( ! frame ) {
				frame = wp.media( {
					title: picker.getAttribute( 'data-title' ) || 'Choose image',
					button: { text: picker.getAttribute( 'data-button' ) || 'Use this image' },
					library: { type: 'image' },
					multiple: false
				} );
				frame.on( 'open', function () {
					var current = parseInt( picker.getAttribute( 'data-image-id' ), 10 );
					var selection = frame.state().get( 'selection' );

					selection.reset();
					if ( current ) {
						var attachment = wp.media.attachment( current );
						attachment.fetch();
						selection.add( attachment );
					}
				} );
				frame.on( 'select', function () {
					var item = frame.state().get( 'selection' ).first().toJSON();
					var img = document.createElement( 'img' );

					input.value = item.url;
					picker.setAttribute( 'data-image-id', item.id );
					img.src = item.url;
					img.alt = '';
					preview.innerHTML = '';
					preview.appendChild( img );
					select.textContent = 'Change image';
					remove.hidden = false;
				} );
			}
			frame.open();
		} );

		remove.addEventListener( 'click', function () {
			input.value = '';
			picker.setAttribute( 'data-image-id', '0' );
			preview.innerHTML = '';
			select.textContent = 'Choose image';
			remove.hidden = true;
		} );
	} );
}() );
