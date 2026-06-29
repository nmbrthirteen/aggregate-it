<?php

namespace AggregateIt\Tests;

use AggregateIt\Publish\FieldMapper;
use PHPUnit\Framework\TestCase;

final class FieldMapperTest extends TestCase {

	public function test_defaults_route_standard_fields(): void {
		$result = FieldMapper::map(
			[
				'title'   => 'iGB LiVE 2026',
				'content' => 'A big show.',
				'image'   => 'https://x.test/logo.png',
				'date'    => '2026-07-01 00:00:00',
				'url'     => 'https://x.test/igb',
			],
			[]
		);

		$this->assertSame( 'iGB LiVE 2026', $result['post']['post_title'] );
		$this->assertSame( 'A big show.', $result['post']['post_content'] );
		$this->assertSame( '2026-07-01 00:00:00', $result['post']['post_date'] );
		$this->assertSame( 'https://x.test/logo.png', $result['image'] );
		$this->assertSame( 'https://x.test/igb', $result['meta']['source_url'] );
	}

	public function test_explicit_map_routes_to_meta_and_taxonomy(): void {
		$result = FieldMapper::map(
			[
				'title'    => 'SiGMA',
				'location' => 'Malta',
				'venue'    => 'MFCC',
			],
			[
				'location' => [ 'dest' => 'taxonomy:event_location' ],
				'venue'    => [ 'dest' => 'meta:venue' ],
			]
		);

		$this->assertSame( 'SiGMA', $result['post']['post_title'] );
		$this->assertSame( [ 'Malta' ], $result['terms']['event_location'] );
		$this->assertSame( 'MFCC', $result['meta']['venue'] );
	}

	public function test_custom_field_defaults_to_meta_and_empty_values_skipped(): void {
		$result = FieldMapper::map(
			[ 'organizer' => 'Clarion', 'sponsor' => '' ],
			[]
		);

		$this->assertSame( 'Clarion', $result['meta']['organizer'] );
		$this->assertArrayNotHasKey( 'sponsor', $result['meta'] );
	}
}
