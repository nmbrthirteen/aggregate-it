<?php

namespace AggregateIt\Publish;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a derived/mapped value to a post field. When ACF is active it uses update_field so
 * the value lands in the matching ACF field with its field-key reference (so the ACF editor
 * shows it and date/select fields format correctly); otherwise it falls back to post meta.
 */
final class Meta {

	public static function write( int $post_id, string $key, $value ): void {
		if ( function_exists( 'update_field' ) ) {
			update_field( $key, $value, $post_id );
			return;
		}
		update_post_meta( $post_id, $key, $value );
	}
}
