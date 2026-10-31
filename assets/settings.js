( function () {
	var modeBox = document.querySelector( '[data-org-mode]' );
	if ( modeBox ) {
		var rowOf = function ( el ) { return el.closest( 'tr' ); };
		var raw = rowOf( document.querySelector( '[data-org-raw]' ) );
		var fieldRows = [];
		document.querySelectorAll( '[name^="lcp_yeast_seo_settings[org_"]' ).forEach( function ( el ) {
			var key = el.name.replace( /^.*\[(org_[a-z_]+)\].*$/, '$1' );
			if ( [ 'org_mode', 'org_raw', 'org_show_front', 'org_show_all', 'org_contact_page', 'org_extra_pages' ].indexOf( key ) === -1 ) {
				var row = rowOf( el );
				if ( row && fieldRows.indexOf( row ) === -1 ) {
					fieldRows.push( row );
				}
			}
		} );
		var apply = function () {
			var isRaw = modeBox.querySelector( ':checked' ).value === 'raw';
			raw.hidden = ! isRaw;
			fieldRows.forEach( function ( row ) { row.hidden = isRaw; } );
		};
		modeBox.addEventListener( 'change', apply );
		apply();
	}

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
