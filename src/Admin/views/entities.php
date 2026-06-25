<?php

namespace AggregateIt\Admin;

use AggregateIt\Entity\DelegationRules;

defined( 'ABSPATH' ) || exit;

/**
 * @var DelegationRules $rules
 * @var string[]        $cpts
 */

$notice   = isset( $_GET['ai_notice'] ) ? sanitize_key( wp_unslash( $_GET['ai_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$all      = $rules->all();
$by_target = [];
foreach ( $all as $i => $rule ) {
	$target = (string) ( $rule['target_cpt'] ?? '' );
	if ( $target !== '' && ! isset( $by_target[ $target ] ) ) {
		$by_target[ $target ] = [ 'index' => $i, 'entity_type' => (string) ( $rule['entity_type'] ?? '' ) ];
	}
}

$post_types = get_post_types( [ 'public' => true ], 'objects' );
unset( $post_types['attachment'] );

$post_action = esc_url( admin_url( 'admin-post.php' ) );
?>
<div class="wrap aggregate-it">
	<h1><?php esc_html_e( 'Linked Pages', 'aggregate-it' ); ?></h1>

	<?php if ( $notice === 'saved' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Updated.', 'aggregate-it' ); ?></p></div>
	<?php elseif ( $notice === 'deleted' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Auto-linking turned off.', 'aggregate-it' ); ?></p></div>
	<?php elseif ( $notice === 'invalid' ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Please enter a name.', 'aggregate-it' ); ?></p></div>
	<?php endif; ?>

	<div class="ai-panel" style="max-width:820px;margin:16px 0;">
		<h2><?php esc_html_e( 'Your content types', 'aggregate-it' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Type', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Pages', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Auto-linking', 'aggregate-it' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $post_types as $pt ) :
					$slug  = $pt->name;
					$count = (int) ( wp_count_posts( $slug )->publish ?? 0 );
					$on    = isset( $by_target[ $slug ] );
					?>
					<tr>
						<td><strong><?php echo esc_html( $pt->labels->singular_name ); ?></strong> <code><?php echo esc_html( $slug ); ?></code></td>
						<td><?php echo (int) $count; ?></td>
						<td>
							<?php if ( $on ) : ?>
								<span class="ai-state ai-state--published"><?php esc_html_e( 'On', 'aggregate-it' ); ?></span>
							<?php else : ?>
								<span class="ai-state"><?php esc_html_e( 'Off', 'aggregate-it' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $on ) : ?>
								<form method="post" action="<?php echo $post_action; ?>" style="display:inline">
									<input type="hidden" name="action" value="aggregate_it_delete_rule">
									<input type="hidden" name="index" value="<?php echo (int) $by_target[ $slug ]['index']; ?>">
									<?php wp_nonce_field( 'aggregate_it_delete_rule_' . (int) $by_target[ $slug ]['index'] ); ?>
									<button class="button button-small"><?php esc_html_e( 'Turn off', 'aggregate-it' ); ?></button>
								</form>
							<?php else : ?>
								<form method="post" action="<?php echo $post_action; ?>" style="display:inline">
									<input type="hidden" name="action" value="aggregate_it_save_rule">
									<input type="hidden" name="target_cpt" value="<?php echo esc_attr( $slug ); ?>">
									<input type="hidden" name="entity_type" value="<?php echo esc_attr( $slug ); ?>">
									<?php wp_nonce_field( 'aggregate_it_save_rule' ); ?>
									<button class="button button-primary button-small"><?php esc_html_e( 'Turn on', 'aggregate-it' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3 style="margin-top:18px;"><?php esc_html_e( 'Add a new content type', 'aggregate-it' ); ?></h3>
		<form method="post" action="<?php echo $post_action; ?>" style="display:flex;gap:8px;align-items:center;">
			<input type="hidden" name="action" value="aggregate_it_save_rule">
			<?php wp_nonce_field( 'aggregate_it_save_rule' ); ?>
			<input name="type_name" type="text" class="regular-text" required
				placeholder="<?php esc_attr_e( 'e.g. Company, Person, Product', 'aggregate-it' ); ?>" list="ai-type-suggestions">
			<datalist id="ai-type-suggestions">
				<option value="Company"></option><option value="Person"></option>
				<option value="Product"></option><option value="Place"></option><option value="Brand"></option>
			</datalist>
			<button class="button button-primary"><?php esc_html_e( 'Add & turn on', 'aggregate-it' ); ?></button>
		</form>
	</div>

	<div class="ai-panel" style="max-width:820px;margin:16px 0;">
		<h2><?php esc_html_e( 'Pages built automatically', 'aggregate-it' ); ?></h2>
		<?php
		$entities = $cpts ? get_posts(
			[ 'post_type' => $cpts, 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'modified', 'order' => 'DESC' ]
		) : [];
		?>
		<?php if ( ! $entities ) : ?>
			<p class="description"><?php esc_html_e( 'Nothing yet. As articles are processed, pages appear here automatically.', 'aggregate-it' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Name', 'aggregate-it' ); ?></th>
					<th><?php esc_html_e( 'Type', 'aggregate-it' ); ?></th>
					<th><?php esc_html_e( 'Status', 'aggregate-it' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $entities as $entity ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $entity->ID ) ); ?>"><?php echo esc_html( get_the_title( $entity ) ); ?></a></td>
							<td><code><?php echo esc_html( $entity->post_type ); ?></code></td>
							<td><?php echo get_post_meta( $entity->ID, '_ai_is_stub', true ) ? esc_html__( 'Basic', 'aggregate-it' ) : esc_html__( 'Detailed', 'aggregate-it' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<details class="ai-panel" style="max-width:820px;">
		<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'Merge duplicate pages (advanced)', 'aggregate-it' ); ?></summary>
		<form method="post" action="<?php echo $post_action; ?>" style="display:flex;gap:8px;align-items:flex-end;">
			<input type="hidden" name="action" value="aggregate_it_merge_entities">
			<?php wp_nonce_field( 'aggregate_it_merge_entities' ); ?>
			<label><?php esc_html_e( 'Merge page ID', 'aggregate-it' ); ?><br><input name="source_id" type="number" required></label>
			<label><?php esc_html_e( 'into page ID', 'aggregate-it' ); ?><br><input name="target_id" type="number" required></label>
			<button class="button" onclick="return confirm('<?php echo esc_js( __( 'Merge and delete the source page?', 'aggregate-it' ) ); ?>');"><?php esc_html_e( 'Merge', 'aggregate-it' ); ?></button>
		</form>
	</details>
</div>
