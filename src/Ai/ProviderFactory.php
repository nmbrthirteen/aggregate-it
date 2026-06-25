<?php

namespace AggregateIt\Ai;

use AggregateIt\Settings;

defined( 'ABSPATH' ) || exit;

final class ProviderFactory {

	private ?AiProvider $resolved = null;

	public function __construct( private Settings $settings ) {}

	public function get(): AiProvider {
		if ( $this->resolved === null ) {
			$this->resolved = $this->make();
		}
		return $this->resolved;
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
