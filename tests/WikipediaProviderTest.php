<?php

namespace AggregateIt\Tests;

use AggregateIt\Research\WikipediaProvider;
use PHPUnit\Framework\TestCase;

final class WikipediaProviderTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__http'] = null;
	}

	public function test_returns_extract_and_sameas(): void {
		$GLOBALS['__http'] = [
			'code' => 200,
			'body' => json_encode(
				[
					'type'         => 'standard',
					'extract'      => 'Svenska Spel is a Swedish state-owned gambling company.',
					'content_urls' => [ 'desktop' => [ 'page' => 'https://en.wikipedia.org/wiki/Svenska_Spel' ] ],
				]
			),
		];
		$r = ( new WikipediaProvider() )->research( 'Svenska Spel', 'company' );
		$this->assertNotEmpty( $r['facts'] );
		$this->assertSame( 'https://en.wikipedia.org/wiki/Svenska_Spel', $r['sameas'][0] );
	}

	public function test_skips_disambiguation(): void {
		$GLOBALS['__http'] = [ 'code' => 200, 'body' => json_encode( [ 'type' => 'disambiguation', 'extract' => 'many things' ] ) ];
		$this->assertSame( [], ( new WikipediaProvider() )->research( 'Mercury', '' )['facts'] );
	}

	public function test_handles_not_found(): void {
		$GLOBALS['__http'] = [ 'code' => 404, 'body' => '' ];
		$this->assertSame( [], ( new WikipediaProvider() )->research( 'Nope', '' )['sameas'] );
	}
}
