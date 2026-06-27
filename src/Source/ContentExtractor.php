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
			$image    = $this->best_meta_image( $html );
			$readable = $this->readability( $html );
			if ( mb_strlen( $readable ) > mb_strlen( $feed_text ) ) {
				return [ 'content' => $readable, 'source' => 'readability', 'image' => $image ];
			}
			return [ 'content' => $feed_text, 'source' => 'feed', 'image' => $image ];
		}

		return [ 'content' => $feed_text, 'source' => 'feed', 'image' => '' ];
	}

	/**
	 * The publisher's chosen share image for this article — the correct hero in almost
	 * all cases (feed enclosures are often generic/sub-topic images). Fetches the page
	 * (cached + polite) and returns the best meta image, or '' on any failure.
	 */
	public function share_image( string $url ): string {
		if ( $url === '' ) {
			return '';
		}
		try {
			$html = $this->fetcher->fetch( $url );
		} catch ( \Throwable $e ) {
			return '';
		}
		return is_string( $html ) ? $this->best_meta_image( $html ) : '';
	}

	private function best_meta_image( string $html ): string {
		foreach ( [ 'og:image:secure_url', 'og:image', 'twitter:image', 'twitter:image:src' ] as $key ) {
			$image = $this->meta_content( $html, $key );
			if ( $image !== '' && ! $this->is_junk_image( $image ) ) {
				return $image;
			}
		}
		// Last resort: the link rel="image_src" hint, then the first real in-page image.
		$link = $this->link_image( $html );
		if ( $link !== '' ) {
			return $link;
		}
		return $this->first_content_image( $html );
	}

	private function link_image( string $html ): string {
		if ( preg_match( '/<link[^>]+rel=["\']image_src["\'][^>]+href=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$url = trim( html_entity_decode( $m[1] ) );
			return $this->is_junk_image( $url ) ? '' : $url;
		}
		return '';
	}

	private function first_content_image( string $html ): string {
		if ( ! preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m ) ) {
			return '';
		}
		foreach ( $m[1] as $src ) {
			$src = trim( html_entity_decode( $src ) );
			if ( preg_match( '#^https?://#i', $src ) && ! $this->is_junk_image( $src ) ) {
				return $src;
			}
		}
		return '';
	}

	private function meta_content( string $html, string $key ): string {
		$e = preg_quote( $key, '/' );
		if ( preg_match( '/<meta[^>]+(?:property|name)=["\']' . $e . '["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			return trim( html_entity_decode( $m[1] ) );
		}
		if ( preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . $e . '["\']/i', $html, $m ) ) {
			return trim( html_entity_decode( $m[1] ) );
		}
		return '';
	}

	private function is_junk_image( string $url ): bool {
		if ( strpos( $url, 'data:' ) === 0 ) {
			return true;
		}
		// Match the filename only — matching the whole URL false-positives on hosts/paths
		// like siliconangle.com ("icon"), dropping every valid image from that source.
		$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		return (bool) preg_match( '/(^|[-_.])(logo|icon|sprite|avatar|gravatar|placeholder|default|blank|spacer|favicon)([-_.]|$)/i', $name );
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
