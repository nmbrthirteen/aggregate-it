<?php

namespace AggregateIt\Tests;

use AggregateIt\Ai\OpenAiProvider;
use AggregateIt\Settings;
use PHPUnit\Framework\TestCase;

final class OpenAiProviderTest extends TestCase {

	private function strict( array $schema ): array {
		$ref    = new \ReflectionMethod( OpenAiProvider::class, 'strict_schema' );
		$ref->setAccessible( true );
		return $ref->invoke( new OpenAiProvider( new Settings() ), $schema );
	}

	public function test_makes_every_property_required_and_forbids_extras(): void {
		$out = $this->strict(
			[
				'type'       => 'object',
				'required'   => [ 'category' ],
				'properties' => [
					'category' => [ 'type' => 'string' ],
					'entities' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				],
			]
		);

		$this->assertSame( [ 'category', 'entities' ], $out['required'] );
		$this->assertFalse( $out['additionalProperties'] );
	}

	public function test_strips_unsupported_string_constraints(): void {
		$out = $this->strict(
			[
				'type'       => 'object',
				'properties' => [
					'seo_title' => [ 'type' => 'string', 'maxLength' => 70 ],
				],
			]
		);

		$this->assertArrayNotHasKey( 'maxLength', $out['properties']['seo_title'] );
	}

	public function test_recurses_into_nested_object_items(): void {
		$out = $this->strict(
			[
				'type'       => 'object',
				'properties' => [
					'entities' => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'name' => [ 'type' => 'string' ],
								'type' => [ 'type' => 'string' ],
							],
						],
					],
				],
			]
		);

		$item = $out['properties']['entities']['items'];
		$this->assertSame( [ 'name', 'type' ], $item['required'] );
		$this->assertFalse( $item['additionalProperties'] );
	}
}
