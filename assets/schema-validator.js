/**
 * A high-level checker for JSON-LD: structure and common mistakes, not the whole
 * schema.org vocabulary. Works in the browser (window.lcpYeastSeoSchema) and in
 * Node (module.exports), so the same code can be tested.
 *
 * validate( text ) returns { ok, errors, warnings, entities, issues } where each
 * issue is { level: 'error' | 'warning' | 'info', message, line?, column? }.
 */
( function ( root, factory ) {
	if ( typeof module === 'object' && module.exports ) {
		module.exports = factory();
	} else {
		root.lcpYeastSeoSchema = factory();
	}
}( typeof self !== 'undefined' ? self : this, function () {
	var KNOWN_TYPES = [
		'Organization', 'Corporation', 'LocalBusiness', 'ProfessionalService', 'HomeAndConstructionBusiness', 'Electrician', 'Plumber',
		'GeneralContractor', 'LegalService', 'AccountingService', 'Dentist', 'Store', 'Restaurant', 'WebSite', 'WebPage', 'CollectionPage',
		'AboutPage', 'ContactPage', 'FAQPage', 'ItemList', 'ListItem', 'BreadcrumbList', 'Article', 'BlogPosting', 'NewsArticle', 'Person',
		'Service', 'Product', 'Offer', 'AggregateOffer', 'AggregateRating', 'Review', 'Rating', 'Event', 'Place', 'PostalAddress',
		'GeoCoordinates', 'ContactPoint', 'ImageObject', 'VideoObject', 'HowTo', 'HowToStep', 'Question', 'Answer', 'Recipe', 'JobPosting',
		'Course', 'OpeningHoursSpecification', 'AdministrativeArea', 'City', 'Country', 'State', 'SearchAction', 'PropertyValue', 'Brand',
		'SoftwareApplication', 'Thing', 'CreativeWork', 'MediaObject', 'Offer', 'QuantitativeValue', 'MonetaryAmount', 'PriceSpecification'
	];

	var LOCAL_TYPES = [ 'LocalBusiness', 'ProfessionalService', 'HomeAndConstructionBusiness', 'Electrician', 'Plumber', 'GeneralContractor',
		'LegalService', 'AccountingService', 'Dentist', 'Store', 'Restaurant' ];
	var ORG_TYPES = [ 'Organization', 'Corporation' ].concat( LOCAL_TYPES );
	var ARTICLE_TYPES = [ 'Article', 'BlogPosting', 'NewsArticle' ];

	// What each family of types needs. Required gaps are errors; recommended ones are advice.
	var RULES = {
		org: { required: [ 'name' ], recommended: [ 'url', 'logo', 'sameAs' ] },
		local: { required: [ 'name', 'address' ], recommended: [ 'telephone', 'openingHoursSpecification', 'areaServed' ] },
		Service: { required: [ 'name' ], recommended: [ 'provider', 'areaServed', 'description' ] },
		article: { required: [ 'headline' ], recommended: [ 'datePublished', 'author', 'image' ] },
		WebPage: { required: [], recommended: [ 'name' ] },
		FAQPage: { required: [ 'mainEntity' ], recommended: [] },
		Question: { required: [ 'name', 'acceptedAnswer' ], recommended: [] },
		Product: { required: [ 'name' ], recommended: [ 'image', 'offers', 'description' ] },
		Event: { required: [ 'name', 'startDate' ], recommended: [ 'location', 'description' ] },
		BreadcrumbList: { required: [ 'itemListElement' ], recommended: [] },
		ImageObject: { required: [], recommended: [ 'url' ] },
		Person: { required: [ 'name' ], recommended: [] },
		JobPosting: { required: [ 'title', 'datePosted', 'description' ], recommended: [ 'hiringOrganization' ] }
	};

	var URL_PROPERTIES = [ 'url', 'logo', 'image', 'contentUrl', 'sameAs', 'mainEntityOfPage' ];
	var DATE_PROPERTIES = [ 'datePublished', 'dateModified', 'foundingDate', 'startDate', 'endDate', 'datePosted', 'validThrough' ];
	var DATE_PATTERN = /^\d{4}(-\d{2}(-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?)?)?$/;
	var PLACEHOLDER = /lorem ipsum|your[_ -]?(business|company|name|url|logo)|\bTODO\b|\bexample\.(com|org)\b|\bxxx+\b/i;

	function withArticle( type ) {
		return ( /^[AEIOU]/.test( type ) ? 'an ' : 'a ' ) + type;
	}

	function typesOf( node ) {
		var type = node[ '@type' ];

		return ( Array.isArray( type ) ? type : [ type ] ).filter( function ( t ) {
			return typeof t === 'string';
		} );
	}

	function family( type ) {
		if ( LOCAL_TYPES.indexOf( type ) > -1 ) {
			return 'local';
		}

		if ( ORG_TYPES.indexOf( type ) > -1 ) {
			return 'org';
		}

		if ( ARTICLE_TYPES.indexOf( type ) > -1 ) {
			return 'article';
		}

		return RULES[ type ] ? type : '';
	}

	function distance( a, b ) {
		var row = [];
		var i;
		var j;
		var prev;
		var tmp;

		for ( j = 0; j <= b.length; j++ ) {
			row[ j ] = j;
		}

		for ( i = 1; i <= a.length; i++ ) {
			prev = row[ 0 ];
			row[ 0 ] = i;

			for ( j = 1; j <= b.length; j++ ) {
				tmp = row[ j ];
				row[ j ] = Math.min( row[ j ] + 1, row[ j - 1 ] + 1, prev + ( a[ i - 1 ] === b[ j - 1 ] ? 0 : 1 ) );
				prev = tmp;
			}
		}

		return row[ b.length ];
	}

	// The closest known type to a name that is not one, or '' when nothing is close.
	function suggest( type ) {
		var lower = type.toLowerCase();
		var best = '';
		var bestDistance = 3;

		KNOWN_TYPES.forEach( function ( known ) {
			var d = known.toLowerCase() === lower ? 0 : distance( lower, known.toLowerCase() );

			if ( d < bestDistance ) {
				best = known;
				bestDistance = d;
			}
		} );

		return best;
	}

	function lineAndColumn( text, position ) {
		var before = text.slice( 0, position );
		var lines = before.split( '\n' );

		return { line: lines.length, column: lines[ lines.length - 1 ].length + 1 };
	}

	// Where a JSON.parse error points, across the wording of different browsers.
	function errorLocation( text, message ) {
		var both = /line (\d+) column (\d+)/i.exec( message );
		var position = /position (\d+)/i.exec( message );

		if ( both ) {
			return { line: parseInt( both[ 1 ], 10 ), column: parseInt( both[ 2 ], 10 ) };
		}

		return position ? lineAndColumn( text, parseInt( position[ 1 ], 10 ) ) : null;
	}

	// Common reasons JSON fails, spotted in the text itself so the message can say what to do.
	function syntaxHints( text, message ) {
		var hints = [];

		if ( /expected ','|after property value|after array element/i.test( message || '' ) ) {
			hints.push( 'Look at the line above the one reported: a comma is usually missing at the end of it.' );
		}

		if ( /<\s*script/i.test( text ) ) {
			hints.push( 'Remove the <script> tags. Paste only the JSON between them.' );
		}

		// Only curly quotes used as JSON quotes (around a name); apostrophes inside text are fine.
		if ( /[“”‘’][^“”‘’\n]*[“”‘’]\s*:/.test( text ) ) {
			hints.push( 'There are curly quotes (“ ”). JSON needs straight quotes ("). Word processors and some editors swap them in.' );
		}

		if ( /,\s*[}\]]/.test( text ) ) {
			hints.push( 'There is a comma after the last item in an object or list. Remove it.' );
		}

		if ( /(^|[{,\s])'[^']*'\s*:/.test( text ) ) {
			hints.push( 'Names are in single quotes. JSON needs double quotes (").' );
		}

		if ( /(^|[\s,{}\[\]])\/\/|\/\*/.test( text ) ) {
			hints.push( 'JSON cannot contain comments. Remove the // or /* */ parts.' );
		}

		return hints;
	}

	function isEmpty( value ) {
		return '' === value || null === value || ( Array.isArray( value ) && 0 === value.length ) || ( value && 'object' === typeof value && ! Array.isArray( value ) && 0 === Object.keys( value ).length );
	}

	function isUrl( value ) {
		return /^(https?:)?\/\/[^\s]+$/i.test( value );
	}

	function collectEntities( data ) {
		if ( Array.isArray( data ) ) {
			return data;
		}

		return Array.isArray( data[ '@graph' ] ) ? data[ '@graph' ] : [ data ];
	}

	function validate( text ) {
		var issues = [];
		var add = function ( level, message ) {
			issues.push( { level: level, message: message } );
		};
		var result = function ( entities ) {
			var errors = issues.filter( function ( i ) {
				return 'error' === i.level;
			} ).length;
			var warnings = issues.filter( function ( i ) {
				return 'warning' === i.level;
			} ).length;

			return { ok: 0 === errors, errors: errors, warnings: warnings, entities: entities || [], issues: issues };
		};
		var data;
		var entities;
		var defined = {};
		var references = [];
		var topContext;

		text = String( text || '' );

		if ( '' === text.trim() ) {
			return result();
		}

		try {
			data = JSON.parse( text );
		} catch ( error ) {
			var where = errorLocation( text, String( error.message ) );
			var syntax = { level: 'error', message: 'This is not valid JSON' + ( where ? ' (line ' + where.line + ', column ' + where.column + ')' : '' ) + '. ' + String( error.message ).replace( /^JSON\.parse: /, '' ) + '.' };

			if ( where ) {
				syntax.line = where.line;
				syntax.column = where.column;
			}

			issues.push( syntax );
			syntaxHints( text, String( error.message ) ).forEach( function ( hint ) {
				add( 'info', hint );
			} );

			return result();
		}

		if ( ! data || 'object' !== typeof data ) {
			add( 'error', 'The schema must be a JSON object (or a list of objects), not a single value.' );
			return result();
		}

		entities = collectEntities( data ).filter( function ( entity ) {
			return entity && 'object' === typeof entity && ! Array.isArray( entity );
		} );

		if ( ! entities.length ) {
			add( 'error', 'No schema entities found. Expect at least one object with an "@type".' );
			return result();
		}

		topContext = ! Array.isArray( data ) && data[ '@context' ];

		entities.forEach( function ( entity, index ) {
			var label = entities.length > 1 ? 'Entity ' + ( index + 1 ) + ( typesOf( entity )[ 0 ] ? ' (' + typesOf( entity )[ 0 ] + ')' : '' ) : 'The schema';
			var context = entity[ '@context' ] || topContext;
			var types = typesOf( entity );

			if ( ! context ) {
				add( 'error', label + ' has no "@context". Add "@context": "https://schema.org".' );
			} else if ( 'string' === typeof context && ! /^https?:\/\/schema\.org\/?$/.test( context.trim() ) ) {
				add( 'warning', label + ' has an unusual "@context" (' + context + '). It should be "https://schema.org".' );
			}

			if ( ! types.length ) {
				add( 'error', label + ' has no "@type", so search engines cannot tell what it describes.' );
				return;
			}

			checkNode( entity, label, true );
		} );

		function checkNode( node, label, top ) {
			var types = typesOf( node );

			types.forEach( function ( type ) {
				var fix;

				if ( /^[a-z]/.test( type ) ) {
					add( 'warning', label + ': "' + type + '" should start with a capital letter. Types are case-sensitive.' );
				} else if ( KNOWN_TYPES.indexOf( type ) < 0 ) {
					fix = suggest( type );

					if ( fix ) {
						add( 'warning', label + ': "' + type + '" is not a type I recognise. Did you mean "' + fix + '"?' );
					}
				}
			} );

			if ( node[ '@id' ] && types.length ) {
				if ( defined[ node[ '@id' ] ] ) {
					add( 'warning', label + ' defines the id ' + node[ '@id' ] + ' again. Each id should be defined once.' );
				}

				defined[ node[ '@id' ] ] = true;
			}

			if ( top ) {
				types.forEach( function ( type ) {
					var rule = RULES[ family( type ) ];

					if ( ! rule ) {
						return;
					}

					rule.required.forEach( function ( property ) {
						if ( undefined === node[ property ] || isEmpty( node[ property ] ) ) {
							add( 'error', label + ' is ' + withArticle( type ) + ' but has no "' + property + '", which it needs.' );
						}
					} );

					rule.recommended.forEach( function ( property ) {
						if ( undefined === node[ property ] ) {
							add( 'info', label + ': ' + withArticle( type ) + ' is stronger with "' + property + '".' );
						}
					} );
				} );
			}

			Object.keys( node ).forEach( function ( key ) {
				var value = node[ key ];

				if ( 0 === key.indexOf( '@' ) && '@id' !== key ) {
					return;
				}

				if ( '@id' === key ) {
					return;
				}

				if ( isEmpty( value ) ) {
					add( 'warning', label + ': "' + key + '" is empty. Fill it in or remove it.' );
					return;
				}

				if ( 'string' === typeof value && PLACEHOLDER.test( value ) ) {
					add( 'warning', label + ': "' + key + '" looks like placeholder text (' + value.slice( 0, 40 ) + ').' );
				}

				if ( URL_PROPERTIES.indexOf( key ) > -1 ) {
					( Array.isArray( value ) ? value : [ value ] ).forEach( function ( item ) {
						if ( 'string' === typeof item && ! isUrl( item ) ) {
							add( 'warning', label + ': "' + key + '" should be a full web address starting with https://, not "' + item.slice( 0, 50 ) + '".' );
						}
					} );
				}

				if ( DATE_PROPERTIES.indexOf( key ) > -1 && 'string' === typeof value && ! DATE_PATTERN.test( value ) ) {
					add( 'warning', label + ': "' + key + '" is "' + value + '", which is not a date search engines read. Use 2026-03-24 or 2026-03-24T09:00:00+00:00.' );
				}

				if ( 'sameAs' === key && 'string' === typeof value ) {
					add( 'info', label + ': "sameAs" works best as a list of addresses, even for one.' );
				}

				if ( value && 'object' === typeof value ) {
					( Array.isArray( value ) ? value : [ value ] ).forEach( function ( item ) {
						if ( ! item || 'object' !== typeof item || Array.isArray( item ) ) {
							return;
						}

						if ( 1 === Object.keys( item ).length && item[ '@id' ] ) {
							references.push( { id: item[ '@id' ], where: label + ' "' + key + '"' } );
						} else if ( typesOf( item ).length ) {
							checkNode( item, label + ' "' + key + '"', false );
						}
					} );
				}
			} );
		}

		references.forEach( function ( reference ) {
			if ( ! defined[ reference.id ] ) {
				add( 'info', reference.where + ' points at ' + reference.id + ', which is not defined in this block. That is fine if it is defined elsewhere on the page, such as your Organization.' );
			}
		} );

		return result( entities.map( function ( entity ) {
			return typesOf( entity ).join( '/' );
		} ).filter( Boolean ) );
	}

	function render( container, text ) {
		var result = validate( text );
		var order = { error: 0, warning: 1, info: 2 };
		var summary;
		var list;

		container.textContent = '';

		if ( '' === String( text || '' ).trim() ) {
			return result;
		}

		summary = document.createElement( 'p' );
		summary.className = 'lcp-schema-check__summary lcp-schema-check__summary--' + ( result.errors ? 'error' : ( result.warnings ? 'warning' : 'ok' ) );
		summary.textContent = result.errors
			? result.errors + ( 1 === result.errors ? ' problem' : ' problems' ) + ' to fix' + ( result.warnings ? ', and ' + result.warnings + ( 1 === result.warnings ? ' warning' : ' warnings' ) : '' ) + '.'
			: ( result.warnings ? 'Valid, with ' + result.warnings + ( 1 === result.warnings ? ' warning' : ' warnings' ) + '.' : 'Looks good' + ( result.entities.length ? ': ' + result.entities.join( ', ' ) : '' ) + '.' );
		container.appendChild( summary );

		if ( result.issues.length ) {
			list = document.createElement( 'ul' );
			list.className = 'lcp-schema-check__list';
			result.issues.slice().sort( function ( a, b ) {
				return order[ a.level ] - order[ b.level ];
			} ).forEach( function ( issue ) {
				var item = document.createElement( 'li' );

				item.className = 'lcp-schema-check__item lcp-schema-check__item--' + issue.level;
				item.textContent = issue.message;
				list.appendChild( item );
			} );
			container.appendChild( list );
		}

		return result;
	}

	// Adds a live result under every textarea marked data-schema-check.
	function attachAll() {
		document.querySelectorAll( 'textarea[data-schema-check]' ).forEach( function ( textarea ) {
			var output = document.createElement( 'div' );
			var timer;

			output.className = 'lcp-schema-check';
			output.setAttribute( 'aria-live', 'polite' );
			textarea.parentNode.insertBefore( output, textarea.nextSibling );
			render( output, textarea.value );
			textarea.addEventListener( 'input', function () {
				clearTimeout( timer );
				timer = setTimeout( function () {
					render( output, textarea.value );
				}, 250 );
			} );
		} );
	}

	if ( typeof document !== 'undefined' ) {
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', attachAll );
		} else {
			attachAll();
		}
	}

	return { validate: validate, render: render, attachAll: attachAll };
} ) );
