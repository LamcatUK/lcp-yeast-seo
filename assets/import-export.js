( function () {
	var results = document.querySelector( '[data-results]' );

	if ( ! results ) {
		return;
	}

	var empty = results.querySelector( '[data-result-empty]' );
	var buttons = results.querySelectorAll( '[data-result-filter]' );

	function apply( bucket ) {
		var shown = 0;

		results.querySelectorAll( '.lcp-ys-result' ).forEach( function ( row ) {
			var show = 'all' === bucket || row.getAttribute( 'data-bucket' ) === bucket;

			row.hidden = ! show;
			shown += show ? 1 : 0;
		} );

		buttons.forEach( function ( button ) {
			button.classList.toggle( 'is-active', button.getAttribute( 'data-result-filter' ) === bucket );
		} );

		if ( empty ) {
			empty.hidden = shown > 0;
		}
	}

	buttons.forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			apply( button.getAttribute( 'data-result-filter' ) );
		} );
	} );
}() );
