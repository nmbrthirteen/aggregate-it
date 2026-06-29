<?php

namespace AggregateIt\Source\Scrape;

use AggregateIt\Ai\AiProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the configured AI provider to propose a scrape config from a sample of listing-page
 * HTML: the repeating-item selector and a field map. The proposal is a starting point the
 * user reviews and edits in the form before saving — it is never applied blind.
 */
final class SelectorAssistant {

	private const MAX_HTML = 18000;

	public function __construct( private AiProvider $provider ) {}

	/** @return array{suggestion:array<string,mixed>,tokens:int,cost_usd:float} */
	public function suggest( string $html ): array {
		$out = $this->provider->structured( self::prompt( self::sample( $html ) ), self::schema(), [ 'max_tokens' => 900 ] );

		return [
			'suggestion' => (array) ( $out['result'] ?? [] ),
			'tokens'     => (int) ( $out['tokens'] ?? 0 ),
			'cost_usd'   => (float) ( $out['cost_usd'] ?? 0 ),
		];
	}

	public static function sample( string $html ): string {
		$html = (string) preg_replace( '#<(script|style|noscript|svg)\b[^>]*>.*?</\1>#is', '', $html );
		$html = (string) preg_replace( '#<!--.*?-->#s', '', $html );
		$html = (string) preg_replace( '/\s+/', ' ', $html );
		return mb_substr( trim( $html ), 0, self::MAX_HTML );
	}

	private static function prompt( string $sample ): string {
		return "You are configuring a web scraper that extracts a repeating list of items "
			. "(events, listings, articles) from an HTML page.\n\n"
			. "1. Give a CSS selector that matches each repeating item/row.\n"
			. "2. For each useful field, give a CSS selector RELATIVE to one item, the attribute "
			. "to read ('text' for the text content, or an attribute name like 'href' or 'src'), "
			. "and where it maps.\n"
			. "Use these field names for standard data: title, url, date, image, content. Use any "
			. "short snake_case name for extra data (e.g. location, venue).\n"
			. "For 'dest' use one of: default, post_title, post_content, post_excerpt, post_date, "
			. "featured_image, meta, taxonomy. Prefer 'default' for the standard field names and "
			. "'meta' for extra fields.\n\n"
			. "HTML sample:\n" . $sample;
	}

	/** @return array<string,mixed> */
	private static function schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'item_selector' => [ 'type' => 'string' ],
				'fields'        => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'name'     => [ 'type' => 'string' ],
							'selector' => [ 'type' => 'string' ],
							'attr'     => [ 'type' => 'string' ],
							'dest'     => [ 'type' => 'string' ],
						],
						'required'   => [ 'name', 'selector' ],
					],
				],
			],
			'required'   => [ 'item_selector', 'fields' ],
		];
	}
}
