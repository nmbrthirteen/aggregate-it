<?php

namespace AggregateIt\Ai;

use AggregateIt\Settings;

defined( 'ABSPATH' ) || exit;

final class ProviderFactory {

	/** Transient holding why publishing is on hold; the admin notice reads it. */
	public const BLOCKED_TRANSIENT = 'aggregate_it_provider_blocked';

	private ?AiProvider $resolved = null;

	public function __construct( private Settings $settings ) {}

	public function get(): AiProvider {
		if ( $this->resolved === null ) {
			$this->resolved = $this->make();
		}
		return $this->resolved;
	}

	/**
	 * Why publishing must not run, or null when a real provider is usable. MockProvider
	 * echoes the source text as a "rewrite", so it is allowed only under the test suite.
	 *
	 * @return 'unconfigured'|'undecryptable'|null
	 */
	public function blocked_reason(): ?string {
		if ( $this->get()->key() !== 'mock' || ( defined( 'AGGREGATE_IT_TESTING' ) && AGGREGATE_IT_TESTING ) ) {
			return null;
		}
		return $this->settings->api_key_unreadable() ? 'undecryptable' : 'unconfigured';
	}

	private function make(): AiProvider {
		$key = $this->settings->provider_key();

		$provider = apply_filters( 'aggregate_it_ai_provider', null, $key, $this->settings );
		if ( $provider instanceof AiProvider ) {
			return $provider;
		}

		if ( $this->settings->api_key() !== '' ) {
			switch ( $key ) {
				case 'anthropic':
					return new AnthropicProvider( $this->settings );
				case 'openai':
					return new OpenAiProvider( $this->settings );
				case 'gemini':
					return new GeminiProvider( $this->settings );
			}
		}

		return new MockProvider();
	}
}
