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
			<?php esc_html_e( 'Linked Pages creates public pages for people, companies, products, places, or other entities mentioned in imported articles. Turn on a target type, process articles, then preview the pages in the output list below.', 'aggregate-it' ); ?>
		</p>
	</div>

	<div class="postbox">
		<h2 class="hndle"><span><?php esc_html_e( 'Choose where linked pages are created', 'aggregate-it' ); ?></span></h2>
		<div class="inside">
		<p class="description">
			<?php esc_html_e( 'Use real public content types such as Company, Person, Directory, Event, Product, or Page. Builder templates and block libraries are hidden by default because they are not useful output destinations.', 'aggregate-it' ); ?>
			<?php if ( $show_all ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=aggregate-it-entities' ) ); ?>"><?php esc_html_e( 'Hide internal types', 'aggregate-it' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( add_query_arg( 'show_all', '1', admin_url( 'admin.php?page=aggregate-it-entities' ) ) ); ?>"><?php esc_html_e( 'Show all types', 'aggregate-it' ); ?></a>
			<?php endif; ?>
		</p>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Output type', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Existing pages', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Linked Pages', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'AI-filled fields', 'aggregate-it' ); ?></th>
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
								<p class="description"><?php esc_html_e( 'New article mentions can create or update pages here.', 'aggregate-it' ); ?></p>
							<?php else : ?>
								<span class="post-state"><?php esc_html_e( 'Off', 'aggregate-it' ); ?></span>
								<p class="description"><?php esc_html_e( 'Aggregate It will not create pages in this type.', 'aggregate-it' ); ?></p>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $on ) : ?>
								<?php
								$ridx       = (int) $by_target[ $slug ]['index'];
								$fields_csv = implode( ', ', (array) ( $all[ $ridx ]['fields'] ?? [] ) );
								?>
								<form method="post" action="<?php echo $post_action; ?>" class="ai-inline">
									<input type="hidden" name="action" value="aggregate_it_save_fields">
									<input type="hidden" name="index" value="<?php echo $ridx; ?>">
									<?php wp_nonce_field( 'aggregate_it_save_fields' ); ?>
									<input type="text" name="fields" value="<?php echo esc_attr( $fields_csv ); ?>" class="regular-text" aria-label="<?php esc_attr_e( 'Fields the AI fills in', 'aggregate-it' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Founded, Industry, Website', 'aggregate-it' ); ?>">
									<button class="button button-small"><?php esc_html_e( 'Save', 'aggregate-it' ); ?></button>
								</form>
							<?php else : ?>
								<span class="description">—</span>
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

		<h3><?php esc_html_e( 'Add a new content type', 'aggregate-it' ); ?></h3>
		<form method="post" action="<?php echo $post_action; ?>" class="ai-field-grid">
			<input type="hidden" name="action" value="aggregate_it_save_rule">
			<?php wp_nonce_field( 'aggregate_it_save_rule' ); ?>
			<input name="type_name" type="text" class="regular-text" required
				aria-label="<?php esc_attr_e( 'New content type name', 'aggregate-it' ); ?>"
				placeholder="<?php esc_attr_e( 'e.g. Company, Person, Product', 'aggregate-it' ); ?>" list="ai-type-suggestions">
			<datalist id="ai-type-suggestions">
				<option value="Company"></option><option value="Person"></option>
				<option value="Product"></option><option value="Place"></option><option value="Brand"></option>
			</datalist>
			<button class="button button-primary"><?php esc_html_e( 'Add & turn on', 'aggregate-it' ); ?></button>
		</form>
		</div>
	</div>

	<div class="postbox">
		<h2 class="hndle"><span><?php esc_html_e( 'Preview generated output', 'aggregate-it' ); ?></span></h2>
		<div class="inside">
		<?php
		$entities = $cpts ? get_posts(
			[ 'post_type' => $cpts, 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'modified', 'order' => 'DESC' ]
		) : [];
		?>
		<?php if ( ! $entities ) : ?>
			<p class="description"><?php esc_html_e( 'Nothing to preview yet. Turn on a target type, add feeds, and process articles. Generated pages will appear here with View and Edit links.', 'aggregate-it' ); ?></p>
		<?php else : ?>
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
		<summary><?php esc_html_e( 'Merge duplicate pages (advanced)', 'aggregate-it' ); ?></summary>
		<div class="inside">
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
