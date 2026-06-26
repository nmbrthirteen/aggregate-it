<?php

namespace AggregateIt\Tests;

use AggregateIt\Entity\EntityRepository;
use AggregateIt\Entity\EntityResolver;
use PHPUnit\Framework\TestCase;

final class EntityResolverTest extends TestCase {

	private array $rule = [ 'target_cpt' => 'company', 'match' => [ 'link_threshold' => 92, 'ambiguous_floor' => 75 ] ];
	private EntityResolver $resolver;

	protected function setUp(): void {
		$GLOBALS['__options']    = [];
		$GLOBALS['__posts_meta'] = [];
		$GLOBALS['__norm_posts'] = [ 10 => 'apple', 20 => 'microsoft' ];
		$this->resolver          = new EntityResolver( new EntityRepository() );
	}

	public function test_exact_match_links(): void {
		$d = $this->resolver->resolve( $this->rule, 'Apple Inc.' );
		$this->assertSame( 'link', $d['action'] );
		$this->assertSame( 10, $d['entity_id'] );
	}

	public function test_novel_name_creates(): void {
		$this->assertSame( 'create', $this->resolver->resolve( $this->rule, 'Tesla Motors' )['action'] );
	}

	public function test_ambiguous_name_is_skipped(): void {
		$this->assertSame( 'skip', $this->resolver->resolve( $this->rule, 'Appl' )['action'] );
	}
}
