const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { validate } = require( '../../assets/schema-validator.js' );

const messages = ( r, level ) => r.issues.filter( ( i ) => ! level || i.level === level ).map( ( i ) => i.message );
const has = ( r, level, text ) => messages( r, level ).some( ( m ) => m.includes( text ) );

const service = {
	'@context': 'https://schema.org',
	'@type': 'Service',
	'@id': 'https://example.test/services/x/#service',
	name: 'Bereavement House Clearance',
	description: 'Clearance across the island.',
	url: 'https://example.test/services/x/',
	provider: { '@type': 'LocalBusiness', '@id': 'https://example.test/#organization', name: 'Tidy', url: 'https://example.test/' },
	areaServed: [ { '@type': 'AdministrativeArea', name: 'Isle of Man' }, { '@type': 'City', name: 'Douglas' } ],
	serviceType: 'Bereavement House Clearance',
	mainEntityOfPage: { '@id': 'https://example.test/services/x/#webpage' },
};

test( 'an empty box has nothing to say', () => {
	const r = validate( '  ' );
	assert.strictEqual( r.ok, true );
	assert.deepStrictEqual( r.issues, [] );
} );

test( 'a complete Service has no errors or warnings', () => {
	const r = validate( JSON.stringify( service, null, 2 ) );
	assert.strictEqual( r.ok, true );
	assert.strictEqual( r.errors, 0 );
	assert.strictEqual( r.warnings, 0 );
	assert.deepStrictEqual( r.entities, [ 'Service' ] );
} );

test( 'a reference to an entity that is not in the block is only information', () => {
	const r = validate( JSON.stringify( service ) );
	assert.ok( has( r, 'info', 'mainEntityOfPage' ) );
	assert.strictEqual( r.errors + r.warnings, 0 );
} );

test( 'broken JSON gives a line and column', () => {
	const r = validate( '{\n  "@context": "https://schema.org",\n  "@type": "Service"\n  "name": "X"\n}' );
	assert.strictEqual( r.ok, false );
	assert.strictEqual( r.issues[ 0 ].level, 'error' );
	assert.ok( r.issues[ 0 ].line >= 3 && r.issues[ 0 ].line <= 4, 'line ' + r.issues[ 0 ].line );
	assert.match( r.issues[ 0 ].message, /line \d+, column \d+/ );
} );

test( 'curly quotes, trailing commas and pasted script tags are explained', () => {
	assert.ok( has( validate( '{ “@type”: “Service” }' ), 'info', 'curly quotes' ) );
	assert.ok( has( validate( '{ "@type": "Service", }' ), 'info', 'comma after the last item' ) );
	assert.ok( has( validate( '<script type="application/ld+json">{ "@type": "Service" }</script>' ), 'info', 'Remove the <script> tags' ) );
	assert.ok( has( validate( "{ '@type': 'Service' }" ), 'info', 'single quotes' ) );
	assert.ok( has( validate( '{ // note\n "@type": "Service" }' ), 'info', 'cannot contain comments' ) );
} );

test( 'apostrophes and quotes inside text are not mistaken for curly JSON quotes', () => {
	const r = validate( '{ "@context": "https://schema.org", "@type": "Service", "name": "Tidy\u2019s \u201Cbest\u201D clearance" "x": 1 }' );
	assert.strictEqual( r.ok, false );
	assert.ok( ! has( r, 'info', 'curly quotes' ), 'the error is a missing comma, not quotes' );
} );

test( 'a missing comma says to look at the line above', () => {
	assert.ok( has( validate( '{\n  "@type": "Service"\n  "name": "X"\n}' ), 'info', 'line above' ) );
} );

test( 'a bare value is not a schema', () => {
	assert.ok( has( validate( '"hello"' ), 'error', 'JSON object' ) );
	assert.ok( has( validate( '42' ), 'error', 'JSON object' ) );
	assert.ok( has( validate( '[]' ), 'error', 'No schema entities' ) );
} );

test( 'a missing @context or @type is an error', () => {
	assert.ok( has( validate( '{ "@type": "Service", "name": "X" }' ), 'error', '@context' ) );
	assert.ok( has( validate( '{ "@context": "https://schema.org", "name": "X" }' ), 'error', '"@type"' ) );
} );

test( 'a top-level @context covers every entity in a graph', () => {
	const r = validate( JSON.stringify( { '@context': 'https://schema.org', '@graph': [ { '@type': 'WebSite', name: 'X' }, { '@type': 'Service', name: 'Y' } ] } ) );
	assert.ok( ! has( r, 'error', '@context' ) );
	assert.deepStrictEqual( r.entities, [ 'WebSite', 'Service' ] );
} );

test( 'a list of separate blocks is accepted, and each needs its own context', () => {
	const r = validate( JSON.stringify( [ { '@context': 'https://schema.org', '@type': 'Service', name: 'A' }, { '@type': 'Service', name: 'B' } ] ) );
	assert.ok( has( r, 'error', 'Entity 2' ) );
	assert.ok( ! has( r, 'error', 'Entity 1' ) );
} );

test( 'an odd @context is a warning', () => {
	assert.ok( has( validate( JSON.stringify( { '@context': 'https://example.test/vocab', '@type': 'Service', name: 'X' } ) ), 'warning', 'unusual "@context"' ) );
} );

