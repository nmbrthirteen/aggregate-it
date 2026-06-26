<?php

namespace AggregateIt\Publish;

use AggregateIt\Settings;
use AggregateIt\Support\EventLog;

defined( 'ABSPATH' ) || exit;

/**
 * Imports a feed/article image into the media library (never hotlinked) and sets it as
 * the featured image with generated alt text. Configurable off/import — default
 * conservative. Source images carry copyright risk, so this is opt-out-able.
 */
final class ImageImporter {

	public function __construct( private Settings $settings ) {}

	public function maybe_import( int $post_id, string $image_url, string $alt, bool $replace = false ): void {
		if ( ! $replace && $this->settings->image_mode() === 'off' ) {
			return;
		}
		if ( $image_url === '' ) {
			EventLog::warning( sprintf( 'Post #%d: no image found for this article.', $post_id ) );
			return;
		}
		if ( has_post_thumbnail( $post_id ) && ! $replace ) {
			return;
		}

		$this->load_media_deps();

		$attachment_id = media_sideload_image( esc_url_raw( $image_url ), $post_id, $alt, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			EventLog::warning( sprintf( 'Post #%d: could not save the image (%s): %s', $post_id, esc_url_raw( $image_url ), $attachment_id->get_error_message() ) );
			return;
		}

		set_post_thumbnail( $post_id, (int) $attachment_id );
		update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
	}

	private function load_media_deps(): void {
		if ( function_exists( 'media_sideload_image' ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
}
