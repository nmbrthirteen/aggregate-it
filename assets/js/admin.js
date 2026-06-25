( function () {
	'use strict';

	var cfg = window.AggregateItAdmin || {};
	var charts = window.AggregateItCharts;
	var app = document.getElementById( 'aggregate-it-app' );
	if ( ! app || ! charts ) {
		return;
	}

	function api( path, method ) {
		return fetch( cfg.root + path, {
			method: method || 'GET',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }
		} ).then( function ( r ) {
			if ( ! r.ok ) {
				throw new Error( 'HTTP ' + r.status );
			}
			return r.json();
		} );
	}

	function status( msg ) {
		var el = document.getElementById( 'ai-status' );
		el.textContent = msg || '';
		el.style.display = msg ? 'block' : 'none';
	}

	function shortDate( iso ) {
		return iso ? iso.slice( 5 ) : '';
	}

	function money( v ) {
		return '$' + ( Number( v ) || 0 ).toFixed( 2 );
	}

	function setCard( key, value ) {
		var el = app.querySelector( '[data-card="' + key + '"]' );
		if ( el ) {
			el.textContent = value;
		}
	}

	function render( data ) {
		var c = data.cards;

		setCard( 'total_items', c.total_items );
		setCard( 'published', c.published );
		setCard( 'in_pipeline', c.in_pipeline );
		setCard( 'dead_letter', c.dead_letter );
		setCard( 'clusters', c.clusters );
		setCard( 'entities', c.entities );
		setCard( 'spend_today', money( c.spend_today ) + ' / ' + money( c.spend_cap ) );
		setCard( 'spend_month', money( c.spend_month ) );

		var providerPill = document.getElementById( 'ai-provider-pill' );
		providerPill.textContent = 'Using: ' + ( cfg.provider || 'mock' );

		toggle( 'ai-paused-pill', c.paused );
		toggle( 'ai-resume', c.paused );

		var states = data.states.filter( function ( s ) { return s.count > 0; } );
		charts.doughnut( document.getElementById( 'ai-chart-states' ),
			data.states.map( function ( s ) { return { label: s.label, count: s.count }; } ) );
		renderLegend( states.length ? states : data.states );

		charts.line( document.getElementById( 'ai-chart-throughput' ),
			data.throughput.map( function ( d ) { return { label: shortDate( d.date ), value: d.count }; } ) );

		charts.bars( document.getElementById( 'ai-chart-cost' ),
			data.cost.map( function ( d ) { return { label: shortDate( d.date ), value: d.cost }; } ), { color: '#14b8a6' } );

		renderRecent( data.recent );
		renderEvents( data.events );
	}

	function renderLegend( states ) {
		var ul = document.getElementById( 'ai-legend-states' );
		ul.innerHTML = '';
		states.forEach( function ( s, i ) {
			var li = document.createElement( 'li' );
			var dot = document.createElement( 'span' );
			dot.className = 'ai-dot';
			dot.style.background = charts.color( i );
			li.appendChild( dot );
			li.appendChild( document.createTextNode( s.label + ' (' + s.count + ')' ) );
			ul.appendChild( li );
		} );
	}

	function renderRecent( rows ) {
		var tbody = document.getElementById( 'ai-recent' );
		tbody.innerHTML = '';
		if ( ! rows.length ) {
			tbody.innerHTML = '<tr><td colspan="4" class="ai-empty">No articles yet — try “Add sample articles”.</td></tr>';
			return;
		}
		rows.forEach( function ( r ) {
			var tr = document.createElement( 'tr' );
			tr.appendChild( cell( '#' + r.id ) );
			tr.appendChild( cell( r.url, true ) );
			var st = cell( '' );
			var badge = document.createElement( 'span' );
			badge.className = 'ai-state ai-state--' + r.state;
			badge.textContent = r.state.replace( /_/g, ' ' );
			st.appendChild( badge );
			tr.appendChild( st );
			tr.appendChild( cell( r.updated_at ) );
			tbody.appendChild( tr );
		} );
	}

	function cell( text, truncate ) {
		var td = document.createElement( 'td' );
		td.textContent = text;
		if ( truncate ) {
			td.className = 'ai-trunc';
		}
		return td;
	}

	function renderEvents( events ) {
		var ul = document.getElementById( 'ai-events' );
		ul.innerHTML = '';
		if ( ! events.length ) {
			ul.innerHTML = '<li class="ai-empty">Nothing has happened yet.</li>';
			return;
		}
		events.forEach( function ( e ) {
			var li = document.createElement( 'li' );
			li.className = 'ai-event ai-event--' + e.level;
			li.innerHTML = '<time>' + e.time + '</time> ' + escapeHtml( e.message );
			ul.appendChild( li );
		} );
	}

	function escapeHtml( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s;
		return d.innerHTML;
	}

	function toggle( id, on ) {
		var el = document.getElementById( id );
		if ( el ) {
			el.classList.toggle( 'ai-hidden', ! on );
		}
	}

	function refresh() {
		return api( 'stats' ).then( render ).catch( function () {
			status( cfg.i18n.failed );
		} );
	}

	function bind( id, handler ) {
		var el = document.getElementById( id );
		if ( el ) {
			el.addEventListener( 'click', handler );
		}
	}

	bind( 'ai-refresh', function () {
		status( cfg.i18n.refreshing );
		refresh().then( function () { status( '' ); } );
	} );

	bind( 'ai-seed', function () {
		status( cfg.i18n.running );
		api( 'seed', 'POST' ).then( function () {
			status( cfg.i18n.seeded );
			setTimeout( refresh, 800 );
		} ).catch( function () { status( cfg.i18n.failed ); } );
	} );

	bind( 'ai-run', function () {
		status( cfg.i18n.running );
		api( 'run', 'POST' ).then( function () {
			setTimeout( function () { refresh().then( function () { status( '' ); } ); }, 800 );
		} ).catch( function () { status( cfg.i18n.failed ); } );
	} );

	bind( 'ai-resume', function () {
		api( 'resume', 'POST' ).then( function () {
			status( cfg.i18n.resumed );
			refresh();
		} ).catch( function () { status( cfg.i18n.failed ); } );
	} );

	refresh();
	setInterval( refresh, 10000 );
} )();
