<?php

namespace AggregateIt\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * The single structured call: one round trip produces the rewritten body plus all SEO
 * fields, entities, and facts. Faithful rewrite — reword and strip promotional cruft,
 * never change facts or invent information.
 */
final class Rewriter {

	public function __construct(
		private ProviderFactory $providers,
		private \AggregateIt\Settings $settings
	) {}

	/**
	 * @return array{result:array<string,mixed>,tokens:int,cost_usd:float}
	 */
	public function rewrite( string $title, string $content, ?string $target_keyword = null, ?string $length = null ): array {
		$prompt = $this->prompt( $title, $content, $target_keyword, $length );
		return $this->providers->get()->structured( $prompt, $this->schema() );
	}

	private function prompt( string $title, string $content, ?string $target_keyword, ?string $length = null ): string {
		$rules = [
			'Rewrite the article below into original prose that reads as if a real human journalist wrote it from scratch.',
			'PRESERVE every fact verbatim: names, numbers, dates, quotes, and claims. Never invent, alter, or DROP a fact. Fact-keeping is the top priority and overrides brevity.',
			'Write naturally and human: vary sentence length and structure, use a plain confident voice, and make it flow. It must NOT read as AI-generated or templated.',
			'Avoid AI tells and stock filler. Do not use phrases like "in conclusion", "furthermore", "moreover", "it is worth noting", "in today\'s world", "delve", "navigate the landscape", "robust", "leverage", "boasts", "a testament to", or formulaic intros/outros. No hedging padding.',
			'Be concise WITHOUT losing information. Shorten by tightening wording and cutting filler, repetition, and throat-clearing — never by removing facts. If a sentence carries a fact, keep the fact; only trim the words around it.',
			'REMOVE promotional cruft: calls-to-action, "subscribe", affiliate lines, ads, author self-promotion, "read more" links.',
			'Keep news as news — neutral, factual tone. Do not editorialize. Do not mention sources, the original article, or that this is a rewrite.',
			'In "entities", list the notable organizations, people, products, and places the article is about (the main subjects, not every passing mention). For each, give its name, a one-word type (company, person, product, place), and a "description": one or two neutral sentences explaining what it is and its role in this story. Keep descriptions factual; do not invent specific figures.',
			'Return ONLY the structured object.',
		];

		if ( $target_keyword ) {
			$rules[] = sprintf( 'Target keyword "%s": include it naturally in the title and first paragraph. Never keyword-stuff.', $target_keyword );
		}

		$lengths = [
			'auto'   => 'Let the length follow the facts: long enough to include every fact, and no longer.',
			'short'  => 'Aim for a short article, about 250-350 words.',
			'medium' => 'Aim for a medium-length article, about 500-650 words.',
			'long'   => 'Aim for a longer article, about 900-1100 words; add depth only from the source, never invent.',
			'match'  => 'Roughly match the length of the source article.',
		];
		$length  = $length ?: $this->settings->article_length();
		$rules[] = ( $lengths[ $length ] ?? $lengths['auto'] )
			. ( $length === 'auto' ? '' : ' Treat this as a target, not a hard limit — never drop or shorten facts to hit it.' );

		$custom = $this->settings->writing_instructions();
		if ( $custom !== '' ) {
			$rules[] = 'Site owner instructions (follow these, but never at the cost of dropping a fact): ' . $custom;
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
							'name'        => [ 'type' => 'string' ],
							'type'        => [ 'type' => 'string' ],
							'description' => [ 'type' => 'string' ],
						],
					],
				],
				'facts'            => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			],
		];

		return (array) apply_filters( 'aggregate_it_rewrite_schema', $schema );
	}
}
