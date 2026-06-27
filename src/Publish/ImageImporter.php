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

	private const EXT_BY_MIME = [
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
		'image/avif' => 'avif',
	];

	private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

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

		$attachment_id = $this->sideload( esc_url_raw( $image_url ), $post_id, $alt );
		if ( $attachment_id === 0 ) {
			return;
		}

		set_post_thumbnail( $post_id, $attachment_id );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
	}

	/**
	 * Download first, then name the file from its real MIME. WordPress's media_sideload_image()
	 * derives the extension from the URL and rejects extensionless URLs ("Invalid image URL.")
	 * before downloading — but most publishers serve og:image via extensionless CDN/proxy URLs
	 * (imgix, Cloudinary, thumbor, signed URLs), which is why featured images go missing.
	 */
	private function sideload( string $url, int $post_id, string $alt ): int {
		$tmp = $this->download( $url );
		if ( is_wp_error( $tmp ) ) {
			EventLog::warning( sprintf( 'Post #%d: could not fetch the image (%s): %s', $post_id, $url, $tmp->get_error_message() ) );
			return 0;
		}

		$name = $this->filename( $url, $tmp );
		if ( $name === '' ) {
			wp_delete_file( $tmp );
			EventLog::warning( sprintf( 'Post #%d: the downloaded file is not a supported image (%s).', $post_id, $url ) );
			return 0;
		}

		$attachment_id = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], $post_id, $alt );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			EventLog::warning( sprintf( 'Post #%d: could not save the image (%s): %s', $post_id, $url, $attachment_id->get_error_message() ) );
			return 0;
		}

		return (int) $attachment_id;
	}

	/** Present as a browser so hotlink-blocking publisher CDNs return the real image bytes. */
	private function download( string $url ) {
		$ua  = self::UA;
		$set = static function ( array $args ) use ( $ua ): array {
			$args['user-agent'] = $ua;
			return $args;
		};
		add_filter( 'http_request_args', $set );
		try {
			return download_url( $url );
		} finally {
			remove_filter( 'http_request_args', $set );
		}
	}

	private function filename( string $url, string $tmp ): string {
		$mime = function_exists( 'wp_get_image_mime' ) ? (string) wp_get_image_mime( $tmp ) : '';
		$ext  = self::EXT_BY_MIME[ $mime ] ?? '';
		if ( $ext === '' ) {
			return '';
		}

		$base = sanitize_title( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$base = $base !== '' ? (string) preg_replace( '/-(jpe?g|png|gif|webp|avif)$/i', '', $base ) : '';
		if ( $base === '' ) {
			$base = 'image-' . substr( md5( $url ), 0, 8 );
		}
		return $base . '.' . $ext;
	}

	private function load_media_deps(): void {
		if ( function_exists( 'media_handle_sideload' ) && function_exists( 'download_url' ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
}
