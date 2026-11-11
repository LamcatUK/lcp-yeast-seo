( function () {
	var root = document.querySelector( '[data-health]' );
	var config = window.lcpYeastSeoHealth;

	if ( ! root || ! config ) {
		return;
	}

	var summary = root.querySelector( '[data-health-summary]' );
	var list = root.querySelector( '[data-health-list]' );
	var scanButton = root.querySelector( '[data-health-scan]' );
	var linksButton = root.querySelector( '[data-health-links]' );
	var recheckButtons = root.querySelectorAll( '[data-health-recheck]' );
	var progress = root.querySelector( '[data-health-progress]' );
	var bar = root.querySelector( '[data-health-bar]' );
	var status = root.querySelector( '[data-health-status]' );
	var running = false;

	function post( ids, baseline ) {
		var body = new URLSearchParams();
		var controller = typeof AbortController === 'function' ? new AbortController() : null;
		var timer = controller ? setTimeout( function () {
			controller.abort();
		}, config.requestTimeout * 1000 ) : null;

		body.append( 'action', 'lcp_yeast_seo_health_check' );
		body.append( '_ajax_nonce', config.nonce );

		if ( baseline ) {
			body.append( 'baseline', '1' );
		}

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

	function postLinks( reset ) {
		var body = new URLSearchParams();
		var controller = typeof AbortController === 'function' ? new AbortController() : null;
		var timer = controller ? setTimeout( function () {
			controller.abort();
		}, config.requestTimeout * 1000 ) : null;

		body.append( 'action', 'lcp_yeast_seo_health_links' );
		body.append( '_ajax_nonce', config.nonce );

		if ( reset ) {
			body.append( 'reset', '1' );
		}

		return fetch( config.ajax, { method: 'POST', credentials: 'same-origin', body: body, signal: controller ? controller.signal : undefined } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				clearTimeout( timer );

				if ( ! result || ! result.success ) {
					throw new Error( 'The server could not check the links.' );
				}

				summary.innerHTML = result.data.summary;
				list.innerHTML = result.data.list;

				return result.data;
			}, function ( error ) {
				clearTimeout( timer );
				throw error;
			} );
	}

	// Checks the links the scan collected, a few seconds' worth at a time, until none are left.
	function checkLinks( reset ) {
		return new Promise( function ( resolve ) {
			var total = null;
			var failures = 0;

			function step( first ) {
				postLinks( first && reset )
					.then( function ( data ) {
						var left = data.remaining;

						failures = 0;
						total = null === total ? data.checked + left : total;

						if ( total ) {
							bar.style.width = Math.round( ( 100 * ( total - left ) ) / total ) + '%';
							status.textContent = left ? 'Checking links: ' + ( total - left ) + ' of ' + total + '.' : 'Links checked.';
						}

						if ( left > 0 && data.checked > 0 ) {
							step( false );
						} else {
							resolve();
						}
					} )
					.catch( function () {
						failures += 1;

						if ( failures >= 3 ) {
							status.textContent = 'Some links could not be checked. Try again in a moment.';
							resolve();
						} else {
							step( false );
						}
					} );
			}

			status.textContent = 'Checking links.';
			bar.style.width = '0%';
			step( true );
		} );
	}

	function syncFilter() {
		var mode = root.getAttribute( 'data-filter' );
		var code = root.getAttribute( 'data-code' ) || '';
		var label = root.getAttribute( 'data-code-label' ) || '';
		var rows = root.querySelectorAll( '.lcp-ys-row' );
		var shown = 0;
		var bar = root.querySelector( '[data-filterbar]' );
		var empty = root.querySelector( '[data-filter-empty]' );

		rows.forEach( function ( row ) {
			var rowStatus = row.getAttribute( 'data-status' );
			var show = true;

			if ( 'attention' === mode ) {
				show = 'ok' !== rowStatus;
			} else if ( 'error' === mode ) {
				show = 'error' === rowStatus;
			} else if ( 'ok' === mode ) {
				show = 'ok' === rowStatus;
			} else if ( 'code' === mode ) {
				show = ( ' ' + ( row.getAttribute( 'data-codes' ) || '' ) + ' ' ).indexOf( ' ' + code + ' ' ) > -1;
			}

			row.classList.toggle( 'is-out', ! show );
			shown += show ? 1 : 0;
		} );

		root.querySelectorAll( '.lcp-ys-filter__btn' ).forEach( function ( button ) {
			button.classList.toggle( 'is-active', button.getAttribute( 'data-filter' ) === mode );
		} );

		if ( bar ) {
			bar.hidden = 'code' !== mode;
			bar.querySelector( '[data-filterbar-text]' ).textContent = 'Showing ' + shown + ( 1 === shown ? ' page' : ' pages' ) + ' with this problem: ' + ( label.length > 110 ? label.slice( 0, 107 ) + '...' : label );
		}

		if ( empty ) {
			empty.hidden = shown > 0 || ! rows.length;
		}
	}

	function idsWithStatus( rowStatus ) {
		return Array.prototype.map.call( root.querySelectorAll( '.lcp-ys-row[data-status="' + rowStatus + '"]' ), function ( row ) {
			return parseInt( row.getAttribute( 'data-id' ), 10 );
		} );
	}

	function updateRecheck() {
		recheckButtons.forEach( function ( button ) {
			var count = idsWithStatus( button.getAttribute( 'data-health-recheck' ) ).length;

			button.hidden = 0 === count;
			button.textContent = 'Re-check ' + count + ( 1 === count ? ' page' : ' pages' ) + ' with ' + ( 'error' === button.getAttribute( 'data-health-recheck' ) ? ( 1 === count ? 'an error' : 'errors' ) : ( 1 === count ? 'a warning' : 'warnings' ) );
		} );
	}

	function setBusy( busy ) {
		running = busy;
		scanButton.disabled = busy;
		linksButton.disabled = busy;
		recheckButtons.forEach( function ( button ) {
			button.disabled = busy;
		} );
		root.querySelectorAll( '[data-recheck]' ).forEach( function ( button ) {
			button.disabled = busy;
		} );
		progress.hidden = ! busy && ! status.textContent;
	}

	function scan( idList, full ) {
		if ( running || ! idList.length ) {
			return;
		}

		var queue = idList.map( Number );
		var total = queue.length;
		var finished = 0;
		var skipped = 0;
		var failures = 0;
		var oneAtATime = false;
		var markBaseline = !! full;

		setBusy( true );
		progress.hidden = false;
		bar.style.width = '0%';

		function advance( count ) {
			finished += count;
			bar.style.width = Math.round( ( 100 * finished ) / total ) + '%';
		}

		function next() {
			if ( ! queue.length ) {
				var summaryText = 'Scan finished. ' + ( total - skipped ) + ' pages checked.'
					+ ( skipped ? ' ' + skipped + ( 1 === skipped ? ' page' : ' pages' ) + ' did not answer in time and ' + ( 1 === skipped ? 'was' : 'were' ) + ' skipped. Use Check again on them.' : '' );

				checkLinks( false ).then( function () {
					bar.style.width = '100%';
					status.textContent = summaryText + ' Links checked.';
					setBusy( false );
				} );
				return;
			}

			var batch = queue.splice( 0, oneAtATime ? 1 : config.batch );

			status.textContent = 'Checking pages ' + ( finished + 1 ) + ' to ' + ( finished + batch.length ) + ' of ' + total + '.' + ( oneAtATime ? ' The site is slow to answer, so pages are checked one at a time.' : '' );

			var asBaseline = markBaseline;

			markBaseline = false;

			post( batch, asBaseline )
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

	scanButton.addEventListener( 'click', function () {
		scan( config.ids, true );
	} );

	linksButton.addEventListener( 'click', function () {
		if ( running ) {
			return;
		}

		setBusy( true );
		progress.hidden = false;
		checkLinks( true ).then( function () {
			bar.style.width = '100%';
			setBusy( false );
		} );
	} );

	recheckButtons.forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			scan( idsWithStatus( button.getAttribute( 'data-health-recheck' ) ) );
		} );
	} );

	updateRecheck();

	root.addEventListener( 'click', function ( event ) {
		var recheck = event.target.closest( '[data-recheck]' );
		var filter = event.target.closest( 'button[data-filter]' );

		if ( recheck && ! running ) {
			var id = parseInt( recheck.getAttribute( 'data-recheck' ), 10 );

			setBusy( true );
			progress.hidden = false;
			bar.style.width = '0%';
			status.textContent = 'Checking this page.';

			post( [ id ] )
				.then( function () {
					return checkLinks( false );
				} )
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

		var problem = event.target.closest( '[data-problem]' );

		if ( problem ) {
			var card = root.querySelector( '.lcp-ys-pages' );

			root.setAttribute( 'data-filter', 'code' );
			root.setAttribute( 'data-code', problem.getAttribute( 'data-problem' ) );
			root.setAttribute( 'data-code-label', problem.getAttribute( 'data-label' ) || '' );
			syncFilter();

			if ( card ) {
				card.scrollIntoView( { behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth', block: 'start' } );
			}
		}

		if ( filter ) {
			root.setAttribute( 'data-filter', filter.getAttribute( 'data-filter' ) );
			root.removeAttribute( 'data-code' );
			syncFilter();
		}
	} );

	var observer = new MutationObserver( function () {
		updateRecheck();
		syncFilter();
	} );

	observer.observe( list, { childList: true } );
	syncFilter();

	// A link from the dashboard widget opens the list already filtered to one problem.
	var wanted = new URLSearchParams( window.location.search ).get( 'problem' );
	var wantedButton = wanted ? Array.prototype.filter.call( root.querySelectorAll( '[data-problem]' ), function ( button ) {
		return button.getAttribute( 'data-problem' ) === wanted;
	} )[ 0 ] : null;

	if ( wantedButton ) {
		wantedButton.click();
	}
}() );
