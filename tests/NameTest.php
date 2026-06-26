<?php

namespace AggregateIt\Tests;

use AggregateIt\Entity\Name;
use PHPUnit\Framework\TestCase;

final class NameTest extends TestCase {

	public function test_strips_corporate_suffixes_and_punctuation(): void {
		$this->assertSame( 'acme', Name::normalize( 'Acme Corp., Inc.' ) );
	}

	public function test_drops_leading_the(): void {
		$this->assertSame( 'acme', Name::normalize( 'The Acme' ) );
	}

	public function test_lowercases_and_collapses_whitespace(): void {
		$this->assertSame( 'tim cook', Name::normalize( '  Tim   COOK ' ) );
	}

	public function test_empty_stays_empty(): void {
		$this->assertSame( '', Name::normalize( '   ' ) );
	}
}
