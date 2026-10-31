( function () {
	var wrap = document.querySelector( '.lcp-yeast-seo-logo' );
	if ( ! wrap || ! window.wp || ! wp.media ) {
		return;
	}

	var input = wrap.querySelector( '[data-logo-input]' );
	var preview = wrap.querySelector( '[data-logo-preview]' );
	var remove = wrap.querySelector( '[data-logo-remove]' );
	var frame;

	wrap.querySelector( '[data-logo-select]' ).addEventListener( 'click', function () {
		if ( ! frame ) {
			frame = wp.media( { title: 'Organization logo', button: { text: 'Use as logo' }, library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				var item = frame.state().get( 'selection' ).first().toJSON();
				var src = item.sizes && item.sizes.medium ? item.sizes.medium.url : item.url;
				input.value = item.id;
				preview.innerHTML = '';
				var img = document.createElement( 'img' );
				img.src = src;
				img.style.maxWidth = '200px';
				img.style.height = 'auto';
				preview.appendChild( img );
				remove.hidden = false;
			} );
		}
		frame.open();
	} );

	remove.addEventListener( 'click', function () {
		input.value = '0';
		preview.innerHTML = '';
		remove.hidden = true;
	} );
}() );
