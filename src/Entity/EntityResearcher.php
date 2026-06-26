<?php

namespace AggregateIt\Entity;

use AggregateIt\Ai\ProviderFactory;
use AggregateIt\Research\ResearchProvider;
use AggregateIt\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the data for a new entity stub. Degrades gracefully: with no research provider
 * the stub is built from in-article context only (zero external dependency); when a
 * provider is registered, it adds cited external facts + sameAs. Stubs stay strict — only
 * cited information, never confidently-filled hallucination.
 */
final class EntityResearcher {

	public function __construct(
		private Settings $settings,
		private ProviderFactory $providers
	) {}

	/**
	 * Fill the plain field labels the user asked for (Founded, Industry, Website…) from
	 * the article and well-known facts. Returns label => value, dropping blanks. One cheap
	 * AI call, only when the content type has fields and a new entity is created.
	 *
	 * @param array<string,mixed> $rule
	 * @return array<string,string>
	 */
	public function fields( array $rule, string $name, string $type, string $context ): array {
		$labels = array_values( array_filter( array_map( 'trim', (array) ( $rule['fields'] ?? [] ) ) ) );
		if ( ! $labels ) {
			return [];
		}

		$keys  = [];
		$props = [];
		foreach ( $labels as $label ) {
			$key            = sanitize_key( str_replace( ' ', '_', $label ) );
			if ( $key === '' ) {
				continue;
			}
			$keys[ $key ]   = $label;
			$props[ $key ]  = [ 'type' => 'string' ];
		}
		if ( ! $props ) {
			return [];
		}

		$prompt = sprintf(
			"For the %s \"%s\", fill in these details from the article and well-known facts. "
			. "Leave a field empty if you are not confident — do not guess. Fields: %s.\n\nARTICLE:\n%s",
			$type !== '' ? $type : 'subject',
			$name,
			implode( ', ', $labels ),
			$context
		);

		$schema = [ 'type' => 'object', 'additionalProperties' => false, 'properties' => $props ];

		try {
			$result = $this->providers->get()->structured( $prompt, $schema );
		} catch ( \Throwable $e ) {
			return [];
		}

		$out = [];
		foreach ( $keys as $key => $label ) {
			$value = trim( (string) ( $result['result'][ $key ] ?? '' ) );
			if ( $value !== '' ) {
				$out[ $label ] = $value;
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $rule
	 * @return array{description:string,sameas:string[],citations:string[],schema_type:string,is_stub:bool}
	 */
	public function research( array $rule, string $name, string $type, string $context, string $source_url, string $article_description = '' ): array {
		$schema_type = (string) ( $rule['schema_type'] ?? 'Thing' );

		// Prefer the AI's per-entity description from the article; fall back to a sentence.
		$description = $article_description !== '' ? $article_description : $this->context_sentence( $name, $context );

		$provider = apply_filters( 'aggregate_it_research_provider', null, $this->settings );
		if ( ! empty( $rule['research']['enabled'] ) && $provider instanceof ResearchProvider ) {
			$max  = (int) ( $rule['research']['max_lookups'] ?? 3 );
			$data = $provider->research( $name, $type, $max );

			$facts      = $data['facts'] ?? [];
			$desc_parts = array_filter( array_merge( [ $description ], array_map( static fn ( $f ) => (string) ( $f['value'] ?? '' ), $facts ) ) );
			$citations  = array_map( static fn ( $f ) => (string) ( $f['source'] ?? '' ), $facts );

			return [
				'description' => trim( implode( ' ', $desc_parts ) ),
				'sameas'      => array_values( array_filter( $data['sameas'] ?? [] ) ),
				'citations'   => array_values( array_filter( array_merge( $citations, [ $source_url ] ) ) ),
				'schema_type' => $schema_type,
				'is_stub'     => $article_description === '',
			];
		}

		return [
			'description' => $description,
			'sameas'      => [],
			'citations'   => array_filter( [ $source_url ] ),
			'schema_type' => $schema_type,
			'is_stub'     => $article_description === '',
		];
	}

	/** First sentence of the article that mentions the entity, as a minimal cited stub. */
	private function context_sentence( string $name, string $context ): string {
		$sentences = preg_split( '/(?<=[.!?])\s+/', $context ) ?: [];
		foreach ( $sentences as $sentence ) {
			if ( stripos( $sentence, $name ) !== false ) {
				return trim( $sentence );
			}
		}
		return '';
	}
}
