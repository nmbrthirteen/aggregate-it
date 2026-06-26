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
