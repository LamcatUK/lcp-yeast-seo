( function () {
	var nav = document.querySelector( '[data-lcp-tabs]' );
	if ( nav ) {
		var panels = document.querySelectorAll( '[data-panel]' );
		var referer = document.querySelector( 'input[name="_wp_http_referer"]' );
		nav.addEventListener( 'click', function ( e ) {
			var link = e.target.closest( '[data-tab]' );
			if ( ! link ) { return; }
			e.preventDefault();
			nav.querySelectorAll( '.nav-tab' ).forEach( function ( a ) { a.classList.toggle( 'nav-tab-active', a === link ); } );
			panels.forEach( function ( p ) { p.hidden = p.dataset.panel !== link.dataset.tab; } );
			history.replaceState( null, '', link.href );
			if ( referer ) { referer.value = link.getAttribute( 'href' ).replace( /^.*\/wp-admin\//, '/wp-admin/' ); }
		} );
	}

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
		var N = 'lcp_yeast_seo_settings[';
		var field = function ( key ) { return document.querySelector( '[name="' + N + key + ']"]' ); };
		var textMap = { name: 'org_name', legalName: 'org_legal_name', url: 'org_url', description: 'org_description', email: 'org_email', telephone: 'org_phone', foundingDate: 'org_founded' };
		var addrMap = { streetAddress: 'org_street', addressLocality: 'org_locality', addressRegion: 'org_region', postalCode: 'org_postcode', addressCountry: 'org_country' };
		var rawBox = document.querySelector( '[data-org-raw]' );

		// Mirrors lcp_yeast_seo_build_organization() in PHP.
		var fieldsToJson = function () {
			var v = function ( k ) { return field( k ).value.trim(); };
			var node = { '@context': 'https://schema.org', '@type': field( 'org_type' ).value, '@id': modeBox.dataset.orgId, name: v( 'org_name' ), url: v( 'org_url' ) || modeBox.dataset.orgHome };
			Object.keys( textMap ).forEach( function ( p ) {
				if ( ! node[ p ] && v( textMap[ p ] ) ) { node[ p ] = v( textMap[ p ] ); }
			} );
			if ( field( 'org_logo' ).value !== '0' && modeBox.dataset.logoUrl ) {
				node.logo = { '@type': 'ImageObject', url: modeBox.dataset.logoUrl, width: +modeBox.dataset.logoW, height: +modeBox.dataset.logoH };
			}
			var addr = {};
			Object.keys( addrMap ).forEach( function ( p ) { if ( v( addrMap[ p ] ) ) { addr[ p ] = v( addrMap[ p ] ); } } );
			if ( Object.keys( addr ).length ) { node.address = Object.assign( { '@type': 'PostalAddress' }, addr ); }
			var same = v( 'org_same_as' ).split( /\r?\n/ ).map( function ( l ) { return l.trim(); } ).filter( Boolean );
			if ( same.length ) { node.sameAs = same; }
			return JSON.stringify( node, null, 2 );
		};

		var firstNode = function ( text ) {
			try {
				var d = JSON.parse( text );
				if ( d && Array.isArray( d[ '@graph' ] ) ) { d = d[ '@graph' ]; }
				if ( Array.isArray( d ) ) { d = d[ 0 ]; }
				return d && typeof d === 'object' ? d : null;
			} catch ( e ) { return null; }
		};

		var jsonToFields = function ( node ) {
			var set = function ( k, val ) { field( k ).value = val == null ? '' : String( val ); };
			var type = Array.isArray( node[ '@type' ] ) ? node[ '@type' ][ 0 ] : node[ '@type' ];
			var typeSel = field( 'org_type' );
			if ( Array.prototype.some.call( typeSel.options, function ( o ) { return o.value === type; } ) ) { typeSel.value = type; }
			Object.keys( textMap ).forEach( function ( p ) { set( textMap[ p ], typeof node[ p ] === 'string' ? node[ p ] : '' ); } );
			var addr = node.address && typeof node.address === 'object' ? node.address : {};
			Object.keys( addrMap ).forEach( function ( p ) { set( addrMap[ p ], typeof addr[ p ] === 'string' ? addr[ p ] : '' ); } );
			set( 'org_same_as', ( Array.isArray( node.sameAs ) ? node.sameAs : [] ).join( '\n' ) );
		};

		var currentMode = modeBox.querySelector( ':checked' ).value;

		modeBox.addEventListener( 'change', function () {
			var next = modeBox.querySelector( ':checked' ).value;
			if ( next === currentMode ) { return; }
			if ( next === 'raw' ) {
				var json = fieldsToJson();
				if ( field( 'org_name' ).value.trim() !== '' && rawBox.value.trim() !== json && ( rawBox.value.trim() === '' || window.confirm( 'Replace the JSON-LD text with JSON built from the fields?' ) ) ) {
					rawBox.value = json;
				}
			} else {
				var node = firstNode( rawBox.value );
				if ( node && window.confirm( 'Replace the fields with values read from the JSON-LD? Only the first entity is used, and properties the fields don\'t cover are not kept.' ) ) {
					jsonToFields( node );
				}
			}
			currentMode = next;
			apply();
		} );
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
