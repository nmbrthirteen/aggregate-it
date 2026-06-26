<?php

namespace AggregateIt\Publish;

use AggregateIt\Ai\Rewriter;
use AggregateIt\Seo\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Re-runs an already-published post through the rewriter again — with the current model,
 * writing style, and prompt — without re-importing. Uses the stored original article
 * text. The slug is left unchanged so the URL stays stable.
 */
final class Reprocessor {

	public function __construct(
		private Rewriter $rewriter,
		private Seo $seo
	) {}

	public function reprocess( int $post_id ): bool {
		$original = (string) get_post_meta( $post_id, '_ai_original', true );
		if ( $original === '' ) {
			return false;
		}

		$rewrite    = $this->rewriter->rewrite( get_the_title( $post_id ), $original );
		$structured = $rewrite['result'];

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_title'   => (string) ( $structured['seo_title'] ?? get_the_title( $post_id ) ),
				'post_content' => $this->body( (string) ( $structured['rewritten_body'] ?? '' ) ),
			]
		);

		update_post_meta( $post_id, '_ai_facts', (array) ( $structured['facts'] ?? [] ) );

		$this->seo->write_meta(
			$post_id,
			[
				'title'         => (string) ( $structured['seo_title'] ?? '' ),
				'description'   => (string) ( $structured['meta_description'] ?? '' ),
				'focus_keyword' => (string) get_post_meta( $post_id, '_ai_focus_keyword', true ),
			]
		);

		return true;
	}

	private function body( string $text ): string {
		$paragraphs = preg_split( '/\n{2,}/', trim( $text ) ) ?: [];
		$html       = '';
		foreach ( $paragraphs as $p ) {
			$p = trim( $p );
			if ( $p !== '' ) {
				$html .= '<p>' . esc_html( $p ) . "</p>\n";
			}
		}
		return $html;
	}
}
