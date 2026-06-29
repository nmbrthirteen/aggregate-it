<?php

namespace AggregateIt\Source\Parser;

use AggregateIt\Source\HttpFetcher;
use AggregateIt\Source\Scrape\CssToXpath;
use AggregateIt\Source\Scrape\FieldExtractor;
use AggregateIt\Source\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Generalized HTML scraper. At import time it does exactly one fetch — the listing or
 * sitemap page — and turns repeating rows into normalized entries; per-item detail-page
 * fetches are deliberately left to the pipeline (ExtractStage), where throttle/defer and
 * retry already apply. Nothing here is specific to any site: rows, fields, and their
 * destinations all come from the source's scrape config.
 */
final class ScraperParser implements SourceParser {

	private const STANDARD = [ 'guid', 'url', 'title', 'content', 'description', 'image', 'date' ];

	public function __construct( private HttpFetcher $http ) {}

	/** @return array<int,array<string,mixed>> */
	public function parse( Source $source ): array {
		$cfg  = $source->scrape_config();
		$mode = (string) ( $cfg['discovery']['mode'] ?? 'list' );
		$body = $this->http->fetch( $source->url );
		if ( ! is_string( $body ) || $body === '' ) {
			return [];
		}

		return $mode === 'sitemap'
			? $this->entries_from_sitemap( $body, (string) ( $cfg['discovery']['url_filter'] ?? '' ) )
			: $this->entries_from_html( $body, $cfg, $source->url );
	}

	/**
	 * Pure list-mode extraction (no network), so it can be unit-tested with fixture HTML.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function entries_from_html( string $html, array $cfg, string $base_url ): array {
		$item_selector = (string) ( $cfg['discovery']['item_selector'] ?? '' );
		$fields        = (array) ( $cfg['extraction']['fields'] ?? [] );
		if ( $item_selector === '' || ! $fields ) {
			return [];
		}

		$doc       = self::dom( $html );
		$xpath     = new \DOMXPath( $doc );
		$extractor = new FieldExtractor( $xpath );
		$rows      = $xpath->query( CssToXpath::convert( $item_selector ) );
		if ( ! $rows ) {
			return [];
		}

		$entries = [];
		foreach ( $rows as $row ) {
			$entry = $this->row_entry( $extractor, $row, $fields, $base_url );
			if ( $entry['guid'] !== '' ) {
				$entries[] = $entry;
			}
		}
		return $entries;
	}

	/**
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	private function row_entry( FieldExtractor $extractor, \DOMNode $row, array $fields, string $base_url ): array {
		$values = [];
		foreach ( $fields as $name => $rule ) {
			$values[ (string) $name ] = $extractor->value( $row, (array) $rule );
		}

		$url     = self::absolute_url( (string) ( $values['url'] ?? '' ), $base_url );
		$title   = (string) ( $values['title'] ?? '' );
		$content = (string) ( $values['content'] ?? ( $values['description'] ?? '' ) );
		$image   = self::absolute_url( (string) ( $values['image'] ?? '' ), $base_url );
		$guid    = (string) ( $values['guid'] ?? '' );
		if ( $guid === '' ) {
			$guid = $url !== '' ? $url : $title;
		}
		$date_raw = (string) ( $values['date'] ?? '' );
		$date     = $date_raw !== '' ? (int) strtotime( $date_raw ) : 0;

		$custom = array_diff_key( $values, array_flip( self::STANDARD ) );

		return [
			'guid'    => $guid,
			'url'     => $url,
			'title'   => $title,
			'content' => $content,
			'image'   => $image,
			'date'    => max( 0, $date ),
			'fields'  => array_map( 'strval', $custom ),
		];
	}

	/**
	 * Sitemap mode: each matching <loc> becomes a url-only entry; the title/content are
	 * filled later from the detail page during extraction.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function entries_from_sitemap( string $xml, string $url_filter ): array {
		if ( ! preg_match_all( '#<loc>\s*([^<]+?)\s*</loc>#i', $xml, $m ) ) {
			return [];
		}

		$entries = [];
		foreach ( $m[1] as $loc ) {
			$loc = html_entity_decode( trim( $loc ) );
			if ( $url_filter !== '' && @preg_match( '~' . str_replace( '~', '\~', $url_filter ) . '~', $loc ) !== 1 ) {
				continue;
			}
			$entries[] = [
				'guid'    => $loc,
				'url'     => $loc,
				'title'   => '',
				'content' => '',
				'image'   => '',
				'date'    => 0,
				'fields'  => [],
			];
		}
		return $entries;
	}

	private static function dom( string $html ): \DOMDocument {
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $doc;
	}

	private static function absolute_url( string $url, string $base ): string {
		$url = trim( $url );
		if ( $url === '' || preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		$parts = wp_parse_url( $base );
		if ( ! $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $url;
		}
		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		if ( str_starts_with( $url, '//' ) ) {
			return $parts['scheme'] . ':' . $url;
		}
		if ( str_starts_with( $url, '/' ) ) {
			return $origin . $url;
		}

		$path = isset( $parts['path'] ) ? preg_replace( '#/[^/]*$#', '/', $parts['path'] ) : '/';
		return $origin . $path . $url;
	}
}
