<?php

namespace AggregateIt\Admin;

use AggregateIt\Support\Json;

defined( 'ABSPATH' ) || exit;

/**
 * @var object[]            $rows
 * @var int                 $total
 * @var int                 $paged
 * @var int                 $per_page
 * @var array<int,string>   $feeds
 */

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
?>
<div class="wrap aggregate-it">
	<h1><?php esc_html_e( 'Articles', 'aggregate-it' ); ?></h1>

	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Article', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'From feed', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Status', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Published post', 'aggregate-it' ); ?></th>
				<th><?php esc_html_e( 'Imported', 'aggregate-it' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="5"><em><?php esc_html_e( 'Nothing imported yet. Add a feed and it will fill up here.', 'aggregate-it' ); ?></em></td></tr>
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
						<div style="color:#646970;font-size:12px;">#<?php echo (int) $row->id; ?></div>
					</td>
					<td><?php echo esc_html( $feeds[ (int) $row->source_id ] ?? '—' ); ?></td>
					<td><span class="ai-state <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></span>
						<?php if ( $row->state === 'dead_letter' && $row->last_error ) : ?>
							<div style="color:#b32d2e;font-size:12px;"><?php echo esc_html( $row->last_error ); ?></div>
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
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $last_page > 1 ) : ?>
		<p style="margin-top:12px;">
			<?php if ( $paged > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, $base ) ); ?>">&laquo; <?php esc_html_e( 'Newer', 'aggregate-it' ); ?></a>
			<?php endif; ?>
			<span style="margin:0 8px;"><?php echo esc_html( sprintf( /* translators: 1: current page, 2: total pages */ __( 'Page %1$d of %2$d', 'aggregate-it' ), $paged, $last_page ) ); ?></span>
			<?php if ( $paged < $last_page ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, $base ) ); ?>"><?php esc_html_e( 'Older', 'aggregate-it' ); ?> &raquo;</a>
			<?php endif; ?>
		</p>
	<?php endif; ?>
</div>
