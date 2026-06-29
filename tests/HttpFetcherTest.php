<?php

namespace AggregateIt\Tests;

use AggregateIt\Source\HttpFetcher;
use AggregateIt\Source\RateLimited;
use PHPUnit\Framework\TestCase;

final class HttpFetcherTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__transients'] = [];
		$GLOBALS['__http']       = [ 'code' => 200, 'body' => '<html></html>' ];
		// Skip the SSRF DNS lookup in tests.
		$GLOBALS['__filters']['aggregate_it_allow_private_hosts'] = static fn ( $v, $host = '' ) => true;
	}

	public function test_second_same_host_fetch_is_rate_limited(): void {
		$fetcher = new HttpFetcher();
		$fetcher->fetch( 'https://example.com/article-a' );

		$this->expectException( RateLimited::class );
		$fetcher->fetch( 'https://example.com/article-b' );
	}

	public function test_transient_error_is_not_negative_cached(): void {
		$GLOBALS['__http'] = [ 'code' => 403, 'body' => '' ];
		$fetcher           = new HttpFetcher();

		try {
			$fetcher->fetch( 'https://forbidden.test/x' );
			$this->fail( 'expected fetch to throw on HTTP 403' );
		} catch ( RateLimited $e ) {
			$this->fail( 'rate limit should not fire on a clean host' );
		} catch ( \RuntimeException $e ) {
			// expected
		}

		$this->assertFalse( get_transient( 'aggregate_it_http_' . md5( 'https://forbidden.test/x' ) ), '403 must not be cached so a retry re-hits the network' );
	}

	public function test_gone_page_is_negative_cached(): void {
		$GLOBALS['__http'] = [ 'code' => 404, 'body' => '' ];
		$fetcher           = new HttpFetcher();

		try {
			$fetcher->fetch( 'https://example.org/missing' );
		} catch ( \RuntimeException $e ) {
			// expected
		}

		$this->assertSame( '', get_transient( 'aggregate_it_http_' . md5( 'https://example.org/missing' ) ), '404 should be cached as permanently gone' );
	}
}
