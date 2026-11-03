<?php

namespace AggregateIt\Tests;

use AggregateIt\Keyword\KeywordProvider;
use AggregateIt\Keyword\KeywordStrategy;
use AggregateIt\Settings;
use PHPUnit\Framework\TestCase;

final class KeywordStrategyTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__options'] = [];
		$GLOBALS['__filters'] = [];
	}

	private function provider_with_volume( int $volume ): KeywordProvider {
		return new class( $volume ) implements KeywordProvider {
			public function __construct( private int $volume ) {}

			public function key(): string {
				return 'fake';
			}

			public function metrics( array $keywords ): array {
				return array_fill_keys( $keywords, [ 'volume' => $this->volume, 'difficulty' => 1 ] );
			}
		};
	}

	public function test_filter_supplies_the_provider_and_rejects_other_types(): void {
		$strategy = new KeywordStrategy( new Settings() );
		$this->assertNull( $strategy->provider() );

		$GLOBALS['__filters']['aggregate_it_keyword_provider'] = static fn () => new \stdClass();
		$this->assertNull( $strategy->provider() );

		$provider                                              = $this->provider_with_volume( 10 );
		$GLOBALS['__filters']['aggregate_it_keyword_provider'] = static fn () => $provider;
		$this->assertSame( $provider, $strategy->provider() );
	}

	public function test_strategic_mode_skips_zero_volume_keywords_when_a_provider_reports_them(): void {
		$GLOBALS['__options']['aggregate_it_settings']         = [ 'strategic_mode' => true ];
		$provider                                              = $this->provider_with_volume( 0 );
		$GLOBALS['__filters']['aggregate_it_keyword_provider'] = static fn () => $provider;

		$this->assertTrue( ( new KeywordStrategy( new Settings() ) )->resolve( 'dead topic', 'text' )['skip'] );
	}

	public function test_no_list_keeps_inferred_keyword(): void {
		$d = ( new KeywordStrategy( new Settings() ) )->resolve( 'iphone launch', 'a story about iphone' );
		$this->assertSame( 'iphone launch', $d['keyword'] );
		$this->assertFalse( $d['skip'] );
	}

	public function test_strategic_miss_is_skipped(): void {
		$GLOBALS['__options']['aggregate_it_settings'] = [ 'keyword_list' => [ 'electric vehicles' ], 'strategic_mode' => true ];
		$d = ( new KeywordStrategy( new Settings() ) )->resolve( 'random topic', 'a story about random topic' );
		$this->assertTrue( $d['skip'] );
	}

	public function test_strategic_hit_uses_target_keyword(): void {
		$GLOBALS['__options']['aggregate_it_settings'] = [ 'keyword_list' => [ 'electric vehicles' ], 'strategic_mode' => true ];
		$d = ( new KeywordStrategy( new Settings() ) )->resolve( 'cars', 'news about electric vehicles market' );
		$this->assertSame( 'electric vehicles', $d['keyword'] );
		$this->assertFalse( $d['skip'] );
	}
}
