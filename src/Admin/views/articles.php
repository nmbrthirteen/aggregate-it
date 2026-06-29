<?php

namespace AggregateIt\Admin;

use AggregateIt\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * @var object[]            $rows
 * @var int                 $total
 * @var int                 $paged
 * @var int                 $per_page
 * @var int[]               $allowed_per_page
 * @var string              $status
 * @var string              $search
 * @var string              $flash_message
 * @var array<string,int>   $counts
 * @var array<int,string>   $feeds
 */

$post_action = esc_url( admin_url( 'admin-post.php' ) );
$tabs        = [
	''           => __( 'All', 'aggregate-it' ),
	'published'  => __( 'Published', 'aggregate-it' ),
	'processing' => __( 'Being processed', 'aggregate-it' ),
	'skipped'    => __( 'Skipped', 'aggregate-it' ),
	'failed'     => __( 'Failed', 'aggregate-it' ),
];

$status_label = static function ( object $row, array $flags ): array {
	if ( $row->state === 'dead_letter' ) {
		return [ __( 'Failed', 'aggregate-it' ), 'post-state' ];
	}
	if ( $row->state === 'published' ) {
		if ( ! empty( $flags['suppressed'] ) ) {
			$reason = [
				'thin'             => __( 'Skipped — too short', 'aggregate-it' ),
				'no-novelty'       => __( 'Skipped — duplicate', 'aggregate-it' ),
				'no-keyword-match' => __( 'Skipped — no keyword match', 'aggregate-it' ),
			];
			return [ $reason[ $flags['suppressed'] ] ?? __( 'Skipped', 'aggregate-it' ), 'post-state' ];
		}
		return ! empty( $flags['updated_post'] ) ? [ __( 'Added to a story', 'aggregate-it' ), 'post-state' ] : [ __( 'Published', 'aggregate-it' ), 'post-state' ];
	}
	return [ __( 'Being processed', 'aggregate-it' ), 'post-state' ];
};

$ctx = array_filter(
	[
		'paged'    => $paged > 1 ? $paged : null,
		'status'   => $status !== '' ? $status : null,
		's'        => $search !== '' ? $search : null,
		'per_page' => $per_page !== 50 ? $per_page : null,
	]
);
$action_link = static function ( string $action, int $id ) use ( $ctx ) {
	$url = add_query_arg( $ctx, admin_url( 'admin-post.php?action=' . $action . '&id=' . $id ) );
	return esc_url( wp_nonce_url( $url, $action . '_' . $id ) );
};

