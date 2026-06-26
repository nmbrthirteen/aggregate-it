<?php

namespace AggregateIt\Tests;

use AggregateIt\Ai\ProviderFactory;
use AggregateIt\Ai\Rewriter;
use AggregateIt\Settings;
use PHPUnit\Framework\TestCase;

final class RewriterPromptTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__options'] = [];
		$GLOBALS['__filters'] = [];
	}

	private function prompt( array $settings ): string {
		$GLOBALS['__options']['aggregate_it_settings'] = $settings;
		$s  = new Settings();
		$rw = new Rewriter( new ProviderFactory( $s ), $s );
		$m  = new \ReflectionMethod( $rw, 'prompt' );
		$m->setAccessible( true );
		return $m->invoke( $rw, 'Title', 'Body content', null );
	}

	public function test_length_auto(): void {
		$this->assertStringContainsString( 'length follow the facts', $this->prompt( [ 'article_length' => 'auto' ] ) );
	}

	public function test_length_short(): void {
		$this->assertStringContainsString( '250-350', $this->prompt( [ 'article_length' => 'short' ] ) );
	}

	public function test_length_long(): void {
		$this->assertStringContainsString( '900-1100', $this->prompt( [ 'article_length' => 'long' ] ) );
	}

	public function test_per_feed_length_override_beats_global(): void {
		$GLOBALS['__options']['aggregate_it_settings'] = [ 'article_length' => 'short' ];
		$s  = new Settings();
		$rw = new Rewriter( new ProviderFactory( $s ), $s );
		$m  = new \ReflectionMethod( $rw, 'prompt' );
		$m->setAccessible( true );
		$prompt = $m->invoke( $rw, 'T', 'B', null, 'long' );
		$this->assertStringContainsString( '900-1100', $prompt );
		$this->assertStringNotContainsString( '250-350', $prompt );
	}

	public function test_writing_instructions_injected(): void {
		$this->assertStringContainsString( 'British spelling', $this->prompt( [ 'writing_instructions' => 'Use British spelling.' ] ) );
	}

	public function test_target_keyword_included(): void {
		$GLOBALS['__options']['aggregate_it_settings'] = [];
		$s  = new Settings();
		$rw = new Rewriter( new ProviderFactory( $s ), $s );
		$m  = new \ReflectionMethod( $rw, 'prompt' );
		$m->setAccessible( true );
		$this->assertStringContainsString( 'electric vehicles', $m->invoke( $rw, 'T', 'B', 'electric vehicles' ) );
	}
}
