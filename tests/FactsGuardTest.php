<?php

namespace AggregateIt\Tests;

use AggregateIt\Ai\FactsGuard;
use PHPUnit\Framework\TestCase;

final class FactsGuardTest extends TestCase {

	private FactsGuard $guard;

	protected function setUp(): void {
		$this->guard = new FactsGuard();
	}

	public function test_salient_catches_numbers_and_proper_nouns(): void {
		$salient = $this->guard->salient( 'Acme Corp raised $5 million in 2024. Tim Cook commented.' );
		$this->assertContains( '$5', $salient );
		$this->assertContains( 'acme corp', $salient );
	}

	public function test_invented_flags_fabricated_number(): void {
		$invented = $this->guard->invented( 'Revenue was $5 million', 'Revenue reached $9 million' );
		$this->assertContains( '9', $invented );
	}

	public function test_invented_ignores_faithful_rewrite(): void {
		$invented = $this->guard->invented( 'Revenue was $5 million', 'Revenue stayed at $5 million this year' );
		$this->assertSame( [], $invented );
	}

	public function test_novel_finds_new_facts(): void {
		$this->assertNotEmpty( $this->guard->novel( 'New CEO Jane Roe joins in 2025', [ 'acme corp', '$5' ] ) );
	}

	public function test_novel_empty_when_nothing_new(): void {
		$this->assertSame( [], $this->guard->novel( 'Acme Corp', [ 'acme corp' ] ) );
	}
}
