( function () {
	'use strict';

	var FONT = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif';

	var PALETTE = [
		'#2271b1', '#3582c4', '#4f94d4', '#72aee6', '#0ea5e9',
		'#14b8a6', '#a855f7', '#8a6500', '#646970', '#9ca3af'
	];

	function theme() {
		var el = document.querySelector( '.aggregate-it' ) || document.documentElement;
		var cs = window.getComputedStyle( el );
		function v( name, fallback ) {
			return ( cs.getPropertyValue( name ) || '' ).trim() || fallback;
		}
		return {
			accent: v( '--ai-accent', '#2271b1' ),
			ink: v( '--ai-ink', '#1d2327' ),
			muted: v( '--ai-muted', '#646970' ),
			line: v( '--ai-line', '#dcdcde' ),
			success: v( '--ai-success', '#008a20' ),
			warning: v( '--ai-warning', '#bd8600' ),
			error: v( '--ai-error', '#d63638' )
		};
	}

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
		var th = theme();
		var cx = p.w / 2;
		var cy = p.h / 2;
		var r = Math.min( cx, cy ) - 8;
		var inner = r * 0.6;
		var total = data.reduce( function ( s, d ) { return s + d.count; }, 0 );

		if ( total === 0 ) {
			ctx.fillStyle = th.line;
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
			ctx.fillStyle = d.color || color( i );
			ctx.fill();
			start += angle;
		} );

		ctx.globalCompositeOperation = 'destination-out';
		ctx.beginPath();
		ctx.arc( cx, cy, inner, 0, Math.PI * 2 );
		ctx.fill();
		ctx.globalCompositeOperation = 'source-over';

		ctx.fillStyle = th.ink;
		ctx.font = '600 22px ' + FONT;
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';
		ctx.fillText( String( total ), cx, cy );
	}

	function axes( ctx, w, h, pad, max, th ) {
		ctx.strokeStyle = th.line;
		ctx.fillStyle = th.muted;
		ctx.lineWidth = 1;
		ctx.font = '11px ' + FONT;
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
		var th = theme();
		var pad = { t: 12, r: 10, b: 26, l: 36 };
		var max = niceMax( Math.max.apply( null, data.map( function ( d ) { return d.value; } ).concat( [ 0 ] ) ) );
		axes( ctx, p.w, p.h, pad, max, th );

		var plotW = p.w - pad.l - pad.r;
		var plotH = p.h - pad.t - pad.b;
		var bw = plotW / data.length;

		data.forEach( function ( d, i ) {
			var bh = max > 0 ? ( d.value / max ) * plotH : 0;
			var x = pad.l + i * bw + bw * 0.18;
			var y = pad.t + plotH - bh;
			ctx.fillStyle = opts.color || th.accent;
			ctx.fillRect( x, y, bw * 0.64, bh );
		} );

		labelEvery( ctx, data, pad, plotW, plotH, th );
	}

	function line( canvas, data ) {
		var p = prepare( canvas );
		var ctx = p.ctx;
		var th = theme();
		var pad = { t: 12, r: 10, b: 26, l: 36 };
		var max = niceMax( Math.max.apply( null, data.map( function ( d ) { return d.value; } ).concat( [ 0 ] ) ) );
		axes( ctx, p.w, p.h, pad, max, th );

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
		ctx.globalAlpha = 0.12;
		ctx.fillStyle = th.accent;
		ctx.fill();
		ctx.globalAlpha = 1;

		ctx.beginPath();
		data.forEach( function ( d, i ) {
			var x = px( i );
			var y = py( d.value );
			i === 0 ? ctx.moveTo( x, y ) : ctx.lineTo( x, y );
		} );
		ctx.strokeStyle = th.accent;
		ctx.lineWidth = 2;
		ctx.stroke();

		data.forEach( function ( d, i ) {
			ctx.beginPath();
			ctx.arc( px( i ), py( d.value ), 2.5, 0, Math.PI * 2 );
			ctx.fillStyle = th.accent;
			ctx.fill();
		} );

		labelEvery( ctx, data, pad, plotW, plotH, th );
	}

	function labelEvery( ctx, data, pad, plotW, plotH, th ) {
		ctx.fillStyle = th.muted;
		ctx.font = '10px ' + FONT;
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

	function stateColor( state, i ) {
		var th = theme();
		if ( state === 'published' || state === 'active' ) {
			return th.success;
		}
		if ( state === 'dead_letter' || state === 'dead' ) {
			return th.error;
		}
		if ( state === 'paused' ) {
			return th.warning;
		}
		return color( i );
	}

	window.AggregateItCharts = {
		palette: PALETTE,
		color: color,
		stateColor: stateColor,
		theme: theme,
		doughnut: doughnut,
		bars: bars,
		line: line
	};
} )();