$last_page = max( 1, (int) ceil( $total / $per_page ) );
$base      = admin_url( 'admin.php?page=aggregate-it-articles' );
if ( $status !== '' ) {
	$base = add_query_arg( 'status', $status, $base );
}
if ( $search !== '' ) {
	$base = add_query_arg( 's', $search, $base );
}
if ( $per_page !== 50 ) {
	$base = add_query_arg( 'per_page', $per_page, $base );
}
$notice  = isset( $_GET['ai_notice'] ) ? sanitize_key( wp_unslash( $_GET['ai_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$notices = [
	'retried'         => __( 'Sent back to be processed again.', 'aggregate-it' ),
	'deleted'         => __( 'Article removed.', 'aggregate-it' ),
	'image_refreshed' => __( 'Image refreshed. Check the post.', 'aggregate-it' ),
	'image_refresh_failed' => __( 'Could not fetch the image right now (the source was busy). Try again in a few seconds.', 'aggregate-it' ),
	'rewritten'       => __( 'Rewritten. Check the post.', 'aggregate-it' ),
	'bulk_done'       => __( 'Done.', 'aggregate-it' ),
];
?>
<div class="wrap aggregate-it">
	<div class="ai-head">
		<h1><?php esc_html_e( 'Articles', 'aggregate-it' ); ?></h1>
		<?php if ( ! empty( $counts['failed'] ) ) : ?>
			<div class="ai-actions">
				<form method="post" action="<?php echo $post_action; ?>">
					<input type="hidden" name="action" value="aggregate_it_retry_failed">
					<?php wp_nonce_field( 'aggregate_it_retry_failed' ); ?>
					<button class="button"><?php esc_html_e( 'Retry all failed', 'aggregate-it' ); ?></button>
				</form>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( $flash_message !== '' ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $flash_message ); ?></p></div>
	<?php elseif ( isset( $notices[ $notice ] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notices[ $notice ] ); ?></p></div>
	<?php endif; ?>

	<form method="get" class="ai-search" style="margin:12px 0;">
		<input type="hidden" name="page" value="aggregate-it-articles">
		<?php if ( $status !== '' ) : ?>
			<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
		<?php endif; ?>
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search title or URL…', 'aggregate-it' ); ?>" class="regular-text">
		<button type="submit" class="button"><?php esc_html_e( 'Search', 'aggregate-it' ); ?></button>
		<label class="ai-perpage">
			<?php esc_html_e( 'Show', 'aggregate-it' ); ?>
			<select name="per_page" onchange="this.form.submit();">
				<?php foreach ( $allowed_per_page as $option ) : ?>
					<option value="<?php echo (int) $option; ?>" <?php selected( $per_page, $option ); ?>><?php echo (int) $option; ?></option>
				<?php endforeach; ?>
			</select>
			<?php esc_html_e( 'per page', 'aggregate-it' ); ?>
		</label>
		<?php if ( $search !== '' ) : ?>
			<a class="button-link" href="<?php echo esc_url( $status !== '' ? add_query_arg( 'status', $status, admin_url( 'admin.php?page=aggregate-it-articles' ) ) : admin_url( 'admin.php?page=aggregate-it-articles' ) ); ?>"><?php esc_html_e( 'Clear', 'aggregate-it' ); ?></a>
			<span class="description">
				<?php
				/* translators: 1: result count, 2: search term */
				echo esc_html( sprintf( _n( '%1$d result for “%2$s”', '%1$d results for “%2$s”', $total, 'aggregate-it' ), $total, $search ) );
				?>
			</span>
		<?php endif; ?>
	</form>

	<ul class="subsubsub">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<?php $url = $key === '' ? admin_url( 'admin.php?page=aggregate-it-articles' ) : add_query_arg( 'status', $key, admin_url( 'admin.php?page=aggregate-it-articles' ) ); ?>
			<li>
				<a href="<?php echo esc_url( $url ); ?>" class="<?php echo $status === $key ? 'current' : ''; ?>">
					<?php echo esc_html( $label ); ?> <span class="count">(<?php echo (int) ( $counts[ $key ] ?? 0 ); ?>)</span>
				</a><?php echo $key !== 'failed' ? ' |' : ''; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<form method="post" action="<?php echo $post_action; ?>" id="aggregate-it-articles" onsubmit="return aiBulkConfirm(this);">
		<input type="hidden" name="action" value="aggregate_it_bulk_articles">
		<input type="hidden" name="paged" value="<?php echo (int) $paged; ?>">
		<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
		<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">
		<input type="hidden" name="per_page" value="<?php echo (int) $per_page; ?>">
		<?php wp_nonce_field( 'aggregate_it_bulk_articles' ); ?>

		<div class="tablenav top">
			<div class="alignleft actions bulkactions">
				<select name="bulk_action">
					<option value="-1"><?php esc_html_e( 'Bulk actions', 'aggregate-it' ); ?></option>
					<option value="publish"><?php esc_html_e( 'Publish', 'aggregate-it' ); ?></option>
					<option value="draft"><?php esc_html_e( 'Move to draft', 'aggregate-it' ); ?></option>
					<option value="rewrite"><?php esc_html_e( 'Rewrite', 'aggregate-it' ); ?></option>
					<option value="refresh_image"><?php esc_html_e( 'Refresh featured image', 'aggregate-it' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete', 'aggregate-it' ); ?></option>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Apply', 'aggregate-it' ); ?></button>
			</div>
		</div>

		<table class="widefat striped">
			<thead>
				<tr>
					<td class="manage-column check-column"><input type="checkbox" id="ai-cb-all" aria-label="<?php esc_attr_e( 'Select all articles', 'aggregate-it' ); ?>"></td>
					<th><?php esc_html_e( 'Article', 'aggregate-it' ); ?></th>
					<th><?php esc_html_e( 'From feed', 'aggregate-it' ); ?></th>
					<th><?php esc_html_e( 'Status', 'aggregate-it' ); ?></th>
					<th><?php esc_html_e( 'Published post', 'aggregate-it' ); ?></th>
					<th><?php esc_html_e( 'Imported', 'aggregate-it' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6" class="ai-empty"><?php esc_html_e( 'Nothing imported yet. Add a feed and it will fill up here.', 'aggregate-it' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$flags = (array) Json::decode( $row->flags ?? null, [] );
					$title = (string) ( $flags['title'] ?? '' );
					$title = $title !== '' ? $title : __( '(untitled)', 'aggregate-it' );
					[ $label, $class ] = $status_label( $row, $flags );
					$post_id = (int) ( $row->post_id ?? 0 );
					$has_post = $post_id && get_post( $post_id );
					?>
					<tr>
						<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="<?php echo (int) $row->id; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: article title */ __( 'Select %s', 'aggregate-it' ), $title ) ); ?>"></th>
						<td>
							<?php if ( $row->url ) : ?>
								<a href="<?php echo esc_url( $row->url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $title ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $title ); ?>
							<?php endif; ?>
							<div class="description">#<?php echo (int) $row->id; ?></div>
							<div class="row-actions">
								<?php if ( $has_post ) : ?>
									<span class="edit"><a href="<?php echo $action_link( 'aggregate_it_rewrite_article', (int) $row->id ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Rewrite this article with AI now? This makes a paid API call.', 'aggregate-it' ) ); ?>');"><?php esc_html_e( 'Rewrite', 'aggregate-it' ); ?></a> | </span>
									<span class="edit"><a href="<?php echo $action_link( 'aggregate_it_refresh_image', (int) $row->id ); ?>"><?php esc_html_e( 'Refresh image', 'aggregate-it' ); ?></a> | </span>
								<?php endif; ?>
								<span class="trash"><a href="<?php echo $action_link( 'aggregate_it_delete_article', (int) $row->id ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Remove this article? The published post is moved to Trash.', 'aggregate-it' ) ); ?>');"><?php esc_html_e( 'Delete', 'aggregate-it' ); ?></a></span>
							</div>
						</td>
						<td><?php echo esc_html( $feeds[ (int) $row->source_id ] ?? '—' ); ?></td>
						<td><span class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></span>
							<?php if ( $row->state === 'dead_letter' ) : ?>
								<?php if ( $row->last_error ) : ?>
									<div class="description"><?php echo esc_html( $row->last_error ); ?></div>
								<?php endif; ?>
								<div><a class="button button-small" href="<?php echo $action_link( 'aggregate_it_retry_article', (int) $row->id ); ?>"><?php esc_html_e( 'Retry', 'aggregate-it' ); ?></a></div>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $has_post ) : ?>
								<a href="<?php echo esc_url( (string) get_permalink( $post_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
								&nbsp;·&nbsp;<a href="<?php echo esc_url( (string) get_edit_post_link( $post_id ) ); ?>"><?php esc_html_e( 'Edit', 'aggregate-it' ); ?></a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row->created_at ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</form>

	<?php if ( $last_page > 1 ) : ?>
		<p class="ai-inline">
			<?php if ( $paged > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, $base ) ); ?>">&laquo; <?php esc_html_e( 'Newer', 'aggregate-it' ); ?></a>
			<?php endif; ?>
			<span class="description"><?php echo esc_html( sprintf( /* translators: 1: current page, 2: total pages */ __( 'Page %1$d of %2$d', 'aggregate-it' ), $paged, $last_page ) ); ?></span>
			<?php if ( $paged < $last_page ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, $base ) ); ?>"><?php esc_html_e( 'Older', 'aggregate-it' ); ?> &raquo;</a>
			<?php endif; ?>
		</p>
	<?php endif; ?>
</div>
<script>
( function () {
	var all = document.getElementById( 'ai-cb-all' );
	var form = document.getElementById( 'aggregate-it-articles' );
	if ( all && form ) {
		all.addEventListener( 'change', function ( e ) {
			form.querySelectorAll( 'input[name="ids[]"]' ).forEach( function ( c ) { c.checked = e.target.checked; } );
		} );
	}
	window.aiBulkConfirm = function ( f ) {
		if ( f.bulk_action.value === '-1' ) { return false; }
		if ( ! f.querySelectorAll( 'input[name="ids[]"]:checked' ).length ) {
			window.alert( '<?php echo esc_js( __( 'Select at least one article first.', 'aggregate-it' ) ); ?>' );
			return false;
		}
		if ( f.bulk_action.value === 'delete' ) { return confirm( '<?php echo esc_js( __( 'Delete the selected articles? Their posts move to Trash.', 'aggregate-it' ) ); ?>' ); }
		if ( f.bulk_action.value === 'rewrite' ) { return confirm( '<?php echo esc_js( __( 'Rewrite the selected articles with AI? This makes a paid API call for each one.', 'aggregate-it' ) ); ?>' ); }
		return true;
	};
} )();
</script>
