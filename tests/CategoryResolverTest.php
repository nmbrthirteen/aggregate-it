<?php

namespace AggregateIt\Tests;

use AggregateIt\Publish\CategoryResolver;
use AggregateIt\Settings;
use PHPUnit\Framework\TestCase;

final class CategoryResolverTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__options'] = [];
		$GLOBALS['__terms']   = [ 'category' => [] ];
	}

	private function resolver(): CategoryResolver {
		return new CategoryResolver( new Settings() );
	}

	/** @param string[] $names */
	private function seed( array $names ): void {
		foreach ( $names as $i => $name ) {
			$GLOBALS['__terms']['category'][] = (object) [
				'term_id' => $i + 1,
				'name'    => $name,
				'slug'    => sanitize_title( $name ),
			];
		}
	}

	public function test_resolve_matches_existing_by_name_case_insensitively(): void {
		$this->seed( [ 'Technology' ] );
		$this->assertSame( 1, $this->resolver()->resolve( 'technology' ) );
	}

	public function test_resolve_matches_existing_by_slug(): void {
		$this->seed( [ 'Tech News' ] );
		$this->assertSame( 1, $this->resolver()->resolve( 'tech-news' ) );
	}

	public function test_resolve_creates_category_when_missing(): void {
		$this->seed( [ 'Technology' ] );
		$id = $this->resolver()->resolve( 'Gadgets' );
		$this->assertGreaterThan( 0, $id );
		$this->assertNotSame( 1, $id );
		$this->assertSame( 'Gadgets', $GLOBALS['__terms']['category'][1]->name );
	}

	public function test_resolve_empty_name_returns_zero(): void {
		$this->assertSame( 0, $this->resolver()->resolve( '   ' ) );
	}

	public function test_existing_names_excludes_generic_buckets(): void {
		$this->seed( [ 'Technology', 'Blog', 'Uncategorized', 'Sports' ] );
		$names = $this->resolver()->existing_names();
		$this->assertContains( 'Technology', $names );
		$this->assertContains( 'Sports', $names );
		$this->assertNotContains( 'Blog', $names );
		$this->assertNotContains( 'Uncategorized', $names );
	}
}
