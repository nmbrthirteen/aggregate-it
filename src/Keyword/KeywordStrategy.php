<?php

namespace AggregateIt\Keyword;

use AggregateIt\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the target keyword for a cluster. Layer 1 (default): use the AI-inferred
 * keyword (volume mode — publish everything). Layer 2 (optional): map to a configured
 * keyword list; in strategic mode, content that matches no target keyword is skipped.
 */
final class KeywordStrategy {

	public function __construct( private Settings $settings ) {}

	/**
	 * @return array{keyword:string,skip:bool}
	 */
	public function resolve( string $inferred, string $content ): array {
		$list = $this->settings->keyword_list();

		if ( ! $list ) {
			return [ 'keyword' => $inferred, 'skip' => false ];
		}

		$haystack = strtolower( $content . ' ' . $inferred );
		foreach ( $list as $keyword ) {
			if ( $keyword !== '' && strpos( $haystack, strtolower( $keyword ) ) !== false ) {
				return [ 'keyword' => $keyword, 'skip' => false ];
			}
		}

		// No target matched: strategic mode skips it, volume mode keeps the inferred one.
		return [ 'keyword' => $inferred, 'skip' => $this->settings->strategic_mode() ];
	}
}
