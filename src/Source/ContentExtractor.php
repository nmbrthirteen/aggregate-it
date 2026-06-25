<?php

namespace AggregateIt\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Hybrid extraction: use the feed's own content when it's already full enough; otherwise
 * fetch the article and run a dependency-free readability heuristic. The heuristic is
 * intentionally swappable via the `aggregate_it_extract_html` filter so a fuller
 * readability library can be dropped in without touching the pipeline.
 */
final class ContentExtractor {

	public function __construct( private HttpFetcher $fetcher ) {}

	/**
	 * @return array{content:string,source:string,image:string} source = 'feed' | 'readability'
	 */
	public function extract( string $url, string $feed_content, int $feed_threshold ): array {
		$feed_text = $this->to_text( $feed_content );

		if ( mb_strlen( $feed_text ) >= $feed_threshold ) {
			return [ 'content' => $feed_text, 'source' => 'feed', 'image' => '' ];
		}

		$html = $url !== '' ? $this->fetcher->fetch( $url ) : null;
		if ( is_string( $html ) && $html !== '' ) {
			$image    = $this->og_image( $html );
			$readable = $this->readability( $html );
			if ( mb_strlen( $readable ) > mb_strlen( $feed_text ) ) {
				return [ 'content' => $readable, 'source' => 'readability', 'image' => $image ];
			}
			return [ 'content' => $feed_text, 'source' => 'feed', 'image' => $image ];
		}

		return [ 'content' => $feed_text, 'source' => 'feed', 'image' => '' ];
	}

	private function og_image( string $html ): string {
		if ( preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			return trim( $m[1] );
		}
		if ( preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	private function readability( string $html ): string {
		$custom = apply_filters( 'aggregate_it_extract_html', null, $html );
		if ( is_string( $custom ) ) {
			return $this->normalize( $custom );
		}

		if ( ! class_exists( '\DOMDocument' ) ) {
			return '';
		}

		$dom = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		foreach ( [ 'script', 'style', 'nav', 'header', 'footer', 'aside', 'form', 'noscript', 'figure' ] as $tag ) {
			$nodes = $dom->getElementsByTagName( $tag );
			for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
				$node = $nodes->item( $i );
				$node->parentNode?->removeChild( $node );
			}
		}

		$best       = '';
		$best_score = 0;
		foreach ( [ 'article', 'main', 'body' ] as $tag ) {
			foreach ( $dom->getElementsByTagName( $tag ) as $container ) {
				$text = $this->paragraph_text( $container );
				if ( mb_strlen( $text ) > $best_score ) {
					$best       = $text;
					$best_score = mb_strlen( $text );
				}
			}
			if ( $best_score > 0 ) {
				break;
			}
		}

		return $this->normalize( $best );
	}

	private function paragraph_text( \DOMNode $container ): string {
		$parts = [];
		foreach ( $container->childNodes as $child ) {
			if ( $child instanceof \DOMElement && in_array( strtolower( $child->nodeName ), [ 'p', 'h2', 'h3', 'li', 'blockquote' ], true ) ) {
				$text = trim( $child->textContent );
				if ( mb_strlen( $text ) > 0 ) {
					$parts[] = $text;
				}
			} elseif ( $child instanceof \DOMElement ) {
				$nested = $this->paragraph_text( $child );
				if ( $nested !== '' ) {
					$parts[] = $nested;
				}
			}
		}
		return implode( "\n\n", $parts );
	}

	private function to_text( string $html ): string {
		return $this->normalize( wp_strip_all_tags( $html ) );
	}

	private function normalize( string $text ): string {
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );
		return trim( (string) $text );
	}
}
