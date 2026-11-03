<?php

namespace AggregateIt\Keyword;

use AggregateIt\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the target keyword for a cluster. Layer 1 (default): use the AI-inferred
 * keyword (volume mode, publish everything). Layer 2 (optional): map to a configured
 * keyword list; in strategic mode, content that matches no target keyword is skipped.
 */
final class KeywordStrategy {

	public function __construct( private Settings $settings ) {}

	/** Optional keyword-data add-on registered through `aggregate_it_keyword_provider`. */
	public function provider(): ?KeywordProvider {
		$provider = apply_filters( 'aggregate_it_keyword_provider', null, $this->settings );
		return $provider instanceof KeywordProvider ? $provider : null;
	}

	/**
	 * @return array{keyword:string,skip:bool}
	 */
	public function resolve( string $inferred, string $content ): array {
		$decision = $this->match( $inferred, $content );

		if ( ! $decision['skip'] && $this->settings->strategic_mode() && $this->has_no_demand( $decision['keyword'] ) ) {
			$decision['skip'] = true;
		}

		return $decision;
	}

	/** True only when a provider is installed and reports zero search volume for the keyword. */
	private function has_no_demand( string $keyword ): bool {
		$provider = $this->provider();
		if ( ! $provider || $keyword === '' ) {
			return false;
		}
		try {
			$metrics = $provider->metrics( [ $keyword ] );
		} catch ( \Throwable $e ) {
			return false;
		}
		return isset( $metrics[ $keyword ]['volume'] ) && (int) $metrics[ $keyword ]['volume'] === 0;
	}

	/**
	 * @return array{keyword:string,skip:bool}
	 */
	private function match( string $inferred, string $content ): array {
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
