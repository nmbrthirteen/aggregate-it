/**
 * Tiny dependency-free canvas charts for the Aggregate It dashboard.
 * Exposes window.AggregateItCharts with doughnut / bars / line renderers.
 * Kept self-contained on purpose — no external chart library to bundle.
 */
( function () {
	'use strict';

	var PALETTE = [
		'#2563eb', '#0ea5e9', '#14b8a6', '#22c55e', '#84cc16',
		'#eab308', '#f97316', '#ef4444', '#a855f7', '#64748b'
	];

	function prepare( canvas ) {
		var ratio = window.devicePixelRatio || 1;
		var w = canvas.clientWidth || canvas.width;
		var h = canvas.clientHeight || canvas.height;
		canvas.width = w * ratio;
		canvas.height = h * ratio;
		var ctx = canvas.getContext( '2d' );
		ctx.setTransform( ratio, 0, 0, ratio, 0, 0 );
		ctx.clearRect( 0, 0, w, h );
		return { ctx: ctx, w: w, h: h };
	}

	function color( i ) {
		return PALETTE[ i % PALETTE.length ];
	}

	function niceMax( value ) {
		if ( value <= 0 ) {
			return 1;
		}
		var pow = Math.pow( 10, Math.floor( Math.log10( value ) ) );
		var n = value / pow;
		var step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10;
		return step * pow;
	}

	function doughnut( canvas, data ) {
		var p = prepare( canvas );
		var ctx = p.ctx;
		var cx = p.w / 2;
		var cy = p.h / 2;
		var r = Math.min( cx, cy ) - 8;
		var inner = r * 0.6;
		var total = data.reduce( function ( s, d ) { return s + d.count; }, 0 );

		if ( total === 0 ) {
			ctx.fillStyle = '#e5e7eb';
			ctx.beginPath();
			ctx.arc( cx, cy, r, 0, Math.PI * 2 );
			ctx.arc( cx, cy, inner, 0, Math.PI * 2, true );
			ctx.fill();
			return;
		}

		var start = -Math.PI / 2;
		data.forEach( function ( d, i ) {
			if ( d.count === 0 ) {
				return;
			}
			var angle = ( d.count / total ) * Math.PI * 2;
			ctx.beginPath();
			ctx.moveTo( cx, cy );
			ctx.arc( cx, cy, r, start, start + angle );
			ctx.closePath();
			ctx.fillStyle = color( i );
			ctx.fill();
			start += angle;
		} );

		ctx.globalCompositeOperation = 'destination-out';
		ctx.beginPath();
		ctx.arc( cx, cy, inner, 0, Math.PI * 2 );
		ctx.fill();
		ctx.globalCompositeOperation = 'source-over';

		ctx.fillStyle = '#111827';
		ctx.font = '600 22px sans-serif';
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';
		ctx.fillText( String( total ), cx, cy );
	}

	function axes( ctx, w, h, pad, max ) {
		ctx.strokeStyle = '#e5e7eb';
		ctx.fillStyle = '#9ca3af';
		ctx.lineWidth = 1;
		ctx.font = '11px sans-serif';
		ctx.textAlign = 'right';
		ctx.textBaseline = 'middle';
		var lines = 4;
		for ( var i = 0; i <= lines; i++ ) {
			var y = pad.t + ( ( h - pad.t - pad.b ) * i ) / lines;
			ctx.beginPath();
			ctx.moveTo( pad.l, y );
			ctx.lineTo( w - pad.r, y );
			ctx.stroke();
			var val = max - ( max * i ) / lines;
			ctx.fillText( trim( val ), pad.l - 6, y );
		}
	}

	function trim( v ) {
		if ( v >= 1 || v === 0 ) {
			return String( Math.round( v ) );
		}
		return v.toFixed( 2 );
	}

	function bars( canvas, data, opts ) {
		opts = opts || {};
		var p = prepare( canvas );
		var ctx = p.ctx;
		var pad = { t: 12, r: 10, b: 26, l: 36 };
		var max = niceMax( Math.max.apply( null, data.map( function ( d ) { return d.value; } ).concat( [ 0 ] ) ) );
		axes( ctx, p.w, p.h, pad, max );

		var plotW = p.w - pad.l - pad.r;
		var plotH = p.h - pad.t - pad.b;
		var bw = plotW / data.length;

		data.forEach( function ( d, i ) {
			var bh = max > 0 ? ( d.value / max ) * plotH : 0;
			var x = pad.l + i * bw + bw * 0.18;
			var y = pad.t + plotH - bh;
			ctx.fillStyle = opts.color || '#2563eb';
			ctx.fillRect( x, y, bw * 0.64, bh );
		} );

		labelEvery( ctx, data, pad, plotW, plotH );
	}

	function line( canvas, data ) {
		var p = prepare( canvas );
		var ctx = p.ctx;
		var pad = { t: 12, r: 10, b: 26, l: 36 };
		var max = niceMax( Math.max.apply( null, data.map( function ( d ) { return d.value; } ).concat( [ 0 ] ) ) );
		axes( ctx, p.w, p.h, pad, max );

		var plotW = p.w - pad.l - pad.r;
		var plotH = p.h - pad.t - pad.b;
		var step = data.length > 1 ? plotW / ( data.length - 1 ) : 0;

		function px( i ) { return pad.l + i * step; }
		function py( v ) { return pad.t + plotH - ( max > 0 ? ( v / max ) * plotH : 0 ); }

		ctx.beginPath();
		data.forEach( function ( d, i ) {
			var x = px( i );
			var y = py( d.value );
			i === 0 ? ctx.moveTo( x, y ) : ctx.lineTo( x, y );
		} );
		ctx.lineTo( px( data.length - 1 ), pad.t + plotH );
		ctx.lineTo( px( 0 ), pad.t + plotH );
		ctx.closePath();
		ctx.fillStyle = 'rgba(37,99,235,0.12)';
		ctx.fill();

		ctx.beginPath();
		data.forEach( function ( d, i ) {
			var x = px( i );
			var y = py( d.value );
			i === 0 ? ctx.moveTo( x, y ) : ctx.lineTo( x, y );
		} );
		ctx.strokeStyle = '#2563eb';
		ctx.lineWidth = 2;
		ctx.stroke();

		data.forEach( function ( d, i ) {
			ctx.beginPath();
			ctx.arc( px( i ), py( d.value ), 2.5, 0, Math.PI * 2 );
			ctx.fillStyle = '#2563eb';
			ctx.fill();
		} );

		labelEvery( ctx, data, pad, plotW, plotH );
	}

	function labelEvery( ctx, data, pad, plotW, plotH ) {
		ctx.fillStyle = '#9ca3af';
		ctx.font = '10px sans-serif';
		ctx.textAlign = 'center';
		ctx.textBaseline = 'top';
		var every = Math.ceil( data.length / 7 );
		data.forEach( function ( d, i ) {
			if ( i % every !== 0 && i !== data.length - 1 ) {
				return;
			}
			var x = pad.l + ( data.length > 1 ? ( plotW * i ) / ( data.length - 1 ) : plotW / 2 );
			ctx.fillText( d.label, x, pad.t + plotH + 6 );
		} );
	}

	window.AggregateItCharts = {
		palette: PALETTE,
		color: color,
		doughnut: doughnut,
		bars: bars,
		line: line
	};
} )();
