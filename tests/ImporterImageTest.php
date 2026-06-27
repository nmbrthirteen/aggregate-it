<?php

namespace AggregateIt\Tests;

use AggregateIt\Source\Importer;
use PHPUnit\Framework\TestCase;

final class ImporterImageTest extends TestCase {

	private object $importer;
	private \ReflectionClass $ref;
	private \ReflectionMethod $best_media_image;

	protected function setUp(): void {
		$this->ref      = new \ReflectionClass( Importer::class );
		$this->importer = $this->ref->newInstanceWithoutConstructor();

		$this->best_media_image = $this->method( 'best_media_image' );
	}

	private function method( string $name ): \ReflectionMethod {
		$m = $this->ref->getMethod( $name );
		$m->setAccessible( true );
		return $m;
	}

	private function usable( string $url ): bool {
		return (bool) $this->method( 'usable_image_url' )->invoke( $this->importer, $url );
	}

	public function test_uses_media_content_image_url(): void {
		$tags = [
			[
				'attribs' => [
					'http://search.yahoo.com/mrss/' => [
						'url' => 'https://example.com/img-srv/example/ext:webp/source.webp',
					],
				],
			],
		];

		$this->assertSame(
			'https://example.com/img-srv/example/ext:webp/source.webp',
			$this->best_media_image->invoke( $this->importer, $tags )
		);
	}

	public function test_ignores_homepage_media_url(): void {
		$tags = [
			[
				'attribs' => [
					'http://search.yahoo.com/mrss/' => [
						'url' => 'https://example.com',
					],
				],
			],
		];

		$this->assertSame( '', $this->best_media_image->invoke( $this->importer, $tags ) );
	}

	public function test_usable_url_accepts_extensionless_cdn(): void {
		$this->assertTrue( $this->usable( 'https://cdn.example.com/thumbor/abc123/hero' ) );
	}

	public function test_usable_url_not_fooled_by_host_substring(): void {
		$this->assertTrue( $this->usable( 'https://siliconangle.com/2024/12/hero.jpg' ) );
	}

	public function test_usable_url_rejects_logo_filename(): void {
		$this->assertFalse( $this->usable( 'https://example.com/assets/logo.png' ) );
	}

	public function test_usable_url_rejects_non_image_enclosure(): void {
		$this->assertFalse( $this->usable( 'https://example.com/media/episode-42.mp3' ) );
	}

	public function test_usable_url_rejects_homepage(): void {
		$this->assertFalse( $this->usable( 'https://example.com' ) );
	}
}
