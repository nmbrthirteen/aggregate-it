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

$show_all = isset( $_GET['show_all'] ) && (string) $_GET['show_all'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification
$is_internal_type = static function ( string $slug, \WP_Post_Type $pt ): bool {
	$internal_patterns = [
		'/^e-/',
		'/elementor/',
		'/template/',
		'/library/',
		'/block/',
		'/penci/',
		'/revision/',
	];
	foreach ( $internal_patterns as $pattern ) {
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
?>
<div class="wrap aggregate-it">
	<div class="ai-head">
		<h1><?php esc_html_e( 'Linked Pages', 'aggregate-it' ); ?></h1>
	</div>

	<?php if ( $notice === 'saved' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Updated.', 'aggregate-it' ); ?></p></div>
	<?php elseif ( $notice === 'deleted' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Auto-linking turned off.', 'aggregate-it' ); ?></p></div>
	<?php elseif ( $notice === 'merged' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Pages merged. The source page was moved to Trash.', 'aggregate-it' ); ?></p></div>
	<?php elseif ( $notice === 'merge_invalid' ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Enter two different page IDs that are both auto-linked pages.', 'aggregate-it' ); ?></p></div>
	<?php elseif ( $notice === 'invalid' ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Please enter a name.', 'aggregate-it' ); ?></p></div>
	<?php endif; ?>

	<div class="notice notice-info">
		<p>
			<?php esc_html_e( 'Choose where Aggregate It should put linked entity pages. When a destination is on, imported articles can create new pages there, link article mentions to matching pages, and add each article to the page’s news list. Existing pages are not rewritten in bulk.', 'aggregate-it' ); ?>
		</p>
	</div>

	<div class="postbox">
		<h2 class="hndle"><span><?php esc_html_e( 'Page destinations', 'aggregate-it' ); ?></span></h2>
		<div class="inside">
		<p class="description">
			<?php esc_html_e( 'Turn on the page type that should receive generated entity pages. Builder templates and block libraries are hidden by default.', 'aggregate-it' ); ?>
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
				<th><?php esc_html_e( 'When on', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Preview', 'aggregate-it' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $visible_post_types as $pt ) :
					$slug  = $pt->name;
					$count = (int) ( wp_count_posts( $slug )->publish ?? 0 );
					$on    = isset( $by_target[ $slug ] );
					$archive_url = get_post_type_archive_link( $slug );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $pt->labels->singular_name ); ?></strong> <code><?php echo esc_html( $slug ); ?></code>
							<div class="row-actions">
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
							</div>
						</td>
						<td><?php echo (int) $count; ?></td>
						<td>
							<?php if ( $on ) : ?>
								<span class="post-state"><?php esc_html_e( 'On', 'aggregate-it' ); ?></span>
							<?php else : ?>
								<span class="post-state"><?php esc_html_e( 'Off', 'aggregate-it' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $on ) : ?>
								<?php esc_html_e( 'Creates missing pages, links article mentions, and adds articles to each matching page’s news list. If a generated page only has a title, Aggregate It may add a short description.', 'aggregate-it' ); ?>
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'Ignored.', 'aggregate-it' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $archive_url ) : ?>
								<a href="<?php echo esc_url( $archive_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View archive', 'aggregate-it' ); ?></a>
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'Preview individual pages below', 'aggregate-it' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	</div>

	<div class="postbox">
		<h2 class="hndle"><span><?php esc_html_e( 'Preview linked pages', 'aggregate-it' ); ?></span></h2>
		<div class="inside">
		<?php
		$entities = $cpts ? get_posts(
			[ 'post_type' => $cpts, 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'modified', 'order' => 'DESC' ]
		) : [];
		?>
		<?php if ( ! $entities ) : ?>
			<p class="description"><?php esc_html_e( 'Nothing to preview yet. Turn on a page destination, process articles, and linked pages will appear here.', 'aggregate-it' ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Recent pages in destinations that are turned on. Use View page to see what visitors see.', 'aggregate-it' ); ?></p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Name', 'aggregate-it' ); ?></th>
					<th><?php esc_html_e( 'Type', 'aggregate-it' ); ?></th>
					<th><?php esc_html_e( 'Status', 'aggregate-it' ); ?></th>
					<th><?php esc_html_e( 'Preview', 'aggregate-it' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $entities as $entity ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( get_the_title( $entity ) ); ?></strong>
								<div class="row-actions">
									<span class="view"><a href="<?php echo esc_url( (string) get_permalink( $entity ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'aggregate-it' ); ?></a> | </span>
									<span class="edit"><a href="<?php echo esc_url( (string) get_edit_post_link( $entity->ID ) ); ?>"><?php esc_html_e( 'Edit', 'aggregate-it' ); ?></a></span>
								</div>
							</td>
							<td><code><?php echo esc_html( $entity->post_type ); ?></code></td>
							<td><?php echo get_post_meta( $entity->ID, '_ai_is_stub', true ) ? esc_html__( 'Basic', 'aggregate-it' ) : esc_html__( 'Detailed', 'aggregate-it' ); ?></td>
							<td><a href="<?php echo esc_url( (string) get_permalink( $entity ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View page', 'aggregate-it' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		</div>
	</div>

	<details class="postbox">
		<summary><?php esc_html_e( 'Advanced', 'aggregate-it' ); ?></summary>
		<div class="inside">
		<h3><?php esc_html_e( 'Create a new output type', 'aggregate-it' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Use this only if your site does not already have a suitable public post type for entity pages.', 'aggregate-it' ); ?></p>
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

		<h3><?php esc_html_e( 'Merge duplicate pages', 'aggregate-it' ); ?></h3>
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
