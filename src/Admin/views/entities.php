<?php

namespace AggregateIt\Admin;

use AggregateIt\Entity\DelegationRules;

defined( 'ABSPATH' ) || exit;

/**
 * @var DelegationRules $rules
 * @var string[]        $cpts
 * @var \WP_Post[]      $pending
 * @var \WP_Post[]      $recent_hubs
 * @var array{hubs:int,new_week:int,linked_week:int,pending:int} $summary
 * @var array<int,array<string,mixed>> $activity
 */

$notice    = isset( $_GET['ai_notice'] ) ? sanitize_key( wp_unslash( $_GET['ai_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$all       = $rules->all();
$by_target = [];
foreach ( $all as $i => $rule ) {
	$target = (string) ( $rule['target_cpt'] ?? '' );
	if ( $target !== '' && ! isset( $by_target[ $target ] ) ) {
		$by_target[ $target ] = [ 'index' => $i, 'entity_type' => (string) ( $rule['entity_type'] ?? '' ) ];
	}
}

$post_types = get_post_types( [ 'public' => true ], 'objects' );
unset( $post_types['attachment'] );

$show_all         = isset( $_GET['show_all'] ) && (string) $_GET['show_all'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification
$is_internal_type = static function ( string $slug, \WP_Post_Type $pt ): bool {
	foreach ( [ '/^e-/', '/elementor/', '/template/', '/library/', '/block/', '/penci/', '/revision/' ] as $pattern ) {
		if ( preg_match( $pattern, $slug ) ) {
			return true;
		}
	}
	return empty( $pt->show_ui );
};

$visible_post_types = [];
foreach ( $post_types as $slug => $pt ) {
	if ( $show_all || isset( $by_target[ $slug ] ) || ! $is_internal_type( $slug, $pt ) ) {
		$visible_post_types[ $slug ] = $pt;
	}
}

$post_action = esc_url( admin_url( 'admin-post.php' ) );
$activity_url = admin_url( 'admin.php?page=aggregate-it-activity&type=' . rawurlencode( \AggregateIt\Database\Schema::STATE_ENTITY_LINKED ) );

$cards = [
	[ 'n' => $summary['hubs'], 'label' => __( 'Topic hubs', 'aggregate-it' ) ],
	[ 'n' => $summary['new_week'], 'label' => __( 'New this week', 'aggregate-it' ) ],
	[ 'n' => $summary['linked_week'], 'label' => __( 'Articles linked this week', 'aggregate-it' ) ],
	[ 'n' => $summary['pending'], 'label' => __( 'Awaiting review', 'aggregate-it' ) ],
];
?>
<div class="wrap aggregate-it">
	<div class="ai-head">
		<h1><?php esc_html_e( 'Topic Hubs', 'aggregate-it' ); ?></h1>
	</div>

	<?php
	$messages = [
		'saved'         => __( 'Updated.', 'aggregate-it' ),
		'deleted'       => __( 'Turned off.', 'aggregate-it' ),
		'merged'        => __( 'Pages merged. The source page was moved to Trash.', 'aggregate-it' ),
		'merge_invalid' => __( 'Enter two different page IDs that are both topic hubs.', 'aggregate-it' ),
		'approved'      => __( 'Topic hub published.', 'aggregate-it' ),
		'hub_trashed'   => __( 'Topic hub moved to Trash.', 'aggregate-it' ),
		'invalid'       => __( 'Please enter a name.', 'aggregate-it' ),
	];
	?>
	<?php if ( isset( $messages[ $notice ] ) ) : ?>
		<div class="notice notice-<?php echo in_array( $notice, [ 'invalid', 'merge_invalid' ], true ) ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div>
	<?php endif; ?>

	<div class="ai-hub-cards">
		<?php foreach ( $cards as $card ) : ?>
			<div class="ai-hub-card">
				<span class="ai-hub-num"><?php echo esc_html( number_format_i18n( (int) $card['n'] ) ); ?></span>
				<span class="ai-hub-label"><?php echo esc_html( $card['label'] ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ( $pending ) : ?>
		<div class="postbox ai-hub-pending">
			<h2 class="hndle"><span><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Awaiting your review (%d)', 'aggregate-it' ), count( $pending ) ) ); ?></span></h2>
			<div class="inside">
				<table class="widefat striped">
					<tbody>
						<?php foreach ( $pending as $hub ) : ?>
							<?php
							$approve = wp_nonce_url( admin_url( 'admin-post.php?action=aggregate_it_approve_hub&id=' . $hub->ID ), 'aggregate_it_approve_hub_' . $hub->ID );
							$trash   = wp_nonce_url( admin_url( 'admin-post.php?action=aggregate_it_trash_hub&id=' . $hub->ID ), 'aggregate_it_trash_hub_' . $hub->ID );
							?>
							<tr>
								<td><a href="<?php echo esc_url( (string) get_edit_post_link( $hub->ID ) ); ?>"><strong><?php echo esc_html( $hub->post_title ?: '#' . $hub->ID ); ?></strong></a> <code><?php echo esc_html( $hub->post_type ); ?></code></td>
								<td class="ai-hub-actions">
									<a class="button button-small button-primary" href="<?php echo esc_url( $approve ); ?>"><?php esc_html_e( 'Approve', 'aggregate-it' ); ?></a>
									<a class="button button-small" href="<?php echo esc_url( $trash ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Move this hub to Trash?', 'aggregate-it' ) ); ?>');"><?php esc_html_e( 'Trash', 'aggregate-it' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>

	<div class="ai-hub-cols">
		<div class="postbox ai-hub-activity">
			<h2 class="hndle"><span><?php esc_html_e( 'What just happened', 'aggregate-it' ); ?></span></h2>
			<div class="inside">
				<?php if ( ! $activity ) : ?>
					<p class="ai-empty"><?php esc_html_e( 'No hubs linked yet. Turn on a topic type below, then process some articles.', 'aggregate-it' ); ?></p>
				<?php else : ?>
					<ul class="ai-hub-feed">
						<?php foreach ( $activity as $row ) : ?>
							<?php
							$detail  = is_array( $row['detail'] ?? null ) ? $row['detail'] : [];
							$created = (array) ( $detail['created'] ?? [] );
							$linked  = (array) ( $detail['linked'] ?? [] );
							$skipped = (array) ( $detail['skipped'] ?? [] );
							$post_link = $row['post_id'] ? get_edit_post_link( (int) $row['post_id'] ) : '';
							?>
							<li>
								<time><?php echo esc_html( human_time_diff( strtotime( (string) $row['time'] ) ) . ' ' . __( 'ago', 'aggregate-it' ) ); ?></time>
								<span class="ai-hub-feed-msg"><?php echo esc_html( (string) $row['message'] ); ?></span>
								<?php if ( $created ) : ?>
									<span class="ai-hub-tags">
										<?php foreach ( $created as $name ) : ?><span class="ai-hub-tag ai-hub-tag--new"><?php echo esc_html( (string) $name ); ?></span><?php endforeach; ?>
									</span>
								<?php endif; ?>
								<?php if ( $post_link ) : ?>
									<a class="ai-hub-feed-link" href="<?php echo esc_url( (string) $post_link ); ?>"><?php esc_html_e( 'view article', 'aggregate-it' ); ?></a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<p><a href="<?php echo esc_url( $activity_url ); ?>"><?php esc_html_e( 'See all activity →', 'aggregate-it' ); ?></a></p>
				<?php endif; ?>
			</div>
		</div>

		<div class="postbox ai-hub-active">
			<h2 class="hndle"><span><?php esc_html_e( 'Most active hubs', 'aggregate-it' ); ?></span></h2>
			<div class="inside">
				<?php if ( ! $recent_hubs ) : ?>
					<p class="ai-empty"><?php esc_html_e( 'No hubs yet.', 'aggregate-it' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead><tr>
							<th><?php esc_html_e( 'Topic', 'aggregate-it' ); ?></th>
							<th><?php esc_html_e( 'Articles', 'aggregate-it' ); ?></th>
							<th></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $recent_hubs as $hub ) : ?>
								<?php
								$timeline = get_post_meta( $hub->ID, '_ai_timeline', true );
								$articles = is_array( $timeline ) ? count( $timeline ) : 0;
								$scraped  = (bool) get_post_meta( $hub->ID, '_ai_scraped', true );
								?>
								<tr>
									<td>
										<a href="<?php echo esc_url( (string) get_edit_post_link( $hub->ID ) ); ?>"><strong><?php echo esc_html( get_the_title( $hub ) ); ?></strong></a>
										<code><?php echo esc_html( $hub->post_type ); ?></code>
										<?php if ( $scraped ) : ?><span class="ai-hub-tag ai-hub-tag--new"><?php esc_html_e( 'scraped', 'aggregate-it' ); ?></span><?php else : ?><span class="ai-hub-tag"><?php esc_html_e( 'topic hub', 'aggregate-it' ); ?></span><?php endif; ?>
									</td>
									<td><?php echo esc_html( number_format_i18n( $articles ) ); ?></td>
									<td><a href="<?php echo esc_url( (string) get_permalink( $hub ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'aggregate-it' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<details class="postbox ai-hub-config" <?php echo $summary['hubs'] === 0 ? 'open' : ''; ?>>
		<summary><?php esc_html_e( 'Configure which topics get hubs', 'aggregate-it' ); ?></summary>
		<div class="inside">
			<p>
				<?php if ( $show_all ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=aggregate-it-entities' ) ); ?>"><?php esc_html_e( 'Hide internal types', 'aggregate-it' ); ?></a>
				<?php else : ?>
					<a href="<?php echo esc_url( add_query_arg( 'show_all', '1', admin_url( 'admin.php?page=aggregate-it-entities' ) ) ); ?>"><?php esc_html_e( 'Show all types', 'aggregate-it' ); ?></a>
				<?php endif; ?>
			</p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Page type', 'aggregate-it' ); ?></th>
					<th><?php esc_html_e( 'Pages', 'aggregate-it' ); ?></th>
					<th><?php esc_html_e( 'Status', 'aggregate-it' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $visible_post_types as $pt ) : ?>
						<?php
						$slug  = $pt->name;
						$count = (int) ( wp_count_posts( $slug )->publish ?? 0 );
						$on    = isset( $by_target[ $slug ] );
						?>
						<tr>
							<td><strong><?php echo esc_html( $pt->labels->singular_name ); ?></strong> <code><?php echo esc_html( $slug ); ?></code></td>
							<td><?php echo (int) $count; ?></td>
							<td><span class="post-state"><?php echo $on ? esc_html__( 'On', 'aggregate-it' ) : esc_html__( 'Off', 'aggregate-it' ); ?></span></td>
							<td>
								<?php if ( $on ) : ?>
									<form method="post" action="<?php echo $post_action; ?>">
										<input type="hidden" name="action" value="aggregate_it_delete_rule">
										<input type="hidden" name="index" value="<?php echo (int) $by_target[ $slug ]['index']; ?>">
										<?php wp_nonce_field( 'aggregate_it_delete_rule_' . (int) $by_target[ $slug ]['index'] ); ?>
										<button class="button-link-delete"><?php esc_html_e( 'Turn off', 'aggregate-it' ); ?></button>
									</form>
								<?php else : ?>
									<form method="post" action="<?php echo $post_action; ?>">
										<input type="hidden" name="action" value="aggregate_it_save_rule">
										<input type="hidden" name="target_cpt" value="<?php echo esc_attr( $slug ); ?>">
										<input type="hidden" name="entity_type" value="<?php echo esc_attr( $slug ); ?>">
										<?php wp_nonce_field( 'aggregate_it_save_rule' ); ?>
										<button class="button-link"><?php esc_html_e( 'Turn on', 'aggregate-it' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Create a new output type', 'aggregate-it' ); ?></h3>
			<form method="post" action="<?php echo $post_action; ?>" class="ai-field-grid">
				<input type="hidden" name="action" value="aggregate_it_save_rule">
				<?php wp_nonce_field( 'aggregate_it_save_rule' ); ?>
				<input name="type_name" type="text" class="regular-text" required
					aria-label="<?php esc_attr_e( 'New output type name', 'aggregate-it' ); ?>"
					placeholder="<?php esc_attr_e( 'e.g. Company, Person, Product', 'aggregate-it' ); ?>" list="ai-type-suggestions">
				<datalist id="ai-type-suggestions">
					<option value="Company"></option><option value="Person"></option>
					<option value="Product"></option><option value="Place"></option><option value="Brand"></option>
				</datalist>
				<button class="button button-primary"><?php esc_html_e( 'Create and turn on', 'aggregate-it' ); ?></button>
			</form>

			<h3><?php esc_html_e( 'Merge duplicate hubs', 'aggregate-it' ); ?></h3>
			<form method="post" action="<?php echo $post_action; ?>" class="ai-field-grid">
				<input type="hidden" name="action" value="aggregate_it_merge_entities">
				<?php wp_nonce_field( 'aggregate_it_merge_entities' ); ?>
				<label><?php esc_html_e( 'Merge page ID', 'aggregate-it' ); ?><br><input name="source_id" type="number" required></label>
				<label><?php esc_html_e( 'into page ID', 'aggregate-it' ); ?><br><input name="target_id" type="number" required></label>
				<button class="button" onclick="return confirm('<?php echo esc_js( __( 'Merge and delete the source page?', 'aggregate-it' ) ); ?>');"><?php esc_html_e( 'Merge', 'aggregate-it' ); ?></button>
			</form>
		</div>
	</details>
</div>
