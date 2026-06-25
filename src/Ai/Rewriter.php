<?php

namespace AggregateIt\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * The single structured call: one round trip produces the rewritten body plus all SEO
 * fields, entities, and facts. Faithful rewrite — reword and strip promotional cruft,
 * never change facts or invent information.
 */
final class Rewriter {

	public function __construct( private ProviderFactory $providers ) {}

	/**
	 * @return array{result:array<string,mixed>,tokens:int,cost_usd:float}
	 */
	public function rewrite( string $title, string $content, ?string $target_keyword = null ): array {
		$prompt = $this->prompt( $title, $content, $target_keyword );
		return $this->providers->get()->structured( $prompt, $this->schema() );
	}

	private function prompt( string $title, string $content, ?string $target_keyword ): string {
		$rules = [
			'Rewrite the article below into original, well-structured prose.',
			'PRESERVE every fact verbatim: names, numbers, dates, quotes, and claims. Never invent or alter a fact.',
			'REMOVE promotional cruft: calls-to-action, "subscribe", affiliate lines, ads, author self-promotion, "read more" links.',
			'Keep news as news — neutral, factual tone. Do not editorialize.',
			'Return ONLY the structured object.',
		];

		if ( $target_keyword ) {
			$rules[] = sprintf( 'Target keyword "%s": include it naturally in the title and first paragraph. Never keyword-stuff.', $target_keyword );
		}

		$prompt = "You are a faithful news rewriter.\n\n"
			. implode( "\n", array_map( static fn ( $r ) => '- ' . $r, $rules ) )
			. "\n\nTITLE: " . $title
			. "\n\nARTICLE:\n" . $content;

		return (string) apply_filters( 'aggregate_it_rewrite_prompt', $prompt, $title, $content, $target_keyword );
	}

	/** @return array<string,mixed> */
	private function schema(): array {
		$schema = [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'rewritten_body', 'seo_title', 'meta_description', 'slug', 'primary_keyword' ],
			'properties'           => [
				'rewritten_body'   => [ 'type' => 'string' ],
				'seo_title'        => [ 'type' => 'string', 'maxLength' => 70 ],
				'meta_description' => [ 'type' => 'string', 'maxLength' => 160 ],
				'slug'             => [ 'type' => 'string' ],
				'primary_keyword'  => [ 'type' => 'string' ],
				'entities'         => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'name' => [ 'type' => 'string' ],
							'type' => [ 'type' => 'string' ],
						],
					],
				],
				'facts'            => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			],
		];

		return (array) apply_filters( 'aggregate_it_rewrite_schema', $schema );
	}
}
