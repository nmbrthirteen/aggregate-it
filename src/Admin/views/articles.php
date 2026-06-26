<?php

namespace AggregateIt\Admin;

use AggregateIt\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * @var object[]            $rows
 * @var int                 $total
 * @var int                 $paged
 * @var int                 $per_page
 * @var string              $status
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
		return [ 'Failed', 'ai-state--dead_letter' ];
	}
	if ( $row->state === 'published' ) {
		if ( ! empty( $flags['suppressed'] ) ) {
			$reason = [
				'thin'             => __( 'Skipped — too short', 'aggregate-it' ),
				'no-novelty'       => __( 'Skipped — duplicate', 'aggregate-it' ),
				'no-keyword-match' => __( 'Skipped — no keyword match', 'aggregate-it' ),
			];
			return [ $reason[ $flags['suppressed'] ] ?? __( 'Skipped', 'aggregate-it' ), '' ];
		}
		return ! empty( $flags['updated_post'] ) ? [ __( 'Added to a story', 'aggregate-it' ), 'ai-state--published' ] : [ __( 'Published', 'aggregate-it' ), 'ai-state--published' ];
	}
	return [ __( 'Being processed', 'aggregate-it' ), '' ];
};

$last_page = max( 1, (int) ceil( $total / $per_page ) );
$base      = admin_url( 'admin.php?page=aggregate-it-articles' );
if ( $status !== '' ) {
	$base = add_query_arg( 'status', $status, $base );
}
$notice = isset( $_GET['ai_notice'] ) ? sanitize_key( wp_unslash( $_GET['ai_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
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

	<?php
	$notices = [
		'retried'         => __( 'Sent back to be processed again.', 'aggregate-it' ),
		'deleted'         => __( 'Article removed.', 'aggregate-it' ),
		'image_refreshed' => __( 'Image refreshed. Check the post.', 'aggregate-it' ),
	];
	?>
	<?php if ( isset( $notices[ $notice ] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notices[ $notice ] ); ?></p></div>
	<?php endif; ?>

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

	<div class="ai-panel">
	<table class="widefat striped" style="clear:both;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Article', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'From feed', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Status', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Published post', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Imported', 'aggregate-it' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="6"><em><?php esc_html_e( 'Nothing imported yet. Add a feed and it will fill up here.', 'aggregate-it' ); ?></em></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$flags    = (array) Json::decode( $row->flags ?? null, [] );
				$title    = (string) ( $flags['title'] ?? '' );
				$title    = $title !== '' ? $title : '(' . esc_html__( 'untitled', 'aggregate-it' ) . ')';
				[ $label, $class ] = $status_label( $row, $flags );
				$post_id  = (int) ( $row->post_id ?? 0 );
				?>
				<tr>
					<td>
						<?php if ( $row->url ) : ?>
							<a href="<?php echo esc_url( $row->url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $title ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $title ); ?>
						<?php endif; ?>
						<div class="ai-muted">#<?php echo (int) $row->id; ?></div>
					</td>
					<td><?php echo esc_html( $feeds[ (int) $row->source_id ] ?? '—' ); ?></td>
					<td><span class="ai-state <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></span>
						<?php if ( $row->state === 'dead_letter' ) : ?>
							<?php if ( $row->last_error ) : ?>
								<div class="ai-muted"><?php echo esc_html( $row->last_error ); ?></div>
							<?php endif; ?>
							<form method="post" action="<?php echo $post_action; ?>">
								<input type="hidden" name="action" value="aggregate_it_retry_article">
								<input type="hidden" name="id" value="<?php echo (int) $row->id; ?>">
								<?php wp_nonce_field( 'aggregate_it_retry_article_' . (int) $row->id ); ?>
								<button class="button button-small"><?php esc_html_e( 'Retry', 'aggregate-it' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $post_id && get_post( $post_id ) ) : ?>
							<a href="<?php echo esc_url( (string) get_permalink( $post_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
							&nbsp;·&nbsp;<a href="<?php echo esc_url( (string) get_edit_post_link( $post_id ) ); ?>"><?php esc_html_e( 'Edit', 'aggregate-it' ); ?></a>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $row->created_at ); ?></td>
					<td class="ai-row-actions ai-inline">
						<?php if ( $post_id && get_post( $post_id ) ) : ?>
							<form method="post" action="<?php echo $post_action; ?>">
								<input type="hidden" name="action" value="aggregate_it_refresh_image">
								<input type="hidden" name="id" value="<?php echo (int) $row->id; ?>">
								<?php wp_nonce_field( 'aggregate_it_refresh_image_' . (int) $row->id ); ?>
								<button class="button button-small"><?php esc_html_e( 'Refresh image', 'aggregate-it' ); ?></button>
							</form>
						<?php endif; ?>
						<form method="post" action="<?php echo $post_action; ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this article? The published post is moved to Trash.', 'aggregate-it' ) ); ?>');">
							<input type="hidden" name="action" value="aggregate_it_delete_article">
							<input type="hidden" name="id" value="<?php echo (int) $row->id; ?>">
							<?php wp_nonce_field( 'aggregate_it_delete_article_' . (int) $row->id ); ?>
							<button class="button button-small"><?php esc_html_e( 'Delete', 'aggregate-it' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $last_page > 1 ) : ?>
		<p class="ai-inline">
			<?php if ( $paged > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, $base ) ); ?>">&laquo; <?php esc_html_e( 'Newer', 'aggregate-it' ); ?></a>
			<?php endif; ?>
			<span class="ai-muted"><?php echo esc_html( sprintf( /* translators: 1: current page, 2: total pages */ __( 'Page %1$d of %2$d', 'aggregate-it' ), $paged, $last_page ) ); ?></span>
			<?php if ( $paged < $last_page ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, $base ) ); ?>"><?php esc_html_e( 'Older', 'aggregate-it' ); ?> &raquo;</a>
			<?php endif; ?>
		</p>
	<?php endif; ?>
	</div>
</div>
