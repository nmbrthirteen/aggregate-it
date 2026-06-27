<?php

namespace AggregateIt\Admin;

use AggregateIt\Source\Source;

defined( 'ABSPATH' ) || exit;

/**
 * @var Source[]    $sources
 * @var Source|null $editing
 * @var int         $default_interval
 */

$notice = isset( $_GET['ai_notice'] ) ? sanitize_key( wp_unslash( $_GET['ai_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$messages = [
	'saved'    => __( 'Feed saved.', 'aggregate-it' ),
	'deleted'  => __( 'Feed deleted.', 'aggregate-it' ),
	'imported' => __( 'Checking that feed now.', 'aggregate-it' ),
	'invalid'  => __( 'Please enter a valid feed address.', 'aggregate-it' ),
	'opml_added' => __( 'Feeds imported.', 'aggregate-it' ),
	'opml_none'  => __( 'No new feeds found in that OPML.', 'aggregate-it' ),
	'bulk_done' => __( 'Selected feeds updated.', 'aggregate-it' ),
	'bulk_none' => __( 'Choose feeds and a bulk action first.', 'aggregate-it' ),
];
?>
<div class="wrap aggregate-it">
	<div class="ai-head">
		<h1><?php esc_html_e( 'Feeds', 'aggregate-it' ); ?></h1>
	</div>

	<?php if ( $notice && isset( $messages[ $notice ] ) ) : ?>
		<div class="notice notice-<?php echo $notice === 'invalid' ? 'error' : 'success'; ?> is-dismissible">
			<p><?php echo esc_html( $messages[ $notice ] ); ?></p>
		</div>
	<?php endif; ?>

	<div class="ai-panel ai-narrow">
		<h2><?php echo $editing ? esc_html__( 'Edit feed', 'aggregate-it' ) : esc_html__( 'Add a feed', 'aggregate-it' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aggregate_it_save_source">
			<input type="hidden" name="id" value="<?php echo (int) ( $editing->id ?? 0 ); ?>">
			<?php wp_nonce_field( 'aggregate_it_save_source' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th><label for="ai-url"><?php esc_html_e( 'Feed address', 'aggregate-it' ); ?></label></th>
					<td><input name="url" id="ai-url" type="url" class="regular-text" required
						value="<?php echo esc_attr( $editing->url ?? '' ); ?>" placeholder="https://example.com/feed"></td>
				</tr>
				<tr>
					<th><label for="ai-title"><?php esc_html_e( 'Title', 'aggregate-it' ); ?></label></th>
					<td><input name="title" id="ai-title" type="text" class="regular-text"
						value="<?php echo esc_attr( $editing->title ?? '' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="ai-interval"><?php esc_html_e( 'Check for new posts every (minutes)', 'aggregate-it' ); ?></label></th>
					<td><input name="interval_minutes" id="ai-interval" type="number" min="1" class="small-text"
						value="<?php echo esc_attr( (string) ( $editing ? $editing->interval_minutes( $default_interval ) : $default_interval ) ); ?>"></td>
				</tr>
				<?php
				$selected_cats = $editing ? $editing->categories() : [];
				$all_cats      = get_categories( [ 'hide_empty' => false, 'number' => 200 ] );
				?>
				<?php if ( $all_cats ) : ?>
				<tr>
					<th><?php esc_html_e( 'Put posts in', 'aggregate-it' ); ?></th>
					<td>
						<div class="ai-checklist">
							<?php foreach ( $all_cats as $cat ) : ?>
								<label>
									<input type="checkbox" name="categories[]" value="<?php echo (int) $cat->term_id; ?>"
										<?php checked( in_array( (int) $cat->term_id, $selected_cats, true ) ); ?>>
									<?php echo esc_html( $cat->name ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</td>
				</tr>
				<?php endif; ?>
				<tr>
					<th><label for="ai-tags"><?php esc_html_e( 'Tags', 'aggregate-it' ); ?></label></th>
					<td><input name="tags" id="ai-tags" type="text" class="regular-text"
						value="<?php echo esc_attr( $editing ? implode( ', ', $editing->tags() ) : '' ); ?>"
						placeholder="<?php esc_attr_e( 'comma separated', 'aggregate-it' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="ai-include"><?php esc_html_e( 'Only import if it mentions', 'aggregate-it' ); ?></label></th>
					<td><input name="include_keywords" id="ai-include" type="text" class="regular-text"
						value="<?php echo esc_attr( (string) ( $editing->settings['include_keywords'] ?? '' ) ); ?>"
						placeholder="<?php esc_attr_e( 'comma separated — leave blank for all', 'aggregate-it' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="ai-exclude"><?php esc_html_e( 'Skip if it mentions', 'aggregate-it' ); ?></label></th>
					<td><input name="exclude_keywords" id="ai-exclude" type="text" class="regular-text"
						value="<?php echo esc_attr( (string) ( $editing->settings['exclude_keywords'] ?? '' ) ); ?>"
						placeholder="<?php esc_attr_e( 'comma separated', 'aggregate-it' ); ?>"></td>
				</tr>
				<?php $feed_status = $editing ? (string) ( $editing->settings['publish_status'] ?? 'default' ) : 'default'; ?>
				<tr>
					<th><label for="ai-publish"><?php esc_html_e( 'Publish as', 'aggregate-it' ); ?></label></th>
					<td>
						<select name="publish_status" id="ai-publish">
							<option value="default" <?php selected( $feed_status, 'default' ); ?>><?php esc_html_e( 'Use the global setting', 'aggregate-it' ); ?></option>
							<option value="publish" <?php selected( $feed_status, 'publish' ); ?>><?php esc_html_e( 'Published', 'aggregate-it' ); ?></option>
							<option value="draft" <?php selected( $feed_status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'aggregate-it' ); ?></option>
							<option value="pending" <?php selected( $feed_status, 'pending' ); ?>><?php esc_html_e( 'Pending review', 'aggregate-it' ); ?></option>
						</select>
					</td>
				</tr>
				<?php $feed_length = $editing ? (string) ( $editing->settings['article_length'] ?? 'default' ) : 'default'; ?>
				<tr>
					<th><label for="ai-length"><?php esc_html_e( 'Article length', 'aggregate-it' ); ?></label></th>
					<td>
						<select name="article_length" id="ai-length">
							<option value="default" <?php selected( $feed_length, 'default' ); ?>><?php esc_html_e( 'Use the global setting', 'aggregate-it' ); ?></option>
							<option value="auto" <?php selected( $feed_length, 'auto' ); ?>><?php esc_html_e( 'As needed', 'aggregate-it' ); ?></option>
							<option value="short" <?php selected( $feed_length, 'short' ); ?>><?php esc_html_e( 'Short (~300 words)', 'aggregate-it' ); ?></option>
							<option value="medium" <?php selected( $feed_length, 'medium' ); ?>><?php esc_html_e( 'Medium (~600 words)', 'aggregate-it' ); ?></option>
							<option value="long" <?php selected( $feed_length, 'long' ); ?>><?php esc_html_e( 'Long (~1000 words)', 'aggregate-it' ); ?></option>
							<option value="match" <?php selected( $feed_length, 'match' ); ?>><?php esc_html_e( 'Match the original', 'aggregate-it' ); ?></option>
						</select>
					</td>
				</tr>
				<?php if ( $editing ) : ?>
				<tr>
					<th><label for="ai-status"><?php esc_html_e( 'Status', 'aggregate-it' ); ?></label></th>
					<td>
						<select name="status" id="ai-status">
							<option value="active" <?php selected( $editing->status, 'active' ); ?>><?php esc_html_e( 'Active', 'aggregate-it' ); ?></option>
							<option value="paused" <?php selected( $editing->status, 'paused' ); ?>><?php esc_html_e( 'Paused', 'aggregate-it' ); ?></option>
						</select>
					</td>
				</tr>
				<?php endif; ?>
			</table>

			<p>
				<button type="submit" class="button button-primary"><?php echo $editing ? esc_html__( 'Save feed', 'aggregate-it' ) : esc_html__( 'Add a feed', 'aggregate-it' ); ?></button>
				<?php if ( $editing ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=aggregate-it-sources' ) ); ?>"><?php esc_html_e( 'Cancel', 'aggregate-it' ); ?></a>
				<?php endif; ?>
			</p>
		</form>
	</div>

	<details class="ai-panel ai-narrow">
		<summary><?php esc_html_e( 'Import many feeds at once (OPML)', 'aggregate-it' ); ?></summary>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aggregate_it_import_opml">
			<?php wp_nonce_field( 'aggregate_it_import_opml' ); ?>
			<p><input type="file" id="ai-opml-file" accept=".opml,.xml,text/xml"></p>
			<textarea name="opml" id="ai-opml" rows="6" class="large-text code"
				aria-label="<?php esc_attr_e( 'OPML to import', 'aggregate-it' ); ?>"
				placeholder="<?php esc_attr_e( 'Choose a file above, or paste your OPML export here', 'aggregate-it' ); ?>"></textarea>
			<p><button type="submit" class="button"><?php esc_html_e( 'Import feeds', 'aggregate-it' ); ?></button></p>
		</form>
		<script>
		( function () {
			var input = document.getElementById( 'ai-opml-file' );
			var area = document.getElementById( 'ai-opml' );
			if ( ! input || ! area ) { return; }
			input.addEventListener( 'change', function () {
				var file = input.files && input.files[0];
				if ( ! file ) { return; }
				var reader = new FileReader();
				reader.onload = function () { area.value = String( reader.result || '' ); };
				reader.readAsText( file );
			} );
		} )();
		</script>
	</details>

	<div class="ai-panel">
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ai-bulk-sources">
	<input type="hidden" name="action" value="aggregate_it_bulk_sources">
	<?php wp_nonce_field( 'aggregate_it_bulk_sources' ); ?>
	<div class="ai-inline" style="margin-bottom: 12px;">
		<label for="ai-source-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'aggregate-it' ); ?></label>
		<select name="bulk_action" id="ai-source-bulk-action">
			<option value=""><?php esc_html_e( 'Bulk actions', 'aggregate-it' ); ?></option>
			<option value="activate"><?php esc_html_e( 'Activate', 'aggregate-it' ); ?></option>
			<option value="pause"><?php esc_html_e( 'Pause', 'aggregate-it' ); ?></option>
			<option value="auto_categories"><?php esc_html_e( 'Auto-match categories', 'aggregate-it' ); ?></option>
			<option value="set_category"><?php esc_html_e( 'Set category', 'aggregate-it' ); ?></option>
			<option value="clear_categories"><?php esc_html_e( 'Clear categories', 'aggregate-it' ); ?></option>
			<option value="delete"><?php esc_html_e( 'Delete', 'aggregate-it' ); ?></option>
		</select>
		<?php $bulk_cats = get_categories( [ 'hide_empty' => false, 'number' => 200 ] ); ?>
		<label for="ai-bulk-category" class="screen-reader-text"><?php esc_html_e( 'Category', 'aggregate-it' ); ?></label>
		<select name="bulk_category" id="ai-bulk-category">
			<option value="0"><?php esc_html_e( 'Choose category', 'aggregate-it' ); ?></option>
			<?php foreach ( $bulk_cats as $cat ) : ?>
				<option value="<?php echo (int) $cat->term_id; ?>"><?php echo esc_html( $cat->name ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'Apply', 'aggregate-it' ); ?></button>
	</div>
	<table class="widefat striped">
		<thead>
			<tr>
				<td class="manage-column column-cb check-column"><input type="checkbox" id="ai-source-select-all"></td>
				<th><?php esc_html_e( 'Title', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Feed address', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Status', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Last checked', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'How it is doing', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'aggregate-it' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $sources ) : ?>
				<tr><td colspan="7" class="ai-empty"><?php esc_html_e( 'No feeds yet. Add one above to get started.', 'aggregate-it' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $sources as $source ) : ?>
				<?php
				$health = $source->health;
				$health_text = ! empty( $health['last_error'] )
					? esc_html( sprintf( /* translators: %s: error message */ __( 'Problem: %s', 'aggregate-it' ), $health['last_error'] ) )
					: ( isset( $health['last_imported'] ) ? sprintf( /* translators: %d: article count */ esc_html__( '%d new articles last time', 'aggregate-it' ), (int) $health['last_imported'] ) : '—' );
				$import_url = wp_nonce_url( admin_url( 'admin-post.php?action=aggregate_it_import_now&id=' . $source->id ), 'aggregate_it_import_now_' . $source->id );
				$delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=aggregate_it_delete_source&id=' . $source->id ), 'aggregate_it_delete_source_' . $source->id );
				$edit_url   = admin_url( 'admin.php?page=aggregate-it-sources&edit=' . $source->id );
				?>
				<tr>
					<th scope="row" class="check-column"><input type="checkbox" name="source_ids[]" value="<?php echo (int) $source->id; ?>"></th>
					<td><?php echo esc_html( $source->title ?: '—' ); ?></td>
					<td class="ai-trunc"><?php echo esc_html( $source->url ); ?></td>
					<td><span class="ai-state ai-state--<?php echo esc_attr( $source->status ); ?>"><?php echo esc_html( $source->status ); ?></span></td>
					<td><?php echo esc_html( $source->last_checked ?: '—' ); ?></td>
					<td><?php echo $health_text; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td class="ai-row-actions ai-inline">
						<a class="button button-small" href="<?php echo esc_url( $import_url ); ?>"><?php esc_html_e( 'Check now', 'aggregate-it' ); ?></a>
						<a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'aggregate-it' ); ?></a>
						<a class="button button-small" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this feed?', 'aggregate-it' ) ); ?>');"><?php esc_html_e( 'Delete', 'aggregate-it' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	</form>
	<script>
	( function () {
		var form = document.getElementById( 'ai-bulk-sources' );
		var all = document.getElementById( 'ai-source-select-all' );
		if ( all && form ) {
			all.addEventListener( 'change', function () {
				form.querySelectorAll( 'input[name="source_ids[]"]' ).forEach( function ( input ) {
					input.checked = all.checked;
				} );
			} );
		}
		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				var action = document.getElementById( 'ai-source-bulk-action' );
				if ( action && action.value === 'delete' && ! confirm( '<?php echo esc_js( __( 'Delete selected feeds?', 'aggregate-it' ) ); ?>' ) ) {
					event.preventDefault();
				}
			} );
		}
	} )();
	</script>
	</div>
</div>
