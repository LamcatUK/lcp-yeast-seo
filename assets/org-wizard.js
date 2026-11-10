( function () {
	var root = document.querySelector( '[data-wizard]' );
	var config = window.lcpYeastSeoWizard;
	var form = document.getElementById( 'lcp-yeast-seo-org-wizard' );

	if ( ! root || ! config || ! form ) {
		return;
	}

	var steps = Array.prototype.slice.call( root.querySelectorAll( '[data-wizard-step]' ) );
	var tabs = Array.prototype.slice.call( root.querySelectorAll( '[data-wizard-tab]' ) );
	var back = root.querySelector( '[data-wizard-back]' );
	var next = root.querySelector( '[data-wizard-next]' );
	var save = root.querySelector( '[data-wizard-save]' );
	var position = root.querySelector( '[data-wizard-position]' );
	var review = root.querySelector( '[data-wizard-review]' );
	var current = 0;
	var last = steps.length - 1;

	function show( index ) {
		current = Math.max( 0, Math.min( last, index ) );

		steps.forEach( function ( step, i ) {
			step.hidden = i !== current;
		} );
		tabs.forEach( function ( tab, i ) {
			tab.classList.toggle( 'is-current', i === current );
			tab.classList.toggle( 'is-done', i < current );
		} );
		back.hidden = 0 === current;
		next.hidden = current === last;
		save.hidden = true;
		position.textContent = 'Step ' + ( current + 1 ) + ' of ' + steps.length;

		if ( current === last ) {
			loadPreview();
		}
	}

	function element( tag, text, className ) {
		var node = document.createElement( tag );

		if ( text ) {
			node.textContent = text;
		}

		if ( className ) {
			node.className = className;
		}

		return node;
	}

	function list( items ) {
		var ul = element( 'ul', '', 'lcp-ys-wiz__list' );

		items.forEach( function ( item ) {
			ul.appendChild( element( 'li', item ) );
		} );

		return ul;
	}

	function loadPreview() {
		var body = new FormData( form );

		body.set( 'action', 'lcp_yeast_seo_org_wizard_preview' );
		review.textContent = '';
		review.appendChild( element( 'p', 'Working it out...' ) );
		save.hidden = true;

		fetch( config.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				var data;
				var details;

				if ( ! result || ! result.success ) {
					throw new Error( 'The preview could not be built.' );
				}

				data = result.data;
				review.textContent = '';
				review.appendChild( element( 'p', ( null === data.before ? 'New schema. ' : 'Score: ' + data.before + ' to ' ) + ( null === data.before ? 'Score ' : '' ) + data.after + ' out of 100.', 'lcp-ys-wiz__score' ) );

				if ( data.switches ) {
					review.appendChild( element( 'p', 'Saving moves your details from the form fields into the JSON-LD source.', 'description' ) );
				}

				if ( data.added.length ) {
					review.appendChild( element( 'h4', 'Added or changed' ) );
					review.appendChild( list( data.added ) );
				}

				if ( data.removed.length ) {
					review.appendChild( element( 'h4', 'Removed' ) );
					review.appendChild( list( data.removed ) );
				}

				if ( ! data.added.length && ! data.removed.length ) {
					review.appendChild( element( 'p', 'Nothing would change.' ) );
				}

				if ( data.problems.length ) {
					review.appendChild( element( 'h4', 'Problems' ) );
					review.appendChild( list( data.problems ) );
				}

				details = element( 'details' );
				details.appendChild( element( 'summary', 'Show the JSON-LD' ) );
				details.appendChild( element( 'pre', data.json ) );
				review.appendChild( details );

				save.hidden = 0 !== data.problems.length;
			} )
			.catch( function ( error ) {
				review.textContent = '';
				review.appendChild( element( 'p', error.message, 'lcp-ys-wiz__error' ) );
			} );
	}

	back.addEventListener( 'click', function () {
		show( current - 1 );
	} );
	next.addEventListener( 'click', function () {
		show( current + 1 );
	} );

	// Enter in a text box moves on; it must not submit the form, which saves.
	root.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' === event.key && event.target.matches( 'input:not([type="submit"])' ) ) {
			event.preventDefault();

			if ( current < last ) {
				show( current + 1 );
			}
		}
	} );

	// "I work from home": hide the street address and map position, keeping what was there in case it is unticked.
	( function () {
		var box = root.querySelector( '[data-wizard-home]' );
		var hideable = [ root.querySelector( '[data-wizard-address]' ), root.querySelector( '[data-wizard-geo]' ) ];
		var kept = [];

		function apply() {
			hideable.forEach( function ( area ) {
				area.hidden = box.checked;
			} );

			hideable.forEach( function ( area ) {
				area.querySelectorAll( 'input' ).forEach( function ( input ) {
					if ( box.checked ) {
						kept.push( [ input, input.value ] );
						input.value = '';
					}
				} );
			} );

			if ( ! box.checked ) {
				kept.forEach( function ( pair ) {
					pair[ 0 ].value = pair[ 0 ].value || pair[ 1 ];
				} );
				kept = [];
			}
		}

		box.addEventListener( 'change', apply );

		if ( box.checked ) {
			hideable.forEach( function ( area ) {
				area.hidden = true;
			} );
		}
	}() );

	show( 0 );
}() );
