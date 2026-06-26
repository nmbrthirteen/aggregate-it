<?php

namespace AggregateIt\Tests;

use AggregateIt\Keyword\KeywordStrategy;
use AggregateIt\Settings;
use PHPUnit\Framework\TestCase;

final class KeywordStrategyTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__options'] = [];
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
