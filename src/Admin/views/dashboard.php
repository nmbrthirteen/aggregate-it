<?php

namespace AggregateIt\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * @var array<string,bool> $setup
 * @var bool               $show_setup
 */
?>
<div class="wrap aggregate-it" id="aggregate-it-app">

	<div class="ai-head">
		<h1><?php esc_html_e( 'Aggregate It', 'aggregate-it' ); ?></h1>
		<div class="ai-actions">
			<span class="post-state" id="ai-provider-pill"></span>
			<span class="post-state ai-hidden" id="ai-paused-pill" role="status">
				<?php esc_html_e( 'Daily cost limit reached — paused', 'aggregate-it' ); ?>
			</span>
			<?php if ( ! empty( $can_seed ) ) : ?>
				<button class="button" id="ai-seed" type="button"><?php esc_html_e( 'Add sample articles', 'aggregate-it' ); ?></button>
			<?php endif; ?>
			<button class="button" id="ai-run" type="button"><?php esc_html_e( 'Run now', 'aggregate-it' ); ?></button>
			<button class="button ai-hidden" id="ai-resume" type="button"><?php esc_html_e( 'Resume', 'aggregate-it' ); ?></button>
			<button class="button button-primary" id="ai-refresh" type="button"><?php esc_html_e( 'Refresh', 'aggregate-it' ); ?></button>
		</div>
	</div>

	<?php if ( ! empty( $show_setup ) ) : ?>
		<div class="postbox ai-setup">
			<h2 class="hndle"><span><?php esc_html_e( 'Get started', 'aggregate-it' ); ?></span></h2>
			<div class="inside">
				<ol>
					<li>
						<?php esc_html_e( 'Connect an AI service and add its key', 'aggregate-it' ); ?>
						<?php if ( ! empty( $setup['provider'] ) ) : ?>
							<span class="post-state"><?php esc_html_e( 'Done', 'aggregate-it' ); ?></span>
						<?php else : ?>
							<a class="button button-primary button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=aggregate-it-settings' ) ); ?>"><?php esc_html_e( 'Open Settings', 'aggregate-it' ); ?></a>
						<?php endif; ?>
					</li>
					<li>
						<?php esc_html_e( 'Add a feed to pull articles from', 'aggregate-it' ); ?>
						<?php if ( ! empty( $setup['feeds'] ) ) : ?>
							<span class="post-state"><?php esc_html_e( 'Done', 'aggregate-it' ); ?></span>
						<?php else : ?>
							<a class="button button-primary button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=aggregate-it-sources' ) ); ?>"><?php esc_html_e( 'Add a feed', 'aggregate-it' ); ?></a>
						<?php endif; ?>
					</li>
					<li>
						<?php esc_html_e( 'Optional: turn on content types so the news builds pages (companies, people…)', 'aggregate-it' ); ?>
						<?php if ( ! empty( $setup['types'] ) ) : ?>
							<span class="post-state"><?php esc_html_e( 'Done', 'aggregate-it' ); ?></span>
						<?php else : ?>
							<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=aggregate-it-entities' ) ); ?>"><?php esc_html_e( 'Set up pages', 'aggregate-it' ); ?></a>
						<?php endif; ?>
					</li>
				</ol>
				<p><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aggregate_it_dismiss_setup' ), 'aggregate_it_dismiss_setup' ) ); ?>"><?php esc_html_e( 'Dismiss', 'aggregate-it' ); ?></a></p>
			</div>
		</div>
	<?php endif; ?>

	<div class="notice notice-info ai-status" id="ai-status" aria-live="polite"><p></p></div>

	<div class="ai-cards" id="ai-cards">
		<?php
		$cards = [
			'total_items' => __( 'Total articles', 'aggregate-it' ),
			'published'   => __( 'Published', 'aggregate-it' ),
			'in_pipeline' => __( 'Being processed', 'aggregate-it' ),
			'dead_letter' => __( 'Failed', 'aggregate-it' ),
			'clusters'    => __( 'Stories', 'aggregate-it' ),
			'entities'    => __( 'Topic hubs', 'aggregate-it' ),
			'spend_today' => __( 'Cost today', 'aggregate-it' ),
			'spend_month' => __( 'Cost this month', 'aggregate-it' ),
		];
		foreach ( $cards as $key => $label ) :
			?>
			<div class="postbox ai-card">
				<div class="inside">
					<span class="ai-card__label"><?php echo esc_html( $label ); ?></span>
					<span class="ai-card__value" data-card="<?php echo esc_attr( $key ); ?>">—</span>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="ai-grid ai-cols-3">
		<div class="postbox">
			<h2 class="hndle"><span><?php esc_html_e( 'Article status', 'aggregate-it' ); ?></span></h2>
			<div class="inside">
				<div class="ai-chart-row">
					<canvas id="ai-chart-states" width="260" height="260"></canvas>
					<ul class="ai-legend" id="ai-legend-states"></ul>
				</div>
			</div>
		</div>
		<div class="postbox">
			<h2 class="hndle"><span><?php esc_html_e( 'Posts published per day', 'aggregate-it' ); ?></span></h2>
			<div class="inside"><canvas id="ai-chart-throughput" width="520" height="240"></canvas></div>
		</div>
		<div class="postbox">
			<h2 class="hndle"><span><?php esc_html_e( 'Cost per day', 'aggregate-it' ); ?></span></h2>
			<div class="inside"><canvas id="ai-chart-cost" width="520" height="240"></canvas></div>
		</div>
	</div>

	<div class="ai-grid ai-cols-2">
		<div class="postbox">
			<h2 class="hndle"><span><?php esc_html_e( 'Recent articles', 'aggregate-it' ); ?></span></h2>
			<div class="inside">
				<table class="widefat striped ai-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'aggregate-it' ); ?></th>
							<th><?php esc_html_e( 'Link', 'aggregate-it' ); ?></th>
							<th><?php esc_html_e( 'Status', 'aggregate-it' ); ?></th>
							<th><?php esc_html_e( 'Updated', 'aggregate-it' ); ?></th>
						</tr>
					</thead>
					<tbody id="ai-recent"></tbody>
				</table>
			</div>
		</div>
		<div class="postbox">
			<h2 class="hndle"><span><?php esc_html_e( 'Activity', 'aggregate-it' ); ?></span></h2>
			<div class="inside"><ul class="ai-events" id="ai-events"></ul></div>
		</div>
	</div>
</div>
