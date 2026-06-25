<?php

namespace AggregateIt\Admin;

defined( 'ABSPATH' ) || exit;

/** @var string $brand */
?>
<div class="wrap aggregate-it" id="aggregate-it-app">

	<div class="ai-topbar">
		<h1><?php echo esc_html( $brand ); ?></h1>
		<div class="ai-actions">
			<span class="ai-pill" id="ai-provider-pill"></span>
			<span class="ai-pill ai-pill--warn ai-hidden" id="ai-paused-pill">
				<?php esc_html_e( 'Spend cap reached — paused', 'aggregate-it' ); ?>
			</span>
			<button class="button" id="ai-seed" type="button"><?php esc_html_e( 'Seed demo items', 'aggregate-it' ); ?></button>
			<button class="button" id="ai-run" type="button"><?php esc_html_e( 'Process now', 'aggregate-it' ); ?></button>
			<button class="button ai-hidden" id="ai-resume" type="button"><?php esc_html_e( 'Resume', 'aggregate-it' ); ?></button>
			<button class="button button-primary" id="ai-refresh" type="button"><?php esc_html_e( 'Refresh', 'aggregate-it' ); ?></button>
		</div>
	</div>

	<div class="ai-status" id="ai-status" aria-live="polite"></div>

	<div class="ai-cards" id="ai-cards">
		<?php
		$cards = [
			'total_items' => __( 'Total items', 'aggregate-it' ),
			'published'   => __( 'Published', 'aggregate-it' ),
			'in_pipeline' => __( 'In pipeline', 'aggregate-it' ),
			'dead_letter' => __( 'Dead-letter', 'aggregate-it' ),
			'clusters'    => __( 'Clusters', 'aggregate-it' ),
			'entities'    => __( 'Entities', 'aggregate-it' ),
			'spend_today' => __( 'Spend today', 'aggregate-it' ),
			'spend_month' => __( 'Spend this month', 'aggregate-it' ),
		];
		foreach ( $cards as $key => $label ) :
			?>
			<div class="ai-card">
				<span class="ai-card__label"><?php echo esc_html( $label ); ?></span>
				<span class="ai-card__value" data-card="<?php echo esc_attr( $key ); ?>">—</span>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="ai-charts">
		<div class="ai-panel">
			<h2><?php esc_html_e( 'Pipeline breakdown', 'aggregate-it' ); ?></h2>
			<div class="ai-chart-row">
				<canvas id="ai-chart-states" width="260" height="260"></canvas>
				<ul class="ai-legend" id="ai-legend-states"></ul>
			</div>
		</div>
		<div class="ai-panel">
			<h2><?php esc_html_e( 'Published / day (14d)', 'aggregate-it' ); ?></h2>
			<canvas id="ai-chart-throughput" width="520" height="240"></canvas>
		</div>
		<div class="ai-panel">
			<h2><?php esc_html_e( 'Spend / day (14d)', 'aggregate-it' ); ?></h2>
			<canvas id="ai-chart-cost" width="520" height="240"></canvas>
		</div>
	</div>

	<div class="ai-lower">
		<div class="ai-panel">
			<h2><?php esc_html_e( 'Recent items', 'aggregate-it' ); ?></h2>
			<table class="widefat striped ai-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'aggregate-it' ); ?></th>
						<th><?php esc_html_e( 'URL', 'aggregate-it' ); ?></th>
						<th><?php esc_html_e( 'State', 'aggregate-it' ); ?></th>
						<th><?php esc_html_e( 'Updated', 'aggregate-it' ); ?></th>
					</tr>
				</thead>
				<tbody id="ai-recent"></tbody>
			</table>
		</div>
		<div class="ai-panel">
			<h2><?php esc_html_e( 'Activity log', 'aggregate-it' ); ?></h2>
			<ul class="ai-events" id="ai-events"></ul>
		</div>
	</div>
</div>
