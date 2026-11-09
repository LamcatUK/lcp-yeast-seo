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
		var controller = typeof AbortController === 'function' ? new AbortController() : null;
		var timer = controller ? setTimeout( function () {
			controller.abort();
		}, config.requestTimeout * 1000 ) : null;

		body.append( 'action', 'lcp_yeast_seo_health_check' );
		body.append( '_ajax_nonce', config.nonce );
		ids.forEach( function ( id ) {
			body.append( 'ids[]', id );
		} );

		return fetch( config.ajax, { method: 'POST', credentials: 'same-origin', body: body, signal: controller ? controller.signal : undefined } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( ! result || ! result.success ) {
					throw new Error( result && result.data && result.data.message ? result.data.message : 'The server could not check those pages.' );
				}

				summary.innerHTML = result.data.summary;
				list.innerHTML = result.data.list;

				return ( result.data.done || [] ).map( Number );
			} )
			.then( function ( done ) {
				clearTimeout( timer );
				return done;
			}, function ( error ) {
				clearTimeout( timer );
				throw error;
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

		var queue = config.ids.map( Number );
		var total = queue.length;
		var finished = 0;
		var skipped = 0;
		var failures = 0;
		var oneAtATime = false;

		setBusy( true );
		progress.hidden = false;
		bar.style.width = '0%';

		function advance( count ) {
			finished += count;
			bar.style.width = Math.round( ( 100 * finished ) / total ) + '%';
		}

		function next() {
			if ( ! queue.length ) {
				bar.style.width = '100%';
				status.textContent = 'Scan finished. ' + ( total - skipped ) + ' pages checked.'
					+ ( skipped ? ' ' + skipped + ( 1 === skipped ? ' page' : ' pages' ) + ' did not answer in time and ' + ( 1 === skipped ? 'was' : 'were' ) + ' skipped. Use Check again on them.' : '' );
				setBusy( false );
				return;
			}

			var batch = queue.splice( 0, oneAtATime ? 1 : config.batch );

			status.textContent = 'Checking pages ' + ( finished + 1 ) + ' to ' + ( finished + batch.length ) + ' of ' + total + '.' + ( oneAtATime ? ' The site is slow to answer, so pages are checked one at a time.' : '' );

			post( batch )
				.then( function ( done ) {
					failures = 0;
					queue = batch.filter( function ( id ) {
						return done.indexOf( id ) < 0;
					} ).concat( queue );
					advance( done.length );
					next();
				} )
				.catch( function () {
					failures += 1;

					if ( failures >= 5 ) {
						status.textContent = 'The scan stopped after ' + finished + ' pages because the server stopped answering. Try again in a moment.';
						setBusy( false );
						return;
					}

					if ( batch.length > 1 ) {
						queue = batch.concat( queue );
						oneAtATime = true;
					} else {
						skipped += 1;
						advance( 1 );
					}

					next();
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
