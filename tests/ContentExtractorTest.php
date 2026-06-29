<?php

namespace AggregateIt\Tests;

use AggregateIt\Source\ContentExtractor;
use AggregateIt\Source\HttpFetcher;
use PHPUnit\Framework\TestCase;

final class ContentExtractorTest extends TestCase {

	private ContentExtractor $extractor;
	private \ReflectionMethod $best;
	private \ReflectionMethod $readability;

	protected function setUp(): void {
		$this->extractor = new ContentExtractor( new HttpFetcher() );
		$ref             = new \ReflectionClass( ContentExtractor::class );
		$this->best      = $ref->getMethod( 'best_meta_image' );
		$this->best->setAccessible( true );
		$this->readability = $ref->getMethod( 'readability' );
		$this->readability->setAccessible( true );
	}

	public function test_prefers_og_image(): void {
		$html = '<meta property="og:image" content="https://x/og.jpg"><img src="https://x/content.jpg">';
		$this->assertSame( 'https://x/og.jpg', $this->best->invoke( $this->extractor, $html ) );
	}

	public function test_prefers_secure_url_over_og_image(): void {
		$html = '<meta property="og:image" content="http://x/a.jpg"><meta property="og:image:secure_url" content="https://x/secure.jpg">';
		$this->assertSame( 'https://x/secure.jpg', $this->best->invoke( $this->extractor, $html ) );
	}

	public function test_skips_logo_falls_back_to_content_image(): void {
		$html = '<img src="https://site/assets/logo.svg"><article><img src="https://cdn/img-srv/ext:webp/hero.webp"></article>';
		$this->assertSame( 'https://cdn/img-srv/ext:webp/hero.webp', $this->best->invoke( $this->extractor, $html ) );
	}

	public function test_no_image_returns_empty(): void {
		$this->assertSame( '', $this->best->invoke( $this->extractor, '<p>no images here</p>' ) );
	}

	public function test_keeps_og_image_on_host_with_icon_substring(): void {
		$html = '<meta property="og:image" content="https://siliconangle.com/2024/hero.jpg">';
		$this->assertSame( 'https://siliconangle.com/2024/hero.jpg', $this->best->invoke( $this->extractor, $html ) );
	}

	public function test_keeps_extensionless_og_image(): void {
		$html = '<meta property="og:image" content="https://cdn.example.com/image/abc123">';
		$this->assertSame( 'https://cdn.example.com/image/abc123', $this->best->invoke( $this->extractor, $html ) );
	}

	public function test_trusts_explicit_og_image_even_with_junk_token(): void {
		$html = '<meta property="og:image" content="https://x.com/2024/icon-hero.jpg">';
		$this->assertSame( 'https://x.com/2024/icon-hero.jpg', $this->best->invoke( $this->extractor, $html ) );
	}

	public function test_og_image_with_apostrophe_not_truncated(): void {
		$html = '<meta property="og:image" content="https://cdn.example.com/o\'brien-feature.jpg">';
		$this->assertSame( 'https://cdn.example.com/o\'brien-feature.jpg', $this->best->invoke( $this->extractor, $html ) );
	}

	public function test_share_image_rethrows_transient_only_when_requested(): void {
		$GLOBALS['__transients'] = [];
		$GLOBALS['__filters']['aggregate_it_allow_private_hosts'] = static fn ( $v, $h = '' ) => true;
		$GLOBALS['__http'] = new \WP_Error( 'boom' );
		$ext = new ContentExtractor( new HttpFetcher() );

		$this->assertSame( '', $ext->share_image( 'https://example.com/x', false ) );

		$GLOBALS['__transients'] = [];
		$this->expectException( \RuntimeException::class );
		$ext->share_image( 'https://example.com/x', true );
	}

	public function test_readability_keeps_article_drops_chrome(): void {
		$html = '<html><body><nav>Home About</nav><header>Site</header>'
			. '<article><h2>Big News</h2><p>First paragraph with real content.</p>'
			. '<script>tracker()</script><p>Second paragraph with detail.</p>'
			. '<aside>Subscribe!</aside></article><footer>Copyright</footer></body></html>';
		$out = $this->readability->invoke( $this->extractor, $html );
		$this->assertStringContainsString( 'Big News', $out );
		$this->assertStringContainsString( 'First paragraph', $out );
		$this->assertStringContainsString( 'Second paragraph', $out );
		$this->assertStringNotContainsString( 'Home About', $out );
		$this->assertStringNotContainsString( 'tracker', $out );
		$this->assertStringNotContainsString( 'Subscribe', $out );
	}
}
