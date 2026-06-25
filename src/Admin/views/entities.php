<?php

namespace AggregateIt\Admin;

use AggregateIt\Entity\DelegationRules;

defined( 'ABSPATH' ) || exit;

/**
 * @var DelegationRules $rules
 * @var string[]        $cpts
 */

$all_rules = $rules->all();
$entities  = $cpts ? get_posts(
	[
		'post_type'      => $cpts,
		'post_status'    => 'publish',
		'posts_per_page' => 200,
		'orderby'        => 'title',
		'order'          => 'ASC',
	]
) : [];
?>
<div class="wrap aggregate-it">
	<h1><?php esc_html_e( 'Entities', 'aggregate-it' ); ?></h1>

	<div class="ai-panel" style="max-width:760px;margin:16px 0;">
		<h2><?php esc_html_e( 'Delegation rules', 'aggregate-it' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Map an extracted entity type to a post type. A public CPT is registered for each target, and mentions are matched, created, and linked automatically.', 'aggregate-it' ); ?></p>

		<table class="widefat striped" style="margin:10px 0;">
			<thead><tr>
				<th><?php esc_html_e( 'Entity type', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Target CPT', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Schema', 'aggregate-it' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
				<?php if ( ! $all_rules ) : ?>
					<tr><td colspan="4"><em><?php esc_html_e( 'No rules yet.', 'aggregate-it' ); ?></em></td></tr>
				<?php endif; ?>
				<?php foreach ( $all_rules as $i => $rule ) : ?>
					<?php $del = wp_nonce_url( admin_url( 'admin-post.php?action=aggregate_it_delete_rule&index=' . $i ), 'aggregate_it_delete_rule_' . $i ); ?>
					<tr>
						<td><code><?php echo esc_html( $rule['entity_type'] ?? '' ); ?></code></td>
						<td><code><?php echo esc_html( $rule['target_cpt'] ?? '' ); ?></code></td>
						<td><?php echo esc_html( $rule['schema_type'] ?? 'Thing' ); ?></td>
						<td><a class="button button-small" href="<?php echo esc_url( $del ); ?>"><?php esc_html_e( 'Delete', 'aggregate-it' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
			<input type="hidden" name="action" value="aggregate_it_save_rule">
			<?php wp_nonce_field( 'aggregate_it_save_rule' ); ?>
			<label><?php esc_html_e( 'Entity type', 'aggregate-it' ); ?><br>
				<input name="entity_type" type="text" required placeholder="company"></label>
			<label><?php esc_html_e( 'Target CPT', 'aggregate-it' ); ?><br>
				<input name="target_cpt" type="text" required placeholder="company"></label>
			<label><?php esc_html_e( 'Schema type', 'aggregate-it' ); ?><br>
				<select name="schema_type">
					<option>Organization</option>
					<option>Person</option>
					<option>Product</option>
					<option>Place</option>
					<option>Thing</option>
				</select></label>
			<button class="button button-primary"><?php esc_html_e( 'Add rule', 'aggregate-it' ); ?></button>
		</form>
	</div>

	<div class="ai-panel" style="max-width:760px;margin:16px 0;">
		<h2><?php esc_html_e( 'Merge duplicate entities', 'aggregate-it' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;align-items:flex-end;">
			<input type="hidden" name="action" value="aggregate_it_merge_entities">
			<?php wp_nonce_field( 'aggregate_it_merge_entities' ); ?>
			<label><?php esc_html_e( 'Merge entity ID', 'aggregate-it' ); ?><br><input name="source_id" type="number" required></label>
			<label><?php esc_html_e( 'into ID', 'aggregate-it' ); ?><br><input name="target_id" type="number" required></label>
			<button class="button" onclick="return confirm('<?php echo esc_js( __( 'Merge and delete the source entity?', 'aggregate-it' ) ); ?>');"><?php esc_html_e( 'Merge', 'aggregate-it' ); ?></button>
		</form>
	</div>

	<div class="ai-panel" style="max-width:760px;">
		<h2><?php esc_html_e( 'Entity hubs', 'aggregate-it' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'ID', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Name', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Type', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Stub', 'aggregate-it' ); ?></th>
			</tr></thead>
			<tbody>
				<?php if ( ! $entities ) : ?>
					<tr><td colspan="4"><em><?php esc_html_e( 'No entities yet — they appear as posts are processed.', 'aggregate-it' ); ?></em></td></tr>
				<?php endif; ?>
				<?php foreach ( $entities as $entity ) : ?>
					<tr>
						<td><?php echo (int) $entity->ID; ?></td>
						<td><a href="<?php echo esc_url( get_edit_post_link( $entity->ID ) ); ?>"><?php echo esc_html( get_the_title( $entity ) ); ?></a></td>
						<td><code><?php echo esc_html( $entity->post_type ); ?></code></td>
						<td><?php echo get_post_meta( $entity->ID, '_ai_is_stub', true ) ? '✓' : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
