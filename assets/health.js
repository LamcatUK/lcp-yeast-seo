( function () {
	var root = document.querySelector( '[data-health]' );
	var config = window.lcpYeastSeoHealth;

	if ( ! root || ! config ) {
		return;
	}

	var summary = root.querySelector( '[data-health-summary]' );
	var list = root.querySelector( '[data-health-list]' );
	var scanButton = root.querySelector( '[data-health-scan]' );
	var progress = root.querySelector( '[data-health-progress]' );
	var bar = root.querySelector( '[data-health-bar]' );
	var status = root.querySelector( '[data-health-status]' );
	var running = false;

	function post( ids ) {
		var body = new URLSearchParams();

		body.append( 'action', 'lcp_yeast_seo_health_check' );
		body.append( '_ajax_nonce', config.nonce );
		ids.forEach( function ( id ) {
			body.append( 'ids[]', id );
		} );

		return fetch( config.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( ! result || ! result.success ) {
					throw new Error( result && result.data && result.data.message ? result.data.message : 'The server could not check those pages.' );
				}

				summary.innerHTML = result.data.summary;
				list.innerHTML = result.data.list;
			} );
	}

	function setBusy( busy ) {
		running = busy;
		scanButton.disabled = busy;
		root.querySelectorAll( '[data-recheck]' ).forEach( function ( button ) {
			button.disabled = busy;
		} );
		progress.hidden = ! busy && ! status.textContent;
	}

	function scan() {
		if ( running ) {
			return;
		}

		var ids = config.ids.slice();
		var done = 0;

		setBusy( true );
		progress.hidden = false;
		bar.style.width = '0%';

		function next() {
			if ( ! ids.length ) {
				bar.style.width = '100%';
				status.textContent = 'Scan finished. ' + done + ' pages checked.';
				setBusy( false );
				return;
			}

			var batch = ids.splice( 0, config.batch );

			status.textContent = 'Checking pages ' + ( done + 1 ) + ' to ' + ( done + batch.length ) + ' of ' + config.ids.length + '.';

			post( batch )
				.then( function () {
					done += batch.length;
					bar.style.width = Math.round( ( 100 * done ) / config.ids.length ) + '%';
					next();
				} )
				.catch( function ( error ) {
					status.textContent = 'The scan stopped after ' + done + ' pages. ' + error.message;
					setBusy( false );
				} );
		}

		next();
	}

	scanButton.addEventListener( 'click', scan );

	root.addEventListener( 'click', function ( event ) {
		var recheck = event.target.closest( '[data-recheck]' );
		var filter = event.target.closest( '[data-filter]' );

		if ( recheck && ! running ) {
			var id = parseInt( recheck.getAttribute( 'data-recheck' ), 10 );

			setBusy( true );
			progress.hidden = false;
			bar.style.width = '0%';
			status.textContent = 'Checking this page.';

			post( [ id ] )
				.then( function () {
					bar.style.width = '100%';
					status.textContent = 'Checked again.';
				} )
				.catch( function ( error ) {
					status.textContent = error.message;
				} )
				.then( function () {
					setBusy( false );
					var row = root.querySelector( '.lcp-ys-row[data-id="' + id + '"]' );

					if ( row ) {
						row.open = true;
					}
				} );
		}

		if ( filter && filter.classList.contains( 'lcp-ys-filter__btn' ) ) {
			root.setAttribute( 'data-filter', filter.getAttribute( 'data-filter' ) );
			root.querySelectorAll( '.lcp-ys-filter__btn' ).forEach( function ( button ) {
				button.classList.toggle( 'is-active', button === filter );
			} );
		}
	} );

	var observer = new MutationObserver( function () {
		var active = root.getAttribute( 'data-filter' );

		root.querySelectorAll( '.lcp-ys-filter__btn' ).forEach( function ( button ) {
			button.classList.toggle( 'is-active', button.getAttribute( 'data-filter' ) === active );
		} );
	} );

	observer.observe( list, { childList: true } );
}() );
