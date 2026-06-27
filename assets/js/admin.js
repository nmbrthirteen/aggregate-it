( function () {
	'use strict';

	var cfg = window.AggregateItAdmin || {};
	var charts = window.AggregateItCharts;
	var app = document.getElementById( 'aggregate-it-app' );
	if ( ! app || ! charts ) {
		return;
	}

	var POLL_MS = 10000;
	var inFlight = false;
	var timer = null;
	var stopped = false;

	function api( path, method ) {
		return fetch( cfg.root + path, {
			method: method || 'GET',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }
		} ).then( function ( r ) {
			if ( ! r.ok ) {
				var e = new Error( 'HTTP ' + r.status );
				e.status = r.status;
				throw e;
			}
			return r.json();
		} );
	}

	function status( msg ) {
		var el = document.getElementById( 'ai-status' );
		if ( ! el ) {
			return;
		}
		var body = el.querySelector( 'p' ) || el;
		body.textContent = msg || '';
		el.style.display = msg ? 'block' : 'none';
	}

	function busy( el, on ) {
		if ( ! el ) {
			return;
		}
		el.disabled = on;
		if ( on ) {
			el.setAttribute( 'aria-busy', 'true' );
		} else {
			el.removeAttribute( 'aria-busy' );
		}
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

	function describe( id, title, text ) {
		var el = document.getElementById( id );
		if ( ! el ) {
			return;
		}
		el.setAttribute( 'role', 'img' );
		el.setAttribute( 'aria-label', title + ': ' + text );
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

		var deadEl = app.querySelector( '[data-card="dead_letter"]' );
		if ( deadEl ) {
			deadEl.setAttribute( 'data-tone', Number( c.dead_letter ) > 0 ? 'error' : '' );
		}

		var providerPill = document.getElementById( 'ai-provider-pill' );
		if ( providerPill ) {
			providerPill.textContent = 'Using: ' + ( cfg.provider || 'mock' );
		}

		toggle( 'ai-paused-pill', c.paused );
		toggle( 'ai-resume', c.paused );

		var slices = data.states.map( function ( s, i ) {
			return { state: s.state, label: s.label, count: s.count, color: charts.stateColor( s.state, i ) };
		} );
		var nonZero = slices.filter( function ( s ) { return s.count > 0; } );

		charts.doughnut( document.getElementById( 'ai-chart-states' ), slices );
		renderLegend( nonZero.length ? nonZero : slices );
		describe( 'ai-chart-states', 'Article status', slices.map( function ( s ) { return s.label + ' ' + s.count; } ).join( ', ' ) );

		charts.line( document.getElementById( 'ai-chart-throughput' ),
			data.throughput.map( function ( d ) { return { label: shortDate( d.date ), value: d.count }; } ) );
		describe( 'ai-chart-throughput', 'Posts published per day', data.throughput.map( function ( d ) { return shortDate( d.date ) + ' ' + d.count; } ).join( ', ' ) );

		charts.bars( document.getElementById( 'ai-chart-cost' ),
			data.cost.map( function ( d ) { return { label: shortDate( d.date ), value: d.cost }; } ) );
		describe( 'ai-chart-cost', 'Cost per day', data.cost.map( function ( d ) { return shortDate( d.date ) + ' ' + money( d.cost ); } ).join( ', ' ) );

		renderRecent( data.recent );
		renderEvents( data.events );
	}

	function renderLegend( slices ) {
		var ul = document.getElementById( 'ai-legend-states' );
		if ( ! ul ) {
			return;
		}
		ul.innerHTML = '';
		slices.forEach( function ( s ) {
			var li = document.createElement( 'li' );
			var dot = document.createElement( 'span' );
			dot.className = 'ai-dot';
			dot.style.background = s.color;
			li.appendChild( dot );
			li.appendChild( document.createTextNode( s.label + ' (' + s.count + ')' ) );
			ul.appendChild( li );
		} );
	}

	function renderRecent( rows ) {
		var tbody = document.getElementById( 'ai-recent' );
		if ( ! tbody ) {
			return;
		}
		tbody.innerHTML = '';
		if ( ! rows.length ) {
			tbody.innerHTML = '<tr><td colspan="4" class="ai-empty">No articles yet — add a feed to get started.</td></tr>';
			return;
		}
		rows.forEach( function ( r ) {
			var tr = document.createElement( 'tr' );
			tr.appendChild( cell( '#' + r.id ) );
			tr.appendChild( cell( r.url, true ) );
			var st = cell( '' );
			var badge = document.createElement( 'span' );
			badge.className = 'post-state';
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
		if ( ! ul ) {
			return;
		}
		ul.innerHTML = '';
		if ( ! events.length ) {
			ul.innerHTML = '<li class="ai-empty">Nothing has happened yet.</li>';
			return;
		}
		events.forEach( function ( e ) {
			var li = document.createElement( 'li' );
			li.className = 'ai-event ai-event--' + e.level;
			var time = document.createElement( 'time' );
			time.textContent = e.time;
			li.appendChild( time );
			li.appendChild( document.createTextNode( ' ' + e.message ) );
			ul.appendChild( li );
		} );
	}

	function toggle( id, on ) {
		var el = document.getElementById( id );
		if ( el ) {
			el.classList.toggle( 'ai-hidden', ! on );
		}
	}

	function refresh() {
		if ( inFlight ) {
			return Promise.resolve();
		}
		inFlight = true;
		return api( 'stats' ).then( function ( data ) {
			render( data );
			status( '' );
		} ).catch( function ( err ) {
			if ( err && ( err.status === 401 || err.status === 403 ) ) {
				stopped = true;
				status( cfg.i18n.expired );
			} else {
				status( cfg.i18n.failed );
			}
		} ).then( function () {
			inFlight = false;
		} );
	}

	function schedule() {
		if ( timer ) {
			clearTimeout( timer );
		}
		if ( stopped ) {
			return;
		}
		timer = setTimeout( tick, POLL_MS );
	}

	function tick() {
		if ( document.hidden ) {
			schedule();
			return;
		}
		refresh().then( schedule );
	}

	function action( id, path, working, done ) {
		var el = document.getElementById( id );
		if ( ! el ) {
			return;
		}
		el.addEventListener( 'click', function () {
			busy( el, true );
			status( working );
			api( path, 'POST' )
				.then( function () { return refresh(); } )
				.then( function () { status( done || '' ); } )
				.catch( function () { status( cfg.i18n.failed ); } )
				.then( function () { busy( el, false ); } );
		} );
	}

	var refreshBtn = document.getElementById( 'ai-refresh' );
	if ( refreshBtn ) {
		refreshBtn.addEventListener( 'click', function () {
			busy( refreshBtn, true );
			status( cfg.i18n.refreshing );
			refresh().then( function () {
				busy( refreshBtn, false );
				schedule();
			} );
		} );
	}

	action( 'ai-seed', 'seed', cfg.i18n.running, cfg.i18n.seeded );
	action( 'ai-run', 'run', cfg.i18n.running, cfg.i18n.started );
	action( 'ai-resume', 'resume', cfg.i18n.running, cfg.i18n.resumed );

	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden && ! stopped ) {
			tick();
		}
	} );

	refresh().then( schedule );
} )();
