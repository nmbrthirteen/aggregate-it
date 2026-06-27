<?php

namespace AggregateIt\Tests;

use AggregateIt\Source\Importer;
use PHPUnit\Framework\TestCase;

final class ImporterImageTest extends TestCase {

	private object $importer;
	private \ReflectionMethod $best_media_image;

	protected function setUp(): void {
		$ref = new \ReflectionClass( Importer::class );
		$this->importer = $ref->newInstanceWithoutConstructor();

		$this->best_media_image = $ref->getMethod( 'best_media_image' );
		$this->best_media_image->setAccessible( true );
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
}