test( 'misspelt and lower-case types are caught with a suggestion', () => {
	const typo = validate( JSON.stringify( { '@context': 'https://schema.org', '@type': 'LocalBussiness', name: 'X', address: 'a' } ) );
	assert.ok( has( typo, 'warning', 'Did you mean "LocalBusiness"' ) );

	const lower = validate( JSON.stringify( { '@context': 'https://schema.org', '@type': 'service', name: 'X' } ) );
	assert.ok( has( lower, 'warning', 'capital letter' ) );
} );

test( 'a type that is simply unusual is not nagged about', () => {
	const r = validate( JSON.stringify( { '@context': 'https://schema.org', '@type': 'WineRegion', name: 'X' } ) );
	assert.strictEqual( r.warnings, 0 );
} );

test( 'each type has its required properties checked', () => {
	assert.ok( has( validate( '{"@context":"https://schema.org","@type":"Service"}' ), 'error', 'no "name"' ) );
	assert.ok( has( validate( '{"@context":"https://schema.org","@type":"LocalBusiness","name":"X"}' ), 'error', 'no "address"' ) );
	assert.ok( has( validate( '{"@context":"https://schema.org","@type":"FAQPage"}' ), 'error', 'no "mainEntity"' ) );
	assert.ok( has( validate( '{"@context":"https://schema.org","@type":"BlogPosting","name":"X"}' ), 'error', 'no "headline"' ) );
	assert.ok( has( validate( '{"@context":"https://schema.org","@type":"Event","name":"X"}' ), 'error', 'no "startDate"' ) );
} );

test( 'recommended properties are advice, not errors', () => {
	const r = validate( '{"@context":"https://schema.org","@type":"Service","name":"X"}' );
	assert.strictEqual( r.ok, true );
	assert.ok( has( r, 'info', '"provider"' ) );
} );

test( 'subtypes use their parent type\'s rules', () => {
	assert.ok( has( validate( '{"@context":"https://schema.org","@type":"Electrician","name":"X"}' ), 'error', 'no "address"' ) );
	assert.ok( has( validate( '{"@context":"https://schema.org","@type":["Organization","LocalBusiness"],"name":"X"}' ), 'error', 'no "address"' ) );
} );

test( 'an id defined twice is a warning', () => {
	const r = validate( JSON.stringify( { '@context': 'https://schema.org', '@graph': [ { '@type': 'WebSite', '@id': 'https://e.test/#website', name: 'A' }, { '@type': 'WebPage', '@id': 'https://e.test/#website', name: 'B' } ] } ) );
	assert.ok( has( r, 'warning', 'defines the id' ) );
} );

test( 'a reference that is defined in the same block is not flagged', () => {
	const r = validate( JSON.stringify( { '@context': 'https://schema.org', '@graph': [ { '@type': 'WebPage', '@id': 'https://e.test/#webpage', name: 'A', isPartOf: { '@id': 'https://e.test/#website' } }, { '@type': 'WebSite', '@id': 'https://e.test/#website', name: 'B' } ] } ) );
	assert.ok( ! has( r, 'info', 'not defined in this block' ) );
} );

test( 'empty values, relative addresses, bad dates and placeholders are warnings', () => {
	const r = validate( JSON.stringify( { '@context': 'https://schema.org', '@type': 'Service', name: 'X', description: '', url: '/services/x/', sameAs: [ 'https://ok.test', 'facebook.com/x' ], foundingDate: 'last year', areaServed: [], provider: { '@id': 'https://e.test/#organization' }, serviceType: 'Lorem ipsum dolor' } ) );
	assert.ok( has( r, 'warning', '"description" is empty' ) );
	assert.ok( has( r, 'warning', '"areaServed" is empty' ) );
	assert.ok( has( r, 'warning', '"url" should be a full web address' ) );
	assert.ok( has( r, 'warning', 'facebook.com/x' ) );
	assert.ok( has( r, 'warning', '"foundingDate" is "last year"' ) );
	assert.ok( has( r, 'warning', 'placeholder' ) );
} );

test( 'good dates in the usual forms pass', () => {
	for ( const d of [ '2026', '2026-03', '2026-03-24', '2026-03-24T09:00:00+00:00', '2026-03-24T09:00:00Z', '2026-03-24 09:00' ] ) {
		const r = validate( JSON.stringify( { '@context': 'https://schema.org', '@type': 'Service', name: 'X', datePublished: d } ) );
		assert.ok( ! has( r, 'warning', 'datePublished' ), d );
	}
} );

test( 'nested entities are checked for type typos too', () => {
	const r = validate( JSON.stringify( { '@context': 'https://schema.org', '@type': 'Service', name: 'X', areaServed: { '@type': 'AdministrativeArea', name: 'IoM' }, provider: { '@type': 'LocalBussiness', name: 'T' } } ) );
	assert.ok( has( r, 'warning', 'Did you mean "LocalBusiness"' ) );
} );

test( 'a single sameAs string is advised to be a list', () => {
	assert.ok( has( validate( '{"@context":"https://schema.org","@type":"Organization","name":"X","sameAs":"https://a.test"}' ), 'info', 'list of addresses' ) );
} );

test( 'a summary can be rendered into an element', () => {
	const { render } = require( '../../assets/schema-validator.js' );
	assert.strictEqual( typeof render, 'function' );
} );
