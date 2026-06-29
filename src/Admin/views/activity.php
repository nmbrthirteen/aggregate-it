<?php
/**
 * @var array<int,array<string,mixed>> $rows
 * @var array{level:string,type:string,item_id:int,search:string} $filters
 * @var string[] $levels
 * @var string[] $types
 * @var int $total
 * @var int $page
 * @var int $pages
 */

defined( 'ABSPATH' ) || exit;

$base = admin_url( 'admin.php?page=aggregate-it-activity' );

$label_for = static function ( string $state ): string {
	return $state === '' ? '' : ucwords( str_replace( '_', ' ', $state ) );
};
?>
<div class="wrap aggregate-it">
	<h1><?php esc_html_e( 'Activity', 'aggregate-it' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Every step the pipeline takes — what was imported, extracted, published, skipped, and why.', 'aggregate-it' ); ?></p>

	<form method="get" class="ai-act-filters">
		<input type="hidden" name="page" value="aggregate-it-activity">
		<?php if ( $filters['item_id'] ) : ?>
			<input type="hidden" name="item" value="<?php echo esc_attr( (string) $filters['item_id'] ); ?>">
		<?php endif; ?>

		<select name="level">
			<option value=""><?php esc_html_e( 'All levels', 'aggregate-it' ); ?></option>
			<?php foreach ( $levels as $level ) : ?>
				<option value="<?php echo esc_attr( $level ); ?>" <?php selected( $filters['level'], $level ); ?>><?php echo esc_html( ucfirst( $level ) ); ?></option>
			<?php endforeach; ?>
		</select>

		<select name="type">
			<option value=""><?php esc_html_e( 'All steps', 'aggregate-it' ); ?></option>
			<?php foreach ( $types as $type ) : ?>
				<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $filters['type'], $type ); ?>><?php echo esc_html( $label_for( $type ) ); ?></option>
			<?php endforeach; ?>
		</select>

		<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search messages…', 'aggregate-it' ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'aggregate-it' ); ?></button>
		<?php if ( $filters['level'] || $filters['type'] || $filters['search'] || $filters['item_id'] ) : ?>
			<a class="button-link" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Clear', 'aggregate-it' ); ?></a>
		<?php endif; ?>

		<label class="ai-act-live">
			<input type="checkbox" id="ai-act-live" checked> <?php esc_html_e( 'Live', 'aggregate-it' ); ?>
		</label>
	</form>

	<table class="widefat striped ai-act-table" id="ai-act-table"
		data-level="<?php echo esc_attr( $filters['level'] ); ?>"
		data-type="<?php echo esc_attr( $filters['type'] ); ?>"
		data-item="<?php echo esc_attr( (string) $filters['item_id'] ); ?>"
		data-search="<?php echo esc_attr( $filters['search'] ); ?>">
		<thead>
			<tr>
				<th class="ai-act-col-time"><?php esc_html_e( 'When', 'aggregate-it' ); ?></th>
				<th class="ai-act-col-step"><?php esc_html_e( 'Step', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'What happened', 'aggregate-it' ); ?></th>
			</tr>
		</thead>
		<tbody id="ai-act-body">
			<?php if ( ! $rows ) : ?>
				<tr class="ai-act-empty"><td colspan="3"><?php esc_html_e( 'Nothing here yet.', 'aggregate-it' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$transition = $row['from_state'] && $row['to_state'] && $row['from_state'] !== $row['to_state']
					? $label_for( $row['from_state'] ) . ' → ' . $label_for( $row['to_state'] )
					: '';
				$detail_json = $row['detail'] ? wp_json_encode( $row['detail'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : '';
				?>
				<tr class="ai-act ai-act--<?php echo esc_attr( $row['level'] ); ?>">
					<td class="ai-act-time"><?php echo esc_html( $row['time'] ); ?></td>
					<td class="ai-act-step"><?php echo esc_html( $label_for( $row['type'] ) ); ?></td>
					<td class="ai-act-msg">
						<?php echo esc_html( $row['message'] ); ?>
						<?php if ( $transition || $detail_json ) : ?>
							<details class="ai-act-detail">
								<summary><?php esc_html_e( 'Details', 'aggregate-it' ); ?></summary>
								<?php if ( $transition ) : ?><p class="ai-act-transition"><?php echo esc_html( $transition ); ?></p><?php endif; ?>
								<?php if ( $detail_json ) : ?><pre><?php echo esc_html( $detail_json ); ?></pre><?php endif; ?>
							</details>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<?php
		$qs       = array_filter(
			[
				'page'  => 'aggregate-it-activity',
				'level' => $filters['level'],
				'type'  => $filters['type'],
				'item'  => $filters['item_id'] ?: '',
				's'     => $filters['search'],
			]
		);
		$page_url = static function ( int $p ) use ( $qs ) {
			$qs['paged'] = $p;
			return esc_url( admin_url( 'admin.php?' . http_build_query( $qs ) ) );
		};
		?>
		<p class="tablenav-pages ai-act-pages">
			<?php if ( $page > 1 ) : ?><a class="button" href="<?php echo $page_url( $page - 1 ); ?>">‹ <?php esc_html_e( 'Newer', 'aggregate-it' ); ?></a><?php endif; ?>
			<span class="ai-act-pageinfo"><?php echo esc_html( sprintf( /* translators: 1: current page, 2: total pages */ __( 'Page %1$d of %2$d', 'aggregate-it' ), $page, $pages ) ); ?></span>
			<?php if ( $page < $pages ) : ?><a class="button" href="<?php echo $page_url( $page + 1 ); ?>"><?php esc_html_e( 'Older', 'aggregate-it' ); ?> ›</a><?php endif; ?>
		</p>
	<?php endif; ?>
</div>

<script>
// AggregateItAdmin is localized with the footer script, so wait for load before reading it.
window.addEventListener( 'load', function () {
	var cfg = window.AggregateItAdmin || {};
	var table = document.getElementById( 'ai-act-table' );
	var body = document.getElementById( 'ai-act-body' );
	var live = document.getElementById( 'ai-act-live' );
	if ( ! cfg.root || ! table || ! body || ! live ) {
		return;
	}

	var i18n = cfg.i18n || {};

	// Live refresh only makes sense on the first page with no manual paging.
	var onFirstPage = window.location.search.indexOf( 'paged=' ) === -1;
	if ( ! onFirstPage ) {
		live.checked = false;
		live.disabled = true;
	}
	var stepLabel = function ( s ) {
		return s ? s.replace( /_/g, ' ' ).replace( /\b\w/g, function ( c ) { return c.toUpperCase(); } ) : '';
	};

	function rowEl( r ) {
		var tr = document.createElement( 'tr' );
		tr.className = 'ai-act ai-act--' + r.level;

		var time = document.createElement( 'td' );
		time.className = 'ai-act-time';
		time.textContent = r.time;
		tr.appendChild( time );

		var step = document.createElement( 'td' );
		step.className = 'ai-act-step';
		step.textContent = stepLabel( r.type );
		tr.appendChild( step );

		var msg = document.createElement( 'td' );
		msg.className = 'ai-act-msg';
		msg.appendChild( document.createTextNode( r.message ) );

		var transition = r.from_state && r.to_state && r.from_state !== r.to_state
			? stepLabel( r.from_state ) + ' → ' + stepLabel( r.to_state ) : '';
		if ( transition || r.detail ) {
			var det = document.createElement( 'details' );
			det.className = 'ai-act-detail';
			var sum = document.createElement( 'summary' );
			sum.textContent = i18n.details || 'Details';
			det.appendChild( sum );
			if ( transition ) {
				var p = document.createElement( 'p' );
				p.className = 'ai-act-transition';
				p.textContent = transition;
				det.appendChild( p );
			}
			if ( r.detail ) {
				var pre = document.createElement( 'pre' );
				pre.textContent = JSON.stringify( r.detail, null, 2 );
				det.appendChild( pre );
			}
			msg.appendChild( det );
		}
		tr.appendChild( msg );
		return tr;
	}

	function query() {
		var p = new URLSearchParams();
		[ 'level', 'type', 'item', 'search' ].forEach( function ( k ) {
			var v = table.getAttribute( 'data-' + k );
			if ( v ) {
				p.set( k === 'search' ? 'search' : k, v );
			}
		} );
		p.set( 'per_page', '50' );
		return p.toString();
	}

	function refresh() {
		if ( ! live.checked || ! onFirstPage || document.hidden ) {
			return;
		}
		// Don't yank the table out from under someone reading an expanded row or copying text.
		if ( body.querySelector( 'details[open]' ) ) {
			return;
		}
		if ( window.getSelection && String( window.getSelection() ) !== '' ) {
			return;
		}
		fetch( cfg.root + 'activity?' + query(), {
			headers: { 'X-WP-Nonce': cfg.nonce }
		} ).then( function ( r ) {
			return r.ok ? r.json() : null;
		} ).then( function ( data ) {
			if ( ! data ) {
				return;
			}
			body.innerHTML = '';
			if ( ! data.rows.length ) {
				var tr = document.createElement( 'tr' );
				tr.className = 'ai-act-empty';
				var td = document.createElement( 'td' );
				td.colSpan = 3;
				td.textContent = i18n.activityEmpty || 'Nothing here yet.';
				tr.appendChild( td );
				body.appendChild( tr );
				return;
			}
			data.rows.forEach( function ( r ) {
				body.appendChild( rowEl( r ) );
			} );
		} ).catch( function () {} );
	}

	setInterval( refresh, 5000 );
} );
</script>
